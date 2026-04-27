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

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_backup_create', array($this, 'ajax_create_backup'));
		add_action('wp_ajax_wudt_backup_list', array($this, 'ajax_list_backups'));
		add_action('wp_ajax_wudt_backup_schedule', array($this, 'ajax_schedule_backup'));
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
		$result     = $this->create_backup_package($this->normalize_components($components), $gzip, $password);
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
		$stamp      = gmdate('Ymd-His');
		$work_dir   = $dir . 'tmp-' . wp_generate_password(10, false, false) . '/';
		$backup_dir = $dir . 'backup-' . $stamp . '/';
		wp_mkdir_p($work_dir);
		wp_mkdir_p($backup_dir);
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

		if (in_array('database', $components, true)) {
			file_put_contents($db_file, $this->build_sql_dump());
		}
		if (in_array('core', $components, true)) {
			$this->copy_tree(ABSPATH, $work_dir . 'wp-core/', array('wp-content', '.git'));
		}
		if (in_array('plugins', $components, true)) {
			$this->copy_tree(WP_CONTENT_DIR . '/plugins', $work_dir . 'plugins/', array());
		}
		if (in_array('themes', $components, true)) {
			$this->copy_tree(WP_CONTENT_DIR . '/themes', $work_dir . 'themes/', array());
		}
		if (in_array('uploads', $components, true)) {
			$this->copy_tree(WP_CONTENT_DIR . '/uploads', $work_dir . 'uploads/', array());
		}

		$zip_file = $backup_dir . 'backup.zip';
		$this->zip_dir($work_dir, $zip_file, $password);

		$final_file = $zip_file;
		if ($gzip) {
			$gz_file = $backup_dir . 'backup.zip.gz';
			$input   = fopen($zip_file, 'rb');
			$output  = gzopen($gz_file, 'wb9');
			if (is_resource($input) && false !== $output) {
				while (! feof($input)) {
					$data = fread($input, 8192);
					if (false !== $data) {
						gzwrite($output, $data);
					}
				}
				fclose($input);
				gzclose($output);
				$final_file = $gz_file;
			}
		}

		$this->delete_recursive($work_dir);
		$entry = array(
			'time'       => current_time('mysql'),
			'file'       => $final_file,
			'url'        => str_replace($paths['basedir'], $paths['baseurl'], $final_file),
			'components' => $components,
		);
		$this->push_log($entry);
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
			if (! preg_match('/backup\.zip(\.gz)?$/', $name)) {
				continue;
			}
			$path = wp_normalize_path((string) $file->getPathname());
			$items[] = array(
				'name' => $name,
				'path' => $path,
				'url'  => str_replace($paths['basedir'], $paths['baseurl'], $path),
				'size' => (int) $file->getSize(),
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
			$target = $dest . $rel;
			if ($item->isDir()) {
				wp_mkdir_p($target);
			} elseif ($item->isFile()) {
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
}
