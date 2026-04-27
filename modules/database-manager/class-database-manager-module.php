<?php
/**
 * Safe native database manager.
 */

declare(strict_types=1);

namespace WUDT\Modules\DatabaseManager;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Database_Manager_Module extends Module_Base {
	private const SAFE_MODE_OPTION = 'wudt_db_safe_mode';
	private const ALLOW_DROP_OPTION = 'wudt_db_allow_drop_table';

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_dbm_list_tables', array($this, 'ajax_list_tables'));
		add_action('wp_ajax_wudt_dbm_table_rows', array($this, 'ajax_table_rows'));
		add_action('wp_ajax_wudt_dbm_run_query', array($this, 'ajax_run_query'));
		add_action('wp_ajax_wudt_dbm_export', array($this, 'ajax_export'));
		add_action('wp_ajax_wudt_dbm_maintain', array($this, 'ajax_maintain'));
		add_action('wp_ajax_wudt_dbm_toggle_safe_mode', array($this, 'ajax_toggle_safe_mode'));
	}

	public function get_key(): string {
		return 'database_manager';
	}

	public function get_label(): string {
		return __('Database Manager', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'safe_mode'   => (bool) get_option(self::SAFE_MODE_OPTION, true),
			'allow_drop'  => (bool) get_option(self::ALLOW_DROP_OPTION, false),
			'table_stats' => $this->get_table_stats(),
		);
	}

	public function ajax_list_tables(): void {
		$this->auth();
		wp_send_json_success(array('tables' => $this->get_table_stats()));
	}

	public function ajax_table_rows(): void {
		$this->auth();
		global $wpdb;
		$table   = $this->sanitize_table_name((string) ($_POST['table'] ?? ''));
		$page    = max(1, (int) ($_POST['page'] ?? 1));
		$perpage = min(200, max(10, (int) ($_POST['per_page'] ?? 50)));
		$offset  = ($page - 1) * $perpage;
		$search  = sanitize_text_field((string) ($_POST['search'] ?? ''));

		$columns = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($table) . '`', 0); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$where   = '';
		if (! empty($search) && ! empty($columns)) {
			$parts = array();
			foreach ($columns as $column) {
				$parts[] = '`' . esc_sql((string) $column) . "` LIKE '%" . esc_sql($wpdb->esc_like($search)) . "%'";
			}
			$where = ' WHERE ' . implode(' OR ', $parts);
		}
		$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`' . $where); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '`' . $where . " LIMIT {$offset},{$perpage}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_send_json_success(array('rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perpage));
	}

	public function ajax_run_query(): void {
		$this->auth();
		global $wpdb;
		$query = trim((string) wp_unslash($_POST['query'] ?? ''));
		$this->validate_query($query);
		$start  = microtime(true);
		$result = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$time   = round((microtime(true) - $start) * 1000, 2);
		Operation_Logger::log('db', 'SELECT query executed', array('query' => $query, 'time_ms' => $time));
		wp_send_json_success(array('rows' => $result, 'time_ms' => $time));
	}

	public function ajax_export(): void {
		$this->auth();
		global $wpdb;
		$table  = $this->sanitize_table_name((string) ($_POST['table'] ?? ''));
		$format = sanitize_text_field((string) ($_POST['format'] ?? 'sql'));
		$up     = wp_get_upload_dir();
		$dir    = trailingslashit($up['basedir']) . 'wudt-db-exports/';
		wp_mkdir_p($dir);
		$file = $dir . sanitize_file_name($table . '-' . gmdate('Ymd-His') . '.' . $format);
		$rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ('csv' === $format) {
			$fh = fopen($file, 'w');
			if (! empty($rows)) {
				fputcsv($fh, array_keys($rows[0]));
			}
			foreach ((array) $rows as $row) {
				fputcsv($fh, $row);
			}
			fclose($fh);
		} else {
			$sql = "-- Export: {$table}\n";
			foreach ((array) $rows as $row) {
				$columns = array_map(static fn( $col ): string => '`' . esc_sql((string) $col) . '`', array_keys($row));
				$values  = array_map(static fn( $value ): string => "'" . esc_sql((string) $value) . "'", array_values($row));
				$sql    .= 'INSERT INTO `' . esc_sql($table) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
			}
			file_put_contents($file, $sql); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		Operation_Logger::log('db', 'Table exported', array('table' => $table, 'format' => $format));
		wp_send_json_success(array('url' => str_replace($up['basedir'], $up['baseurl'], $file)));
	}

	public function ajax_maintain(): void {
		$this->auth();
		global $wpdb;
		$table  = $this->sanitize_table_name((string) ($_POST['table'] ?? ''));
		$action = sanitize_text_field((string) ($_POST['db_action'] ?? 'optimize'));
		$sql    = ('repair' === $action ? 'REPAIR TABLE ' : 'OPTIMIZE TABLE ') . '`' . esc_sql($table) . '`';
		$result = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Operation_Logger::log('db', 'Table maintenance', array('table' => $table, 'action' => $action));
		wp_send_json_success(array('result' => $result));
	}

	public function ajax_toggle_safe_mode(): void {
		$this->auth();
		$safe = isset($_POST['safe_mode']) && '1' === (string) $_POST['safe_mode'];
		update_option(self::SAFE_MODE_OPTION, $safe);
		wp_send_json_success(array('safe_mode' => $safe));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_table_stats(): array {
		global $wpdb;
		$rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	private function auth(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
	}

	private function sanitize_table_name(string $table): string {
		$table = sanitize_text_field(wp_unslash($table));
		if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
			wp_send_json_error(array('message' => __('Invalid table name.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		return $table;
	}

	private function validate_query(string $query): void {
		$safe_mode = (bool) get_option(self::SAFE_MODE_OPTION, true);
		$trimmed   = ltrim($query);
		if (! preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $trimmed)) {
			wp_send_json_error(array('message' => __('Only SELECT/SHOW/DESCRIBE/EXPLAIN queries are allowed.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		if (preg_match('/\bDROP\s+DATABASE\b/i', $trimmed)) {
			wp_send_json_error(array('message' => __('DROP DATABASE is blocked.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
		if ($safe_mode && preg_match('/\bDROP\s+TABLE\b/i', $trimmed)) {
			wp_send_json_error(array('message' => __('DROP TABLE is blocked in Safe Mode.', 'wp-ultimate-diagnostics-toolkit')), 400);
		}
	}
}
