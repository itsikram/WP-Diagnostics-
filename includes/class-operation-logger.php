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
