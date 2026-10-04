<?php
/**
 * Crash recovery and automatic plugin disabler.
 */

declare(strict_types=1);

namespace WUDT\Modules\Recovery;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Crash_Recovery_Module extends Module_Base {
	private const OPTION_LAST = 'wudt_crash_recovery_events';
	private const OPTION_WHITELIST = 'wudt_recovery_plugin_protectlist';
	private const CRASH_THRESHOLD = 3;
	private const CRASH_WINDOW = 600;

	public function register_hooks(): void {
		add_action('shutdown', array($this, 'handle_fatal_shutdown'));
		add_action('wp_ajax_wudt_recovery_auto_toggle', array($this, 'ajax_auto_toggle'));
	}

	/**
	 * Automatic deactivation of crashing plugins (and switching away from a crashing
	 * theme) only happens after an administrator switches it on.
	 */
	public static function auto_enabled(): bool {
		return (bool) get_option('wudt_auto_disable_crashing', false);
	}

	public function ajax_auto_toggle(): void {
		\WUDT\Includes\Security_Guard::assert_ajax_admin();
		$enabled = isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash($_POST['enabled']));
		update_option('wudt_auto_disable_crashing', $enabled, false);
		wp_send_json_success(array('enabled' => $enabled));
	}

	public function get_key(): string {
		return 'recovery';
	}

	public function get_label(): string {
		return __('Recovery', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$data = array('events' => array_slice((array) get_option(self::OPTION_LAST, array()), -100));
		if (class_exists('\\WUDT\\Includes\\Rescue_Manager')) {
			$data['rescue'] = \WUDT\Includes\Rescue_Manager::status();
		}
		$data['last_fatal'] = get_option('wudt_last_fatal_error') ?: null;
		$data['auto_disable'] = self::auto_enabled();
		return $data;
	}

	public function handle_fatal_shutdown(): void {
		$error = error_get_last();
		if (! is_array($error) || ! in_array((int) ($error['type'] ?? 0), array(E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR), true)) {
			return;
		}
		self::report_fatal($error, 'shutdown');
	}

	/**
	 * Shared crash policy (also used by the log scanner of Auto_Recovery_Module).
	 *
	 * A plugin is deactivated only when it caused real fatal errors in at least
	 * CRASH_THRESHOLD separate web requests within CRASH_WINDOW seconds. Single
	 * errors, command-line/cron runs and Diagnostics Toolkit itself never trigger it.
	 *
	 * @param array<string,mixed> $error
	 */
	public static function report_fatal(array $error, string $source): void {
		if ('cli' === PHP_SAPI || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
			return;
		}
		$file = wp_normalize_path((string) ($error['file'] ?? ''));
		// Uncaught exceptions report where they were thrown; the message holds the same file.
		if (! str_contains($file, '/plugins/') && preg_match('# in (.+?\.php)(?::| on line )#', (string) ($error['message'] ?? ''), $m)) {
			$file = wp_normalize_path($m[1]);
		}
		if (! str_contains($file, '/plugins/')) {
			return;
		}
		$self = new self();
		$plugin = $self->plugin_from_path($file);
		if ('' === $plugin || $self->is_protected_plugin($plugin)) {
			return;
		}

		$counts = get_option('wudt_crash_counts', array());
		$counts = is_array($counts) ? $counts : array();
		$now = time();
		$recent = array_values(array_filter((array) ($counts[$plugin] ?? array()), static function ($t) use ($now) {
			return ($now - (int) $t) < self::CRASH_WINDOW;
		}));
		$recent[] = $now;
		$counts[$plugin] = array_slice($recent, -10);
		update_option('wudt_crash_counts', $counts, false);

		if (count($recent) < self::CRASH_THRESHOLD || ! get_option('wudt_auto_disable_crashing', false)) {
			$self->log_event('detected', $plugin, $error, count($recent) * 30);
			return;
		}
		if (! function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins($plugin, true);
		unset($counts[$plugin]);
		update_option('wudt_crash_counts', $counts, false);
		$self->log_event('disabled', $plugin, $error, 100);
		Operation_Logger::log('recovery', 'Auto-disabled crashing plugin', array('plugin' => $plugin, 'source' => $source, 'crashes' => count($recent)));
		update_option('wudt_recovery_last_notice', array('plugin' => $plugin, 'time' => current_time('mysql')), false);
	}

	/**
	 * @param array<string,mixed> $error
	 */
	private function log_event(string $action, string $plugin, array $error, int $confidence): void {
		$events   = (array) get_option(self::OPTION_LAST, array());
		$events[] = array(
			'time'       => current_time('mysql'),
			'action'     => $action,
			'plugin'     => $plugin,
			'confidence' => $confidence,
			'message'    => (string) ($error['message'] ?? ''),
			'file'       => (string) ($error['file'] ?? ''),
			'line'       => (int) ($error['line'] ?? 0),
		);
		update_option(self::OPTION_LAST, array_slice($events, -200), false);
	}

	public function plugin_from_path(string $file): string {
		$marker = '/plugins/';
		$pos = strpos($file, $marker);
		if (false === $pos) {
			return '';
		}
		$rel = substr($file, $pos + strlen($marker));
		$parts = explode('/', $rel);
		$slug = $parts[0] ?? '';
		if ('' === $slug) {
			return '';
		}
		$candidate = trailingslashit($slug) . $slug . '.php';
		$active = (array) get_option('active_plugins', array());
		if (in_array($candidate, $active, true)) {
			return $candidate;
		}
		foreach ($active as $plugin) {
			if (str_starts_with((string) $plugin, $slug . '/')) {
				return (string) $plugin;
			}
		}
		return '';
	}

	private function is_protected_plugin(string $plugin): bool {
		// Never switch off Diagnostics Toolkit itself: it is the tool used to recover the site.
		if ('diagnostics-toolkit.php' === basename($plugin) || plugin_basename(WUDT_PLUGIN_FILE) === $plugin) {
			return true;
		}
		$list = (array) get_option(self::OPTION_WHITELIST, array('query-monitor/query-monitor.php'));
		return in_array($plugin, $list, true);
	}

	private function confidence_from_error(string $message, string $file): int {
		$score = 50;
		if (str_contains(strtolower($message), 'fatal error')) {
			$score += 20;
		}
		if (str_contains($file, '/plugins/')) {
			$score += 20;
		}
		if (str_contains(strtolower($message), 'memory size')) {
			$score -= 10;
		}
		return max(0, min(100, $score));
	}
}
