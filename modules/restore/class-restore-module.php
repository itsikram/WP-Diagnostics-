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
		$safe = Security_Guard::normalize_inside_wp($path);
		$info = $this->read_archive_metadata($safe);
		wp_send_json_success($info);
	}

	public function ajax_restore(): void {
		Security_Guard::assert_ajax_admin();
		$path        = isset($_POST['backup_path']) ? (string) wp_unslash($_POST['backup_path']) : '';
		$options     = isset($_POST['restore_options']) ? (array) json_decode((string) wp_unslash($_POST['restore_options']), true) : array();
		$safe_mode   = isset($_POST['safe_mode']) && '1' === (string) wp_unslash($_POST['safe_mode']);
		$media_base  = isset($_POST['media_base']) ? sanitize_text_field((string) wp_unslash($_POST['media_base'])) : '';
		$archive     = Security_Guard::normalize_inside_wp($path);
		$selected    = $this->normalize_restore_options($options);
		$restored    = $this->restore_package($archive, $selected, $safe_mode, $media_base);
		wp_send_json_success($restored);
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

		$this->extract_archive($archive, $temp);
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
		$opened = $zip->open($archive);
		if (true !== $opened) {
			throw new \RuntimeException('Unable to open archive.');
		}
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (is_array($stat) && isset($stat['name'])) {
				$list[] = (string) $stat['name'];
			}
		}
		$zip->close();
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
		if (true !== $zip->open($archive)) {
			throw new \RuntimeException('Could not open backup archive.');
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
}
