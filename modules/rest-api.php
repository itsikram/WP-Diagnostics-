<?php
/**
 * REST API testing module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;
use WP_REST_Request;

if (! defined('ABSPATH')) {
	exit;
}

class REST_API_Module extends Module_Base {
	public function register_hooks(): void {
		add_action('wp_ajax_wudt_test_rest_route', array($this, 'ajax_test_rest_route'));
	}

	public function get_key(): string {
		return 'rest_api';
	}

	public function get_label(): string {
		return __('REST API', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$server = rest_get_server();
		$routes = array_keys($server->get_routes());
		return array('routes' => $routes);
	}

	public function ajax_test_rest_route(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		$method = isset($_POST['method']) ? strtoupper(sanitize_text_field((string) wp_unslash($_POST['method']))) : 'GET';
		$route  = isset($_POST['route']) ? sanitize_text_field((string) wp_unslash($_POST['route'])) : '/';
		$body   = isset($_POST['body']) ? json_decode((string) wp_unslash($_POST['body']), true) : array();
		if (! is_array($body)) {
			$body = array();
		}

		$request = new WP_REST_Request($method, $route);
		foreach ($body as $key => $value) {
			$request->set_param((string) $key, $value);
		}
		$response = rest_do_request($request);
		$data     = rest_get_server()->response_to_data($response, false);

		wp_send_json_success(
			array(
				'status'  => $response->get_status(),
				'headers' => $response->get_headers(),
				'body'    => $data,
			)
		);
	}
}
