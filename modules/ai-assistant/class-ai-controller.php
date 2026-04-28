<?php
/**
 * AI assistant controller and AJAX endpoints.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Controller extends Module_Base {
	private Context_Builder $context_builder;
	private AI_Service $service;
	private Response_Parser $parser;

	public function __construct() {
		$this->context_builder = new Context_Builder();
		$this->service         = new AI_Service();
		$this->parser          = new Response_Parser();
	}

	public function register_hooks(): void {
		add_action('admin_init', array($this, 'maybe_create_table'));
		add_action('wp_ajax_diagnostics_ai_chat', array($this, 'ajax_chat'));
		add_action('wp_ajax_diagnostics_ai_chat_stream', array($this, 'ajax_chat_stream'));
		add_action('wp_ajax_diagnostics_ai_history', array($this, 'ajax_history'));
		add_action('wp_ajax_diagnostics_ai_clear_history', array($this, 'ajax_clear_history'));
		add_action('wp_ajax_diagnostics_ai_apply_fix', array($this, 'ajax_apply_fix'));
		add_action('wp_ajax_diagnostics_ai_autodebug', array($this, 'ajax_autodebug'));
		add_action('wp_ajax_diagnostics_ai_models', array($this, 'ajax_models'));
		add_action('wp_ajax_diagnostics_ai_test_api', array($this, 'ajax_test_api'));
	}

	public function get_key(): string {
		return 'ai_assistant';
	}

	public function get_label(): string {
		return __('AI Assistant', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'history' => $this->get_history(20),
			'rate_limit' => array('max_per_minute' => 12),
			'modes' => array('ask', 'agent'),
			'models' => $this->service->list_models(),
		);
	}

	public function maybe_create_table(): void {
		global $wpdb;
		$table = $this->table_name();
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists === $table) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			content LONGTEXT NOT NULL,
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta($sql);
	}

	public function ajax_chat(): void {
		Security_Guard::assert_ajax_admin();
		$this->enforce_rate_limit();
		$prompt   = sanitize_textarea_field((string) wp_unslash($_POST['prompt'] ?? ''));
		$flags    = isset($_POST['context_flags']) ? (array) json_decode((string) wp_unslash($_POST['context_flags']), true) : array();
		$file     = isset($_POST['file_content']) ? (string) wp_unslash($_POST['file_content']) : '';
		$chat_txt = isset($_POST['chat_transcript']) ? (string) wp_unslash($_POST['chat_transcript']) : '';
		$model    = sanitize_text_field((string) wp_unslash($_POST['model'] ?? ''));
		$mode     = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'ask'));
		$history  = $this->get_history(10);
		$context  = $this->context_builder->build($prompt, $flags, $file, $chat_txt);
		$result   = $this->service->complete($prompt, $context, $history, array('model' => $model, 'mode' => $mode));
		$parsed   = $this->parser->parse((string) ($result['content'] ?? ''));
		if ('agent' !== $mode) {
			$parsed['action'] = array();
		}
		$this->push_history('user', $prompt, $context);
		$this->push_history('assistant', (string) ($parsed['text'] ?? ''), array('model' => $result['model'] ?? 'unknown', 'mode' => $mode));
		Operation_Logger::log('ai', 'AI diagnosis generated', array('prompt_len' => strlen($prompt)));
		$response = array(
			'message' => $parsed['text'] ?? '',
			'action'  => $parsed['action'] ?? array(),
			'model'   => $result['model'] ?? 'unknown',
		);
		
		// Include debug info if available (when WP_DEBUG is enabled)
		if (! empty($result['debug_info'])) {
			$response['debug_info'] = $result['debug_info'];
		}
		
		wp_send_json_success($response);
	}

	public function ajax_chat_stream(): void {
		Security_Guard::assert_ajax_admin();
		$this->enforce_rate_limit();
		$prompt  = sanitize_textarea_field((string) wp_unslash($_POST['prompt'] ?? ''));
		$flags   = isset($_POST['context_flags']) ? (array) json_decode((string) wp_unslash($_POST['context_flags']), true) : array();
		$file    = isset($_POST['file_content']) ? (string) wp_unslash($_POST['file_content']) : '';
		$chat_txt = isset($_POST['chat_transcript']) ? (string) wp_unslash($_POST['chat_transcript']) : '';
		$model   = sanitize_text_field((string) wp_unslash($_POST['model'] ?? ''));
		$mode    = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'ask'));
		$history = $this->get_history(10);
		$context = $this->context_builder->build($prompt, $flags, $file, $chat_txt);
		$result  = $this->service->complete($prompt, $context, $history, array('model' => $model, 'mode' => $mode));
		$text    = (string) ($result['content'] ?? '');
		$parsed  = $this->parser->parse($text);
		$text    = (string) ($parsed['text'] ?? $text);
		if ('agent' !== $mode) {
			$parsed['action'] = array();
		}
		$this->push_history('user', $prompt, $context);
		$this->push_history('assistant', $text, array('streamed' => true, 'mode' => $mode, 'model' => $model));

		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		$chunk_size = 260;
		$total = strlen($text);
		for ($i = 0; $i < $total; $i += $chunk_size) {
			$chunk = substr($text, $i, $chunk_size);
			echo wp_json_encode(array('type' => 'chunk', 'content' => $chunk)) . "\n";
			@ob_flush();
			@flush();
			usleep(30000);
		}
		// Include debug info if available (when WP_DEBUG is enabled)
		if (! empty($result['debug_info'])) {
			echo wp_json_encode(array('type' => 'debug', 'content' => $result['debug_info'])) . "\n";
			@ob_flush();
			@flush();
		}
		echo wp_json_encode(array(
			'type'     => 'done',
			'action'   => $parsed['action'] ?? array(),
			'actions'  => $parsed['actions'] ?? array()
		)) . "\n";
		exit;
	}

	public function ajax_history(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('history' => $this->get_history(80)));
	}

	public function ajax_clear_history(): void {
		Security_Guard::assert_ajax_admin();
		global $wpdb;
		$wpdb->delete($this->table_name(), array('user_id' => get_current_user_id()), array('%d'));
		wp_send_json_success(array('cleared' => true));
	}

	public function ajax_apply_fix(): void {
		Security_Guard::assert_ajax_admin();
		$action = sanitize_key((string) wp_unslash($_POST['action_type'] ?? ''));
		$params = isset($_POST['params']) ? (array) json_decode((string) wp_unslash($_POST['params']), true) : array();
		$result = array('applied' => false, 'message' => '', 'data' => null);

		switch ($action) {
			case 'disable_plugin':
				$result = $this->action_disable_plugin($params);
				break;
			case 'run_sql':
				$result = $this->action_run_sql($params);
				break;
			case 'edit_file':
				$result = $this->action_edit_file($params);
				break;
			case 'create_file':
				$result = $this->action_create_file($params);
				break;
			case 'delete_file':
				$result = $this->action_delete_file($params);
				break;
			case 'read_file':
				$result = $this->action_read_file($params);
				break;
			default:
				$result['message'] = 'Unknown action: ' . $action;
				break;
		}

		if ($result['applied']) {
			Operation_Logger::log('ai', 'AI action applied: ' . $action, array('params' => $params));
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Disable a plugin action
	 */
	private function action_disable_plugin(array $params): array {
		if (empty($params['plugin'])) {
			return array('applied' => false, 'message' => 'Plugin parameter missing');
		}
		$plugin = sanitize_text_field((string) $params['plugin']);
		$protected = array('wp-ultimate-diagnostics-toolkit/wp-ultimate-diagnostics-toolkit.php');
		if (in_array($plugin, $protected, true)) {
			return array('applied' => false, 'message' => 'Cannot disable this plugin');
		}
		if (!is_plugin_active($plugin)) {
			return array('applied' => false, 'message' => 'Plugin is not active');
		}
		deactivate_plugins($plugin, true);
		return array('applied' => true, 'message' => 'Plugin disabled: ' . $plugin);
	}

	/**
	 * Run SQL query action
	 */
	private function action_run_sql(array $params): array {
		global $wpdb;
		if (empty($params['sql'])) {
			return array('applied' => false, 'message' => 'SQL query missing');
		}
		$sql = sanitize_textarea_field((string) $params['sql']);
		$readonly = !empty($params['readonly']);

		// Block dangerous queries
		$dangerous = array('DROP DATABASE', 'TRUNCATE DATABASE', 'ALTER DATABASE', 'CREATE DATABASE', 'GRANT', 'REVOKE');
		$sql_upper = strtoupper($sql);
		foreach ($dangerous as $word) {
			if (strpos($sql_upper, $word) !== false) {
				return array('applied' => false, 'message' => 'Query contains blocked keyword: ' . $word);
			}
		}

		// Determine query type
		$is_select = strpos($sql_upper, 'SELECT') === 0 || strpos($sql_upper, 'SHOW') === 0 || strpos($sql_upper, 'DESCRIBE') === 0;
		$is_write = !$is_select && (strpos($sql_upper, 'INSERT') === 0 || strpos($sql_upper, 'UPDATE') === 0 || strpos($sql_upper, 'DELETE') === 0);

		if ($readonly && $is_write) {
			return array('applied' => false, 'message' => 'Write operations not allowed in readonly mode');
		}

		// Replace wp_ prefix placeholder
		$sql = str_replace('wp_', $wpdb->prefix, $sql);

		$wpdb->suppress_errors = true;
		if ($is_select) {
			$rows = $wpdb->get_results($sql, ARRAY_A);
			if ($wpdb->last_error) {
				return array('applied' => false, 'message' => 'SQL Error: ' . $wpdb->last_error);
			}
			return array(
				'applied' => true,
				'message' => 'Query executed successfully. Rows: ' . count($rows),
				'data' => array('rows' => $rows, 'row_count' => count($rows))
			);
		} else {
			$rows_affected = $wpdb->query($sql);
			if ($wpdb->last_error) {
				return array('applied' => false, 'message' => 'SQL Error: ' . $wpdb->last_error);
			}
			return array(
				'applied' => true,
				'message' => 'Query executed. Rows affected: ' . $rows_affected,
				'data' => array('rows_affected' => $rows_affected)
			);
		}
	}

	/**
	 * Edit file action
	 */
	private function action_edit_file(array $params): array {
		if (empty($params['path']) || !isset($params['content'])) {
			return array('applied' => false, 'message' => 'File path or content missing');
		}
		$path = sanitize_text_field((string) $params['path']);
		$content = (string) wp_unslash($params['content']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath) {
			// File doesn't exist yet, try to resolve parent
			$fullpath = $path;
		}
		if (strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path - must be within WordPress directory');
		}

		if (!file_exists($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist: ' . $path);
		}

		// Check if writable
		if (!is_writable($fullpath)) {
			return array('applied' => false, 'message' => 'File is not writable: ' . $path);
		}

		// Create backup before editing
		$backup_path = $fullpath . '.backup.' . time();
		copy($fullpath, $backup_path);

		$result = file_put_contents($fullpath, $content, LOCK_EX);
		if ($result === false) {
			return array('applied' => false, 'message' => 'Failed to write file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File edited successfully: ' . $path,
			'data' => array('bytes_written' => $result, 'backup_path' => $backup_path)
		);
	}

	/**
	 * Create file action
	 */
	private function action_create_file(array $params): array {
		if (empty($params['path']) || !isset($params['content'])) {
			return array('applied' => false, 'message' => 'File path or content missing');
		}
		$path = sanitize_text_field((string) $params['path']);
		$content = (string) wp_unslash($params['content']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		if (strpos($path, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path - must be within WordPress directory');
		}

		if (file_exists($path)) {
			return array('applied' => false, 'message' => 'File already exists: ' . $path);
		}

		// Ensure directory exists
		$dir = dirname($path);
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		$result = file_put_contents($path, $content, LOCK_EX);
		if ($result === false) {
			return array('applied' => false, 'message' => 'Failed to create file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File created successfully: ' . $path,
			'data' => array('bytes_written' => $result)
		);
	}

	/**
	 * Delete file action
	 */
	private function action_delete_file(array $params): array {
		if (empty($params['path'])) {
			return array('applied' => false, 'message' => 'File path missing');
		}
		$path = sanitize_text_field((string) $params['path']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath || strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path');
		}

		// Block deletion of critical files
		$protected_files = array('wp-config.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php');
		$basename = basename($fullpath);
		if (in_array($basename, $protected_files, true)) {
			return array('applied' => false, 'message' => 'Cannot delete critical WordPress file: ' . $basename);
		}

		if (!file_exists($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist: ' . $path);
		}

		if (!is_writable($fullpath)) {
			return array('applied' => false, 'message' => 'File is not deletable: ' . $path);
		}

		if (unlink($fullpath)) {
			return array('applied' => true, 'message' => 'File deleted: ' . $path);
		} else {
			return array('applied' => false, 'message' => 'Failed to delete file: ' . $path);
		}
	}

	/**
	 * Read file action
	 */
	private function action_read_file(array $params): array {
		if (empty($params['path'])) {
			return array('applied' => false, 'message' => 'File path missing');
		}
		$path = sanitize_text_field((string) $params['path']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath || strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path');
		}

		if (!file_exists($fullpath) || !is_readable($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist or is not readable: ' . $path);
		}

		// Limit file size to prevent memory issues
		$max_size = 1024 * 1024; // 1MB
		$size = filesize($fullpath);
		if ($size > $max_size) {
			return array('applied' => false, 'message' => 'File too large to read (>1MB)');
		}

		$content = file_get_contents($fullpath);
		if ($content === false) {
			return array('applied' => false, 'message' => 'Failed to read file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File read successfully: ' . $path,
			'data' => array(
				'content' => $content,
				'size' => $size,
				'modified' => date('Y-m-d H:i:s', filemtime($fullpath))
			)
		);
	}

	public function ajax_autodebug(): void {
		Security_Guard::assert_ajax_admin();
		$error_entries = (array) get_option('wudt_error_log_entries', array());
		$recent = array_slice($error_entries, -5);
		$malware = (array) get_option('wudt_enterprise_malware_results', array());
		$signal = array(
			'new_errors' => $recent,
			'malware_hits' => count($malware),
		);
		$prompt = 'Perform proactive diagnostics summary and recommend immediate safe actions.';
		$result = $this->service->complete($prompt, $signal, array(), array('mode' => 'agent'));
		wp_send_json_success(array('suggestion' => (string) ($result['content'] ?? '')));
	}

	public function ajax_models(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('models' => $this->service->list_models()));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_history(int $limit = 20): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, created_at FROM {$this->table_name()} WHERE user_id=%d ORDER BY id DESC LIMIT %d",
				get_current_user_id(),
				$limit
			),
			ARRAY_A
		);
		$rows = is_array($rows) ? array_reverse($rows) : array();
		return $rows;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function push_history(string $role, string $content, array $context = array()): void {
		global $wpdb;
		$wpdb->insert(
			$this->table_name(),
			array(
				'user_id'     => get_current_user_id(),
				'role'        => sanitize_key($role),
				'content'     => $content,
				'context_json'=> wp_json_encode($context),
				'created_at'  => current_time('mysql'),
			),
			array('%d', '%s', '%s', '%s', '%s')
		);
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wudt_ai_chat_history';
	}

	private function enforce_rate_limit(): void {
		$key = 'wudt_ai_rate_' . get_current_user_id();
		$count = (int) get_transient($key);
		if ($count >= 12) {
			wp_send_json_error(array('message' => 'Rate limit reached. Please wait a minute.'), 429);
		}
		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
	}

	/**
	 * Test API connection and return diagnostic info
	 */
	public function ajax_test_api(): void {
		Security_Guard::assert_ajax_admin();
		
		$api_key = $this->service->api_key();
		$model_setting = 'gemini-2.5-flash';//\WUDT\Admin\Settings_Page::get_ai_model();
		$model =  "gemini-2.5-flash"; //sanitize_text_field((string) ($_POST['model'] ?? ''));
		
		if ('' === $api_key) {
			wp_send_json_error(array('message' => 'API key not configured'));
			return;
		}
		
		// Test with a simple prompt
		$test_prompt = 'Say "API connection successful" and nothing else.';
		
		// Build endpoint manually for testing
		$endpoint = '';
		if ($model_setting === 'gemini' || $model_setting === 'gemini-2.5-flash') {
			$model_to_use = $model ?: 'gemini-2.5-flash';
			$endpoint = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=AIzaSyCDWEvjG6Og0-Is_bfWfsPEz1VbvsaNd4k';
			// $endpoint = 'https://generativelanguage.googleapis.com/v1/models/' . $model_to_use . ':generateContent?key=' . $api_key;
		} else {
			wp_send_json_error(array('message' => 'Test only supports Gemini currently'));
			return;
		}
		
		// Make direct test request
		$request_body = array(
			'contents' => array(
				array(
					'parts' => array(
						array('text' => $test_prompt),
					),
				),
			),
			'generationConfig' => array(
				'temperature' => 0.1,
				'maxOutputTokens' => 50,
			),
		);
		
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array('Content-Type' => 'application/json'),
				'body' => wp_json_encode($request_body),
			)
		);
		
		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string) $body, true);
		
		$result = array(
			'model_setting' =>   $model_setting,
			'model_used' => $model ?: 'gemini-2.5-flash',
			'endpoint' => str_replace($api_key, '***', $endpoint),
			'status_code' => $status_code,
			'raw_response' => $data,
		);
		
		if ($status_code >= 200 && $status_code < 300) {
			if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
				$result['success'] = true;
				$result['response_text'] = $data['candidates'][0]['content']['parts'][0]['text'];
				wp_send_json_success($result);
			} else {
				$result['success'] = false;
				$result['error'] = 'No content in response';
				wp_send_json_error($result);
			}
		} else {
			$result['success'] = false;
			$result['error'] = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $status_code;
			wp_send_json_error($result);
		}
	}
}
