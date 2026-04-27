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
		echo wp_json_encode(array('type' => 'done', 'action' => $parsed['action'] ?? array())) . "\n";
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
		$applied = false;

		if ('disable_plugin' === $action && ! empty($params['plugin'])) {
			$plugin = sanitize_text_field((string) $params['plugin']);
			$protected = array('akismet/akismet.php');
			if (! in_array($plugin, $protected, true)) {
				deactivate_plugins($plugin, true);
				$applied = true;
				Operation_Logger::log('ai', 'AI auto-fix applied: plugin disabled', array('plugin' => $plugin));
			}
		}
		wp_send_json_success(array('applied' => $applied));
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
