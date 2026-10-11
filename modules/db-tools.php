<?php
/**
 * Database diagnostics module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never printed as HTML: they are logged or returned with wp_send_json_*(), and the admin scripts escape them when displaying them.

class DB_Tools_Module extends Module_Base {
	public function register_hooks(): void {
		add_action('wp_ajax_wudt_db_action', array($this, 'ajax_db_action'));
	}

	public function get_key(): string {
		return 'db_tools';
	}

	public function get_label(): string {
		return __('Database', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		global $wpdb;
		$tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
		if (! is_array($tables)) {
			$tables = array();
		}
		$large_options = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} ORDER BY size DESC LIMIT 20",
			ARRAY_A
		);

		return array(
			'tables'        => $tables,
			'large_options' => is_array($large_options) ? $large_options : array(),
		);
	}

	public function ajax_db_action(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'diagnostics-toolkit')), 403);
		}
		$action = isset($_POST['db_action']) ? sanitize_key(wp_unslash($_POST['db_action'])) : '';
		$table  = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
		try {
			$result = self::maintain($table, $action);
		} catch (\RuntimeException $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
		}
		wp_send_json_success(array('result' => $result));
	}

	/**
	 * Names of this site's database tables.
	 *
	 * @return array<int,string>
	 */
	public static function table_names(): array {
		global $wpdb;
		$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->base_prefix) . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map('strval', is_array($tables) ? $tables : array());
	}

	/**
	 * Optimize or repair one of this site's tables.
	 *
	 * @throws \RuntimeException When the table is not one of this site's tables.
	 * @return array<int,array<string,mixed>>
	 */
	public static function maintain(string $table, string $action): array {
		global $wpdb;
		if (! in_array($table, self::table_names(), true)) {
			throw new \RuntimeException(__('Unknown table.', 'diagnostics-toolkit'));
		}
		// The table name was checked against SHOW TABLES above; identifiers cannot be placeholders.
		$sql    = ('repair' === $action ? 'REPAIR TABLE `' : 'OPTIMIZE TABLE `') . esc_sql($table) . '`';
		$result = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		\WUDT\Includes\Operation_Logger::log('db', 'Table maintenance', array('table' => $table, 'action' => 'repair' === $action ? 'repair' : 'optimize'));
		return is_array($result) ? $result : array();
	}
}
