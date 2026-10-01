<?php
/**
 * AI provider configuration: per-provider API keys (encrypted) and models.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Config {
	public const OPTION_PROVIDER = 'wudt_ai_provider';
	public const OPTION_KEYS = 'wudt_ai_keys';
	public const OPTION_MODELS = 'wudt_ai_models';
	public const OPTION_AUTO_APPROVE = 'wudt_ai_auto_approve';
	private const OPTION_LEGACY_KEY = 'wudt_ai_api_key';
	private const OPTION_LEGACY_MODEL = 'wudt_ai_model';

	/**
	 * @return array<string,array{label:string,default_model:string,models:array<int,string>,key_url:string,key_hint:string}>
	 */
	public static function providers(): array {
		return array(
			'gemini'     => array(
				'label'         => 'Google Gemini',
				'default_model' => 'gemini-2.5-flash',
				'models'        => array('gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'),
				'key_url'       => 'https://aistudio.google.com/app/apikey',
				'key_hint'      => 'AIza…',
			),
			'anthropic'  => array(
				'label'         => 'Anthropic Claude',
				'default_model' => 'claude-sonnet-5-5',
				'models'        => array('claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-4-5-20251001', 'claude-fable-5-1'),
				'key_url'       => 'https://console.anthropic.com/settings/keys',
				'key_hint'      => 'sk-ant-…',
			),
			'openai'     => array(
				'label'         => 'OpenAI',
				'default_model' => 'gpt-4o',
				'models'        => array('gpt-4o', 'gpt-4o-mini'),
				'key_url'       => 'https://platform.openai.com/api-keys',
				'key_hint'      => 'sk-…',
			),
			'openrouter' => array(
				'label'         => 'OpenRouter',
				'default_model' => 'anthropic/claude-sonnet-4.5',
				'models'        => array('anthropic/claude-sonnet-4.5', 'google/gemini-2.5-pro', 'openai/gpt-4o'),
				'key_url'       => 'https://openrouter.ai/keys',
				'key_hint'      => 'sk-or-…',
			),
		);
	}

	public static function is_provider(string $provider): bool {
		return isset(self::providers()[$provider]);
	}

	public static function active_provider(): string {
		$provider = (string) get_option(self::OPTION_PROVIDER, '');
		if (self::is_provider($provider) && '' !== self::get_key($provider)) {
			return $provider;
		}
		foreach (array_keys(self::providers()) as $candidate) {
			if ('' !== self::get_key($candidate)) {
				return $candidate;
			}
		}
		return self::is_provider($provider) ? $provider : 'gemini';
	}

	public static function set_active_provider(string $provider): void {
		if (self::is_provider($provider)) {
			update_option(self::OPTION_PROVIDER, $provider, false);
		}
	}

	public static function get_key(string $provider): string {
		if ('anthropic' === $provider && defined('WUDT_ANTHROPIC_API_KEY')) {
			return (string) WUDT_ANTHROPIC_API_KEY;
		}
		if ('gemini' === $provider && defined('WUDT_GEMINI_API_KEY')) {
			return (string) WUDT_GEMINI_API_KEY;
		}
		$keys = get_option(self::OPTION_KEYS, array());
		$keys = is_array($keys) ? $keys : array();
		if (! empty($keys[$provider])) {
			return Secret_Box::decrypt((string) $keys[$provider]);
		}
		// Legacy single key belongs to the provider that was selected when it was saved.
		$legacy = (string) get_option(self::OPTION_LEGACY_KEY, '');
		if ('' !== $legacy && (string) get_option(self::OPTION_PROVIDER, 'gemini') === $provider) {
			$decoded = base64_decode($legacy, true);
			return false !== $decoded ? trim($decoded) : '';
		}
		if (defined('WUDT_AI_API_KEY') && (string) get_option(self::OPTION_PROVIDER, 'gemini') === $provider) {
			return (string) WUDT_AI_API_KEY;
		}
		return '';
	}

	public static function set_key(string $provider, string $key): void {
		if (! self::is_provider($provider)) {
			return;
		}
		$keys = get_option(self::OPTION_KEYS, array());
		$keys = is_array($keys) ? $keys : array();
		$key = trim($key);
		if ('' === $key) {
			unset($keys[$provider]);
		} else {
			$keys[$provider] = Secret_Box::encrypt($key);
		}
		update_option(self::OPTION_KEYS, $keys, false);
		if ((string) get_option(self::OPTION_PROVIDER, '') === $provider) {
			delete_option(self::OPTION_LEGACY_KEY);
		}
	}

	public static function key_preview(string $provider): string {
		$key = self::get_key($provider);
		if ('' === $key) {
			return '';
		}
		return substr($key, 0, 6) . '…' . substr($key, -4);
	}

	public static function get_model(string $provider): string {
		$models = get_option(self::OPTION_MODELS, array());
		$models = is_array($models) ? $models : array();
		if (! empty($models[$provider])) {
			return (string) $models[$provider];
		}
		$legacy = (string) get_option(self::OPTION_LEGACY_MODEL, '');
		if ('' !== $legacy && (string) get_option(self::OPTION_PROVIDER, 'gemini') === $provider && self::model_matches_provider($legacy, $provider)) {
			return $legacy;
		}
		return self::providers()[$provider]['default_model'] ?? '';
	}

	public static function set_model(string $provider, string $model): void {
		$models = get_option(self::OPTION_MODELS, array());
		$models = is_array($models) ? $models : array();
		$models[$provider] = sanitize_text_field($model);
		update_option(self::OPTION_MODELS, $models, false);
	}

	private static function model_matches_provider(string $model, string $provider): bool {
		switch ($provider) {
			case 'gemini':
				return 0 === strpos($model, 'gemini');
			case 'anthropic':
				// Old "claude-3-*" style names from earlier versions are retired.
				return 0 === strpos($model, 'claude') && false === strpos($model, 'claude-3');
			default:
				return true;
		}
	}

	public static function auto_approve(): bool {
		return (bool) get_option(self::OPTION_AUTO_APPROVE, false);
	}

	public static function max_tokens(): int {
		$tokens = (int) get_option('wudt_ai_max_tokens', 16000);
		return max(1024, min(64000, $tokens < 4000 ? 16000 : $tokens));
	}

	public static function temperature(): float {
		return max(0.0, min(1.0, (float) get_option('wudt_ai_temperature', 0.4)));
	}
}

/**
 * Symmetric encryption for secrets stored in the database, keyed by the
 * site's wp-config salts so a database dump alone does not reveal them.
 */
class Secret_Box {
	private const PREFIX = 'wudt1:';

	private static function key(): string {
		$material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') . 'wudt-secret-box';
		if ('wudt-secret-box' === $material && defined('DB_PASSWORD')) {
			$material .= DB_PASSWORD . (defined('DB_NAME') ? DB_NAME : '');
		}
		return hash('sha256', $material, true);
	}

	public static function encrypt(string $plain): string {
		if (! function_exists('openssl_encrypt')) {
			return 'b64:' . base64_encode($plain);
		}
		$iv = random_bytes(12);
		$tag = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
		if (false === $cipher) {
			return 'b64:' . base64_encode($plain);
		}
		return self::PREFIX . base64_encode($iv . $tag . $cipher);
	}

	public static function decrypt(string $stored): string {
		if (0 === strpos($stored, 'b64:')) {
			return (string) base64_decode(substr($stored, 4), true);
		}
		if (0 !== strpos($stored, self::PREFIX) || ! function_exists('openssl_decrypt')) {
			return '';
		}
		$raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if (false === $raw || strlen($raw) < 29) {
			return '';
		}
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
		return false === $plain ? '' : $plain;
	}
}
