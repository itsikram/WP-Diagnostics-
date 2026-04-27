<?php
/**
 * Cron inspector module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Cron_Module extends Module_Base {
	public function register_hooks(): void {
		add_action('wp_ajax_wudt_cron_action', array($this, 'ajax_cron_action'));
	}

	public function get_key(): string {
		return 'cron';
	}

	public function get_label(): string {
		return __('Cron Jobs', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$cron  = _get_cron_array();
		$items = array();
		if (is_array($cron)) {
			foreach ($cron as $timestamp => $hooks) {
				foreach ($hooks as $hook => $events) {
					foreach ($events as $event) {
						$items[] = array(
							'hook'      => $hook,
							'timestamp' => (int) $timestamp,
							'next_run'  => wp_date('Y-m-d H:i:s', (int) $timestamp),
							'args'      => $event['args'] ?? array(),
						);
					}
				}
			}
		}
		return array('jobs' => $items);
	}

	public function ajax_cron_action(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		$action    = isset($_POST['cron_action']) ? sanitize_text_field((string) wp_unslash($_POST['cron_action'])) : '';
		$hook      = isset($_POST['hook']) ? sanitize_text_field((string) wp_unslash($_POST['hook'])) : '';
		$timestamp = isset($_POST['timestamp']) ? (int) wp_unslash($_POST['timestamp']) : 0;
		$args      = isset($_POST['args']) ? json_decode((string) wp_unslash($_POST['args']), true) : array();
		if (! is_array($args)) {
			$args = array();
		}
		if ('run' === $action) {
			do_action_ref_array($hook, $args);
		}
		if ('delete' === $action && $timestamp > 0) {
			wp_unschedule_event($timestamp, $hook, $args);
		}
		wp_send_json_success(array('message' => __('Cron action executed.', 'wp-ultimate-diagnostics-toolkit')));
	}
}
