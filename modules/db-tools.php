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

class DB_Tools_Module extends Module_Base {
	public function register_hooks(): void {
		add_action('wp_ajax_wudt_db_action', array($this, 'ajax_db_action'));
	}

	public function get_key(): string {
		return 'db_tools';
	}

	public function get_label(): string {
		return __('Database', 'wp-ultimate-diagnostics-toolkit');
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
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		global $wpdb;
		$action = isset($_POST['db_action']) ? sanitize_text_field((string) wp_unslash($_POST['db_action'])) : '';
		$table  = isset($_POST['table']) ? sanitize_text_field((string) wp_unslash($_POST['table'])) : '';
		if ('' === $table) {
			wp_send_json_error(array('message' => __('Missing table parameter.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		$this->backup_table($table);

		$table_sql = '`' . esc_sql($table) . '`';
		if ('repair' === $action) {
			$result = $wpdb->get_results("REPAIR TABLE {$table_sql}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$result = $wpdb->get_results("OPTIMIZE TABLE {$table_sql}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		wp_send_json_success(array('result' => $result));
	}

	private function backup_table(string $table): void {
		global $wpdb;
		$upload_dir = wp_get_upload_dir();
		$dir        = trailingslashit($upload_dir['basedir']) . 'wudt-db-backups/';
		if (! wp_mkdir_p($dir)) {
			return;
		}
		$file = $dir . sanitize_file_name($table) . '-' . gmdate('Ymd-His') . '.sql';
		$sql  = "-- Backup {$table}\n";
		$row  = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (is_array($row) && isset($row[1])) {
			$sql .= $row[1] . ";\n\n";
		}
		$rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (is_array($rows)) {
			foreach ($rows as $data) {
				$columns = array_map(static fn( $col ): string => '`' . esc_sql((string) $col) . '`', array_keys($data));
				$values  = array_map(static fn( $val ): string => "'" . esc_sql((string) $val) . "'", array_values($data));
				$sql    .= 'INSERT INTO `' . esc_sql($table) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
			}
		}
		file_put_contents($file, $sql); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
