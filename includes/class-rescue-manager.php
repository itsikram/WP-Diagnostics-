<?php
/**
 * Rescue access: keeps WP Diagnostics reachable when the site has a fatal error.
 *
 *  - rescue.php is a standalone console that boots WordPress without plugins or
 *    theme (SHORTINIT) and is protected by a secret rescue key.
 *  - A tiny must-use plugin ("safe loader") lets an authenticated rescue session
 *    load wp-admin with only WP Diagnostics active and a default theme, so the full
 *    plugin UI (including the AI agent) works even when another plugin or the
 *    theme crashes every request.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Rescue_Manager {
	public const OPTION_KEY = 'wudt_rescue_key';
	private const LOADER_VERSION = '1';
	private const LOADER_FILE = 'wudt-safe-loader.php';

	public function register_hooks(): void {
		add_action('admin_init', array($this, 'maybe_setup'));
		add_filter('recovery_mode_email', array($this, 'filter_recovery_email'), 10, 2);
		add_action('admin_notices', array($this, 'safe_mode_notice'));
		add_action('wp_ajax_wudt_rescue_regenerate', array($this, 'ajax_regenerate'));
	}

	public static function key_file(): string {
		return wp_normalize_path(WP_CONTENT_DIR) . '/wudt-rescue/key.php';
	}

	/**
	 * Rescue key, generated on first use. Mirrored to a file so the console works
	 * even if the options table cannot be read.
	 */
	public static function get_key(): string {
		$key = (string) get_option(self::OPTION_KEY, '');
		if (strlen($key) < 32) {
			$key = bin2hex(random_bytes(20));
			update_option(self::OPTION_KEY, $key, false);
		}
		self::write_key_file($key);
		return $key;
	}

	private static function write_key_file(string $key): void {
		$file = self::key_file();
		$content = "<?php\n// WP Diagnostics rescue key. Do not share.\nreturn '" . $key . "';\n";
		if (is_file($file) && (string) @file_get_contents($file) === $content) {
			return;
		}
		$dir = dirname($file);
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		}
		@file_put_contents($file, $content, LOCK_EX);
	}

	public static function rescue_url(): string {
		return plugins_url('rescue.php', WUDT_PLUGIN_FILE) . '?key=' . self::get_key();
	}

	public static function loader_path(): string {
		return wp_normalize_path(WPMU_PLUGIN_DIR) . '/' . self::LOADER_FILE;
	}

	public static function status(): array {
		$loader = self::loader_path();
		return array(
			'rescue_url'      => self::rescue_url(),
			'loader_active'   => is_file($loader),
			'loader_path'     => $loader,
			'safe_mode'       => defined('WUDT_SAFE_MODE') && WUDT_SAFE_MODE,
		);
	}

	public function maybe_setup(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		self::get_key();
		self::install_loader();
	}

	/**
	 * Write (or update) the must-use safe loader.
	 */
	public static function install_loader(): bool {
		$path = self::loader_path();
		$basename = plugin_basename(WUDT_PLUGIN_FILE);
		$expected_marker = 'WUDT_LOADER ' . self::LOADER_VERSION . ' ' . $basename;
		if (is_file($path) && false !== strpos((string) @file_get_contents($path, false, null, 0, 600), $expected_marker)) {
			return true;
		}
		if (! is_dir(dirname($path)) && ! wp_mkdir_p(dirname($path))) {
			return false;
		}
		$code = self::loader_code($basename, $expected_marker);
		return false !== @file_put_contents($path, $code, LOCK_EX);
	}

	public static function remove_loader(): void {
		$path = self::loader_path();
		if (is_file($path) && false !== strpos((string) @file_get_contents($path, false, null, 0, 300), 'WUDT_LOADER')) {
			@unlink($path);
		}
	}

	private static function loader_code(string $basename, string $marker): string {
		$basename_export = var_export($basename, true);
		return <<<PHP
<?php
/**
 * Plugin Name: WP Diagnostics Safe Loader
 * Description: Lets an authenticated WP Diagnostics rescue session open wp-admin with all other plugins and the theme disabled (for that browser only). Installed automatically by WP Diagnostics; safe to delete.
 * Version: 1.0
 */
// {$marker}

if (! defined('ABSPATH')) {
	exit;
}

(function () {
	\$basename = {$basename_export};
	\$main = WP_PLUGIN_DIR . '/' . \$basename;
	if (! is_file(\$main)) {
		return; // WP Diagnostics was removed.
	}
	\$key_file = WP_CONTENT_DIR . '/wudt-rescue/key.php';
	\$key = is_file(\$key_file) ? (string) (include \$key_file) : '';
	if (strlen(\$key) < 32) {
		return;
	}
	\$valid = static function (string \$cookie, string \$scope) use (\$key): bool {
		\$value = isset(\$_COOKIE[\$cookie]) ? (string) \$_COOKIE[\$cookie] : '';
		\$parts = explode('.', \$value, 2);
		if (2 !== count(\$parts) || (int) \$parts[0] < time()) {
			return false;
		}
		return hash_equals(hash_hmac('sha256', \$scope . '|' . \$parts[0], \$key), \$parts[1]);
	};

	if (isset(\$_GET['wudt_exit_safe'])) {
		setcookie('wudt_isolate', '', time() - 3600, '/');
		unset(\$_COOKIE['wudt_isolate']);
		return;
	}
	if (! \$valid('wudt_isolate', 'isolate')) {
		return;
	}

	define('WUDT_SAFE_MODE', true);
	add_filter('option_active_plugins', static function () use (\$basename) {
		return array(\$basename);
	}, PHP_INT_MAX);
	add_filter('site_option_active_sitewide_plugins', static function () {
		return array();
	}, PHP_INT_MAX);
	// Plugin (de)activations made in safe mode are applied to the real, stored list.
	add_filter('pre_update_option_active_plugins', static function (\$new) use (\$basename) {
		global \$wpdb;
		\$real = maybe_unserialize(\$wpdb->get_var(\$wpdb->prepare("SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s", 'active_plugins')));
		\$real = is_array(\$real) ? \$real : array();
		\$new = is_array(\$new) ? \$new : array();
		\$added = array_diff(\$new, array(\$basename));
		\$removed = in_array(\$basename, \$new, true) ? array() : array(\$basename);
		\$result = array_values(array_diff(array_unique(array_merge(\$real, \$added)), \$removed));
		sort(\$result);
		return \$result;
	}, PHP_INT_MAX);

	// Use a bundled default theme so a broken theme cannot crash the request.
	foreach (array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone') as \$theme) {
		if (is_file(WP_CONTENT_DIR . '/themes/' . \$theme . '/style.css')) {
			\$use = static function () use (\$theme) {
				return \$theme;
			};
			add_filter('pre_option_template', \$use, PHP_INT_MAX);
			add_filter('pre_option_stylesheet', \$use, PHP_INT_MAX);
			break;
		}
	}
})();

PHP;
	}

	public function filter_recovery_email(array $email, $url): array {
		if (isset($email['message']) && is_string($email['message'])) {
			$email['message'] .= "\n\n" . 'WP Diagnostics rescue console (works even when wp-admin is broken):' . "\n" . self::rescue_url() . "\n";
		}
		return $email;
	}

	public function safe_mode_notice(): void {
		if (! (defined('WUDT_SAFE_MODE') && WUDT_SAFE_MODE) || ! current_user_can('manage_options')) {
			return;
		}
		$exit = add_query_arg('wudt_exit_safe', '1', admin_url());
		echo '<div class="notice notice-warning"><p><strong>WP Diagnostics safe mode:</strong> all other plugins and your theme are disabled for your browser only. Visitors are not affected. '
			. '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=wudt-diagnostics-pro#ai_assistant')) . '">Open AI Assistant</a> '
			. '<a class="button button-small" href="' . esc_url($exit) . '">Exit safe mode</a></p></div>';
	}

	public function ajax_regenerate(): void {
		Security_Guard::assert_ajax_admin();
		update_option(self::OPTION_KEY, bin2hex(random_bytes(20)), false);
		wp_send_json_success(self::status());
	}
}
