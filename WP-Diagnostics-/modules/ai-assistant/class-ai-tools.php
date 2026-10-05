<?php
/**
 * AI Tools - the actions the AI agent can take on this WordPress site.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Tools {
	private const MAX_RESULT = 40000;

	private string $conversation = '';

	public function set_conversation(string $id): void {
		$this->conversation = $id;
	}

	/* ---------------------------------------------------------------------
	 * Definitions
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<int,array{name:string,description:string,parameters:array,write:bool}>
	 */
	public function definitions(): array {
		$s = static function (string $desc, array $extra = array()) {
			return array_merge(array('type' => 'string', 'description' => $desc), $extra);
		};
		$i = static function (string $desc) {
			return array('type' => 'integer', 'description' => $desc);
		};
		$b = static function (string $desc) {
			return array('type' => 'boolean', 'description' => $desc);
		};
		$obj = static function (array $props, array $required = array()) {
			$schema = array('type' => 'object', 'properties' => $props);
			if (! empty($required)) {
				$schema['required'] = $required;
			}
			return $schema;
		};

		return array(
			// --- Read-only -------------------------------------------------
			array('name' => 'site_overview', 'write' => false, 'description' => 'Get WordPress/PHP versions, active theme, active plugins, Elementor status (version, Pro, whether flexbox containers are active), debug settings, paths and the last fatal error. Call this first.', 'parameters' => array()),
			array('name' => 'read_error_log', 'write' => false, 'description' => 'Read the latest lines of the WordPress debug.log and PHP error log, plus the last captured fatal error.', 'parameters' => $obj(array(
				'lines'    => $i('Number of lines from the end (default 80, max 400).'),
				'contains' => $s('Only return lines containing this text (optional).'),
			))),
			array('name' => 'check_site_health', 'write' => false, 'description' => 'Load a page of this site (front end or wp-admin) like a browser and report the HTTP status, fatal errors, PHP warnings and new debug.log entries. Use it to reproduce problems and to verify fixes.', 'parameters' => $obj(array(
				'path'  => $s('Path relative to the home URL, e.g. "/" or "/contact/" or "/wp-admin/". Default "/".'),
			))),
			array('name' => 'list_files', 'write' => false, 'description' => 'List a directory. Paths are relative to the WordPress root (e.g. "wp-content/themes").', 'parameters' => $obj(array(
				'path'  => $s('Directory path relative to the WordPress root.'),
				'depth' => $i('Recursion depth 1-3 (default 1).'),
			), array('path'))),
			array('name' => 'read_file', 'write' => false, 'description' => 'Read a text file. Paths are relative to the WordPress root. Large files can be read in parts with start_line/end_line.', 'parameters' => $obj(array(
				'path'       => $s('File path relative to the WordPress root.'),
				'start_line' => $i('First line to return (1-based, optional).'),
				'end_line'   => $i('Last line to return (optional).'),
			), array('path'))),
			array('name' => 'search_files', 'write' => false, 'description' => 'Search text inside files (like grep). Returns file:line matches.', 'parameters' => $obj(array(
				'query'        => $s('Text (or regular expression when regex=true) to find.'),
				'path'         => $s('Directory to search, relative to the WordPress root (default "wp-content").'),
				'file_pattern' => $s('Filename pattern, e.g. "*.php" (default all text files).'),
				'regex'        => $b('Treat query as a PCRE regular expression.'),
			), array('query'))),
			array('name' => 'db_query', 'write' => false, 'description' => 'Run a read-only SQL query (SELECT, SHOW, DESCRIBE, EXPLAIN). Use {prefix} for the table prefix, e.g. SELECT * FROM {prefix}options LIMIT 5.', 'parameters' => $obj(array(
				'sql' => $s('One read-only SQL statement.'),
			), array('sql'))),
			array('name' => 'get_option', 'write' => false, 'description' => 'Read a WordPress option value.', 'parameters' => $obj(array('name' => $s('Option name.')), array('name'))),
			array('name' => 'list_posts', 'write' => false, 'description' => 'List posts/pages with IDs, status, URL, template and whether they are built with Elementor.', 'parameters' => $obj(array(
				'post_type' => $s('Post type (default "page"). Use "any" for all.'),
				'search'    => $s('Search text (optional).'),
				'limit'     => $i('Max results (default 30).'),
			))),
			array('name' => 'get_post', 'write' => false, 'description' => 'Get a post/page with its content and meta keys.', 'parameters' => $obj(array('id' => $i('Post ID.')), array('id'))),
			array('name' => 'elementor_get_page', 'write' => false, 'description' => 'Get the Elementor element tree (JSON) of a page, its page settings and template. For big pages an outline is returned; then use element_id to fetch one element.', 'parameters' => $obj(array(
				'post_id'    => $i('Page ID.'),
				'element_id' => $s('Only return this element (optional).'),
			), array('post_id'))),
			array('name' => 'elementor_get_kit', 'write' => false, 'description' => 'Get the Elementor global kit settings: global colors, global fonts, layout width, etc.', 'parameters' => array()),
			array('name' => 'elementor_list_widgets', 'write' => false, 'description' => 'List the Elementor widget types available on this site (including Pro and third-party widgets).', 'parameters' => array()),
			array('name' => 'list_changes', 'write' => false, 'description' => 'List recent changes made by the AI agent (with IDs usable by undo_change).', 'parameters' => array()),

			// --- Changes ---------------------------------------------------
			array('name' => 'edit_file', 'write' => true, 'description' => 'Replace an exact piece of text in a file. old_string must match exactly once (include surrounding lines to make it unique). PHP files are syntax-checked; a change that breaks the site is reverted automatically. A backup is kept.', 'parameters' => $obj(array(
				'path'        => $s('File path relative to the WordPress root.'),
				'old_string'  => $s('Exact existing text to replace.'),
				'new_string'  => $s('Replacement text.'),
				'replace_all' => $b('Replace every occurrence instead of requiring a unique match.'),
			), array('path', 'old_string', 'new_string'))),
			array('name' => 'write_file', 'write' => true, 'description' => 'Create or overwrite a whole file. Prefer edit_file for small changes. PHP is syntax-checked and auto-reverted if it breaks the site. A backup is kept.', 'parameters' => $obj(array(
				'path'    => $s('File path relative to the WordPress root.'),
				'content' => $s('Full file content.'),
			), array('path', 'content'))),
			array('name' => 'delete_file', 'write' => true, 'description' => 'Delete a file (a backup is kept).', 'parameters' => $obj(array('path' => $s('File path relative to the WordPress root.')), array('path'))),
			array('name' => 'db_execute', 'write' => true, 'description' => 'Run one data-changing SQL statement (INSERT, UPDATE, DELETE, ALTER, CREATE, REPLACE, OPTIMIZE, REPAIR). Use {prefix} for the table prefix. Prefer the dedicated tools when one exists.', 'parameters' => $obj(array('sql' => $s('One SQL statement.')), array('sql'))),
			array('name' => 'update_option', 'write' => true, 'description' => 'Set a WordPress option. value is JSON for arrays/objects/numbers/booleans, otherwise plain text.', 'parameters' => $obj(array(
				'name'  => $s('Option name.'),
				'value' => $s('New value (JSON or plain text).'),
			), array('name', 'value'))),
			array('name' => 'manage_plugin', 'write' => true, 'description' => 'Install (from WordPress.org), activate, deactivate, update or delete a plugin. Activation is verified and rolled back if it breaks the site.', 'parameters' => $obj(array(
				'action' => $s('install, activate, deactivate, update or delete.', array('enum' => array('install', 'activate', 'deactivate', 'update', 'delete'))),
				'plugin' => $s('Plugin slug (e.g. "contact-form-7") or plugin file (e.g. "elementor/elementor.php").'),
			), array('action', 'plugin'))),
			array('name' => 'manage_theme', 'write' => true, 'description' => 'Install (from WordPress.org), activate or delete a theme. Activation is verified and rolled back if it breaks the site.', 'parameters' => $obj(array(
				'action' => $s('install, activate or delete.', array('enum' => array('install', 'activate', 'delete'))),
				'theme'  => $s('Theme slug, e.g. "hello-elementor".'),
			), array('action', 'theme'))),
			array('name' => 'save_post', 'write' => true, 'description' => 'Create a post/page (omit id) or update one (with id). Only given fields change.', 'parameters' => $obj(array(
				'id'        => $i('Post ID to update (omit to create).'),
				'title'     => $s('Title.'),
				'content'   => $s('Content (HTML/blocks).'),
				'status'    => $s('draft, publish, private or pending.'),
				'post_type' => $s('Post type for new posts (default "page").'),
				'slug'      => $s('URL slug.'),
				'template'  => $s('Page template file, e.g. "elementor_header_footer".'),
			))),
			array('name' => 'elementor_save_page', 'write' => true, 'description' => 'Create or update an Elementor page from an element tree. For large designs build the page in several calls using mode "append" (one or two sections per call). Returns the page URL and editor URL.', 'parameters' => $obj(array(
				'elements'      => $s('JSON array of Elementor elements (containers/sections with widgets) — see the Elementor guide in your instructions.'),
				'post_id'       => $i('Existing page ID to update. Omit to create a new page.'),
				'title'         => $s('Page title (required when creating).'),
				'mode'          => $s('replace (default), append, prepend, or replace_element.', array('enum' => array('replace', 'append', 'prepend', 'replace_element'))),
				'element_id'    => $s('For mode=replace_element: the id of the element to replace.'),
				'status'        => $s('draft (default) or publish.'),
				'template'      => $s('elementor_header_footer (full width with theme header/footer, default), elementor_canvas (blank, no header/footer) or default.'),
				'page_settings' => $s('Optional JSON object of Elementor page settings, e.g. {"background_background":"classic","background_color":"#fff"}.'),
				'set_as_homepage' => $b('Make this page the site front page.'),
			), array('elements'))),
			array('name' => 'elementor_update_kit', 'write' => true, 'description' => 'Update Elementor global kit settings (merged): e.g. {"system_colors":[{"_id":"primary","title":"Primary","color":"#1A73E8"}], "system_typography":[...], "container_width":{"unit":"px","size":1200}}.', 'parameters' => $obj(array(
				'settings' => $s('JSON object of kit settings to merge.'),
			), array('settings'))),
			array('name' => 'upload_media_from_url', 'write' => true, 'description' => 'Download an image from a URL into the Media Library; returns its attachment ID and URL for use in designs.', 'parameters' => $obj(array(
				'url'   => $s('Image URL.'),
				'title' => $s('Title / alt text.'),
			), array('url'))),
			array('name' => 'toggle_debug', 'write' => true, 'description' => 'Set WP_DEBUG, WP_DEBUG_LOG and WP_DEBUG_DISPLAY in wp-config.php (backup kept). Enable logging to capture errors; keep display off on live sites.', 'parameters' => $obj(array(
				'debug'   => $b('WP_DEBUG'),
				'log'     => $b('WP_DEBUG_LOG'),
				'display' => $b('WP_DEBUG_DISPLAY'),
			), array('debug'))),
			array('name' => 'flush_caches', 'write' => false, 'description' => 'Clear object cache, rewrite rules, Elementor CSS and common page-cache plugins.', 'parameters' => array()),
			array('name' => 'undo_change', 'write' => true, 'description' => 'Undo a change made earlier by the agent (see list_changes).', 'parameters' => $obj(array('change_id' => $s('Change ID.')), array('change_id'))),
		);
	}

	public function is_write(string $name): bool {
		foreach ($this->definitions() as $d) {
			if ($d['name'] === $name) {
				return (bool) $d['write'];
			}
		}
		return true;
	}

	public function exists(string $name): bool {
		foreach ($this->definitions() as $d) {
			if ($d['name'] === $name) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Short human description of a call, for the UI.
	 */
	public function describe(string $name, array $a): string {
		$p = (string) ($a['path'] ?? '');
		switch ($name) {
			case 'site_overview': return 'Inspect site setup';
			case 'read_error_log': return 'Read error logs';
			case 'check_site_health': return 'Load ' . ($a['path'] ?? '/') . ' and check for errors';
			case 'list_files': return 'List ' . $p;
			case 'read_file': return 'Read ' . $p;
			case 'search_files': return 'Search files for “' . mb_substr((string) ($a['query'] ?? ''), 0, 60) . '”';
			case 'db_query': return 'Query database';
			case 'get_option': return 'Read option ' . ($a['name'] ?? '');
			case 'list_posts': return 'List ' . ($a['post_type'] ?? 'page') . 's';
			case 'get_post': return 'Read post #' . ($a['id'] ?? '');
			case 'elementor_get_page': return 'Read Elementor page #' . ($a['post_id'] ?? '');
			case 'elementor_get_kit': return 'Read Elementor global styles';
			case 'elementor_list_widgets': return 'List Elementor widgets';
			case 'list_changes': return 'List previous changes';
			case 'edit_file': return 'Edit ' . $p;
			case 'write_file': return (file_exists($this->safe_resolve($p)) ? 'Overwrite ' : 'Create ') . $p;
			case 'delete_file': return 'Delete ' . $p;
			case 'db_execute': return 'Run SQL: ' . mb_substr((string) ($a['sql'] ?? ''), 0, 90);
			case 'update_option': return 'Update option ' . ($a['name'] ?? '');
			case 'manage_plugin': return ucfirst((string) ($a['action'] ?? '')) . ' plugin ' . ($a['plugin'] ?? '');
			case 'manage_theme': return ucfirst((string) ($a['action'] ?? '')) . ' theme ' . ($a['theme'] ?? '');
			case 'save_post': return empty($a['id']) ? 'Create ' . ($a['post_type'] ?? 'page') . ' “' . ($a['title'] ?? '') . '”' : 'Update post #' . $a['id'];
			case 'elementor_save_page':
				$mode = (string) ($a['mode'] ?? 'replace');
				return empty($a['post_id']) ? 'Create Elementor page “' . ($a['title'] ?? '') . '”' : ucfirst(str_replace('_', ' ', $mode)) . ' Elementor content on page #' . $a['post_id'];
			case 'elementor_update_kit': return 'Update Elementor global styles';
			case 'upload_media_from_url': return 'Import image into Media Library';
			case 'toggle_debug': return 'Set debug mode in wp-config.php';
			case 'flush_caches': return 'Clear caches';
			case 'undo_change': return 'Undo change ' . ($a['change_id'] ?? '');
		}
		return $name;
	}

	/* ---------------------------------------------------------------------
	 * Execution
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{content:string,error:bool,meta:array}
	 */
	public function execute(string $name, array $args): array {
		$method = 'tool_' . $name;
		if (! $this->exists($name) || ! method_exists($this, $method)) {
			return $this->result('Unknown tool: ' . $name, true);
		}
		try {
			$out = $this->$method($args);
			if (! is_array($out) || ! isset($out['content'])) {
				$out = $this->result($out);
			}
			return $out;
		} catch (\Throwable $e) {
			return $this->result('Error: ' . $e->getMessage(), true);
		}
	}

	private function result($data, bool $error = false, array $meta = array()): array {
		$text = is_string($data) ? $data : (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
		if (strlen($text) > self::MAX_RESULT) {
			$text = substr($text, 0, self::MAX_RESULT) . "\n…[truncated " . (strlen($text) - self::MAX_RESULT) . ' bytes]';
		}
		return array('content' => $text, 'error' => $error, 'meta' => $meta);
	}

	/* ------------------------------ Paths ------------------------------- */

	private function root(): string {
		return rtrim(wp_normalize_path(ABSPATH), '/');
	}

	/**
	 * Resolve a user/AI supplied path to an absolute path inside the WordPress install.
	 */
	private function resolve(string $path): string {
		$path = trim(wp_normalize_path(trim($path)));
		if ('' === $path || str_contains($path, "\0")) {
			throw new \RuntimeException('A path is required.');
		}
		$root = $this->root();
		$content = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/');
		$is_abs = ('/' === $path[0] || preg_match('#^[a-zA-Z]:/#', $path));
		$full = $is_abs ? $path : $root . '/' . ltrim($path, '/');

		// Collapse "." and ".." lexically.
		$prefix = '';
		if (preg_match('#^([a-zA-Z]:)(/.*)$#', $full, $m)) {
			$prefix = $m[1];
			$full = $m[2];
		}
		$parts = array();
		foreach (explode('/', $full) as $seg) {
			if ('' === $seg || '.' === $seg) {
				continue;
			}
			if ('..' === $seg) {
				array_pop($parts);
				continue;
			}
			$parts[] = $seg;
		}
		$full = $prefix . '/' . implode('/', $parts);

		foreach (array($root, $content) as $allowed) {
			if (0 === strcasecmp($full, $allowed) || 0 === stripos($full . '/', $allowed . '/')) {
				return $full;
			}
		}
		throw new \RuntimeException('Path is outside the WordPress installation: ' . $path);
	}

	private function safe_resolve(string $path): string {
		try {
			return $this->resolve($path);
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function rel(string $abs): string {
		$root = $this->root() . '/';
		return 0 === stripos($abs, $root) ? substr($abs, strlen($root)) : $abs;
	}

	/* ------------------------------ Read tools -------------------------- */

	private function tool_site_overview(array $a): array {
		global $wpdb;
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		$active = array();
		foreach ($this->real_active_plugins() as $file) {
			$active[] = array('file' => $file, 'name' => $all[$file]['Name'] ?? $file, 'version' => $all[$file]['Version'] ?? '');
		}
		$theme = wp_get_theme();
		$elementor = array('active' => defined('ELEMENTOR_VERSION'));
		if (defined('ELEMENTOR_VERSION')) {
			$elementor['version'] = ELEMENTOR_VERSION;
			$elementor['pro'] = defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : false;
			$elementor['containers_active'] = $this->elementor_containers_active();
			$elementor['kit_id'] = (int) get_option('elementor_active_kit');
		} elseif (isset($all['elementor/elementor.php'])) {
			$elementor['installed_inactive'] = true;
		}
		$paused = function_exists('wp_paused_plugins') ? array_keys((array) wp_paused_plugins()->get_all()) : array();

		return $this->result(array(
			'site'   => array(
				'name'        => get_bloginfo('name'),
				'home'        => home_url('/'),
				'admin'       => admin_url(),
				'wp_version'  => get_bloginfo('version'),
				'php_version' => PHP_VERSION,
				'db_version'  => $wpdb->db_version(),
				'multisite'   => is_multisite(),
				'permalinks'  => (string) get_option('permalink_structure'),
				'front_page'  => 'page' === get_option('show_on_front') ? (int) get_option('page_on_front') : 'latest posts',
				'language'    => get_locale(),
				'memory_limit'=> WP_MEMORY_LIMIT . ' (php ' . ini_get('memory_limit') . ')',
				'table_prefix'=> $wpdb->prefix,
			),
			'debug'  => array(
				'WP_DEBUG'         => defined('WP_DEBUG') && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false,
				'WP_DEBUG_DISPLAY' => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : true,
				'debug_log_file'   => $this->rel($this->debug_log_path()),
			),
			'theme'  => array(
				'name'       => $theme->get('Name'),
				'version'    => $theme->get('Version'),
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'dir'        => $this->rel(wp_normalize_path($theme->get_stylesheet_directory())),
			),
			'plugins' => array('active' => $active, 'inactive_count' => count($all) - count($active), 'paused_by_recovery_mode' => $paused),
			'elementor' => $elementor,
			'last_fatal_error' => get_option('wudt_last_fatal_error') ?: null,
			'wordpress_root' => $this->root(),
		));
	}

	private function debug_log_path(): string {
		if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && ! in_array(strtolower(WP_DEBUG_LOG), array('1', 'true', ''), true)) {
			return wp_normalize_path(WP_DEBUG_LOG);
		}
		return wp_normalize_path(WP_CONTENT_DIR) . '/debug.log';
	}

	private static function tail(string $file, int $lines): array {
		if (! is_file($file) || ! is_readable($file)) {
			return array();
		}
		$size = filesize($file);
		$fh = fopen($file, 'rb');
		$read = min($size, 512 * 1024);
		fseek($fh, $size - $read);
		$data = (string) fread($fh, $read);
		fclose($fh);
		$all = preg_split('/\r?\n/', rtrim($data));
		if ($read < $size) {
			array_shift($all);
		}
		return array_slice($all, -$lines);
	}

	private function tool_read_error_log(array $a): array {
		$lines = max(10, min(400, (int) ($a['lines'] ?? 80)));
		$filter = (string) ($a['contains'] ?? '');
		$out = array();
		$sources = array('debug.log' => $this->debug_log_path());
		$php_log = (string) ini_get('error_log');
		if ('' !== $php_log && is_file($php_log) && wp_normalize_path($php_log) !== $this->debug_log_path()) {
			$sources['php_error_log'] = $php_log;
		}
		foreach ($sources as $label => $file) {
			$tail = self::tail($file, '' === $filter ? $lines : 4000);
			if ('' !== $filter) {
				$tail = array_values(array_filter($tail, static function ($l) use ($filter) {
					return false !== stripos($l, $filter);
				}));
				$tail = array_slice($tail, -$lines);
			}
			$out[$label] = empty($tail) ? '(empty or not found: ' . $file . ')' : implode("\n", $tail);
		}
		$fatal = get_option('wudt_last_fatal_error');
		if ($fatal) {
			$out['last_fatal_error'] = $fatal;
		}
		if (! (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
			$out['note'] = 'WP_DEBUG_LOG is off, so new PHP errors are not being written to debug.log. Use toggle_debug to enable logging if you need more detail.';
		}
		return $this->result($out);
	}

	/**
	 * @return array{ok:?bool,status:int,summary:string,details:array}
	 */
	public function health_check(string $path = '/'): array {
		$path = '/' . ltrim($path, '/');
		$url = 0 === strpos($path, '/wp-admin') ? site_url($path) : home_url($path);
		$log = $this->debug_log_path();
		$log_size = is_file($log) ? (int) filesize($log) : 0;

		$cookies = array();
		foreach ($_COOKIE as $name => $value) {
			// Never pass the safe-mode cookie: the check must see the real site.
			if (is_string($value) && 'wudt_isolate' !== $name) {
				$cookies[] = new \WP_Http_Cookie(array('name' => $name, 'value' => $value));
			}
		}
		$started = microtime(true);
		$response = wp_remote_get(add_query_arg('wudt_health', (string) time(), $url), array(
			'timeout'     => 45,
			'redirection' => 3,
			// Request to this same site, like WordPress's own loopback checks.
			'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'cookies'     => $cookies,
			'headers'     => array('Cache-Control' => 'no-cache'),
		));
		$elapsed = round(microtime(true) - $started, 2);

		$new_log = array();
		clearstatcache(true, $log);
		if (is_file($log) && filesize($log) > $log_size) {
			$fh = fopen($log, 'rb');
			fseek($fh, $log_size);
			$new_log = array_slice(preg_split('/\r?\n/', trim((string) fread($fh, 65536))) ?: array(), -30);
			fclose($fh);
		}

		if (is_wp_error($response)) {
			return array('ok' => null, 'status' => 0, 'summary' => 'Could not load ' . $url . ': ' . $response->get_error_message(), 'details' => array('new_log_lines' => $new_log));
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$problems = array();
		if (preg_match_all('#<b>(Fatal error|Parse error|Warning|Deprecated|Notice)</b>:\s*(.{0,400}?)<br#is', $body, $m, PREG_SET_ORDER)) {
			foreach (array_slice($m, 0, 10) as $hit) {
				$problems[] = $hit[1] . ': ' . wp_strip_all_tags($hit[2]);
			}
		}
		$critical = false !== stripos($body, 'There has been a critical error on this website') || false !== stripos($body, 'Fatal error');
		$fatal_in_log = (bool) preg_grep('/PHP (Fatal|Parse) error/i', $new_log);
		$ok = $code < 500 && ! $critical && ! $fatal_in_log;
		$title = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $t) ? trim(wp_strip_all_tags(html_entity_decode($t[1]))) : '';

		return array(
			'ok'      => $ok,
			'status'  => $code,
			'summary' => sprintf('%s → HTTP %d in %ss%s', $url, $code, $elapsed, $ok ? ' — OK' : ' — BROKEN (fatal/critical error)'),
			'details' => array(
				'title'         => $title,
				'page_errors'   => $problems,
				'new_log_lines' => $new_log,
			),
		);
	}

	private function tool_check_site_health(array $a): array {
		$res = $this->health_check((string) ($a['path'] ?? '/'));
		return $this->result($res, false);
	}

	private function tool_list_files(array $a): array {
		$dir = $this->resolve((string) ($a['path'] ?? ''));
		if (! is_dir($dir)) {
			return $this->result('Not a directory: ' . $this->rel($dir), true);
		}
		$depth = max(1, min(3, (int) ($a['depth'] ?? 1)));
		$out = array();
		$walk = function (string $d, int $level) use (&$walk, &$out, $depth) {
			$items = @scandir($d) ?: array();
			natcasesort($items);
			foreach ($items as $item) {
				if ('.' === $item || '..' === $item || count($out) >= 500) {
					continue;
				}
				$p = $d . '/' . $item;
				$indent = str_repeat('  ', $level - 1);
				if (is_dir($p)) {
					$out[] = $indent . $item . '/';
					if ($level < $depth && ! in_array($item, array('node_modules', '.git', 'vendor'), true)) {
						$walk($p, $level + 1);
					}
				} else {
					$out[] = $indent . $item . ' (' . size_format((int) @filesize($p)) . ')';
				}
			}
		};
		$walk($dir, 1);
		return $this->result($this->rel($dir) . "/\n" . implode("\n", $out) . (count($out) >= 500 ? "\n…(truncated)" : ''));
	}

	private function tool_read_file(array $a): array {
		$file = $this->resolve((string) ($a['path'] ?? ''));
		if (! is_file($file)) {
			return $this->result('File not found: ' . $this->rel($file), true);
		}
		if (filesize($file) > 3 * MB_IN_BYTES) {
			return $this->result('File is larger than 3 MB; use search_files or read a line range.', true);
		}
		$content = (string) file_get_contents($file);
		if (str_contains(substr($content, 0, 8000), "\0")) {
			return $this->result('This is a binary file (' . size_format(strlen($content)) . ').', true);
		}
		$lines = preg_split('/\r\n|\n/', $content);
		$total = count($lines);
		$start = max(1, (int) ($a['start_line'] ?? 1));
		$end = (int) ($a['end_line'] ?? 0);
		if ($end <= 0) {
			$end = $start + 1499;
		}
		$end = min($total, $end);
		$slice = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
		$header = sprintf("%s — lines %d-%d of %d\n", $this->rel($file), $start, $end, $total);
		if ($end < $total) {
			$header .= "(more lines remain; call again with start_line=" . ($end + 1) . ")\n";
		}
		return $this->result($header . "-----\n" . $slice);
	}

	private function tool_search_files(array $a): array {
		$query = (string) ($a['query'] ?? '');
		if ('' === $query) {
			return $this->result('query is required', true);
		}
		$dir = $this->resolve((string) ($a['path'] ?? 'wp-content'));
		$pattern = (string) ($a['file_pattern'] ?? '');
		$regex = ! empty($a['regex']);
		if ($regex && false === @preg_match('~' . str_replace('~', '\~', $query) . '~', '')) {
			return $this->result('Invalid regular expression.', true);
		}
		$started = microtime(true);
		$matches = array();
		$files = 0;
		$text_ext = '/\.(php|js|jsx|ts|css|scss|html?|txt|json|xml|md|twig|ini|htaccess|log|po|pot|svg|sql|yml|yaml)$/i';
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				static function ($f) {
					return ! ($f->isDir() && in_array($f->getFilename(), array('.git', 'node_modules', 'wudt-ai-backups', 'wudt-migrations', 'wudt-backups', 'cache'), true));
				}
			),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ($iter as $f) {
			if (count($matches) >= 100 || (microtime(true) - $started) > 20) {
				break;
			}
			$name = $f->getFilename();
			if ('' !== $pattern ? ! fnmatch($pattern, $name) : ! preg_match($text_ext, $name)) {
				continue;
			}
			if ($f->getSize() > 2 * MB_IN_BYTES) {
				continue;
			}
			$files++;
			$content = @file_get_contents($f->getPathname());
			if (false === $content || ($regex ? ! preg_match('~' . str_replace('~', '\~', $query) . '~', $content) : false === stripos($content, $query))) {
				continue;
			}
			foreach (preg_split('/\r?\n/', $content) as $n => $line) {
				$hit = $regex ? preg_match('~' . str_replace('~', '\~', $query) . '~', $line) : false !== stripos($line, $query);
				if ($hit) {
					$matches[] = $this->rel(wp_normalize_path($f->getPathname())) . ':' . ($n + 1) . ': ' . mb_substr(trim($line), 0, 220);
					if (count($matches) >= 100) {
						break;
					}
				}
			}
		}
		if (empty($matches)) {
			return $this->result(sprintf('No matches in %d files.', $files));
		}
		return $this->result(implode("\n", $matches) . (count($matches) >= 100 ? "\n…(first 100 matches)" : ''));
	}

	private function prepare_sql(string $sql): string {
		global $wpdb;
		$sql = trim(str_replace('{prefix}', $wpdb->prefix, $sql));
		$sql = rtrim($sql, "; \t\n\r");
		$stripped = preg_replace(array("/'(?:[^'\\\\]|\\\\.)*'/s", '/"(?:[^"\\\\]|\\\\.)*"/s', '/`[^`]*`/'), "''", $sql);
		if (false !== strpos((string) $stripped, ';')) {
			throw new \RuntimeException('Only one SQL statement per call is allowed.');
		}
		return $sql;
	}

	private function tool_db_query(array $a): array {
		global $wpdb;
		$sql = $this->prepare_sql((string) ($a['sql'] ?? ''));
		if (! preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|CHECKSUM)\b/i', $sql) || preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE)\b/i', $sql)) {
			return $this->result('db_query only runs read-only statements. Use db_execute for changes.', true);
		}
		$rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		if ($wpdb->last_error) {
			return $this->result('SQL error: ' . $wpdb->last_error, true);
		}
		$rows = (array) $rows;
		$count = count($rows);
		$rows = array_slice($rows, 0, 100);
		foreach ($rows as &$row) {
			foreach ($row as $k => $v) {
				if (is_string($v) && strlen($v) > 600) {
					$row[$k] = substr($v, 0, 600) . '…[' . strlen($v) . ' bytes]';
				}
			}
		}
		return $this->result(array('row_count' => $count, 'rows' => $rows));
	}

	private function tool_get_option(array $a): array {
		$name = (string) ($a['name'] ?? '');
		$value = get_option($name, '__wudt_missing__');
		if ('__wudt_missing__' === $value) {
			return $this->result('Option "' . $name . '" does not exist.');
		}
		return $this->result(array('name' => $name, 'value' => $value));
	}

	private function tool_list_posts(array $a): array {
		$type = sanitize_key((string) ($a['post_type'] ?? 'page')) ?: 'page';
		$query = array(
			'post_type'      => 'any' === $type ? 'any' : $type,
			'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
			'posts_per_page' => max(1, min(100, (int) ($a['limit'] ?? 30))),
			'orderby'        => 'modified',
			's'              => (string) ($a['search'] ?? ''),
		);
		$out = array();
		foreach (get_posts($query) as $p) {
			$out[] = array(
				'id'        => $p->ID,
				'title'     => $p->post_title,
				'type'      => $p->post_type,
				'status'    => $p->post_status,
				'url'       => get_permalink($p),
				'template'  => get_post_meta($p->ID, '_wp_page_template', true),
				'elementor' => 'builder' === get_post_meta($p->ID, '_elementor_edit_mode', true),
				'modified'  => $p->post_modified,
			);
		}
		$front = (int) get_option('page_on_front');
		return $this->result(array('front_page_id' => $front, 'posts' => $out));
	}

	private function tool_get_post(array $a): array {
		$post = get_post((int) ($a['id'] ?? 0));
		if (! $post) {
			return $this->result('Post not found.', true);
		}
		$meta = array();
		foreach ((array) get_post_meta($post->ID) as $k => $v) {
			$val = (string) ($v[0] ?? '');
			$meta[$k] = strlen($val) > 300 ? substr($val, 0, 300) . '…[' . strlen($val) . ' bytes]' : $val;
		}
		return $this->result(array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'url'       => get_permalink($post),
			'content'   => mb_substr($post->post_content, 0, 20000),
			'excerpt'   => $post->post_excerpt,
			'elementor' => 'builder' === get_post_meta($post->ID, '_elementor_edit_mode', true),
			'meta'      => $meta,
		));
	}

	private function tool_list_changes(array $a): array {
		$out = array();
		foreach (array_slice(AI_Changes::all(), 0, 40) as $c) {
			$out[] = array('id' => $c['id'], 'time' => gmdate('Y-m-d H:i', (int) $c['time']), 'description' => $c['description'], 'undone' => $c['undone']);
		}
		return $this->result(empty($out) ? 'No changes recorded yet.' : $out);
	}

	/* ------------------------------ Files ------------------------------- */

	/**
	 * Check PHP syntax without executing it.
	 */
	public static function php_syntax_error(string $code): ?string {
		try {
			token_get_all($code, TOKEN_PARSE);
			return null;
		} catch (\ParseError $e) {
			return $e->getMessage() . ' on line ' . $e->getLine();
		}
	}

	private function is_protected_path(string $abs): bool {
		$self = wp_normalize_path(dirname(WUDT_PLUGIN_FILE));
		return 0 === stripos($abs . '/', rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/wudt-ai-backups/')
			|| 0 === stripos($abs . '/', rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/wudt-migrations/')
			|| 0 === stripos($abs . '/', $self . '/');
	}

	/**
	 * Write a file with backup, PHP lint and automatic revert when the site breaks.
	 */
	private function write_guarded(string $abs, string $content, string $description): array {
		if ($this->is_protected_path($abs)) {
			return $this->result('This path is managed by Diagnostics Toolkit and cannot be changed by the agent.', true);
		}
		$is_php = (bool) preg_match('/\.(php|phtml|inc)$/i', $abs);
		if ($is_php) {
			$err = self::php_syntax_error($content);
			if (null !== $err) {
				return $this->result('Not saved — the new PHP code has a syntax error: ' . $err . '. Fix the code and try again.', true);
			}
		}
		$existed = file_exists($abs);
		$old = $existed ? (string) file_get_contents($abs) : null;
		if ($existed && ! is_writable($abs)) {
			return $this->result('File is not writable: ' . $this->rel($abs), true);
		}
		if (! $existed && ! wp_mkdir_p(dirname($abs))) {
			return $this->result('Could not create the folder for ' . $this->rel($abs), true);
		}
		if (false === file_put_contents($abs, $content, LOCK_EX)) {
			return $this->result('Could not write ' . $this->rel($abs), true);
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($abs, true);
		}
		$change_id = AI_Changes::record('file', $this->rel($abs), $description, array('path' => $abs, 'created' => ! $existed), $old, $this->conversation);

		$note = '';
		if ($is_php && $this->affects_runtime($abs)) {
			$health = $this->health_check('/');
			if (false === $health['ok']) {
				// Revert and see whether the change was the cause.
				if ($existed) {
					file_put_contents($abs, (string) $old, LOCK_EX);
				} else {
					@unlink($abs);
				}
				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($abs, true);
				}
				$after = $this->health_check('/');
				if (false !== $after['ok']) {
					AI_Changes::undo_silently($change_id);
					return $this->result(array(
						'saved'   => false,
						'message' => 'The change broke the site, so it was reverted automatically. Nothing was changed.',
						'error'   => $health,
					), true);
				}
				// The site was already broken before this change: keep the change.
				file_put_contents($abs, $content, LOCK_EX);
				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($abs, true);
				}
				$note = 'The site still shows an error (it was already broken before this change): ' . $health['summary'] . ' ' . wp_json_encode($health['details']);
			} elseif (true === $health['ok']) {
				$note = 'Site check after the change: ' . $health['summary'];
			} else {
				$note = 'Could not verify the site automatically: ' . $health['summary'];
			}
		}
		return $this->result(
			trim(sprintf('Saved %s (%s). Change ID %s. %s', $this->rel($abs), size_format(strlen($content)), $change_id, $note)),
			false,
			array('change_id' => $change_id)
		);
	}

	private function affects_runtime(string $abs): bool {
		$content = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/');
		$root = $this->root();
		if (0 === stripos($abs, $content . '/uploads/')) {
			return false;
		}
		return 0 === stripos($abs, $content . '/') || 0 === stripos($abs, $root . '/wp-config.php') || 0 === stripos($abs, $root . '/wp-includes/') || 0 === stripos($abs, $root . '/wp-admin/');
	}

	private function tool_write_file(array $a): array {
		$abs = $this->resolve((string) ($a['path'] ?? ''));
		return $this->write_guarded($abs, (string) ($a['content'] ?? ''), (file_exists($abs) ? 'Overwrote ' : 'Created ') . $this->rel($abs));
	}

	private function tool_edit_file(array $a): array {
		$abs = $this->resolve((string) ($a['path'] ?? ''));
		if (! is_file($abs)) {
			return $this->result('File not found: ' . $this->rel($abs), true);
		}
		$old = (string) ($a['old_string'] ?? '');
		$new = (string) ($a['new_string'] ?? '');
		if ('' === $old) {
			return $this->result('old_string must not be empty.', true);
		}
		$content = (string) file_get_contents($abs);
		$count = substr_count($content, $old);
		if (0 === $count) {
			// Tolerate line-ending differences.
			$alt = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $old));
			if ($alt !== $old && substr_count($content, $alt) > 0) {
				$old = $alt;
				$new = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $new));
				$count = substr_count($content, $old);
			}
		}
		if (0 === $count) {
			return $this->result('old_string was not found in ' . $this->rel($abs) . '. Read the file again and copy the text exactly.', true);
		}
		if ($count > 1 && empty($a['replace_all'])) {
			return $this->result('old_string matches ' . $count . ' places. Include more surrounding text to make it unique, or set replace_all.', true);
		}
		$updated = empty($a['replace_all']) ? (string) preg_replace('/' . preg_quote($old, '/') . '/', str_replace(array('\\', '$'), array('\\\\', '\\$'), $new), $content, 1) : str_replace($old, $new, $content);
		return $this->write_guarded($abs, $updated, 'Edited ' . $this->rel($abs));
	}

	private function tool_delete_file(array $a): array {
		$abs = $this->resolve((string) ($a['path'] ?? ''));
		if (! is_file($abs)) {
			return $this->result('File not found: ' . $this->rel($abs), true);
		}
		if ($this->is_protected_path($abs) || preg_match('#/wp-config\.php$#', $abs)) {
			return $this->result('This file cannot be deleted by the agent.', true);
		}
		$change = AI_Changes::record('file', $this->rel($abs), 'Deleted ' . $this->rel($abs), array('path' => $abs, 'created' => false), (string) file_get_contents($abs), $this->conversation);
		if (! @unlink($abs)) {
			return $this->result('Could not delete ' . $this->rel($abs), true);
		}
		return $this->result('Deleted ' . $this->rel($abs) . ' (change ' . $change . ').', false, array('change_id' => $change));
	}

	/* ------------------------------ Database ---------------------------- */

	private function tool_db_execute(array $a): array {
		global $wpdb;
		$sql = $this->prepare_sql((string) ($a['sql'] ?? ''));
		if (preg_match('/^\s*(DROP\s+DATABASE|GRANT|REVOKE|CREATE\s+USER|DROP\s+USER|SET\s+PASSWORD|LOAD\s+DATA)\b/i', $sql) || preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
			return $this->result('This statement is not allowed.', true);
		}
		$affected = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL
		if ($wpdb->last_error) {
			return $this->result('SQL error: ' . $wpdb->last_error, true);
		}
		wp_cache_flush();
		AI_Changes::record('sql', mb_substr($sql, 0, 120), 'SQL: ' . mb_substr($sql, 0, 160), array(), null, $this->conversation);
		return $this->result('OK. Rows affected: ' . (int) $affected);
	}

	private function tool_update_option(array $a): array {
		$name = (string) ($a['name'] ?? '');
		if ('' === $name) {
			return $this->result('name is required', true);
		}
		if (0 === strpos($name, 'wudt_')) {
			return $this->result('Diagnostics Toolkit settings cannot be changed by the agent.', true);
		}
		$raw = (string) ($a['value'] ?? '');
		$decoded = json_decode($raw, true);
		$value = (JSON_ERROR_NONE === json_last_error() && (is_array($decoded) || is_bool($decoded) || is_numeric($raw) || 'null' === $raw)) ? $decoded : $raw;
		$old = get_option($name, '__wudt_missing__');
		$missing = '__wudt_missing__' === $old;
		$change = AI_Changes::record('option', $name, 'Updated option ' . $name, array('name' => $name, 'missing' => $missing), $missing ? null : maybe_serialize($old), $this->conversation);
		update_option($name, $value);
		return $this->result('Option ' . $name . ' updated (change ' . $change . ').', false, array('change_id' => $change));
	}

	/* ------------------------------ Plugins & themes -------------------- */

	private function find_plugin_file(string $plugin): string {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache(false);
		$all = get_plugins();
		if (isset($all[$plugin])) {
			return $plugin;
		}
		$slug = strtok(trim($plugin, '/'), '/');
		foreach (array_keys($all) as $file) {
			if (strtok($file, '/') === $slug || basename($file, '.php') === $slug) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Active plugins as stored in the database (unaffected by safe mode filters).
	 */
	private function real_active_plugins(): array {
		global $wpdb;
		$value = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins')));
		return is_array($value) ? array_values($value) : array();
	}

	private function store_active_plugins(array $plugins): void {
		global $wpdb;
		$plugins = array_values(array_unique($plugins));
		sort($plugins);
		$wpdb->update($wpdb->options, array('option_value' => serialize($plugins)), array('option_name' => 'active_plugins'));
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('active_plugins', 'options');
	}

	private function load_upgrader(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if (! WP_Filesystem()) {
			throw new \RuntimeException('WordPress cannot write files directly on this server (FTP credentials required).');
		}
	}

	private function tool_manage_plugin(array $a): array {
		$action = (string) ($a['action'] ?? '');
		$plugin = trim((string) ($a['plugin'] ?? ''));
		if ('' === $plugin) {
			return $this->result('plugin is required', true);
		}
		$this->load_upgrader();
		$file = $this->find_plugin_file($plugin);

		switch ($action) {
			case 'install':
				if ('' !== $file) {
					return $this->result('Plugin is already installed as ' . $file . '. Use activate.');
				}
				$slug = sanitize_key(strtok($plugin, '/'));
				$api = plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => false)));
				if (is_wp_error($api)) {
					return $this->result('Not found on WordPress.org: ' . $api->get_error_message(), true);
				}
				$upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
				$res = $upgrader->install($api->download_link);
				if (is_wp_error($res) || ! $res) {
					return $this->result('Install failed: ' . (is_wp_error($res) ? $res->get_error_message() : implode(' ', (array) $upgrader->skin->get_upgrade_messages())), true);
				}
				$file = $this->find_plugin_file($slug);
				AI_Changes::record('plugin_install', $file, 'Installed plugin ' . $api->name, array(), null, $this->conversation);
				return $this->result('Installed ' . $api->name . ' ' . $api->version . ' (' . $file . '). It is not active yet.');

			case 'activate':
				if ('' === $file) {
					return $this->result('Plugin not installed: ' . $plugin . '. Install it first.', true);
				}
				if (is_plugin_active($file)) {
					return $this->result($file . ' is already active.');
				}
				$res = activate_plugin($file);
				if (is_wp_error($res)) {
					return $this->result('Activation failed: ' . $res->get_error_message(), true);
				}
				$health = $this->health_check('/');
				if (false === $health['ok']) {
					deactivate_plugins($file, true);
					return $this->result(array('activated' => false, 'message' => 'Activating ' . $file . ' broke the site, so it was deactivated again.', 'error' => $health), true);
				}
				$change = AI_Changes::record('plugin', $file, 'Activated plugin ' . $file, array('plugin' => $file, 'revert' => 'deactivate'), null, $this->conversation);
				return $this->result('Activated ' . $file . '. ' . $health['summary'], false, array('change_id' => $change));

			case 'deactivate':
				if ('' === $file || ! in_array($file, $this->real_active_plugins(), true)) {
					return $this->result('Plugin is not active: ' . $plugin);
				}
				if (plugin_basename(WUDT_PLUGIN_FILE) === $file) {
					return $this->result('Diagnostics Toolkit cannot deactivate itself.', true);
				}
				if (defined('WUDT_SAFE_MODE') && WUDT_SAFE_MODE) {
					// In safe mode the active list is filtered; edit the stored list directly.
					$this->store_active_plugins(array_diff($this->real_active_plugins(), array($file)));
				} else {
					deactivate_plugins($file);
				}
				$change = AI_Changes::record('plugin', $file, 'Deactivated plugin ' . $file, array('plugin' => $file, 'revert' => 'activate'), null, $this->conversation);
				return $this->result('Deactivated ' . $file . '.', false, array('change_id' => $change));

			case 'update':
				if ('' === $file) {
					return $this->result('Plugin not installed: ' . $plugin, true);
				}
				wp_update_plugins();
				$upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
				$res = $upgrader->upgrade($file);
				if (is_wp_error($res) || false === $res) {
					return $this->result('Update failed: ' . (is_wp_error($res) ? $res->get_error_message() : implode(' ', (array) $upgrader->skin->get_upgrade_messages())), true);
				}
				AI_Changes::record('plugin_update', $file, 'Updated plugin ' . $file, array(), null, $this->conversation);
				return $this->result(null === $res ? 'No update available for ' . $file . '.' : 'Updated ' . $file . '.');

			case 'delete':
				if ('' === $file) {
					return $this->result('Plugin not installed: ' . $plugin, true);
				}
				if (is_plugin_active($file)) {
					return $this->result('Deactivate the plugin before deleting it.', true);
				}
				$res = delete_plugins(array($file));
				if (is_wp_error($res) || ! $res) {
					return $this->result('Delete failed' . (is_wp_error($res) ? ': ' . $res->get_error_message() : '.'), true);
				}
				AI_Changes::record('plugin_delete', $file, 'Deleted plugin ' . $file, array(), null, $this->conversation);
				return $this->result('Deleted ' . $file . '.');
		}
		return $this->result('Unknown action: ' . $action, true);
	}

	private function tool_manage_theme(array $a): array {
		$action = (string) ($a['action'] ?? '');
		$slug = sanitize_key((string) ($a['theme'] ?? ''));
		if ('' === $slug) {
			return $this->result('theme is required', true);
		}
		$this->load_upgrader();
		$theme = wp_get_theme($slug);

		switch ($action) {
			case 'install':
				if ($theme->exists()) {
					return $this->result('Theme already installed. Use activate.');
				}
				$api = themes_api('theme_information', array('slug' => $slug, 'fields' => array('sections' => false)));
				if (is_wp_error($api)) {
					return $this->result('Not found on WordPress.org: ' . $api->get_error_message(), true);
				}
				$upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
				$res = $upgrader->install($api->download_link);
				if (is_wp_error($res) || ! $res) {
					return $this->result('Install failed: ' . (is_wp_error($res) ? $res->get_error_message() : 'unknown error'), true);
				}
				AI_Changes::record('theme_install', $slug, 'Installed theme ' . $slug, array(), null, $this->conversation);
				return $this->result('Installed theme ' . $api->name . ' ' . $api->version . '. It is not active yet.');

			case 'activate':
				if (! $theme->exists()) {
					return $this->result('Theme not installed: ' . $slug, true);
				}
				$previous = get_stylesheet();
				switch_theme($slug);
				$health = $this->health_check('/');
				if (false === $health['ok']) {
					switch_theme($previous);
					return $this->result(array('activated' => false, 'message' => 'Activating the theme broke the site, so the previous theme was restored.', 'error' => $health), true);
				}
				$change = AI_Changes::record('theme', $slug, 'Activated theme ' . $slug, array('stylesheet' => $previous), null, $this->conversation);
				return $this->result('Activated theme ' . $slug . '. ' . $health['summary'], false, array('change_id' => $change));

			case 'delete':
				if (get_stylesheet() === $slug || get_template() === $slug) {
					return $this->result('The active theme (or its parent) cannot be deleted.', true);
				}
				$res = delete_theme($slug);
				if (is_wp_error($res) || ! $res) {
					return $this->result('Delete failed.', true);
				}
				return $this->result('Deleted theme ' . $slug . '.');
		}
		return $this->result('Unknown action: ' . $action, true);
	}

	/* ------------------------------ Posts ------------------------------- */

	private function tool_save_post(array $a): array {
		$id = (int) ($a['id'] ?? 0);
		$data = array();
		foreach (array('title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'slug' => 'post_name') as $in => $field) {
			if (isset($a[$in])) {
				$data[$field] = (string) $a[$in];
			}
		}
		if ($id) {
			$post = get_post($id);
			if (! $post) {
				return $this->result('Post not found: ' . $id, true);
			}
			$old = array('ID' => $id, 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_status' => $post->post_status, 'post_name' => $post->post_name);
			$data['ID'] = $id;
			$res = wp_update_post(wp_slash($data), true);
			if (is_wp_error($res)) {
				return $this->result($res->get_error_message(), true);
			}
			$change = AI_Changes::record('post', (string) $id, 'Updated post #' . $id, array('id' => $id), (string) wp_json_encode($old), $this->conversation);
		} else {
			$data['post_type'] = sanitize_key((string) ($a['post_type'] ?? 'page')) ?: 'page';
			$data['post_status'] = $data['post_status'] ?? 'draft';
			$data['post_title'] = $data['post_title'] ?? 'Untitled';
			$res = wp_insert_post(wp_slash($data), true);
			if (is_wp_error($res)) {
				return $this->result($res->get_error_message(), true);
			}
			$id = (int) $res;
			$change = AI_Changes::record('post', (string) $id, 'Created ' . $data['post_type'] . ' “' . $data['post_title'] . '”', array('id' => $id, 'created' => true), null, $this->conversation);
		}
		if (isset($a['template'])) {
			update_post_meta($id, '_wp_page_template', sanitize_text_field((string) $a['template']));
		}
		return $this->result(array('id' => $id, 'url' => get_permalink($id), 'change_id' => $change), false, array('change_id' => $change, 'links' => array(array('label' => 'View', 'url' => get_permalink($id)), array('label' => 'Edit', 'url' => get_edit_post_link($id, 'raw')))));
	}

	/* ------------------------------ Elementor --------------------------- */

	private function require_elementor(): void {
		if (! defined('ELEMENTOR_VERSION') || ! class_exists('\Elementor\Plugin')) {
			throw new \RuntimeException('Elementor is not active. Install/activate it with manage_plugin (slug "elementor") first.');
		}
	}

	private function elementor_containers_active(): bool {
		try {
			if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->experiments)) {
				return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active('container');
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}

	private function elementor_data(int $post_id): array {
		$raw = get_post_meta($post_id, '_elementor_data', true);
		if (is_array($raw)) {
			return $raw;
		}
		$data = json_decode((string) $raw, true);
		return is_array($data) ? $data : array();
	}

	private function tool_elementor_get_page(array $a): array {
		$this->require_elementor();
		$post_id = (int) ($a['post_id'] ?? 0);
		$post = get_post($post_id);
		if (! $post) {
			return $this->result('Page not found.', true);
		}
		$data = $this->elementor_data($post_id);
		if (! empty($a['element_id'])) {
			$el = $this->find_element($data, (string) $a['element_id']);
			return null === $el ? $this->result('Element not found.', true) : $this->result($el);
		}
		$json = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$info = array(
			'post_id'       => $post_id,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'url'           => get_permalink($post_id),
			'template'      => get_post_meta($post_id, '_wp_page_template', true),
			'built_with_elementor' => 'builder' === get_post_meta($post_id, '_elementor_edit_mode', true),
			'page_settings' => get_post_meta($post_id, '_elementor_page_settings', true) ?: new \stdClass(),
		);
		if (strlen($json) > 30000) {
			$info['outline'] = $this->outline($data);
			$info['note'] = 'The page is large; this is an outline. Use element_id to fetch a specific element.';
		} else {
			$info['elements'] = $data;
		}
		return $this->result($info);
	}

	private function outline(array $elements, int $depth = 0): array {
		$out = array();
		foreach ($elements as $el) {
			$item = array('id' => $el['id'] ?? '', 'type' => ($el['elType'] ?? '') . ('widget' === ($el['elType'] ?? '') ? ':' . ($el['widgetType'] ?? '') : ''));
			$settings = (array) ($el['settings'] ?? array());
			foreach (array('title', 'editor', 'text', 'heading') as $k) {
				if (! empty($settings[$k]) && is_string($settings[$k])) {
					$item['text'] = mb_substr(wp_strip_all_tags($settings[$k]), 0, 80);
					break;
				}
			}
			if (! empty($el['elements']) && $depth < 6) {
				$item['children'] = $this->outline((array) $el['elements'], $depth + 1);
			}
			$out[] = $item;
		}
		return $out;
	}

	private function find_element(array $elements, string $id): ?array {
		foreach ($elements as $el) {
			if (($el['id'] ?? '') === $id) {
				return $el;
			}
			if (! empty($el['elements'])) {
				$found = $this->find_element((array) $el['elements'], $id);
				if (null !== $found) {
					return $found;
				}
			}
		}
		return null;
	}

	private function replace_element(array $elements, string $id, array $replacement, bool &$done): array {
		$out = array();
		foreach ($elements as $el) {
			if (! $done && ($el['id'] ?? '') === $id) {
				foreach ($replacement as $r) {
					$out[] = $r;
				}
				$done = true;
				continue;
			}
			if (! empty($el['elements'])) {
				$el['elements'] = $this->replace_element((array) $el['elements'], $id, $replacement, $done);
			}
			$out[] = $el;
		}
		return $out;
	}

	private function decode_json_arg($value, string $name) {
		if (is_array($value)) {
			return $value;
		}
		$value = trim((string) $value);
		// Models sometimes wrap JSON in a code fence.
		$value = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $value);
		$decoded = json_decode($value, true);
		if (JSON_ERROR_NONE !== json_last_error()) {
			throw new \RuntimeException($name . ' is not valid JSON: ' . json_last_error_msg() . '. Send smaller pieces if the JSON was cut off.');
		}
		return $decoded;
	}

	/**
	 * Make model-generated elements valid for Elementor.
	 */
	public function normalize_elements(array $elements, bool $containers, array &$ids, bool $inner = false, string $parent = ''): array {
		if (isset($elements['elType']) || isset($elements['widgetType'])) {
			$elements = array($elements);
		} elseif (isset($elements['elements']) && ! isset($elements[0])) {
			$elements = (array) $elements['elements'];
		}
		$out = array();
		foreach ($elements as $el) {
			if (! is_array($el)) {
				continue;
			}
			$type = (string) ($el['elType'] ?? (isset($el['widgetType']) ? 'widget' : 'container'));
			$el['elType'] = $type;
			$id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($el['id'] ?? '')));
			if ('' === $id || isset($ids[$id])) {
				do {
					$id = substr(md5(uniqid('', true) . wp_rand()), 0, 7);
				} while (isset($ids[$id]));
			}
			$ids[$id] = true;
			$el['id'] = $id;
			$el['settings'] = is_array($el['settings'] ?? null) ? $el['settings'] : array();
			$el['elements'] = is_array($el['elements'] ?? null) ? $el['elements'] : array();

			if ('widget' === $type) {
				if (empty($el['widgetType'])) {
					throw new \RuntimeException('A widget element is missing "widgetType".');
				}
				$el['elements'] = array();
				if ('section' === $parent || '' === $parent) {
					// Widgets need a container (or a section/column) around them.
					$wrap = $containers
						? array('elType' => 'container', 'settings' => array(), 'elements' => array($el))
						: array('elType' => 'section', 'settings' => array(), 'elements' => array(array('elType' => 'column', 'settings' => array('_column_size' => 100), 'elements' => array($el))));
					$out = array_merge($out, $this->normalize_elements(array($wrap), $containers, $ids, $inner, $parent));
					continue;
				}
				$out[] = $el;
				continue;
			}

			if ('container' === $type && ! $containers) {
				// Convert a flexbox container into a section with one column.
				$children = $el['elements'];
				$section = array(
					'elType'   => 'section',
					'isInner'  => $inner,
					'settings' => $el['settings'],
					'elements' => array(array('elType' => 'column', 'settings' => array('_column_size' => 100), 'elements' => $children)),
				);
				$out = array_merge($out, $this->normalize_elements(array($section), $containers, $ids, $inner, $parent));
				unset($ids[$id]);
				continue;
			}

			if ('section' === $type) {
				$el['isInner'] = $inner || ! empty($el['isInner']);
				$cols = array();
				foreach ($el['elements'] as $child) {
					$cols[] = (is_array($child) && 'column' === ($child['elType'] ?? '')) ? $child : array('elType' => 'column', 'settings' => array(), 'elements' => array($child));
				}
				if (empty($cols)) {
					$cols[] = array('elType' => 'column', 'settings' => array(), 'elements' => array());
				}
				$size = round(100 / count($cols), 3);
				foreach ($cols as &$col) {
					$col['settings'] = is_array($col['settings'] ?? null) ? $col['settings'] : array();
					if (empty($col['settings']['_column_size'])) {
						$col['settings']['_column_size'] = $size;
					}
				}
				unset($col);
				$el['elements'] = $this->normalize_elements($cols, $containers, $ids, true, 'section');
			} elseif ('column' === $type) {
				$children = array();
				foreach ($el['elements'] as $child) {
					if (is_array($child) && 'container' === ($child['elType'] ?? '') && ! $containers) {
						$child['elType'] = 'section';
					}
					if (is_array($child) && 'section' === ($child['elType'] ?? '') && $inner && 'column' === $parent) {
						// Elementor does not allow inner sections inside inner sections: flatten.
						foreach ((array) ($child['elements'] ?? array()) as $col) {
							foreach ((array) ($col['elements'] ?? array()) as $w) {
								$children[] = $w;
							}
						}
						continue;
					}
					$children[] = $child;
				}
				$el['elements'] = $this->normalize_elements($children, $containers, $ids, true, 'column');
			} else {
				$el['isInner'] = $inner;
				$el['elements'] = $this->normalize_elements($el['elements'], $containers, $ids, true, 'container');
			}
			$out[] = $el;
		}
		return $out;
	}

	private function collect_ids(array $elements, array &$ids): void {
		foreach ($elements as $el) {
			if (! empty($el['id'])) {
				$ids[(string) $el['id']] = true;
			}
			if (! empty($el['elements'])) {
				$this->collect_ids((array) $el['elements'], $ids);
			}
		}
	}

	private function tool_elementor_save_page(array $a): array {
		$this->require_elementor();
		$elements = $this->decode_json_arg($a['elements'] ?? '', 'elements');
		if (! is_array($elements)) {
			return $this->result('elements must be a JSON array.', true);
		}
		$mode = (string) ($a['mode'] ?? 'replace');
		$post_id = (int) ($a['post_id'] ?? 0);
		$containers = $this->elementor_containers_active();
		$template = (string) ($a['template'] ?? '');
		$page_settings = isset($a['page_settings']) && '' !== $a['page_settings'] ? $this->decode_json_arg($a['page_settings'], 'page_settings') : null;

		$created = false;
		if ($post_id) {
			$post = get_post($post_id);
			if (! $post) {
				return $this->result('Page not found: ' . $post_id, true);
			}
		} else {
			$title = trim((string) ($a['title'] ?? ''));
			if ('' === $title) {
				return $this->result('title is required when creating a page.', true);
			}
			$post_id = wp_insert_post(array(
				'post_title'  => $title,
				'post_type'   => 'page',
				'post_status' => in_array($a['status'] ?? '', array('publish', 'private'), true) ? $a['status'] : 'draft',
			), true);
			if (is_wp_error($post_id)) {
				return $this->result($post_id->get_error_message(), true);
			}
			$created = true;
			$mode = 'replace';
			if ('' === $template) {
				$template = 'elementor_header_footer';
			}
		}

		$existing = $this->elementor_data($post_id);
		$ids = array();
		if ('replace' !== $mode) {
			$this->collect_ids($existing, $ids);
		}
		$new = $this->normalize_elements($elements, $containers, $ids);
		if (empty($new)) {
			return $this->result('No valid elements were provided.', true);
		}

		switch ($mode) {
			case 'append':
				$data = array_merge($existing, $new);
				break;
			case 'prepend':
				$data = array_merge($new, $existing);
				break;
			case 'replace_element':
				$target = (string) ($a['element_id'] ?? '');
				unset($ids[$target]);
				$done = false;
				$data = $this->replace_element($existing, $target, $new, $done);
				if (! $done) {
					return $this->result('element_id "' . $target . '" was not found on the page.', true);
				}
				break;
			default:
				$data = $new;
		}

		$backup = array(
			'data'     => (string) get_post_meta($post_id, '_elementor_data', true),
			'settings' => get_post_meta($post_id, '_elementor_page_settings', true),
			'template' => (string) get_post_meta($post_id, '_wp_page_template', true),
		);

		$saved_with = 'meta';
		if ('' === (string) get_post_meta($post_id, '_elementor_template_type', true)) {
			update_post_meta($post_id, '_elementor_template_type', 'page' === get_post_type($post_id) ? 'wp-page' : 'wp-post');
		}
		try {
			$document = \Elementor\Plugin::$instance->documents->get($post_id, false);
			if ($document) {
				$save = array('elements' => $data);
				if (is_array($page_settings)) {
					$save['settings'] = array_merge((array) get_post_meta($post_id, '_elementor_page_settings', true), $page_settings);
				}
				if ('' !== $template) {
					$save['settings'] = array_merge($save['settings'] ?? (array) get_post_meta($post_id, '_elementor_page_settings', true), array('template' => $template));
				}
				$document->set_is_built_with_elementor(true);
				if ($document->save($save)) {
					$saved_with = 'elementor';
				}
			}
		} catch (\Throwable $e) {
			$saved_with = 'meta';
		}
		if ('elementor' !== $saved_with) {
			update_post_meta($post_id, '_elementor_edit_mode', 'builder');
			update_post_meta($post_id, '_elementor_template_type', 'wp-page');
			update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
			update_post_meta($post_id, '_elementor_data', wp_slash((string) wp_json_encode($data)));
			if (is_array($page_settings)) {
				update_post_meta($post_id, '_elementor_page_settings', array_merge((array) get_post_meta($post_id, '_elementor_page_settings', true), $page_settings));
			}
			delete_post_meta($post_id, '_elementor_css');
		}
		if ('' !== $template) {
			update_post_meta($post_id, '_wp_page_template', $template);
		}
		if (! empty($a['status']) && ! $created && in_array($a['status'], array('publish', 'draft', 'private'), true)) {
			wp_update_post(array('ID' => $post_id, 'post_status' => $a['status']));
		}
		if (! empty($a['set_as_homepage'])) {
			update_option('show_on_front', 'page');
			update_option('page_on_front', $post_id);
			if ('publish' !== get_post_status($post_id)) {
				wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
			}
		}
		$this->clear_elementor_cache();

		$change = AI_Changes::record(
			'elementor',
			(string) $post_id,
			($created ? 'Created Elementor page “' : 'Updated Elementor page “') . get_the_title($post_id) . '”',
			array('id' => $post_id, 'created' => $created),
			$created ? null : (string) wp_json_encode($backup),
			$this->conversation
		);
		$count = 0;
		array_walk_recursive($data, static function ($v, $k) use (&$count) {
			if ('elType' === $k) {
				$count++;
			}
		});
		$view = get_permalink($post_id);
		$edit = admin_url('post.php?post=' . $post_id . '&action=elementor');
		return $this->result(
			array(
				'post_id'   => $post_id,
				'status'    => get_post_status($post_id),
				'elements'  => $count,
				'layout'    => $containers ? 'flexbox containers' : 'sections/columns',
				'view_url'  => $view,
				'edit_url'  => $edit,
				'change_id' => $change,
			),
			false,
			array('change_id' => $change, 'links' => array(array('label' => 'View page', 'url' => $view), array('label' => 'Edit in Elementor', 'url' => $edit)))
		);
	}

	private function clear_elementor_cache(): void {
		try {
			if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch (\Throwable $e) {
			delete_post_meta_by_key('_elementor_css');
		}
	}

	private function kit_id(): int {
		$kit = (int) get_option('elementor_active_kit');
		if (! $kit) {
			throw new \RuntimeException('No active Elementor kit found.');
		}
		return $kit;
	}

	private function tool_elementor_get_kit(array $a): array {
		$this->require_elementor();
		$kit = $this->kit_id();
		$settings = (array) get_post_meta($kit, '_elementor_page_settings', true);
		$keep = array();
		foreach ($settings as $k => $v) {
			if (preg_match('/^(system_|custom_|container_|body_|link_|h[1-6]_|button_|site_|space_between|default_|viewport_|page_title|stretched)/', (string) $k)) {
				$keep[$k] = $v;
			}
		}
		return $this->result(array('kit_id' => $kit, 'settings' => $keep));
	}

	private function tool_elementor_update_kit(array $a): array {
		$this->require_elementor();
		$kit = $this->kit_id();
		$new = $this->decode_json_arg($a['settings'] ?? '', 'settings');
		if (! is_array($new)) {
			return $this->result('settings must be a JSON object.', true);
		}
		$old = (array) get_post_meta($kit, '_elementor_page_settings', true);
		$merged = array_merge($old, $new);
		try {
			$doc = \Elementor\Plugin::$instance->documents->get($kit, false);
			if ($doc) {
				$doc->save(array('settings' => $merged));
			} else {
				update_post_meta($kit, '_elementor_page_settings', $merged);
			}
		} catch (\Throwable $e) {
			update_post_meta($kit, '_elementor_page_settings', $merged);
		}
		$this->clear_elementor_cache();
		$change = AI_Changes::record('kit', (string) $kit, 'Updated Elementor global styles', array('id' => $kit), (string) wp_json_encode($old), $this->conversation);
		return $this->result('Kit updated (' . implode(', ', array_keys($new)) . '). Change ' . $change . '.', false, array('change_id' => $change));
	}

	private function tool_elementor_list_widgets(array $a): array {
		$this->require_elementor();
		$out = array();
		foreach (\Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $name => $widget) {
			if (0 === strpos((string) $name, 'wp-widget-')) {
				continue;
			}
			$out[] = $name;
		}
		sort($out);
		return $this->result(array('containers_active' => $this->elementor_containers_active(), 'widgets' => $out));
	}

	/* ------------------------------ Misc -------------------------------- */

	private function tool_upload_media_from_url(array $a): array {
		$url = esc_url_raw((string) ($a['url'] ?? ''));
		if ('' === $url) {
			return $this->result('url is required', true);
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$title = sanitize_text_field((string) ($a['title'] ?? ''));
		$tmp = download_url($url, 60);
		if (is_wp_error($tmp)) {
			return $this->result('Download failed: ' . $tmp->get_error_message(), true);
		}
		$name = basename((string) wp_parse_url($url, PHP_URL_PATH));
		if (! preg_match('/\.(jpe?g|png|gif|webp|svg|avif)$/i', $name)) {
			$type = function_exists('mime_content_type') ? (string) @mime_content_type($tmp) : '';
			$ext = array('image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif')[$type] ?? 'jpg';
			$name = sanitize_title($title ?: 'image') . '.' . $ext;
		}
		$id = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), 0, $title);
		if (is_wp_error($id)) {
			@unlink($tmp);
			return $this->result('Import failed: ' . $id->get_error_message(), true);
		}
		if ('' !== $title) {
			update_post_meta($id, '_wp_attachment_image_alt', $title);
		}
		AI_Changes::record('media', (string) $id, 'Imported image “' . ($title ?: $name) . '”', array(), null, $this->conversation);
		return $this->result(array('id' => $id, 'url' => wp_get_attachment_url($id)));
	}

	private function tool_toggle_debug(array $a): array {
		$config = $this->root() . '/wp-config.php';
		if (! file_exists($config)) {
			$config = dirname($this->root()) . '/wp-config.php';
		}
		if (! is_file($config) || ! is_writable($config)) {
			return $this->result('wp-config.php is not writable.', true);
		}
		$content = (string) file_get_contents($config);
		$values = array(
			'WP_DEBUG'         => ! empty($a['debug']),
			'WP_DEBUG_LOG'     => array_key_exists('log', $a) ? ! empty($a['log']) : ! empty($a['debug']),
			'WP_DEBUG_DISPLAY' => ! empty($a['display']),
		);
		foreach ($values as $const => $on) {
			$literal = $on ? 'true' : 'false';
			$pattern = '/define\s*\(\s*[\'"]' . $const . '[\'"]\s*,\s*[^)]*\)\s*;/i';
			if (preg_match($pattern, $content)) {
				$content = (string) preg_replace($pattern, "define( '" . $const . "', " . $literal . ' );', $content, 1);
			} else {
				$line = "define( '" . $const . "', " . $literal . " );\n";
				if (preg_match('/\/\*\s*That\'s all, stop editing/i', $content)) {
					$content = (string) preg_replace('/(\/\*\s*That\'s all, stop editing)/i', $line . '$1', $content, 1);
				} elseif (false !== strpos($content, "require_once ABSPATH . 'wp-settings.php'")) {
					$content = str_replace("require_once ABSPATH . 'wp-settings.php'", $line . "require_once ABSPATH . 'wp-settings.php'", $content);
				} else {
					return $this->result('Could not find where to add ' . $const . ' in wp-config.php.', true);
				}
			}
		}
		return $this->write_guarded(wp_normalize_path($config), $content, sprintf('Debug settings: WP_DEBUG=%s, LOG=%s, DISPLAY=%s', $values['WP_DEBUG'] ? 'on' : 'off', $values['WP_DEBUG_LOG'] ? 'on' : 'off', $values['WP_DEBUG_DISPLAY'] ? 'on' : 'off'));
	}

	private function tool_flush_caches(array $a): array {
		$done = array();
		wp_cache_flush();
		$done[] = 'object cache';
		flush_rewrite_rules(false);
		$done[] = 'rewrite rules';
		if (class_exists('\Elementor\Plugin')) {
			$this->clear_elementor_cache();
			$done[] = 'Elementor CSS';
		}
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}
		if (has_action('litespeed_purge_all')) {
			do_action('litespeed_purge_all');
			$done[] = 'LiteSpeed Cache';
		}
		do_action('wudt_flush_caches');
		return $this->result('Cleared: ' . implode(', ', $done) . '.');
	}

	private function tool_undo_change(array $a): array {
		return $this->result(AI_Changes::undo((string) ($a['change_id'] ?? '')));
	}
}
