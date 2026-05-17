<?php
/**
 * OpenAI-compatible service adapter.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Admin\Settings_Page;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Service {
	/**
	 * Map settings model values to actual API model names.
	 */
	private function get_model_mapping(): array {
		return array(
			'gemini-2.5-flash' => 'gemini-2.5-flash',
			'gemini' => 'gemini-2.5-flash',
			'gpt4' => 'gpt-4o',
			'gpt35' => 'gpt-3.5-turbo',
			'sonnet' => 'claude-3-sonnet',
			'opus' => 'claude-3-opus',
			'haiku' => 'claude-3-haiku',
		);
	}

	/**
	 * Get API endpoint based on selected provider.
	 */
	private function get_api_endpoint(string $provider, string $model = ''): string {
		switch ($provider) {
			case 'gemini':
				$model = $model ?: 'gemini-1.5-flash-latest';
				return 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
			case 'anthropic':
				return 'https://api.anthropic.com/v1/messages';
			case 'openrouter':
				return 'https://openrouter.ai/api/v1/chat/completions';
			case 'openai':
			default:
				return 'https://api.openai.com/v1/chat/completions';
		}
	}

	/**
	 * Determine whether the prompt explicitly asks for diagnostics or automated fixes.
	 */
	private function prompt_requires_diagnostics(string $prompt): bool {
		$keywords = array(
			'diagnos',
			'debug',
			'error',
			'issue',
			'problem',
			'fix',
			'repair',
			'troubleshoot',
			'not working',
			'broken',
			'fatal',
			'crash',
			'slow',
			'unable',
			'fail',
			'failure',
			'inspect',
			'investigate',
			'review'
		);
		$prompt_text = strtolower(trim($prompt));
		if ($prompt_text === '') {
			return false;
		}
		foreach ($keywords as $keyword) {
			if (false !== strpos($prompt_text, $keyword)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<int,array<string,string>> $history
	 * @return array<string,mixed>
	 */
	public function complete(string $prompt, array $context, array $history, array $options = array()): array {
		$api_key = $this->api_key();
		if ('' === $api_key) {
			return array(
				'content' => "AI API key is not configured. Please go to WP Diagnostics → Settings and configure your API key.\n\nFallback diagnosis:\n- Review recent PHP errors\n- Disable recently changed plugin/theme\n- Enable WP_DEBUG_LOG and inspect logs",
				'model'   => 'fallback',
			);
		}

		$provider = Settings_Page::get_ai_provider();
		$model_setting = Settings_Page::get_ai_model();
		$model_option = $options['model'] ?? '';
		$model = sanitize_text_field((string) (! empty($model_option) ? $model_option : $model_setting));

		// DEBUG LOGGING
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] === REQUEST START ===');
			error_log('[WUDT AI] Provider: ' . $provider);
			error_log('[WUDT AI] Model Setting: ' . $model_setting);
			error_log('[WUDT AI] Model Name: ' . $model);
			error_log('[WUDT AI] API Key (first 10 chars): ' . substr($api_key, 0, 10) . '...');
		}
		$mode = sanitize_key((string) ($options['mode'] ?? 'ask'));
		$temperature = Settings_Page::get_temperature();
		$max_tokens = Settings_Page::get_max_tokens();
		
		$agent_mode = 'agent' === $mode;
		
		if ($agent_mode) {
			$system_prompt = 'You are an expert WordPress administrator and developer assistant. You have full access to the WordPress database and file system.';
		} else {
			$system_prompt = 'You are a helpful AI assistant. Answer questions and provide assistance based on the user\'s request.';
		}
		
		if ($agent_mode) {
				$system_prompt .= "\n\n=== AGENT MODE ENABLED ===\n";
				$system_prompt .= "You are a DYNAMIC AI AGENT that can automatically fix WordPress issues.\n\n";
				$system_prompt .= "=== SUPERPOWERED AI AGENT - ALL CAPABILITIES ===\n";
				$system_prompt .= "PLUGIN MANAGEMENT:\n";
				$system_prompt .= "- install_plugin: INSTALL plugins from WordPress.org (requires 'plugin_slug', optional 'activate': true)\n";
				$system_prompt .= "- activate_plugin: ACTIVATE plugins (requires 'plugin' file path like 'elementor/elementor.php')\n";
				$system_prompt .= "- disable_plugin: DEACTIVATE plugins (requires 'plugin' file path)\n";
				$system_prompt .= "THEME MANAGEMENT:\n";
				$system_prompt .= "- install_theme: INSTALL themes from WordPress.org (requires 'theme_slug', optional 'activate': true)\n";
				$system_prompt .= "- activate_theme: SWITCH active theme (requires 'theme_slug')\n";
				$system_prompt .= "FILE OPERATIONS:\n";
				$system_prompt .= "- read_file: Read file contents (requires 'path')\n";
				$system_prompt .= "- edit_file: Modify files (requires 'path' and 'content')\n";
				$system_prompt .= "- create_file: Create new files (requires 'path' and 'content')\n";
				$system_prompt .= "- delete_file: Delete files (requires 'path')\n";
				$system_prompt .= "- chmod: Change file permissions (requires 'path' and 'mode' like 755)\n";
				$system_prompt .= "- rename: Rename/move files (requires 'old_path' and 'new_path')\n";
				$system_prompt .= "- compress: Create zip archives (requires 'paths' array and 'destination')\n";
				$system_prompt .= "- extract: Extract zip archives (requires 'archive' and 'destination')\n";
				$system_prompt .= "- list_directory: List directory contents (optional 'path')\n";
				$system_prompt .= "- search_files: Search text in files (requires 'query', optional 'path', 'extension')\n";
				$system_prompt .= "DATABASE OPERATIONS:\n";
				$system_prompt .= "- run_sql: Execute SQL queries (SELECT/INSERT/UPDATE/DELETE)\n";
				$system_prompt .= "- search_replace_db: Search/replace across all tables (requires 'search', optional 'replace', 'dry_run')\n";
				$system_prompt .= "- optimize_tables: OPTIMIZE database tables (optional 'tables' array)\n";
				$system_prompt .= "- repair_tables: REPAIR corrupted tables (optional 'tables' array)\n";
				$system_prompt .= "WORDPRESS CONFIGURATION:\n";
				$system_prompt .= "- toggle_wp_debug: Toggle WP_DEBUG in wp-config.php (requires 'enable': true/false)\n";
				$system_prompt .= "CRON SCHEDULING:\n";
				$system_prompt .= "- schedule_cron: Schedule WP cron events (requires 'hook', 'timestamp' or relative time like '+1 hour')\n";
				$system_prompt .= "- unschedule_cron: Remove scheduled events (requires 'hook')\n";
				$system_prompt .= "SYSTEM INFO:\n";
				$system_prompt .= "- get_system_info: Retrieve WordPress/PHP/server information\n";
				$system_prompt .= "\n=== CRITICAL RULES ===\n";
				$system_prompt .= "- ALWAYS use 'activate_plugin' action to activate plugins - NEVER use SQL for plugin activation\n";
				$system_prompt .= "- ALWAYS use 'disable_plugin' action to deactivate plugins - NEVER use SQL for plugin deactivation\n";
				$system_prompt .= "- SQL is ONLY for reading/updating database content like posts, users, options (not plugin status)\n";
				$system_prompt .= "- For plugin operations, the 'plugin' parameter must be the relative path from wp-content/plugins/ (e.g., 'elementor/elementor.php')\n";
				$system_prompt .= "\n=== ACTION EXECUTION PROTOCOL ===\n";
				$system_prompt .= "When the user asks you to perform changes (install/activate/modify), prefer emitting JSON action block(s) that the system can present to the user.\n";
				$system_prompt .= "Do NOT assume actions will be executed automatically; the system will present actions for explicit user approval unless the user explicitly asks for automatic execution.\n";
				$system_prompt .= "If additional inspection is required before acting, emit read_file or run_sql actions to gather the minimal information needed.\n\n";
				$system_prompt .= "=== JSON ACTION FORMAT ===\n";
				$system_prompt .= "Single action:\n";
				$system_prompt .= "```json\n";
				$system_prompt .= '{"action":"activate_plugin","plugin":"elementor/elementor.php","description":"Activate Elementor"}' . "\n";
				$system_prompt .= "```\n";
				$system_prompt .= "\nMultiple actions (array):\n";
				$system_prompt .= "```json\n";
				$system_prompt .= '[{"action":"read_file","path":"wp-config.php","description":"Check config"},{"action":"activate_plugin","plugin":"elementor/elementor.php","description":"Activate Elementor"}]' . "\n";
				$system_prompt .= "```\n";
				$system_prompt .= "\n=== RESPONSE FLOW ===\n";
				$system_prompt .= "When requested to perform changes (install/activate/modify), output the required JSON action block(s) as the primary response.\n";
				$system_prompt .= "Do NOT prepend lengthy diagnostic narratives unless the user explicitly asks for a diagnosis. A one-line summary is acceptable.\n";
				$system_prompt .= "Actions should be well-formed JSON objects or an array of objects following the JSON ACTION FORMAT above.\n";
				$system_prompt .= "If additional inspection is necessary before taking an action, request the minimum information or emit a read_file/run_sql action to gather it.\n";
			$system_prompt .= "\n\n=== AGENT MODE ENABLED ===\n";
			$system_prompt .= "You are a DYNAMIC AI AGENT that can automatically fix WordPress issues.\n\n";
			$system_prompt .= "=== SUPERPOWERED AI AGENT - ALL CAPABILITIES ===\n";
			$system_prompt .= "PLUGIN MANAGEMENT:\n";
			$system_prompt .= "- install_plugin: INSTALL plugins from WordPress.org (requires 'plugin_slug', optional 'activate': true)\n";
			$system_prompt .= "- activate_plugin: ACTIVATE plugins (requires 'plugin' file path like 'elementor/elementor.php')\n";
			$system_prompt .= "- disable_plugin: DEACTIVATE plugins (requires 'plugin' file path)\n";
			$system_prompt .= "THEME MANAGEMENT:\n";
			$system_prompt .= "- install_theme: INSTALL themes from WordPress.org (requires 'theme_slug', optional 'activate': true)\n";
			$system_prompt .= "- activate_theme: SWITCH active theme (requires 'theme_slug')\n";
			$system_prompt .= "FILE OPERATIONS:\n";
			$system_prompt .= "- read_file: Read file contents (requires 'path')\n";
			$system_prompt .= "- edit_file: Modify files (requires 'path' and 'content')\n";
			$system_prompt .= "- create_file: Create new files (requires 'path' and 'content')\n";
			$system_prompt .= "- delete_file: Delete files (requires 'path')\n";
			$system_prompt .= "- chmod: Change file permissions (requires 'path' and 'mode' like 755)\n";
			$system_prompt .= "- rename: Rename/move files (requires 'old_path' and 'new_path')\n";
			$system_prompt .= "- compress: Create zip archives (requires 'paths' array and 'destination')\n";
			$system_prompt .= "- extract: Extract zip archives (requires 'archive' and 'destination')\n";
			$system_prompt .= "- list_directory: List directory contents (optional 'path')\n";
			$system_prompt .= "- search_files: Search text in files (requires 'query', optional 'path', 'extension')\n";
			$system_prompt .= "DATABASE OPERATIONS:\n";
			$system_prompt .= "- run_sql: Execute SQL queries (SELECT/INSERT/UPDATE/DELETE)\n";
			$system_prompt .= "- search_replace_db: Search/replace across all tables (requires 'search', optional 'replace', 'dry_run')\n";
			$system_prompt .= "- optimize_tables: OPTIMIZE database tables (optional 'tables' array)\n";
			$system_prompt .= "- repair_tables: REPAIR corrupted tables (optional 'tables' array)\n";
			$system_prompt .= "WORDPRESS CONFIGURATION:\n";
			$system_prompt .= "- toggle_wp_debug: Toggle WP_DEBUG in wp-config.php (requires 'enable': true/false)\n";
			$system_prompt .= "CRON SCHEDULING:\n";
			$system_prompt .= "- schedule_cron: Schedule WP cron events (requires 'hook', 'timestamp' or relative time like '+1 hour')\n";
			$system_prompt .= "- unschedule_cron: Remove scheduled events (requires 'hook')\n";
			$system_prompt .= "SYSTEM INFO:\n";
			$system_prompt .= "- get_system_info: Retrieve WordPress/PHP/server information\n";
			$system_prompt .= "\n=== CRITICAL RULES ===\n";
			$system_prompt .= "- ALWAYS use 'activate_plugin' action to activate plugins - NEVER use SQL for plugin activation\n";
			$system_prompt .= "- ALWAYS use 'disable_plugin' action to deactivate plugins - NEVER use SQL for plugin deactivation\n";
			$system_prompt .= "- SQL is ONLY for reading/updating database content like posts, users, options (not plugin status)\n";
			$system_prompt .= "- For plugin operations, the 'plugin' parameter must be the relative path from wp-content/plugins/ (e.g., 'elementor/elementor.php')\n";
			$system_prompt .= "\n=== ACTION EXECUTION PROTOCOL ===\n";
			$system_prompt .= "When the user asks you to perform changes (install/activate/modify), prefer emitting JSON action block(s) that the system can present to the user.\n";
			$system_prompt .= "Do NOT assume actions will be executed automatically; the system will present actions for explicit user approval unless the user explicitly asks for automatic execution.\n";
			$system_prompt .= "If additional inspection is required before acting, emit read_file or run_sql actions to gather the minimal information needed.\n\n";
			$system_prompt .= "=== JSON ACTION FORMAT ===\n";
			$system_prompt .= "Single action:\n";
			$system_prompt .= "```json\n";
			$system_prompt .= '{"action":"activate_plugin","plugin":"elementor/elementor.php","description":"Activate Elementor"}' . "\n";
			$system_prompt .= "```\n";
			$system_prompt .= "\nMultiple actions (array):\n";
			$system_prompt .= "```json\n";
			$system_prompt .= '[{"action":"read_file","path":"wp-config.php","description":"Check config"},{"action":"activate_plugin","plugin":"elementor/elementor.php","description":"Activate Elementor"}]' . "\n";
			$system_prompt .= "```\n";
			$system_prompt .= "\n=== RESPONSE FLOW ===\n";
			$system_prompt .= "When requested to perform changes (install/activate/modify), output the required JSON action block(s) as the primary response.\n";
			$system_prompt .= "Do NOT prepend lengthy diagnostic narratives unless the user explicitly asks for a diagnosis. A one-line summary is acceptable.\n";
			$system_prompt .= "Actions should be well-formed JSON objects or an array of objects following the JSON ACTION FORMAT above.\n";
			$system_prompt .= "If additional inspection is necessary before taking an action, request the minimum information or emit a read_file/run_sql action to gather it.\n";
		} else {
			$system_prompt .= ' Ask mode: Explain clearly and suggest safe, manual troubleshooting steps only. Do NOT output JSON action blocks, automated fix plans, or action execution confirmations. If the user asks you to check or inspect something, only provide analysis and recommended manual checks. Do not mention that actions will be executed automatically or ask for confirmation unless the user explicitly requests execution.';
		}
		$messages = array(
			array(
				'role' => 'system',
				'content' => $system_prompt,
			),
		);
		foreach ($history as $item) {
			if (! empty($item['role']) && ! empty($item['content'])) {
				$messages[] = array(
					'role'    => (string) $item['role'],
					'content' => (string) $item['content'],
				);
			}
		}
		$messages[] = array(
			'role' => 'user',
			'content' => "User prompt:\n" . $prompt . "\n\nDiagnostics context:\n" . (string) wp_json_encode($context, JSON_PRETTY_PRINT),
		);

		$endpoint = $this->get_api_endpoint($provider, $model);
		$is_gemini = 'gemini' === $provider;
		$is_openrouter = 'openrouter' === $provider;
		
		// DEBUG LOGGING
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] Endpoint: ' . $endpoint);
		}
		
		$request_body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
		);

		if ($is_gemini) {
			// Gemini API uses a different format
			$gemini_content = '';
			foreach ($messages as $msg) {
				if ($msg['role'] === 'system') {
					$gemini_content .= "System instructions:\n" . $msg['content'] . "\n\n";
				} elseif ($msg['role'] === 'user') {
					$gemini_content .= "User request:\n" . $msg['content'] . "\n\n";
				} elseif ($msg['role'] === 'assistant') {
					$gemini_content .= "Previous response:\n" . $msg['content'] . "\n\n";
				}
			}
			
			$request_body = array(
				'contents' => array(
					array(
						'parts' => array(
							array('text' => $gemini_content),
						),
					),
				),
				'generationConfig' => array(
					'temperature' => $temperature,
					'maxOutputTokens' => $max_tokens,
				),
			);
			
			// Append API key to Gemini endpoint
			$endpoint = add_query_arg('key', $api_key, $endpoint);
			
			// DEBUG LOGGING
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[WUDT AI] Gemini Endpoint with key: ' . str_replace($api_key, '***API_KEY***', $endpoint));
				error_log('[WUDT AI] Gemini Request Body: ' . wp_json_encode($request_body));
			}
		} elseif ('anthropic' === $provider) {
			// Anthropic uses 'model', 'messages', 'max_tokens', and 'system' as top-level param
			$system_message = '';
			$anthropic_messages = array();
			foreach ($messages as $msg) {
				if ($msg['role'] === 'system') {
					$system_message = $msg['content'];
				} else {
					$anthropic_messages[] = array(
						'role' => $msg['role'],
						'content' => $msg['content'],
					);
				}
			}
			$request_body = array(
				'model' => $model,
				'messages' => $anthropic_messages,
				'max_tokens' => $max_tokens,
				'system' => $system_message,
			);
		}

		$body_json = wp_json_encode($request_body);

		// Build headers for cURL
		$headers = array('Content-Type: application/json');
		if (! $is_gemini) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
		}
		if ($is_openrouter) {
			$headers[] = 'HTTP-Referer: ' . get_site_url();
			$headers[] = 'X-Title: WP Ultimate Diagnostics Toolkit';
		}

		// DEBUG LOGGING
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] Request Body: ' . $body_json);
			error_log('[WUDT AI] Request Headers: ' . wp_json_encode($headers));
		}

		// Use cURL for reliable Authorization header support
		$status_code = 0;
		$body = '';
		if (function_exists('curl_init')) {
			$ch = curl_init($endpoint);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

			$body = curl_exec($ch);
			$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_error = curl_error($ch);
			curl_close($ch);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[WUDT AI] cURL Status: ' . $status_code);
				if ($curl_error) {
					error_log('[WUDT AI] cURL Error: ' . $curl_error);
				}
			}

			if ($body === false || $curl_error) {
				return array('content' => 'AI request failed (cURL): ' . ($curl_error ?: 'Unknown error'), 'model' => $model);
			}
		} else {
			// Fallback to wp_remote_post if cURL not available
			$request_args = array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
				),
				'body' => $body_json,
				'sslverify' => false,
			);

			if (! $is_gemini) {
				$request_args['headers']['Authorization'] = 'Bearer ' . $api_key;
			}
			if ($is_openrouter) {
				$request_args['headers']['HTTP-Referer'] = get_site_url();
				$request_args['headers']['X-Title'] = 'WP Ultimate Diagnostics Toolkit';
			}

			$response = wp_remote_post($endpoint, $request_args);
			if (is_wp_error($response)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[WUDT AI] WP ERROR: ' . $response->get_error_message());
				}
				return array('content' => 'AI request failed: ' . $response->get_error_message(), 'model' => $model);
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
		}

		$data = json_decode((string) $body, true);
		
		// DEBUG LOGGING
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] Response Status: ' . $status_code);
			error_log('[WUDT AI] Response Body: ' . substr($body, 0, 2000)); // Log first 2000 chars
			error_log('[WUDT AI] Parsed Data Keys: ' . implode(', ', array_keys($data ?? array())));
			if (isset($data['error'])) {
				error_log('[WUDT AI] Error Details: ' . wp_json_encode($data['error']));
			}
			if (isset($data['candidates'])) {
				error_log('[WUDT AI] Candidates Count: ' . count($data['candidates']));
				if (isset($data['candidates'][0])) {
					error_log('[WUDT AI] First Candidate Keys: ' . implode(', ', array_keys($data['candidates'][0])));
					if (isset($data['candidates'][0]['finishReason'])) {
						error_log('[WUDT AI] Finish Reason: ' . $data['candidates'][0]['finishReason']);
					}
				}
			}
		}

		// Handle API errors
		if ($status_code < 200 || $status_code >= 300) {
			$error_msg = isset($data['error']['message']) ? $data['error']['message'] : (isset($data['error']['details'][0]['description']) ? $data['error']['details'][0]['description'] : 'HTTP ' . $status_code);
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[WUDT AI] API ERROR: ' . $error_msg);
				if (isset($data['error']['details'])) {
					error_log('[WUDT AI] Error Details Full: ' . wp_json_encode($data['error']['details']));
				}
			}
			return array('content' => 'AI API error (' . $provider . '): ' . $error_msg, 'model' => $model, 'raw' => $data);
		}

		// Parse response based on provider format
		$content = '';
		if ($is_gemini) {
			if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
				$content = (string) $data['candidates'][0]['content']['parts'][0]['text'];
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[WUDT AI] Gemini Content Retrieved: ' . substr($content, 0, 100) . '...');
				}
			} elseif (isset($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] !== 'STOP') {
				$content = 'Response blocked: ' . $data['candidates'][0]['finishReason'];
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[WUDT AI] Gemini Blocked: ' . $data['candidates'][0]['finishReason']);
				}
			} else {
				$content = 'No response from Gemini. Check API key and model configuration.';
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[WUDT AI] Gemini No Response - Full Data: ' . wp_json_encode($data));
				}
			}
		} else {
			$content = (string) ($data['choices'][0]['message']['content'] ?? 'No response.');
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] === REQUEST END ===');
		}
		
		// Prepare debug info for chat display
		$debug_info = '';
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$debug_info .= "=== AI DEBUG INFO ===\n\n";
			$debug_info .= "REQUEST:\n";
			$debug_info .= "Endpoint: " . str_replace($api_key, '***API_KEY***', $endpoint) . "\n";
			$debug_info .= "Model Setting: " . $model_setting . "\n";
			$debug_info .= "Model Used: " . $model . "\n\n";
			$debug_info .= "Request Body:\n" . wp_json_encode($request_body, JSON_PRETTY_PRINT) . "\n\n";
			$debug_info .= "RESPONSE:\n";
			$debug_info .= "Status Code: " . $status_code . "\n";
			$debug_info .= "Response Body:\n" . wp_json_encode($data, JSON_PRETTY_PRINT) . "\n";
			$debug_info .= "=== END DEBUG INFO ===\n\n";
		}
		
		return array(
			'content'    => $content,
			'model'      => $model,
			'raw'        => $data,
			'debug_info' => "",
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function list_models(): array {
		$provider = Settings_Page::get_ai_provider();

		// Return default models based on selected provider
		switch ($provider) {
			case 'gemini':
				return array(
					'gemini-2.5-flash',
					'gemini-1.5-flash-latest',
					'gemini-1.5-pro-latest',
					'gemini-1.0-pro-latest'
				);
			case 'anthropic':
				return array('claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku');
			case 'openrouter':
				return array(
					'mistralai/mistral-7b-instruct',
					'mistralai/mixtral-8x7b',
					'google/gemini-2.5-flash-preview',
					'openai/gpt-4o',
					'openai/gpt-4o-mini',
					'openai/gpt-3.5-turbo',
					'anthropic/claude-3.5-sonnet',
					'anthropic/claude-3-haiku',
					'meta-llama/llama-3-8b-instruct',
					'meta-llama/llama-3-70b-instruct',
					'microsoft/wizardlm-2-8x22b',
					'nousresearch/nous-capybara-34b'
				);
			case 'openai':
			default:
				return array('gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo');
		}
	}

	public function api_key(): string {
		// Check constant first (for advanced users)
		$constant = defined('WUDT_AI_API_KEY') ? (string) WUDT_AI_API_KEY : '';
		if ('' !== $constant) {
			return $constant;
		}
		// Use Settings_Page to get the API key
		return Settings_Page::get_api_key();
	}
}
