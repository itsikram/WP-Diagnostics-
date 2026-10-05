<?php
/**
 * Simple text-completion service (used by legacy endpoints and summaries).
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Includes\AI_Config;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Service {
	private AI_Client $client;

	public function __construct() {
		$this->client = new AI_Client();
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<int,array<string,string>> $history
	 * @return array<string,mixed>
	 */
	public function complete(string $prompt, array $context, array $history, array $options = array()): array {
		$provider = AI_Config::active_provider();
		$model = ! empty($options['model']) ? sanitize_text_field((string) $options['model']) : AI_Config::get_model($provider);
		if ('' === AI_Config::get_key($provider)) {
			return array(
				'content' => 'No AI API key is configured. Open Diagnostics Toolkit → AI Assistant → Settings and add a Gemini or Claude API key.',
				'model'   => 'none',
			);
		}

		$system = 'You are an expert WordPress developer and administrator helping a site owner. Explain clearly and give safe, concrete steps.';
		$messages = array();
		foreach ($history as $item) {
			$role = ('assistant' === ($item['role'] ?? '')) ? 'assistant' : 'user';
			if (! empty($item['content'])) {
				$messages[] = 'assistant' === $role
					? array('role' => 'assistant', 'text' => (string) $item['content'], 'calls' => array(), 'provider' => 'history')
					: array('role' => 'user', 'text' => (string) $item['content']);
			}
		}
		$text = $prompt;
		if (! empty($context)) {
			$text .= "\n\nSite diagnostics context:\n" . wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		}
		$messages[] = array('role' => 'user', 'text' => $text);

		try {
			$res = $this->client->chat($provider, $model, $system, $messages, array(), array('max_tokens' => min(8000, AI_Config::max_tokens())));
			return array('content' => $res['text'], 'model' => $model);
		} catch (\Throwable $e) {
			return array('content' => 'AI request failed: ' . $e->getMessage(), 'model' => $model);
		}
	}

	/**
	 * @return array<int,string>
	 */
	public function list_models(): array {
		return $this->client->list_models(AI_Config::active_provider());
	}

	public function api_key(): string {
		return AI_Config::get_key(AI_Config::active_provider());
	}
}
