<?php
/**
 * OpenAI-compatible service adapter.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Service {
	/**
	 * @param array<string,mixed> $context
	 * @param array<int,array<string,string>> $history
	 * @return array<string,mixed>
	 */
	public function complete(string $prompt, array $context, array $history, array $options = array()): array {
		$api_key = $this->api_key();
		if ('' === $api_key) {
			return array(
				'content' => "AI API key is not configured. Add `WUDT_AI_API_KEY` in wp-config.php or set option `wudt_ai_api_key`.\n\nFallback diagnosis:\n- Review recent PHP errors\n- Disable recently changed plugin/theme\n- Enable WP_DEBUG_LOG and inspect logs",
				'model'   => 'fallback',
			);
		}

		$endpoint = (string) get_option('wudt_ai_endpoint', 'https://api.openai.com/v1/chat/completions');
		$model    = sanitize_text_field((string) ($options['model'] ?? get_option('wudt_ai_model', 'gpt-4o-mini')));
		$mode     = sanitize_key((string) ($options['mode'] ?? 'ask'));
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

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => 0.2,
					)
				),
			)
		);
		if (is_wp_error($response)) {
			return array('content' => 'AI request failed: ' . $response->get_error_message(), 'model' => $model);
		}
		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string) $body, true);
		$content = (string) ($data['choices'][0]['message']['content'] ?? 'No response.');
		return array(
			'content' => $content,
			'model'   => $model,
			'raw'     => $data,
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function list_models(): array {
		$api_key = $this->api_key();
		if ('' === $api_key) {
			return array('gpt-5', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini', 'o4-mini');
		}
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 30,
				'headers' => array('Authorization' => 'Bearer ' . $api_key),
			)
		);
		if (is_wp_error($response)) {
			return array('gpt-5', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini', 'o4-mini');
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

	private function api_key(): string {
		$constant = defined('WUDT_AI_API_KEY') ? (string) WUDT_AI_API_KEY : '';
		if ('' !== $constant) {
			return $constant;
		}
		return (string) get_option('wudt_ai_api_key', '');
	}
}
