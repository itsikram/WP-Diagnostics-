<?php
/**
 * AI Request Normalization Class
 * Standardizes request format across all AI providers
 */

declare(strict_types=1);

namespace WUDT\Modules\AIGateway;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class AI_Request
 * 
 * Represents a normalized AI request that can be converted
 * to any provider-specific format
 */
class AI_Request {
	/** @var array<int,array<string,string>> Messages array with 'role' and 'content' */
	private array $messages = array();

	/** @var array<string,mixed> Additional context data */
	private array $context = array();

	/** @var string Target model identifier */
	private string $model = '';

	/** @var string Target provider identifier */
	private string $provider = '';

	/** @var float Temperature parameter (0-2) */
	private float $temperature = 0.2;

	/** @var int Maximum tokens to generate */
	private int $max_tokens = 4096;

	/** @var bool Whether to use streaming */
	private bool $streaming = false;

	/** @var string System prompt/instruction */
	private string $system_prompt = '';

	/** @var array<string,mixed> Additional parameters */
	private array $parameters = array();

	/** @var string Unique request ID for tracking */
	private string $request_id;

	/** @var string Request purpose for routing decisions */
	private string $purpose = 'general';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->request_id = wp_unique_id('ai_req_');
	}

	/**
	 * Static factory method for fluent API
	 */
	public static function create(): self {
		return new self();
	}

	/**
	 * Set messages
	 *
	 * @param array<int,array<string,string>> $messages
	 * @return $this
	 */
	public function set_messages(array $messages): self {
		$this->messages = $this->sanitize_messages($messages);
		return $this;
	}

	/**
	 * Add a single message
	 *
	 * @param string $role Message role (system, user, assistant)
	 * @param string $content Message content
	 * @return $this
	 */
	public function add_message(string $role, string $content): self {
		$allowed_roles = array('system', 'user', 'assistant', 'function', 'tool');
		if (! in_array($role, $allowed_roles, true)) {
			$role = 'user';
		}
		$this->messages[] = array(
			'role'    => sanitize_key($role),
			'content' => sanitize_textarea_field($content),
		);
		return $this;
	}

	/**
	 * Set context data
	 *
	 * @param array<string,mixed> $context
	 * @return $this
	 */
	public function set_context(array $context): self {
		$this->context = $this->sanitize_context($context);
		return $this;
	}

	/**
	 * Set target model
	 *
	 * @param string $model
	 * @return $this
	 */
	public function set_model(string $model): self {
		$this->model = sanitize_text_field($model);
		return $this;
	}

	/**
	 * Set target provider
	 *
	 * @param string $provider
	 * @return $this
	 */
	public function set_provider(string $provider): self {
		$this->provider = sanitize_key($provider);
		return $this;
	}

	/**
	 * Set temperature
	 *
	 * @param float $temperature
	 * @return $this
	 */
	public function set_temperature(float $temperature): self {
		$this->temperature = max(0.0, min(2.0, $temperature));
		return $this;
	}

	/**
	 * Set max tokens
	 *
	 * @param int $max_tokens
	 * @return $this
	 */
	public function set_max_tokens(int $max_tokens): self {
		$this->max_tokens = max(1, $max_tokens);
		return $this;
	}

	/**
	 * Set streaming flag
	 *
	 * @param bool $streaming
	 * @return $this
	 */
	public function set_streaming(bool $streaming): self {
		$this->streaming = $streaming;
		return $this;
	}

	/**
	 * Set system prompt
	 *
	 * @param string $system_prompt
	 * @return $this
	 */
	public function set_system_prompt(string $system_prompt): self {
		$this->system_prompt = sanitize_textarea_field($system_prompt);
		return $this;
	}

	/**
	 * Set additional parameters
	 *
	 * @param array<string,mixed> $parameters
	 * @return $this
	 */
	public function set_parameters(array $parameters): self {
		$this->parameters = $this->sanitize_parameters($parameters);
		return $this;
	}

	/**
	 * Set request purpose for smart routing
	 *
	 * @param string $purpose (general, debugging, analysis, code, cheap)
	 * @return $this
	 */
	public function set_purpose(string $purpose): self {
		$allowed = array('general', 'debugging', 'analysis', 'code', 'cheap', 'creative');
		$this->purpose = in_array($purpose, $allowed, true) ? $purpose : 'general';
		return $this;
	}

	/**
	 * Get messages
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_messages(): array {
		return $this->messages;
	}

	/**
	 * Get context
	 *
	 * @return array<string,mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Get model
	 *
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Get provider
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Get temperature
	 *
	 * @return float
	 */
	public function get_temperature(): float {
		return $this->temperature;
	}

	/**
	 * Get max tokens
	 *
	 * @return int
	 */
	public function get_max_tokens(): int {
		return $this->max_tokens;
	}

	/**
	 * Is streaming enabled
	 *
	 * @return bool
	 */
	public function is_streaming(): bool {
		return $this->streaming;
	}

	/**
	 * Get system prompt
	 *
	 * @return string
	 */
	public function get_system_prompt(): string {
		return $this->system_prompt;
	}

	/**
	 * Get parameters
	 *
	 * @return array<string,mixed>
	 */
	public function get_parameters(): array {
		return $this->parameters;
	}

	/**
	 * Get request ID
	 *
	 * @return string
	 */
	public function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Get purpose
	 *
	 * @return string
	 */
	public function get_purpose(): string {
		return $this->purpose;
	}

	/**
	 * Calculate approximate token count
	 * Rough estimation: ~4 characters per token for English
	 *
	 * @return int
	 */
	public function estimate_tokens(): int {
		$text = '';
		foreach ($this->messages as $message) {
			$text .= $message['content'] ?? '';
		}
		$text .= $this->system_prompt;
		$text .= wp_json_encode($this->context);
		return (int) ceil(strlen($text) / 4);
	}

	/**
	 * Convert to array representation
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'request_id'    => $this->request_id,
			'messages'      => $this->messages,
			'context'       => $this->context,
			'model'         => $this->model,
			'provider'      => $this->provider,
			'temperature'   => $this->temperature,
			'max_tokens'    => $this->max_tokens,
			'streaming'     => $this->streaming,
			'system_prompt' => $this->system_prompt,
			'parameters'    => $this->parameters,
			'purpose'       => $this->purpose,
			'estimated_tokens' => $this->estimate_tokens(),
		);
	}

	/**
	 * Create from array
	 *
	 * @param array<string,mixed> $data
	 * @return self
	 */
	public static function from_array(array $data): self {
		$request = new self();
		
		if (! empty($data['messages'])) {
			$request->set_messages($data['messages']);
		}
		if (! empty($data['context'])) {
			$request->set_context($data['context']);
		}
		if (! empty($data['model'])) {
			$request->set_model($data['model']);
		}
		if (! empty($data['provider'])) {
			$request->set_provider($data['provider']);
		}
		if (isset($data['temperature'])) {
			$request->set_temperature((float) $data['temperature']);
		}
		if (! empty($data['max_tokens'])) {
			$request->set_max_tokens((int) $data['max_tokens']);
		}
		if (! empty($data['streaming'])) {
			$request->set_streaming((bool) $data['streaming']);
		}
		if (! empty($data['system_prompt'])) {
			$request->set_system_prompt($data['system_prompt']);
		}
		if (! empty($data['parameters'])) {
			$request->set_parameters($data['parameters']);
		}
		if (! empty($data['purpose'])) {
			$request->set_purpose($data['purpose']);
		}
		if (! empty($data['request_id'])) {
			$request->request_id = sanitize_text_field($data['request_id']);
		}
		
		return $request;
	}

	/**
	 * Sanitize messages array
	 *
	 * @param array<int,array<string,string>> $messages
	 * @return array<int,array<string,string>>
	 */
	private function sanitize_messages(array $messages): array {
		$sanitized = array();
		$allowed_roles = array('system', 'user', 'assistant', 'function', 'tool');
		
		foreach ($messages as $message) {
			if (! is_array($message)) {
				continue;
			}
			$role = isset($message['role']) ? sanitize_key($message['role']) : 'user';
			if (! in_array($role, $allowed_roles, true)) {
				$role = 'user';
			}
			
			$sanitized[] = array(
				'role'    => $role,
				'content' => isset($message['content']) ? sanitize_textarea_field($message['content']) : '',
			);
		}
		
		return $sanitized;
	}

	/**
	 * Sanitize context array - mask sensitive data
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function sanitize_context(array $context): array {
		$sensitive_keys = array(
			'password', 'pass', 'pwd', 'secret', 'key', 'api_key', 'token',
			'auth', 'credential', 'db_password', 'db_host', 'db_user',
			'WP_PASSWORD', 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT', 'NONCE_SALT'
		);
		
		return $this->mask_sensitive_data($context, $sensitive_keys);
	}

	/**
	 * Recursively mask sensitive data
	 *
	 * @param mixed $data
	 * @param array<int,string> $sensitive_keys
	 * @return mixed
	 */
	private function mask_sensitive_data($data, array $sensitive_keys) {
		if (is_array($data)) {
			$result = array();
			foreach ($data as $key => $value) {
				$lower_key = strtolower((string) $key);
				$is_sensitive = false;
				foreach ($sensitive_keys as $sensitive) {
					if (strpos($lower_key, strtolower($sensitive)) !== false) {
						$is_sensitive = true;
						break;
					}
				}
				if ($is_sensitive && is_string($value)) {
					$result[$key] = '***REDACTED***';
				} else {
					$result[$key] = $this->mask_sensitive_data($value, $sensitive_keys);
				}
			}
			return $result;
		}
		return $data;
	}

	/**
	 * Sanitize parameters
	 *
	 * @param array<string,mixed> $parameters
	 * @return array<string,mixed>
	 */
	private function sanitize_parameters(array $parameters): array {
		$sanitized = array();
		$allowed_keys = array(
			'top_p', 'frequency_penalty', 'presence_penalty', 'stop',
			'response_format', 'tools', 'tool_choice', 'seed'
		);
		
		foreach ($parameters as $key => $value) {
			if (in_array($key, $allowed_keys, true)) {
				$sanitized[$key] = $value;
			}
		}
		
		return $sanitized;
	}
}
