<?php
/**
 * Request Sampler - decides whether this request records performance samples.
 *
 * Samples are stored in options (tens of KB each), so writing them on every
 * request turns busy moments (WP-Cron, polling, migrations) into a stream of
 * large database writes. This keeps it to at most one sampled request every
 * few seconds and skips machine traffic entirely.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Request_Sampler {
	// Seconds between sampled requests.
	private const INTERVAL = 15;

	private static ?bool $decision = null;

	/**
	 * Whether to record samples for the current request (the same answer for
	 * every module during one request).
	 */
	public static function should_sample(): bool {
		if (null === self::$decision) {
			self::$decision = self::decide();
		}
		return self::$decision;
	}

	private static function decide(): bool {
		if (wp_doing_cron() || (defined('WP_INSTALLING') && WP_INSTALLING) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
			return false;
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return false;
		}
		if (wp_doing_ajax()) {
			$action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			// Polling, migrations and scans: frequent machine requests, not page loads.
			if ('heartbeat' === $action || 0 === strpos($action, 'wudt_')) {
				return false;
			}
		}
		if (! (bool) apply_filters('wudt_record_request_samples', true)) {
			return false;
		}
		// A file timestamp shared by all requests: no database read or write to decide.
		$stamp = trailingslashit(get_temp_dir()) . 'wudt-sample-' . md5(ABSPATH) . '.stamp';
		$last = @filemtime($stamp);
		if (false !== $last && time() - $last < self::INTERVAL) {
			return false;
		}
		@touch($stamp);
		return true;
	}
}
