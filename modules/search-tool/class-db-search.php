<?php
/**
 * Database Search Engine
 * Safe, efficient database search across WordPress tables
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class DB_Search
 * 
 * Search across WordPress database tables with safe queries
 */
class DB_Search {
	/**
	 * @var \wpdb WordPress database object
	 */
	private $wpdb;

	/**
	 * Search options
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Results collection
	 * @var array<int,array<string,mixed>>
	 */
	private array $results = array();

	/**
	 * Maximum results per table
	 */
	private int $max_results_per_table = 500;

	/**
	 * Total maximum results
	 */
	private int $max_total_results = 2000;

	/**
	 * @param array<string,mixed> $options Search configuration
	 */
	public function __construct(array $options = array()) {
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->options = wp_parse_args(
			$options,
			array(
				'tables'         => array(), // Empty = all tables
				'columns'        => array(), // Empty = auto-detect text columns
				'case_sensitive' => false,
				'whole_word'     => false,
				'regex'          => false,
				'preview_length' => 200,
				'exclude_cols'   => array('ID', 'id', 'user_id', 'post_id', 'term_id', 'meta_id'),
			)
		);
	}

	/**
	 * Search for text in database
	 *
	 * @param string $search_text Text to search for
	 * @return array<string,mixed> Search results and metadata
	 */
	public function search(string $search_text): array {
		if (empty($search_text)) {
			return array(
				'success'        => false,
				'error'          => 'Search text cannot be empty',
				'results'        => array(),
				'tables_searched'=> 0,
			);
		}

		$this->results = array();
		$tables_searched = 0;

		$tables = $this->get_tables_to_search();

		foreach ($tables as $table) {
			// Stop if we've collected enough results
			if (count($this->results) >= $this->max_total_results) {
				break;
			}

			$this->search_table($table, $search_text);
			$tables_searched++;
		}

		return array(
			'success'         => true,
			'results'         => $this->results,
			'tables_searched' => $tables_searched,
			'match_count'     => count($this->results),
			'has_more'        => count($this->results) >= $this->max_total_results,
		);
	}

	/**
	 * Get list of tables to search
	 *
	 * @return array<int,string>
	 */
	private function get_tables_to_search(): array {
		if (! empty($this->options['tables'])) {
			return array_map(array($this, 'validate_table_name'), $this->options['tables']);
		}

		// Get all WordPress tables
		$prefix = $this->wpdb->prefix;
		$tables = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$prefix . '%'
			)
		);

		return array_filter($tables);
	}

	/**
	 * Validate and sanitize table name
	 *
	 * @param string $table Table name
	 * @return string
	 */
	private function validate_table_name(string $table): string {
		// Remove any non-alphanumeric characters except underscore
		$table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
		return $table;
	}

	/**
	 * Search within a specific table
	 *
	 * @param string $table Table name
	 * @param string $search_text Text to search for
	 */
	private function search_table(string $table, string $search_text): void {
		$columns = $this->get_searchable_columns($table);

		if (empty($columns)) {
			return;
		}

		$primary_key = $this->get_primary_key($table);

		foreach ($columns as $column) {
			// Stop if we've collected enough results
			if (count($this->results) >= $this->max_total_results) {
				return;
			}

			$this->search_column($table, $column, $primary_key, $search_text);
		}
	}

	/**
	 * Get searchable columns for a table
	 *
	 * @param string $table Table name
	 * @return array<int,string>
	 */
	private function get_searchable_columns(string $table): array {
		// If specific columns specified, use those
		if (! empty($this->options['columns'])) {
			return array_intersect(
				$this->options['columns'],
				$this->get_table_columns($table)
			);
		}

		$all_columns = $this->get_table_columns($table);
		$searchable = array();

		// Get column info to filter for text types
		$column_info = $this->wpdb->get_results(
			"SHOW COLUMNS FROM `{$table}`", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		foreach ($column_info as $col) {
			$type = strtolower($col['Type']);
			$name = $col['Field'];

			// Skip excluded columns
			if (in_array($name, $this->options['exclude_cols'], true)) {
				continue;
			}

			// Include text-based columns
			$text_types = array('varchar', 'text', 'longtext', 'mediumtext', 'tinytext', 'char');
			foreach ($text_types as $text_type) {
				if (strpos($type, $text_type) !== false) {
					$searchable[] = $name;
					break;
				}
			}
		}

		return $searchable;
	}

	/**
	 * Get all columns for a table
	 *
	 * @param string $table Table name
	 * @return array<int,string>
	 */
	private function get_table_columns(string $table): array {
		$columns = $this->wpdb->get_col(
			"SHOW COLUMNS FROM `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		return $columns ?: array();
	}

	/**
	 * Get primary key column for table
	 *
	 * @param string $table Table name
	 * @return string|null
	 */
	private function get_primary_key(string $table): ?string {
		$key = $this->wpdb->get_var(
			"SELECT COLUMN_NAME 
			FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
			WHERE TABLE_SCHEMA = DATABASE() 
			AND TABLE_NAME = '{$table}' 
			AND CONSTRAINT_NAME = 'PRIMARY' 
			LIMIT 1"
		);

		if ($key) {
			return $key;
		}

		// Fallback: look for common ID columns
		$columns = $this->get_table_columns($table);
		$id_cols = array('ID', 'id', 'meta_id', 'comment_ID', 'link_id', 'term_id');
		
		foreach ($id_cols as $id_col) {
			if (in_array($id_col, $columns, true)) {
				return $id_col;
			}
		}

		return null;
	}

	/**
	 * Search within a specific column
	 *
	 * @param string      $table Table name
	 * @param string      $column Column name
	 * @param string|null $primary_key Primary key column
	 * @param string      $search_text Text to search for
	 */
	private function search_column(string $table, string $column, ?string $primary_key, string $search_text): void {
		// Build search condition
		$like_pattern = $this->build_like_pattern($search_text);
		$collation = $this->options['case_sensitive'] ? 'BINARY ' : '';

		if ($primary_key) {
			$query = $this->wpdb->prepare(
				"SELECT `{$primary_key}`, `{$column}` 
				FROM `{$table}` 
				WHERE {$collation}`{$column}` LIKE %s 
				LIMIT %d",
				$like_pattern,
				$this->max_results_per_table
			);
		} else {
			$query = $this->wpdb->prepare(
				"SELECT `{$column}` 
				FROM `{$table}` 
				WHERE {$collation}`{$column}` LIKE %s 
				LIMIT %d",
				$like_pattern,
				$this->max_results_per_table
			);
		}

		$rows = $this->wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if (empty($rows)) {
			return;
		}

		foreach ($rows as $row) {
			// Additional regex/whole word filtering if needed
			if ($this->options['regex'] || $this->options['whole_word']) {
				if (! $this->content_matches($row[$column], $search_text)) {
					continue;
				}
			}

			$preview = $this->generate_preview($row[$column], $search_text);

			$result = array(
				'table'      => $table,
				'column'     => $column,
				'row_id'     => $primary_key ? $row[$primary_key] : null,
				'match'      => $preview['match'],
				'preview'    => $preview['context'],
				'full_value' => $row[$column],
			);

			$this->results[] = $result;

			// Stop if we've collected enough results
			if (count($this->results) >= $this->max_total_results) {
				return;
			}
		}
	}

	/**
	 * Build LIKE pattern for search
	 *
	 * @param string $search Search text
	 * @return string
	 */
	private function build_like_pattern(string $search): string {
		global $wpdb;

		if ($this->options['regex']) {
			// For regex, we do a broad search and filter in PHP
			return '%' . $wpdb->esc_like($search) . '%';
		}

		if ($this->options['whole_word']) {
			// For whole word, search with word boundaries around
			return '%' . $wpdb->esc_like($search) . '%';
		}

		return '%' . $wpdb->esc_like($search) . '%';
	}

	/**
	 * Check if content matches with regex or whole word
	 *
	 * @param string $content Content to check
	 * @param string $search Search pattern
	 * @return bool
	 */
	private function content_matches(string $content, string $search): bool {
		if ($this->options['regex']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			return preg_match('/' . $search . '/' . $flags, $content) === 1;
		}

		if ($this->options['whole_word']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			$pattern = '/\b' . preg_quote($search, '/') . '\b/' . $flags;
			return preg_match($pattern, $content) === 1;
		}

		return true;
	}

	/**
	 * Generate preview with context around match
	 *
	 * @param string $content Full content
	 * @param string $search Search text
	 * @return array<string,string>
	 */
	private function generate_preview(string $content, string $search): array {
		$max_length = $this->options['preview_length'];

		if ($this->options['regex']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			preg_match('/.{0,50}' . $search . '.{0,50}/s' . $flags, $content, $matches);
			$match_text = $matches[0] ?? substr($content, 0, $max_length);
		} elseif ($this->options['whole_word']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			preg_match('/.{0,50}\b' . preg_quote($search, '/') . '\b.{0,50}/s' . $flags, $content, $matches);
			$match_text = $matches[0] ?? substr($content, 0, $max_length);
		} else {
			// Find position of match
			$pos = $this->options['case_sensitive'] 
				? strpos($content, $search) 
				: stripos($content, $search);

			if ($pos === false) {
				$match_text = substr($content, 0, $max_length);
			} else {
				$start = max(0, $pos - 50);
				$match_text = substr($content, $start, $max_length);
			}
		}

		return array(
			'match'   => substr($content, 0, 200), // First 200 chars as match preview
			'context' => $match_text,
		);
	}

	/**
	 * Get all WordPress tables with row counts
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tables_info(): array {
		$prefix = $this->wpdb->prefix;
		$tables = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT TABLE_NAME as name, TABLE_ROWS as rows_count 
				FROM INFORMATION_SCHEMA.TABLES 
				WHERE TABLE_SCHEMA = DATABASE() 
				AND TABLE_NAME LIKE %s",
				$prefix . '%'
			),
			ARRAY_A
		);

		return $tables ?: array();
	}

	/**
	 * Get columns for a specific table
	 *
	 * @param string $table Table name
	 * @return array<int,array<string,mixed>>
	 */
	public function get_table_columns_info(string $table): array {
		$table = $this->validate_table_name($table);
		
		$columns = $this->wpdb->get_results(
			"SHOW COLUMNS FROM `{$table}`", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $columns ?: array();
	}
}
