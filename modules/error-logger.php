<?php
/**
 * Error logger module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Error_Logger_Module extends Module_Base {
	private const OPTION_KEY = 'wudt_error_log_entries';

	public function register_hooks(): void {
		add_action('init', array($this, 'setup_error_capture'));
		add_action('wp_ajax_wudt_get_error_logs', array($this, 'ajax_get_logs'));
		add_action('wp_ajax_wudt_clear_error_logs', array($this, 'ajax_clear_logs'));
		add_action('wp_ajax_wudt_toggle_debug', array($this, 'ajax_toggle_debug'));
	}

	public function get_key(): string {
		return 'error_logs';
	}

	public function get_label(): string {
		return __('Error Logs', 'wp-ultimate-diagnostics-toolkit');
	}

	/**
	 * Errors captured during this request; written once at shutdown.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $buffer = array();

	public function setup_error_capture(): void {
		$previous = set_error_handler(array($this, 'capture_php_error'));
		$this->previous_handler = is_callable($previous) ? $previous : null;
		register_shutdown_function(array($this, 'capture_shutdown_error'));
	}

	/** @var callable|null */
	private $previous_handler = null;

	/**
	 * @return bool
	 */
	public function capture_php_error(int $errno, string $errstr, string $errfile, int $errline): bool {
		// Respect error_reporting() and the @ operator, and skip deprecation noise.
		if ((error_reporting() & $errno) && ! in_array($errno, array(E_DEPRECATED, E_USER_DEPRECATED), true)) {
			$key = md5($errno . '|' . $errfile . '|' . $errline . '|' . $errstr);
			if (! isset($this->buffer[$key]) && count($this->buffer) < 50) {
				$this->buffer[$key] = array(
					'type'      => 'php',
					'severity'  => (string) $errno,
					'message'   => $errstr,
					'file'      => $errfile,
					'line'      => $errline,
					'logged_at' => current_time('mysql'),
				);
			}
		}
		if ($this->previous_handler) {
			return (bool) call_user_func($this->previous_handler, $errno, $errstr, $errfile, $errline);
		}
		return false;
	}

	public function capture_shutdown_error(): void {
		$error = error_get_last();
		$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (! empty($error) && in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
			$this->buffer['fatal'] = array(
				'type'      => 'fatal',
				'severity'  => (string) ($error['type'] ?? ''),
				'message'   => (string) ($error['message'] ?? ''),
				'file'      => (string) ($error['file'] ?? ''),
				'line'      => (int) ($error['line'] ?? 0),
				'logged_at' => current_time('mysql'),
			);
		}
		if (empty($this->buffer)) {
			return;
		}
		try {
			$entries = (array) get_option(self::OPTION_KEY, array());
			$entries = array_merge($entries, array_values($this->buffer));
			if (count($entries) > 300) {
				$entries = array_slice($entries, -300);
			}
			update_option(self::OPTION_KEY, $entries, false);
		} catch (\Throwable $e) {
			// The database may be unavailable during shutdown; never make things worse.
			unset($e);
		}
		$this->buffer = array();
	}

	public function get_dashboard_data(): array {
		$debug_log = $this->get_debug_log_lines();
		return array(
			'wp_debug'     => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
			'wp_debug_log' => defined('WP_DEBUG_LOG') ? (bool) WP_DEBUG_LOG : false,
			'entries'      => get_option(self::OPTION_KEY, array()),
			'debug_log'    => $debug_log,
		);
	}

	public function ajax_get_logs(): void {
		$this->verify_ajax();
		$type = isset($_POST['type']) ? sanitize_text_field((string) wp_unslash($_POST['type'])) : '';
		$date = isset($_POST['date']) ? sanitize_text_field((string) wp_unslash($_POST['date'])) : '';

		$entries = (array) get_option(self::OPTION_KEY, array());
		$entries = array_values(
			array_filter(
				$entries,
				static function ($entry) use ($type, $date): bool {
					if ($type && ('all' !== $type) && (($entry['type'] ?? '') !== $type)) {
						return false;
					}
					if ($date && ! empty($entry['logged_at']) && 0 !== strpos((string) $entry['logged_at'], $date)) {
						return false;
					}
					return true;
				}
			)
		);
		wp_send_json_success(array('entries' => $entries, 'debug_log' => $this->get_debug_log_lines()));
	}

	public function ajax_clear_logs(): void {
		$this->verify_ajax();
		update_option(self::OPTION_KEY, array(), false);
		wp_send_json_success(array('message' => __('Logs cleared.', 'wp-ultimate-diagnostics-toolkit')));
	}

	public function ajax_toggle_debug(): void {
		$this->verify_ajax();
		$enabled = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
		update_option('wudt_force_debug', $enabled);
		if ($enabled) {
			if (! defined('WP_DEBUG')) {
				define('WP_DEBUG', true);
			}
			if (! defined('WP_DEBUG_LOG')) {
				define('WP_DEBUG_LOG', true);
			}
		}
		wp_send_json_success(array('enabled' => $enabled));
	}

	/**
	 * @param array<string,mixed> $entry Entry.
	 */
	private function store_log(array $entry): void {
		$entries   = (array) get_option(self::OPTION_KEY, array());
		$entries[] = $entry;
		if (count($entries) > 300) {
			$entries = array_slice($entries, -300);
		}
		update_option(self::OPTION_KEY, $entries, false);
	}

	/**
	 * @return array<int,string>
	 */
	private function get_debug_log_lines(): array {
		$file = WP_CONTENT_DIR . '/debug.log';
		if (! file_exists($file) || ! is_readable($file)) {
			return array();
		}
		$content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (! is_array($content)) {
			return array();
		}
		return array_slice($content, -200);
	}

	private function verify_ajax(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
	}
}
