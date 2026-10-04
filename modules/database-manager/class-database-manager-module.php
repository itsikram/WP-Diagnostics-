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
	private const HISTORY_OPTION = 'wudt_db_query_history';

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_dbm_list_tables', array($this, 'ajax_list_tables'));
		add_action('wp_ajax_wudt_dbm_table_rows', array($this, 'ajax_table_rows'));
		add_action('wp_ajax_wudt_dbm_run_query', array($this, 'ajax_run_query'));
		add_action('wp_ajax_wudt_dbm_export', array($this, 'ajax_export'));
		add_action('wp_ajax_wudt_dbm_maintain', array($this, 'ajax_maintain'));
		add_action('wp_ajax_wudt_dbm_toggle_safe_mode', array($this, 'ajax_toggle_safe_mode'));
		// phpMyAdmin-like endpoints.
		add_action('wp_ajax_diagnostics_db_tables', array($this, 'ajax_pm_tables'));
		add_action('wp_ajax_diagnostics_db_browse', array($this, 'ajax_pm_browse'));
		add_action('wp_ajax_diagnostics_db_structure', array($this, 'ajax_pm_structure'));
		add_action('wp_ajax_diagnostics_db_query', array($this, 'ajax_pm_query'));
		add_action('wp_ajax_diagnostics_db_insert', array($this, 'ajax_pm_insert'));
		add_action('wp_ajax_diagnostics_db_update', array($this, 'ajax_pm_update'));
		add_action('wp_ajax_diagnostics_db_delete', array($this, 'ajax_pm_delete'));
		add_action('wp_ajax_diagnostics_db_export', array($this, 'ajax_pm_export'));
		add_action('wp_ajax_diagnostics_db_operations', array($this, 'ajax_pm_operations'));
	}

	public function get_key(): string {
		return 'database_manager';
	}

	public function get_label(): string {
		return __('Database Manager', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'safe_mode'   => (bool) get_option(self::SAFE_MODE_OPTION, true),
			'allow_drop'  => (bool) get_option(self::ALLOW_DROP_OPTION, false),
			'table_stats' => $this->get_table_stats(),
			'query_history' => array_slice((array) get_option(self::HISTORY_OPTION, array()), -50),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_table_stats_report(): array {
		return $this->get_table_stats();
	}

	/**
	 * Execute table optimize/repair.
	 *
	 * @param string $table Table name.
	 * @param string $action optimize|repair.
	 * @return array<int,array<string,mixed>>
	 */
	public function run_table_maintenance(string $table, string $action = 'optimize'): array {
		global $wpdb;
		$table_name = $this->validate_table_name_or_throw($table);
		$type       = 'repair' === $action ? 'repair' : 'optimize';
		$sql        = ('repair' === $type ? 'REPAIR TABLE ' : 'OPTIMIZE TABLE ') . '`' . esc_sql($table_name) . '`';
		$result     = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Operation_Logger::log('db', 'Table maintenance', array('table' => $table_name, 'action' => $type, 'source' => 'cli_or_service'));
		return is_array($result) ? $result : array();
	}

	/**
	 * @param string $query SQL query.
	 * @return array<string,mixed>
	 */
	public function run_safe_query(string $query): array {
		global $wpdb;
		$trimmed = trim($query);
		if (! $this->is_safe_select_query($trimmed)) {
			throw new \RuntimeException('Only safe read-only queries are allowed.');
		}
		$start = microtime(true);
		$rows  = $wpdb->get_results($trimmed, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$time  = round((microtime(true) - $start) * 1000, 2);
		Operation_Logger::log('db', 'SELECT query executed', array('query' => $trimmed, 'time_ms' => $time, 'source' => 'cli_or_service'));
		return array(
			'rows'    => is_array($rows) ? $rows : array(),
			'time_ms' => $time,
		);
	}

	public function ajax_list_tables(): void {
		$this->auth();
		wp_send_json_success(array('tables' => $this->get_table_stats()));
	}

	public function ajax_table_rows(): void {
		$this->auth();
		global $wpdb;
		$table   = $this->sanitize_table_name(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$page    = max(1, (int) ($_POST['page'] ?? 1));
		$perpage = min(200, max(10, (int) ($_POST['per_page'] ?? 50)));
		$offset  = ($page - 1) * $perpage;
		$search  = sanitize_text_field(sanitize_text_field(wp_unslash($_POST['search'] ?? '')));

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
		$query = trim((string) wp_unslash($_POST['query'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- SQL typed by an administrator in the database manager; validated and run in safe mode.
		try {
			wp_send_json_success($this->run_safe_query($query));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
		}
	}

	public function ajax_export(): void {
		$this->auth();
		global $wpdb;
		$table  = $this->sanitize_table_name(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
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
		$table  = $this->sanitize_table_name(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$action = sanitize_text_field((string) ($_POST['db_action'] ?? 'optimize'));
		try {
			$result = $this->run_table_maintenance($table, $action);
			wp_send_json_success(array('result' => $result));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
		}
	}

	public function ajax_toggle_safe_mode(): void {
		$this->auth();
		$safe = isset($_POST['safe_mode']) && '1' === sanitize_text_field(wp_unslash($_POST['safe_mode']));
		update_option(self::SAFE_MODE_OPTION, $safe);
		wp_send_json_success(array('safe_mode' => $safe));
	}

	public function ajax_pm_tables(): void {
		$this->auth();
		$tables = $this->get_table_stats();
		$rows   = array();
		foreach ($tables as $table) {
			$rows[] = array(
				'name'        => (string) ($table['Name'] ?? ''),
				'rows'        => (int) ($table['Rows'] ?? 0),
				'size'        => (int) ($table['Data_length'] ?? 0) + (int) ($table['Index_length'] ?? 0),
				'overhead'    => (int) ($table['Data_free'] ?? 0),
				'engine'      => (string) ($table['Engine'] ?? ''),
				'collation'   => (string) ($table['Collation'] ?? ''),
				'updated'     => (string) ($table['Update_time'] ?? ''),
			);
		}
		wp_send_json_success(
			array(
				'tables'       => $rows,
				'safe_mode'    => (bool) get_option(self::SAFE_MODE_OPTION, true),
				'query_history'=> array_slice((array) get_option(self::HISTORY_OPTION, array()), -50),
			)
		);
	}

	public function ajax_pm_browse(): void {
		$this->auth();
		global $wpdb;
		$table      = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$page       = max(1, (int) ($_POST['page'] ?? 1));
		$requested  = (int) ($_POST['per_page'] ?? 20);
		$per_page   = 0 === $requested ? 0 : min(100, max(20, $requested));
		$offset     = 0 === $per_page ? 0 : (($page - 1) * $per_page);
		$search     = sanitize_text_field(sanitize_text_field(wp_unslash($_POST['search'] ?? '')));
		$sort_by    = sanitize_text_field(sanitize_text_field(wp_unslash($_POST['sort_by'] ?? '')));
		$sort_dir   = strtoupper(sanitize_text_field((string) ($_POST['sort_dir'] ?? 'ASC')));
		$columns    = $this->get_table_structure($table);
		$col_names  = array_map(static fn( $c ): string => (string) ($c['Field'] ?? ''), $columns);

		$where = '';
		if ('' !== $search && ! empty($col_names)) {
			$parts = array();
			foreach ($col_names as $col) {
				$parts[] = '`' . esc_sql($col) . "` LIKE '%" . esc_sql($wpdb->esc_like($search)) . "%'";
			}
			$where = ' WHERE ' . implode(' OR ', $parts);
		}
		$order = '';
		if (in_array($sort_by, $col_names, true)) {
			$order = ' ORDER BY `' . esc_sql($sort_by) . '` ' . ('DESC' === $sort_dir ? 'DESC' : 'ASC');
		}

		$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`' . $where); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql   = 'SELECT * FROM `' . esc_sql($table) . '`' . $where . $order;
		if ($per_page > 0) {
			$sql .= " LIMIT {$offset},{$per_page}";
		}
		$rows  = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_send_json_success(
			array(
				'rows'        => is_array($rows) ? $rows : array(),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'columns'     => $columns,
				'primary_key' => $this->detect_primary_column($columns),
			)
		);
	}

	public function ajax_pm_structure(): void {
		$this->auth();
		$table = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		wp_send_json_success(array('structure' => $this->get_table_structure($table)));
	}

	public function ajax_pm_query(): void {
		$this->auth();
		global $wpdb;
		$query = trim((string) wp_unslash($_POST['query'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- SQL typed by an administrator in the database manager; validated and run in safe mode.
		try {
			$this->validate_sql_for_console($query);
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 400);
		}
		$start = microtime(true);
		$is_select = (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query);
		if ($is_select) {
			$data = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$data = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$elapsed = round((microtime(true) - $start) * 1000, 2);
		$this->push_query_history($query, $elapsed, true);
		Operation_Logger::log('db', 'SQL executed from phpmyadmin UI', array('query' => $query, 'time_ms' => $elapsed));
		wp_send_json_success(
			array(
				'result'  => $data,
				'time_ms' => $elapsed,
				'slow'    => $elapsed > 500,
				'type'    => $is_select ? 'select' : 'write',
				'history' => array_slice((array) get_option(self::HISTORY_OPTION, array()), -50),
			)
		);
	}

	public function ajax_pm_insert(): void {
		$this->auth();
		global $wpdb;
		$table = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$data  = isset($_POST['data']) ? json_decode((string) wp_unslash($_POST['data']), true) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- row values are stored exactly as entered; written with $wpdb->insert/update placeholders.
		if (! is_array($data) || empty($data)) {
			wp_send_json_error(array('message' => 'Insert payload missing.'), 400);
		}
		$result = $wpdb->insert($table, $this->sanitize_row_payload($data)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if (false === $result) {
			wp_send_json_error(array('message' => $wpdb->last_error ?: 'Insert failed'), 400);
		}
		Operation_Logger::log('db', 'Row inserted', array('table' => $table));
		wp_send_json_success(array('insert_id' => $wpdb->insert_id));
	}

	public function ajax_pm_update(): void {
		$this->auth();
		global $wpdb;
		$table = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$data  = isset($_POST['data']) ? json_decode((string) wp_unslash($_POST['data']), true) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- row values are stored exactly as entered; written with $wpdb->insert/update placeholders.
		$where = isset($_POST['where']) ? json_decode((string) wp_unslash($_POST['where']), true) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- row values are stored exactly as entered; written with $wpdb->insert/update placeholders.
		if (! is_array($data) || ! is_array($where) || empty($where)) {
			wp_send_json_error(array('message' => 'Update payload invalid.'), 400);
		}
		$result = $wpdb->update($table, $this->sanitize_row_payload($data), $this->sanitize_row_payload($where)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if (false === $result) {
			wp_send_json_error(array('message' => $wpdb->last_error ?: 'Update failed'), 400);
		}
		Operation_Logger::log('db', 'Row updated', array('table' => $table, 'affected' => (int) $result));
		wp_send_json_success(array('affected' => (int) $result));
	}

	public function ajax_pm_delete(): void {
		$this->auth();
		global $wpdb;
		if ((bool) get_option(self::SAFE_MODE_OPTION, true)) {
			wp_send_json_error(array('message' => 'Safe mode is enabled. Deletion blocked.'), 400);
		}
		$table = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$where = isset($_POST['where']) ? json_decode((string) wp_unslash($_POST['where']), true) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- row values are stored exactly as entered; written with $wpdb->insert/update placeholders.
		if (! is_array($where) || empty($where)) {
			wp_send_json_error(array('message' => 'Delete requires WHERE conditions.'), 400);
		}
		$result = $wpdb->delete($table, $this->sanitize_row_payload($where)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if (false === $result) {
			wp_send_json_error(array('message' => $wpdb->last_error ?: 'Delete failed'), 400);
		}
		Operation_Logger::log('db', 'Row deleted', array('table' => $table, 'affected' => (int) $result));
		wp_send_json_success(array('affected' => (int) $result));
	}

	public function ajax_pm_export(): void {
		$this->auth();
		global $wpdb;
		$table       = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$format      = sanitize_text_field((string) ($_POST['format'] ?? 'sql'));
		$compression = isset($_POST['compression']) ? sanitize_text_field((string) ($_POST['compression'])) : '';
		$up          = wp_get_upload_dir();
		$dir         = trailingslashit($up['basedir']) . 'wudt-db-exports/';
		wp_mkdir_p($dir);
		$base = sanitize_file_name($table . '-' . gmdate('Ymd-His'));
		$file = $dir . $base . '.' . $format;
		$rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($table) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->write_export_file($file, $format, $table, is_array($rows) ? $rows : array());
		$final_file = $file;

		if ('zip' === strtolower($compression) && class_exists('ZipArchive')) {
			$zip_file = $dir . $base . '.zip';
			$zip      = new \ZipArchive();
			if (true === $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
				$zip->addFile($file, basename($file));
				$zip->close();
				$final_file = $zip_file;
			}
		}
		Operation_Logger::log('db', 'Export generated', array('table' => $table, 'format' => $format, 'compression' => $compression));
		wp_send_json_success(array('url' => str_replace($up['basedir'], $up['baseurl'], $final_file)));
	}

	public function ajax_pm_operations(): void {
		$this->auth();
		global $wpdb;
		$table     = $this->sanitize_table_name_or_throw(sanitize_text_field(wp_unslash($_POST['table'] ?? '')));
		$operation = sanitize_text_field(sanitize_text_field(wp_unslash($_POST['operation'] ?? '')));
		$confirm   = isset($_POST['confirm']) && '1' === sanitize_text_field(wp_unslash($_POST['confirm']));

		if (in_array($operation, array('empty', 'drop'), true) && ! $confirm) {
			wp_send_json_error(array('message' => 'Confirmation required.'), 400);
		}

		if ('optimize' === $operation || 'repair' === $operation) {
			$result = $this->run_table_maintenance($table, $operation);
			wp_send_json_success(array('result' => $result));
		}
		if ('empty' === $operation) {
			if ((bool) get_option(self::SAFE_MODE_OPTION, true)) {
				wp_send_json_error(array('message' => 'Safe mode is enabled. TRUNCATE blocked.'), 400);
			}
			$wpdb->query('TRUNCATE TABLE `' . esc_sql($table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			Operation_Logger::log('db', 'Table truncated', array('table' => $table));
			wp_send_json_success(array('message' => 'Table emptied.'));
		}
		if ('drop' === $operation) {
			if (! (bool) get_option(self::ALLOW_DROP_OPTION, false)) {
				wp_send_json_error(array('message' => 'DROP TABLE is disabled in settings.'), 400);
			}
			$wpdb->query('DROP TABLE `' . esc_sql($table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			Operation_Logger::log('db', 'Table dropped', array('table' => $table));
			wp_send_json_success(array('message' => 'Table dropped.'));
		}
		wp_send_json_error(array('message' => 'Unsupported operation.'), 400);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_table_stats(): array {
		global $wpdb;
		$rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_table_structure(string $table): array {
		global $wpdb;
		$rows = $wpdb->get_results('SHOW COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array($rows) ? $rows : array();
	}

	private function auth(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'diagnostics-toolkit')), 403);
		}
	}

	private function sanitize_table_name(string $table): string {
		try {
			return $this->sanitize_table_name_or_throw($table);
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => __('Invalid table name.', 'diagnostics-toolkit')), 400);
		}
	}

	private function validate_query(string $query): void {
		if (! $this->is_safe_select_query($query)) {
			wp_send_json_error(array('message' => __('Only SELECT/SHOW/DESCRIBE/EXPLAIN queries are allowed.', 'diagnostics-toolkit')), 400);
		}
	}

	private function is_safe_select_query(string $query): bool {
		$safe_mode = (bool) get_option(self::SAFE_MODE_OPTION, true);
		$trimmed   = ltrim($query);
		if (! preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $trimmed)) {
			return false;
		}
		if (preg_match('/\bDROP\s+DATABASE\b/i', $trimmed)) {
			return false;
		}
		if ($safe_mode && preg_match('/\bDROP\s+TABLE\b/i', $trimmed)) {
			return false;
		}
		return true;
	}

	private function validate_sql_for_console(string $query): void {
		$trimmed = trim($query);
		$safe    = (bool) get_option(self::SAFE_MODE_OPTION, true);
		if (preg_match('/\bDROP\s+DATABASE\b/i', $trimmed)) {
			throw new \RuntimeException('DROP DATABASE is blocked.');
		}
		if ($safe && preg_match('/\b(DROP|TRUNCATE|ALTER)\b/i', $trimmed)) {
			throw new \RuntimeException('Safe mode blocks DROP/TRUNCATE/ALTER statements.');
		}
		if ($safe && preg_match('/^\s*DELETE\s+FROM\b/i', $trimmed) && ! preg_match('/\bWHERE\b/i', $trimmed)) {
			throw new \RuntimeException('Unsafe DELETE without WHERE is blocked in safe mode.');
		}
	}

	private function validate_table_name_or_throw(string $table): string {
		return $this->sanitize_table_name_or_throw($table);
	}

	private function sanitize_table_name_or_throw(string $table): string {
		$table_name = sanitize_text_field(wp_unslash($table));
		if (! preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
			throw new \RuntimeException('Invalid table name.');
		}
		return $table_name;
	}

	private function detect_primary_column(array $structure): string {
		foreach ($structure as $column) {
			if (isset($column['Key']) && 'PRI' === $column['Key']) {
				return (string) $column['Field'];
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $payload Raw row payload.
	 * @return array<string,mixed>
	 */
	private function sanitize_row_payload(array $payload): array {
		$out = array();
		foreach ($payload as $key => $value) {
			$out[ sanitize_key((string) $key) ] = is_scalar($value) || null === $value ? $value : wp_json_encode($value);
		}
		return $out;
	}

	private function push_query_history(string $query, float $time_ms, bool $success): void {
		$history   = (array) get_option(self::HISTORY_OPTION, array());
		$history[] = array(
			'time'     => current_time('mysql'),
			'query'    => $query,
			'time_ms'  => $time_ms,
			'success'  => $success,
			'slow'     => $time_ms > 500,
		);
		update_option(self::HISTORY_OPTION, array_slice($history, -50), false);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function write_export_file(string $file, string $format, string $table, array $rows): void {
		$format = strtolower($format);
		if ('csv' === $format) {
			$fh = fopen($file, 'w');
			if (! empty($rows)) {
				fputcsv($fh, array_keys($rows[0]));
			}
			foreach ($rows as $row) {
				fputcsv($fh, $row);
			}
			fclose($fh);
			return;
		}
		if ('json' === $format) {
			file_put_contents($file, (string) wp_json_encode($rows, JSON_PRETTY_PRINT)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return;
		}
		$sql = "-- Export: {$table}\n";
		foreach ($rows as $row) {
			$columns = array_map(static fn( $col ): string => '`' . esc_sql((string) $col) . '`', array_keys($row));
			$values  = array_map(static fn( $value ): string => "'" . esc_sql((string) $value) . "'", array_values($row));
			$sql    .= 'INSERT INTO `' . esc_sql($table) . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
		}
		file_put_contents($file, $sql); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
