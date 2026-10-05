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
		add_action('wp_ajax_wudt_fm_fetch', array($this, 'ajax_fetch_download'));
		add_action('wp_ajax_wudt_fm_compress', array($this, 'ajax_compress'));
		add_action('wp_ajax_wudt_fm_extract', array($this, 'ajax_extract'));
	}

	public function get_key(): string {
		return 'file_manager';
	}

	public function get_label(): string {
		return __('File Manager', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'root'    => $this->root() . '/',
			'entries' => $this->list_path($this->root()),
		);
	}

	/* ---------------------------------------------------------------------
	 * CLI helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_directory_listing(string $path = ''): array {
		return $this->list_path($this->resolve_path($path));
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function search_in_path(string $path, string $query, int $limit = 200): array {
		$base = $this->resolve_path($path);
		$query_text = trim($query);
		$hits = array();
		if ('' === $query_text || ! is_dir($base)) {
			return $hits;
		}
		$started = microtime(true);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
				static function ($file) {
					return ! ($file->isDir() && in_array($file->getFilename(), array('.git', 'node_modules', 'wudt-migrations', 'wudt-ai-backups', 'wudt-backups'), true));
				}
			),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ($iterator as $file) {
			if (count($hits) >= $limit || (microtime(true) - $started) > 20) {
				break;
			}
			$file_path = wp_normalize_path((string) $file->getPathname());
			if (false !== stripos($file->getFilename(), $query_text)) {
				$hits[] = array('path' => $file_path, 'kind' => 'name');
				continue;
			}
			if ($file->isFile() && $file->getSize() < 1024 * 1024) {
				$content = @file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if (false !== $content && ! str_contains(substr($content, 0, 1000), "\0") && false !== stripos($content, $query_text)) {
					$hits[] = array('path' => $file_path, 'kind' => 'content');
				}
			}
		}
		return $hits;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Run a handler and turn any exception into a JSON error instead of a 500.
	 */
	private function handle(callable $callback): void {
		$this->authorize();
		try {
			wp_send_json_success($callback());
		} catch (\Throwable $e) {
			// HTTP 200 so every caller's success handler receives and shows the message.
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_list(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			if (! is_dir($path)) {
				$path = is_file($path) ? dirname($path) : $this->root();
			}
			return array('entries' => $this->list_path($path), 'path' => $path);
		});
	}

	public function ajax_read_file(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$this->assert_not_blocked($path);
			if (! is_file($path) || ! is_readable($path)) {
				throw new \RuntimeException(__('File not found or not readable.', 'diagnostics-toolkit'));
			}
			if (filesize($path) > 5 * MB_IN_BYTES) {
				throw new \RuntimeException(__('File is larger than 5 MB; download it instead of editing.', 'diagnostics-toolkit'));
			}
			$content = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if (str_contains(substr($content, 0, 8000), "\0")) {
				throw new \RuntimeException(__('This is a binary file and cannot be edited as text.', 'diagnostics-toolkit'));
			}
			return array('content' => $content, 'path' => $path);
		});
	}

	public function ajax_write_file(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$this->assert_not_blocked($path);
			if (is_dir($path)) {
				throw new \RuntimeException(__('That path is a folder.', 'diagnostics-toolkit'));
			}
			$content = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file contents are saved exactly as typed in the code editor (admin only).
			if (preg_match('/\.(php|phtml|inc)$/i', $path)) {
				try {
					token_get_all($content, TOKEN_PARSE);
				} catch (\ParseError $e) {
					/* translators: 1: error, 2: line */
					throw new \RuntimeException(sprintf(__('Not saved: PHP syntax error — %1$s on line %2$d. Saving it would break the site.', 'diagnostics-toolkit'), $e->getMessage(), $e->getLine()));
				}
			}
			if (file_exists($path) && ! is_writable($path)) {
				throw new \RuntimeException(__('File is not writable (check permissions).', 'diagnostics-toolkit'));
			}
			if (! is_dir(dirname($path))) {
				throw new \RuntimeException(__('The folder does not exist.', 'diagnostics-toolkit'));
			}
			if (false === file_put_contents($path, $content, LOCK_EX)) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				throw new \RuntimeException(__('Could not write the file.', 'diagnostics-toolkit'));
			}
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($path, true);
			}
			Operation_Logger::log('file', 'File updated', array('path' => $path));
			return array('message' => __('File saved.', 'diagnostics-toolkit'));
		});
	}

	public function ajax_create(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$type = sanitize_key((string) ($_POST['entry_type'] ?? 'file'));
			$this->assert_not_blocked($path);
			$this->assert_valid_name(basename($path));
			if (file_exists($path)) {
				throw new \RuntimeException(sprintf(/* translators: %s: name */ __('“%s” already exists.', 'diagnostics-toolkit'), basename($path)));
			}
			if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
				throw new \RuntimeException(__('The parent folder is not writable.', 'diagnostics-toolkit'));
			}
			$ok = 'dir' === $type ? wp_mkdir_p($path) : false !== file_put_contents($path, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if (! $ok) {
				throw new \RuntimeException(__('Could not create it.', 'diagnostics-toolkit'));
			}
			Operation_Logger::log('file', 'Entry created', array('path' => $path, 'type' => $type));
			return array('message' => __('Created.', 'diagnostics-toolkit'), 'path' => $path);
		});
	}

	public function ajax_rename(): void {
		$this->handle(function () {
			$old = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['old_path'] ?? '')));
			$new = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['new_path'] ?? '')));
			$this->assert_not_blocked($old);
			$this->assert_not_blocked($new);
			$this->assert_not_protected($old, __('moved or renamed', 'diagnostics-toolkit'));
			$this->assert_valid_name(basename($new));
			if (! file_exists($old)) {
				throw new \RuntimeException(__('The source no longer exists.', 'diagnostics-toolkit'));
			}
			if ($old === $new) {
				return array('message' => __('Nothing to change.', 'diagnostics-toolkit'), 'path' => $new);
			}
			if (is_dir($old) && 0 === stripos($new . '/', $old . '/')) {
				throw new \RuntimeException(__('A folder cannot be moved into itself.', 'diagnostics-toolkit'));
			}
			if (file_exists($new) && strtolower($old) !== strtolower($new)) {
				throw new \RuntimeException(sprintf(/* translators: %s: name */ __('“%s” already exists in the destination.', 'diagnostics-toolkit'), basename($new)));
			}
			if (! is_dir(dirname($new))) {
				throw new \RuntimeException(__('The destination folder does not exist.', 'diagnostics-toolkit'));
			}
			if (! @rename($old, $new)) {
				throw new \RuntimeException(__('Could not rename/move (check permissions, or the file may be in use).', 'diagnostics-toolkit'));
			}
			Operation_Logger::log('file', 'Entry renamed', array('old' => $old, 'new' => $new));
			return array('message' => __('Done.', 'diagnostics-toolkit'), 'path' => $new);
		});
	}

	public function ajax_delete(): void {
		$this->handle(function () {
			$paths = isset($_POST['paths']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['paths'])) : array(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$deleted = 0;
			foreach ($paths as $raw) {
				$path = $this->resolve_path((string) $raw);
				$this->assert_not_blocked($path);
				$this->assert_not_protected($path, __('deleted', 'diagnostics-toolkit'));
				if (! file_exists($path) && ! is_link($path)) {
					continue;
				}
				$this->delete_recursive($path);
				Operation_Logger::log('file', 'Entry deleted', array('path' => $path));
				$deleted++;
			}
			/* translators: %d: count */
			return array('message' => sprintf(_n('Deleted %d item.', 'Deleted %d items.', $deleted, 'diagnostics-toolkit'), $deleted));
		});
	}

	public function ajax_upload(): void {
		$this->handle(function () {
			$dir = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			if (! is_dir($dir) || ! is_writable($dir)) {
				throw new \RuntimeException(__('The current folder is not writable.', 'diagnostics-toolkit'));
			}
			$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if (! is_array($file) || empty($file['tmp_name'])) {
				throw new \RuntimeException(sprintf(/* translators: %s: size */ __('No file received. The file may exceed the server upload limit (%s).', 'diagnostics-toolkit'), size_format(wp_max_upload_size())));
			}
			if (UPLOAD_ERR_OK !== (int) $file['error']) {
				$messages = array(
					UPLOAD_ERR_INI_SIZE   => sprintf(/* translators: %s: size */ __('The file exceeds the server upload limit (%s).', 'diagnostics-toolkit'), size_format(wp_max_upload_size())),
					UPLOAD_ERR_FORM_SIZE  => __('The file is too large.', 'diagnostics-toolkit'),
					UPLOAD_ERR_PARTIAL    => __('The upload was interrupted.', 'diagnostics-toolkit'),
					UPLOAD_ERR_NO_TMP_DIR => __('The server has no temporary folder.', 'diagnostics-toolkit'),
					UPLOAD_ERR_CANT_WRITE => __('The server could not write the upload.', 'diagnostics-toolkit'),
				);
				throw new \RuntimeException($messages[(int) $file['error']] ?? __('Upload failed.', 'diagnostics-toolkit'));
			}
			$name = sanitize_file_name((string) $file['name']);
			$this->assert_valid_name($name);
			$dest = $dir . '/' . $name;
			$this->assert_not_blocked($dest);
			if (is_dir($dest)) {
				throw new \RuntimeException(__('A folder with that name already exists.', 'diagnostics-toolkit'));
			}
			if (! is_uploaded_file((string) $file['tmp_name']) || ! @move_uploaded_file((string) $file['tmp_name'], $dest)) {
				throw new \RuntimeException(__('Could not save the uploaded file.', 'diagnostics-toolkit'));
			}
			Operation_Logger::log('file', 'File uploaded', array('path' => $dest));
			return array('message' => sprintf(/* translators: %s: file */ __('Uploaded %s.', 'diagnostics-toolkit'), $name), 'path' => $dest);
		});
	}

	public function ajax_chmod(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$raw = preg_replace('/[^0-7]/', '', sanitize_text_field(wp_unslash($_POST['mode'] ?? '')));
			if (! is_string($raw) || strlen($raw) < 3 || strlen($raw) > 4) {
				throw new \RuntimeException(__('Enter permissions as 3–4 octal digits, e.g. 644 or 755.', 'diagnostics-toolkit'));
			}
			$mode = intval($raw, 8);
			$this->assert_not_blocked($path);
			if (! file_exists($path) || ! @chmod($path, $mode)) {
				throw new \RuntimeException(__('Could not change permissions.', 'diagnostics-toolkit'));
			}
			clearstatcache(true, $path);
			Operation_Logger::log('file', 'Permissions changed', array('path' => $path, 'mode' => decoct($mode)));
			return array('message' => __('Permissions updated.', 'diagnostics-toolkit'));
		});
	}

	public function ajax_search(): void {
		$this->handle(function () {
			$query = sanitize_text_field((string) wp_unslash($_POST['query'] ?? ''));
			$base = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			return array('results' => $this->search_in_path($base, $query, 200));
		});
	}

	/**
	 * Prepare a download. Files are streamed as-is, folders as a ZIP, through an
	 * admin-only link (never via a public URL).
	 */
	public function ajax_download_zip(): void {
		$this->handle(function () {
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			$this->assert_not_blocked($path);
			if (! file_exists($path)) {
				throw new \RuntimeException(__('Not found.', 'diagnostics-toolkit'));
			}
			$name = basename($path) ?: 'wordpress';
			$file = $path;
			$temporary = false;
			if (is_dir($path)) {
				$this->require_zip();
				$file = $this->temp_dir() . '/' . wp_generate_password(16, false, false) . '.zip';
				$zip = new ZipArchive();
				if (true !== $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
					throw new \RuntimeException(__('Could not create the ZIP file.', 'diagnostics-toolkit'));
				}
				$this->zip_add_path($zip, $path, $name, $file);
				if (! $zip->close()) {
					throw new \RuntimeException(__('Could not finish the ZIP file (folder too large or disk full?).', 'diagnostics-toolkit'));
				}
				$name .= '.zip';
				$temporary = true;
			}
			$token = wp_generate_password(32, false, false);
			set_transient('wudt_fm_dl_' . $token, array('file' => $file, 'name' => $name, 'temp' => $temporary, 'user' => get_current_user_id()), 10 * MINUTE_IN_SECONDS);
			Operation_Logger::log('file', 'Download prepared', array('source' => $path));
			return array(
				'url' => add_query_arg(array('action' => 'wudt_fm_fetch', 'token' => $token), admin_url('admin-ajax.php')),
			);
		});
	}

	public function ajax_fetch_download(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Permission denied.', 'diagnostics-toolkit'), 403);
		}
		$token = preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['token'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		$info = get_transient('wudt_fm_dl_' . $token);
		if (! is_array($info) || (int) $info['user'] !== get_current_user_id() || ! is_file((string) $info['file'])) {
			wp_die(esc_html__('This download link has expired. Start the download again.', 'diagnostics-toolkit'), 404);
		}
		delete_transient('wudt_fm_dl_' . $token);
		$file = (string) $info['file'];
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $info['name']) . '"');
		header('Content-Length: ' . (string) filesize($file));
		readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if (! empty($info['temp'])) {
			@unlink($file);
		}
		exit;
	}

	public function ajax_compress(): void {
		$this->handle(function () {
			$this->require_zip();
			$paths = isset($_POST['paths']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['paths'])) : array();
			if (empty($paths)) {
				throw new \RuntimeException(__('No files selected.', 'diagnostics-toolkit'));
			}
			$name = sanitize_file_name((string) wp_unslash($_POST['name'] ?? ''));
			if ('' === $name) {
				$name = 'archive-' . gmdate('Ymd-His');
			}
			if (! str_ends_with(strtolower($name), '.zip')) {
				$name .= '.zip';
			}

			$resolved = array();
			foreach ($paths as $p) {
				$r = $this->resolve_path((string) $p);
				$this->assert_not_blocked($r);
				if (file_exists($r)) {
					$resolved[] = $r;
				}
			}
			if (empty($resolved)) {
				throw new \RuntimeException(__('The selected items no longer exist.', 'diagnostics-toolkit'));
			}
			$base_dir = dirname($resolved[0]);
			if (! is_writable($base_dir)) {
				throw new \RuntimeException(__('This folder is not writable.', 'diagnostics-toolkit'));
			}
			$output = $this->unique_path($base_dir . '/' . $name);

			$zip = new ZipArchive();
			if (true !== $zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
				throw new \RuntimeException(__('Could not create ZIP archive.', 'diagnostics-toolkit'));
			}
			foreach ($resolved as $item) {
				$this->zip_add_path($zip, $item, basename($item), $output);
			}
			if (! $zip->close()) {
				@unlink($output);
				throw new \RuntimeException(__('Could not finish the archive (disk full or unreadable files?).', 'diagnostics-toolkit'));
			}
			Operation_Logger::log('file', 'Archive created', array('output' => $output, 'items' => count($resolved)));
			return array(
				/* translators: %s: Archive name */
				'message' => sprintf(__('Archive "%s" created successfully.', 'diagnostics-toolkit'), basename($output)),
				'name'    => basename($output),
				'path'    => $output,
				'items'   => count($resolved),
			);
		});
	}

	public function ajax_extract(): void {
		$this->handle(function () {
			$this->require_zip();
			$path = $this->resolve_path(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
			if (! is_file($path) || ! is_readable($path)) {
				throw new \RuntimeException(__('Archive file not readable.', 'diagnostics-toolkit'));
			}
			if ('zip' !== strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
				throw new \RuntimeException(__('Only ZIP archives are supported.', 'diagnostics-toolkit'));
			}
			$dest = ! empty($_POST['destination'])
				? $this->resolve_path(sanitize_text_field(wp_unslash($_POST['destination'])))
				: $this->unique_path(dirname($path) . '/' . basename($path, '.' . pathinfo($path, PATHINFO_EXTENSION)));
			$this->assert_not_blocked($dest);

			$zip = new ZipArchive();
			if (true !== $zip->open($path)) {
				throw new \RuntimeException(__('Could not open the ZIP archive (it may be damaged).', 'diagnostics-toolkit'));
			}
			// Reject entries that would write outside the destination ("zip slip").
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
				if ('' === $entry || '/' === $entry[0] || preg_match('#(^|/)\.\.(/|$)#', $entry) || preg_match('#^[a-zA-Z]:#', $entry) || str_contains($entry, "\0")) {
					$zip->close();
					throw new \RuntimeException(__('The archive contains unsafe paths and was not extracted.', 'diagnostics-toolkit'));
				}
			}
			if (! is_dir($dest) && ! wp_mkdir_p($dest)) {
				$zip->close();
				throw new \RuntimeException(__('Could not create the destination folder.', 'diagnostics-toolkit'));
			}
			$num_files = $zip->numFiles;
			$ok = $zip->extractTo($dest);
			$zip->close();
			if (true !== $ok) {
				throw new \RuntimeException(__('Failed to extract archive (permissions or disk space).', 'diagnostics-toolkit'));
			}
			Operation_Logger::log('file', 'Archive extracted', array('archive' => $path, 'destination' => $dest, 'files' => $num_files));
			return array(
				/* translators: 1: Number of files, 2: Destination folder */
				'message' => sprintf(__('Extracted %1$d files to "%2$s".', 'diagnostics-toolkit'), $num_files, basename($dest)),
				'files'   => $num_files,
				'dest'    => $dest,
			);
		});
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function root(): string {
		return rtrim(wp_normalize_path(ABSPATH), '/');
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_path(string $path): array {
		$items = @scandir($path);
		if (false === $items) {
			throw new \RuntimeException(__('This folder cannot be read (permissions).', 'diagnostics-toolkit'));
		}
		$out = array();
		foreach ($items as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$full = rtrim($path, '/') . '/' . $entry;
			$is_dir = is_dir($full);
			$out[] = array(
				'name'        => $entry,
				'path'        => $full,
				'type'        => $is_dir ? 'dir' : 'file',
				'size'        => $is_dir ? 0 : (int) @filesize($full),
				'modified'    => (int) @filemtime($full),
				'permissions' => substr(sprintf('%o', (int) @fileperms($full)), -4),
			);
		}
		return $out;
	}

	private function authorize(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'diagnostics-toolkit')), 403);
		}
	}

	/**
	 * Resolve a path inside the WordPress installation. Relative paths are relative
	 * to the WordPress root; "." and ".." are collapsed before checking, so a path
	 * can never escape the installation.
	 */
	private function resolve_path(string $path): string {
		$path = trim(wp_normalize_path((string) wp_unslash($path)));
		$root = $this->root();
		if ('' === $path || '/' === $path) {
			return $root;
		}
		if (str_contains($path, "\0")) {
			throw new \RuntimeException(__('Invalid path.', 'diagnostics-toolkit'));
		}
		$is_absolute = '/' === $path[0] || (bool) preg_match('#^[a-zA-Z]:/#', $path);
		$full = $is_absolute ? $path : $root . '/' . $path;

		$prefix = '';
		if (preg_match('#^([a-zA-Z]:)(/.*)?$#', $full, $m)) {
			$prefix = $m[1];
			$full = $m[2] ?? '/';
		}
		$parts = array();
		foreach (explode('/', $full) as $segment) {
			if ('' === $segment || '.' === $segment) {
				continue;
			}
			if ('..' === $segment) {
				array_pop($parts);
				continue;
			}
			$parts[] = $segment;
		}
		$full = $prefix . '/' . implode('/', $parts);

		if (0 === strcasecmp($full, $root) || 0 === stripos($full . '/', $root . '/')) {
			// Keep the casing the user sees for the root part.
			return $root . substr($full, strlen($root));
		}
		throw new \RuntimeException(__('That location is outside the WordPress installation.', 'diagnostics-toolkit'));
	}

	private function assert_not_blocked(string $path): void {
		if (in_array(basename($path), $this->blocked_names, true)) {
			throw new \RuntimeException(__('Access blocked for sensitive file.', 'diagnostics-toolkit'));
		}
	}

	private function assert_valid_name(string $name): void {
		if ('' === $name || '.' === $name || '..' === $name || preg_match('#[\\\\/:*?"<>|\x00-\x1f]#', $name)) {
			throw new \RuntimeException(__('That name is not allowed.', 'diagnostics-toolkit'));
		}
	}

	/**
	 * Core folders and files that must never be deleted or moved.
	 * Everything inside them (e.g. a single plugin or a ZIP in wp-content/plugins) is allowed.
	 */
	private function assert_not_protected(string $path, string $verb): void {
		$root = $this->root();
		$content = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/');
		$protected = array(
			$root,
			$root . '/wp-admin',
			$root . '/wp-includes',
			$root . '/wp-config.php',
			$root . '/index.php',
			$root . '/wp-load.php',
			$root . '/wp-settings.php',
			$content,
			rtrim(wp_normalize_path(WP_PLUGIN_DIR), '/'),
			rtrim(wp_normalize_path(get_theme_root()), '/'),
			rtrim(wp_normalize_path(wp_get_upload_dir()['basedir']), '/'),
			rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/'),
			rtrim(wp_normalize_path(dirname(WUDT_PLUGIN_FILE)), '/'),
		);
		foreach ($protected as $item) {
			if (0 === strcasecmp(rtrim($path, '/'), $item)) {
				Operation_Logger::log('file', 'Blocked change to protected path', array('path' => $path));
				/* translators: 1: name, 2: action */
				throw new \RuntimeException(sprintf(__('“%1$s” is an essential WordPress folder/file and cannot be %2$s here.', 'diagnostics-toolkit'), basename($path), $verb));
			}
		}
		// Files inside wp-admin and wp-includes are WordPress core.
		foreach (array($root . '/wp-admin/', $root . '/wp-includes/') as $core) {
			if (0 === stripos($path, $core)) {
				throw new \RuntimeException(__('WordPress core files cannot be deleted or moved here. Reinstall WordPress from Dashboard → Updates to repair core.', 'diagnostics-toolkit'));
			}
		}
	}

	private function delete_recursive(string $path): void {
		if (is_link($path) || is_file($path)) {
			if (! @unlink($path)) {
				/* translators: %s: name */
				throw new \RuntimeException(sprintf(__('Could not delete “%s” (permissions or file in use).', 'diagnostics-toolkit'), basename($path)));
			}
			return;
		}
		if (! is_dir($path)) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		$failed = 0;
		foreach ($iterator as $item) {
			$p = (string) $item->getPathname();
			$ok = ($item->isDir() && ! $item->isLink()) ? @rmdir($p) : @unlink($p);
			if (! $ok) {
				$failed++;
			}
		}
		if (! @rmdir($path) || $failed > 0) {
			/* translators: %d: count */
			throw new \RuntimeException(sprintf(__('Some items could not be deleted (%d failed — check permissions).', 'diagnostics-toolkit'), max(1, $failed)));
		}
	}

	private function zip_add_path(ZipArchive $zip, string $path, string $local_base, string $exclude = ''): void {
		$path = rtrim(wp_normalize_path($path), '/');
		if (is_file($path)) {
			$zip->addFile($path, $local_base);
			return;
		}
		$zip->addEmptyDir($local_base);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		$exclude = wp_normalize_path($exclude);
		foreach ($iterator as $item) {
			$full = wp_normalize_path((string) $item->getPathname());
			if ($full === $exclude || ! $item->isReadable()) {
				continue;
			}
			$local = $local_base . '/' . substr($full, strlen($path) + 1);
			if ($item->isDir()) {
				$zip->addEmptyDir($local);
			} else {
				$zip->addFile($full, $local);
			}
		}
	}

	private function unique_path(string $path): string {
		if (! file_exists($path)) {
			return $path;
		}
		$dir = dirname($path);
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		$stem = '' !== $ext ? basename($path, '.' . $ext) : basename($path);
		for ($i = 1; $i < 1000; $i++) {
			$candidate = $dir . '/' . $stem . '-' . $i . ('' !== $ext ? '.' . $ext : '');
			if (! file_exists($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException(__('Could not find a free file name.', 'diagnostics-toolkit'));
	}

	private function require_zip(): void {
		if (! class_exists('ZipArchive')) {
			throw new \RuntimeException(__('The PHP Zip extension is not installed on this server.', 'diagnostics-toolkit'));
		}
	}

	private function temp_dir(): string {
		$dir = wp_normalize_path(WP_CONTENT_DIR) . '/wudt-fm-tmp';
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			@file_put_contents($dir . '/index.php', "<?php\n");
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		}
		// Remove downloads older than an hour.
		foreach ((array) glob($dir . '/*.zip') as $old) {
			if (is_file((string) $old) && filemtime((string) $old) < time() - HOUR_IN_SECONDS) {
				@unlink((string) $old);
			}
		}
		return $dir;
	}
}
