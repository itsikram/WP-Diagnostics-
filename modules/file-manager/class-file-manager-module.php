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
		add_action('wp_ajax_wudt_fm_compress', array($this, 'ajax_compress'));
		add_action('wp_ajax_wudt_fm_extract', array($this, 'ajax_extract'));
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

	public function ajax_compress(): void {
		$this->authorize();
		$paths = isset($_POST['paths']) ? (array) $_POST['paths'] : array();
		$name  = isset($_POST['name']) ? sanitize_file_name((string) $_POST['name']) : '';

		if (empty($paths)) {
			wp_send_json_error(array('message' => __('No files selected.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		if (empty($name)) {
			$name = 'archive-' . gmdate('Ymd-His');
		}
		if (!str_ends_with($name, '.zip')) {
			$name .= '.zip';
		}

		// Get the directory of the first item for output location
		$first_path = $this->resolve_path((string) ($paths[0] ?? ''));
		$base_dir   = dirname($first_path);
		$output_path = $base_dir . '/' . $name;

		// Ensure we don't overwrite existing file
		$counter = 1;
		$original_name = $name;
		while (file_exists($output_path)) {
			$name = str_replace('.zip', '-' . $counter . '.zip', $original_name);
			$output_path = $base_dir . '/' . $name;
			$counter++;
		}

		$zip = new ZipArchive();
		if (true !== $zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
			wp_send_json_error(array('message' => __('Could not create ZIP archive.', 'wp-ultimate-diagnostics-toolkit')), 500);
			return;
		}

		$total_items = count($paths);
		$processed   = 0;

		foreach ($paths as $path) {
			$resolved = $this->resolve_path((string) $path);
			if (!file_exists($resolved)) {
				continue;
			}
			$this->zip_add_path($zip, $resolved, basename($resolved));
			$processed++;
		}

		$zip->close();
		Operation_Logger::log('file', 'Archive created', array('output' => $output_path, 'items' => $processed));
		wp_send_json_success(
			array(
				'message'  => sprintf(/* translators: %s: Archive name */ __('Archive "%s" created successfully.', 'wp-ultimate-diagnostics-toolkit'), $name),
				'name'     => $name,
				'path'     => $output_path,
				'items'    => $processed,
			)
		);
	}

	public function ajax_extract(): void {
		$this->authorize();
		$path    = $this->resolve_path((string) ($_POST['path'] ?? ''));
		$dest    = isset($_POST['destination']) ? (string) $_POST['destination'] : '';

		if (!is_file($path) || !is_readable($path)) {
			wp_send_json_error(array('message' => __('Archive file not readable.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		// Validate it's a ZIP file
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($ext !== 'zip') {
			wp_send_json_error(array('message' => __('Only ZIP archives are supported.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		// Determine extract destination
		if (empty($dest)) {
			$dest = dirname($path) . '/' . basename($path, '.zip');
		} else {
			$dest = $this->resolve_path($dest);
		}

		// Create destination directory if it doesn't exist
		if (!is_dir($dest)) {
			wp_mkdir_p($dest);
		}

		$zip = new ZipArchive();
		if (true !== $zip->open($path)) {
			wp_send_json_error(array('message' => __('Could not open ZIP archive.', 'wp-ultimate-diagnostics-toolkit')), 500);
			return;
		}

		$num_files = $zip->numFiles;
		if (true !== $zip->extractTo($dest)) {
			$zip->close();
			wp_send_json_error(array('message' => __('Failed to extract archive.', 'wp-ultimate-diagnostics-toolkit')), 500);
			return;
		}
		$zip->close();

		Operation_Logger::log('file', 'Archive extracted', array('archive' => $path, 'destination' => $dest, 'files' => $num_files));
		wp_send_json_success(
			array(
				'message' => sprintf(/* translators: %1$d: Number of files, %2$s: Destination path */ __('Extracted %1$d files to "%2$s".', 'wp-ultimate-diagnostics-toolkit'), $num_files, $dest),
				'files'   => $num_files,
				'dest'    => $dest,
			)
		);
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

		$abs_path = wp_normalize_path(ABSPATH);

		// First check: if path string starts with WordPress root, it's likely valid
		$path_lower = strtolower($path);
		$abs_lower = strtolower($abs_path);
		
		if (0 === strpos($path_lower, $abs_lower)) {
			// Path appears to be inside WordPress root based on string comparison
			// Try realpath to resolve any symlinks
			$real_path = @realpath($path);
			if (false !== $real_path) {
				return wp_normalize_path($real_path);
			}
			// Path doesn't exist yet, but string starts with WordPress root
			return wp_normalize_path($path);
		}

		// Path doesn't start with WordPress root - try to resolve and check
		$real_path = @realpath($path);
		if (false === $real_path) {
			// Cannot resolve and path doesn't start with WordPress root - fallback to ABSPATH
			Operation_Logger::log('file', 'Path outside WordPress root, falling back to ABSPATH', array(
				'path' => $path,
				'abspath' => $abs_path,
			));
			return $abs_path;
		}
		
		$full = wp_normalize_path($real_path);
		$full_lower = strtolower(trailingslashit($full));
		$abs_lower = strtolower(trailingslashit($abs_path));

		if (0 !== strpos($full_lower, $abs_lower)) {
			// Path is outside WordPress root - fallback to ABSPATH
			Operation_Logger::log('file', 'Path outside WordPress root, falling back to ABSPATH', array(
				'path' => $path,
				'resolved' => $full,
				'abspath' => $abs_path,
			));
			return $abs_path;
		}
		
		return $full;
	}

	private function resolve_path_for_cli(string $path): string {
		$normalized = wp_normalize_path($path);
		if ('' === $normalized) {
			return wp_normalize_path(ABSPATH);
		}
		
		$abs_path = wp_normalize_path(ABSPATH);
		
		// First check: if path string starts with WordPress root, it's likely valid
		$normalized_lower = strtolower($normalized);
		$abs_lower = strtolower($abs_path);
		
		if (0 === strpos($normalized_lower, $abs_lower)) {
			// Path appears to be inside WordPress root based on string comparison
			// Try realpath to resolve any symlinks
			$real_path = @realpath($normalized);
			if (false !== $real_path) {
				return wp_normalize_path($real_path);
			}
			// Path doesn't exist yet, but string starts with WordPress root
			return wp_normalize_path($normalized);
		}
		
		// Path doesn't start with WordPress root - try to resolve and check
		$real_path = @realpath($normalized);
		if (false === $real_path) {
			// Cannot resolve - fallback to ABSPATH
			return $abs_path;
		}
		
		$full = wp_normalize_path($real_path);
		$full_lower = strtolower(trailingslashit($full));
		$abs_lower = strtolower(trailingslashit($abs_path));
		
		if (0 !== strpos($full_lower, $abs_lower)) {
			// Path is outside WordPress root - fallback to ABSPATH
			return $abs_path;
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
		// CRITICAL SAFETY: Never delete WordPress core directories
		$protected_paths = array(
			wp_normalize_path(ABSPATH),
			wp_normalize_path(ABSPATH . 'wp-admin'),
			wp_normalize_path(ABSPATH . 'wp-includes'),
			wp_normalize_path(ABSPATH . 'wp-content'),
		);
		
		$normalized_path = wp_normalize_path($path);
		foreach ($protected_paths as $protected) {
			if ($normalized_path === $protected || strpos($normalized_path, $protected . '/') === 0) {
				Operation_Logger::log('file', 'CRITICAL: Attempted to delete protected path', array(
					'path' => $path,
					'protected_match' => $protected,
				));
				throw new \RuntimeException('Cannot delete protected path: ' . $path);
			}
		}
		
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
