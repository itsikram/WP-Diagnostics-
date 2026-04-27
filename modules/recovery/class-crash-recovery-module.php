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

	public function register_hooks(): void {
		add_action('shutdown', array($this, 'handle_fatal_shutdown'));
	}

	public function get_key(): string {
		return 'recovery';
	}

	public function get_label(): string {
		return __('Recovery', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array('events' => array_slice((array) get_option(self::OPTION_LAST, array()), -100));
	}

	public function handle_fatal_shutdown(): void {
		$error = error_get_last();
		if (! is_array($error) || ! in_array((int) ($error['type'] ?? 0), array(E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR), true)) {
			return;
		}
		$file = wp_normalize_path((string) ($error['file'] ?? ''));
		if (! str_contains($file, '/plugins/')) {
			return;
		}
		$plugin = $this->plugin_from_path($file);
		if ('' === $plugin || $this->is_protected_plugin($plugin)) {
			return;
		}
		$confidence = $this->confidence_from_error((string) ($error['message'] ?? ''), $file);
		if ($confidence < 70) {
			$this->log_event('detected', $plugin, $error, $confidence);
			return;
		}
		deactivate_plugins($plugin, true);
		$this->log_event('disabled', $plugin, $error, $confidence);
		Operation_Logger::log('recovery', 'Auto-disabled crashing plugin', array('plugin' => $plugin, 'confidence' => $confidence));
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

	private function plugin_from_path(string $file): string {
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
