<?php
/**
 * AI Gateway Core Class
 * Central manager for multiple AI providers with fallback and routing
 */

declare(strict_types=1);

namespace WUDT\Modules\AIGateway;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class AI_Gateway
 * 
 * Manages multiple AI providers, handles fallback logic,
 * smart routing, and request distribution
 */
class AI_Gateway {
	/** @var array<string,AI_Provider_Interface> Registered providers */
	private array $providers = array();

	/** @var array<string> Default fallback chain */
	private array $fallback_chain = array('openai', 'gemini', 'claude');

	/** @var AI_Cache_Manager|null Cache manager instance */
	private ?AI_Cache_Manager $cache_manager = null;

	/** @var AI_Cost_Tracker|null Cost tracker instance */
	private ?AI_Cost_Tracker $cost_tracker = null;

	/** @var AI_Rate_Limiter|null Rate limiter instance */
	private ?AI_Rate_Limiter $rate_limiter = null;

	/** @var bool Enable fallback on failure */
	private bool $enable_fallback = true;

	/** @var bool Enable smart routing based on purpose */
	private bool $enable_smart_routing = true;

	/** @var bool Enable caching */
	private bool $enable_caching = true;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->initialize_components();
		$this->register_default_providers();
	}

	/**
	 * Initialize gateway components
	 */
	private function initialize_components(): void {
		if ($this->enable_caching) {
			$this->cache_manager = new AI_Cache_Manager();
		}
		$this->cost_tracker = new AI_Cost_Tracker();
		$this->rate_limiter = new AI_Rate_Limiter();
	}

	/**
	 * Register default built-in providers
	 */
	private function register_default_providers(): void {
		$this->register_provider(new OpenAI_Provider());
		$this->register_provider(new Gemini_Provider());
		$this->register_provider(new Claude_Provider());
	}

	/**
	 * Register a provider
	 *
	 * @param AI_Provider_Interface $provider
	 * @return $this
	 */
	public function register_provider(AI_Provider_Interface $provider): self {
		$this->providers[$provider->get_id()] = $provider;
		return $this;
	}

	/**
	 * Get a registered provider
	 *
	 * @param string $provider_id
	 * @return AI_Provider_Interface|null
	 */
	public function get_provider(string $provider_id): ?AI_Provider_Interface {
		return $this->providers[$provider_id] ?? null;
	}

	/**
	 * Get all registered providers
	 *
	 * @return array<string,AI_Provider_Interface>
	 */
	public function get_all_providers(): array {
		return $this->providers;
	}

	/**
	 * Get configured providers (those with valid API keys)
	 *
	 * @return array<string,AI_Provider_Interface>
	 */
	public function get_configured_providers(): array {
		$configured = array();
		foreach ($this->providers as $id => $provider) {
			if ($provider->is_configured()) {
				$configured[$id] = $provider;
			}
		}
		return $configured;
	}

	/**
	 * Check if any provider is configured
	 *
	 * @return bool
	 */
	public function has_configured_provider(): bool {
		return ! empty($this->get_configured_providers());
	}

	/**
	 * Main send method - the unified interface
	 *
	 * @param AI_Request $request
	 * @return AI_Response
	 */
	public function send(AI_Request $request): AI_Response {
		$start_time = microtime(true);
		
		// Check rate limiting
		if ($this->rate_limiter && ! $this->rate_limiter->check_limit()) {
			return AI_Response::error(
				'Rate limit exceeded. Please wait before making more requests.',
				429
			);
		}

		// Check cache first
		if ($this->enable_caching && $this->cache_manager) {
			$cached = $this->cache_manager->get($request);
			if ($cached) {
				$cached->set_response_time(microtime(true) - $start_time);
				return $cached;
			}
		}

		// Determine provider to use
		$provider_chain = $this->get_provider_chain($request);
		
		$last_error = null;
		
		foreach ($provider_chain as $provider_id) {
			$provider = $this->get_provider($provider_id);
			
			if (! $provider || ! $provider->is_configured()) {
				continue;
			}

			// Update request with provider and model
			if (empty($request->get_model())) {
				$request->set_model($provider->get_default_model());
			}
			$request->set_provider($provider_id);

			// Send request
			$response = $this->send_to_provider($provider, $request);
			
			if ($response->is_success()) {
				// Calculate and set cost
				if ($this->cost_tracker) {
					$cost = $this->cost_tracker->calculate_cost(
						$provider_id,
						$request->get_model(),
						$response->get_tokens_input() ?? $request->estimate_tokens(),
						$response->get_tokens_output() ?? (int) (strlen($response->get_text()) / 4)
					);
					$response->set_cost($cost);
					$this->cost_tracker->track_request($request, $response);
				}

				// Cache successful response
				if ($this->enable_caching && $this->cache_manager) {
					$this->cache_manager->set($request, $response);
				}

				$response->set_response_time(microtime(true) - $start_time);
				return $response;
			}

			$last_error = $response;
			
			// Log failure for fallback
			if ($this->enable_fallback) {
				$this->log_fallback($provider_id, $response->get_error_message());
			}
		}

		// All providers failed
		$error_message = $last_error ? $last_error->get_error_message() : 'All AI providers failed. Please check your API key configuration.';
		$error_code = $last_error ? $last_error->get_error_code() : 500;
		
		$response = AI_Response::error($error_message, $error_code);
		$response->set_response_time(microtime(true) - $start_time);
		
		return $response;
	}

	/**
	 * Send with streaming support
	 *
	 * @param AI_Request $request
	 * @param callable $callback
	 * @return AI_Response
	 */
	public function send_streaming(AI_Request $request, callable $callback): AI_Response {
		$start_time = microtime(true);
		
		// Check rate limiting
		if ($this->rate_limiter && ! $this->rate_limiter->check_limit()) {
			return AI_Response::error(
				'Rate limit exceeded. Please wait before making more requests.',
				429
			);
		}

		// Determine provider chain
		$provider_chain = $this->get_provider_chain($request);
		
		foreach ($provider_chain as $provider_id) {
			$provider = $this->get_provider($provider_id);
			
			if (! $provider || ! $provider->is_configured()) {
				continue;
			}

			// Check if provider supports streaming for this model
			$model = $request->get_model() ?: $provider->get_default_model();
			if (! $provider->supports_streaming($model)) {
				continue;
			}

			$request->set_provider($provider_id)->set_model($model);

			$response = $provider->send_streaming($request, $callback);
			
			if ($response->is_success()) {
				// Calculate and set cost
				if ($this->cost_tracker) {
					$cost = $this->cost_tracker->calculate_cost(
						$provider_id,
						$model,
						$response->get_tokens_input() ?? $request->estimate_tokens(),
						$response->get_tokens_output() ?? (int) (strlen($response->get_text()) / 4)
					);
					$response->set_cost($cost);
					$this->cost_tracker->track_request($request, $response);
				}

				$response->set_response_time(microtime(true) - $start_time);
				return $response;
			}
		}

		// Fallback to non-streaming
		$request->set_streaming(false);
		return $this->send($request);
	}

	/**
	 * Send request to specific provider
	 *
	 * @param AI_Provider_Interface $provider
	 * @param AI_Request $request
	 * @return AI_Response
	 */
	private function send_to_provider(AI_Provider_Interface $provider, AI_Request $request): AI_Response {
		try {
			return $provider->send($request);
		} catch (\Throwable $e) {
			return AI_Response::error(
				'Provider error: ' . $e->getMessage(),
				$e->getCode() ?: 500,
				$provider->get_id()
			);
		}
	}

	/**
	 * Get provider chain based on request
	 *
	 * @param AI_Request $request
	 * @return array<int,string>
	 */
	private function get_provider_chain(AI_Request $request): array {
		$specified_provider = $request->get_provider();
		
		// If provider explicitly specified, prioritize it
		if (! empty($specified_provider) && isset($this->providers[$specified_provider])) {
			$chain = array($specified_provider);
			
			// Add fallback providers
			if ($this->enable_fallback) {
				foreach ($this->fallback_chain as $fallback) {
					if ($fallback !== $specified_provider) {
						$chain[] = $fallback;
					}
				}
			}
			
			return $chain;
		}

		// Smart routing based on purpose
		if ($this->enable_smart_routing) {
			return $this->get_smart_route($request);
		}

		return $this->fallback_chain;
	}

	/**
	 * Get smart route based on request purpose
	 *
	 * @param AI_Request $request
	 * @return array<int,string>
	 */
	private function get_smart_route(AI_Request $request): array {
		$purpose = $request->get_purpose();
		
		switch ($purpose) {
			case 'debugging':
			case 'code':
				// Prefer GPT/Claude for code tasks
				return array('openai', 'claude', 'gemini');
			
			case 'analysis':
				// Prefer Claude for long analysis
				return array('claude', 'openai', 'gemini');
			
			case 'cheap':
				// Prefer Gemini for cost-effective
				return array('gemini', 'openai', 'claude');
			
			case 'creative':
				// Prefer GPT for creative tasks
				return array('openai', 'claude', 'gemini');
			
			default:
				return $this->fallback_chain;
		}
	}

	/**
	 * Log fallback event
	 *
	 * @param string $provider_id
	 * @param string $error
	 */
	private function log_fallback(string $provider_id, string $error): void {
		$fallback_log = get_option('wudt_ai_fallback_log', array());
		$fallback_log[] = array(
			'time'     => current_time('mysql'),
			'provider' => $provider_id,
			'error'    => substr($error, 0, 500),
		);
		// Keep only last 50 entries
		$fallback_log = array_slice($fallback_log, -50);
		update_option('wudt_ai_fallback_log', $fallback_log, false);
	}

	/**
	 * Set fallback chain
	 *
	 * @param array<int,string> $chain
	 * @return $this
	 */
	public function set_fallback_chain(array $chain): self {
		$this->fallback_chain = $chain;
		return $this;
	}

	/**
	 * Enable/disable fallback
	 *
	 * @param bool $enable
	 * @return $this
	 */
	public function set_enable_fallback(bool $enable): self {
		$this->enable_fallback = $enable;
		return $this;
	}

	/**
	 * Enable/disable smart routing
	 *
	 * @param bool $enable
	 * @return $this
	 */
	public function set_enable_smart_routing(bool $enable): self {
		$this->enable_smart_routing = $enable;
		return $this;
	}

	/**
	 * Enable/disable caching
	 *
	 * @param bool $enable
	 * @return $this
	 */
	public function set_enable_caching(bool $enable): self {
		$this->enable_caching = $enable;
		return $this;
	}

	/**
	 * Get available models from all providers
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function get_all_available_models(): array {
		$models = array();
		foreach ($this->get_configured_providers() as $id => $provider) {
			$models[$id] = $provider->get_available_models();
		}
		return $models;
	}

	/**
	 * Get unified model list for UI
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_models_for_ui(): array {
		$ui_models = array();
		
		foreach ($this->get_configured_providers() as $provider_id => $provider) {
			foreach ($provider->get_available_models() as $model) {
				$ui_models[] = array(
					'id'                 => $model['id'],
					'name'               => $model['name'],
					'provider'           => $provider_id,
					'provider_name'      => $provider->get_name(),
					'cost_per_1k_input'  => $model['cost_per_1k_input'] ?? 0,
					'cost_per_1k_output' => $model['cost_per_1k_output'] ?? 0,
					'supports_streaming' => $model['supports_streaming'] ?? false,
					'max_tokens'         => $model['max_tokens'] ?? 4096,
				);
			}
		}
		
		return $ui_models;
	}

	/**
	 * Validate a provider connection
	 *
	 * @param string $provider_id
	 * @return array<string,mixed>
	 */
	public function validate_provider(string $provider_id): array {
		$provider = $this->get_provider($provider_id);
		
		if (! $provider) {
			return array(
				'success' => false,
				'message' => 'Provider not found: ' . $provider_id,
			);
		}

		if (! $provider->is_configured()) {
			return array(
				'success' => false,
				'message' => 'Provider not configured: ' . $provider_id,
			);
		}

		return $provider->validate_connection();
	}

	/**
	 * Get usage statistics
	 *
	 * @return array<string,mixed>
	 */
	public function get_usage_stats(): array {
		return $this->cost_tracker ? $this->cost_tracker->get_stats() : array();
	}

	/**
	 * Singleton instance
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return self
	 */
	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton instance (for testing)
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
