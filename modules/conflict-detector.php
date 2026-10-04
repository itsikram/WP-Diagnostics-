<?php
/**
 * Conflict detector module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Conflict_Detector_Module extends Module_Base {
	private const TEST_MODE_OPTION = 'wudt_test_mode_enabled';

	public function register_hooks(): void {
		add_filter('option_active_plugins', array($this, 'apply_test_mode_plugin_filter'));
		add_action('wp_ajax_wudt_toggle_test_mode', array($this, 'ajax_toggle_test_mode'));
	}

	public function get_key(): string {
		return 'conflict_detector';
	}

	public function get_label(): string {
		return __('Conflict Detector', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$recently_activated = (array) get_option('recently_activated', array());
		arsort($recently_activated);
		$suspected = array_slice(array_keys($recently_activated), 0, 5);

		return array(
			'test_mode_enabled' => (bool) get_option(self::TEST_MODE_OPTION, false),
			'suspected_plugins' => $suspected,
			'suggestions'       => array(
				__('Disable recently activated plugins first and test front-end rendering.', 'diagnostics-toolkit'),
				__('Switch temporarily to a default theme and compare behavior.', 'diagnostics-toolkit'),
				__('Use test mode to keep only one plugin active per request.', 'diagnostics-toolkit'),
			),
		);
	}

	/**
	 * @param array<int,string> $active_plugins Active plugins.
	 * @return array<int,string>
	 */
	public function apply_test_mode_plugin_filter(array $active_plugins): array {
		if (! is_admin() || ! current_user_can('manage_options')) {
			return $active_plugins;
		}
		if (! get_option(self::TEST_MODE_OPTION, false)) {
			return $active_plugins;
		}

		$requested = isset($_GET['wudt_plugins']) ? sanitize_text_field((string) wp_unslash($_GET['wudt_plugins'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ('' === $requested) {
			return array();
		}
		$allowed = array_filter(array_map('trim', explode(',', $requested)));
		return array_values(array_intersect($active_plugins, $allowed));
	}

	public function ajax_toggle_test_mode(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'diagnostics-toolkit')), 403);
		}
		$enabled = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
		update_option(self::TEST_MODE_OPTION, $enabled);
		wp_send_json_success(array('enabled' => $enabled));
	}
}
