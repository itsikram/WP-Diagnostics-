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
		
		// Clear any stale progress and set initial 5%
		delete_transient(self::TRANSIENT_PROGRESS);
		$this->set_progress('preparing', 5, __('Starting backup...', 'wp-ultimate-diagnostics-toolkit'));
		
		$result = $this->create_backup_package($this->normalize_components($components), $gzip, $password);
		wp_send_json_success($result);
	}

	public function ajax_list_backups(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('backups' => $this->list_backups()));
	}

	public function ajax_schedule_backup(): void {
		Security_Guard::assert_ajax_admin();
		$enabled    = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
		$interval   = sanitize_text_field((string) wp_unslash($_POST['interval'] ?? 'daily'));
		$components = isset($_POST['components']) ? (array) json_decode((string) wp_unslash($_POST['components']), true) : array('database');
		$config     = array(
			'enabled'    => $enabled,
			'interval'   => in_array($interval, array('hourly', 'twicedaily', 'daily'), true) ? $interval : 'daily',
			'components' => $this->normalize_components($components),
		);
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
			wp_send_json_error(array('message' => __('No backup path provided.', 'wp-ultimate-diagnostics-toolkit')));
		}
		
		try {
			$safe = Security_Guard::normalize_inside_wp($path);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
			return;
		}
		
		if (! file_exists($safe)) {
			wp_send_json_error(array('message' => __('Backup file does not exist.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}
		
		if (! is_writable($safe)) {
			wp_send_json_error(array('message' => __('Backup file cannot be deleted (permission denied).', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}
		
		if (unlink($safe)) {
			// Also remove from log history
			$history = (array) get_option(self::OPTION_LOGS, array());
			$history = array_filter($history, function($entry) use ($safe) {
				return isset($entry['file']) && $entry['file'] !== $safe;
			});
			update_option(self::OPTION_LOGS, array_values($history), false);
			
			Operation_Logger::log('backup', 'Backup deleted', array('file' => $safe));
			wp_send_json_success(array('message' => __('Backup deleted successfully.', 'wp-ultimate-diagnostics-toolkit')));
		} else {
			wp_send_json_error(array('message' => __('Failed to delete backup file.', 'wp-ultimate-diagnostics-toolkit')));
		}
	}

	public function ajax_download_backup(): void {
		Security_Guard::assert_ajax_admin();
		
		$path = isset($_GET['file']) ? (string) wp_unslash($_GET['file']) : '';
		if (empty($path)) {
			wp_send_json_error(array('message' => __('No file specified.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}
		
		try {
			$safe = Security_Guard::normalize_inside_wp($path);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 403);
			return;
		}
		
		if (! file_exists($safe) || ! is_readable($safe)) {
			wp_send_json_error(array('message' => __('File not found or not readable.', 'wp-ultimate-diagnostics-toolkit')), 404);
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
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: DENY');
		
		// Output file
		readfile($safe);
		exit;
	}

	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();
		$progress = get_transient(self::TRANSIENT_PROGRESS);
		if (! is_array($progress)) {
			$progress = array('status' => 'idle', 'percent' => 0, 'message' => '');
		}
		wp_send_json_success($progress);
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

	public function run_scheduled_backup(): void {
		$config = (array) get_option(self::OPTION_SCHEDULE, array());
		if (empty($config['enabled'])) {
			return;
		}
		$components = $this->normalize_components((array) ($config['components'] ?? array('database')));
		$this->create_backup_package($components, false, '');
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
			ini_set('memory_limit', '1024M');
		}
		
		// Set execution time to 0 (unlimited) or max 600 seconds
		if (0 !== (int) $original_max_execution_time) {
			set_time_limit(600);
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
		
		$db_file = $work_dir . 'database.sql';
		$config  = array(
			'created_at'   => gmdate('c'),
			'site_url'     => home_url('/'),
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
			$this->copy_tree(ABSPATH, $work_dir . 'wp-core/', array('wp-content', '.git'));
			$progress += $progress_step;
		}
		if (in_array('plugins', $components, true)) {
			$this->set_progress('running', $progress, __('Copying plugins...', 'wp-ultimate-diagnostics-toolkit'));
			$this->copy_tree(WP_CONTENT_DIR . '/plugins', $work_dir . 'plugins/', array());
			$progress += $progress_step;
		}
		if (in_array('themes', $components, true)) {
			$this->set_progress('running', $progress, __('Copying themes...', 'wp-ultimate-diagnostics-toolkit'));
			$this->copy_tree(WP_CONTENT_DIR . '/themes', $work_dir . 'themes/', array());
			$progress += $progress_step;
		}
		if (in_array('uploads', $components, true)) {
			$this->set_progress('running', $progress, __('Copying uploads...', 'wp-ultimate-diagnostics-toolkit'));
			// Exclude wudt-backups to prevent recursive copying of backup files
			$this->copy_tree(WP_CONTENT_DIR . '/uploads', $work_dir . 'uploads/', array('wudt-backups'));
			$progress += $progress_step;
		}

		$this->set_progress('zipping', 60, __('Creating ZIP archive...', 'wp-ultimate-diagnostics-toolkit'));
		$zip_file = $backup_dir . $filename . '.zip';
		
		// Debug logging before zip
		Operation_Logger::log('backup', 'Before zip creation', array(
			'work_dir' => $work_dir,
			'work_dir_exists' => is_dir($work_dir),
			'zip_file' => $zip_file,
			'backup_dir_exists' => is_dir($backup_dir),
			'backup_dir_writable' => is_writable($backup_dir),
		));
		
		$this->zip_dir($work_dir, $zip_file, $password);
		$progress = 75;
		
		// Debug logging after zip
		Operation_Logger::log('backup', 'After zip creation', array(
			'zip_file' => $zip_file,
			'zip_exists' => file_exists($zip_file),
			'zip_size' => file_exists($zip_file) ? filesize($zip_file) : 0,
		));

		$final_file = $zip_file;
		if ($gzip) {
			$this->set_progress('compressing', 80, __('Compressing with GZIP...', 'wp-ultimate-diagnostics-toolkit'));
			$gz_file = $backup_dir . $filename . '.zip.gz';
			$input   = fopen($zip_file, 'rb');
			$output  = gzopen($gz_file, 'wb9');
			if (is_resource($input) && false !== $output) {
				$total_size = filesize($zip_file);
				$processed = 0;
				$last_percent = 80;
				while (! feof($input)) {
					$data = fread($input, 8192);
					if (false !== $data) {
						gzwrite($output, $data);
						$processed += strlen($data);
						if ($total_size > 0) {
							$gzip_percent = 80 + intval(($processed / $total_size) * 15);
							// Only update in 5% steps
							if ($gzip_percent >= $last_percent + 5) {
								$last_percent = floor($gzip_percent / 5) * 5;
								$this->set_progress('compressing', min(95, $last_percent), __('Compressing with GZIP...', 'wp-ultimate-diagnostics-toolkit'));
							}
						}
					}
				}
				fclose($input);
				gzclose($output);
				$final_file = $gz_file;
			}
			$progress = 95;
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
			'size' => $entry['size'],
			'exists' => file_exists($final_file),
		));
		
		$this->push_log($entry);
		
		// Store backup info in progress for auto-download
		$progress_data = array(
			'status'    => 'complete',
			'percent'   => 100,
			'message'   => __('Backup complete!', 'wp-ultimate-diagnostics-toolkit'),
			'timestamp' => time(),
			'backup'    => $entry,
		);
		set_transient(self::TRANSIENT_PROGRESS, $progress_data, 5 * MINUTE_IN_SECONDS);
		
		Operation_Logger::log('backup', 'Backup package created', $entry);
		
		// Restore original limits
		ini_set('memory_limit', $original_memory_limit);
		if (0 !== (int) $original_max_execution_time) {
			set_time_limit((int) $original_max_execution_time);
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
		$items = array();
		$seen  = array(); // Track base names to avoid duplicates
		$it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		
		// First pass: collect all files
		$all_files = array();
		foreach ($it as $file) {
			if (! $file->isFile()) {
				continue;
			}
			$name = (string) $file->getFilename();
			$path = wp_normalize_path((string) $file->getPathname());
			
			// Skip files in tmp-* directories (work directories)
			if (preg_match('/tmp-[a-zA-Z0-9]+\//', $path)) {
				continue;
			}
			
			// Only include zip files that match our backup naming pattern
			// Pattern: site_url-hh-mm_dd-mm-yy-unique.zip (site_url may contain dots, underscores, hyphens)
			// Note: Using hyphens instead of colons for Windows compatibility
			if (! preg_match('/^.+-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-zA-Z0-9]{6}\.zip(\.gz)?$/', $name)) {
				continue;
			}
			
			$all_files[] = array('name' => $name, 'path' => $path, 'file' => $file);
		}
		
		// Sort by modification time (newest first)
		usort($all_files, function ($a, $b) {
			return $b['file']->getMTime() - $a['file']->getMTime();
		});
		
		// Second pass: prefer .zip.gz over .zip for same backup
		foreach ($all_files as $f) {
			$name = $f['name'];
			$path = $f['path'];
			$file = $f['file'];
			
			// Get base name (without .gz extension if present)
			$base_name = preg_replace('/\.gz$/', '', $name);
			
			// If we already saw this base name, skip (prefer .zip.gz which comes first alphabetically)
			if (isset($seen[$base_name])) {
				continue;
			}
			$seen[$base_name] = true;
			
			$mtime = (int) $file->getMTime();
			
			// Parse time from filename: site_url-hh-mm_dd-mm-yy-unique.zip
			$time_formatted = gmdate('H:i d-m-Y', $mtime);
			if (preg_match('/-(\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})-[a-zA-Z0-9]{6}\.zip/', $name, $matches)) {
				// Convert hyphens back to colons for display
				$hour_min = str_replace('-', ':', $matches[1]);
				$time_formatted = $hour_min . ' ' . $matches[2];
			}
			
			$items[] = array(
				'name'           => $name,
				'file'           => $path,
				'path'           => $path,
				'url'            => str_replace($paths['basedir'], $paths['baseurl'], $path),
				'size'           => (int) $file->getSize(),
				'time'           => gmdate('Y-m-d H:i:s', $mtime),
				'time_formatted' => $time_formatted,
			);
		}
		return array_slice(array_reverse($items), 0, 100);
	}

	/**
	 * @param array<int,string> $raw
	 * @return array<int,string>
	 */
	private function normalize_components(array $raw): array {
		$allowed = array('core', 'plugins', 'themes', 'uploads', 'database');
		$clean   = array_values(array_intersect($allowed, array_map('sanitize_key', $raw)));
		return empty($clean) ? array('database') : $clean;
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
		fwrite($handle, "-- WUDT SQL Backup\n-- " . gmdate('c') . "\n-- For large databases, this backup uses chunked processing\n\n");
		fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
		
		$tables = $wpdb->get_col('SHOW TABLES');
		if (! is_array($tables)) {
			fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
			fclose($handle);
			return;
		}
		
		$table_count = 0;
		foreach ($tables as $table) {
			$table_name = sanitize_text_field((string) $table);
			if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
				continue;
			}
			
			$table_count++;
			
			// Get create statement
			$create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table_name) . '`', ARRAY_N); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			fwrite($handle, "\n-- Table structure for table `{$table_name}`\n");
			fwrite($handle, "DROP TABLE IF EXISTS `" . esc_sql($table_name) . "`;\n");
			fwrite($handle, (string) ($create[1] ?? '') . ";\n\n");
			
			// Get row count for batching
			$count_result = $wpdb->get_row("SELECT COUNT(*) as cnt FROM `" . esc_sql($table_name) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total_rows = (int) ($count_result['cnt'] ?? 0);
			
			if ($total_rows === 0) {
				continue;
			}
			
			fwrite($handle, "-- Dumping data for table `{$table_name}` ({$total_rows} rows)\n");
			
			// Process in batches to avoid memory issues with large tables
			$batch_size = 1000;
			$offset = 0;
			
			while ($offset < $total_rows) {
				$rows = $wpdb->get_results(
					"SELECT * FROM `" . esc_sql($table_name) . '` LIMIT ' . (int) $batch_size . ' OFFSET ' . (int) $offset, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					ARRAY_A
				);
				
				if (empty($rows)) {
					break;
				}
				
				foreach ($rows as $row) {
					$columns = array_map(static fn( $col ): string => '`' . esc_sql((string) $col) . '`', array_keys($row));
					$values  = array_map(static fn( $value ): string => "'" . esc_sql((string) $value) . "'", array_values($row));
					fwrite($handle, 'INSERT INTO `' . esc_sql($table_name) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
				}
				
				$offset += $batch_size;
				
				// Clear query cache periodically to prevent memory buildup
				if ($offset % 10000 === 0) {
					$wpdb->queries = array();
				}
			}
			
			fwrite($handle, "\n");
		}
		
		fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
		fclose($handle);
	}

	/**
	 * Legacy method - kept for compatibility but redirects to file-based method
	 */
	private function build_sql_dump(): string {
		// For backwards compatibility only - returns empty string as this is now file-based
		return "-- This method is deprecated. Use build_sql_dump_to_file() instead.\n";
	}

	/**
	 * @param array<int,string> $exclude_roots
	 */
	private function copy_tree(string $source, string $dest, array $exclude_roots): void {
		if (! is_dir($source)) {
			return;
		}
		wp_mkdir_p($dest);
		
		// Common backup plugin directories to exclude
		$common_backup_dirs = array(
			'backups',           // Common backup folder name
			'backup',            // Common backup folder name
			'backup-db',           // WP-DB-Backup plugin
			'backupbuddy',       // BackupBuddy
			'updraft',           // UpdraftPlus
			'backwpup',          // BackWPup
			'duplicator',        // Duplicator
			'wp-clone',          // WP Clone
			'backups-daily',     // All-in-One WP Migration
			'snapshot',          // Snapshot
			'managewp',          // ManageWP
			'backup-guard',      // BackupGuard
			'wpvivid',           // WPvivid
			'backups-wp',        // Generic
			'backupwordpress',   // BackUpWordPress
		);
		
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($it as $item) {
			$src = wp_normalize_path((string) $item->getPathname());
			$rel = ltrim(str_replace(wp_normalize_path($source), '', $src), '/');
			$top = explode('/', $rel)[0] ?? '';
			
			// Check if in excluded roots
			if (in_array($top, $exclude_roots, true)) {
				continue;
			}
			
			// Check if path contains common backup directories
			$path_parts = explode('/', $rel);
			foreach ($path_parts as $part) {
				if (in_array(strtolower($part), $common_backup_dirs, true)) {
					continue 2; // Skip this item and all its contents
				}
			}
			
			$target = $dest . $rel;
			if ($item->isDir()) {
				wp_mkdir_p($target);
			} elseif ($item->isFile()) {
				// Skip large zip files (>25MB)
				if (preg_match('/\.zip(\.gz)?$/i', $src)) {
					$file_size = filesize($src);
					if ($file_size === false || $file_size > (25 * 1024 * 1024)) {
						continue; // Skip zip files larger than 25MB
					}
				}
				copy($src, $target);
			}
		}
	}

	private function zip_dir(string $source_dir, string $zip_file, string $password): void {
		// Normalize path first
		$zip_file = wp_normalize_path($zip_file);
		
		// Ensure parent directory exists
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
		if (file_exists($zip_file)) {
			unlink($zip_file);
		}
		
		$zip = new \ZipArchive();
		// Use CREATE only, not OVERWRITE (avoids temp file rename issues on Windows)
		$result = $zip->open($zip_file, \ZipArchive::CREATE);
		if (true !== $result) {
			$error_msg = 'Could not create backup archive';
			if ($result === \ZipArchive::ER_EXISTS) {
				$error_msg = 'Backup file already exists';
			} elseif ($result === \ZipArchive::ER_OPEN) {
				$error_msg = 'Could not open backup file (permission denied or invalid path)';
			} elseif ($result === \ZipArchive::ER_WRITE) {
				$error_msg = 'Could not write backup file (permission denied)';
			} elseif ($result === \ZipArchive::ER_NOENT) {
				$error_msg = 'Path does not exist';
			} elseif ($result === \ZipArchive::ER_INVAL) {
				$error_msg = 'Invalid argument';
			}
			throw new \RuntimeException($error_msg . ': ' . $zip_file . ' (Error code: ' . $result . ')');
		}
		if ('' !== $password && method_exists($zip, 'setPassword')) {
			$zip->setPassword($password);
		}
		
		// Ensure source directory exists
		if (! is_dir($source_dir)) {
			throw new \RuntimeException('Source directory does not exist: ' . $source_dir);
		}
		
		$source_dir_normalized = wp_normalize_path($source_dir);
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source_dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		
		$file_count = 0;
		foreach ($it as $file) {
			if (! $file->isFile()) {
				continue;
			}
			
			$full = wp_normalize_path((string) $file->getPathname());
			$rel  = ltrim(str_replace($source_dir_normalized, '', $full), '/');
			$rel  = Security_Guard::safe_zip_entry_name($rel);
			
			// On Windows, convert path back for addFile
			if (DIRECTORY_SEPARATOR === '\\') {
				$full = str_replace('/', '\\', $full);
			}
			
			if (! $zip->addFile($full, $rel)) {
				// Log error but continue
				error_log('WUDT Backup: Failed to add file to zip: ' . $full);
				continue;
			}
			$file_count++;
			
			if ('' !== $password && method_exists($zip, 'setEncryptionName')) {
				$zip->setEncryptionName($rel, \ZipArchive::EM_AES_256);
			}
		}
		
		if ($file_count === 0) {
			// No files were added, this is suspicious
			error_log('WUDT Backup: No files were added to the zip archive from: ' . $source_dir);
		}
		
		// Close the zip archive
		$close_result = $zip->close();
		
		// On Windows, close() may return false even if the file was created successfully
		// Check if the file exists and has content
		if (! $close_result) {
			// Get the last error
			$status = $zip->status;
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

	/**
	 * @param array<string,mixed> $entry
	 */
	private function push_log(array $entry): void {
		$history   = (array) get_option(self::OPTION_LOGS, array());
		$history[] = $entry;
		update_option(self::OPTION_LOGS, array_slice($history, -200), false);
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
			$name = $item->getFilename();
			// Match tmp-* directories
			if (! preg_match('/^tmp-[a-zA-Z0-9]+$/', $name)) {
				continue;
			}
			// Check if directory is older than cutoff
			if ($item->getMTime() < $cutoff) {
				$this->delete_recursive($item->getPathname());
			}
		}
	}
}
