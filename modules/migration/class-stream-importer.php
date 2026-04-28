<?php
/**
 * Stream Importer - Handles streaming download and automatic restore.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Security_Guard;
use WUDT\Modules\Restore\Restore_Module;
use WUDT\Modules\Restore\Media_URL_Handler;

if (! defined('ABSPATH')) {
	exit;
}

class Stream_Importer {
	private const DOWNLOAD_CHUNK_SIZE = 262144; // 256KB
	private const MAX_DOWNLOAD_RETRIES = 5;
	private const MIGRATIONS_DIR = 'wudt-migrations';

	/**
	 * Download backup from remote server with resume support.
	 */
	public function download_backup(
		string $job_id,
		string $site_url,
		string $api_key,
		string $remote_job_id,
		callable $progress_callback = null
	): string {
		$uploads_dir = wp_get_upload_dir();
		$migrations_dir = trailingslashit($uploads_dir['basedir']) . self::MIGRATIONS_DIR;
		
		if (!wp_mkdir_p($migrations_dir)) {
			throw new \RuntimeException(__('Failed to create migrations directory', 'wp-ultimate-diagnostics-toolkit'));
		}

		$local_path = $migrations_dir . '/migration_' . $job_id . '.zip';
		$temp_path = $local_path . '.part';
		$resume_byte = 0;

		// Check for partial download to resume
		if (file_exists($temp_path)) {
			$resume_byte = filesize($temp_path);
		}

		// Build download URL
		$download_url = trailingslashit($site_url) . 'wp-json/wudt-migration/v1/download/' . $remote_job_id;
		$timestamp = time();
		$signature = hash_hmac('sha256', $api_key . $timestamp, $api_key);

		$headers = array(
			'X-WUDT-API-Key' => $api_key,
			'X-WUDT-Timestamp' => $timestamp,
			'X-WUDT-Signature' => $signature,
		);

		// Get file size
		$total_size = $this->get_remote_file_size($download_url, $headers);
		
		if ($total_size === false) {
			throw new \RuntimeException(__('Cannot determine remote file size', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Check disk space
		$free_space = disk_free_space(dirname($local_path));
		if ($free_space === false || $free_space < $total_size + 100 * 1024 * 1024) {
			throw new \RuntimeException(__('Insufficient disk space for download', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Open temp file for writing (append mode for resume)
		$handle = fopen($temp_path, $resume_byte > 0 ? 'ab' : 'wb');
		if (!$handle) {
			throw new \RuntimeException(__('Cannot create download file', 'wp-ultimate-diagnostics-toolkit'));
		}

		$downloaded = $resume_byte;
		$retry_count = 0;

		while ($downloaded < $total_size) {
			$end_byte = min($downloaded + self::DOWNLOAD_CHUNK_SIZE - 1, $total_size - 1);
			$headers['Range'] = "bytes={$downloaded}-{$end_byte}";

			$response = wp_remote_get($download_url, array(
				'headers' => $headers,
				'timeout' => 60,
				'sslverify' => false,
			));

			if (is_wp_error($response)) {
				$retry_count++;
				if ($retry_count > self::MAX_DOWNLOAD_RETRIES) {
					fclose($handle);
					throw new \RuntimeException($response->get_error_message());
				}
				sleep(2 * $retry_count);
				continue;
			}

			$code = wp_remote_retrieve_response_code($response);
			if ($code !== 200 && $code !== 206) {
				$retry_count++;
				if ($retry_count > self::MAX_DOWNLOAD_RETRIES) {
					fclose($handle);
					throw new \RuntimeException(__('Download failed with HTTP ' . $code, 'wp-ultimate-diagnostics-toolkit'));
				}
				sleep(2 * $retry_count);
				continue;
			}

			$body = wp_remote_retrieve_body($response);
			if (empty($body)) {
				$retry_count++;
				if ($retry_count > self::MAX_DOWNLOAD_RETRIES) {
					fclose($handle);
					throw new \RuntimeException(__('Empty response from server', 'wp-ultimate-diagnostics-toolkit'));
				}
				sleep(2 * $retry_count);
				continue;
			}

			fwrite($handle, $body);
			$downloaded += strlen($body);
			$retry_count = 0;

			// Update progress
			if ($progress_callback) {
				$percent = (int) (($downloaded / $total_size) * 100);
				$message = sprintf(
					__('Downloading: %s / %s', 'wp-ultimate-diagnostics-toolkit'),
					$this->format_bytes($downloaded),
					$this->format_bytes($total_size)
				);
				$progress_callback($percent, $message);
			}

			// Small delay to prevent overwhelming server
			usleep(5000); // 5ms
		}

		fclose($handle);

		// Rename temp file to final
		if (!rename($temp_path, $local_path)) {
			throw new \RuntimeException(__('Failed to finalize download file', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Verify downloaded file
		if (!file_exists($local_path) || filesize($local_path) === 0) {
			throw new \RuntimeException(__('Downloaded file is empty or missing', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Validate as ZIP
		$zip = new \ZipArchive();
		$opened = $zip->open($local_path);
		if ($opened !== true) {
			unlink($local_path);
			throw new \RuntimeException(__('Downloaded file is not a valid ZIP archive', 'wp-ultimate-diagnostics-toolkit'));
		}
		$zip->close();

		return $local_path;
	}

	/**
	 * Restore downloaded backup with URL replacement.
	 */
	public function restore_backup(
		string $backup_path,
		array $components,
		string $old_site_url,
		string $new_site_url,
		callable $progress_callback = null
	): array {
		// Verify backup file
		try {
			$safe_path = Security_Guard::normalize_inside_wp($backup_path);
		} catch (\RuntimeException $e) {
			throw new \RuntimeException(__('Invalid backup file path', 'wp-ultimate-diagnostics-toolkit'));
		}

		if (!file_exists($safe_path)) {
			throw new \RuntimeException(__('Backup file not found', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Extract backup first
		$temp_dir = WP_CONTENT_DIR . '/uploads/wudt-migration-' . uniqid() . '/';
		if (!wp_mkdir_p($temp_dir)) {
			throw new \RuntimeException(__('Failed to create temp extraction directory', 'wp-ultimate-diagnostics-toolkit'));
		}

		$progress_callback && $progress_callback(5, __('Extracting backup archive...', 'wp-ultimate-diagnostics-toolkit'));
		$this->extract_archive($safe_path, $temp_dir);
		$progress_callback && $progress_callback(15, __('Backup extracted', 'wp-ultimate-diagnostics-toolkit'));

		// Update database.sql URLs if needed
		if (in_array('database', $components, true) && file_exists($temp_dir . 'database.sql')) {
			$progress_callback && $progress_callback(20, __('Preparing database for import...', 'wp-ultimate-diagnostics-toolkit'));
			$this->update_database_urls($temp_dir . 'database.sql', $old_site_url, $new_site_url);
			$progress_callback && $progress_callback(25, __('Database URLs updated', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Import database
		if (in_array('database', $components, true) && file_exists($temp_dir . 'database.sql')) {
			$progress_callback && $progress_callback(30, __('Importing database...', 'wp-ultimate-diagnostics-toolkit'));
			$this->import_sql_with_progress($temp_dir . 'database.sql', function($percent) use ($progress_callback) {
				if ($progress_callback) {
					$mapped = 30 + (int) ($percent * 0.5); // Map 0-100 to 30-80
					$progress_callback($mapped, __('Importing database...', 'wp-ultimate-diagnostics-toolkit'));
				}
			});
		}

		// Copy files
		$restore_module = new Restore_Module();
		$restored = array();

		if (in_array('plugins', $components, true) && is_dir($temp_dir . 'plugins')) {
			$progress_callback && $progress_callback(80, __('Restoring plugins...', 'wp-ultimate-diagnostics-toolkit'));
			$this->sync_tree($temp_dir . 'plugins', WP_CONTENT_DIR . '/plugins');
			$restored[] = 'plugins';
		}

		if (in_array('themes', $components, true) && is_dir($temp_dir . 'themes')) {
			$progress_callback && $progress_callback(85, __('Restoring themes...', 'wp-ultimate-diagnostics-toolkit'));
			$this->sync_tree($temp_dir . 'themes', WP_CONTENT_DIR . '/themes');
			$restored[] = 'themes';
		}

		if (in_array('core', $components, true) && is_dir($temp_dir . 'wp-core')) {
			$progress_callback && $progress_callback(90, __('Restoring WordPress core...', 'wp-ultimate-diagnostics-toolkit'));
			$this->sync_tree($temp_dir . 'wp-core', ABSPATH, array('wp-content', 'wp-config.php'));
			$restored[] = 'core';
		}

		if (in_array('uploads', $components, true) && is_dir($temp_dir . 'uploads')) {
			$progress_callback && $progress_callback(95, __('Restoring uploads...', 'wp-ultimate-diagnostics-toolkit'));
			$this->sync_tree($temp_dir . 'uploads', WP_CONTENT_DIR . '/uploads');
			$restored[] = 'uploads';
		}

		// Cleanup
		$this->delete_recursive($temp_dir);

		$progress_callback && $progress_callback(100, __('Restore complete', 'wp-ultimate-diagnostics-toolkit'));

		return array(
			'success' => true,
			'restored' => $restored,
		);
	}

	/**
	 * Extract ZIP archive.
	 */
	private function extract_archive(string $archive, string $destination): void {
		$zip = new \ZipArchive();
		$opened = $zip->open($archive);
		
		if ($opened !== true) {
			throw new \RuntimeException(__('Cannot open backup archive', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Security: check for path traversal
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry_name = (string) $zip->getNameIndex($i);
			$normalized = str_replace('\\', '/', $entry_name);
			$normalized = ltrim($normalized, '/');
			
			if (strpos($normalized, '../') !== false || str_contains($normalized, "\0")) {
				$zip->close();
				throw new \RuntimeException(__('Invalid archive entry detected', 'wp-ultimate-diagnostics-toolkit'));
			}
		}

		$zip->extractTo($destination);
		$zip->close();
	}

	/**
	 * Update URLs in database SQL file.
	 */
	private function update_database_urls(string $sql_file, string $old_url, string $new_url): void {
		if ($old_url === $new_url) {
			return;
		}

		// Read, replace, write back
		$content = file_get_contents($sql_file);
		if ($content === false) {
			return;
		}

		// Handle serialized data
		$old_url_esc = preg_quote($old_url, '/');
		$new_url_esc = $new_url;

		// Simple string replacement (may not handle all serialized cases perfectly)
		$content = str_replace($old_url, $new_url, $content);
		
		// Handle URL-encoded versions
		$old_url_encoded = str_replace('/', '%2F', $old_url);
		$new_url_encoded = str_replace('/', '%2F', $new_url);
		$content = str_replace($old_url_encoded, $new_url_encoded, $content);

		file_put_contents($sql_file, $content);
	}

	/**
	 * Import SQL file with progress tracking.
	 */
	private function import_sql_with_progress(string $file, callable $progress_callback = null): void {
		global $wpdb;

		$handle = fopen($file, 'rb');
		if (!$handle) {
			throw new \RuntimeException(__('Cannot read SQL file', 'wp-ultimate-diagnostics-toolkit'));
		}

		$file_size = filesize($file);
		$processed = 0;
		$buffer = '';
		$chunk_size = 8192;
		$statement_count = 0;

		while (!feof($handle)) {
			$data = fread($handle, $chunk_size);
			if ($data === false) {
				break;
			}

			$buffer .= $data;
			$processed += strlen($data);

			// Process complete statements
			while (($pos = strpos($buffer, ';')) !== false) {
				$statement = substr($buffer, 0, $pos);
				$buffer = substr($buffer, $pos + 1);

				$query = trim($statement);
				if (empty($query) || str_starts_with($query, '--')) {
					continue;
				}

				// Skip dangerous queries
				if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
					continue;
				}

				$wpdb->query($query);
				$statement_count++;

				if ($statement_count % 100 === 0) {
					$wpdb->queries = array();
				}
			}

			// Update progress
			if ($progress_callback && $file_size > 0) {
				$percent = (int) (($processed / $file_size) * 100);
				$progress_callback($percent);
			}
		}

		// Process remaining buffer
		$query = trim($buffer);
		if (!empty($query) && !str_starts_with($query, '--')) {
			if (!preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
				$wpdb->query($query);
			}
		}

		fclose($handle);
	}

	/**
	 * Sync directory tree (copy files from source to destination).
	 */
	private function sync_tree(string $source, string $destination, array $exclude = array()): void {
		if (!is_dir($source)) {
			return;
		}

		if (!is_dir($destination)) {
			wp_mkdir_p($destination);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$relative = str_replace($source, '', $item->getPathname());
			$target = $destination . $relative;

			// Check excludes
			foreach ($exclude as $ex) {
				if (strpos($relative, $ex) !== false) {
					continue 2;
				}
			}

			if ($item->isDir()) {
				if (!is_dir($target)) {
					wp_mkdir_p($target);
				}
			} else {
				if (!is_dir(dirname($target))) {
					wp_mkdir_p(dirname($target));
				}
				copy($item->getPathname(), $target);
			}
		}
	}

	/**
	 * Recursively delete directory.
	 */
	private function delete_recursive(string $path): void {
		if (is_file($path)) {
			unlink($path);
			return;
		}

		if (!is_dir($path)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($path);
	}

	/**
	 * Get remote file size via HEAD request.
	 */
	private function get_remote_file_size(string $url, array $headers): int|false {
		$response = wp_remote_head($url, array(
			'headers' => $headers,
			'timeout' => 30,
			'sslverify' => false,
		));

		if (is_wp_error($response)) {
			return false;
		}

		$content_length = wp_remote_retrieve_header($response, 'content-length');
		if (empty($content_length)) {
			return false;
		}

		return (int) $content_length;
	}

	/**
	 * Format bytes to human readable.
	 */
	private function format_bytes(int $bytes, int $precision = 2): string {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		return round($bytes, $precision) . ' ' . $units[$pow];
	}
}
