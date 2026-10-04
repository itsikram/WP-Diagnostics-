<?php
/**
 * Safe mode: open wp-admin with only Diagnostics Toolkit active and a default
 * theme, for the current administrator's browser only.
 *
 * A tiny must-use plugin ("safe loader") is installed the first time an
 * administrator turns safe mode on. It only acts on requests that carry a
 * signed, short-lived cookie, so visitors are never affected. When wp-admin
 * cannot load at all, WordPress's own Recovery Mode email link gets the
 * administrator in, and safe mode can be switched on from there.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Rescue_Manager {
	public const OPTION_KEY = 'wudt_rescue_key';
	public const COOKIE = 'wudt_isolate';
	private const LOADER_VERSION = '2';
	private const LOADER_FILE = 'wudt-safe-loader.php';
	private const SESSION_TTL = 2 * HOUR_IN_SECONDS;

	public function register_hooks(): void {
		add_action('admin_notices', array($this, 'safe_mode_notice'));
		add_action('wp_ajax_wudt_safe_mode_enter', array($this, 'ajax_enter'));
	}

	/**
	 * Secret used to sign the safe-mode cookie.
	 */
	public static function get_key(): string {
		$key = (string) get_option(self::OPTION_KEY, '');
		if (strlen($key) < 32) {
			$key = bin2hex(random_bytes(20));
			update_option(self::OPTION_KEY, $key, false);
		}
		return $key;
	}

	public static function loader_path(): string {
		return wp_normalize_path(WPMU_PLUGIN_DIR) . '/' . self::LOADER_FILE;
	}

	public static function status(): array {
		return array(
			'loader_active' => is_file(self::loader_path()),
			'loader_path'   => self::loader_path(),
			'safe_mode'     => defined('WUDT_SAFE_MODE') && WUDT_SAFE_MODE,
			'exit_url'      => add_query_arg('wudt_exit_safe', '1', admin_url()),
		);
	}

	/**
	 * Write (or update) the must-use safe loader.
	 */
	public static function install_loader(): bool {
		$path = self::loader_path();
		$basename = plugin_basename(WUDT_PLUGIN_FILE);
		$expected_marker = 'WUDT_LOADER ' . self::LOADER_VERSION . ' ' . $basename;
		if (is_file($path) && false !== strpos((string) file_get_contents($path, false, null, 0, 800), $expected_marker)) {
			return true;
		}
		if (! is_dir(dirname($path)) && ! wp_mkdir_p(dirname($path))) {
			return false;
		}
		return false !== file_put_contents($path, self::loader_code($basename, $expected_marker), LOCK_EX);
	}

	public static function remove_loader(): void {
		$path = self::loader_path();
		if (is_file($path) && false !== strpos((string) file_get_contents($path, false, null, 0, 400), 'WUDT_LOADER')) {
			wp_delete_file($path);
		}
	}

	private static function loader_code(string $basename, string $marker): string {
		$basename_export = "'" . addcslashes($basename, "\\'") . "'";
		$cookie = self::COOKIE;
		// Assembled at runtime: a literal header line in this file would make WordPress's
		// plugin installer mistake this class file for the plugin's main file.
		$header = 'Plugin' . ' Name';
		return <<<PHP
<?php
/**
 * {$header}: Diagnostics Toolkit Safe Mode
 * Description: Lets an administrator open wp-admin with all other plugins and the theme disabled (for that browser only). Added when safe mode is first used in Diagnostics Toolkit; removed when Diagnostics Toolkit is deactivated. Safe to delete.
 * Version: 2.0
 */
// {$marker}

if (! defined('ABSPATH')) {
	exit;
}

(function () {
	\$basename = {$basename_export};
	if (! is_file(WP_PLUGIN_DIR . '/' . \$basename)) {
		return; // Diagnostics Toolkit was removed.
	}
	if (isset(\$_GET['wudt_exit_safe'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leaving safe mode only clears this browser's cookie.
		setcookie('{$cookie}', '', time() - 3600, '/');
		unset(\$_COOKIE['{$cookie}']);
		return;
	}
	\$key = (string) get_option('wudt_rescue_key', '');
	\$value = isset(\$_COOKIE['{$cookie}']) ? sanitize_text_field(wp_unslash(\$_COOKIE['{$cookie}'])) : '';
	\$parts = explode('.', \$value, 2);
	if (strlen(\$key) < 32 || 2 !== count(\$parts) || (int) \$parts[0] < time() || ! hash_equals(hash_hmac('sha256', 'isolate|' . \$parts[0], \$key), \$parts[1])) {
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

	/**
	 * Turn safe mode on for the current browser.
	 */
	public function ajax_enter(): void {
		Security_Guard::assert_ajax_admin();
		if (! self::install_loader()) {
			wp_send_json_error(array('message' => __('Could not write the safe-mode loader. Make wp-content/mu-plugins writable and try again.', 'diagnostics-toolkit')));
		}
		$expires = time() + self::SESSION_TTL;
		$value = $expires . '.' . hash_hmac('sha256', 'isolate|' . $expires, self::get_key());
		setcookie(self::COOKIE, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		));
		Operation_Logger::log('recovery', 'Safe mode enabled for an administrator browser', array('user' => get_current_user_id()));
		wp_send_json_success(array('redirect' => admin_url('admin.php?page=wudt-diagnostics-pro#recovery')));
	}

	public function safe_mode_notice(): void {
		if (! (defined('WUDT_SAFE_MODE') && WUDT_SAFE_MODE) || ! current_user_can('manage_options')) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Diagnostics Toolkit safe mode:', 'diagnostics-toolkit') . '</strong> '
			. esc_html__('all other plugins and your theme are disabled for your browser only. Visitors are not affected.', 'diagnostics-toolkit') . ' '
			. '<a class="button button-small" href="' . esc_url(add_query_arg('wudt_exit_safe', '1', admin_url())) . '">' . esc_html__('Exit safe mode', 'diagnostics-toolkit') . '</a></p></div>';
	}
}
