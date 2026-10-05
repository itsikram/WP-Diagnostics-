<?php
/**
 * Centralized operation logging.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Operation_Logger {
	private const OPTION_KEY = 'wudt_operation_logs';

	/**
	 * @param array<string,mixed> $context Context.
	 */
	public static function log(string $type, string $message, array $context = array()): void {
		global $wpdb;

		// During database restore, wp_options may not exist - check first
		if (!isset($wpdb) || !$wpdb->ready) {
			return;
		}

		// Suppress database errors during check to prevent race condition output
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			// Silently skip logging during database restore when tables don't exist
			return;
		}

		$entries   = (array) get_option(self::OPTION_KEY, array());
		$entries[] = array(
			'time'    => current_time('mysql'),
			'user'    => get_current_user_id(),
			'type'    => sanitize_key($type),
			'message' => sanitize_text_field($message),
			'context' => $context,
		);
		if (count($entries) > 1000) {
			$entries = array_slice($entries, -1000);
		}
		update_option(self::OPTION_KEY, $entries, false);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_logs(): array {
		return (array) get_option(self::OPTION_KEY, array());
	}
}
