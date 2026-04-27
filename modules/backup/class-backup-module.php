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

	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();
		$progress = get_transient(self::TRANSIENT_PROGRESS);
		if (! is_array($progress)) {
			$progress = array('status' => 'idle', 'percent' => 0, 'message' => '');
		}
		wp_send_json_success($progress);
	}

	private function set_progress(string $status, int $percent, string $message): void {
		set_transient(
			self::TRANSIENT_PROGRESS,
			array(
				'status'    => $status,
				'percent'   => max(0, min(100, $percent)),
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
		$paths = wp_get_upload_dir();
		$dir   = trailingslashit($paths['basedir']) . 'wudt-backups/';
		wp_mkdir_p($dir);
		
		// Create filename in format: site_url-hh:mm_dd-mm-yy with random suffix for uniqueness
		$site_url = parse_url(home_url('/'), PHP_URL_HOST);
		$site_url = preg_replace('/[^a-zA-Z0-9_-]/', '_', $site_url);
		$stamp    = gmdate('H:i_d-m-y');
		$unique   = wp_generate_password(6, false, false);
		$filename = $site_url . '-' . $stamp . '-' . $unique;
		
		$work_dir   = $dir . 'tmp-' . wp_generate_password(10, false, false) . '/';
		$backup_dir = $dir . 'backup-' . $filename . '/';
		
		$this->set_progress('preparing', 5, __('Creating backup directories...', 'wp-ultimate-diagnostics-toolkit'));
		wp_mkdir_p($work_dir);
		wp_mkdir_p($backup_dir);
		
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
			file_put_contents($db_file, $this->build_sql_dump());
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
		$this->zip_dir($work_dir, $zip_file, $password);
		$progress = 75;

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
		$entry = array(
			'time'       => current_time('mysql'),
			'file'       => $final_file,
			'url'        => str_replace($paths['basedir'], $paths['baseurl'], $final_file),
			'components' => $components,
			'size'       => (false === $file_size) ? 0 : $file_size,
			'name'       => basename($final_file),
			'time_formatted' => current_time('H:i d-m-Y'),
		);
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
		$it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
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
			// Pattern: site_url-hh:mm_dd-mm-yy-unique.zip (site_url may contain dots, underscores, hyphens)
			if (! preg_match('/^.+-\d{2}:\d{2}_\d{2}-\d{2}-\d{2}-[a-zA-Z0-9]{6}\.zip(\.gz)?$/', $name)) {
				continue;
			}
			
			$mtime = (int) $file->getMTime();
			
			// Parse time from filename: site_url-hh:mm_dd-mm-yy-unique.zip
			$time_formatted = gmdate('H:i d-m-Y', $mtime);
			if (preg_match('/-(\d{2}:\d{2})_(\d{2}-\d{2}-\d{2})-[a-zA-Z0-9]{6}\.zip/', $name, $matches)) {
				$time_formatted = $matches[1] . ' ' . $matches[2];
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

	private function build_sql_dump(): string {
		global $wpdb;
		$sql    = "-- WUDT SQL Backup\n-- " . gmdate('c') . "\n\n";
		$tables = $wpdb->get_col('SHOW TABLES');
		if (! is_array($tables)) {
			return $sql;
		}
		foreach ($tables as $table) {
			$table_name = sanitize_text_field((string) $table);
			if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
				continue;
			}
			$create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table_name) . '`', ARRAY_N); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql .= "\nDROP TABLE IF EXISTS `" . esc_sql($table_name) . "`;\n";
			$sql .= (string) ($create[1] ?? '') . ";\n";
			$rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table_name) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ((array) $rows as $row) {
				$columns = array_map(static fn( $col ): string => '`' . esc_sql((string) $col) . '`', array_keys($row));
				$values  = array_map(static fn( $value ): string => "'" . esc_sql((string) $value) . "'", array_values($row));
				$sql    .= 'INSERT INTO `' . esc_sql($table_name) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
			}
			$sql .= "\n";
		}
		return $sql;
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
		$zip = new \ZipArchive();
		if (true !== $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
			throw new \RuntimeException('Could not create backup archive.');
		}
		if ('' !== $password && method_exists($zip, 'setPassword')) {
			$zip->setPassword($password);
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source_dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if (! $file->isFile()) {
				continue;
			}
			$full = wp_normalize_path((string) $file->getPathname());
			$rel  = ltrim(str_replace(wp_normalize_path($source_dir), '', $full), '/');
			$rel  = Security_Guard::safe_zip_entry_name($rel);
			$zip->addFile($full, $rel);
			if ('' !== $password && method_exists($zip, 'setEncryptionName')) {
				$zip->setEncryptionName($rel, \ZipArchive::EM_AES_256);
			}
		}
		$zip->close();
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
