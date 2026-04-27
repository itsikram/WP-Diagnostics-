<?php
/**
 * AI Response Normalization Class
 * Standardizes response format across all AI providers
 */

declare(strict_types=1);

namespace WUDT\Modules\AIGateway;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class AI_Response
 * 
 * Represents a normalized AI response that is consistent
 * regardless of which provider was used
 */
class AI_Response {
	/** @var string Response text content */
	private string $text = '';

	/** @var string Model used for the response */
	private string $model = '';

	/** @var string Provider that generated the response */
	private string $provider = '';

	/** @var int|null Tokens used for input (prompt) */
	private ?int $tokens_input = null;

	/** @var int|null Tokens used for output (completion) */
	private ?int $tokens_output = null;

	/** @var int|null Total tokens used */
	private ?int $tokens_total = null;

	/** @var float|null Cost in USD */
	private ?float $cost = null;

	/** @var string Response ID from provider */
	private string $response_id = '';

	/** @var bool Whether the response was successful */
	private bool $success = true;

	/** @var string|null Error message if failed */
	private ?string $error_message = null;

	/** @var int|null Error code if failed */
	private ?int $error_code = null;

	/** @var float Response time in seconds */
	private float $response_time = 0.0;

	/** @var array<string,mixed> Raw response data from provider */
	private array $raw_data = array();

	/** @var array<string,mixed> Additional metadata */
	private array $metadata = array();

	/** @var bool Whether response was served from cache */
	private bool $from_cache = false;

	/**
	 * Constructor
	 */
	public function __construct() {}

	/**
	 * Static factory method for fluent API
	 */
	public static function create(): self {
		return new self();
	}

	/**
	 * Create a success response
	 */
	public static function success(string $text, string $model, string $provider): self {
		$response = new self();
		$response->set_text($text)
			->set_model($model)
			->set_provider($provider)
			->set_success(true);
		return $response;
	}

	/**
	 * Create an error response
	 */
	public static function error(string $error_message, int $error_code = 0, string $provider = ''): self {
		$response = new self();
		$response->set_error_message($error_message)
			->set_error_code($error_code)
			->set_provider($provider)
			->set_success(false);
		return $response;
	}

	/**
	 * Set response text
	 *
	 * @param string $text
	 * @return $this
	 */
	public function set_text(string $text): self {
		$this->text = $text;
		return $this;
	}

	/**
	 * Set model
	 *
	 * @param string $model
	 * @return $this
	 */
	public function set_model(string $model): self {
		$this->model = sanitize_text_field($model);
		return $this;
	}

	/**
	 * Set provider
	 *
	 * @param string $provider
	 * @return $this
	 */
	public function set_provider(string $provider): self {
		$this->provider = sanitize_key($provider);
		return $this;
	}

	/**
	 * Set token counts
	 *
	 * @param int|null $input
	 * @param int|null $output
	 * @param int|null $total
	 * @return $this
	 */
	public function set_tokens(?int $input = null, ?int $output = null, ?int $total = null): self {
		$this->tokens_input = $input;
		$this->tokens_output = $output;
		$this->tokens_total = $total ?? ($input + $output);
		return $this;
	}

	/**
	 * Set cost
	 *
	 * @param float|null $cost
	 * @return $this
	 */
	public function set_cost(?float $cost): self {
		$this->cost = $cost;
		return $this;
	}

	/**
	 * Set response ID
	 *
	 * @param string $response_id
	 * @return $this
	 */
	public function set_response_id(string $response_id): self {
		$this->response_id = sanitize_text_field($response_id);
		return $this;
	}

	/**
	 * Set success status
	 *
	 * @param bool $success
	 * @return $this
	 */
	public function set_success(bool $success): self {
		$this->success = $success;
		return $this;
	}

	/**
	 * Set error message
	 *
	 * @param string|null $error_message
	 * @return $this
	 */
	public function set_error_message(?string $error_message): self {
		$this->error_message = $error_message;
		return $this;
	}

	/**
	 * Set error code
	 *
	 * @param int|null $error_code
	 * @return $this
	 */
	public function set_error_code(?int $error_code): self {
		$this->error_code = $error_code;
		return $this;
	}

	/**
	 * Set response time
	 *
	 * @param float $response_time
	 * @return $this
	 */
	public function set_response_time(float $response_time): self {
		$this->response_time = $response_time;
		return $this;
	}

	/**
	 * Set raw data
	 *
	 * @param array<string,mixed> $raw_data
	 * @return $this
	 */
	public function set_raw_data(array $raw_data): self {
		$this->raw_data = $raw_data;
		return $this;
	}

	/**
	 * Set metadata
	 *
	 * @param array<string,mixed> $metadata
	 * @return $this
	 */
	public function set_metadata(array $metadata): self {
		$this->metadata = $metadata;
		return $this;
	}

	/**
	 * Set from cache flag
	 *
	 * @param bool $from_cache
	 * @return $this
	 */
	public function set_from_cache(bool $from_cache): self {
		$this->from_cache = $from_cache;
		return $this;
	}

	/**
	 * Get response text
	 *
	 * @return string
	 */
	public function get_text(): string {
		return $this->text;
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
	 * Get input tokens
	 *
	 * @return int|null
	 */
	public function get_tokens_input(): ?int {
		return $this->tokens_input;
	}

	/**
	 * Get output tokens
	 *
	 * @return int|null
	 */
	public function get_tokens_output(): ?int {
		return $this->tokens_output;
	}

	/**
	 * Get total tokens
	 *
	 * @return int|null
	 */
	public function get_tokens_total(): ?int {
		return $this->tokens_total;
	}

	/**
	 * Get cost
	 *
	 * @return float|null
	 */
	public function get_cost(): ?float {
		return $this->cost;
	}

	/**
	 * Get response ID
	 *
	 * @return string
	 */
	public function get_response_id(): string {
		return $this->response_id;
	}

	/**
	 * Is success
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Get error message
	 *
	 * @return string|null
	 */
	public function get_error_message(): ?string {
		return $this->error_message;
	}

	/**
	 * Get error code
	 *
	 * @return int|null
	 */
	public function get_error_code(): ?int {
		return $this->error_code;
	}

	/**
	 * Get response time
	 *
	 * @return float
	 */
	public function get_response_time(): float {
		return $this->response_time;
	}

	/**
	 * Get raw data
	 *
	 * @return array<string,mixed>
	 */
	public function get_raw_data(): array {
		return $this->raw_data;
	}

	/**
	 * Get metadata
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Is from cache
	 *
	 * @return bool
	 */
	public function is_from_cache(): bool {
		return $this->from_cache;
	}

	/**
	 * Convert to array
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'text'          => $this->text,
			'model'         => $this->model,
			'provider'      => $this->provider,
			'tokens_input'  => $this->tokens_input,
			'tokens_output' => $this->tokens_output,
			'tokens_total'  => $this->tokens_total,
			'cost'          => $this->cost,
			'response_id'   => $this->response_id,
			'success'       => $this->success,
			'error_message' => $this->error_message,
			'error_code'    => $this->error_code,
			'response_time' => $this->response_time,
			'metadata'      => $this->metadata,
			'from_cache'    => $this->from_cache,
		);
	}

	/**
	 * Convert to JSON string
	 *
	 * @return string
	 */
	public function to_json(): string {
		return wp_json_encode($this->to_array());
	}

	/**
	 * Create from array
	 *
	 * @param array<string,mixed> $data
	 * @return self
	 */
	public static function from_array(array $data): self {
		$response = new self();
		
		if (! empty($data['text'])) {
			$response->set_text($data['text']);
		}
		if (! empty($data['model'])) {
			$response->set_model($data['model']);
		}
		if (! empty($data['provider'])) {
			$response->set_provider($data['provider']);
		}
		if (isset($data['tokens_input']) || isset($data['tokens_output'])) {
			$response->set_tokens(
				$data['tokens_input'] ?? null,
				$data['tokens_output'] ?? null,
				$data['tokens_total'] ?? null
			);
		}
		if (isset($data['cost'])) {
			$response->set_cost((float) $data['cost']);
		}
		if (! empty($data['response_id'])) {
			$response->set_response_id($data['response_id']);
		}
		if (isset($data['success'])) {
			$response->set_success((bool) $data['success']);
		}
		if (! empty($data['error_message'])) {
			$response->set_error_message($data['error_message']);
		}
		if (isset($data['error_code'])) {
			$response->set_error_code((int) $data['error_code']);
		}
		if (isset($data['response_time'])) {
			$response->set_response_time((float) $data['response_time']);
		}
		if (! empty($data['metadata'])) {
			$response->set_metadata($data['metadata']);
		}
		if (isset($data['from_cache'])) {
			$response->set_from_cache((bool) $data['from_cache']);
		}
		if (! empty($data['raw_data'])) {
			$response->set_raw_data($data['raw_data']);
		}
		
		return $response;
	}

	/**
	 * Get a summary of the response for logging
	 *
	 * @return array<string,mixed>
	 */
	public function get_summary(): array {
		return array(
			'success'       => $this->success,
			'provider'      => $this->provider,
			'model'         => $this->model,
			'tokens_total'  => $this->tokens_total,
			'cost'          => $this->cost,
			'response_time' => $this->response_time,
			'from_cache'    => $this->from_cache,
			'text_length'   => strlen($this->text),
		);
	}
}
