<?php
/**
 * Enterprise backup module.
 */

declare(strict_types=1);

namespace WUDT\Modules\Backup;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

class Backup_Module extends Module_Base {
	private const OPTION_LOGS = 'wudt_backup_logs';
	private const OPTION_SCHEDULE = 'wudt_backup_schedule';
	private const TRANSIENT_PROGRESS = 'wudt_backup_progress';

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_backup_create', array($this, 'ajax_create_backup'));
		add_action('wp_ajax_wudt_backup_list', array($this, 'ajax_list_backups'));
		add_action('wp_ajax_wudt_backup_schedule', array($this, 'ajax_schedule_backup'));
		add_action('wp_ajax_wudt_backup_delete', array($this, 'ajax_delete_backup'));
		add_action('wp_ajax_wudt_backup_progress', array($this, 'ajax_get_progress'));
		add_action('wp_ajax_wudt_backup_download', array($this, 'ajax_download_backup'));
		add_action('wudt_scheduled_backup_event', array($this, 'run_scheduled_backup'));
	}

	public function get_key(): string {
		return 'backup_suite';
	}

	public function get_label(): string {
		return __('Backups', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'components' => array('core', 'plugins', 'themes', 'uploads', 'database'),
			'schedule'   => (array) get_option(self::OPTION_SCHEDULE, array()),
			'history'    => array_slice((array) get_option(self::OPTION_LOGS, array()), -100),
			'backups'    => $this->list_backups(),
		);
	}

	public function ajax_create_backup(): void {
		Security_Guard::assert_ajax_admin();

		$components = isset($_POST['components']) ? (array) json_decode((string) wp_unslash($_POST['components']), true) : array();
		$gzip       = isset($_POST['gzip']) && '1' === (string) wp_unslash($_POST['gzip']);
		$password   = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

		if (empty($components)) {
			wp_send_json_error(array('message' => __('No components selected for backup.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}

		try {
			$backup = $this->create_backup_package($components, $gzip, $password);
			wp_send_json_success($backup);
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}

	public function ajax_list_backups(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('backups' => $this->list_backups()));
	}

	public function ajax_schedule_backup(): void {
		Security_Guard::assert_ajax_admin();

		$config = isset($_POST['config']) ? (array) json_decode((string) wp_unslash($_POST['config']), true) : array();
		$enabled = isset($config['enabled']) && '1' === $config['enabled'];

		update_option(self::OPTION_SCHEDULE, $config, false);
		wp_clear_scheduled_hook('wudt_scheduled_backup_event');
		if ($enabled) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, $config['interval'], 'wudt_scheduled_backup_event');
		}
		Operation_Logger::log('backup', 'Backup schedule updated', $config);
		wp_send_json_success($config);
	}

	public function ajax_delete_backup(): void {
		Security_Guard::assert_ajax_admin();

		$path = isset($_POST['backup_path']) ? (string) wp_unslash($_POST['backup_path']) : '';

		if (empty($path)) {
			wp_send_json_error(array('message' => __('No backup path specified.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		// For backup deletions, validate the path is within uploads/wudt-backups directory
		$upload_dir = wp_get_upload_dir();
		$backup_base = trailingslashit($upload_dir['basedir']) . 'wudt-backups/';
		
		$normalized_path = wp_normalize_path($path);
		$normalized_backup_base = wp_normalize_path($backup_base);
		
		// Check if path is within backup directory (case-insensitive for Windows)
		if (strpos(strtolower($normalized_path), strtolower($normalized_backup_base)) !== 0) {
			// Try using Security_Guard as fallback
			try {
				$safe = Security_Guard::normalize_inside_wp($path);
			} catch (\RuntimeException $e) {
				Operation_Logger::log('backup', 'Delete path rejected', array(
					'path' => $path,
					'normalized' => $normalized_path,
					'backup_base' => $normalized_backup_base,
				));
				wp_send_json_error(array('message' => __('Invalid backup file path.', 'wp-ultimate-diagnostics-toolkit')), 403);
				return;
			}
			$normalized_path = $safe;
		}
		
		$safe = $normalized_path;

		if (! file_exists($safe)) {
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'wp-ultimate-diagnostics-toolkit')), 404);
			return;
		}

		// Handle both files and directories (backup could be a .zip file or a directory)
		$deleted = false;
		if (is_dir($safe)) {
			// Delete directory recursively
			$this->delete_recursive($safe);
			$deleted = ! is_dir($safe);
		} else {
			// Delete file
			$deleted = @unlink($safe);
		}

		if (! $deleted) {
			$error = error_get_last();
			$error_msg = $error ? $error['message'] : 'Unknown error';
			wp_send_json_error(array(
				'message' => __('Failed to delete backup file: ', 'wp-ultimate-diagnostics-toolkit') . $error_msg
			), 500);
			return;
		}

		Operation_Logger::log('backup', 'Backup deleted', array('path' => $safe));
		wp_send_json_success(array('message' => __('Backup deleted successfully.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();
		$progress = get_transient(self::TRANSIENT_PROGRESS);
		if (! is_array($progress)) {
			$progress = array('status' => 'idle', 'percent' => 0, 'message' => '');
		}
		wp_send_json_success($progress);
	}

	public function ajax_download_backup(): void {
		Security_Guard::assert_ajax_admin();

		$path = isset($_GET['file']) ? (string) wp_unslash($_GET['file']) : '';

		// For backup downloads, validate the path is within uploads/wudt-backups directory
		$upload_dir = wp_get_upload_dir();
		$backup_base = trailingslashit($upload_dir['basedir']) . 'wudt-backups/';
		
		$normalized_path = wp_normalize_path($path);
		$normalized_backup_base = wp_normalize_path($backup_base);
		
		// Check if path is within backup directory
		if (strpos(strtolower($normalized_path), strtolower($normalized_backup_base)) !== 0) {
			// Try using Security_Guard as fallback
			try {
				$safe = Security_Guard::normalize_inside_wp($path);
			} catch (\RuntimeException $e) {
				Operation_Logger::log('backup', 'Download path rejected', array(
					'path' => $path,
					'normalized' => $normalized_path,
					'backup_base' => $normalized_backup_base,
				));
				wp_send_json_error(array('message' => __('Invalid backup file path.', 'wp-ultimate-diagnostics-toolkit')), 403);
				return;
			}
			$normalized_path = $safe;
		}
		
		$safe = $normalized_path;

		if (! file_exists($safe)) {
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'wp-ultimate-diagnostics-toolkit')), 404);
			return;
		}

		if (! is_readable($safe)) {
			wp_send_json_error(array('message' => __('Backup file is not readable.', 'wp-ultimate-diagnostics-toolkit')), 403);
			return;
		}

		$filename = basename($safe);
		$file_size = filesize($safe);

		// Clear any output buffers
		while (ob_get_level()) {
			ob_end_clean();
		}

		// Set headers for download
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . $file_size);
		header('Cache-Control: no-cache, must-revalidate');
		header('Pragma: no-cache');

		readfile($safe);
		exit;
	}

	public function run_scheduled_backup(): void {
		$config = (array) get_option(self::OPTION_SCHEDULE, array());
		if (empty($config) || empty($config['components'])) {
			return;
		}

		try {
			$this->create_backup_package($config['components'], false, '');
			Operation_Logger::log('backup', 'Scheduled backup completed', $config);
		} catch (\Throwable $e) {
			Operation_Logger::log('backup', 'Scheduled backup failed', array('error' => $e->getMessage()));
		}
	}

	private function set_progress(string $status, float $percent, string $message): void {
		set_transient(
			self::TRANSIENT_PROGRESS,
			array(
				'status'    => $status,
				'percent'   => max(0, min(100, (int) $percent)),
				'message'   => $message,
				'timestamp' => time(),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * @param array<int,string> $components
	 * @return array<string,mixed>
	 */
	public function create_backup_package(array $components, bool $gzip, string $password): array {
		// Increase memory and execution time for large sites
		$original_memory_limit = ini_get('memory_limit');
		$original_max_execution_time = ini_get('max_execution_time');
		
		// Set generous limits for backup operations
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('admin');
		} else {
			@ini_set('memory_limit', '1024M');
		}
		
		// Set execution time to 0 (unlimited) or max 600 seconds
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit(600);
		}
		
		$paths = wp_get_upload_dir();
		$dir   = trailingslashit($paths['basedir']) . 'wudt-backups/';
		
		// Debug logging
		Operation_Logger::log('backup', 'Backup starting', array(
			'base_dir' => $dir,
			'wp_content_dir' => WP_CONTENT_DIR,
			'upload_dir' => $paths['basedir'],
			'memory_limit' => ini_get('memory_limit'),
			'max_execution_time' => ini_get('max_execution_time'),
		));
		
		if (! wp_mkdir_p($dir)) {
			throw new \RuntimeException('Failed to create base backup directory: ' . $dir);
		}
		
		// Create filename in format: site_url-hh-mm_dd-mm-yy with random suffix for uniqueness
		// Note: Using hyphens instead of colons for Windows compatibility
		$site_url = parse_url(home_url('/'), PHP_URL_HOST);
		$site_url = preg_replace('/[^a-zA-Z0-9_-]/', '_', $site_url);
		$unique   = wp_generate_password(6, false, false);
		
		// Use hyphens instead of colons for Windows compatibility (H:i becomes H-i)
		$stamp      = gmdate('H-i_d-m-y');
		$filename   = $site_url . '-' . $stamp . '-' . $unique;
		
		// Directory name uses same format
		$dir_stamp  = $stamp;
		$dir_name   = $site_url . '-' . $dir_stamp . '-' . $unique;
		
		$work_dir   = $dir . 'tmp-' . wp_generate_password(10, false, false) . '/';
		$backup_dir = $dir . 'backup-' . $dir_name . '/';
		
		// Debug paths
		$zip_file_path = $backup_dir . $filename . '.zip';
		Operation_Logger::log('backup', 'Backup paths', array(
			'work_dir' => $work_dir,
			'backup_dir' => $backup_dir,
			'filename' => $filename,
			'zip_file' => $zip_file_path,
			'has_colon' => strpos($filename, ':') !== false,
			'stamp' => $stamp,
		));
		
		// Extra safety: ensure no colons in filename (Windows compatibility)
		if (strpos($filename, ':') !== false) {
			error_log('WUDT Backup ERROR: Filename contains colon! This will fail on Windows.');
			$filename = str_replace(':', '-', $filename);
			error_log('WUDT Backup: Fixed filename to: ' . $filename);
		}
		
		$this->set_progress('preparing', 5, __('Creating backup directories...', 'wp-ultimate-diagnostics-toolkit'));
		
		// Create directories with error checking
		if (! wp_mkdir_p($work_dir)) {
			throw new \RuntimeException('Failed to create work directory: ' . $work_dir);
		}
		if (! wp_mkdir_p($backup_dir)) {
			throw new \RuntimeException('Failed to create backup directory: ' . $backup_dir);
		}
		
		// Verify directories were created
		if (! is_dir($work_dir)) {
			throw new \RuntimeException('Work directory does not exist after creation: ' . $work_dir);
		}
		if (! is_dir($backup_dir)) {
			throw new \RuntimeException('Backup directory does not exist after creation: ' . $backup_dir);
		}
		
		// Clean up old orphaned tmp directories (older than 1 hour)
		$this->cleanup_old_tmp_dirs($dir);
		
		global $wpdb;
		$db_file = $work_dir . 'database.sql';
		$config  = array(
			'created_at'   => gmdate('c'),
			'site_url'     => home_url('/'),
			'table_prefix' => $wpdb->prefix,
			'components'   => $components,
			'format'       => $gzip ? 'zip+gz' : 'zip',
			'incremental'  => false,
			'encrypted'    => '' !== $password,
			'password_hint'=> '' !== $password ? 'configured' : 'none',
		);
		file_put_contents($work_dir . 'config.json', (string) wp_json_encode($config, JSON_PRETTY_PRINT));

		// Progress in 5% increments
		$progress = 10;
		$progress_step = 5;

		if (in_array('database', $components, true)) {
			$this->set_progress('running', $progress, __('Backing up database...', 'wp-ultimate-diagnostics-toolkit'));
			$this->build_sql_dump_to_file($db_file);
			$progress += $progress_step;
		}
		if (in_array('core', $components, true)) {
			$this->set_progress('running', $progress, __('Copying WordPress core files...', 'wp-ultimate-diagnostics-toolkit'));
			// Exclude wp-content (contains plugins, themes, uploads), .git, and other non-core directories
			$this->copy_tree(ABSPATH, $work_dir . 'wp-core/', array('wp-content', '.git', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads', 'wp-content/upgrade', 'wp-content/backup-db', 'wp-content/cache', 'wp-content/et-cache', 'wp-content/wflog'));
			$progress += $progress_step;
		}
		if (in_array('plugins', $components, true)) {
			$this->set_progress('running', $progress, __('Copying plugins...', 'wp-ultimate-diagnostics-toolkit'));
			$this->copy_tree(WP_CONTENT_DIR . '/plugins', $work_dir . 'plugins/');
			$progress += $progress_step;
		}
		if (in_array('themes', $components, true)) {
			$this->set_progress('running', $progress, __('Copying themes...', 'wp-ultimate-diagnostics-toolkit'));
			$this->copy_tree(WP_CONTENT_DIR . '/themes', $work_dir . 'themes/');
			$progress += $progress_step;
		}
		if (in_array('uploads', $components, true)) {
			$this->set_progress('running', $progress, __('Copying uploads...', 'wp-ultimate-diagnostics-toolkit'));
			// Exclude wudt-backups folder to avoid backing up backup files (prevents recursion)
			$this->copy_tree(WP_CONTENT_DIR . '/uploads', $work_dir . 'uploads/', array('wudt-backups'));
			$progress += $progress_step;
		}

		$this->set_progress('running', 80, __('Creating archive...', 'wp-ultimate-diagnostics-toolkit'));
		$zip_file = $backup_dir . $filename . '.zip';
		$this->create_zip_archive($work_dir, $zip_file, $password);

		Operation_Logger::log('backup', 'After zip creation', array(
			'zip_file' => $zip_file,
			'zip_exists' => file_exists($zip_file),
			'zip_size' => file_exists($zip_file) ? filesize($zip_file) : 0,
		));

		$final_file = $zip_file;
		if ($gzip) {
			$this->set_progress('running', 85, __('Compressing with GZIP...', 'wp-ultimate-diagnostics-toolkit'));
			$gz_file = $zip_file . '.gz';
			$input   = fopen($zip_file, 'rb');
			$output  = gzopen($gz_file, 'wb9');
			if (is_resource($input) && false !== $output) {
				$total_size = filesize($zip_file);
				$processed = 0;
				$last_percent = 80;
				while (! feof($input)) {
					$chunk = fread($input, 65536);
					if (false === $chunk) {
						break;
					}
					gzwrite($output, $chunk);
					$processed += strlen($chunk);
					$percent = 80 + floor(($processed / $total_size) * 15);
					if ($percent > $last_percent) {
						$this->set_progress('running', $percent, __('Compressing with GZIP...', 'wp-ultimate-diagnostics-toolkit'));
						$last_percent = $percent;
					}
				}
				fclose($input);
				gzclose($output);
				unlink($zip_file);
				$final_file = $gz_file;
			}
		}

		$this->set_progress('finalizing', 100, __('Finalizing backup...', 'wp-ultimate-diagnostics-toolkit'));
		$this->delete_recursive($work_dir);

		$file_size = filesize($final_file);

		// Verify file was created
		if (! file_exists($final_file)) {
			throw new \RuntimeException('Backup file was not created: ' . $final_file);
		}

		$entry = array(
			'time'       => current_time('mysql'),
			'file'       => $final_file,
			'url'        => str_replace($paths['basedir'], $paths['baseurl'], $final_file),
			'components' => $components,
			'size'       => (false === $file_size) ? 0 : $file_size,
			'name'       => basename($final_file),
			'time_formatted' => current_time('H:i d-m-Y'),
		);

		// Debug success
		Operation_Logger::log('backup', 'Backup created successfully', array(
			'file' => $final_file,
			'size' => $file_size,
			'components' => $components,
		));

		// Store backup info in progress for auto-download
		$progress_data = array(
			'status'    => 'complete',
			'percent'   => 100,
			'message'   => __('Backup complete!', 'wp-ultimate-diagnostics-toolkit'),
			'timestamp' => time(),
			'backup'    => $entry,
		);
		set_transient(self::TRANSIENT_PROGRESS, $progress_data, 5 * MINUTE_IN_SECONDS);

		$this->push_log($entry);

		// Restore original limits
		@ini_set('memory_limit', $original_memory_limit);
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit((int) $original_max_execution_time);
		}

		return $entry;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_backups(): array {
		$paths = wp_get_upload_dir();
		$dir   = trailingslashit($paths['basedir']) . 'wudt-backups/';
		if (! is_dir($dir)) {
			return array();
		}

		$it      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
		$all_files = array();

		foreach ($it as $file) {
			if ($file->isFile() && preg_match('/\.zip(\.gz)?$/', $file->getFilename())) {
				$name = $file->getFilename();
				$path = $file->getPathname();
				$all_files[] = array('name' => $name, 'path' => $path, 'file' => $file);
			}
		}

		// Sort by modification time (newest first)
		usort($all_files, function ($a, $b) {
			return $b['file']->getMTime() - $a['file']->getMTime();
		});

		// Second pass: prefer .zip.gz over .zip for same backup
		$seen = array();
		$items = array();
		foreach ($all_files as $item) {
			$name = $item['name'];
			$path = $item['path'];
			$file_obj = $item['file'];
			$base_name = preg_replace('/\.zip(\.gz)?$/', '', $name);
			if (isset($seen[$base_name])) {
				// Prefer .zip.gz over .zip
				if (str_ends_with($name, '.zip.gz')) {
					continue; // Keep the .zip.gz from previous iteration
				}
				// This is .zip and we already have .zip.gz, skip it
				continue;
			}
			$seen[$base_name] = true;

			$mtime = (int) $file_obj->getMTime();

			// Parse time from filename: site_url-hh-mm_dd-mm-yy-unique.zip
			$time_formatted = gmdate('H:i d-m-Y', $mtime);
			if (preg_match('/-(\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})-[a-zA-Z0-9]{6}\.zip/', $name, $matches)) {
				// Convert hyphens back to colons for display
				$hour_min = str_replace('-', ':', $matches[1]);
				$time_formatted = $hour_min . ' ' . $matches[2];
			}

			$items[] = array(
				'name'           => $name,
				'path'           => $path,
				'url'            => str_replace($paths['basedir'], $paths['baseurl'], $path),
				'size'           => (int) $file_obj->getSize(),
				'time'           => gmdate('Y-m-d H:i:s', $mtime),
				'time_formatted' => $time_formatted,
			);
		}
		return array_slice(array_reverse($items), 0, 100);
	}

	/**
	 * Build SQL dump directly to file for memory efficiency with large databases
	 */
	private function build_sql_dump_to_file(string $file): void {
		global $wpdb;

		$handle = fopen($file, 'wb');
		if (false === $handle) {
			throw new \RuntimeException('Could not create database dump file: ' . $file);
		}

		// Write header
		fwrite($handle, "-- WUDT Database Backup\n");
		fwrite($handle, "-- Generated: " . gmdate('c') . "\n");
		fwrite($handle, "-- Site: " . home_url('/') . "\n\n");

		// Get all tables
		$tables = $wpdb->get_col('SHOW TABLES');
		foreach ($tables as $table) {
			$table_name = sanitize_text_field($table);
			if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
				continue;
			}

			fwrite($handle, "\n-- Table: {$table_name}\n");
			fwrite($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");

			$create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
			if ($create_table && isset($create_table[1])) {
				fwrite($handle, $create_table[1] . ";\n\n");
			}

			// Export data
			$row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
			if ($row_count > 0) {
				$batch_size = 1000;
				$offset = 0;
				while ($offset < $row_count) {
					$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $batch_size, $offset), ARRAY_A);
					foreach ($rows as $row) {
						$values = array();
						foreach ($row as $value) {
							if (null === $value) {
								$values[] = 'NULL';
							} else {
								$values[] = "'" . esc_sql($value) . "'";
							}
						}
						fwrite($handle, "INSERT INTO `{$table_name}` VALUES (" . implode(', ', $values) . ");\n");
					}
					$offset += $batch_size;
				}
				fwrite($handle, "\n");
			}
		}

		fclose($handle);
	}

	/**
	 * @param array<int,string> $exclude_roots
	 */
	private function copy_tree(string $source, string $dest, array $exclude_roots = array()): void {
		if (! is_dir($source)) {
			return;
		}

		// CRITICAL SAFETY: Never allow source to be inside destination (prevents infinite recursion)
		$dest_real = realpath($dest);
		$source_normalized = wp_normalize_path($source);
		if ($dest_real && strpos($source_normalized, wp_normalize_path($dest_real)) === 0) {
			throw new \RuntimeException('CRITICAL: Source cannot be inside destination directory');
		}

		wp_mkdir_p($dest);

		// Normalize exclude roots for consistent comparison (lowercase, forward slashes, no trailing slash)
		$normalized_excludes = array();
		foreach ($exclude_roots as $root) {
			$normalized_excludes[] = rtrim(str_replace('\\', '/', strtolower($root)), '/');
		}
		
		// Log exclusions for debugging
		Operation_Logger::log('backup', 'copy_tree exclusions', array(
			'excludes' => $normalized_excludes,
			'source' => $source,
			'dest' => $dest
		));

		$copied_count = 0;
		$excluded_count = 0;
		
		// Manual stack-based traversal for better control on Windows
		$stack = array(array('src' => $source, 'dst' => $dest, 'rel' => ''));
		
		while (!empty($stack)) {
			$current = array_pop($stack);
			$src_dir = $current['src'];
			$dst_dir = $current['dst'];
			$rel_path = $current['rel'];
			
			if (!is_dir($src_dir)) {
				continue;
			}
			
			$handle = @opendir($src_dir);
			if (!$handle) {
				continue;
			}
			
			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				
				$src_path = $src_dir . '/' . $entry;
				$dst_path = $dst_dir . '/' . $entry;
				$new_rel = $rel_path === '' ? $entry : $rel_path . '/' . $entry;
				$new_rel_normalized = str_replace('\\', '/', strtolower($new_rel));
				
				// Check if this item should be excluded
				$is_excluded = false;
				foreach ($normalized_excludes as $exclude_root) {
					// Exact match or starts with exclude_root/
					if ($new_rel_normalized === $exclude_root || strpos($new_rel_normalized, $exclude_root . '/') === 0) {
						$is_excluded = true;
						$excluded_count++;
						break;
					}
				}
				
				if ($is_excluded) {
					continue;
				}
				
				// Double-check: never copy from destination to itself (prevent recursion)
				if (strpos(wp_normalize_path($src_path), wp_normalize_path($dest)) === 0) {
					closedir($handle);
					throw new \RuntimeException('CRITICAL: Attempted to copy from destination: ' . $src_path);
				}
				
				if (is_dir($src_path)) {
					// Create directory and add to stack for traversal
					wp_mkdir_p($dst_path);
					$stack[] = array('src' => $src_path, 'dst' => $dst_path, 'rel' => $new_rel);
				} else {
					// Copy file
					// Skip large zip files (>25MB) to avoid memory issues
					if (preg_match('/\.zip(\.gz)?$/i', $entry)) {
						$file_size = filesize($src_path);
						if ($file_size === false || $file_size > (25 * 1024 * 1024)) {
							continue;
						}
					}
					if (@copy($src_path, $dst_path)) {
						$copied_count++;
					}
				}
			}
			
			closedir($handle);
		}
		
		Operation_Logger::log('backup', 'copy_tree completed', array(
			'copied' => $copied_count,
			'excluded' => $excluded_count,
			'source' => $source,
			'dest' => $dest
		));
	}

	private function create_zip_archive(string $source_dir, string $zip_file, string $password = ''): void {
		$zip = new \ZipArchive();
		$zip_dir = dirname($zip_file);
		
		if (! is_dir($zip_dir)) {
			if (! wp_mkdir_p($zip_dir)) {
				throw new \RuntimeException('Failed to create zip directory: ' . $zip_dir);
			}
		}
		
		// Now use realpath for Windows compatibility (directory should exist now)
		$zip_dir_real = realpath($zip_dir);
		if ($zip_dir_real === false) {
			throw new \RuntimeException('Could not resolve zip directory path: ' . $zip_dir);
		}
		$zip_file = $zip_dir_real . DIRECTORY_SEPARATOR . basename($zip_file);
		
		// Verify directory exists and is writable (use original path for check)
		if (! is_dir($zip_dir)) {
			throw new \RuntimeException('Zip directory does not exist: ' . $zip_dir);
		}
		if (! is_writable($zip_dir)) {
			throw new \RuntimeException('Zip directory is not writable: ' . $zip_dir);
		}
		
		// On Windows, delete existing file first to avoid "renaming temporary file failed" error
		if (file_exists($zip_file) && is_writable($zip_file)) {
			@unlink($zip_file);
		}
		
		$opened = $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if (true !== $opened) {
			$error_messages = array(
				\ZipArchive::ER_EXISTS => __('File already exists.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INCONS => __('Zip archive inconsistent.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_INVAL => __('Invalid argument.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_MEMORY => __('Malloc failure.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOENT => __('No such file.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_NOZIP => __('Not a zip archive.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_OPEN => __('Can\'t open file.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_READ => __('Read error.', 'wp-ultimate-diagnostics-toolkit'),
				\ZipArchive::ER_SEEK => __('Seek error.', 'wp-ultimate-diagnostics-toolkit'),
			);
			$error_msg = $error_messages[$opened] ?? sprintf(__('Unknown error (code: %d)', 'wp-ultimate-diagnostics-toolkit'), $opened);
			throw new \RuntimeException($error_msg . ': ' . $zip_file . ' (Error code: ' . $opened . ')');
		}
		if ('' !== $password && method_exists($zip, 'setPassword')) {
			$zip->setPassword($password);
		}
		
		// Ensure source directory exists
		if (! is_dir($source_dir)) {
			throw new \RuntimeException('Source directory does not exist: ' . $source_dir);
		}
		
		$source_dir_normalized = wp_normalize_path($source_dir);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$local_path = wp_normalize_path($item->getPathname());
			$relative_path = substr($local_path, strlen($source_dir_normalized));
			$relative_path = ltrim($relative_path, '/');
			$relative_path = str_replace('\\', '/', $relative_path);

			if ($item->isDir()) {
				$zip->addEmptyDir($relative_path);
			} else {
				$zip->addFile($local_path, $relative_path);
			}
		}

		$close_result = $zip->close();
		if (false === $close_result) {
			$status = $zip->getStatus();
			$status_sys = $zip->getStatusString();
			error_log('WUDT Backup: Zip close returned false. Status: ' . $status . ', System: ' . $status_sys);
			
			// Check if file was actually created despite close() returning false
			if (file_exists($zip_file) && filesize($zip_file) > 0) {
				// File was created successfully despite the error
				error_log('WUDT Backup: Zip file created successfully despite close() error. Size: ' . filesize($zip_file));
				return;
			}
			
			throw new \RuntimeException('Failed to close zip archive properly: ' . $status_sys);
		}
	}

	private function delete_recursive(string $path): void {
		$normalized_path = wp_normalize_path($path);
		$basename = basename($normalized_path);
		
		// Check: only delete paths that contain 'tmp-' or 'wudt' or 'backup-'
		// This ensures we only delete our own temporary/work directories
		$is_safe_temp = (strpos($basename, 'tmp-') === 0 || 
		           strpos($basename, 'wudt') !== false || 
		           strpos($basename, 'backup-') === 0 ||
		           strpos($normalized_path, 'wudt-backups') !== false);
		
		// CRITICAL SAFETY: Never delete WordPress core directories
		// But allow temp directories inside wp-content/uploads
		$protected_paths = array(
			wp_normalize_path(ABSPATH),
			wp_normalize_path(ABSPATH . 'wp-admin'),
			wp_normalize_path(ABSPATH . 'wp-includes'),
			wp_normalize_path(ABSPATH . 'wp-content'),
			wp_normalize_path(ABSPATH . 'wp-content/plugins'),
			wp_normalize_path(ABSPATH . 'wp-content/themes'),
		);
		
		foreach ($protected_paths as $protected) {
			if ($normalized_path === $protected || strpos($normalized_path, $protected . '/') === 0) {
				// If it's a temp directory, allow deletion (for cleanup)
				if ($is_safe_temp) {
					break;
				}
				Operation_Logger::log('backup', 'CRITICAL: Attempted to delete protected path', array(
					'path' => $path,
					'normalized' => $normalized_path,
					'protected_match' => $protected,
				));
				throw new \RuntimeException('CRITICAL: Cannot delete protected path: ' . $path);
			}
		}
		
		// Additional safety: ensure we're only deleting temp directories
		if (!$is_safe_temp && is_dir($path)) {
			Operation_Logger::log('backup', 'CRITICAL: Attempted to delete non-temp directory', array(
				'path' => $path,
				'basename' => $basename,
			));
			throw new \RuntimeException('CRITICAL: Can only delete temporary directories: ' . $path);
		}
		
		if (! is_dir($path)) {
			if (file_exists($path)) {
				@unlink($path);
			}
			return;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($it as $item) {
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}

		@rmdir($path);
	}

	/**
	 * Clean up old orphaned tmp directories older than specified hours.
	 */
	private function cleanup_old_tmp_dirs(string $backup_dir, int $max_age_hours = 1): void {
		if (! is_dir($backup_dir)) {
			return;
		}
		$cutoff = time() - ($max_age_hours * HOUR_IN_SECONDS);
		$it = new \DirectoryIterator($backup_dir);
		foreach ($it as $item) {
			if ($item->isDot() || ! $item->isDir()) {
				continue;
			}
			// Only clean up tmp- directories
			if (str_starts_with($item->getFilename(), 'tmp-')) {
				// Check if directory is older than cutoff
				if ($item->getMTime() < $cutoff) {
					$this->delete_recursive($item->getPathname());
				}
			}
		}
	}

	private function push_log(array $entry): void {
		$logs = (array) get_option(self::OPTION_LOGS, array());
		$logs[] = $entry;
		// Keep only last 100 entries
		if (count($logs) > 100) {
			$logs = array_slice($logs, -100);
		}
		update_option(self::OPTION_LOGS, $logs, false);
	}
}
