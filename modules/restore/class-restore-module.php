<?php
/**
 * Enterprise Backup Restore Module
 * Complete site restoration with full file and database replacement
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
	private const OPTION_LOGS = 'wudt_restore_logs';
	private const TRANSIENT_PROGRESS = 'wudt_restore_progress';
	private const TRANSIENT_LOCK = 'wudt_restore_lock';
	private const RESTORE_TIMEOUT = 1800; // 30 minutes

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_restore_preview', array($this, 'ajax_preview'));
		add_action('wp_ajax_wudt_restore_run', array($this, 'ajax_restore'));
		add_action('wp_ajax_wudt_restore_progress', array($this, 'ajax_get_progress'));
		add_action('wp_ajax_wudt_restore_check', array($this, 'ajax_pre_restore_checks'));
		add_action('wp_ajax_wudt_restore_cancel', array($this, 'ajax_cancel_restore'));
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

	/**
	 * AJAX: Pre-restore system checks
	 */
	public function ajax_pre_restore_checks(): void {
		Security_Guard::assert_ajax_admin();
		
		$checks = $this->run_pre_restore_checks();
		wp_send_json_success($checks);
	}

	/**
	 * Run comprehensive pre-restore checks
	 */
	private function run_pre_restore_checks(): array {
		$checks = array();
		
		// PHP configuration
		$checks['php_version'] = array(
			'pass' => version_compare(PHP_VERSION, '7.4', '>='),
			'value' => PHP_VERSION,
			'message' => version_compare(PHP_VERSION, '7.4', '>=') ? 'OK' : 'PHP 7.4+ recommended',
		);
		
		// Memory limit
		$memory_limit = ini_get('memory_limit');
		$memory_bytes = $this->return_bytes($memory_limit);
		$checks['memory'] = array(
			'pass' => $memory_bytes >= 256 * 1024 * 1024,
			'value' => $memory_limit,
			'message' => $memory_bytes >= 256 * 1024 * 1024 ? 'OK' : '256MB+ recommended',
		);
		
		// Max execution time
		$max_time = ini_get('max_execution_time');
		$checks['max_execution_time'] = array(
			'pass' => $max_time >= 300 || $max_time === 0,
			'value' => $max_time . 's',
			'message' => ($max_time >= 300 || $max_time === 0) ? 'OK' : '300s+ recommended',
		);
		
		// Disk space
		$upload_dir = wp_upload_dir();
		$free_space_raw = @disk_free_space($upload_dir['basedir']);
		$free_space = is_numeric($free_space_raw) ? (float) $free_space_raw : 0;
		$checks['disk_space'] = array(
			'pass' => $free_space > 100 * 1024 * 1024,
			'value' => $this->format_bytes($free_space),
			'message' => $free_space > 100 * 1024 * 1024 ? 'OK' : 'Low disk space',
		);
		
		// File permissions
		$wp_content_writable = is_writable(WP_CONTENT_DIR);
		$abspath_writable = is_writable(ABSPATH);
		$checks['permissions'] = array(
			'pass' => $wp_content_writable && $abspath_writable,
			'value' => 'WP_CONTENT: ' . ($wp_content_writable ? 'Writable' : 'Not Writable') . ', ABSPATH: ' . ($abspath_writable ? 'Writable' : 'Not Writable'),
			'message' => ($wp_content_writable && $abspath_writable) ? 'OK' : 'Some directories not writable',
		);
		
		// ZIP extension
		$checks['zip_extension'] = array(
			'pass' => class_exists('ZipArchive'),
			'value' => class_exists('ZipArchive') ? 'Installed' : 'Missing',
			'message' => class_exists('ZipArchive') ? 'OK' : 'ZIP extension required',
		);
		
		// Database connection
		global $wpdb;
		$checks['database'] = array(
			'pass' => $wpdb->check_connection(),
			'value' => 'Connected',
			'message' => $wpdb->check_connection() ? 'OK' : 'Database connection failed',
		);
		
		// WordPress version
		$checks['wp_version'] = array(
			'pass' => true,
			'value' => get_bloginfo('version'),
			'message' => 'OK',
		);
		
		// Overall status
		$all_passed = true;
		foreach ($checks as $check) {
			if (!$check['pass']) {
				$all_passed = false;
				break;
			}
		}
		
		return array(
			'can_restore' => $all_passed,
			'checks' => $checks,
			'warnings' => $this->get_restore_warnings(),
		);
	}

	/**
	 * Get restore warnings
	 */
	private function get_restore_warnings(): array {
		$warnings = array();
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$warnings[] = 'WP_DEBUG is enabled - errors may be visible to visitors during restore';
		}
		
		if ($this->is_multisite()) {
			$warnings[] = 'Multisite detected - ensure backup is from the correct site';
		}
		
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP'])) {
			$warnings[] = 'Proxy/CDN detected - DNS/cache may need clearing after restore';
		}
		
		return $warnings;
	}

	/**
	 * Check if multisite
	 */
	private function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * Convert memory string to bytes
	 */
	private function return_bytes(string $val): float {
		$val = trim($val);
		if ('' === $val || '-1' === $val) {
			return PHP_FLOAT_MAX;
		}
		$last = strtolower($val[strlen($val) - 1]);
		$num = (float) $val;
		switch($last) {
			case 'g': $num *= 1024;
			case 'm': $num *= 1024;
			case 'k': $num *= 1024;
		}
		return $num;
	}

	/**
	 * Format bytes to human readable
	 */
	private function format_bytes(float $bytes, int $precision = 2): string {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		return round($bytes, $precision) . ' ' . $units[$pow];
	}

	/**
	 * AJAX: Cancel ongoing restore
	 */
	public function ajax_cancel_restore(): void {
		Security_Guard::assert_ajax_admin();
		delete_transient(self::TRANSIENT_LOCK);
		$this->set_progress('cancelled', 0, __('Restore cancelled by user', 'wp-ultimate-diagnostics-toolkit'));
		wp_send_json_success(array('message' => __('Restore cancelled', 'wp-ultimate-diagnostics-toolkit')));
	}

	/**
	 * Check if restore is locked
	 */
	private function is_restore_locked(): bool {
		$lock = get_transient(self::TRANSIENT_LOCK);
		if (!$lock) return false;
		
		// Check if lock is stale (older than timeout)
		if (isset($lock['timestamp']) && (time() - $lock['timestamp']) > self::RESTORE_TIMEOUT) {
			delete_transient(self::TRANSIENT_LOCK);
			return false;
		}
		
		return true;
	}

	/**
	 * Lock restore operation
	 */
	private function lock_restore(): void {
		set_transient(self::TRANSIENT_LOCK, array(
			'timestamp' => time(),
			'user' => get_current_user_id(),
		), self::RESTORE_TIMEOUT);
	}

	/**
	 * Unlock restore operation
	 */
	private function unlock_restore(): void {
		delete_transient(self::TRANSIENT_LOCK);
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
			$info['pre_checks'] = $this->run_pre_restore_checks();
			wp_send_json_success($info);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}

	public function ajax_restore(): void {
		Security_Guard::assert_ajax_admin();
		
		// Check if another restore is in progress
		if ($this->is_restore_locked()) {
			wp_send_json_error(array('message' => __('Another restore operation is in progress. Please wait or cancel it.', 'wp-ultimate-diagnostics-toolkit')), 423);
			return;
		}
		
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
		
		// Run pre-restore checks
		$pre_checks = $this->run_pre_restore_checks();
		if (!$pre_checks['can_restore']) {
			wp_send_json_error(array(
				'message' => __('Pre-restore checks failed. Please review the requirements.', 'wp-ultimate-diagnostics-toolkit'),
				'checks' => $pre_checks
			), 400);
			return;
		}
		
		// Lock the restore operation
		$this->lock_restore();
		
		// Clear any stale progress and set initial status
		delete_transient(self::TRANSIENT_PROGRESS);
		$this->set_progress('preparing', 5, __('Starting restore...', 'wp-ultimate-diagnostics-toolkit'));
		
		try {
			$restored = $this->restore_package($archive, $selected, $safe_mode, $media_base);
			$this->unlock_restore();
			wp_send_json_success($restored);
		} catch (\RuntimeException $e) {
			$this->unlock_restore();
			$this->set_progress('error', 0, $e->getMessage());
			Operation_Logger::log('restore', 'Restore failed', array(
				'error' => $e->getMessage(),
				'archive' => $archive,
				'options' => $selected
			));
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		} catch (\Throwable $e) {
			$this->unlock_restore();
			$this->set_progress('error', 0, __('Unexpected error: ', 'wp-ultimate-diagnostics-toolkit') . $e->getMessage());
			Operation_Logger::log('restore', 'Restore failed with exception', array(
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			));
			wp_send_json_error(array('message' => __('Unexpected error during restore: ', 'wp-ultimate-diagnostics-toolkit') . $e->getMessage()), 500);
		}
	}

	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();
		$progress = get_transient(self::TRANSIENT_PROGRESS);
		if (! is_array($progress)) {
			$progress = array('status' => 'idle', 'percent' => 0, 'message' => '');
		}
		wp_send_json_success($progress);
	}

	/**
	 * @param array<int,string> $restore_options
	 * @return array<string,mixed>
	 */
	private function restore_package(string $archive, array $restore_options, bool $safe_mode, string $media_base): array {
		// Increase memory and execution time for large sites
		$original_memory_limit = ini_get('memory_limit');
		$original_max_execution_time = ini_get('max_execution_time');
		
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('admin');
		} else {
			@ini_set('memory_limit', '1024M');
		}
		
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit(self::RESTORE_TIMEOUT);
		}
		
		// Send heartbeat to prevent session timeout
		$this->set_progress('preparing', 5, __('Creating temporary working directory...', 'wp-ultimate-diagnostics-toolkit'));
		
		$temp = WP_CONTENT_DIR . '/uploads/wudt-restore-' . wp_generate_password(10, false, false) . '/';
		if (!wp_mkdir_p($temp)) {
			throw new \RuntimeException(__('Failed to create temporary directory for restore', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Create safety backup if requested
		$safety_backup = null;
		if ($safe_mode) {
			$this->set_progress('safety_backup', 8, __('Creating safety backup first...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$backup = new Backup_Module();
				$safety_backup = $backup->create_backup_package(array('database', 'plugins', 'themes'), false, '');
				Operation_Logger::log('restore', 'Safety backup created', $safety_backup);
			} catch (\Throwable $e) {
				// Safety backup failed but continue anyway
				Operation_Logger::log('restore', 'Safety backup failed', array('error' => $e->getMessage()));
			}
		}

		// Handle GZIP compressed archives (.zip.gz)
		$zip_file = $archive;
		if (str_ends_with(strtolower($archive), '.zip.gz')) {
			$this->set_progress('extracting', 12, __('Decompressing GZIP archive...', 'wp-ultimate-diagnostics-toolkit'));
			$zip_file = $temp . 'archive.zip';
			$this->decompress_gzip($archive, $zip_file);
		}

		$this->set_progress('extracting', 15, __('Extracting backup files...', 'wp-ultimate-diagnostics-toolkit'));
		$this->extract_archive($zip_file, $temp);
		
		$config = $this->read_json($temp . 'config.json');
		$done   = array();
		$errors = array();
		$progress = 20;
		
		// Calculate progress steps based on components to restore
		$component_count = count($restore_options);
		$progress_per_component = $component_count > 0 ? floor(70 / $component_count) : 70;

		// RESTORE ORDER: Database LAST to minimize downtime
		// Files can be restored while site is running, database cannot

		// 1. WordPress Core (files only - no active content)
		if (in_array('core', $restore_options, true) && is_dir($temp . 'wp-core')) {
			$this->set_progress('restoring_core', $progress, __('Replacing WordPress core files...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$this->replace_tree($temp . 'wp-core', ABSPATH, array('wp-content', 'wp-config.php'));
				$done[] = 'core';
				Operation_Logger::log('restore', 'Core files restored');
			} catch (\Throwable $e) {
				$errors[] = 'core: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Core restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		}

		// 2. Plugins (files) - skip if preserve_plugins is set
		$preserve_plugins = isset($_POST['preserve_plugins']) && '1' === (string) wp_unslash($_POST['preserve_plugins']);
		if (in_array('plugins', $restore_options, true) && is_dir($temp . 'plugins') && !$preserve_plugins) {
			$this->set_progress('restoring_plugins', $progress, __('Replacing plugins...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$this->replace_tree($temp . 'plugins', WP_CONTENT_DIR . '/plugins');
				$done[] = 'plugins';
				Operation_Logger::log('restore', 'Plugins restored');
			} catch (\Throwable $e) {
				$errors[] = 'plugins: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Plugins restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		} elseif (in_array('plugins', $restore_options, true) && $preserve_plugins) {
			// Skip plugins restore but log it
			Operation_Logger::log('restore', 'Plugins restore skipped (preserve mode enabled)');
			$done[] = 'plugins';
			$progress += $progress_per_component;
		}

		// 3. Themes (files)
		if (in_array('themes', $restore_options, true) && is_dir($temp . 'themes')) {
			$this->set_progress('restoring_themes', $progress, __('Replacing themes...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$this->replace_tree($temp . 'themes', WP_CONTENT_DIR . '/themes');
				$done[] = 'themes';
				Operation_Logger::log('restore', 'Themes restored');
			} catch (\Throwable $e) {
				$errors[] = 'themes: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Themes restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		}

		// 4. Uploads/Media (files)
		if (in_array('uploads', $restore_options, true) && is_dir($temp . 'uploads')) {
			$this->set_progress('restoring_uploads', $progress, __('Replacing uploads...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$this->replace_tree($temp . 'uploads', WP_CONTENT_DIR . '/uploads', array(), true); // true = merge mode for uploads
				$done[] = 'uploads';
				Operation_Logger::log('restore', 'Uploads restored');
			} catch (\Throwable $e) {
				$errors[] = 'uploads: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Uploads restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		} else {
			// If not restoring uploads, preserve URLs
			$handler = new Media_URL_Handler();
			$handler->preserve_urls((string) ($config['site_url'] ?? ''), $media_base);
		}

		// 5. Database (CRITICAL - Do this LAST to minimize downtime)
		if (in_array('database', $restore_options, true) && is_file($temp . 'database.sql')) {
			$this->set_progress('restoring_database', 90, __('Restoring database (this may take a while)...', 'wp-ultimate-diagnostics-toolkit'));
			try {
				$this->restore_database_complete($temp . 'database.sql');
				$done[] = 'database';
				Operation_Logger::log('restore', 'Database restored');
			} catch (\Throwable $e) {
				$errors[] = 'database: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Database restore failed', array('error' => $e->getMessage()));
				// Database error is critical - may need rollback
				if (!empty($errors) && in_array('database', $done)) {
					// Database partially restored - site may be broken
					Operation_Logger::log('restore', 'CRITICAL: Database restore partially failed', array('errors' => $errors));
				}
			}
		}

		$this->set_progress('finalizing', 95, __('Cleaning up and finalizing...', 'wp-ultimate-diagnostics-toolkit'));
		
		// Clean up temp directory
		$this->delete_recursive($temp);
		
		// Clear WordPress object cache
		wp_cache_flush();
		
		// Restore original limits
		@ini_set('memory_limit', $original_memory_limit);
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit((int) $original_max_execution_time);
		}
		
		$result = array(
			'restored' => $done, 
			'safe_mode' => $safe_mode,
			'safety_backup' => $safety_backup,
			'errors' => $errors,
			'warnings' => !empty($errors) ? array('Some components could not be restored. Check the logs.') : array()
		);
		
		// Store final result in progress for frontend
		$progress_data = array(
			'status'    => empty($errors) ? 'complete' : 'complete_with_errors',
			'percent'   => 100,
			'message'   => empty($errors) ? __('Restore complete!', 'wp-ultimate-diagnostics-toolkit') : __('Restore complete with some errors. Check logs.', 'wp-ultimate-diagnostics-toolkit'),
			'timestamp' => time(),
			'result'    => $result,
		);
		set_transient(self::TRANSIENT_PROGRESS, $progress_data, 5 * MINUTE_IN_SECONDS);
		
		Operation_Logger::log('restore', 'Restore completed', array(
			'archive' => $archive, 
			'options' => $restore_options, 
			'safe_mode' => $safe_mode, 
			'restored' => $done,
			'errors' => $errors
		));
		
		return $result;
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
		
		// For large SQL files, use chunked reading instead of loading entire file
		$handle = fopen($file, 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Could not read SQL file: ' . $file);
		}
		
		$buffer = '';
		$chunk_size = 8192; // Read 8KB at a time
		$statement_count = 0;
		
		while (! feof($handle)) {
			$data = fread($handle, $chunk_size);
			if (false === $data) {
				break;
			}
			
			$buffer .= $data;
			
			// Process complete statements (ending with ;)
			while (($pos = strpos($buffer, ';')) !== false) {
				$statement = substr($buffer, 0, $pos);
				$buffer = substr($buffer, $pos + 1);
				
				$query = trim($statement);
				if ('' === $query || str_starts_with($query, '--')) {
					continue;
				}
				if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
					continue;
				}
				
				$wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$statement_count++;
				
				// Prevent memory buildup by clearing WPDB queries periodically
				if ($statement_count % 100 === 0) {
					$wpdb->queries = array();
				}
			}
		}
		
		// Process any remaining statement without trailing semicolon
		$query = trim($buffer);
		if ('' !== $query && ! str_starts_with($query, '--')) {
			if (! preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
				$wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		
		fclose($handle);
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

	/**
	 * Completely replace target tree with source tree
	 * This DELETES the old files and replaces them with backup files
	 * 
	 * @param array<int,string> $exclude_roots Directories to exclude from deletion
	 * @param bool $merge_mode If true, merge files instead of full replacement (used for uploads)
	 */
	private function replace_tree(string $source, string $target, array $exclude_roots = array(), bool $merge_mode = false): void {
		if (! is_dir($source)) {
			throw new \RuntimeException(__('Source directory does not exist: ', 'wp-ultimate-diagnostics-toolkit') . $source);
		}

		// Ensure target directory exists
		if (! is_dir($target)) {
			if (! wp_mkdir_p($target)) {
				throw new \RuntimeException(__('Cannot create target directory: ', 'wp-ultimate-diagnostics-toolkit') . $target);
			}
		}

		// Step 1: In non-merge mode, delete existing files in target that don't exist in source
		// This ensures a COMPLETE replacement
		if (!$merge_mode && is_dir($target)) {
			$this->cleanup_target_for_replacement($source, $target, $exclude_roots);
		}

		// Step 2: Copy all files from source to target
		$source_normalized = wp_normalize_path($source);
		$target_normalized = wp_normalize_path($target);
		
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		
		$copied = 0;
		foreach ($it as $item) {
			$src = wp_normalize_path((string) $item->getPathname());
			$rel = ltrim(str_replace($source_normalized, '', $src), '/');
			
			// Check excluded roots
			$top = explode('/', $rel)[0] ?? '';
			if (in_array($top, $exclude_roots, true)) {
				continue;
			}
			
			$dest = wp_normalize_path($target_normalized . '/' . $rel);
			$dest = Security_Guard::normalize_inside_wp($dest);
			
			if ($item->isDir()) {
				if (! wp_mkdir_p($dest)) {
					throw new \RuntimeException(__('Cannot create directory: ', 'wp-ultimate-diagnostics-toolkit') . $dest);
				}
			} else {
				// Remove existing file if exists (to ensure clean replacement)
				if (file_exists($dest)) {
					if (! is_writable($dest)) {
						// Try to make writable
						@chmod($dest, 0644);
					}
					@unlink($dest);
				}
				
				// Ensure parent directory exists
				$dest_dir = dirname($dest);
				if (! is_dir($dest_dir)) {
					wp_mkdir_p($dest_dir);
				}
				
				// Copy with error handling
				if (! @copy($src, $dest)) {
					// Try alternative copy method
					$content = @file_get_contents($src);
					if ($content === false) {
						throw new \RuntimeException(__('Cannot read source file: ', 'wp-ultimate-diagnostics-toolkit') . $src);
					}
					if (@file_put_contents($dest, $content) === false) {
						throw new \RuntimeException(__('Cannot write to target file: ', 'wp-ultimate-diagnostics-toolkit') . $dest);
					}
				}
				
				// Set proper permissions
				@chmod($dest, 0644);
				$copied++;
			}
		}
		
		Operation_Logger::log('restore', 'Tree replacement complete', array(
			'source' => $source,
			'target' => $target,
			'files_copied' => $copied,
			'merge_mode' => $merge_mode
		));
	}

	/**
	 * Clean up target directory before replacement
	 * Removes files that don't exist in source
	 */
	private function cleanup_target_for_replacement(string $source, string $target, array $exclude_roots): void {
		if (! is_dir($target)) {
			return;
		}
		
		$source_normalized = wp_normalize_path($source);
		$target_normalized = wp_normalize_path($target);
		
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		
		$removed = 0;
		foreach ($it as $item) {
			$target_path = wp_normalize_path((string) $item->getPathname());
			$rel = ltrim(str_replace($target_normalized, '', $target_path), '/');
			
			// Check excluded roots
			$top = explode('/', $rel)[0] ?? '';
			if (in_array($top, $exclude_roots, true)) {
				continue;
			}
			
			$source_path = $source_normalized . '/' . $rel;
			
			// If doesn't exist in source, remove from target
			if (! file_exists($source_path)) {
				if ($item->isDir()) {
					// Only remove if empty
					@rmdir($target_path);
				} else {
					if (is_writable($target_path) || @chmod($target_path, 0644)) {
						@unlink($target_path);
						$removed++;
					}
				}
			}
		}
		
		Operation_Logger::log('restore', 'Target cleanup complete', array(
			'target' => $target,
			'files_removed' => $removed
		));
	}

	/**
	 * Complete database restore - drops all existing tables and imports fresh
	 */
	private function restore_database_complete(string $sql_file): void {
		global $wpdb;
		
		// Verify SQL file exists
		if (! is_file($sql_file)) {
			throw new \RuntimeException(__('SQL file not found: ', 'wp-ultimate-diagnostics-toolkit') . $sql_file);
		}
		
		// Get list of tables that will be created from the SQL file
		// Read only first 500KB to find table names (avoids memory issues with large SQL files)
		$handle = fopen($sql_file, 'rb');
		if (false === $handle) {
			throw new \RuntimeException(__('Cannot read SQL file: ', 'wp-ultimate-diagnostics-toolkit') . $sql_file);
		}
		$sql_content = fread($handle, 512 * 1024);
		fclose($handle);
		
		if (false === $sql_content) {
			throw new \RuntimeException(__('Cannot read SQL file: ', 'wp-ultimate-diagnostics-toolkit') . $sql_file);
		}
		
		// Extract table names from CREATE TABLE statements
		preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\']?(\w+)[`\']?/i', $sql_content, $matches);
		$tables_in_sql = $matches[1] ?? array();
		
		// Disable foreign key checks for the operation
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		
		// Get current tables
		$existing_tables = $wpdb->get_col('SHOW TABLES');
		
		// Drop tables that will be replaced (intersection of existing and SQL tables)
		$tables_to_drop = array_intersect($existing_tables, $tables_in_sql);
		
		$this->set_progress('restoring_database', 91, __('Dropping existing tables...', 'wp-ultimate-diagnostics-toolkit'));
		
		foreach ($tables_to_drop as $table) {
			$table_name = sanitize_text_field($table);
			if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
				continue;
			}
			$wpdb->query("DROP TABLE IF EXISTS `{$table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		
		// Import the SQL file
		$this->set_progress('restoring_database', 93, __('Importing database tables...', 'wp-ultimate-diagnostics-toolkit'));
		$this->import_sql_file_chunked($sql_file);
		
		// Re-enable foreign key checks
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
		
		Operation_Logger::log('restore', 'Database restore complete', array(
			'tables_dropped' => count($tables_to_drop),
			'tables_created' => count($tables_in_sql)
		));
	}

	/**
	 * Import SQL file with chunking and better error handling
	 */
	private function import_sql_file_chunked(string $file): void {
		global $wpdb;
		
		$handle = fopen($file, 'rb');
		if (false === $handle) {
			throw new \RuntimeException(__('Could not read SQL file: ', 'wp-ultimate-diagnostics-toolkit') . $file);
		}
		
		$buffer = '';
		$chunk_size = 16384; // Read 16KB at a time
		$statement_count = 0;
		$error_count = 0;
		$max_errors = 10;
		
		// Process file in chunks
		while (! feof($handle)) {
			$data = fread($handle, $chunk_size);
			if (false === $data) {
				break;
			}
			
			$buffer .= $data;
			
			// Process complete statements (ending with ;)
			while (($pos = strpos($buffer, ';')) !== false) {
				$statement = substr($buffer, 0, $pos);
				$buffer = substr($buffer, $pos + 1);
				
				$query = trim($statement);
				if ('' === $query || str_starts_with($query, '--') || str_starts_with($query, '/*')) {
					continue;
				}
				
				// Skip dangerous commands
				if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
					continue;
				}
				
				// Execute query
				$result = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				
				if ($result === false) {
					$error_count++;
					if ($error_count >= $max_errors) {
						fclose($handle);
						throw new \RuntimeException(__('Too many SQL errors. Last error: ', 'wp-ultimate-diagnostics-toolkit') . $wpdb->last_error);
					}
				}
				
				$statement_count++;
				
				// Prevent memory buildup
				if ($statement_count % 100 === 0) {
					$wpdb->queries = array();
				}
			}
		}
		
		// Process any remaining statement
		$query = trim($buffer);
		if ('' !== $query && ! str_starts_with($query, '--') && ! str_starts_with($query, '/*')) {
			if (! preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
				$wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		
		fclose($handle);
		
		Operation_Logger::log('restore', 'SQL import complete', array(
			'statements' => $statement_count,
			'errors' => $error_count
		));
	}
}
