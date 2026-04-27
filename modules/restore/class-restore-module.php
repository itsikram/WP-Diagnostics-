<?php
/**
 * Backup restore module.
 */

declare(strict_types=1);

namespace WUDT\Modules\Restore;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;
use WUDT\Modules\Backup\Backup_Module;

if (! defined('ABSPATH')) {
	exit;
}

class Restore_Module extends Module_Base {
	public function register_hooks(): void {
		add_action('wp_ajax_wudt_restore_preview', array($this, 'ajax_preview'));
		add_action('wp_ajax_wudt_restore_run', array($this, 'ajax_restore'));
	}

	public function get_key(): string {
		return 'restore_suite';
	}

	public function get_label(): string {
		return __('Restore', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'restore_options' => array('core', 'plugins', 'themes', 'uploads', 'database'),
		);
	}

	public function ajax_preview(): void {
		Security_Guard::assert_ajax_admin();
		$path = isset($_POST['backup_path']) ? (string) wp_unslash($_POST['backup_path']) : '';
		
		if (empty($path)) {
			wp_send_json_error(array('message' => __('No backup path provided.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		
		try {
			$safe = Security_Guard::normalize_inside_wp($path);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
			return;
		}
		
		if (! file_exists($safe)) {
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'wp-ultimate-diagnostics-toolkit')), 404);
			return;
		}
		
		if (! is_readable($safe)) {
			wp_send_json_error(array('message' => __('Backup file is not readable.', 'wp-ultimate-diagnostics-toolkit')), 403);
			return;
		}
		
		try {
			$info = $this->read_archive_metadata($safe);
			wp_send_json_success($info);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}

	public function ajax_restore(): void {
		Security_Guard::assert_ajax_admin();
		$path        = isset($_POST['backup_path']) ? (string) wp_unslash($_POST['backup_path']) : '';
		
		if (empty($path)) {
			wp_send_json_error(array('message' => __('No backup path provided.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		
		$options     = isset($_POST['restore_options']) ? (array) json_decode((string) wp_unslash($_POST['restore_options']), true) : array();
		$safe_mode   = isset($_POST['safe_mode']) && '1' === (string) wp_unslash($_POST['safe_mode']);
		$media_base  = isset($_POST['media_base']) ? sanitize_text_field((string) wp_unslash($_POST['media_base'])) : '';
		
		try {
			$archive = Security_Guard::normalize_inside_wp($path);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
			return;
		}
		
		if (! file_exists($archive)) {
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'wp-ultimate-diagnostics-toolkit')), 404);
			return;
		}
		
		$selected = $this->normalize_restore_options($options);
		
		try {
			$restored = $this->restore_package($archive, $selected, $safe_mode, $media_base);
			wp_send_json_success($restored);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}

	/**
	 * @param array<int,string> $restore_options
	 * @return array<string,mixed>
	 */
	private function restore_package(string $archive, array $restore_options, bool $safe_mode, string $media_base): array {
		$temp = WP_CONTENT_DIR . '/uploads/wudt-restore-' . wp_generate_password(10, false, false) . '/';
		wp_mkdir_p($temp);

		if ($safe_mode) {
			$backup = new Backup_Module();
			$backup->create_backup_package(array('database', 'plugins', 'themes'), false, '');
		}

		// Handle GZIP compressed archives (.zip.gz)
		$zip_file = $archive;
		if (str_ends_with(strtolower($archive), '.zip.gz')) {
			$zip_file = $temp . 'archive.zip';
			$this->decompress_gzip($archive, $zip_file);
		}

		$this->extract_archive($zip_file, $temp);
		$config = $this->read_json($temp . 'config.json');
		$done   = array();

		if (in_array('database', $restore_options, true) && is_file($temp . 'database.sql')) {
			$this->import_sql_file($temp . 'database.sql');
			$done[] = 'database';
		}
		if (in_array('plugins', $restore_options, true) && is_dir($temp . 'plugins')) {
			$this->sync_tree($temp . 'plugins', WP_CONTENT_DIR . '/plugins');
			$done[] = 'plugins';
		}
		if (in_array('themes', $restore_options, true) && is_dir($temp . 'themes')) {
			$this->sync_tree($temp . 'themes', WP_CONTENT_DIR . '/themes');
			$done[] = 'themes';
		}
		if (in_array('core', $restore_options, true) && is_dir($temp . 'wp-core')) {
			$this->sync_tree($temp . 'wp-core', ABSPATH, array('wp-content', 'wp-config.php'));
			$done[] = 'core';
		}
		if (in_array('uploads', $restore_options, true) && is_dir($temp . 'uploads')) {
			$this->sync_tree($temp . 'uploads', WP_CONTENT_DIR . '/uploads');
			$done[] = 'uploads';
		} else {
			$handler = new Media_URL_Handler();
			$handler->preserve_urls((string) ($config['site_url'] ?? ''), $media_base);
		}

		$this->delete_recursive($temp);
		Operation_Logger::log('restore', 'Restore completed', array('archive' => $archive, 'options' => $restore_options, 'safe_mode' => $safe_mode));
		return array('restored' => $done, 'safe_mode' => $safe_mode);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_archive_metadata(string $archive): array {
		$list  = array();
		$zip   = new \ZipArchive();
		$temp_zip = null; // Will hold temp file path for GZIP decompression
		
		// Basic file validation
		if (! file_exists($archive)) {
			throw new \RuntimeException(__('Backup file does not exist: ', 'wp-ultimate-diagnostics-toolkit') . $archive);
		}
		
		$file_size = filesize($archive);
		if ($file_size === false || $file_size === 0) {
			throw new \RuntimeException(__('Backup file is empty or cannot be read.', 'wp-ultimate-diagnostics-toolkit'));
		}
		
		// Check if file is actually a zip file (extension check first - more reliable on Windows)
		$lowercase_path = strtolower($archive);
		$has_zip_ext = str_ends_with($lowercase_path, '.zip') || str_ends_with($lowercase_path, '.zip.gz');
		
		// MIME type check as secondary validation (may not work on all Windows setups)
		$mime_valid = false;
		$mime_type = null;
		if (function_exists('finfo_open')) {
			$finfo = @finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo) {
				$mime_type = @finfo_file($finfo, $archive);
				finfo_close($finfo);
				$valid_zip_types = array('application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'application/gzip');
				$mime_valid = in_array($mime_type, $valid_zip_types, true);
			}
		}
		
		// If no zip extension and MIME check failed/invalid, reject it
		if (! $has_zip_ext && ! $mime_valid) {
			throw new \RuntimeException(__('File is not a valid ZIP archive. Must have .zip extension.', 'wp-ultimate-diagnostics-toolkit'));
		}
		
		// Handle GZIP compressed archives for preview
		$zip_file = $archive;
		if (str_ends_with($lowercase_path, '.zip.gz')) {
			$temp_zip = wp_normalize_path(WP_CONTENT_DIR . '/uploads/wudt-preview-' . wp_generate_password(10, false, false) . '.zip');
			try {
				$this->decompress_gzip($archive, $temp_zip);
				$zip_file = $temp_zip;
			} catch (\RuntimeException $e) {
				throw new \RuntimeException(__('Could not decompress GZIP archive: ', 'wp-ultimate-diagnostics-toolkit') . $e->getMessage());
			}
		}
		
		$opened = $zip->open($zip_file);
		if (true !== $opened) {
			$error_messages = array(
				\ZipArchive::ER_EXISTS => __('File already exists.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INCONS => __('Zip archive inconsistent.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INVAL  => __('Invalid argument.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_MEMORY => __('Memory allocation failure.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOENT  => __('File not found.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOZIP  => __('Not a zip archive.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_OPEN   => __('Cannot open file.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_READ   => __('Read error.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_SEEK   => __('Seek error.', 'wp-ultimate-diagnostics-toolkit'),
			);
			
			// Log detailed error for debugging
			$error_msg = isset($error_messages[$opened]) ? $error_messages[$opened] : 'Unknown error code: ' . $opened;
			error_log(sprintf(
				'[WUDT Restore] Failed to open archive: %s | Error: %s (code: %d) | MIME: %s | Size: %d | Readable: %s | PHP: %s',
				$archive,
				$error_msg,
				$opened,
				$mime_type ?? 'unknown',
				filesize($archive) ?: 0,
				is_readable($archive) ? 'yes' : 'no',
				phpversion()
			));
			
			// Provide user-friendly error with code
			$display_msg = isset($error_messages[$opened]) 
				? $error_messages[$opened] 
				: sprintf(__('Unable to open archive. Error code: %d. Please check the file exists and is a valid ZIP.', 'wp-ultimate-diagnostics-toolkit'), $opened);
			
			throw new \RuntimeException($display_msg);
		}
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (is_array($stat) && isset($stat['name'])) {
				$list[] = (string) $stat['name'];
			}
		}
		$zip->close();
		
		// Clean up temp file if we decompressed GZIP
		if ($temp_zip && file_exists($temp_zip)) {
			unlink($temp_zip);
		}
		
		return array(
			'archive' => $archive,
			'files'   => array_slice($list, 0, 200),
			'contains'=> array(
				'database' => in_array('database.sql', $list, true),
				'config'   => in_array('config.json', $list, true),
			),
		);
	}

	private function extract_archive(string $archive, string $destination): void {
		$zip = new \ZipArchive();
		$opened = $zip->open($archive);
		if (true !== $opened) {
			$error_messages = array(
				\ZipArchive::ER_EXISTS => __('File already exists.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INCONS => __('Zip archive inconsistent.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INVAL  => __('Invalid argument.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_MEMORY => __('Memory allocation failure.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOENT  => __('File not found.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOZIP  => __('Not a zip archive.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_OPEN   => __('Cannot open file.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_READ   => __('Read error.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_SEEK   => __('Seek error.', 'wp-ultimate-diagnostics-toolkit'),
			);
			
			$error_msg = isset($error_messages[$opened]) ? $error_messages[$opened] : 'Unknown error code: ' . $opened;
			error_log(sprintf('[WUDT Restore] Extract failed: %s | Error: %s (code: %d)', $archive, $error_msg, $opened));
			
			$display_msg = isset($error_messages[$opened]) 
				? $error_messages[$opened] 
				: sprintf(__('Could not open backup archive. Error code: %d', 'wp-ultimate-diagnostics-toolkit'), $opened);
			throw new \RuntimeException($display_msg);
		}
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry_name = (string) $zip->getNameIndex($i);
			Security_Guard::safe_zip_entry_name($entry_name);
			$target = wp_normalize_path($destination . $entry_name);
			$base   = rtrim(wp_normalize_path($destination), '/') . '/';
			if (0 !== strpos($target, $base)) {
				$zip->close();
				throw new \RuntimeException('Blocked ZIP traversal entry.');
			}
		}
		$zip->extractTo($destination);
		$zip->close();
	}

	private function import_sql_file(string $file): void {
		global $wpdb;
		$sql = file_get_contents($file);
		if (false === $sql) {
			throw new \RuntimeException('Could not read SQL file.');
		}
		$parts = preg_split('/;\s*\n/', (string) $sql) ?: array();
		foreach ($parts as $statement) {
			$query = trim($statement);
			if ('' === $query || str_starts_with($query, '--')) {
				continue;
			}
			if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
				continue;
			}
			$wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * @param array<int,string> $exclude_roots
	 */
	private function sync_tree(string $source, string $target, array $exclude_roots = array()): void {
		if (! is_dir($source)) {
			return;
		}
		wp_mkdir_p($target);
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($it as $item) {
			$src = wp_normalize_path((string) $item->getPathname());
			$rel = ltrim(str_replace(wp_normalize_path($source), '', $src), '/');
			$top = explode('/', $rel)[0] ?? '';
			if (in_array($top, $exclude_roots, true)) {
				continue;
			}
			$dest = wp_normalize_path(trailingslashit($target) . $rel);
			$dest = Security_Guard::normalize_inside_wp($dest);
			if ($item->isDir()) {
				wp_mkdir_p($dest);
			} else {
				@copy($src, $dest);
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_json(string $file): array {
		if (! is_file($file)) {
			return array();
		}
		$raw = file_get_contents($file);
		$val = false !== $raw ? json_decode($raw, true) : null;
		return is_array($val) ? $val : array();
	}

	/**
	 * @param array<int,string> $raw
	 * @return array<int,string>
	 */
	private function normalize_restore_options(array $raw): array {
		$allowed = array('core', 'plugins', 'themes', 'uploads', 'database');
		$clean   = array_values(array_intersect($allowed, array_map('sanitize_key', $raw)));
		return empty($clean) ? array('database') : $clean;
	}

	private function delete_recursive(string $path): void {
		if (is_file($path)) {
			unlink($path);
			return;
		}
		if (! is_dir($path)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $item) {
			$item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
		}
		rmdir($path);
	}

	/**
	 * Decompress GZIP file to output file.
	 *
	 * @throws \RuntimeException If decompression fails.
	 */
	private function decompress_gzip(string $input, string $output): void {
		$source = @gzopen($input, 'rb');
		if (! is_resource($source)) {
			throw new \RuntimeException(__('Could not open GZIP archive for reading: ', 'wp-ultimate-diagnostics-toolkit') . $input);
		}

		$dest = @fopen($output, 'wb');
		if (! is_resource($dest)) {
			gzclose($source);
			throw new \RuntimeException(__('Could not create output file: ', 'wp-ultimate-diagnostics-toolkit') . $output);
		}

		while (! gzeof($source)) {
			$data = gzread($source, 8192);
			if (false === $data) {
				gzclose($source);
				fclose($dest);
				unlink($output);
				throw new \RuntimeException(__('Error reading GZIP data from: ', 'wp-ultimate-diagnostics-toolkit') . $input);
			}
			if (fwrite($dest, $data) === false) {
				gzclose($source);
				fclose($dest);
				unlink($output);
				throw new \RuntimeException(__('Error writing decompressed data to: ', 'wp-ultimate-diagnostics-toolkit') . $output);
			}
		}

		gzclose($source);
		fclose($dest);

		// Verify the output file was created
		if (! file_exists($output) || filesize($output) === 0) {
			throw new \RuntimeException(__('GZIP decompression failed - output file is empty or missing.', 'wp-ultimate-diagnostics-toolkit'));
		}
	}
}
