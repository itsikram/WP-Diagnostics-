<?php
/**
 * External requests monitor module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class External_Requests_Module extends Module_Base {
	private const OPTION_KEY = 'wudt_external_request_log';

	public function register_hooks(): void {
		add_action('http_api_debug', array($this, 'capture_http_event'), 10, 5);
	}

	public function get_key(): string {
		return 'external_requests';
	}

	public function get_label(): string {
		return __('External Requests', 'wp-ultimate-diagnostics-toolkit');
	}

	/**
	 * @param mixed $response Response.
	 * @param string $type Type.
	 * @param string $class Class.
	 * @param array<string,mixed> $args Args.
	 * @param string $url Url.
	 */
	public function capture_http_event($response, string $type, string $class, array $args, string $url): void {
		if ('response' !== $type) {
			return;
		}
		$entry = array(
			'time'       => current_time('mysql'),
			'url'        => esc_url_raw($url),
			'method'     => strtoupper((string) ($args['method'] ?? 'GET')),
			'timeout'    => isset($args['timeout']) ? (float) $args['timeout'] : 0,
			'is_error'   => is_wp_error($response),
			'error'      => is_wp_error($response) ? $response->get_error_message() : '',
			'status'     => is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0,
			'slow'       => isset($args['timeout']) && (float) $args['timeout'] >= 5,
		);
		$logs    = (array) get_option(self::OPTION_KEY, array());
		$logs[]  = $entry;
		$logs    = array_slice($logs, -300);
		update_option(self::OPTION_KEY, $logs, false);
	}

	public function get_dashboard_data(): array {
		$logs = (array) get_option(self::OPTION_KEY, array());
		return array(
			'logs'  => $logs,
			'slow'  => array_values(array_filter($logs, static fn( $log ): bool => ! empty($log['slow']))),
			'fails' => array_values(array_filter($logs, static fn( $log ): bool => ! empty($log['is_error']) || ((int) ($log['status'] ?? 0) >= 400))),
		);
	}
}
