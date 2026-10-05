<?php
/**
 * Search Tool Controller
 * AJAX handlers for global search and replace operations
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
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
	private const HISTORY_OPTION = 'wudt_search_history';
	private const HISTORY_LIMIT  = 15;

	private File_Search $file_search;
	private DB_Search $db_search;
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
		add_action('wp_ajax_wudt_search_db_value', array($this, 'ajax_search_db_value'));

		// Replace endpoints
		add_action('wp_ajax_wudt_preview_replace_files', array($this, 'ajax_preview_replace_files'));
		add_action('wp_ajax_wudt_preview_replace_db', array($this, 'ajax_preview_replace_db'));
		add_action('wp_ajax_wudt_execute_replace_files', array($this, 'ajax_execute_replace_files'));
		add_action('wp_ajax_wudt_execute_replace_db', array($this, 'ajax_execute_replace_db'));

		// Undo backups
		add_action('wp_ajax_wudt_search_backups', array($this, 'ajax_list_backups'));
		add_action('wp_ajax_wudt_search_restore_backup', array($this, 'ajax_restore_backup'));
		add_action('wp_ajax_wudt_search_delete_backup', array($this, 'ajax_delete_backup'));

		// Utility endpoints
		add_action('wp_ajax_wudt_get_tables', array($this, 'ajax_get_tables'));
		add_action('wp_ajax_wudt_get_table_columns', array($this, 'ajax_get_table_columns'));
		add_action('wp_ajax_wudt_get_malware_patterns', array($this, 'ajax_get_malware_patterns'));
		add_action('wp_ajax_wudt_save_search_history', array($this, 'ajax_save_search_history'));
		add_action('wp_ajax_wudt_get_search_history', array($this, 'ajax_get_search_history'));
		add_action('wp_ajax_wudt_clear_search_history', array($this, 'ajax_clear_search_history'));
	}

	public function get_key(): string {
		return 'search_tool';
	}

	public function get_label(): string {
		return __('Search & Replace', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'directories'      => File_Search::get_wp_directories(),
			'malware_patterns' => File_Search::get_malware_patterns(),
			'tables_count'     => count($this->db_search->get_table_names()),
		);
	}

	/* ------------------------------------------------------------------
	 * Search
	 * ------------------------------------------------------------------ */

	public function ajax_search_files(): void {
		$search  = $this->require_search();
		$options = $this->build_file_search_options();
		$this->extend_time_limit();

		$results = $this->file_search->search($search, $options);
		if (! $results['success']) {
			wp_send_json_error(array('message' => $results['error']));
		}

		$this->log('search', 'File search: ' . $search, array('matches' => $results['match_count']));
		wp_send_json_success($results);
	}

	public function ajax_search_db(): void {
		$search  = $this->require_search();
		$options = $this->build_db_search_options();
		$this->extend_time_limit();

		$results = $this->db_search->search($search, $options);
		if (! $results['success']) {
			wp_send_json_error(array('message' => $results['error']));
		}

		$this->log('search', 'Database search: ' . $search, array('matches' => $results['match_count']));
		wp_send_json_success($results);
	}

	/**
	 * Full value of one matched cell, highlighted.
	 */
	public function ajax_search_db_value(): void {
		$search = $this->require_search();
		$table  = $this->table_param('table');
		$column = isset($_POST['column']) ? preg_replace('/[^A-Za-z0-9_$-]/', '', (string) wp_unslash($_POST['column'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to identifier characters.
		$row_id = isset($_POST['row_id']) ? sanitize_text_field((string) wp_unslash($_POST['row_id'])) : '';

		$value = $this->db_search->fetch_value($table, (string) $column, $row_id);
		if (null === $value) {
			wp_send_json_error(array('message' => __('That row no longer exists.', 'diagnostics-toolkit')));
		}

		try {
			$matcher = new Search_Matcher($search, $this->build_db_search_options());
		} catch (\InvalidArgumentException $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}

		$limit     = 200 * 1024;
		$truncated = strlen($value) > $limit;
		$shown     = $truncated ? Search_Matcher::clip($value, $limit) : $value;
		$segmented = $matcher->segments($shown, PHP_INT_MAX);

		wp_send_json_success(array(
			'segments'  => $segmented['segments'],
			'length'    => strlen($value),
			'truncated' => $truncated,
		));
	}

	/* ------------------------------------------------------------------
	 * Replace
	 * ------------------------------------------------------------------ */

	public function ajax_preview_replace_files(): void {
		$search  = $this->require_search();
		$replace = $this->raw_param('replace');
		$this->extend_time_limit();

		$preview = $this->search_replace->preview_file_replacements($search, $replace, $this->build_file_search_options());
		$this->respond($preview);
	}

	public function ajax_preview_replace_db(): void {
		$search  = $this->require_search();
		$replace = $this->raw_param('replace');
		$this->extend_time_limit();

		$preview = $this->search_replace->preview_db_replacements($search, $replace, $this->build_db_search_options());
		$this->respond($preview);
	}

	public function ajax_execute_replace_files(): void {
		$search  = $this->require_search();
		$replace = $this->raw_param('replace');
		$this->require_confirmation();
		$this->extend_time_limit();

		$result = $this->search_replace->execute_file_replacements(
			$search,
			$replace,
			$this->build_file_search_options(),
			$this->flag('backup'),
			$this->selection_param()
		);

		if ($result['success']) {
			$this->log('replace', 'File replace: ' . $search, array('files' => $result['files_modified'], 'backup' => $result['backup_id']));
		}
		$this->respond($result);
	}

	public function ajax_execute_replace_db(): void {
		$search  = $this->require_search();
		$replace = $this->raw_param('replace');
		$this->require_confirmation();
		$this->extend_time_limit();

		$result = $this->search_replace->execute_db_replacements(
			$search,
			$replace,
			$this->build_db_search_options(),
			$this->flag('backup'),
			$this->selection_param()
		);

		if ($result['success']) {
			$this->log('replace', 'Database replace: ' . $search, array('rows' => $result['rows_modified'], 'backup' => $result['backup_id']));
		}
		$this->respond($result);
	}

	/* ------------------------------------------------------------------
	 * Undo backups
	 * ------------------------------------------------------------------ */

	public function ajax_list_backups(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('backups' => Search_Backup::list_backups()));
	}

	public function ajax_restore_backup(): void {
		Security_Guard::assert_ajax_admin();
		$id     = isset($_POST['id']) ? sanitize_text_field((string) wp_unslash($_POST['id'])) : '';
		$result = Search_Backup::restore($id, $this->flag('force'));

		if ($result['success']) {
			$this->log('replace', 'Undo search & replace ' . $id, $result);
		}
		$this->respond($result);
	}

	public function ajax_delete_backup(): void {
		Security_Guard::assert_ajax_admin();
		$id = isset($_POST['id']) ? sanitize_text_field((string) wp_unslash($_POST['id'])) : '';
		if (! Search_Backup::delete($id)) {
			wp_send_json_error(array('message' => __('Backup not found.', 'diagnostics-toolkit')));
		}
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------
	 * Utilities
	 * ------------------------------------------------------------------ */

	public function ajax_get_tables(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('tables' => $this->db_search->get_tables_info()));
	}

	public function ajax_get_table_columns(): void {
		Security_Guard::assert_ajax_admin();
		$table = $this->table_param('table');
		if ('' === $table) {
			wp_send_json_error(array('message' => __('Table name is required.', 'diagnostics-toolkit')));
		}
		wp_send_json_success(array('columns' => $this->db_search->get_table_columns_info($table)));
	}

	public function ajax_get_malware_patterns(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('patterns' => File_Search::get_malware_patterns()));
	}

	/**
	 * Remember a search with its options so it can be re-run in one click.
	 */
	public function ajax_save_search_history(): void {
		Security_Guard::assert_ajax_admin();

		$search = $this->raw_param('search');
		if ('' === $search) {
			wp_send_json_error();
		}

		$entry = array(
			'search'         => Search_Matcher::clip($search, 500),
			'regex'          => $this->flag('regex'),
			'case_sensitive' => $this->flag('case_sensitive'),
			'whole_word'     => $this->flag('whole_word'),
			'files'          => $this->flag('files'),
			'db'             => $this->flag('db'),
			'time'           => time(),
		);

		$history = array_filter(
			(array) get_option(self::HISTORY_OPTION, array()),
			static function ($item) use ($entry): bool {
				return is_array($item) && isset($item['search']) && $item['search'] !== $entry['search'];
			}
		);
		array_unshift($history, $entry);
		update_option(self::HISTORY_OPTION, array_slice(array_values($history), 0, self::HISTORY_LIMIT), false);

		wp_send_json_success(array('history' => self::get_history()));
	}

	public function ajax_get_search_history(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('history' => self::get_history()));
	}

	public function ajax_clear_search_history(): void {
		Security_Guard::assert_ajax_admin();
		delete_option(self::HISTORY_OPTION);
		wp_send_json_success(array('history' => array()));
	}

	/**
	 * Recent searches, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history(): array {
		$history = array();
		foreach ((array) get_option(self::HISTORY_OPTION, array()) as $item) {
			// Entries saved by older versions only had search/type/time and were stored oldest-first.
			if (is_array($item) && isset($item['search']) && isset($item['regex'])) {
				$history[] = $item;
			}
		}
		return $history;
	}

	/* ------------------------------------------------------------------
	 * Request helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Check the nonce + capability and return the search text.
	 */
	private function require_search(): string {
		Security_Guard::assert_ajax_admin();

		$search = $this->raw_param('search');
		if ('' === $search) {
			wp_send_json_error(array('message' => __('Search text is required.', 'diagnostics-toolkit')));
		}
		return $search;
	}

	private function require_confirmation(): void {
		if (! $this->flag('confirmed')) {
			wp_send_json_error(array('message' => __('Replacement must be confirmed.', 'diagnostics-toolkit')));
		}
	}

	/**
	 * Search and replace text must reach the engine exactly as typed: sanitizing
	 * would strip the very markup or code being searched for (e.g. "<iframe").
	 * Only administrators reach this, and the value is never echoed unescaped.
	 */
	private function raw_param(string $key): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by caller; raw by design, see above.
		$value = isset($_POST[ $key ]) ? (string) wp_unslash($_POST[ $key ]) : '';
		return str_replace("\0", '', $value);
	}

	private function flag(string $key): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		return isset($_POST[ $key ]) && '1' === (string) wp_unslash($_POST[ $key ]);
	}

	/**
	 * @return array<int,mixed>
	 */
	private function json_list(string $key): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded then sanitized by callers.
		$decoded = isset($_POST[ $key ]) ? json_decode((string) wp_unslash($_POST[ $key ]), true) : null;
		return is_array($decoded) ? array_values($decoded) : array();
	}

	private function table_param(string $key): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		$table = isset($_POST[ $key ]) ? preg_replace('/[^A-Za-z0-9_$]/', '', (string) wp_unslash($_POST[ $key ])) : '';
		return $this->db_search->is_valid_table((string) $table) ? (string) $table : '';
	}

	/**
	 * Selected items from the replace preview, or null to apply to everything.
	 *
	 * @return array<int,string>|null
	 */
	private function selection_param(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		if (! isset($_POST['selection'])) {
			return null;
		}
		return array_map(
			static function ($item): string {
				return str_replace("\0", '', (string) $item);
			},
			array_filter($this->json_list('selection'), 'is_scalar')
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_file_search_options(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.
		$key       = isset($_POST['directory']) ? sanitize_text_field((string) wp_unslash($_POST['directory'])) : 'wp-content';
		$directory = File_Search::resolve_directory($key);
		if (null === $directory) {
			wp_send_json_error(array('message' => __('Unknown folder selected.', 'diagnostics-toolkit')));
		}

		$defaults   = File_Search::default_options();
		$extensions = array_map(
			static function ($ext): string {
				return preg_replace('/[^a-z0-9]/', '', strtolower(ltrim((string) $ext, '.')));
			},
			array_filter($this->json_list('extensions'), 'is_scalar')
		);

		$exclude = isset($_POST['exclude_dirs']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map(
				static function ($dir): string {
					return trim(sanitize_text_field((string) $dir), " /\\");
				},
				array_filter($this->json_list('exclude_dirs'), 'is_scalar')
			)
			: $defaults['exclude_dirs'];
		// Never search our own undo backups.
		$exclude[] = 'wudt-search-backups';

		return array(
			'directory'      => $directory,
			'extensions'     => array_values(array_unique(array_filter($extensions))),
			'exclude_dirs'   => array_values(array_unique(array_filter($exclude))),
			'skip_minified'  => $this->flag('skip_minified'),
			'case_sensitive' => $this->flag('case_sensitive'),
			'whole_word'     => $this->flag('whole_word'),
			'regex'          => $this->flag('regex'),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_db_search_options(): array {
		$tables = array_map(
			static function ($table): string {
				return preg_replace('/[^A-Za-z0-9_$]/', '', (string) $table);
			},
			array_filter($this->json_list('tables'), 'is_scalar')
		);

		return array(
			'tables'         => array_values(array_filter($tables)),
			'case_sensitive' => $this->flag('case_sensitive'),
			'whole_word'     => $this->flag('whole_word'),
			'regex'          => $this->flag('regex'),
		);
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function respond(array $result): void {
		if (empty($result['success'])) {
			wp_send_json_error(array('message' => $result['error'] ?? __('Request failed.', 'diagnostics-toolkit')));
		}
		wp_send_json_success($result);
	}

	private function extend_time_limit(): void {
		if (function_exists('set_time_limit')) {
			@set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log(string $type, string $message, array $context = array()): void {
		if (class_exists(Operation_Logger::class)) {
			Operation_Logger::log($type, Search_Matcher::clip($message, 300), $context);
		}
	}
}
