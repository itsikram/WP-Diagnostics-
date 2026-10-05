<?php
/**
 * AI Provider Interface
 * Defines the contract for all AI provider implementations
 */

declare(strict_types=1);

namespace WUDT\Modules\AIGateway;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Interface AI_Provider_Interface
 * 
 * All AI providers must implement this interface to ensure
 * consistent behavior across different AI services (OpenAI, Gemini, Claude, etc.)
 */
interface AI_Provider_Interface {
	/**
	 * Get the provider unique identifier
	 *
	 * @return string Provider slug (e.g., 'openai', 'gemini', 'claude')
	 */
	public function get_id(): string;

	/**
	 * Get the provider display name
	 *
	 * @return string Human-readable name (e.g., 'OpenAI GPT', 'Google Gemini')
	 */
	public function get_name(): string;

	/**
	 * Get available models for this provider
	 *
	 * @return array<int,array<string,mixed>> Array of model data with 'id', 'name', 'cost_per_1k_input', 'cost_per_1k_output', 'max_tokens', 'supports_streaming'
	 */
	public function get_available_models(): array;

	/**
	 * Check if the provider is properly configured (has valid API key)
	 *
	 * @return bool True if API key is set and valid
	 */
	public function is_configured(): bool;

	/**
	 * Send a request to the AI provider
	 *
	 * @param AI_Request $request The normalized request object
	 * @return AI_Response The normalized response object
	 */
	public function send(AI_Request $request): AI_Response;

	/**
	 * Send a streaming request to the AI provider
	 *
	 * @param AI_Request $request The normalized request object
	 * @param callable $callback Callback function to handle stream chunks: function(string $chunk, bool $is_done, array $metadata): void
	 * @return AI_Response The final response after streaming completes
	 */
	public function send_streaming(AI_Request $request, callable $callback): AI_Response;

	/**
	 * Validate the provider connection by making a test request
	 *
	 * @return array<string,mixed> Validation result with 'success', 'message', and 'details'
	 */
	public function validate_connection(): array;

	/**
	 * Estimate cost for a request before sending
	 *
	 * @param AI_Request $request The request to estimate
	 * @return float Estimated cost in USD
	 */
	public function estimate_cost(AI_Request $request): float;

	/**
	 * Get the provider's default model
	 *
	 * @return string Default model identifier
	 */
	public function get_default_model(): string;

	/**
	 * Check if a specific model supports streaming
	 *
	 * @param string $model Model identifier
	 * @return bool True if streaming is supported
	 */
	public function supports_streaming(string $model): bool;

	/**
	 * Format messages for the provider's specific API format
	 *
	 * @param array<int,array<string,string>> $messages Array of message arrays with 'role' and 'content'
	 * @return array<string,mixed> Provider-specific formatted payload
	 */
	public function format_messages(array $messages): array;

	/**
	 * Parse the provider's raw response into normalized format
	 *
	 * @param array<string,mixed>|string $raw_response Raw response from API
	 * @param string $model Model used for the request
	 * @return AI_Response Normalized response object
	 */
	public function parse_response($raw_response, string $model): AI_Response;
}
