<?php
/**
 * Search Tool Controller
 * AJAX handlers for global search and replace operations
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Controller
 * 
 * Main controller for Search Tool module
 */
class Search_Controller extends Module_Base {
	/**
	 * @var File_Search
	 */
	private File_Search $file_search;

	/**
	 * @var DB_Search
	 */
	private DB_Search $db_search;

	/**
	 * @var Search_Replace
	 */
	private Search_Replace $search_replace;

	public function __construct() {
		$this->file_search    = new File_Search();
		$this->db_search      = new DB_Search();
		$this->search_replace = new Search_Replace();
	}

	public function register_hooks(): void {
		// Search endpoints
		add_action('wp_ajax_wudt_search_files', array($this, 'ajax_search_files'));
		add_action('wp_ajax_wudt_search_db', array($this, 'ajax_search_db'));

		// Replace endpoints
		add_action('wp_ajax_wudt_preview_replace_files', array($this, 'ajax_preview_replace_files'));
		add_action('wp_ajax_wudt_preview_replace_db', array($this, 'ajax_preview_replace_db'));
		add_action('wp_ajax_wudt_execute_replace_files', array($this, 'ajax_execute_replace_files'));
		add_action('wp_ajax_wudt_execute_replace_db', array($this, 'ajax_execute_replace_db'));

		// Utility endpoints
		add_action('wp_ajax_wudt_get_tables', array($this, 'ajax_get_tables'));
		add_action('wp_ajax_wudt_get_table_columns', array($this, 'ajax_get_table_columns'));
		add_action('wp_ajax_wudt_get_malware_patterns', array($this, 'ajax_get_malware_patterns'));
		add_action('wp_ajax_wudt_save_search_history', array($this, 'ajax_save_search_history'));
		add_action('wp_ajax_wudt_get_search_history', array($this, 'ajax_get_search_history'));
		add_action('wp_ajax_wudt_export_results', array($this, 'ajax_export_results'));
	}

	public function get_key(): string {
		return 'search_tool';
	}

	public function get_label(): string {
		return __('Search & Replace', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'directories'      => File_Search::get_wp_directories(),
			'malware_patterns' => File_Search::get_malware_patterns(),
			'tables_count'     => count($this->db_search->get_tables_info()),
		);
	}

	/**
	 * AJAX: Search files
	 */
	public function ajax_search_files(): void {
		Security_Guard::assert_ajax_admin();

		$search_text = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';
		
		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		// Build options from request
		$options = $this->build_file_search_options($_POST);

		// Perform search
		$results = $this->file_search->search($search_text, $options);

		if ($results['success']) {
			// Log operation
			$this->log_search('file', $search_text, $results['match_count']);
			wp_send_json_success($results);
		} else {
			wp_send_json_error(array('message' => $results['error']));
		}
	}

	/**
	 * AJAX: Search database
	 */
	public function ajax_search_db(): void {
		Security_Guard::assert_ajax_admin();

		$search_text = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';

		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		// Build options from request
		$options = $this->build_db_search_options($_POST);

		// Perform search
		$results = $this->db_search->search($search_text, $options);

		if ($results['success']) {
			// Log operation
			$this->log_search('database', $search_text, $results['match_count']);
			wp_send_json_success($results);
		} else {
			wp_send_json_error(array('message' => $results['error']));
		}
	}

	/**
	 * AJAX: Preview file replacements
	 */
	public function ajax_preview_replace_files(): void {
		Security_Guard::assert_ajax_admin();

		$search_text  = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';
		$replace_text = isset($_POST['replace']) ? sanitize_textarea_field((string) wp_unslash($_POST['replace'])) : '';

		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$options = $this->build_file_search_options($_POST);
		$preview = $this->search_replace->preview_file_replacements($search_text, $replace_text, $options);

		if ($preview['success']) {
			wp_send_json_success($preview);
		} else {
			wp_send_json_error(array('message' => $preview['error'] ?? 'Preview failed'));
		}
	}

	/**
	 * AJAX: Preview database replacements
	 */
	public function ajax_preview_replace_db(): void {
		Security_Guard::assert_ajax_admin();

		$search_text  = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';
		$replace_text = isset($_POST['replace']) ? sanitize_textarea_field((string) wp_unslash($_POST['replace'])) : '';

		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$options = $this->build_db_search_options($_POST);
		$preview = $this->search_replace->preview_db_replacements($search_text, $replace_text, $options);

		if ($preview['success']) {
			wp_send_json_success($preview);
		} else {
			wp_send_json_error(array('message' => $preview['error'] ?? 'Preview failed'));
		}
	}

	/**
	 * AJAX: Execute file replacements
	 */
	public function ajax_execute_replace_files(): void {
		Security_Guard::assert_ajax_admin();

		$search_text   = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';
		$replace_text  = isset($_POST['replace']) ? sanitize_textarea_field((string) wp_unslash($_POST['replace'])) : '';
		$confirmed     = isset($_POST['confirmed']) && '1' === (string) wp_unslash($_POST['confirmed']);
		$create_backup = isset($_POST['backup']) && '1' === (string) wp_unslash($_POST['backup']);

		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		if (! $confirmed) {
			wp_send_json_error(array('message' => __('Replacement must be confirmed.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$options = $this->build_file_search_options($_POST);
		$result  = $this->search_replace->execute_file_replacements($search_text, $replace_text, $options, $create_backup);

		if ($result['success']) {
			// Log operation
			$this->log_replace('file', $search_text, $replace_text, $result['files_modified']);
			wp_send_json_success($result);
		} else {
			wp_send_json_error(array('message' => $result['error']));
		}
	}

	/**
	 * AJAX: Execute database replacements
	 */
	public function ajax_execute_replace_db(): void {
		Security_Guard::assert_ajax_admin();

		$search_text   = isset($_POST['search']) ? sanitize_textarea_field((string) wp_unslash($_POST['search'])) : '';
		$replace_text  = isset($_POST['replace']) ? sanitize_textarea_field((string) wp_unslash($_POST['replace'])) : '';
		$confirmed     = isset($_POST['confirmed']) && '1' === (string) wp_unslash($_POST['confirmed']);
		$create_backup = isset($_POST['backup']) && '1' === (string) wp_unslash($_POST['backup']);

		if (empty($search_text)) {
			wp_send_json_error(array('message' => __('Search text is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		if (! $confirmed) {
			wp_send_json_error(array('message' => __('Replacement must be confirmed.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$options = $this->build_db_search_options($_POST);
		$result  = $this->search_replace->execute_db_replacements($search_text, $replace_text, $options, $create_backup);

		if ($result['success']) {
			// Log operation
			$this->log_replace('database', $search_text, $replace_text, $result['rows_modified']);
			wp_send_json_success($result);
		} else {
			wp_send_json_error(array('message' => $result['error']));
		}
	}

	/**
	 * AJAX: Get database tables list
	 */
	public function ajax_get_tables(): void {
		Security_Guard::assert_ajax_admin();

		$tables = $this->db_search->get_tables_info();
		wp_send_json_success(array('tables' => $tables));
	}

	/**
	 * AJAX: Get columns for a table
	 */
	public function ajax_get_table_columns(): void {
		Security_Guard::assert_ajax_admin();

		$table = isset($_POST['table']) ? sanitize_text_field((string) wp_unslash($_POST['table'])) : '';
		
		if (empty($table)) {
			wp_send_json_error(array('message' => __('Table name is required.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$columns = $this->db_search->get_table_columns_info($table);
		wp_send_json_success(array('columns' => $columns));
	}

	/**
	 * AJAX: Get malware detection patterns
	 */
	public function ajax_get_malware_patterns(): void {
		Security_Guard::assert_ajax_admin();

		$patterns = File_Search::get_malware_patterns();
		wp_send_json_success(array('patterns' => $patterns));
	}

	/**
	 * AJAX: Save search to history
	 */
	public function ajax_save_search_history(): void {
		Security_Guard::assert_ajax_admin();

		$search = isset($_POST['search']) ? sanitize_text_field((string) wp_unslash($_POST['search'])) : '';
		$type   = isset($_POST['type']) ? sanitize_key((string) wp_unslash($_POST['type'])) : 'file';

		if (empty($search)) {
			wp_send_json_error();
		}

		$history   = get_option('wudt_search_history', array());
		$history[] = array(
			'search' => $search,
			'type'   => $type,
			'time'   => current_time('mysql'),
		);

		// Keep only last 50 searches
		$history = array_slice($history, -50);
		update_option('wudt_search_history', $history);

		wp_send_json_success();
	}

	/**
	 * AJAX: Get search history
	 */
	public function ajax_get_search_history(): void {
		Security_Guard::assert_ajax_admin();

		$history = get_option('wudt_search_history', array());
		
		// Reverse to show newest first
		$history = array_reverse($history);
		
		wp_send_json_success(array('history' => $history));
	}

	/**
	 * AJAX: Export search results
	 */
	public function ajax_export_results(): void {
		Security_Guard::assert_ajax_admin();

		$results = isset($_POST['results']) ? (array) json_decode((string) wp_unslash($_POST['results']), true) : array();
		$format  = isset($_POST['format']) ? sanitize_key((string) wp_unslash($_POST['format'])) : 'json';

		if (empty($results)) {
			wp_send_json_error(array('message' => __('No results to export.', 'wp-ultimate-diagnostics-toolkit')));
		}

		if ($format === 'csv') {
			$csv = $this->convert_to_csv($results);
			wp_send_json_success(array(
				'content'     => $csv,
				'filename'    => 'search-results-' . current_time('Y-m-d') . '.csv',
				'content_type'=> 'text/csv',
			));
		} else {
			wp_send_json_success(array(
				'content'     => wp_json_encode($results, JSON_PRETTY_PRINT),
				'filename'    => 'search-results-' . current_time('Y-m-d') . '.json',
				'content_type'=> 'application/json',
			));
		}
	}

	/**
	 * Build file search options from request
	 *
	 * @param array<string,mixed> $post POST data
	 * @return array<string,mixed>
	 */
	private function build_file_search_options(array $post): array {
		$directory = isset($post['directory']) ? sanitize_text_field((string) wp_unslash($post['directory'])) : ABSPATH;
		
		// Validate and resolve directory
		$wp_dirs = File_Search::get_wp_directories();
		if (isset($wp_dirs[$directory])) {
			$directory = $wp_dirs[$directory];
		}

		$extensions = isset($post['extensions']) ? (array) json_decode((string) wp_unslash($post['extensions']), true) : array('php', 'js', 'css', 'html');
		$extensions = array_map('sanitize_key', $extensions);

		$exclude_dirs = isset($post['exclude_dirs']) ? (array) json_decode((string) wp_unslash($post['exclude_dirs']), true) : array('node_modules', '.git', 'vendor');
		$exclude_dirs = array_map('sanitize_file_name', $exclude_dirs);

		return array(
			'directory'      => $directory,
			'extensions'     => $extensions,
			'exclude_dirs'   => $exclude_dirs,
			'case_sensitive' => isset($post['case_sensitive']) && '1' === (string) wp_unslash($post['case_sensitive']),
			'whole_word'     => isset($post['whole_word']) && '1' === (string) wp_unslash($post['whole_word']),
			'regex'          => isset($post['regex']) && '1' === (string) wp_unslash($post['regex']),
		);
	}

	/**
	 * Build database search options from request
	 *
	 * @param array<string,mixed> $post POST data
	 * @return array<string,mixed>
	 */
	private function build_db_search_options(array $post): array {
		$tables = isset($post['tables']) ? (array) json_decode((string) wp_unslash($post['tables']), true) : array();
		$tables = array_map(array($this, 'sanitize_table_name'), $tables);

		$columns = isset($post['columns']) ? (array) json_decode((string) wp_unslash($post['columns']), true) : array();
		$columns = array_map('sanitize_key', $columns);

		return array(
			'tables'         => $tables,
			'columns'        => $columns,
			'case_sensitive' => isset($post['case_sensitive']) && '1' === (string) wp_unslash($post['case_sensitive']),
			'whole_word'     => isset($post['whole_word']) && '1' === (string) wp_unslash($post['whole_word']),
			'regex'          => isset($post['regex']) && '1' === (string) wp_unslash($post['regex']),
		);
	}

	/**
	 * Sanitize table name
	 *
	 * @param string $table Table name
	 * @return string
	 */
	private function sanitize_table_name(string $table): string {
		return preg_replace('/[^a-zA-Z0-9_]/', '', $table);
	}

	/**
	 * Log search operation
	 *
	 * @param string $type Search type
	 * @param string $query Search query
	 * @param int    $matches Number of matches
	 */
	private function log_search(string $type, string $query, int $matches): void {
		if (function_exists('WUDT\Includes\Operation_Logger::log')) {
			\WUDT\Includes\Operation_Logger::log('search', "{$type} search: {$query}", array('matches' => $matches));
		}
	}

	/**
	 * Log replace operation
	 *
	 * @param string $type Replace type
	 * @param string $search Search text
	 * @param string $replace Replace text
	 * @param int    $count Number of replacements
	 */
	private function log_replace(string $type, string $search, string $replace, int $count): void {
		if (function_exists('WUDT\Includes\Operation_Logger::log')) {
			\WUDT\Includes\Operation_Logger::log('replace', "{$type} replace: {$search}", array('count' => $count));
		}
	}

	/**
	 * Convert results to CSV
	 *
	 * @param array<int,array<string,mixed>> $results Search results
	 * @return string
	 */
	private function convert_to_csv(array $results): string {
		if (empty($results)) {
			return '';
		}

		$output = fopen('php://temp', 'r+');

		// Headers
		$first = reset($results);
		fputcsv($output, array_keys($first));

		// Data
		foreach ($results as $row) {
			fputcsv($output, $row);
		}

		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		return $csv ?: '';
	}
}
