<?php
/**
 * Database Search Engine
 * Batched, prepared searches across WordPress tables
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class DB_Search
 *
 * Plain and whole-word searches are narrowed with LIKE in SQL; regex searches
 * scan rows in batches in PHP so PCRE syntax behaves exactly as in file search.
 */
class DB_Search {
	/**
	 * Options written by this tool itself, never searched or replaced.
	 */
	private const OWN_OPTIONS = array('wudt_search_history', 'wudt_operation_logs');

	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private array $results = array();

	private int $match_count  = 0;
	private int $rows_scanned = 0;
	private string $truncated = '';
	private float $started_at = 0.0;

	/**
	 * Per-request caches.
	 * @var array<string,mixed>
	 */
	private array $column_cache = array();
	private array $key_cache    = array();
	private ?array $table_list  = null;

	/**
	 * @param array<string,mixed> $options Search configuration
	 */
	public function __construct(array $options = array()) {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->options = wp_parse_args(
			$options,
			array(
				'tables'           => array(), // Empty = all tables with the site prefix.
				'columns'          => array(), // Empty = all text columns.
				'case_sensitive'   => false,
				'whole_word'       => false,
				'regex'            => false,
				'max_results'      => 1000,
				'batch_size'       => 500,
				'max_rows_scanned' => 300000, // Regex mode only.
				'time_limit'       => 25,
			)
		);
	}

	/**
	 * Search for text in the database.
	 *
	 * @param string              $search_text Text or pattern to search for
	 * @param array<string,mixed> $options     Overrides for this search
	 * @return array<string,mixed>
	 */
	public function search(string $search_text, array $options = array()): array {
		$opts = wp_parse_args($options, $this->options);

		try {
			$matcher = new Search_Matcher($search_text, $opts);
		} catch (\InvalidArgumentException $e) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
				'results' => array(),
			);
		}

		$this->results      = array();
		$this->match_count  = 0;
		$this->rows_scanned = 0;
		$this->truncated    = '';
		$this->started_at   = microtime(true);

		$tables          = $this->resolve_tables((array) $opts['tables']);
		$tables_searched = 0;
		$tables_matched  = array();

		foreach ($tables as $table) {
			if ('' !== $this->truncated) {
				break;
			}
			$before = count($this->results);
			$this->search_table($table, $matcher, $opts);
			$tables_searched++;
			if (count($this->results) > $before) {
				$tables_matched[ $table ] = true;
			}
		}

		return array(
			'success'         => true,
			'results'         => $this->results,
			'match_count'     => $this->match_count,
			'rows_matched'    => count($this->results),
			'tables_searched' => $tables_searched,
			'tables_matched'  => count($tables_matched),
			'truncated'       => $this->truncated,
			'duration'        => round(microtime(true) - $this->started_at, 2),
		);
	}

	/**
	 * Only tables that really exist and carry this site's prefix can be searched.
	 *
	 * @param array<int,string> $requested
	 * @return array<int,string>
	 */
	private function resolve_tables(array $requested): array {
		$all = $this->get_table_names();
		if (empty($requested)) {
			return $all;
		}
		return array_values(array_intersect($all, $requested));
	}

	/**
	 * @return array<int,string>
	 */
	public function get_table_names(): array {
		if (null === $this->table_list) {
			$tables = $this->wpdb->get_col(
				$this->wpdb->prepare('SHOW TABLES LIKE %s', $this->wpdb->esc_like($this->wpdb->prefix) . '%')
			);
			$this->table_list = array_values(array_filter(array_map('strval', (array) $tables)));
		}
		return $this->table_list;
	}

	public function is_valid_table(string $table): bool {
		return in_array($table, $this->get_table_names(), true);
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function search_table(string $table, Search_Matcher $matcher, array $opts): void {
		$columns = $this->get_text_columns($table);
		if (! empty($opts['columns'])) {
			$columns = array_values(array_intersect($columns, (array) $opts['columns']));
		}
		if (empty($columns)) {
			return;
		}

		$primary_key = $this->get_primary_key($table);

		foreach ($columns as $column) {
			if ($column === $primary_key) {
				continue;
			}
			$this->search_column($table, $column, $primary_key, $matcher, $opts);
			if ('' !== $this->truncated) {
				return;
			}
		}
	}

	/**
	 * Text-like columns of a table.
	 *
	 * @return array<int,string>
	 */
	public function get_text_columns(string $table): array {
		if (isset($this->column_cache[ $table ])) {
			return $this->column_cache[ $table ];
		}

		$columns = array();
		if ($this->is_valid_table($table)) {
			$info = (array) $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table validated against SHOW TABLES.
			foreach ($info as $col) {
				if (preg_match('/char|text|enum|set|json/i', (string) $col['Type'])) {
					$columns[] = (string) $col['Field'];
				}
			}
		}

		$this->column_cache[ $table ] = $columns;
		return $columns;
	}

	/**
	 * Single-column primary key, or null when the table has none (or a composite one).
	 */
	public function get_primary_key(string $table): ?string {
		if (array_key_exists($table, $this->key_cache)) {
			return $this->key_cache[ $table ];
		}

		$key = null;
		if ($this->is_valid_table($table)) {
			$keys = (array) $this->wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table validated against SHOW TABLES.
			if (1 === count($keys)) {
				$key = (string) $keys[0]['Column_name'];
			}
		}

		$this->key_cache[ $table ] = $key;
		return $key;
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function search_column(string $table, string $column, ?string $primary_key, Search_Matcher $matcher, array $opts): void {
		$batch  = max(50, (int) $opts['batch_size']);
		$cursor = null;
		$offset = 0;

		$select = $primary_key ? "`{$primary_key}` AS __pk, `{$column}` AS __val" : "`{$column}` AS __val";
		$order  = $primary_key ? "ORDER BY `{$primary_key}`" : '';

		if ($matcher->is_regex()) {
			$where = "`{$column}` IS NOT NULL AND `{$column}` <> ''";
			$args  = array();
		} else {
			$where = "`{$column}` LIKE %s";
			$args  = array('%' . $this->wpdb->esc_like($matcher->get_search()) . '%');
		}

		// This tool stores recent searches and logs in options; they would otherwise match every query.
		if ($table === $this->wpdb->options) {
			$where .= ' AND `option_name` NOT IN (' . implode(',', array_fill(0, count(self::OWN_OPTIONS), '%s')) . ')';
			$args   = array_merge($args, self::OWN_OPTIONS);
		}

		while (true) {
			$sql_where = $where;
			$sql_args  = $args;
			if ($primary_key && null !== $cursor) {
				$sql_where .= " AND `{$primary_key}` > %s";
				$sql_args[] = $cursor;
			}
			$limit      = $primary_key ? 'LIMIT %d' : 'LIMIT %d OFFSET %d';
			$sql_args[] = $batch;
			if (! $primary_key) {
				$sql_args[] = $offset;
			}

			$sql  = "SELECT {$select} FROM `{$table}` WHERE {$sql_where} {$order} {$limit}";
			$rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $sql_args), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers validated, values prepared.

			if (empty($rows)) {
				return;
			}

			foreach ($rows as $row) {
				$this->rows_scanned++;
				$value = (string) $row['__val'];

				if ($matcher->matches($value)) {
					$this->add_result($table, $column, $primary_key, $row['__pk'] ?? null, $value, $matcher);
					if (count($this->results) >= (int) $opts['max_results']) {
						$this->truncated = 'limit';
						return;
					}
				}
			}

			if (count($rows) < $batch) {
				return;
			}

			$cursor  = $primary_key ? (string) end($rows)['__pk'] : null;
			$offset += $batch;

			if (microtime(true) - $this->started_at > (float) $opts['time_limit']) {
				$this->truncated = 'time';
				return;
			}
			if ($matcher->is_regex() && $this->rows_scanned >= (int) $opts['max_rows_scanned']) {
				$this->truncated = 'rows';
				return;
			}
		}
	}

	/**
	 * @param mixed $row_id
	 */
	private function add_result(string $table, string $column, ?string $primary_key, $row_id, string $value, Search_Matcher $matcher): void {
		$count              = max(1, $matcher->count($value));
		$segmented          = $matcher->segments($value, 360);
		$this->match_count += $count;

		$this->results[] = array(
			'table'       => $table,
			'column'      => $column,
			'primary_key' => $primary_key,
			'row_id'      => null === $row_id ? null : (string) $row_id,
			'match_count' => $count,
			'length'      => strlen($value),
			'serialized'  => is_serialized($value, false),
			'segments'    => $segmented['segments'],
			'cut_start'   => $segmented['cut_start'],
			'cut_end'     => $segmented['cut_end'],
			'edit_url'    => null === $row_id ? '' : $this->edit_url($table, (string) $row_id),
		);
	}

	/**
	 * Admin link for rows that have an edit screen.
	 */
	private function edit_url(string $table, string $row_id): string {
		$id = absint($row_id);
		if (! $id) {
			return '';
		}
		switch ($table) {
			case $this->wpdb->posts:
				return admin_url('post.php?post=' . $id . '&action=edit');
			case $this->wpdb->comments:
				return admin_url('comment.php?action=editcomment&c=' . $id);
			case $this->wpdb->users:
				return admin_url('user-edit.php?user_id=' . $id);
		}
		return '';
	}

	/**
	 * Current value of one cell, or null if the row/column can't be addressed.
	 */
	public function fetch_value(string $table, string $column, string $row_id): ?string {
		$primary_key = $this->get_primary_key($table);
		if (! $primary_key || ! in_array($column, $this->get_text_columns($table), true)) {
			return null;
		}
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$primary_key}` = %s LIMIT 1", $row_id) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers validated.
		);
		return null === $value ? null : (string) $value;
	}

	/**
	 * All tables with this site's prefix, with row counts and sizes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tables_info(): array {
		$tables = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_count, (DATA_LENGTH + INDEX_LENGTH) AS size
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s
				ORDER BY TABLE_NAME',
				$this->wpdb->esc_like($this->wpdb->prefix) . '%'
			),
			ARRAY_A
		);

		return $tables ?: array();
	}

	/**
	 * Columns for a specific table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_table_columns_info(string $table): array {
		if (! $this->is_valid_table($table)) {
			return array();
		}
		$columns = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table validated.
		return $columns ?: array();
	}
}
