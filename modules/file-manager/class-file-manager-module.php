<?php
/**
 * Professional file manager module.
 */

declare(strict_types=1);

namespace WUDT\Modules\FileManager;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use ZipArchive;

if (! defined('ABSPATH')) {
	exit;
}

class File_Manager_Module extends Module_Base {
	private array $blocked_names = array('.env');

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_fm_list', array($this, 'ajax_list'));
		add_action('wp_ajax_wudt_fm_read', array($this, 'ajax_read_file'));
		add_action('wp_ajax_wudt_fm_write', array($this, 'ajax_write_file'));
		add_action('wp_ajax_wudt_fm_create', array($this, 'ajax_create'));
		add_action('wp_ajax_wudt_fm_rename', array($this, 'ajax_rename'));
		add_action('wp_ajax_wudt_fm_delete', array($this, 'ajax_delete'));
		add_action('wp_ajax_wudt_fm_upload', array($this, 'ajax_upload'));
		add_action('wp_ajax_wudt_fm_chmod', array($this, 'ajax_chmod'));
		add_action('wp_ajax_wudt_fm_search', array($this, 'ajax_search'));
		add_action('wp_ajax_wudt_fm_download_zip', array($this, 'ajax_download_zip'));
	}

	public function get_key(): string {
		return 'file_manager';
	}

	public function get_label(): string {
		return __('File Manager', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'root'    => ABSPATH,
			'entries' => $this->list_path(ABSPATH),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_directory_listing(string $path = ''): array {
		$resolved = $this->resolve_path_for_cli($path);
		return $this->list_path($resolved);
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function search_in_path(string $path, string $query, int $limit = 200): array {
		$base       = $this->resolve_path_for_cli($path);
		$query_text = sanitize_text_field($query);
		$hits       = array();
		if ('' === $query_text) {
			return $hits;
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if (count($hits) >= $limit) {
				break;
			}
			$file_path = (string) $file->getPathname();
			if (false !== stripos($file_path, $query_text)) {
				$hits[] = array('path' => $file_path, 'kind' => 'name');
			}
			if ($file->isFile() && $file->getSize() < 1024 * 1024) {
				$content = @file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if (false !== $content && false !== stripos($content, $query_text)) {
					$hits[] = array('path' => $file_path, 'kind' => 'content');
				}
			}
		}
		return $hits;
	}

	public function ajax_list(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ABSPATH));
		wp_send_json_success(array('entries' => $this->list_path($path), 'path' => $path));
	}

	public function ajax_read_file(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$this->assert_not_blocked($path, false);
		if (! is_file($path) || ! is_readable($path)) {
			wp_send_json_error(array('message' => __('File not readable.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		$content = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ('wp-config.php' === basename($path)) {
			$content = preg_replace('/(DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY)\',\s*\'[^\']+\'/', "$1', '********'", $content) ?: $content;
		}
		wp_send_json_success(array('content' => $content, 'path' => $path));
	}

	public function ajax_write_file(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$this->assert_not_blocked($path, true);
		$content = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : '';
		file_put_contents($path, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		Operation_Logger::log('file', 'File updated', array('path' => $path));
		wp_send_json_success(array('message' => __('File saved.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_create(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$type = sanitize_text_field((string) ($_POST['entry_type'] ?? 'file'));
		$this->assert_not_blocked($path, true);
		if ('dir' === $type) {
			wp_mkdir_p($path);
		} else {
			file_put_contents($path, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		Operation_Logger::log('file', 'Entry created', array('path' => $path, 'type' => $type));
		wp_send_json_success(array('message' => __('Created.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_rename(): void {
		$this->authorize();
		$old = $this->resolve_path((string) ($_POST['old_path'] ?? ''));
		$new = $this->resolve_path((string) ($_POST['new_path'] ?? ''));
		$this->assert_not_blocked($old, true);
		$this->assert_not_blocked($new, true);
		rename($old, $new);
		Operation_Logger::log('file', 'Entry renamed', array('old' => $old, 'new' => $new));
		wp_send_json_success(array('message' => __('Renamed.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_delete(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$this->assert_not_blocked($path, true);
		$this->delete_recursive($path);
		Operation_Logger::log('file', 'Entry deleted', array('path' => $path));
		wp_send_json_success(array('message' => __('Deleted.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_upload(): void {
		$this->authorize();
		$dir = $this->resolve_path((string) ($_POST['path'] ?? ABSPATH));
		if (empty($_FILES['file']) || ! isset($_FILES['file']['tmp_name'])) {
			wp_send_json_error(array('message' => __('Missing upload file.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		$name = sanitize_file_name((string) $_FILES['file']['name']);
		$dest = trailingslashit($dir) . $name;
		$this->assert_not_blocked($dest, true);
		move_uploaded_file((string) $_FILES['file']['tmp_name'], $dest);
		Operation_Logger::log('file', 'File uploaded', array('path' => $dest));
		wp_send_json_success(array('message' => __('Uploaded.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_chmod(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$mode = isset($_POST['mode']) ? intval((string) $_POST['mode'], 8) : 0644;
		$this->assert_not_blocked($path, true);
		chmod($path, $mode);
		Operation_Logger::log('file', 'Permissions changed', array('path' => $path, 'mode' => decoct($mode)));
		wp_send_json_success(array('message' => __('Permissions updated.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_search(): void {
		$this->authorize();
		$query = sanitize_text_field((string) ($_POST['query'] ?? ''));
		$base  = $this->resolve_path((string) ($_POST['path'] ?? ABSPATH));
		$hits  = $this->search_in_path($base, $query, 200);
		wp_send_json_success(array('results' => $hits));
	}

	public function ajax_download_zip(): void {
		$this->authorize();
		$path = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$zip  = new ZipArchive();
		$up   = wp_get_upload_dir();
		$file = trailingslashit($up['basedir']) . 'wudt-download-' . gmdate('Ymd-His') . '.zip';
		if (true !== $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
			wp_send_json_error(array('message' => __('Could not create ZIP.', 'wp-ultimate-diagnostics-toolkit')), 500);
		}
		$this->zip_add_path($zip, $path, basename($path));
		$zip->close();
		Operation_Logger::log('file', 'ZIP generated', array('source' => $path, 'zip' => $file));
		wp_send_json_success(array('url' => str_replace($up['basedir'], $up['baseurl'], $file)));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_path(string $path): array {
		$out = array();
		foreach (scandir($path) ?: array() as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$full   = trailingslashit($path) . $entry;
			$is_dir = is_dir($full);
			$out[]  = array(
				'name'        => $entry,
				'path'        => $full,
				'type'        => $is_dir ? 'dir' : 'file',
				'size'        => $is_dir ? 0 : (int) filesize($full),
				'permissions' => substr(sprintf('%o', (int) fileperms($full)), -4),
			);
		}
		return $out;
	}

	private function authorize(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
	}

	private function resolve_path(string $path): string {
		$path = wp_normalize_path((string) wp_unslash($path));
		if ('' === $path) {
			return ABSPATH;
		}
		$full = wp_normalize_path(realpath($path) ?: $path);
		if (0 !== strpos($full, wp_normalize_path(ABSPATH))) {
			wp_send_json_error(array('message' => __('Path outside WordPress root.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		return $full;
	}

	private function resolve_path_for_cli(string $path): string {
		$normalized = wp_normalize_path($path);
		if ('' === $normalized) {
			return wp_normalize_path(ABSPATH);
		}
		$full = wp_normalize_path(realpath($normalized) ?: $normalized);
		if (0 !== strpos($full, wp_normalize_path(ABSPATH))) {
			throw new \RuntimeException('Path outside WordPress root.');
		}
		return $full;
	}

	private function assert_not_blocked(string $path, bool $write): void {
		$base = basename($path);
		if (in_array($base, $this->blocked_names, true)) {
			wp_send_json_error(array('message' => __('Access blocked for sensitive file.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		if ($write && 'wp-config.php' === $base) {
			wp_send_json_error(array('message' => __('wp-config.php is read-only in File Manager.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
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

	private function zip_add_path(ZipArchive $zip, string $path, string $local_base): void {
		if (is_file($path)) {
			$zip->addFile($path, $local_base);
			return;
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $item) {
			$full  = (string) $item->getPathname();
			$local = $local_base . '/' . ltrim(str_replace($path, '', $full), '/\\');
			if ($item->isFile()) {
				$zip->addFile($full, $local);
			}
		}
	}
}
