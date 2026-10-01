<?php
/**
 * WP Diagnostics — Rescue Console.
 *
 * A standalone page that works even when a plugin or theme causes a fatal error
 * ("There has been a critical error on this website"). WordPress is loaded with
 * SHORTINIT, so no plugins, theme or admin code run. Access requires the secret
 * rescue key shown in WP Diagnostics → Pro → Recovery (and in WordPress's
 * recovery-mode email).
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

define('WUDT_RESCUE', true);
define('SHORTINIT', true);

/**
 * Find wp-load.php by walking up from the requested script (works with symlinked plugin folders).
 */
function wudt_rescue_find_wp_load(): string {
	$starts = array();
	if (! empty($_SERVER['SCRIPT_FILENAME'])) {
		$starts[] = dirname((string) $_SERVER['SCRIPT_FILENAME']);
	}
	$starts[] = __DIR__;
	foreach ($starts as $dir) {
		for ($i = 0; $i < 8; $i++) {
			if (is_file($dir . '/wp-load.php')) {
				return $dir . '/wp-load.php';
			}
			$parent = dirname($dir);
			if ($parent === $dir) {
				break;
			}
			$dir = $parent;
		}
	}
	header('Content-Type: text/plain; charset=utf-8', true, 500);
	exit('WP Diagnostics rescue: could not find wp-load.php.');
}

require wudt_rescue_find_wp_load();

/**
 * HTML escaping that does not depend on kses (not loaded in SHORTINIT mode).
 */
function wudt_rescue_e($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
require_once __DIR__ . '/includes/class-ai-config.php';

nocache_headers();
header('X-Robots-Tag: noindex, nofollow', true);
header('X-Frame-Options: SAMEORIGIN', true);
header('Content-Type: text/html; charset=utf-8', true);

final class WUDT_Rescue {
	private string $key = '';
	private array $flash = array();
	private string $self_basename = '';

	public function __construct() {
		$file = WP_CONTENT_DIR . '/wudt-rescue/key.php';
		if (is_file($file)) {
			$this->key = (string) include $file;
		}
		if (strlen($this->key) < 32) {
			$this->key = (string) get_option('wudt_rescue_key', '');
		}
		$this->self_basename = basename(__DIR__) . '/wp-ultimate-diagnostics.php';
		foreach ((array) get_option('active_plugins', array()) as $p) {
			if ('wp-ultimate-diagnostics.php' === basename((string) $p)) {
				$this->self_basename = (string) $p;
			}
		}
	}

	/* --------------------------------------------------------------- */

	public function run(): void {
		if (strlen($this->key) < 32) {
			$this->page('Rescue console not set up', '<p>Open <strong>WP Diagnostics</strong> in wp-admin once to create the rescue key, then use the rescue link shown on the Recovery tab.</p>');
			return;
		}

		if (isset($_GET['logout'])) {
			$this->set_cookie('wudt_rescue_auth', '', time() - 3600);
			$this->set_cookie('wudt_isolate', '', time() - 3600);
			$this->redirect($this->url());
		}

		if (isset($_GET['key'])) {
			$this->login((string) $_GET['key']);
		}

		if (! $this->authed()) {
			$this->login_form();
			return;
		}

		if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['do'])) {
			if (! hash_equals($this->csrf(), (string) ($_POST['csrf'] ?? ''))) {
				$this->flash('error', 'Security token expired. Please try again.');
			} else {
				$this->handle((string) $_POST['do']);
			}
			$this->save_flash();
			$this->redirect($this->url());
		}

		$this->dashboard();
	}

	/* --------------------------------------------------------------- */
	/* Auth                                                            */

	private function login(string $key): void {
		$ip = substr(preg_replace('/[^0-9a-f.:]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
		$fails = (array) get_option('wudt_rescue_fails', array());
		$entry = $fails[$ip] ?? array(0, 0);
		if ($entry[0] >= 10 && (time() - $entry[1]) < 900) {
			$this->page('Locked', '<p>Too many wrong keys. Try again in 15 minutes.</p>');
			exit;
		}
		if (hash_equals($this->key, trim($key))) {
			unset($fails[$ip]);
			update_option('wudt_rescue_fails', $fails, false);
			$exp = time() + 12 * HOUR_IN_SECONDS;
			$this->set_cookie('wudt_rescue_auth', $exp . '.' . hash_hmac('sha256', 'auth|' . $exp, $this->key), $exp);
			$this->redirect($this->url());
		}
		$fails[$ip] = array($entry[0] + 1, time());
		update_option('wudt_rescue_fails', array_slice($fails, -200, null, true), false);
		$this->flash('error', 'That rescue key is not correct.');
	}

	private function valid_cookie(string $name, string $scope): bool {
		$value = (string) ($_COOKIE[$name] ?? '');
		$parts = explode('.', $value, 2);
		if (2 !== count($parts) || (int) $parts[0] < time()) {
			return false;
		}
		return hash_equals(hash_hmac('sha256', $scope . '|' . $parts[0], $this->key), $parts[1]);
	}

	private function authed(): bool {
		return $this->valid_cookie('wudt_rescue_auth', 'auth');
	}

	private function csrf(): string {
		return substr(hash_hmac('sha256', 'csrf|' . (string) ($_COOKIE['wudt_rescue_auth'] ?? ''), $this->key), 0, 32);
	}

	private function set_cookie(string $name, string $value, int $expires): void {
		setcookie($name, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		));
		$_COOKIE[$name] = $value;
	}

	/* --------------------------------------------------------------- */
	/* Actions                                                         */

	private function handle(string $do): void {
		try {
			switch ($do) {
				case 'deactivate':
					$this->set_active(array_diff($this->active_plugins(), array((string) $_POST['plugin'])));
					$this->flash('success', 'Deactivated ' . (string) $_POST['plugin'] . '.');
					break;
				case 'activate':
					$plugin = (string) $_POST['plugin'];
					if (! isset($this->plugins()[$plugin])) {
						throw new RuntimeException('Unknown plugin.');
					}
					$this->set_active(array_merge($this->active_plugins(), array($plugin)));
					$this->flash('success', 'Activated ' . $plugin . '. Check that the site loads; deactivate it again if not.');
					break;
				case 'deactivate_all':
					update_option('wudt_rescue_prev_plugins', $this->active_plugins(), false);
					$this->set_active(array($this->self_basename));
					$this->flash('success', 'All plugins except WP Diagnostics were deactivated. Use “Restore previous plugins” to bring them back.');
					break;
				case 'restore_plugins':
					$prev = (array) get_option('wudt_rescue_prev_plugins', array());
					if (empty($prev)) {
						throw new RuntimeException('There is no saved plugin list.');
					}
					$this->set_active($prev);
					$this->flash('success', 'Previous plugin list restored.');
					break;
				case 'theme':
					$this->switch_theme((string) $_POST['theme']);
					break;
				case 'debug':
					$this->write_debug(! empty($_POST['debug']), ! empty($_POST['log']), ! empty($_POST['display']));
					break;
				case 'safe_mode':
					$exp = time() + 6 * HOUR_IN_SECONDS;
					$this->set_cookie('wudt_isolate', $exp . '.' . hash_hmac('sha256', 'isolate|' . $exp, $this->key), $exp);
					if (! is_file((defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins') . '/wudt-safe-loader.php')) {
						throw new RuntimeException('The safe loader is not installed (wp-content/mu-plugins is not writable). Use the tools on this page instead.');
					}
					$this->redirect(rtrim((string) get_option('siteurl'), '/') . '/wp-admin/admin.php?page=wudt-diagnostics-pro#ai_assistant');
					break;
				case 'exit_safe':
					$this->set_cookie('wudt_isolate', '', time() - 3600);
					$this->flash('success', 'Safe mode ended for this browser.');
					break;
				case 'ai_fix':
					$this->ai_fix();
					break;
				case 'apply_fix':
					$this->apply_fix();
					break;
				case 'discard_fix':
					delete_option('wudt_rescue_proposal');
					break;
				case 'undo':
					$this->undo_change((string) $_POST['change']);
					break;
				case 'clear_fatal':
					delete_option('wudt_last_fatal_error');
					$this->flash('success', 'Cleared the stored fatal error.');
					break;
			}
		} catch (Throwable $e) {
			$this->flash('error', $e->getMessage());
		}
	}

	/* --------------------------------------------------------------- */
	/* Plugins & themes                                                */

	private function active_plugins(): array {
		global $wpdb;
		$value = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins')));
		return is_array($value) ? array_values($value) : array();
	}

	private function set_active(array $plugins): void {
		$plugins = array_values(array_unique(array_filter(array_map('strval', $plugins))));
		if (! in_array($this->self_basename, $plugins, true)) {
			$plugins[] = $this->self_basename;
		}
		sort($plugins);
		update_option('active_plugins', $plugins);
	}

	private function plugins(): array {
		$dir = WP_CONTENT_DIR . '/plugins';
		$out = array();
		$headers = array('Name' => 'Plugin Name', 'Version' => 'Version');
		foreach ((array) glob($dir . '/*.php') as $file) {
			$data = get_file_data((string) $file, $headers);
			if (! empty($data['Name'])) {
				$out[basename((string) $file)] = $data;
			}
		}
		foreach ((array) glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
			foreach ((array) glob($folder . '/*.php') as $file) {
				$data = get_file_data((string) $file, $headers);
				if (! empty($data['Name'])) {
					$out[basename((string) $folder) . '/' . basename((string) $file)] = $data;
					break;
				}
			}
		}
		ksort($out);
		return $out;
	}

	private function themes(): array {
		$out = array();
		foreach ((array) glob(WP_CONTENT_DIR . '/themes/*/style.css') as $css) {
			$data = get_file_data((string) $css, array('Name' => 'Theme Name', 'Template' => 'Template', 'Version' => 'Version'));
			if (! empty($data['Name'])) {
				$out[basename(dirname((string) $css))] = $data;
			}
		}
		ksort($out);
		return $out;
	}

	private function switch_theme(string $slug): void {
		$themes = $this->themes();
		if (! isset($themes[$slug])) {
			throw new RuntimeException('Theme not found.');
		}
		$template = '' !== $themes[$slug]['Template'] ? $themes[$slug]['Template'] : $slug;
		if (! isset($themes[$template])) {
			throw new RuntimeException('The parent theme “' . $template . '” is missing.');
		}
		update_option('wudt_rescue_prev_theme', array('template' => get_option('template'), 'stylesheet' => get_option('stylesheet')), false);
		update_option('template', $template);
		update_option('stylesheet', $slug);
		update_option('current_theme', $themes[$slug]['Name']);
		$this->flash('success', 'Switched the theme to ' . $themes[$slug]['Name'] . '.');
	}

	/* --------------------------------------------------------------- */
	/* Logs & debug                                                    */

	private function debug_log_path(): string {
		if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && ! in_array(strtolower(WP_DEBUG_LOG), array('1', 'true', ''), true)) {
			return WP_DEBUG_LOG;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	private function tail(string $file, int $lines = 80): array {
		if (! is_file($file) || ! is_readable($file)) {
			return array();
		}
		$size = (int) filesize($file);
		$fh = fopen($file, 'rb');
		$read = min($size, 256 * 1024);
		fseek($fh, $size - $read);
		$data = (string) fread($fh, $read);
		fclose($fh);
		$all = preg_split('/\r?\n/', rtrim($data)) ?: array();
		if ($read < $size) {
			array_shift($all);
		}
		return array_slice($all, -$lines);
	}

	/**
	 * Most recent fatal error: from the plugin's capture or the logs.
	 */
	private function last_fatal(): ?array {
		$stored = get_option('wudt_last_fatal_error');
		$from_log = null;
		$sources = array($this->debug_log_path());
		$ini = (string) ini_get('error_log');
		if ('' !== $ini) {
			$sources[] = $ini;
		}
		foreach ($sources as $file) {
			foreach (array_reverse($this->tail($file, 400)) as $line) {
				if (preg_match('/PHP (Fatal|Parse) error:\s*(.*?) in (.+?)(?: on line |:)(\d+)/', $line, $m)) {
					$from_log = array('message' => trim($m[2]), 'file' => trim($m[3]), 'line' => (int) $m[4], 'raw' => $line);
					break 2;
				}
			}
		}
		if (is_array($stored) && ! empty($stored['message'])) {
			$msg = (string) $stored['message'];
			$file = (string) ($stored['file'] ?? '');
			$line = (int) ($stored['line'] ?? 0);
			// "Uncaught Error: ... in /path:12" — the real location is inside the message.
			if (preg_match('/ in (.+?):(\d+)(?:\s|$)/', $msg, $m)) {
				$file = $m[1];
				$line = (int) $m[2];
			}
			return array('message' => $msg, 'file' => $file, 'line' => $line, 'time' => (string) ($stored['time'] ?? ''));
		}
		return $from_log;
	}

	private function culprit(string $file): array {
		$file = wp_normalize_path($file);
		$content = wp_normalize_path(WP_CONTENT_DIR);
		if (preg_match('#' . preg_quote($content, '#') . '/plugins/([^/]+)#', $file, $m)) {
			foreach (array_keys($this->plugins()) as $p) {
				if (strtok($p, '/') === $m[1] || $p === $m[1]) {
					return array('type' => 'plugin', 'id' => $p);
				}
			}
		}
		if (preg_match('#' . preg_quote($content, '#') . '/themes/([^/]+)#', $file, $m)) {
			return array('type' => 'theme', 'id' => $m[1]);
		}
		return array('type' => 'other', 'id' => '');
	}

	private function config_path(): string {
		return is_file(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
	}

	private function write_debug(bool $debug, bool $log, bool $display): void {
		$path = $this->config_path();
		if (! is_writable($path)) {
			throw new RuntimeException('wp-config.php is not writable.');
		}
		$content = (string) file_get_contents($path);
		$original = $content;
		foreach (array('WP_DEBUG' => $debug, 'WP_DEBUG_LOG' => $log, 'WP_DEBUG_DISPLAY' => $display) as $const => $on) {
			$literal = $on ? 'true' : 'false';
			$pattern = '/define\s*\(\s*[\'"]' . $const . '[\'"]\s*,\s*[^)]*\)\s*;/i';
			if (preg_match($pattern, $content)) {
				$content = (string) preg_replace($pattern, "define( '" . $const . "', " . $literal . ' );', $content, 1);
			} elseif (preg_match('/\/\*\s*That\'s all, stop editing/i', $content)) {
				$content = (string) preg_replace('/(\/\*\s*That\'s all, stop editing)/i', "define( '" . $const . "', " . $literal . " );\n$1", $content, 1);
			} else {
				$content = str_replace("require_once ABSPATH . 'wp-settings.php'", "define( '" . $const . "', " . $literal . " );\nrequire_once ABSPATH . 'wp-settings.php'", $content);
			}
		}
		$this->lint($content);
		$this->backup_and_write($path, $content, $original, 'Debug settings changed from the rescue console');
		$this->flash('success', sprintf('Saved: WP_DEBUG %s, logging %s, display %s.', $debug ? 'on' : 'off', $log ? 'on' : 'off', $display ? 'on' : 'off'));
	}

	private function lint(string $code): void {
		try {
			token_get_all($code, TOKEN_PARSE);
		} catch (ParseError $e) {
			throw new RuntimeException('Refused: the result would contain a PHP syntax error (' . $e->getMessage() . ' on line ' . $e->getLine() . ').');
		}
	}

	/**
	 * Write a file and record the change in the AI agent's change journal (so it can be undone).
	 */
	private function backup_and_write(string $path, string $content, string $original, string $description): string {
		$dir = WP_CONTENT_DIR . '/wudt-ai-backups';
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
			@file_put_contents($dir . '/index.php', "<?php\n");
		}
		$id = 'c' . gmdate('ymdHis') . strtolower(substr(bin2hex(random_bytes(4)), 0, 5));
		file_put_contents($dir . '/' . $id . '.bak', $original);
		if (false === file_put_contents($path, $content, LOCK_EX)) {
			throw new RuntimeException('Could not write ' . $path);
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($path, true);
		}
		$list = (array) get_option('wudt_ai_changes', array());
		array_unshift($list, array(
			'id'           => $id,
			'time'         => time(),
			'type'         => 'file',
			'target'       => $this->rel($path),
			'description'  => $description,
			'undo'         => array('path' => wp_normalize_path($path), 'created' => false, 'payload' => $id . '.bak'),
			'conversation' => 'rescue',
			'undone'       => false,
		));
		update_option('wudt_ai_changes', array_slice($list, 0, 150), false);
		return $id;
	}

	private function undo_change(string $id): void {
		$list = (array) get_option('wudt_ai_changes', array());
		foreach ($list as &$c) {
			if ($c['id'] !== $id) {
				continue;
			}
			if ('file' !== $c['type'] || ! empty($c['undone'])) {
				throw new RuntimeException('Only file changes can be undone from the rescue console.');
			}
			$path = (string) $c['undo']['path'];
			if (! empty($c['undo']['created'])) {
				@unlink($path);
			} else {
				$backup = WP_CONTENT_DIR . '/wudt-ai-backups/' . basename((string) ($c['undo']['payload'] ?? ''));
				if (! is_file($backup)) {
					throw new RuntimeException('Backup file is missing.');
				}
				copy($backup, $path);
				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($path, true);
				}
			}
			$c['undone'] = true;
			update_option('wudt_ai_changes', $list, false);
			$this->flash('success', 'Undid: ' . $c['description']);
			return;
		}
		throw new RuntimeException('Change not found.');
	}

	private function rel(string $path): string {
		$path = wp_normalize_path($path);
		$root = wp_normalize_path(ABSPATH);
		return 0 === stripos($path, $root) ? substr($path, strlen($root)) : $path;
	}

	private function abs_path(string $rel): string {
		$rel = wp_normalize_path(trim($rel));
		$root = rtrim(wp_normalize_path(ABSPATH), '/');
		$full = (0 === stripos($rel, $root . '/')) ? $rel : $root . '/' . ltrim($rel, '/');
		if (preg_match('#(^|/)\.\.(/|$)#', $full) || ! is_file($full)) {
			throw new RuntimeException('File not found: ' . $rel);
		}
		return $full;
	}

	/* --------------------------------------------------------------- */
	/* AI fix                                                          */

	private function ai_fix(): void {
		$fatal = $this->last_fatal();
		if (! $fatal) {
			throw new RuntimeException('No fatal error found to fix.');
		}
		$provider = \WUDT\Includes\AI_Config::active_provider();
		$key = \WUDT\Includes\AI_Config::get_key($provider);
		if ('' === $key) {
			throw new RuntimeException('No AI API key is configured. Add a Gemini or Claude key in WP Diagnostics settings.');
		}
		$model = \WUDT\Includes\AI_Config::get_model($provider);

		$code = '';
		if (! empty($fatal['file']) && is_file($fatal['file']) && filesize($fatal['file']) < 2 * MB_IN_BYTES) {
			$lines = preg_split('/\r?\n/', (string) file_get_contents($fatal['file'])) ?: array();
			$start = max(0, $fatal['line'] - 60);
			$slice = array_slice($lines, $start, 120);
			foreach ($slice as $i => $l) {
				$code .= str_pad((string) ($start + $i + 1), 5, ' ', STR_PAD_LEFT) . ' | ' . $l . "\n";
			}
		}
		$culprit = $this->culprit((string) $fatal['file']);
		$log = implode("\n", array_slice($this->tail($this->debug_log_path(), 40), -40));

		$system = 'You are a senior WordPress/PHP engineer fixing a fatal error on a live site. Respond with ONE JSON object only, no prose outside JSON.';
		$prompt = "A WordPress site (WP " . (string) $GLOBALS['wp_version'] . ", PHP " . PHP_VERSION . ") is down with this fatal error:\n" . $fatal['message']
			. "\nFile: " . $this->rel((string) $fatal['file']) . ' line ' . $fatal['line']
			. "\nCulprit: " . $culprit['type'] . ' ' . $culprit['id']
			. "\n\nCode around the error (line numbers are NOT part of the file):\n" . $code
			. "\n\nRecent debug.log:\n" . $log
			. "\n\nReturn JSON with keys: explanation (short, plain language), fix. fix is one of:\n"
			. "{\"type\":\"edit\",\"file\":\"<path relative to WordPress root>\",\"old\":\"<exact text copied from the file, without line numbers, unique>\",\"new\":\"<replacement>\"}\n"
			. "{\"type\":\"deactivate_plugin\",\"plugin\":\"<folder/file.php>\"} (when a third-party plugin cannot be fixed safely)\n"
			. "{\"type\":\"switch_theme\"} (when the theme is broken)\n{\"type\":\"none\"}\nPrefer the smallest safe code edit.";

		$text = $this->ai_request($provider, $model, $key, $system, $prompt);
		$json = null;
		if (preg_match('/\{.*\}/s', $text, $m)) {
			$json = json_decode($m[0], true);
		}
		if (! is_array($json) || empty($json['fix']['type'])) {
			throw new RuntimeException('The AI response could not be understood: ' . mb_substr($text, 0, 300));
		}
		$json['provider'] = $provider . ' / ' . $model;
		$json['fatal'] = $fatal;
		update_option('wudt_rescue_proposal', $json, false);
		$this->flash('info', 'The AI proposed a fix — review it below.');
	}

	private function apply_fix(): void {
		$p = get_option('wudt_rescue_proposal');
		if (! is_array($p) || empty($p['fix']['type'])) {
			throw new RuntimeException('No proposal to apply.');
		}
		$fix = $p['fix'];
		switch ($fix['type']) {
			case 'edit':
				$path = $this->abs_path((string) $fix['file']);
				$content = (string) file_get_contents($path);
				$old = (string) $fix['old'];
				if ('' === $old || 1 !== substr_count($content, $old)) {
					$alt = str_replace("\n", "\r\n", $old);
					if (1 === substr_count($content, $alt)) {
						$old = $alt;
						$fix['new'] = str_replace("\n", "\r\n", (string) $fix['new']);
					} else {
						throw new RuntimeException('The text to replace was not found exactly once in the file. Ask the AI again or fix it manually.');
					}
				}
				$updated = str_replace($old, (string) $fix['new'], $content);
				if (preg_match('/\.php$/i', $path)) {
					$this->lint($updated);
				}
				$id = $this->backup_and_write($path, $updated, $content, 'AI fix (rescue console): ' . $this->rel($path));
				$this->flash('success', 'Fix applied to ' . $this->rel($path) . ' (undo ID ' . $id . '). ' . $this->check_site());
				break;
			case 'deactivate_plugin':
				$this->set_active(array_diff($this->active_plugins(), array((string) $fix['plugin'])));
				$this->flash('success', 'Deactivated ' . $fix['plugin'] . '. ' . $this->check_site());
				break;
			case 'switch_theme':
				foreach (array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone') as $t) {
					if (isset($this->themes()[$t])) {
						$this->switch_theme($t);
						break;
					}
				}
				break;
			default:
				throw new RuntimeException('Nothing to apply.');
		}
		delete_option('wudt_rescue_proposal');
	}

	private function check_site(): string {
		if (! function_exists('curl_init')) {
			return '';
		}
		$ch = curl_init(rtrim((string) get_option('home'), '/') . '/?wudt_health=' . time());
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true));
		$body = (string) curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$broken = $code >= 500 || false !== stripos($body, 'critical error');
		return $broken ? 'The home page still shows an error (HTTP ' . $code . ').' : 'The home page now loads (HTTP ' . $code . ').';
	}

	private function ai_request(string $provider, string $model, string $key, string $system, string $prompt): string {
		if (! function_exists('curl_init')) {
			throw new RuntimeException('cURL is not available on this server.');
		}
		$headers = array('Content-Type: application/json');
		switch ($provider) {
			case 'anthropic':
				$url = 'https://api.anthropic.com/v1/messages';
				$headers[] = 'x-api-key: ' . $key;
				$headers[] = 'anthropic-version: 2023-06-01';
				$body = array('model' => $model, 'max_tokens' => 4000, 'system' => $system, 'messages' => array(array('role' => 'user', 'content' => $prompt)));
				break;
			case 'gemini':
				$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
				$headers[] = 'x-goog-api-key: ' . $key;
				$body = array(
					'systemInstruction' => array('parts' => array(array('text' => $system))),
					'contents'          => array(array('role' => 'user', 'parts' => array(array('text' => $prompt)))),
					'generationConfig'  => array('maxOutputTokens' => 8000, 'responseMimeType' => 'application/json'),
				);
				break;
			default:
				$url = 'openrouter' === $provider ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
				$headers[] = 'Authorization: Bearer ' . $key;
				$body = array('model' => $model, 'messages' => array(array('role' => 'system', 'content' => $system), array('role' => 'user', 'content' => $prompt)));
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => (string) wp_json_encode($body),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 180,
		));
		$raw = curl_exec($ch);
		if (false === $raw && false !== stripos(curl_error($ch), 'certificate')) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			$raw = curl_exec($ch);
		}
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$data = json_decode((string) $raw, true);
		if ($code < 200 || $code >= 300 || ! is_array($data)) {
			throw new RuntimeException('AI request failed (HTTP ' . $code . '): ' . ($data['error']['message'] ?? $err ?: mb_substr((string) $raw, 0, 200)));
		}
		if ('anthropic' === $provider) {
			$text = '';
			foreach ((array) ($data['content'] ?? array()) as $b) {
				$text .= (string) ($b['text'] ?? '');
			}
			return $text;
		}
		if ('gemini' === $provider) {
			$text = '';
			foreach ((array) ($data['candidates'][0]['content']['parts'] ?? array()) as $p) {
				if (empty($p['thought'])) {
					$text .= (string) ($p['text'] ?? '');
				}
			}
			return $text;
		}
		return (string) ($data['choices'][0]['message']['content'] ?? '');
	}

	/* --------------------------------------------------------------- */
	/* Rendering                                                       */

	private function url(array $args = array()): string {
		$path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
		return $path . ($args ? '?' . http_build_query($args) : '');
	}

	private function redirect(string $url): void {
		header('Location: ' . $url, true, 303);
		exit;
	}

	private function flash(string $type, string $msg): void {
		$this->flash[] = array($type, $msg);
	}

	private function save_flash(): void {
		if (! empty($this->flash)) {
			set_transient('wudt_rescue_flash', $this->flash, 300);
		}
	}

	private function take_flash(): array {
		$f = array_merge((array) get_transient('wudt_rescue_flash'), $this->flash);
		delete_transient('wudt_rescue_flash');
		return array_filter($f);
	}

	private function form(string $do, string $label, array $fields = array(), string $class = '', string $confirm = ''): string {
		$h = '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(' . wudt_rescue_e(wp_json_encode($confirm)) . ')"' : '') . '>'
			. '<input type="hidden" name="do" value="' . wudt_rescue_e($do) . '"><input type="hidden" name="csrf" value="' . wudt_rescue_e($this->csrf()) . '">';
		foreach ($fields as $k => $v) {
			$h .= '<input type="hidden" name="' . wudt_rescue_e($k) . '" value="' . wudt_rescue_e((string) $v) . '">';
		}
		return $h . '<button class="btn ' . wudt_rescue_e($class) . '">' . wudt_rescue_e($label) . '</button></form>';
	}

	private function login_form(): void {
		$body = '<p>Enter the rescue key from <strong>WP Diagnostics → Pro → Recovery</strong> (or open the full rescue link from the recovery email).</p>'
			. '<form method="get" class="login"><input type="password" name="key" placeholder="Rescue key" autofocus required><button class="btn primary">Open rescue console</button></form>';
		$this->page('WP Diagnostics Rescue', $body);
	}

	private function dashboard(): void {
		$fatal = $this->last_fatal();
		$active = $this->active_plugins();
		$plugins = $this->plugins();
		$themes = $this->themes();
		$stylesheet = (string) get_option('stylesheet');
		$h = '';

		// Status.
		$h .= '<section><h2>Status</h2>';
		if ($fatal) {
			$c = $this->culprit((string) $fatal['file']);
			$h .= '<div class="alert error"><strong>Last fatal error' . (! empty($fatal['time']) ? ' (' . wudt_rescue_e($fatal['time']) . ')' : '') . ':</strong><br><code>' . wudt_rescue_e($fatal['message']) . '</code>'
				. '<br>in <code>' . wudt_rescue_e($this->rel((string) $fatal['file'])) . '</code> line ' . (int) $fatal['line'] . '</div><div class="row">';
			if ('plugin' === $c['type'] && in_array($c['id'], $active, true) && $c['id'] !== $this->self_basename) {
				$h .= $this->form('deactivate', 'Deactivate the plugin that caused it (' . $c['id'] . ')', array('plugin' => $c['id']), 'primary');
			}
			if ('theme' === $c['type']) {
				$h .= $this->form('theme', 'Switch to a default theme', array('theme' => $this->default_theme($themes)), 'primary');
			}
			$h .= $this->form('ai_fix', 'Ask AI to fix it (' . \WUDT\Includes\AI_Config::providers()[\WUDT\Includes\AI_Config::active_provider()]['label'] . ')', array(), 'ai');
			$h .= $this->form('clear_fatal', 'Clear this error', array(), 'subtle');
			$h .= '</div>';
		} else {
			$h .= '<div class="alert ok">No fatal error recorded.</div>';
		}

		$proposal = get_option('wudt_rescue_proposal');
		if (is_array($proposal) && ! empty($proposal['fix']['type'])) {
			$fix = $proposal['fix'];
			$h .= '<div class="proposal"><h3>AI proposal <small>' . wudt_rescue_e((string) ($proposal['provider'] ?? '')) . '</small></h3><p>' . wudt_rescue_e((string) ($proposal['explanation'] ?? '')) . '</p>';
			if ('edit' === $fix['type']) {
				$h .= '<p>Edit <code>' . wudt_rescue_e((string) $fix['file']) . '</code>:</p><div class="diff"><pre class="del">' . wudt_rescue_e((string) $fix['old']) . '</pre><pre class="add">' . wudt_rescue_e((string) $fix['new']) . '</pre></div>';
			} elseif ('deactivate_plugin' === $fix['type']) {
				$h .= '<p>Deactivate plugin <code>' . wudt_rescue_e((string) $fix['plugin']) . '</code>.</p>';
			} elseif ('switch_theme' === $fix['type']) {
				$h .= '<p>Switch to a default theme.</p>';
			}
			$h .= '<div class="row">' . ('none' !== $fix['type'] ? $this->form('apply_fix', 'Apply this fix (backup kept)', array(), 'primary') : '') . $this->form('discard_fix', 'Discard') . '</div></div>';
		}
		$h .= '</section>';

		// Safe mode.
		$isolated = $this->valid_cookie('wudt_isolate', 'isolate');
		$h .= '<section><h2>Open WP Diagnostics in safe mode</h2><p>Loads wp-admin <strong>for your browser only</strong> with every other plugin and your theme disabled, so the full WP Diagnostics app and AI agent work even while the site is broken. Visitors are not affected.</p><div class="row">'
			. $this->form('safe_mode', $isolated ? 'Open WP Diagnostics (safe mode is on)' : 'Start safe mode and open WP Diagnostics', array(), 'primary')
			. ($isolated ? $this->form('exit_safe', 'Exit safe mode') : '') . '</div></section>';

		// Plugins.
		$h .= '<section><h2>Plugins</h2><div class="row">'
			. $this->form('deactivate_all', 'Deactivate all plugins (keep WP Diagnostics)', array(), '', 'Deactivate all plugins except WP Diagnostics?')
			. (get_option('wudt_rescue_prev_plugins') ? $this->form('restore_plugins', 'Restore previous plugins') : '')
			. '</div><table><thead><tr><th>Plugin</th><th>File</th><th></th></tr></thead><tbody>';
		foreach ($plugins as $file => $data) {
			$is_active = in_array($file, $active, true);
			$h .= '<tr class="' . ($is_active ? 'on' : '') . '"><td>' . wudt_rescue_e($data['Name']) . ' <small>' . wudt_rescue_e($data['Version']) . '</small></td><td><code>' . wudt_rescue_e($file) . '</code></td><td>';
			if ($file === $this->self_basename) {
				$h .= '<em>this plugin</em>';
			} else {
				$h .= $is_active ? $this->form('deactivate', 'Deactivate', array('plugin' => $file)) : $this->form('activate', 'Activate', array('plugin' => $file), 'subtle');
			}
			$h .= '</td></tr>';
		}
		$h .= '</tbody></table></section>';

		// Themes.
		$h .= '<section><h2>Theme</h2><table><tbody>';
		foreach ($themes as $slug => $data) {
			$h .= '<tr class="' . ($slug === $stylesheet ? 'on' : '') . '"><td>' . wudt_rescue_e($data['Name']) . ($data['Template'] ? ' <small>child of ' . wudt_rescue_e($data['Template']) . '</small>' : '') . '</td><td>'
				. ($slug === $stylesheet ? '<em>active</em>' : $this->form('theme', 'Activate', array('theme' => $slug), 'subtle')) . '</td></tr>';
		}
		$h .= '</tbody></table></section>';

		// Debug.
		$d = defined('WP_DEBUG') && WP_DEBUG;
		$l = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
		$s = ! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY;
		$h .= '<section><h2>Debugging</h2><p>Now: WP_DEBUG <strong>' . ($d ? 'on' : 'off') . '</strong>, logging <strong>' . ($l ? 'on' : 'off') . '</strong>, on-screen errors <strong>' . ($d && $s ? 'on' : 'off') . '</strong>.</p><div class="row">'
			. $this->form('debug', 'Turn on error logging (hidden from visitors)', array('debug' => 1, 'log' => 1, 'display' => 0), 'primary')
			. $this->form('debug', 'Turn debugging off', array('debug' => 0, 'log' => 0, 'display' => 0))
			. '</div>';
		$tail = $this->tail($this->debug_log_path(), 60);
		$h .= '<details' . ($fatal ? ' open' : '') . '><summary>debug.log (last 60 lines)</summary><pre class="log">';
		foreach ($tail as $line) {
			$h .= preg_match('/(Fatal|Parse) error/', $line) ? '<mark>' . wudt_rescue_e($line) . '</mark>' . "\n" : wudt_rescue_e($line) . "\n";
		}
		$h .= ($tail ? '' : '(empty)') . '</pre></details></section>';

		// Changes.
		$changes = array_slice(array_filter((array) get_option('wudt_ai_changes', array()), static function ($c) {
			return 'file' === ($c['type'] ?? '');
		}), 0, 15);
		if ($changes) {
			$h .= '<section><h2>Recent file changes by the AI agent</h2><table><tbody>';
			foreach ($changes as $c) {
				$h .= '<tr><td>' . wudt_rescue_e(gmdate('Y-m-d H:i', (int) $c['time'])) . '</td><td>' . wudt_rescue_e($c['description']) . '</td><td>'
					. (! empty($c['undone']) ? '<em>undone</em>' : $this->form('undo', 'Undo', array('change' => $c['id']), 'subtle', 'Restore the previous version of this file?')) . '</td></tr>';
			}
			$h .= '</tbody></table></section>';
		}

		$this->page('WP Diagnostics Rescue', $h, true);
	}

	private function default_theme(array $themes): string {
		foreach (array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone') as $t) {
			if (isset($themes[$t])) {
				return $t;
			}
		}
		return (string) array_key_first($themes);
	}

	private function page(string $title, string $body, bool $authed = false): void {
		$flash = '';
		foreach ($this->take_flash() as $f) {
			$flash .= '<div class="alert ' . wudt_rescue_e($f[0]) . '">' . wudt_rescue_e($f[1]) . '</div>';
		}
		$home = (string) get_option('home');
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . wudt_rescue_e($title) . '</title><style>'
			. ':root{--bg:#f4f6fb;--card:#fff;--text:#1f2937;--muted:#6b7280;--line:#e5e7eb;--accent:#4f46e5;--ok:#059669;--bad:#dc2626}'
			. '*{box-sizing:border-box}body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text)}'
			. 'header{background:#111827;color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}header a{color:#c7d2fe}'
			. 'main{max-width:1000px;margin:20px auto;padding:0 16px}section{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 20px;margin-bottom:16px}'
			. 'h1{font-size:18px;margin:0}h2{font-size:16px;margin:0 0 10px}h3{margin:0 0 6px;font-size:15px}small{color:var(--muted)}code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:13px;word-break:break-all}'
			. '.alert{padding:10px 14px;border-radius:8px;margin:0 0 12px;border:1px solid}.alert.error{background:#fef2f2;border-color:#fecaca}.alert.ok,.alert.success{background:#ecfdf5;border-color:#a7f3d0}.alert.info{background:#eef2ff;border-color:#c7d2fe}'
			. '.row{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}form.inline{display:inline}.btn{border:1px solid #d1d5db;background:#fff;border-radius:8px;padding:7px 12px;cursor:pointer;font-size:14px}.btn:hover{border-color:var(--accent)}'
			. '.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.ai{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;border:0}.btn.subtle{color:var(--muted)}'
			. 'table{width:100%;border-collapse:collapse;font-size:14px}td,th{padding:7px 6px;border-top:1px solid var(--line);text-align:left;vertical-align:middle}tr.on td:first-child{font-weight:600}tr.on td:first-child::before{content:"● ";color:var(--ok)}'
			. 'pre{white-space:pre-wrap;word-break:break-word;margin:0}.log{background:#111827;color:#e5e7eb;padding:10px;border-radius:8px;font-size:12px;max-height:420px;overflow:auto}mark{background:#7f1d1d;color:#fecaca}'
			. '.proposal{border:1px solid #c7d2fe;background:#f8f9ff;border-radius:10px;padding:12px 14px;margin-top:12px}.diff pre{padding:8px;border-radius:6px;font-size:12.5px;margin:4px 0}.del{background:#fef2f2;border-left:3px solid var(--bad)}.add{background:#ecfdf5;border-left:3px solid var(--ok)}'
			. '.login{display:flex;gap:8px;flex-wrap:wrap}.login input{flex:1;min-width:220px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px}details summary{cursor:pointer;color:var(--muted);margin:8px 0}'
			. '</style></head><body><header><h1>🛟 ' . wudt_rescue_e($title) . '</h1><div>' . wudt_rescue_e((string) get_option('blogname')) . ' · <a href="' . wudt_rescue_e($home) . '" target="_blank" rel="noopener">View site</a>'
			. ($authed ? ' · <a href="' . wudt_rescue_e($this->url(array('logout' => 1))) . '">Log out</a>' : '') . '</div></header><main>' . $flash . ($authed ? '' : '<section>') . $body . ($authed ? '' : '</section>') . '</main></body></html>';
	}
}

(new WUDT_Rescue())->run();
