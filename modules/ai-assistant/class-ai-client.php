<?php
/**
 * AI Client - one tool-calling interface over Anthropic Claude, Google Gemini
 * and OpenAI-compatible APIs.
 *
 * Conversation messages use a provider-neutral format:
 *   ['role' => 'user', 'text' => string]
 *   ['role' => 'assistant', 'text' => string, 'calls' => [[id, name, args]], 'provider' => string, 'native' => mixed]
 *   ['role' => 'tool', 'results' => [[id, name, content, error]]]
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Includes\AI_Config;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Client_Exception extends \RuntimeException {
}

class AI_Client {
	private const TIMEOUT = 240;

	/**
	 * @param array<int,array> $messages Neutral messages.
	 * @param array<int,array> $tools    [name, description, parameters(JSON schema)]
	 * @return array{text:string,calls:array,native:mixed,stop:string,usage:array}
	 */
	public function chat(string $provider, string $model, string $system, array $messages, array $tools = array(), array $opts = array()): array {
		$key = AI_Config::get_key($provider);
		if ('' === $key) {
			throw new AI_Client_Exception(sprintf('No API key is configured for %s. Add it in the AI settings.', AI_Config::providers()[$provider]['label'] ?? $provider));
		}
		$max_tokens = (int) ($opts['max_tokens'] ?? AI_Config::max_tokens());
		$temperature = isset($opts['temperature']) ? (float) $opts['temperature'] : null;

		switch ($provider) {
			case 'anthropic':
				return $this->chat_anthropic($key, $model, $system, $messages, $tools, $max_tokens, $temperature);
			case 'gemini':
				return $this->chat_gemini($key, $model, $system, $messages, $tools, $max_tokens, $temperature);
			case 'openai':
			case 'openrouter':
				return $this->chat_openai($provider, $key, $model, $system, $messages, $tools, $max_tokens, $temperature);
		}
		throw new AI_Client_Exception('Unknown AI provider: ' . $provider);
	}

	/**
	 * Simple text completion (no tools).
	 */
	public function complete_text(string $provider, string $model, string $system, string $prompt, array $opts = array()): string {
		$res = $this->chat($provider, $model, $system, array(array('role' => 'user', 'text' => $prompt)), array(), $opts);
		return $res['text'];
	}

	/* ---------------------------------------------------------------------
	 * Anthropic
	 * ------------------------------------------------------------------- */

	private function chat_anthropic(string $key, string $model, string $system, array $messages, array $tools, int $max_tokens, ?float $temperature): array {
		$out = array();
		foreach ($messages as $m) {
			if ('user' === $m['role']) {
				$out[] = array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => self::nonempty($m['text']))));
			} elseif ('assistant' === $m['role']) {
				if ('anthropic' === ($m['provider'] ?? '') && is_array($m['native'] ?? null) && ! empty($m['native'])) {
					$content = array();
					foreach ($m['native'] as $block) {
						if ('tool_use' === ($block['type'] ?? '')) {
							$block['input'] = self::as_object($block['input'] ?? array());
						}
						$content[] = $block;
					}
				} else {
					$content = array();
					if ('' !== trim((string) ($m['text'] ?? ''))) {
						$content[] = array('type' => 'text', 'text' => (string) $m['text']);
					}
					foreach ((array) ($m['calls'] ?? array()) as $c) {
						$content[] = array('type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => self::as_object($c['args'] ?? array()));
					}
					if (empty($content)) {
						$content[] = array('type' => 'text', 'text' => '(no response)');
					}
				}
				$out[] = array('role' => 'assistant', 'content' => $content);
			} elseif ('tool' === $m['role']) {
				$content = array();
				foreach ((array) $m['results'] as $r) {
					$content[] = array(
						'type'        => 'tool_result',
						'tool_use_id' => $r['id'],
						'content'     => self::nonempty((string) $r['content']),
						'is_error'    => ! empty($r['error']),
					);
				}
				$out[] = array('role' => 'user', 'content' => $content);
			}
		}
		$out = self::merge_roles($out, 'content');

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system,
			'messages'   => $out,
		);
		if (null !== $temperature) {
			$body['temperature'] = $temperature;
		}
		if (! empty($tools)) {
			$body['tools'] = array_map(static function ($t) {
				return array(
					'name'         => $t['name'],
					'description'  => $t['description'],
					'input_schema' => ! empty($t['parameters']) ? $t['parameters'] : array('type' => 'object', 'properties' => new \stdClass()),
				);
			}, $tools);
		}

		$data = $this->post('https://api.anthropic.com/v1/messages', array(
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		), $body, 'Anthropic');

		$text = '';
		$calls = array();
		foreach ((array) ($data['content'] ?? array()) as $block) {
			if ('text' === ($block['type'] ?? '')) {
				$text .= (string) $block['text'];
			} elseif ('tool_use' === ($block['type'] ?? '')) {
				$calls[] = array('id' => (string) $block['id'], 'name' => (string) $block['name'], 'args' => (array) ($block['input'] ?? array()));
			}
		}
		return array(
			'text'   => $text,
			'calls'  => $calls,
			'native' => $data['content'] ?? array(),
			'stop'   => 'max_tokens' === ($data['stop_reason'] ?? '') ? 'max_tokens' : (string) ($data['stop_reason'] ?? ''),
			'usage'  => array(
				'in'  => (int) ($data['usage']['input_tokens'] ?? 0),
				'out' => (int) ($data['usage']['output_tokens'] ?? 0),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Gemini
	 * ------------------------------------------------------------------- */

	private function chat_gemini(string $key, string $model, string $system, array $messages, array $tools, int $max_tokens, ?float $temperature): array {
		$contents = array();
		$native_call_ids = array();
		foreach ($messages as $m) {
			if ('user' === $m['role']) {
				$contents[] = array('role' => 'user', 'parts' => array(array('text' => self::nonempty($m['text']))));
			} elseif ('assistant' === $m['role']) {
				if ('gemini' === ($m['provider'] ?? '') && is_array($m['native'] ?? null) && ! empty($m['native'])) {
					$parts = array();
					foreach ($m['native'] as $part) {
						if (isset($part['functionCall'])) {
							$part['functionCall']['args'] = self::as_object($part['functionCall']['args'] ?? array());
						}
						$parts[] = $part;
					}
					foreach ((array) ($m['calls'] ?? array()) as $c) {
						$native_call_ids[$c['id']] = true;
					}
				} else {
					// Calls made by another provider are replayed as text: Gemini requires
					// its own signatures on function-call parts.
					$text = (string) ($m['text'] ?? '');
					foreach ((array) ($m['calls'] ?? array()) as $c) {
						$text .= "\n[Tool call] " . $c['name'] . ' ' . wp_json_encode($c['args'] ?? array());
					}
					$parts = array(array('text' => self::nonempty(trim($text))));
				}
				$contents[] = array('role' => 'model', 'parts' => $parts);
			} elseif ('tool' === $m['role']) {
				$parts = array();
				foreach ((array) $m['results'] as $r) {
					if (isset($native_call_ids[$r['id']])) {
						$parts[] = array('functionResponse' => array(
							'name'     => $r['name'],
							'response' => array(! empty($r['error']) ? 'error' : 'result' => (string) $r['content']),
						));
					} else {
						$parts[] = array('text' => '[Tool result: ' . $r['name'] . ']' . "\n" . (string) $r['content']);
					}
				}
				$contents[] = array('role' => 'user', 'parts' => $parts);
			}
		}
		$contents = self::merge_roles($contents, 'parts');

		$config = array('maxOutputTokens' => $max_tokens);
		if (null !== $temperature) {
			$config['temperature'] = $temperature;
		}
		$body = array(
			'systemInstruction' => array('parts' => array(array('text' => $system))),
			'contents'          => $contents,
			'generationConfig'  => $config,
		);
		if (! empty($tools)) {
			$decls = array();
			foreach ($tools as $t) {
				$decl = array('name' => $t['name'], 'description' => $t['description']);
				if (! empty($t['parameters']['properties'])) {
					$decl['parameters'] = self::gemini_schema($t['parameters']);
				}
				$decls[] = $decl;
			}
			$body['tools'] = array(array('functionDeclarations' => $decls));
		}

		$model = preg_replace('#^models/#', '', $model);
		$data = $this->post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) $model) . ':generateContent',
			array('x-goog-api-key' => $key, 'content-type' => 'application/json'),
			$body,
			'Gemini'
		);

		$candidate = $data['candidates'][0] ?? null;
		if (! is_array($candidate)) {
			$reason = (string) ($data['promptFeedback']['blockReason'] ?? 'no candidates returned');
			throw new AI_Client_Exception('Gemini returned no answer (' . $reason . ').');
		}
		$parts = (array) ($candidate['content']['parts'] ?? array());
		$text = '';
		$calls = array();
		foreach ($parts as $part) {
			if (isset($part['functionCall'])) {
				$fc = $part['functionCall'];
				$calls[] = array(
					'id'   => ! empty($fc['id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $fc['id']) : 'call_' . wp_generate_password(16, false, false),
					'name' => (string) ($fc['name'] ?? ''),
					'args' => (array) ($fc['args'] ?? array()),
				);
			} elseif (isset($part['text']) && empty($part['thought'])) {
				$text .= (string) $part['text'];
			}
		}
		$finish = (string) ($candidate['finishReason'] ?? '');
		if ('' === $text && empty($calls) && ! in_array($finish, array('STOP', ''), true)) {
			if ('MAX_TOKENS' !== $finish) {
				throw new AI_Client_Exception('Gemini stopped without an answer (' . $finish . '). Try rephrasing or another model.');
			}
		}
		return array(
			'text'   => $text,
			'calls'  => $calls,
			'native' => $parts,
			'stop'   => 'MAX_TOKENS' === $finish ? 'max_tokens' : strtolower($finish),
			'usage'  => array(
				'in'  => (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
				'out' => (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
			),
		);
	}

	private static function gemini_schema(array $schema): array {
		$allowed = array('type', 'description', 'enum', 'items', 'properties', 'required', 'nullable', 'format');
		$out = array();
		foreach ($schema as $k => $v) {
			if (! in_array($k, $allowed, true)) {
				continue;
			}
			if ('type' === $k) {
				$out['type'] = strtoupper((string) $v);
			} elseif ('properties' === $k) {
				$props = array();
				foreach ((array) $v as $name => $sub) {
					$props[$name] = self::gemini_schema((array) $sub);
				}
				$out['properties'] = $props;
			} elseif ('items' === $k) {
				$out['items'] = self::gemini_schema((array) $v);
			} else {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * OpenAI-compatible (OpenAI, OpenRouter)
	 * ------------------------------------------------------------------- */

	private function chat_openai(string $provider, string $key, string $model, string $system, array $messages, array $tools, int $max_tokens, ?float $temperature): array {
		$out = array(array('role' => 'system', 'content' => $system));
		foreach ($messages as $m) {
			if ('user' === $m['role']) {
				$out[] = array('role' => 'user', 'content' => self::nonempty($m['text']));
			} elseif ('assistant' === $m['role']) {
				$msg = array('role' => 'assistant', 'content' => (string) ($m['text'] ?? ''));
				if (! empty($m['calls'])) {
					$msg['tool_calls'] = array_map(static function ($c) {
						return array(
							'id'       => $c['id'],
							'type'     => 'function',
							'function' => array('name' => $c['name'], 'arguments' => (string) wp_json_encode(self::as_object($c['args'] ?? array()))),
						);
					}, $m['calls']);
				}
				$out[] = $msg;
			} elseif ('tool' === $m['role']) {
				foreach ((array) $m['results'] as $r) {
					$out[] = array('role' => 'tool', 'tool_call_id' => $r['id'], 'content' => self::nonempty((string) $r['content']));
				}
			}
		}

		$body = array('model' => $model, 'messages' => $out);
		$body['openai' === $provider ? 'max_completion_tokens' : 'max_tokens'] = $max_tokens;
		if (null !== $temperature) {
			$body['temperature'] = $temperature;
		}
		if (! empty($tools)) {
			$body['tools'] = array_map(static function ($t) {
				return array('type' => 'function', 'function' => array(
					'name'        => $t['name'],
					'description' => $t['description'],
					'parameters'  => ! empty($t['parameters']) ? $t['parameters'] : array('type' => 'object', 'properties' => new \stdClass()),
				));
			}, $tools);
		}

		$url = 'openrouter' === $provider ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
		$headers = array('Authorization' => 'Bearer ' . $key, 'content-type' => 'application/json');
		if ('openrouter' === $provider) {
			$headers['HTTP-Referer'] = home_url('/');
			$headers['X-Title'] = 'WP Diagnostics';
		}
		$data = $this->post($url, $headers, $body, 'openrouter' === $provider ? 'OpenRouter' : 'OpenAI');

		$msg = $data['choices'][0]['message'] ?? array();
		$calls = array();
		foreach ((array) ($msg['tool_calls'] ?? array()) as $tc) {
			$args = json_decode((string) ($tc['function']['arguments'] ?? '{}'), true);
			$calls[] = array('id' => (string) $tc['id'], 'name' => (string) ($tc['function']['name'] ?? ''), 'args' => is_array($args) ? $args : array());
		}
		$finish = (string) ($data['choices'][0]['finish_reason'] ?? '');
		return array(
			'text'   => (string) ($msg['content'] ?? ''),
			'calls'  => $calls,
			'native' => null,
			'stop'   => 'length' === $finish ? 'max_tokens' : $finish,
			'usage'  => array(
				'in'  => (int) ($data['usage']['prompt_tokens'] ?? 0),
				'out' => (int) ($data['usage']['completion_tokens'] ?? 0),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Models
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<int,string>
	 */
	public function list_models(string $provider, bool $refresh = false): array {
		$defaults = AI_Config::providers()[$provider]['models'] ?? array();
		$key = AI_Config::get_key($provider);
		if ('' === $key) {
			return $defaults;
		}
		$cache_key = 'wudt_ai_models_' . $provider . '_' . substr(md5($key), 0, 8);
		if (! $refresh) {
			$cached = get_transient($cache_key);
			if (is_array($cached) && ! empty($cached)) {
				return $cached;
			}
		}
		$models = array();
		try {
			switch ($provider) {
				case 'anthropic':
					$data = $this->get('https://api.anthropic.com/v1/models?limit=100', array('x-api-key' => $key, 'anthropic-version' => '2023-06-01'), 'Anthropic');
					foreach ((array) ($data['data'] ?? array()) as $m) {
						$models[] = (string) $m['id'];
					}
					break;
				case 'gemini':
					$data = $this->get('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', array('x-goog-api-key' => $key), 'Gemini');
					foreach ((array) ($data['models'] ?? array()) as $m) {
						$name = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
						$methods = (array) ($m['supportedGenerationMethods'] ?? array());
						if (0 === strpos((string) $name, 'gemini') && in_array('generateContent', $methods, true) && ! preg_match('/(tts|image|embedding|audio|live)/', (string) $name)) {
							$models[] = (string) $name;
						}
					}
					rsort($models);
					break;
				case 'openai':
					$data = $this->get('https://api.openai.com/v1/models', array('Authorization' => 'Bearer ' . $key), 'OpenAI');
					foreach ((array) ($data['data'] ?? array()) as $m) {
						$id = (string) $m['id'];
						if (preg_match('/^(gpt-|o\d)/', $id) && ! preg_match('/(audio|realtime|image|tts|transcribe|search)/', $id)) {
							$models[] = $id;
						}
					}
					rsort($models);
					break;
				case 'openrouter':
					$data = $this->get('https://openrouter.ai/api/v1/models', array('Authorization' => 'Bearer ' . $key), 'OpenRouter');
					foreach ((array) ($data['data'] ?? array()) as $m) {
						if (in_array('tools', (array) ($m['supported_parameters'] ?? array('tools')), true)) {
							$models[] = (string) $m['id'];
						}
					}
					sort($models);
					break;
			}
		} catch (\Throwable $e) {
			set_transient($cache_key, $defaults, HOUR_IN_SECONDS);
			return $defaults;
		}
		$models = array_values(array_unique(array_filter($models)));
		if (empty($models)) {
			return $defaults;
		}
		set_transient($cache_key, $models, 12 * HOUR_IN_SECONDS);
		return $models;
	}

	/* ---------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------- */

	private function post(string $url, array $headers, array $body, string $label): array {
		$json = wp_json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
		if (! is_string($json)) {
			throw new AI_Client_Exception('Could not encode the request to ' . $label . '.');
		}
		return $this->request('POST', $url, $headers, $json, $label);
	}

	private function get(string $url, array $headers, string $label): array {
		return $this->request('GET', $url, $headers, null, $label);
	}

	private function request(string $method, string $url, array $headers, ?string $body, string $label): array {
		if (function_exists('set_time_limit')) {
			@set_time_limit(self::TIMEOUT + 60);
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::TIMEOUT,
		);
		if (null !== $body) {
			$args['body'] = $body;
		}

		$attempt = 0;
		while (true) {
			$attempt++;
			$response = wp_remote_request($url, $args);
			if (is_wp_error($response)) {
				$msg = $response->get_error_message();
				if (false !== stripos($msg, 'certificate') && ! isset($args['sslverify'])) {
					// Local stacks (XAMPP etc.) often ship without a CA bundle.
					$args['sslverify'] = false;
					continue;
				}
				if ($attempt < 3 && false !== stripos($msg, 'timed out') && 'GET' === $method) {
					continue;
				}
				throw new AI_Client_Exception($label . ' request failed: ' . $msg);
			}
			$code = (int) wp_remote_retrieve_response_code($response);
			$raw = (string) wp_remote_retrieve_body($response);
			$data = json_decode($raw, true);

			if ($code >= 200 && $code < 300 && is_array($data)) {
				return $data;
			}
			if (in_array($code, array(429, 500, 502, 503, 504, 529), true) && $attempt < 3) {
				$wait = (int) wp_remote_retrieve_header($response, 'retry-after');
				sleep(max(2, min(20, $wait > 0 ? $wait : 3 * $attempt)));
				continue;
			}
			$error = '';
			if (is_array($data)) {
				$error = (string) ($data['error']['message'] ?? ($data['error']['type'] ?? ($data['message'] ?? '')));
				if (is_array($data[0] ?? null)) {
					$error = (string) ($data[0]['error']['message'] ?? '');
				}
			}
			if ('' === $error) {
				$error = trim(mb_substr(wp_strip_all_tags($raw), 0, 300));
			}
			$hint = '';
			if (401 === $code || 403 === $code) {
				$hint = ' Check that the API key is correct and active.';
			} elseif (404 === $code) {
				$hint = ' The selected model may not exist for this key — pick another model.';
			} elseif (429 === $code) {
				$hint = ' Rate limit or quota reached — wait a moment or check your plan/billing.';
			}
			throw new AI_Client_Exception(sprintf('%s API error (HTTP %d): %s%s', $label, $code, $error, $hint));
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function merge_roles(array $messages, string $field): array {
		$merged = array();
		foreach ($messages as $m) {
			$last = count($merged) - 1;
			if ($last >= 0 && $merged[$last]['role'] === $m['role']) {
				$merged[$last][$field] = array_merge($merged[$last][$field], $m[$field]);
			} else {
				$merged[] = $m;
			}
		}
		return $merged;
	}

	private static function as_object($args) {
		return empty($args) ? new \stdClass() : $args;
	}

	private static function nonempty($text): string {
		$text = (string) $text;
		return '' === trim($text) ? '(empty)' : $text;
	}
}
