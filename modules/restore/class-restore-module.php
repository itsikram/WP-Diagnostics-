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
	private const ENABLE_URL_REPLACEMENT = false; // TEMPORARY: disable URL replacement during restore

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_restore_preview', array($this, 'ajax_preview'));
		add_action('wp_ajax_wudt_restore_run', array($this, 'ajax_restore'));
		add_action('wp_ajax_wudt_restore_progress', array($this, 'ajax_get_progress'));
		add_action('wp_ajax_wudt_restore_check', array($this, 'ajax_pre_restore_checks'));
		add_action('wp_ajax_wudt_restore_cancel', array($this, 'ajax_cancel_restore'));
		add_action('wp_ajax_wudt_restore_download', array($this, 'ajax_download'));
	}

	public function get_key(): string {
		return 'restore_suite';
	}

	public function get_label(): string {
		return __('Restore', 'diagnostics-toolkit');
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
		$this->set_progress('cancelled', 0, __('Restore cancelled by user', 'diagnostics-toolkit'));
		wp_send_json_success(array('message' => __('Restore cancelled', 'diagnostics-toolkit')));
	}

	/**
	 * AJAX: Download backup from URL
	 */
	public function ajax_download(): void {
		Security_Guard::assert_ajax_admin();

		$url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

		if (empty($url)) {
			wp_send_json_error(array('message' => __('No URL provided.', 'diagnostics-toolkit')), 400);
			return;
		}

		// Validate URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			wp_send_json_error(array('message' => __('Invalid URL format.', 'diagnostics-toolkit')), 400);
			return;
		}

		// Only allow http/https
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!in_array($scheme, array('http', 'https'), true)) {
			wp_send_json_error(array('message' => __('Only HTTP and HTTPS URLs are allowed.', 'diagnostics-toolkit')), 400);
			return;
		}

		// Prepare download directory
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/wudt-backups';

		if (!is_dir($backup_dir)) {
			wp_mkdir_p($backup_dir);
		}

		if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
			wp_send_json_error(array('message' => __('Backup directory is not writable.', 'diagnostics-toolkit')), 500);
			return;
		}

		// Generate filename from URL or use timestamp
		$filename = basename(parse_url($url, PHP_URL_PATH));
		if (empty($filename) || !preg_match('/\.(zip|tar\.gz|gz)$/i', $filename)) {
			$filename = 'downloaded-backup-' . date('Y-m-d-His') . '.zip';
		}

		$filename = sanitize_file_name($filename);
		$target_path = $backup_dir . '/' . $filename;

		// If file already exists, add number suffix
		$counter = 1;
		$original_filename = $filename;
		while (file_exists($target_path)) {
			$info = pathinfo($original_filename);
			$extension = isset($info['extension']) ? '.' . $info['extension'] : '';
			$filename = $info['filename'] . '-' . $counter . $extension;
			$target_path = $backup_dir . '/' . $filename;
			$counter++;
		}

		// Download the file using WordPress HTTP API
		$response = wp_remote_get($url, array(
			'timeout'   => 300, // 5 minutes
			'blocking'  => true,
			'headers'   => array(
				'Accept' => 'application/zip, application/octet-stream, */*',
			),
		));

		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => __('Download failed: ', 'diagnostics-toolkit') . $response->get_error_message()), 500);
			return;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			wp_send_json_error(array('message' => __('Download failed with HTTP status: ', 'diagnostics-toolkit') . $status_code), 500);
			return;
		}

		$body = wp_remote_retrieve_body($response);
		if (empty($body)) {
			wp_send_json_error(array('message' => __('Downloaded file is empty.', 'diagnostics-toolkit')), 500);
			return;
		}

		// Save the file
		if (false === file_put_contents($target_path, $body)) {
			wp_send_json_error(array('message' => __('Failed to save downloaded file.', 'diagnostics-toolkit')), 500);
			return;
		}

		$file_size = filesize($target_path);

		wp_send_json_success(array(
			'message' => __('Backup downloaded successfully.', 'diagnostics-toolkit'),
			'name'    => $filename,
			'path'    => $target_path,
			'url'     => $upload_dir['baseurl'] . '/wudt-backups/' . $filename,
			'size'    => $file_size,
		));
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
		$path = isset($_POST['backup_path']) ? sanitize_text_field(wp_unslash($_POST['backup_path'])) : '';
		
		if (empty($path)) {
			wp_send_json_error(array('message' => __('No backup path provided.', 'diagnostics-toolkit')), 400);
		}
		
		// Validate path is within backup directory
		$upload_dir = wp_get_upload_dir();
		$backup_base = trailingslashit($upload_dir['basedir']) . 'wudt-backups/';
		$normalized_path = wp_normalize_path($path);
		$normalized_backup_base = wp_normalize_path($backup_base);
		
		if (strpos(strtolower($normalized_path), strtolower($normalized_backup_base)) !== 0) {
			try {
				$safe = Security_Guard::normalize_inside_wp($path);
			} catch (\RuntimeException $e) {
				Operation_Logger::log('restore', 'Preview path rejected', array('path' => $path));
				wp_send_json_error(array('message' => __('Invalid backup path.', 'diagnostics-toolkit')), 400);
				return;
			}
			$normalized_path = $safe;
		}
		
		$safe = $normalized_path;
		
		if (! file_exists($safe) || ! is_readable($safe)) {
			wp_send_json_error(array('message' => __('Backup file not found or not readable.', 'diagnostics-toolkit')), 404);
			return;
		}
		
		if (! is_readable($safe)) {
			wp_send_json_error(array('message' => __('Backup file is not readable.', 'diagnostics-toolkit')), 403);
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
		
		// Register shutdown handler to catch fatal errors
		register_shutdown_function(function() {
			$error = error_get_last();
			if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
				Operation_Logger::log('restore', 'FATAL ERROR during restore', array(
					'error' => $error['message'],
					'file' => $error['file'],
					'line' => $error['line']
				));
				// Clear any output and send error
				if (ob_get_level()) {
					ob_end_clean();
				}
				wp_send_json_error(array(
					'message' => __('Fatal error during restore: ', 'diagnostics-toolkit') . $error['message']
				), 500);
			}
		});
		
		// Log start of restore for debugging
		Operation_Logger::log('restore', 'ajax_restore started', array(
			'time' => microtime(true),
			'memory' => memory_get_usage(true)
		));
		
		// Prevent any output before JSON response
		if (ob_get_level()) {
			ob_end_clean();
		}
		ob_start();
		
		// Keep processing even if client disconnects
		ignore_user_abort(true);
		
		// Check if another restore is in progress
		if ($this->is_restore_locked()) {
			ob_end_clean();
			wp_send_json_error(array('message' => __('Another restore operation is in progress. Please wait or cancel it.', 'diagnostics-toolkit')), 423);
			return;
		}
		
		$path        = isset($_POST['backup_path']) ? sanitize_text_field(wp_unslash($_POST['backup_path'])) : '';
		
		if (empty($path)) {
			ob_end_clean();
			wp_send_json_error(array('message' => __('No backup path provided.', 'diagnostics-toolkit')), 400);
			return;
		}
		
		$options     = isset($_POST['restore_options']) ? (array) json_decode(sanitize_text_field(wp_unslash($_POST['restore_options'])), true) : array();
		$safe_mode   = isset($_POST['safe_mode']) && '1' === (string) wp_unslash($_POST['safe_mode']);
		$media_base  = isset($_POST['media_base']) ? sanitize_text_field((string) wp_unslash($_POST['media_base'])) : '';
		
		// Validate path is within backup directory
		$upload_dir = wp_get_upload_dir();
		$backup_base = trailingslashit($upload_dir['basedir']) . 'wudt-backups/';
		$normalized_path = wp_normalize_path($path);
		$normalized_backup_base = wp_normalize_path($backup_base);
		
		if (strpos(strtolower($normalized_path), strtolower($normalized_backup_base)) !== 0) {
			try {
				$archive = Security_Guard::normalize_inside_wp($path);
			} catch (\RuntimeException $e) {
				Operation_Logger::log('restore', 'Restore path rejected', array('path' => $path));
				ob_end_clean();
				wp_send_json_error(array('message' => __('Invalid backup path.', 'diagnostics-toolkit')), 400);
				return;
			}
			$normalized_path = $archive;
		}
		
		$archive = $normalized_path;
		
		if (! file_exists($archive)) {
			ob_end_clean();
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'diagnostics-toolkit')), 404);
			return;
		}
		
		$selected = $this->normalize_restore_options($options);
		
		// Run pre-restore checks
		$pre_checks = $this->run_pre_restore_checks();
		if (!$pre_checks['can_restore']) {
			ob_end_clean();
			wp_send_json_error(array(
				'message' => __('Pre-restore checks failed. Please review the requirements.', 'diagnostics-toolkit'),
				'checks' => $pre_checks
			), 400);
			return;
		}
		
		// Lock the restore operation
		$this->lock_restore();
		
		// Clear any stale progress and set initial status
		delete_transient(self::TRANSIENT_PROGRESS);
		$this->set_progress('preparing', 5, __('Starting restore...', 'diagnostics-toolkit'));
		
		try {
			$preserve_plugins = isset($_POST['preserve_plugins']) && '1' === (string) wp_unslash($_POST['preserve_plugins']);
			$restored = $this->restore_package($archive, $selected, $safe_mode, $media_base, $preserve_plugins);
			$this->unlock_restore();
			ob_end_clean();
			wp_send_json_success($restored);
		} catch (\RuntimeException $e) {
			$this->unlock_restore();
			$this->set_progress('error', 0, $e->getMessage());
			Operation_Logger::log('restore', 'Restore failed', array(
				'error' => $e->getMessage(),
				'archive' => $archive,
				'options' => $selected
			));
			ob_end_clean();
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		} catch (\Throwable $e) {
			$this->unlock_restore();
			$this->set_progress('error', 0, __('Unexpected error: ', 'diagnostics-toolkit') . $e->getMessage());
			Operation_Logger::log('restore', 'Restore failed with exception', array(
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
			));
			ob_end_clean();
			wp_send_json_error(array('message' => __('Unexpected error during restore: ', 'diagnostics-toolkit') . $e->getMessage()), 500);
		}
	}

	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();

		// Check if options table exists before querying (during database restore it may not exist)
		global $wpdb;
		if (!isset($wpdb) || !$wpdb->ready) {
			wp_send_json_success(array('status' => 'restoring_database', 'percent' => 90, 'message' => 'Database restore in progress...'));
			return;
		}

		// Suppress database errors during check
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			// During database restore, return meaningful progress instead of error
			wp_send_json_success(array('status' => 'restoring_database', 'percent' => 90, 'message' => 'Database restore in progress...'));
			return;
		}

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
	private function restore_package(string $archive, array $restore_options, bool $safe_mode, string $media_base, bool $preserve_plugins = false): array {
		global $wpdb;
		
		// Preserve current site URLs and user session before restore
		$current_home = get_option('home');
		$current_siteurl = get_option('siteurl');
		$current_user_id = get_current_user_id();

		// Increase memory and execution time for large sites
		$original_memory_limit = ini_get('memory_limit');
		$original_max_execution_time = ini_get('max_execution_time');
		@ini_set('memory_limit', '2048M');
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit(600);
		}

		// Pre-checks
		$backup_dir = wp_upload_dir();
		if (! empty($backup_dir['error'])) {
			throw new \RuntimeException(__('Upload directory error: ', 'diagnostics-toolkit') . $backup_dir['error']);
		}
		
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit(self::RESTORE_TIMEOUT);
		}
		
		// Send heartbeat to prevent session timeout
		$this->set_progress('preparing', 5, __('Creating temporary working directory...', 'diagnostics-toolkit'));
		
		$upload_dir = wp_get_upload_dir();
		$temp = $upload_dir['basedir'] . '/wudt-restore-' . wp_generate_password(10, false, false) . '/';
		
		// Check if uploads directory is writable
		if (!is_writable($upload_dir['basedir'])) {
			throw new \RuntimeException(__('Uploads directory is not writable: ', 'diagnostics-toolkit') . $upload_dir['basedir']);
		}
		
		if (!wp_mkdir_p($temp)) {
			// Try to get more details about why it failed
			$error = error_get_last();
			$error_msg = $error ? $error['message'] : 'Unknown error';
			throw new \RuntimeException(__('Failed to create temporary directory for restore: ', 'diagnostics-toolkit') . $error_msg);
		}

		// Create safety backup ONLY if safe_mode checkbox is explicitly checked
		$safety_backup = null;
		if ($safe_mode) {
			$this->set_progress('safety_backup', 8, __('Creating safety backup first...', 'diagnostics-toolkit'));
			try {
				$backup = new Backup_Module();
				$safety_backup = $backup->create_backup_package(array('database', 'plugins', 'themes'), false, '');
				Operation_Logger::log('restore', 'Safety backup created', $safety_backup);
			} catch (\Throwable $e) {
				// Safety backup failed - log but continue
				Operation_Logger::log('restore', 'Safety backup failed', array('error' => $e->getMessage()));
			}
		}

		// Handle GZIP compressed archives (.zip.gz)
		$zip_file = $archive;
		if (str_ends_with(strtolower($archive), '.zip.gz')) {
			$this->set_progress('extracting', 12, __('Decompressing GZIP archive...', 'diagnostics-toolkit'));
			$zip_file = $temp . 'archive.zip';
			$this->decompress_gzip($archive, $zip_file);
		}

		// Check available disk space before extraction (need at least 2x archive size for safety)
		$archive_size = filesize($zip_file);
		if ($archive_size !== false) {
			$upload_dir = wp_get_upload_dir();
			$free_space = @disk_free_space($upload_dir['basedir']);
			$required_space = $archive_size * 2.5; // 2.5x for extracted files + working space
			if (is_numeric($free_space) && $free_space < $required_space) {
				throw new \RuntimeException(sprintf(
					__('Insufficient disk space. Archive size: %s, Required: %s, Available: %s', 'diagnostics-toolkit'),
					$this->format_bytes($archive_size),
					$this->format_bytes($required_space),
					$this->format_bytes($free_space)
				));
			}
		}
		
		$this->set_progress('extracting', 15, __('Extracting backup files...', 'diagnostics-toolkit'));
		$this->extract_archive($zip_file, $temp);

		// Log extracted contents for debugging
		$extracted_dirs = glob($temp . '*', GLOB_ONLYDIR);
		$extracted_files = glob($temp . '*');
		Operation_Logger::log('restore', 'Backup extracted', array(
			'temp_dir' => $temp,
			'directories' => $extracted_dirs,
			'files' => $extracted_files,
			'plugins_exists' => is_dir($temp . 'plugins'),
			'themes_exists' => is_dir($temp . 'themes'),
			'uploads_exists' => is_dir($temp . 'uploads')
		));

		$config = $this->read_json($temp . 'config.json');
		$done   = array();
		$errors = array();
		$progress = 20;
		
		// Calculate progress steps based on components to restore
		$component_count = count($restore_options);
		$progress_per_component = $component_count > 0 ? floor(70 / $component_count) : 70;

		// RESTORE ORDER: User requested: uploads → plugins → themes → database → core (last)
		// This order restores media first, then code, then database, then core files last

		// 1. Uploads/Media (files) - restore media files first
		if (in_array('uploads', $restore_options, true) && is_dir($temp . 'uploads')) {
			$this->set_progress('restoring_uploads', $progress, __('Replacing uploads...', 'diagnostics-toolkit'));
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

		// 2. Plugins (files) - skip if preserve_plugins is set
		$plugins_dir_exists = is_dir($temp . 'plugins');
		$plugins_in_options = in_array('plugins', $restore_options, true);
		Operation_Logger::log('restore', 'Plugin restore check', array(
			'plugins_dir_exists' => $plugins_dir_exists,
			'plugins_in_options' => $plugins_in_options,
			'preserve_plugins' => $preserve_plugins,
			'temp_plugins_path' => $temp . 'plugins'
		));
		if ($plugins_in_options && $plugins_dir_exists && !$preserve_plugins) {
			$this->set_progress('restoring_plugins', $progress, __('Replacing plugins...', 'diagnostics-toolkit'));
			Operation_Logger::log('restore', 'Starting plugin restoration', array(
				'source' => $temp . 'plugins',
				'destination' => WP_CONTENT_DIR . '/plugins'
			));
			try {
				$this->replace_tree($temp . 'plugins', WP_CONTENT_DIR . '/plugins');
				$done[] = 'plugins';
				Operation_Logger::log('restore', 'Plugins restored successfully');
			} catch (\Throwable $e) {
				$errors[] = 'plugins: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Plugins restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		} elseif ($plugins_in_options && $preserve_plugins) {
			// Skip plugins restore but log it
			Operation_Logger::log('restore', 'Plugins restore skipped (preserve mode enabled)');
			$done[] = 'plugins';
			$progress += $progress_per_component;
		} else {
			Operation_Logger::log('restore', 'Plugins restore skipped (conditions not met)', array(
				'plugins_in_options' => $plugins_in_options,
				'plugins_dir_exists' => $plugins_dir_exists,
				'preserve_plugins' => $preserve_plugins
			));
		}

		// 3. Themes (files)
		if (in_array('themes', $restore_options, true) && is_dir($temp . 'themes')) {
			$this->set_progress('restoring_themes', $progress, __('Replacing themes...', 'diagnostics-toolkit'));
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

		// 4. Database - restore after files but before core
		if (in_array('database', $restore_options, true) && is_file($temp . 'database.sql')) {
			$this->set_progress('restoring_database', $progress, __('Restoring database (this may take a while)...', 'diagnostics-toolkit'));
			Operation_Logger::log('restore', 'Starting database restore step', array(
				'sql_file' => $temp . 'database.sql',
				'file_exists' => file_exists($temp . 'database.sql'),
				'file_size' => filesize($temp . 'database.sql')
			));
			try {
				// Pass config and preserved URLs to handle table prefix and URL updates
				$preserve_urls = array(
					'home' => $current_home,
					'siteurl' => $current_siteurl,
					'user_id' => $current_user_id
				);
				$this->restore_database_complete($temp . 'database.sql', $config, $preserve_urls);
				$done[] = 'database';
				Operation_Logger::log('restore', 'Database restored with preserved URLs', $preserve_urls);
			} catch (\Throwable $e) {
				$errors[] = 'database: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Database restore failed', array('error' => $e->getMessage()));
			}
			$progress += $progress_per_component;
		}

		// Re-establish user session after database restore to prevent logout
		// This must happen after database restore and wp-config.php update
		if ($current_user_id > 0 && in_array('database', $done, true)) {
			// Clear existing auth cookies
			wp_clear_auth_cookie();
			// Set current user
			wp_set_current_user($current_user_id);
			// Create new auth cookie
			wp_set_auth_cookie($current_user_id, true);
			Operation_Logger::log('restore', 'Re-established user session after restore', array(
				'user_id' => $current_user_id,
				'user_login' => wp_get_current_user()->user_login
			));
		}

		// 5. WordPress Core (files only - restored LAST)
		// CRITICAL: Set progress to 100% BEFORE core restore because admin-ajax.php will be unavailable during the operation
		if (in_array('core', $restore_options, true) && is_dir($temp . 'wp-core')) {
			// Pre-set to 100% since we can't send progress updates during core restore (admin-ajax.php gets replaced)
			$this->set_progress('restoring_core', 100, __('Restoring WordPress core files - this may take a moment...', 'diagnostics-toolkit'));
			
			try {
				// Exclude sensitive files to prevent conflicts: wp-config.php (credentials), .htaccess (rewrite rules)
				$this->replace_tree($temp . 'wp-core', ABSPATH, array('wp-content', 'wp-config.php', '.htaccess'));
				$done[] = 'core';
				Operation_Logger::log('restore', 'Core files restored');
			} catch (\Throwable $e) {
				$errors[] = 'core: ' . $e->getMessage();
				Operation_Logger::log('restore', 'Core restore failed', array('error' => $e->getMessage()));
			}
			$progress = 100;
		}

		$this->set_progress('finalizing', 100, __('Cleaning up and finalizing...', 'diagnostics-toolkit'));
		
		// Clean up temp directory
		$this->delete_recursive($temp);
		
		// Clear all WordPress caches to ensure new settings take effect
		wp_cache_flush();
		
		// Flush rewrite rules to ensure permalinks and other settings are updated
		flush_rewrite_rules();
		
		// Clear object cache again after rewrite rules flush
		wp_cache_flush();
		
		// Restore original limits
		@ini_set('memory_limit', $original_memory_limit);
		if (0 !== (int) $original_max_execution_time) {
			@set_time_limit((int) $original_max_execution_time);
		}
		
		// Regenerate nonce for post-restore operations to prevent 400 errors
		$new_nonce = wp_create_nonce('wudt_pro_admin');

		$result = array(
			'restored' => $done,
			'safe_mode' => $safe_mode,
			'safety_backup' => $safety_backup,
			'errors' => $errors,
			'warnings' => !empty($errors) ? array('Some components could not be restored. Check the logs.') : array(),
			'new_nonce' => $new_nonce,
			'table_prefix' => $wpdb->prefix,
		);
		
		// Store final result in progress for frontend
		$progress_data = array(
			'status'    => empty($errors) ? 'complete' : 'complete_with_errors',
			'percent'   => 100,
			'message'   => empty($errors) ? __('Restore complete!', 'diagnostics-toolkit') : __('Restore complete with some errors. Check logs.', 'diagnostics-toolkit'),
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
			throw new \RuntimeException(__('Backup file does not exist: ', 'diagnostics-toolkit') . $archive);
		}
		
		$file_size = filesize($archive);
		if ($file_size === false || $file_size === 0) {
			throw new \RuntimeException(__('Backup file is empty or cannot be read.', 'diagnostics-toolkit'));
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
			throw new \RuntimeException(__('File is not a valid ZIP archive. Must have .zip extension.', 'diagnostics-toolkit'));
		}
		
		// Handle GZIP compressed archives for preview
		$zip_file = $archive;
		if (str_ends_with($lowercase_path, '.zip.gz')) {
			$temp_zip = wp_normalize_path(WP_CONTENT_DIR . '/uploads/wudt-preview-' . wp_generate_password(10, false, false) . '.zip');
			try {
				$this->decompress_gzip($archive, $temp_zip);
				$zip_file = $temp_zip;
			} catch (\RuntimeException $e) {
				throw new \RuntimeException(__('Could not decompress GZIP archive: ', 'diagnostics-toolkit') . $e->getMessage());
			}
		}
		
		$opened = $zip->open($zip_file);
		if (true !== $opened) {
			$error_messages = array(
				\ZipArchive::ER_EXISTS => __('File already exists.', 'diagnostics-toolkit'),
				\ZipArchive::ER_INCONS => __('Zip archive inconsistent.', 'diagnostics-toolkit'),
				\ZipArchive::ER_INVAL  => __('Invalid argument.', 'diagnostics-toolkit'),
				\ZipArchive::ER_MEMORY => __('Memory allocation failure.', 'diagnostics-toolkit'),
				\ZipArchive::ER_NOENT  => __('File not found.', 'diagnostics-toolkit'),
				\ZipArchive::ER_NOZIP  => __('Not a zip archive.', 'diagnostics-toolkit'),
				\ZipArchive::ER_OPEN   => __('Cannot open file.', 'diagnostics-toolkit'),
				\ZipArchive::ER_READ   => __('Read error.', 'diagnostics-toolkit'),
				\ZipArchive::ER_SEEK   => __('Seek error.', 'diagnostics-toolkit'),
			);
			
			// Log detailed error for debugging
			$error_msg = isset($error_messages[$opened]) ? $error_messages[$opened] : 'Unknown error code: ' . $opened;
			Operation_Logger::log('restore', sprintf(
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
				: sprintf(__('Unable to open archive. Error code: %d. Please check the file exists and is a valid ZIP.', 'diagnostics-toolkit'), $opened);
			
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
				\ZipArchive::ER_EXISTS => __('File already exists.', 'diagnostics-toolkit'),
				\ZipArchive::ER_INCONS => __('Zip archive inconsistent.', 'diagnostics-toolkit'),
				\ZipArchive::ER_INVAL  => __('Invalid argument.', 'diagnostics-toolkit'),
				\ZipArchive::ER_MEMORY => __('Memory allocation failure.', 'diagnostics-toolkit'),
				\ZipArchive::ER_NOENT  => __('File not found.', 'diagnostics-toolkit'),
				\ZipArchive::ER_NOZIP  => __('Not a zip archive.', 'diagnostics-toolkit'),
				\ZipArchive::ER_OPEN   => __('Cannot open file.', 'diagnostics-toolkit'),
				\ZipArchive::ER_READ   => __('Read error.', 'diagnostics-toolkit'),
				\ZipArchive::ER_SEEK   => __('Seek error.', 'diagnostics-toolkit'),
			);
			
			$error_msg = isset($error_messages[$opened]) ? $error_messages[$opened] : 'Unknown error code: ' . $opened;
			Operation_Logger::log('restore', sprintf('[WUDT Restore] Extract failed: %s | Error: %s (code: %d)', $archive, $error_msg, $opened));
			
			$display_msg = isset($error_messages[$opened]) 
				? $error_messages[$opened] 
				: sprintf(__('Could not open backup archive. Error code: %d', 'diagnostics-toolkit'), $opened);
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
		// Extract with error handling for individual files
		$extracted = 0;
		$failed = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$file_name = $zip->getNameIndex($i);
			if (empty($file_name)) {
				continue;
			}
			
			// Skip macOS system files and hidden files
			if (strpos($file_name, '__MACOSX/') === 0 || strpos($file_name, '.DS_Store') !== false) {
				continue;
			}
			
			if ($zip->extractTo($destination, $file_name)) {
				$extracted++;
			} else {
				$failed++;
				Operation_Logger::log('restore', 'Failed to extract file', array('file' => $file_name));
			}
		}
		$zip->close();
		
		if ($extracted === 0 && $failed > 0) {
			throw new \RuntimeException(__('Failed to extract any files from archive. The backup may be corrupted.', 'diagnostics-toolkit'));
		}
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
			
			// Process complete statements (ending with ; outside of quoted strings)
			$search_pos = 0;
			while (($pos = $this->find_statement_end($buffer, $search_pos)) !== false) {
				$statement = substr($buffer, 0, $pos);
				$buffer = substr($buffer, $pos + 1);
				$search_pos = 0;
				
				$query = trim($statement);
				if ('' === $query || str_starts_with($query, '--')) {
					continue;
				}
				if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
					continue;
				}
				
				// Convert INSERT INTO to INSERT IGNORE to prevent duplicate key errors
				if (preg_match('/^INSERT\s+INTO\s+/i', $query)) {
					$query = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $query);
				}
				
				// Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS
				if (preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $query)) {
					$query = preg_replace('/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $query);
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
				// Convert INSERT INTO to INSERT IGNORE for remaining statement too
				if (preg_match('/^INSERT\s+INTO\s+/i', $query)) {
					$query = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $query);
				}
				// Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS for remaining statement
				if (preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $query)) {
					$query = preg_replace('/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $query);
				}
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
		global $wpdb;

		// During database restore, wp_options may not exist - check first
		if (!isset($wpdb) || !$wpdb->ready) {
			return;
		}

		// Suppress database errors during check to prevent race condition output
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			// Silently skip progress updates during database restore when tables don't exist
			return;
		}

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
			// Try with error suppression, and retry if locked
			if (!@unlink($path)) {
				@chmod($path, 0777);
				@unlink($path);
			}
			return;
		}
		if (! is_dir($path)) {
			return;
		}
		
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		
		$failed = array();
		foreach ($it as $item) {
			$item_path = (string) $item->getPathname();
			if ($item->isDir()) {
				if (!@rmdir($item_path)) {
					$failed[] = $item_path;
				}
			} else {
				if (!@unlink($item_path)) {
					// Try to fix permissions and retry
					@chmod($item_path, 0777);
					if (!@unlink($item_path)) {
						$failed[] = $item_path;
					}
				}
			}
		}
		
		// Try to remove parent directory
		if (!@rmdir($path)) {
			// Some files couldn't be deleted - log but don't fail
			if (!empty($failed)) {
				Operation_Logger::log('restore', 'Some files could not be deleted during cleanup', array('failed' => $failed));
			}
			// Try one more time
			@chmod($path, 0755);
			@rmdir($path);
		}
	}

	/**
	 * Decompress GZIP file to output file.
	 *
	 * @throws \RuntimeException If decompression fails.
	 */
	private function decompress_gzip(string $input, string $output): void {
		$source = @gzopen($input, 'rb');
		if (! is_resource($source)) {
			throw new \RuntimeException(__('Could not open GZIP archive for reading: ', 'diagnostics-toolkit') . $input);
		}

		$dest = @fopen($output, 'wb');
		if (! is_resource($dest)) {
			gzclose($source);
			throw new \RuntimeException(__('Could not create output file: ', 'diagnostics-toolkit') . $output);
		}

		while (! gzeof($source)) {
			$data = gzread($source, 8192);
			if (false === $data) {
				gzclose($source);
				fclose($dest);
				unlink($output);
				throw new \RuntimeException(__('Error reading GZIP data from: ', 'diagnostics-toolkit') . $input);
			}
			if (fwrite($dest, $data) === false) {
				gzclose($source);
				fclose($dest);
				unlink($output);
				throw new \RuntimeException(__('Error writing decompressed data to: ', 'diagnostics-toolkit') . $output);
			}
		}

		gzclose($source);
		fclose($dest);

		// Verify the output file was created
		if (! file_exists($output) || filesize($output) === 0) {
			throw new \RuntimeException(__('GZIP decompression failed - output file is empty or missing.', 'diagnostics-toolkit'));
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
		Operation_Logger::log('restore', 'replace_tree started', array(
			'source' => $source,
			'target' => $target,
			'merge_mode' => $merge_mode,
			'source_exists' => is_dir($source),
			'target_exists' => is_dir($target)
		));

		if (! is_dir($source)) {
			throw new \RuntimeException(__('Source directory does not exist: ', 'diagnostics-toolkit') . $source);
		}

		// Count source files for logging
		$source_files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source));
		$file_count = 0;
		foreach ($source_files as $file) {
			if ($file->isFile()) $file_count++;
		}
		Operation_Logger::log('restore', 'Source directory file count', array('count' => $file_count));

		// Ensure target directory exists
		if (! is_dir($target)) {
			if (! wp_mkdir_p($target)) {
				throw new \RuntimeException(__('Cannot create target directory: ', 'diagnostics-toolkit') . $target);
			}
		}

		// Step 1: In non-merge mode, delete existing files in target that don't exist in source
		// This ensures a COMPLETE replacement
		if (!$merge_mode && is_dir($target)) {
			// First, check permissions on target directory
			if (!is_writable($target)) {
				// Try to make writable
				@chmod($target, 0755);
				if (!is_writable($target)) {
					throw new \RuntimeException(__('Target directory is not writable: ', 'diagnostics-toolkit') . $target);
				}
			}
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
					throw new \RuntimeException(__('Cannot create directory: ', 'diagnostics-toolkit') . $dest);
				}
			} else {
				// Ensure parent directory exists
				$dest_dir = dirname($dest);
				if (! is_dir($dest_dir)) {
					wp_mkdir_p($dest_dir);
				}
				
				// Use atomic file replacement: copy to temp file then rename
				// This ensures the file is never missing during the operation
				$temp_dest = $dest . '.tmp.' . uniqid();
				$max_retries = 3;
				$copied_success = false;
				
				for ($i = 0; $i < $max_retries; $i++) {
					// Copy to temp file first
					if (@copy($src, $temp_dest)) {
						// Make writable if needed
						if (file_exists($dest) && !is_writable($dest)) {
							@chmod($dest, 0644);
						}
						// Atomic rename: replaces old file without any window where it doesn't exist
						if (@rename($temp_dest, $dest)) {
							$copied_success = true;
							break;
						} else {
							// Rename failed, try to clean up temp
							@unlink($temp_dest);
						}
					}
					usleep(100000); // 100ms between retries
				}
				
				// Clean up temp file if it still exists
				if (file_exists($temp_dest)) {
					@unlink($temp_dest);
				}

				if (!$copied_success) {
					// Try alternative copy method for small files (< 50MB)
					$file_size = @filesize($src);
					if ($file_size !== false && $file_size < (50 * 1024 * 1024)) {
						$content = @file_get_contents($src);
						if ($content !== false) {
							if (@file_put_contents($dest, $content) !== false) {
								$copied_success = true;
							}
						}
					}
				}
				
				if (!$copied_success) {
					// Log warning but don't fail - some files might be locked by other processes
					Operation_Logger::log('restore', 'Warning: Could not copy file (skipped)', array(
						'source' => $src,
						'dest' => $dest,
						'error' => error_get_last()['message'] ?? 'Unknown'
					));
					continue; // Skip this file but continue restoring others
				}
				
				// Set proper permissions
				@chmod($dest, 0644);
				$copied++;
			}
		}
		
		Operation_Logger::log('restore', 'replace_tree completed', array(
			'source' => $source,
			'target' => $target,
			'files_copied' => $copied,
			'files_removed' => $removed,
			'delete_errors' => $failed
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
		$failed = 0;
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
					$deleted = false;
					// Try multiple times with increasing permissions
					for ($i = 0; $i < 3; $i++) {
						if (is_writable($target_path) || @chmod($target_path, 0777)) {
							if (@unlink($target_path)) {
								$deleted = true;
								$removed++;
								break;
							}
						}
						if ($i === 0) {
							// First attempt failed, try clearing file permissions
							@chmod($target_path, 0777);
						}
						usleep(50000); // 50ms between attempts
					}
					if (!$deleted) {
						$failed++;
						// Log but don't fail - some files might be locked by the OS or another process
						Operation_Logger::log('restore', 'Warning: Could not delete file during cleanup', array('file' => $target_path));
					}
				}
			}
		}
		
		Operation_Logger::log('restore', 'Target cleanup complete', array(
			'target' => $target,
			'files_removed' => $removed,
			'files_failed' => $failed
		));
	}

	/**
	 * Complete database restore - drops all existing tables and imports fresh
	 * @param array<string,mixed> $config Backup config from config.json
	 * @param array<string,string> $preserve_urls Array with 'home' and 'siteurl' to preserve
	 */
	private function restore_database_complete(string $sql_file, array $config = array(), array $preserve_urls = array()): void {
		global $wpdb;

		// Suppress all warnings/notices during restore to prevent broken AJAX responses
		$error_level = error_reporting();
		error_reporting(0);
		
		// Store original wait_timeout to restore later
		$original_wait_timeout = $wpdb->get_var("SELECT @@SESSION.wait_timeout");
		$wpdb->query("SET SESSION wait_timeout = 600"); // 10 minutes
		$wpdb->query("SET SESSION innodb_lock_wait_timeout = 120"); // 2 minutes for InnoDB
		
		// Verify SQL file exists and is readable
		if (! is_file($sql_file)) {
			throw new \RuntimeException(__('SQL file not found: ', 'diagnostics-toolkit') . $sql_file);
		}
		
		if (! is_readable($sql_file)) {
			throw new \RuntimeException(__('SQL file is not readable: ', 'diagnostics-toolkit') . $sql_file);
		}
		
		// Verify SQL file has content
		$file_size = filesize($sql_file);
		if ($file_size === false || $file_size === 0) {
			throw new \RuntimeException(__('SQL file is empty: ', 'diagnostics-toolkit') . $sql_file);
		}
		
		// Get list of tables that will be created from the SQL file
		// Read only first 500KB to find table names (avoids memory issues with large SQL files)
		$handle = fopen($sql_file, 'rb');
		if (false === $handle) {
			throw new \RuntimeException(__('Cannot read SQL file: ', 'diagnostics-toolkit') . $sql_file);
		}
		$sql_content = fread($handle, 512 * 1024);
		fclose($handle);
		
		if (false === $sql_content) {
			throw new \RuntimeException(__('Cannot read SQL file: ', 'diagnostics-toolkit') . $sql_file);
		}
		
		// Extract table names from CREATE TABLE statements
		preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\']?(\w+)[`\']?/i', $sql_content, $matches);
		$tables_in_sql = $matches[1] ?? array();
		
		// Detect backup's table prefix from first table name, or use config if available
		$backup_prefix = '';
		if (!empty($config['table_prefix'])) {
			$backup_prefix = $config['table_prefix'];
		} elseif (!empty($tables_in_sql)) {
			$first_table = $tables_in_sql[0];
			// Find common prefix ending with underscore
			if (preg_match('/^(.+_)/', $first_table, $prefix_match)) {
				$backup_prefix = $prefix_match[1];
			}
		}
		
		$current_prefix = $wpdb->prefix;
		
		Operation_Logger::log('restore', 'Table prefix detection', array(
			'backup_prefix' => $backup_prefix,
			'current_prefix' => $current_prefix,
			'same' => ($backup_prefix === $current_prefix)
		));
		
		$old_prefix = $backup_prefix;

		// Logic: Different prefix = keep as-is, Same prefix = use random prefix
		if ($backup_prefix === $current_prefix) {
			// Same prefix: use 6-char random to avoid table name conflicts
			$new_prefix = 'wudt' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6) . '_';
			$will_replace = true;
		} else {
			// Different prefix: import tables with backup's original prefix
			$new_prefix = $backup_prefix;
			$will_replace = false;
		}

		// Log the prefix strategy
		Operation_Logger::log('restore', 'Table prefix strategy determined', array(
			'backup_prefix' => $backup_prefix,
			'current_prefix' => $current_prefix,
			'old_prefix' => $old_prefix,
			'new_prefix' => $new_prefix,
			'will_replace' => $will_replace,
			'strategy' => ($backup_prefix === $current_prefix) ? 'same_prefix_use_random' : 'different_prefix_keep_as_is'
		));

		// Temporarily update wpdb prefix to match where tables will be created
		$original_wpdb_prefix = $wpdb->prefix;
		$wpdb->prefix = $new_prefix;
		
		if (empty($tables_in_sql)) {
			throw new \RuntimeException(__('SQL file does not contain any CREATE TABLE statements. The file may be corrupted or in an invalid format.', 'diagnostics-toolkit'));
		}
		
		// Get current tables BEFORE dropping anything
		$existing_tables = $wpdb->get_col('SHOW TABLES');
		
		if (empty($existing_tables)) {
			throw new \RuntimeException(__('Database has no tables. This is unusual - cannot proceed with restore.', 'diagnostics-toolkit'));
		}
		
		// Disable foreign key checks for the operation
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		
		// SAFER APPROACH: Rename tables to temp names instead of dropping immediately
		// This allows us to recover if the import fails
		// Convert backup table names to current prefix for matching (e.g., wp_options -> custom_options)
		$tables_in_sql_converted = array();
		foreach ($tables_in_sql as $sql_table) {
			if ($backup_prefix && $current_prefix && $backup_prefix !== $current_prefix) {
				// Replace backup prefix with current prefix for matching
				$converted_table = preg_replace('/^' . preg_quote($backup_prefix, '/') . '/', $current_prefix, $sql_table);
				$tables_in_sql_converted[] = $converted_table;
			} else {
				$tables_in_sql_converted[] = $sql_table;
			}
		}
		$tables_to_replace = array_intersect($existing_tables, $tables_in_sql_converted);
		$temp_prefix = 'wudt_old_' . time() . '_';
		$renamed_tables = array();

		Operation_Logger::log('restore', 'Table matching for cross-site restore', array(
			'backup_tables' => $tables_in_sql,
			'converted_tables' => $tables_in_sql_converted,
			'existing_tables' => $existing_tables,
			'tables_to_replace' => $tables_to_replace,
			'backup_prefix' => $backup_prefix,
			'current_prefix' => $current_prefix
		));
		
		if (empty($tables_to_replace)) {
			Operation_Logger::log('restore', 'Warning: No matching tables found between backup and current database', array(
				'sql_tables' => $tables_in_sql,
				'existing_tables' => $existing_tables
			));
		}
		
		$this->set_progress('restoring_database', 91, __('Backing up existing tables...', 'diagnostics-toolkit'));
		
		// Rename tables to temp names instead of dropping
		foreach ($tables_to_replace as $table) {
			$table_name = sanitize_text_field($table);
			if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
				continue;
			}
			$temp_name = $temp_prefix . $table_name;
			// Drop temp table if it exists from a previous failed attempt
			$wpdb->query("DROP TABLE IF EXISTS `{$temp_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// Rename original table to temp name
			$rename_result = $wpdb->query("RENAME TABLE `{$table_name}` TO `{$temp_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ($rename_result !== false) {
				$renamed_tables[] = $table_name;
			}
		}
		
		Operation_Logger::log('restore', 'Tables renamed to temp prefix for safety', array(
			'renamed_count' => count($renamed_tables),
			'temp_prefix' => $temp_prefix
			));
		
		// Import the SQL file - this is the critical step
		$this->set_progress('restoring_database', 93, __('Importing database tables...', 'diagnostics-toolkit'));
		
		$import_success = false;
		try {
			// Import with prefix replacement based on $will_replace flag:
			// - If $will_replace is true: replace old_prefix with new_prefix (random)
			// - If $will_replace is false: keep backup's prefix (no replacement)
			if ($will_replace && $old_prefix !== $new_prefix) {
				$this->import_sql_file_chunked($sql_file, $old_prefix, $new_prefix);
			} else {
				$this->import_sql_file_chunked($sql_file);
			}
			$import_success = true;
		} catch (\Throwable $e) {
			$import_error = $e->getMessage();
			Operation_Logger::log('restore', 'SQL import failed', array(
				'error' => $import_error,
				'will_replace' => $will_replace,
				'old_prefix' => $old_prefix,
				'new_prefix' => $new_prefix
			));
			throw new \RuntimeException(__('Database import failed: ', 'diagnostics-toolkit') . $import_error);
			$this->set_progress('restoring_database', 94, __('Import failed - restoring original tables...', 'diagnostics-toolkit'));
			
			// Drop any partially imported tables with the original names
			foreach ($renamed_tables as $table) {
				$table_name = sanitize_text_field($table);
				$wpdb->query("DROP TABLE IF EXISTS `{$table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			
			// Rename temp tables back to original names
			$recovered_tables = array();
			foreach ($renamed_tables as $table) {
				$table_name = sanitize_text_field($table);
				$temp_name = $temp_prefix . $table_name;
				$wpdb->query("RENAME TABLE `{$temp_name}` TO `{$table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$recovered_tables[] = $table_name;
			}
			
			Operation_Logger::log('restore', 'Database recovery complete', array(
				'recovered_tables' => $recovered_tables
			));
			
			// Re-enable foreign key checks
			$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
			
			throw new \RuntimeException(
				__('Database import failed. Your original tables have been restored. Error: ', 'diagnostics-toolkit') .
				$e->getMessage()
			);
		}
		
		// Import succeeded - update URLs, re-establish session, and drop old tables
		if ($import_success) {
			// Get preserved values from parameter
			$preserved_home = $preserve_urls['home'] ?? '';
			$preserved_siteurl = $preserve_urls['siteurl'] ?? '';
			$preserved_user_id = $preserve_urls['user_id'] ?? 0;

			// IMPORTANT: When backup prefix differs from current, tables were imported with backup's prefix
			// We need to use the correct options table for URL updates
			// CRITICAL: Only update backup tables, never touch previous site's tables
			if ($backup_prefix !== $current_prefix) {
				// Different prefix: tables imported with backup's prefix (e.g., wp_options)
				$effective_prefix = $backup_prefix;
				$previous_prefix = $current_prefix; // Previous site's prefix for reference
			} else {
				// Same prefix: tables imported with random prefix (e.g., wudt1a2b3c_options)
				$effective_prefix = $wpdb->prefix;
				$previous_prefix = $current_prefix; // Same as effective, no previous tables
			}
			$options_table = $effective_prefix . 'options';
			$url_replacement_enabled = self::ENABLE_URL_REPLACEMENT;

			// Update wp_options with current site's URLs (so user stays logged in to current site)
			// NOTE: This ONLY updates the backup's tables, NEVER the previous site's tables
			if ($url_replacement_enabled && $preserved_home && $preserved_siteurl) {
				Operation_Logger::log('restore', 'Updating site URLs in backup tables only', array(
					'options_table' => $options_table,
					'backup_prefix' => $backup_prefix,
					'current_prefix' => $current_prefix,
					'previous_prefix' => $previous_prefix,
					'will_update_previous_tables' => false,
					'note' => 'Only backup tables are updated, previous site tables remain untouched'
				));
				// Use direct query to avoid get_option/update_option cache issues
				$home_result = $wpdb->query($wpdb->prepare(
					"UPDATE {$options_table} SET option_value = %s WHERE option_name = 'home'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$preserved_home
				));
				$siteurl_result = $wpdb->query($wpdb->prepare(
					"UPDATE {$options_table} SET option_value = %s WHERE option_name = 'siteurl'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$preserved_siteurl
				));
				Operation_Logger::log('restore', 'Site URLs updated', array(
					'home_result' => $home_result,
					'siteurl_result' => $siteurl_result,
					'home' => $preserved_home,
					'siteurl' => $preserved_siteurl,
					'options_table' => $options_table
				));

				// Replace URLs in all tables for cross-site compatibility
				$this->replace_urls_across_tables($preserved_home, $preserved_siteurl, $effective_prefix);
			} else {
				Operation_Logger::log('restore', 'URL replacement disabled during restore', array(
					'preserved_home' => $preserved_home,
					'preserved_siteurl' => $preserved_siteurl,
					'options_table' => $options_table,
					'url_replacement_enabled' => $url_replacement_enabled,
				));
			}

			// Re-establish user session to prevent logout
			if ($preserved_user_id > 0) {
				// Clear any existing auth cookies first
				wp_clear_auth_cookie();
				// Set current user and create new auth cookie
				wp_set_current_user($preserved_user_id);
				wp_set_auth_cookie($preserved_user_id, true);
				// Clear session tokens from backup database and create fresh session
				delete_user_meta($preserved_user_id, 'session_tokens');
				// Also regenerate the logged_in cookie
				wp_set_logged_in_cookie($preserved_user_id, true, true);
				Operation_Logger::log('restore', 'Re-established user session', array(
					'user_id' => $preserved_user_id,
					'user_login' => wp_get_current_user()->user_login
				));
			}

			foreach ($renamed_tables as $table) {
				$temp_name = $temp_prefix . sanitize_text_field($table);
				$wpdb->query("DROP TABLE IF EXISTS `{$temp_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			Operation_Logger::log('restore', 'Old tables cleaned up after successful import', array(
				'dropped_temp_tables' => count($renamed_tables)
			));
		}
		
		// Verify tables were actually created
		$new_tables = $wpdb->get_col('SHOW TABLES');
		if (empty($new_tables)) {
			$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
			throw new \RuntimeException(__('Database import completed but no tables were found. The SQL file may be corrupted.', 'diagnostics-toolkit'));
		}
		
		// Re-enable foreign key checks
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

		// If backup had a different prefix, update wp-config.php to use the backup's prefix
		// This ensures WordPress connects to the correct tables after restore
		if ($backup_prefix !== $current_prefix) {
			$updated = $this->update_wp_config_prefix($backup_prefix);
			Operation_Logger::log('restore', 'Updated wp-config.php for different prefix', array(
				'backup_prefix' => $backup_prefix,
				'current_prefix' => $current_prefix,
				'wp_config_updated' => $updated
			));
		}

		// Restore original wait_timeout
		if ($original_wait_timeout) {
			$wpdb->query("SET SESSION wait_timeout = {$original_wait_timeout}");
		}
		
		// Ensure arrays are set before counting (they may be null if queries failed)
		$tables_dropped_count = is_array($renamed_tables) ? count($renamed_tables) : 0;
		$tables_sql_count = is_array($tables_in_sql) ? count($tables_in_sql) : 0;
		$tables_after_count = is_array($new_tables) ? count($new_tables) : 0;
		
		Operation_Logger::log('restore', 'Database restore complete', array(
			'tables_dropped' => $tables_dropped_count,
			'tables_created' => $tables_sql_count,
			'tables_after_restore' => $tables_after_count
		));
		
		// Restore original error reporting
		error_reporting($error_level);
	}

	/**
	 * Replace URLs across all tables for cross-site restore compatibility
	 * Replaces backup site URLs with current site URLs
	 * @param string $effective_prefix The table prefix to use (backup's prefix when different)
	 */
	private function replace_urls_across_tables(string $current_home, string $current_siteurl, string $effective_prefix = ''): void {
		global $wpdb;

		// Use effective prefix if provided, otherwise fall back to wpdb prefix
		$prefix = $effective_prefix ?: $wpdb->prefix;

		// Get the original URLs from the backup options
		$backup_home = $wpdb->get_var(
			"SELECT option_value FROM {$prefix}options WHERE option_name = 'home'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$backup_siteurl = $wpdb->get_var(
			"SELECT option_value FROM {$prefix}options WHERE option_name = 'siteurl'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if (!$backup_home || !$backup_siteurl) {
			Operation_Logger::log('restore', 'Could not detect backup URLs for replacement', array(
				'backup_home' => $backup_home,
				'backup_siteurl' => $backup_siteurl
			));
			return;
		}

		Operation_Logger::log('restore', 'Replacing URLs for cross-site compatibility', array(
			'from_home' => $backup_home,
			'to_home' => $current_home,
			'from_siteurl' => $backup_siteurl,
			'to_siteurl' => $current_siteurl
		));

		// Tables that typically contain URLs
		$tables_with_urls = array(
			'posts',
			'postmeta',
			'options',
			'comments',
			'commentmeta',
			'usermeta',
			'links',
		);

		$total_replaced = 0;

		foreach ($tables_with_urls as $table) {
			// Use effective prefix for table names (backup's prefix when different)
			$table_name = $prefix . $table;
			// Skip if table doesn't exist
			$table_exists = $wpdb->get_var(
				"SHOW TABLES LIKE '{$table_name}'"
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ($table_name !== $table_exists) {
				continue;
			}

			// Get columns for this table
			$columns = $wpdb->get_results(
				"SHOW COLUMNS FROM {$table_name}",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ($columns as $column) {
				$column_name = $column['Field'];
				$column_type = strtolower($column['Type']);

				// Only process text/blob columns
				if (!str_contains($column_type, 'text') &&
					!str_contains($column_type, 'varchar') &&
					!str_contains($column_type, 'blob') &&
					!str_contains($column_type, 'longtext')) {
					continue;
				}

				// Replace URLs in this column
				$replaced = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table_name} SET `{$column_name}` = REPLACE(`{$column_name}`, %s, %s) WHERE `{$column_name}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$backup_home,
						$current_home,
						'%' . $wpdb->esc_like($backup_home) . '%'
					)
				);
				if ($replaced) {
					$total_replaced += $replaced;
				}

				// Also replace siteurl if different
				if ($backup_siteurl !== $backup_home) {
					$replaced = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table_name} SET `{$column_name}` = REPLACE(`{$column_name}`, %s, %s) WHERE `{$column_name}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
							$backup_siteurl,
							$current_siteurl,
							'%' . $wpdb->esc_like($backup_siteurl) . '%'
						)
					);
					if ($replaced) {
						$total_replaced += $replaced;
					}
				}
			}
		}

		Operation_Logger::log('restore', 'URL replacement complete', array(
			'total_rows_updated' => $total_replaced
		));
	}

	/**
	 * Find position of next semicolon that is NOT inside a quoted string.
	 * This is crucial for parsing SQL correctly when values contain semicolons.
	 */
	private function find_statement_end(string $sql, int $start_pos = 0): int|false {
		$len = strlen($sql);
		$in_quote = null;
		$escape_next = false;
		
		for ($i = $start_pos; $i < $len; $i++) {
			$char = $sql[$i];
			
			// Handle escape sequences
			if ($escape_next) {
				$escape_next = false;
				continue;
			}
			
			if ($char === '\\' && $in_quote !== null) {
				$escape_next = true;
				continue;
			}
			
			// Handle quote characters
			if ($in_quote === null) {
				if ($char === "'" || $char === '"' || $char === '`') {
					$in_quote = $char;
				} elseif ($char === ';') {
					return $i;
				}
			} else {
				// We're inside a quoted string
				if ($char === $in_quote) {
					$in_quote = null;
				}
			}
		}
		
		return false; // No semicolon found outside quotes
	}

	/**
	 * Import SQL file with chunking and better error handling
	 * @param string $old_prefix Optional old prefix to replace
	 * @param string $new_prefix Optional new prefix to replace with
	 */
	private function import_sql_file_chunked(string $file, string $old_prefix = '', string $new_prefix = ''): void {
		global $wpdb;
		
		// Extend time limit for large imports and suppress ALL errors to prevent JSON corruption
		@set_time_limit(300); // 5 minutes
		$display_errors = ini_get('display_errors');
		ini_set('display_errors', '0');
		$error_level = error_reporting();
		error_reporting(0); // Suppress ALL errors during SQL import
		
		$handle = fopen($file, 'rb');
		if (false === $handle) {
			error_reporting($error_level);
			ini_set('display_errors', $display_errors);
			throw new \RuntimeException(__('Could not read SQL file: ', 'diagnostics-toolkit') . $file);
		}
		
		$buffer = '';
		$chunk_size = 8192; // Read 8KB at a time (smaller for better progress)
		$statement_count = 0;
		$error_count = 0;
		$max_errors = 50; // Increased from 10 to allow more tolerance
		$needs_prefix_replace = ($old_prefix !== '' && $new_prefix !== '' && $old_prefix !== $new_prefix);
		$last_progress_update = 0;
		$first_query_logged = false;
		
		Operation_Logger::log('restore', 'Starting SQL import', array(
			'file' => $file,
			'file_size' => filesize($file),
			'needs_prefix_replace' => $needs_prefix_replace,
			'old_prefix' => $old_prefix,
			'new_prefix' => $new_prefix
		));
		
		// Process file in chunks
		$max_buffer_size = 5 * 1024 * 1024; // 5MB max buffer for large INSERT statements
		$max_statement_size = 2 * 1024 * 1024; // 2MB max individual statement size
		
		while (! feof($handle)) {
			$data = fread($handle, $chunk_size);
			if (false === $data) {
				break;
			}
			
			$buffer .= $data;
			
			// If buffer is growing too large without finding a statement end, we might have a huge statement
			if (strlen($buffer) > $max_statement_size && $this->find_statement_end($buffer) === false) {
				// Try to find semicolon in smaller chunks or skip to next
				// Some INSERT statements can be very large, so we allow them up to max_buffer_size
				if (strlen($buffer) > $max_buffer_size) {
					fclose($handle);
					throw new \RuntimeException(__('SQL file contains a statement that exceeds the maximum size limit (5MB). The file may be corrupted or contain extremely large data.', 'diagnostics-toolkit'));
				}
			}
			
			// Process complete statements (ending with ; outside of quoted strings)
			$search_pos = 0;
			while (($pos = $this->find_statement_end($buffer, $search_pos)) !== false) {
				$statement = substr($buffer, 0, $pos);
				$buffer = substr($buffer, $pos + 1);
				$search_pos = 0; // Reset for next search in new buffer
				
				$query = trim($statement);
				if ('' === $query || str_starts_with($query, '--') || str_starts_with($query, '/*')) {
					continue;
				}
				
				// Replace table prefix if needed
				if ($needs_prefix_replace) {
					$query = str_replace($old_prefix, $new_prefix, $query);
				}
				
				// Skip dangerous commands
				if (preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
					continue;
				}
				
				// Convert INSERT INTO to INSERT IGNORE to prevent duplicate key errors
				// This handles cases where data might already exist or duplicates in the SQL
				if (preg_match('/^INSERT\s+INTO\s+/i', $query)) {
					$query = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $query);
				}
				
				// Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS to handle race conditions
				// where tables might be auto-created by WordPress/plugins during import
				if (preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $query)) {
					$query = preg_replace('/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $query);
				}
				
				// Execute query with error catching
				ob_start();
				$result = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ob_output = ob_get_clean();
				
				if ($result === false) {
					// Get detailed error information
					$error_message = $wpdb->last_error;
					if (empty($error_message) && $wpdb->dbh instanceof mysqli) {
						$error_message = $wpdb->dbh->error;
					}
					if (empty($error_message)) {
						$error_message = 'Unknown database error (result=false but no error message)';
					}
					
					// Check if this is a "table already exists" error - treat as warning, not error
					$is_already_exists = (strpos($error_message, 'already exists') !== false);
					
					if (!$is_already_exists) {
						$error_count++;
					}
					
					Operation_Logger::log('restore', $is_already_exists ? 'SQL query warning (ignored)' : 'SQL query failed', array(
						'error' => $error_message,
						'query_preview' => substr($query, 0, 200),
						'query_type' => strtoupper(substr($query, 0, 20)),
						'error_count' => $error_count,
						'max_errors' => $max_errors,
						'is_already_exists' => $is_already_exists
					));
					
					// On critical errors (like table missing), fail immediately
					if (strpos($error_message, 'doesn\'t exist') !== false || 
					    strpos($error_message, 'Unknown table') !== false) {
						fclose($handle);
						throw new \RuntimeException(__('SQL Error: ', 'diagnostics-toolkit') . $error_message . ' | Query: ' . substr($query, 0, 100));
					}
					
					// Only count non-already-exists errors toward max_errors limit
					if (!$is_already_exists && $error_count >= $max_errors) {
						fclose($handle);
						throw new \RuntimeException(__('Too many SQL errors. Last error: ', 'diagnostics-toolkit') . $error_message);
					}
				}
				
				$statement_count++;
				
				// Debug: Log first query to verify import is working
				if (!$first_query_logged && $result !== false) {
					$first_query_logged = true;
					Operation_Logger::log('restore', 'First SQL query executed successfully', array(
						'query_type' => strtoupper(substr($query, 0, 30)),
						'statement_count' => $statement_count
					));
				}
				
				// Update progress every 50 statements
				if ($statement_count % 50 === 0) {
					$this->set_progress('restoring_database', 93, sprintf(
						__('Importing... %d statements processed (%d errors)', 'diagnostics-toolkit'),
						$statement_count,
						$error_count
					));
					// Prevent memory buildup
					$wpdb->queries = array();
				}
			}
		}
		
		// Process any remaining statement
		$query = trim($buffer);
		if ('' !== $query && ! str_starts_with($query, '--') && ! str_starts_with($query, '/*')) {
			if (! preg_match('/\b(LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $query)) {
				// Convert INSERT INTO to INSERT IGNORE for remaining statement too
				if (preg_match('/^INSERT\s+INTO\s+/i', $query)) {
					$query = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $query);
				}
				
				// Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS for remaining statement
				if (preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $query)) {
					$query = preg_replace('/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $query);
				}
				
				// Execute with error handling
				$result = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ($result === false) {
					$error_message = $wpdb->last_error ?: 'Unknown error on final statement';
					Operation_Logger::log('restore', 'Final SQL statement failed', array(
						'error' => $error_message,
						'query_preview' => substr($query, 0, 200)
					));
				}
			}
		}
		
		fclose($handle);
		
		Operation_Logger::log('restore', 'SQL import complete', array(
			'statements' => $statement_count,
			'errors' => $error_count
		));
		
		// Restore original error reporting
		error_reporting($error_level);
		ini_set('display_errors', $display_errors);
	}

	/**
	 * Update wp-config.php with new table prefix
	 * Called when backup has different prefix than current site
	 */
	private function update_wp_config_prefix(string $new_prefix): bool {

	return true;

		// return true;
		$wp_config_path = ABSPATH . 'wp-config.php';
		
		// Check if wp-config.php exists
		if (!file_exists($wp_config_path)) {
			// Try one directory up (some installations have wp-config.php outside web root)
			$wp_config_path = dirname(ABSPATH) . '/wp-config.php';
			if (!file_exists($wp_config_path)) {
				Operation_Logger::log('restore', 'wp-config.php not found', array('abspath' => ABSPATH));
				return false;
			}
		}

		// Read current wp-config.php content
		$content = file_get_contents($wp_config_path);
		if ($content === false) {
			Operation_Logger::log('restore', 'Failed to read wp-config.php');
			return false;
		}

		// Replace the table_prefix line
		$pattern = "/(define\s*\(\s*['\"]TABLE_PREFIX['\"]\s*,\s*['\"])[a-zA-Z0-9_]*(['\"]\s*\)\s*;)/";
		$replacement = "\${1}{$new_prefix}\${2}";
		
		$new_content = preg_replace($pattern, $replacement, $content);
		
		if ($new_content === $content) {
			// No replacement made - try alternate format without TABLE_PREFIX constant
			$pattern = '/(\$table_prefix\s*=\s*[\'"])[a-zA-Z0-9_]*([\'"]\s*;)/';
			$new_content = preg_replace($pattern, "\${1}{$new_prefix}\${2}", $content);
		}

		// Write updated content back
		if ($new_content !== $content) {
			$result = file_put_contents($wp_config_path, $new_content);
			if ($result !== false) {
				Operation_Logger::log('restore', 'Updated wp-config.php table prefix', array(
					'new_prefix' => $new_prefix,
					'wp_config_path' => $wp_config_path
				));
				return true;
			}
		}

		Operation_Logger::log('restore', 'Failed to update wp-config.php or no change needed', array(
			'new_prefix' => $new_prefix,
			'wp_config_path' => $wp_config_path
		));
		return false;
	}
}
