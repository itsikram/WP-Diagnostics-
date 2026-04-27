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
			'gemini-2.5-flash' => 'gemini-2.5-flash-preview-05-20',
			'gemini' => 'gemini-2.5-flash-preview-05-20',
			'gpt4' => 'gpt-4o',
			'gpt35' => 'gpt-3.5-turbo',
			'sonnet' => 'claude-3-sonnet',
			'opus' => 'claude-3-opus',
			'haiku' => 'claude-3-haiku',
		);
	}

	/**
	 * Get API endpoint based on selected model.
	 */
	private function get_api_endpoint(string $model_setting): string {

		return "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=AIzaSyCDWEvjG6Og0-Is_bfWfsPEz1VbvsaNd4k";
		switch ($model_setting) {
			case 'gemini-2.5-flash':
			case 'gemini':
				return 'https://generativelanguage.googleapis.com/v1/models/' . $this->get_model_mapping()['gemini-2.5-flash'] . ':generateContent';
			case 'sonnet':
			case 'opus':
			case 'haiku':
				return 'https://api.anthropic.com/v1/messages';
			case 'gpt4':
			case 'gpt35':
			default:
				return 'https://api.openai.com/v1/chat/completions';
		}
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

		$model_setting = Settings_Page::get_ai_model();
		$model_mapping = $this->get_model_mapping();
		$model = sanitize_text_field((string) ($options['model'] ?? ($model_mapping[$model_setting] ?? 'gpt-4o-mini')));

		// DEBUG LOGGING
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[WUDT AI] === REQUEST START ===');
			error_log('[WUDT AI] Model Setting: ' . $model_setting);
			error_log('[WUDT AI] Model Name: ' . $model);
			error_log('[WUDT AI] API Key (first 10 chars): ' . substr($api_key, 0, 10) . '...');
		}
		$mode     = sanitize_key((string) ($options['mode'] ?? 'ask'));
		$temperature = Settings_Page::get_temperature();
		$max_tokens = Settings_Page::get_max_tokens();
		$system_prompt = 'You are an expert WordPress debugging assistant. Use provided diagnostics context. Keep suggestions safe and practical.';
		if ('agent' === $mode) {
			$system_prompt .= ' Agent mode is enabled: propose concrete, executable remediation steps. If a safe action is possible, include a JSON object with keys action and parameters.';
		} else {
			$system_prompt .= ' Ask mode is enabled: explain clearly and suggest steps, but do not output automation actions.';
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

		$endpoint = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=AIzaSyCDWEvjG6Og0-Is_bfWfsPEz1VbvsaNd4k"; //$this->get_api_endpoint($model_setting);
		
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

		// Gemini API uses a different format
		if ($model_setting === 'gemini' || $model_setting === 'gemini-2.5-flash') {
			// $endpoint .= '?key=' . $api_key;
			$request_body = array(
				'contents' => array(
					array(
						'parts' => array(
							array('text' => $messages[0]['content'] . "\n\n" . $messages[count($messages) - 1]['content']),
						),
					),
				),
				'generationConfig' => array(
					'temperature' => $temperature,
					'maxOutputTokens' => $max_tokens,
				),
			);
			
			// DEBUG LOGGING
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[WUDT AI] Gemini Endpoint with key: ' . str_replace($api_key, '***API_KEY***', $endpoint));
				error_log('[WUDT AI] Gemini Request Body: ' . wp_json_encode($request_body));
			}
		}

		$request_args = array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode($request_body),
		);

		// Gemini uses API key in query param, not Authorization header
		if ($model_setting !== 'gemini' && $model_setting !== 'gemini-2.5-flash') {
			$request_args['headers']['Authorization'] = 'Bearer ' . $api_key;
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
			return array('content' => 'AI API error (' . $model_setting . '): ' . $error_msg, 'model' => $model, 'raw' => $data);
		}

		// Parse response based on provider format
		$content = '';
		if ($model_setting === 'gemini' || $model_setting === 'gemini-2.5-flash') {
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
		$api_key = $this->api_key();
		$model_setting = Settings_Page::get_ai_model();

		if ('' === $api_key) {
			// Return default models based on selected provider
			switch ($model_setting) {
				case 'gemini':
					return array('gemini-1.5-flash-latest', 'gemini-1.5-pro-latest', 'gemini-1.0-pro-latest');
				case 'sonnet':
				case 'opus':
				case 'haiku':
					return array('claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku');
				case 'gpt4':
				case 'gpt35':
				default:
					return array('gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo');
			}
		}

		// Try to fetch models from the selected provider's API
		$endpoint = 'https://api.openai.com/v1/models';
		if ($model_setting === 'gemini') {
			return array('gemini-1.5-flash-latest', 'gemini-1.5-pro-latest', 'gemini-1.0-pro-latest');
		} elseif (in_array($model_setting, array('sonnet', 'opus', 'haiku'), true)) {
			return array('claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku');
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array('Authorization' => 'Bearer ' . $api_key),
			)
		);
		if (is_wp_error($response)) {
			return array('gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo');
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		$models = array();
		foreach ((array) ($data['data'] ?? array()) as $item) {
			if (! empty($item['id'])) {
				$models[] = (string) $item['id'];
			}
		}
		sort($models);
		return array_slice($models, 0, 300);
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
