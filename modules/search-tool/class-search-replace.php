<?php
/**
 * Search & Replace Engine
 * Safe replacement with preview and backup integration
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

use WUDT\Modules\Backup\Backup_Module;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Replace
 * 
 * Handles replacement operations with safety checks and preview
 */
class Search_Replace {
	/**
	 * @var File_Search
	 */
	private File_Search $file_search;

	/**
	 * @var DB_Search
	 */
	private DB_Search $db_search;

	/**
	 * Preview mode flag
	 */
	private bool $preview_mode = false;

	/**
	 * Changes log for preview
	 * @var array<int,array<string,mixed>>
	 */
	private array $pending_changes = array();

	/**
	 * Statistics
	 */
	private array $stats = array(
		'files_modified'    => 0,
		'files_failed'      => 0,
		'db_rows_modified'  => 0,
		'db_rows_failed'    => 0,
	);

	public function __construct() {
		$this->file_search = new File_Search();
		$this->db_search   = new DB_Search();
	}

	/**
	 * Preview file replacements
	 *
	 * @param string              $search_text Text to find
	 * @param string              $replace_text Text to replace with
	 * @param array<string,mixed> $options Search options
	 * @return array<string,mixed> Preview of changes
	 */
	public function preview_file_replacements(string $search_text, string $replace_text, array $options): array {
		$this->preview_mode = true;
		$this->pending_changes = array();
	
		// First search for matches
		$search_results = $this->file_search->search($search_text, $options);
		
		if (! $search_results['success'] || empty($search_results['results'])) {
			return array(
				'success'       => true,
				'preview'         => array(),
				'total_changes'   => 0,
				'message'         => 'No matches found for replacement.',
			);
		}
	
		// Generate preview for each match
		foreach ($search_results['results'] as $match) {
			$preview = $this->generate_file_replace_preview(
				$match['full_path'],
				$search_text,
				$replace_text,
				$options
			);
		
			if ($preview) {
				$this->pending_changes[] = $preview;
			}
		}
	
		return array(
			'success'       => true,
			'preview'       => $this->pending_changes,
			'total_changes' => count($this->pending_changes),
			'files_found'   => count(array_unique(array_column($this->pending_changes, 'file'))),
			'can_replace'   => count($this->pending_changes) > 0,
		);
	}

	/**
	 * Execute file replacements
	 *
	 * @param string              $search_text Text to find
	 * @param string              $replace_text Text to replace with
	 * @param array<string,mixed> $options Search options
	 * @param bool                $create_backup Whether to create backup first
	 * @return array<string,mixed> Results
	 */
	public function execute_file_replacements(string $search_text, string $replace_text, array $options, bool $create_backup = true): array {
		$this->preview_mode = false;
		$this->stats = array(
			'files_modified'   => 0,
			'files_failed'     => 0,
			'db_rows_modified' => 0,
			'db_rows_failed'   => 0,
		);

		// Create backup if requested
		if ($create_backup) {
			$backup_result = $this->create_backup();
			if (! $backup_result['success']) {
				return array(
					'success' => false,
					'error'   => 'Failed to create backup: ' . $backup_result['error'],
				);
			}
		}

		// Search for matches
		$search_results = $this->file_search->search($search_text, $options);
		
		if (! $search_results['success']) {
			return array(
				'success' => false,
				'error'   => $search_results['error'],
			);
		}

		$modified_files = array();
		$errors = array();

		// Group matches by file
		$files_to_modify = array();
		foreach ($search_results['results'] as $match) {
			$files_to_modify[$match['full_path']][] = $match;
		}

		// Process each file
		foreach ($files_to_modify as $filepath => $matches) {
			$result = $this->replace_in_file($filepath, $search_text, $replace_text, $options);
			
			if ($result['success']) {
				$this->stats['files_modified']++;
				$modified_files[] = $filepath;
			} else {
				$this->stats['files_failed']++;
				$errors[] = array(
					'file'  => $filepath,
					'error' => $result['error'],
				);
			}
		}

		return array(
			'success'          => true,
			'files_modified'   => $this->stats['files_modified'],
			'files_failed'     => $this->stats['files_failed'],
			'modified_files'   => $modified_files,
			'errors'           => $errors,
			'backup_created'   => $create_backup,
		);
	}

	/**
	 * Preview database replacements
	 *
	 * @param string              $search_text Text to find
	 * @param string              $replace_text Text to replace with
	 * @param array<string,mixed> $options Search options
	 * @return array<string,mixed> Preview of changes
	 */
	public function preview_db_replacements(string $search_text, string $replace_text, array $options): array {
		$this->preview_mode = true;
		$this->pending_changes = array();

		// Search for matches
		$search_results = $this->db_search->search($search_text, $options);

		if (! $search_results['success'] || empty($search_results['results'])) {
			return array(
				'success'       => true,
				'preview'       => array(),
				'total_changes' => 0,
				'message'       => 'No matches found for replacement.',
			);
		}

		// Generate preview for each match
		foreach ($search_results['results'] as $match) {
			$before = $match['full_value'];
			$after  = $this->calculate_db_replace_result($before, $search_text, $replace_text, $options);

			if ($before !== $after) {
				$this->pending_changes[] = array(
					'table'        => $match['table'],
					'column'       => $match['column'],
					'row_id'       => $match['row_id'],
					'before'       => $before,
					'after'        => $after,
					'preview_diff' => $this->generate_diff_preview($before, $after),
				);
			}
		}

		return array(
			'success'       => true,
			'preview'       => $this->pending_changes,
			'total_changes' => count($this->pending_changes),
			'tables_found'  => count(array_unique(array_column($this->pending_changes, 'table'))),
			'can_replace'   => count($this->pending_changes) > 0,
		);
	}

	/**
	 * Execute database replacements
	 *
	 * @param string              $search_text Text to find
	 * @param string              $replace_text Text to replace with
	 * @param array<string,mixed> $options Search options
	 * @param bool                $create_backup Whether to create backup first
	 * @return array<string,mixed> Results
	 */
	public function execute_db_replacements(string $search_text, string $replace_text, array $options, bool $create_backup = true): array {
		global $wpdb;

		$this->preview_mode = false;
		$this->stats = array(
			'files_modified'   => 0,
			'files_failed'     => 0,
			'db_rows_modified' => 0,
			'db_rows_failed'   => 0,
		);

		// Create backup if requested
		if ($create_backup) {
			$backup_result = $this->create_backup(array('database'));
			if (! $backup_result['success']) {
				return array(
					'success' => false,
					'error'   => 'Failed to create backup: ' . $backup_result['error'],
				);
			}
		}

		// Search for matches
		$search_results = $this->db_search->search($search_text, $options);

		if (! $search_results['success']) {
			return array(
				'success' => false,
				'error'   => $search_results['error'],
			);
		}

		$errors = array();

		// Group by table/column for efficient updates
		$updates = array();
		foreach ($search_results['results'] as $match) {
			if (! $match['row_id']) {
				continue; // Can't update without primary key
			}
			
			$key = $match['table'] . '|' . $match['column'];
			$updates[$key][] = $match;
		}

		// Execute updates
		foreach ($updates as $key => $matches) {
			list($table, $column) = explode('|', $key);
			
			foreach ($matches as $match) {
				$before = $match['full_value'];
				$after  = $this->calculate_db_replace_result($before, $search_text, $replace_text, $options);

				if ($before === $after) {
					continue;
				}

				$result = $this->update_db_row($table, $column, $match['row_id'], $after);
				
				if ($result) {
					$this->stats['db_rows_modified']++;
				} else {
					$this->stats['db_rows_failed']++;
					$errors[] = array(
						'table'  => $table,
						'column' => $column,
						'row_id' => $match['row_id'],
						'error'  => $wpdb->last_error,
					);
				}
			}
		}

		return array(
			'success'          => true,
			'rows_modified'    => $this->stats['db_rows_modified'],
			'rows_failed'      => $this->stats['db_rows_failed'],
			'errors'           => $errors,
			'backup_created'   => $create_backup,
		);
	}

	/**
	 * Generate file replacement preview
	 *
	 * @param string              $filepath File path
	 * @param string              $search Search text
	 * @param string              $replace Replace text
	 * @param array<string,mixed> $options Options
	 * @return array<string,mixed>|null
	 */
	private function generate_file_replace_preview(string $filepath, string $search, string $replace, array $options): ?array {
		$content = file_get_contents($filepath);
		if ($content === false) {
			return null;
		}

		$after_content = $this->calculate_file_replace_result($content, $search, $replace, $options);

		if ($content === $after_content) {
			return null;
		}

		// Find first change location
		$lines_before = explode("\n", $content);
		$lines_after  = explode("\n", $after_content);

		return array(
			'file'         => str_replace(ABSPATH, '/', $filepath),
			'full_path'    => $filepath,
			'preview_diff' => $this->generate_diff_preview($content, $after_content, 5),
			'replace_count' => $this->count_replacements($content, $search, $options),
		);
	}

	/**
	 * Replace text in a file
	 *
	 * @param string              $filepath File path
	 * @param string              $search Search text
	 * @param string              $replace Replace text
	 * @param array<string,mixed> $options Options
	 * @return array<string,mixed>
	 */
	private function replace_in_file(string $filepath, string $search, string $replace, array $options): array {
		$content = file_get_contents($filepath);
		if ($content === false) {
			return array(
				'success' => false,
				'error'   => 'Could not read file',
			);
		}

		$new_content = $this->calculate_file_replace_result($content, $search, $replace, $options);

		if ($content === $new_content) {
			return array(
				'success' => false,
				'error'   => 'No replacements made',
			);
		}

		// Write new content
		$result = file_put_contents($filepath, $new_content);
		
		if ($result === false) {
			return array(
				'success' => false,
				'error'   => 'Could not write file',
			);
		}

		return array(
			'success'       => true,
			'bytes_written' => $result,
		);
	}

	/**
	 * Calculate file replacement result
	 *
	 * @param string              $content Original content
	 * @param string              $search Search text
	 * @param string              $replace Replace text
	 * @param array<string,mixed> $options Options
	 * @return string
	 */
	private function calculate_file_replace_result(string $content, string $search, string $replace, array $options): string {
		if ($options['regex'] ?? false) {
			$flags = ($options['case_sensitive'] ?? false) ? '' : 'i';
			return preg_replace('/' . $search . '/' . $flags, $replace, $content);
		}

		if ($options['whole_word'] ?? false) {
			$flags = ($options['case_sensitive'] ?? false) ? '' : 'i';
			$pattern = '/\b' . preg_quote($search, '/') . '\b/' . $flags;
			return preg_replace($pattern, $replace, $content);
		}

		if ($options['case_sensitive'] ?? false) {
			return str_replace($search, $replace, $content);
		}

		// Case-insensitive replacement that preserves original case pattern
		return $this->str_ireplace_preserve_case($search, $replace, $content);
	}

	/**
	 * Calculate database replacement result
	 *
	 * @param string              $content Original content
	 * @param string              $search Search text
	 * @param string              $replace Replace text
	 * @param array<string,mixed> $options Options
	 * @return string
	 */
	private function calculate_db_replace_result(string $content, string $search, string $replace, array $options): string {
		// Same logic as file replacement
		return $this->calculate_file_replace_result($content, $search, $replace, $options);
	}

	/**
	 * Update database row
	 *
	 * @param string     $table Table name
	 * @param string     $column Column name
	 * @param int|string $row_id Row ID
	 * @param string     $new_value New value
	 * @return bool
	 */
	private function update_db_row(string $table, string $column, $row_id, string $new_value): bool {
		global $wpdb;

		// Sanitize table and column names
		$table  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
		$column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

		$primary_key = $this->get_primary_key($table);
		if (! $primary_key) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET `{$column}` = %s WHERE `{$primary_key}` = %s",
				$new_value,
				$row_id
			)
		);

		return $result !== false;
	}

	/**
	 * Get primary key for table
	 *
	 * @param string $table Table name
	 * @return string|null
	 */
	private function get_primary_key(string $table): ?string {
		global $wpdb;

		$key = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME 
				FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
				WHERE TABLE_SCHEMA = DATABASE() 
				AND TABLE_NAME = %s 
				AND CONSTRAINT_NAME = 'PRIMARY' 
				LIMIT 1",
				$table
			)
		);

		return $key;
	}

	/**
	 * Generate diff preview
	 *
	 * @param string $before Original content
	 * @param string $after  New content
	 * @param int    $lines  Number of context lines
	 * @return array<string,mixed>
	 */
	private function generate_diff_preview(string $before, string $after, int $lines = 5): array {
		$before_lines = explode("\n", $before);
		$after_lines  = explode("\n", $after);

		$diff = array();
		$max_lines = max(count($before_lines), count($after_lines));

		for ($i = 0; $i < $max_lines; $i++) {
			$before_line = $before_lines[$i] ?? '';
			$after_line  = $after_lines[$i] ?? '';

			if ($before_line !== $after_line) {
				$diff[] = array(
					'line'    => $i + 1,
					'before'  => $before_line,
					'after'   => $after_line,
					'changed' => true,
				);
			}
		}

		// Return first few changes
		return array_slice($diff, 0, $lines);
	}

	/**
	 * Count replacements that would be made
	 *
	 * @param string              $content Content
	 * @param string              $search Search text
	 * @param array<string,mixed> $options Options
	 * @return int
	 */
	private function count_replacements(string $content, string $search, array $options): int {
		if ($options['regex'] ?? false) {
			$flags = ($options['case_sensitive'] ?? false) ? '' : 'i';
			return preg_match_all('/' . $search . '/' . $flags, $content);
		}

		if ($options['whole_word'] ?? false) {
			$flags = ($options['case_sensitive'] ?? false) ? '' : 'i';
			$pattern = '/\b' . preg_quote($search, '/') . '\b/' . $flags;
			return preg_match_all($pattern, $content);
		}

		return substr_count(
			($options['case_sensitive'] ?? false) ? $content : strtolower($content),
			($options['case_sensitive'] ?? false) ? $search : strtolower($search)
		);
	}

	/**
	 * Create backup before replacement
	 *
	 * @param array<int,string> $components Components to backup
	 * @return array<string,mixed>
	 */
	private function create_backup(array $components = array('plugins', 'themes', 'database')): array {
		if (! class_exists(Backup_Module::class)) {
			return array(
				'success' => false,
				'error'   => 'Backup module not available',
			);
		}

		try {
			$backup = new Backup_Module();
			$result = $backup->create_backup_package($components, false, '');
			
			return array(
				'success' => true,
				'backup_path' => $result['file'] ?? '',
			);
		} catch (\Exception $e) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Case-insensitive replace that preserves original case pattern
	 *
	 * @param string $search Search string
	 * @param string $replace Replace string
	 * @param string $subject Subject string
	 * @return string
	 */
	private function str_ireplace_preserve_case(string $search, string $replace, string $subject): string {
		// Simple case-insensitive replacement
		// For more advanced case preservation, this would need more logic
		return str_ireplace($search, $replace, $subject);
	}
}
