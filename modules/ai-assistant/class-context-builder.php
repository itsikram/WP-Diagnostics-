<?php
/**
 * Build structured AI context from diagnostics data.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

if (! defined('ABSPATH')) {
	exit;
}

class Context_Builder {
	/**
	 * @param array<string,mixed> $flags
	 * @return array<string,mixed>
	 */
	public function build(string $user_prompt, array $flags, string $selected_file_content = '', string $chat_transcript = ''): array {
		global $wpdb;
		$errors_option = (array) get_option('wudt_error_log_entries', array());
		$recent_errors = array_slice($errors_option, -20);
		$active_plugins = (array) get_option('active_plugins', array());
		$theme = wp_get_theme();
		$db_issues = array();
		$autoload_large = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload='yes' ORDER BY size DESC LIMIT 10",
			ARRAY_A
		);
		foreach ((array) $autoload_large as $row) {
			if ((int) ($row['size'] ?? 0) > 1024 * 100) {
				$db_issues[] = $row;
			}
		}

		$context = array(
			'user_prompt'           => sanitize_textarea_field($user_prompt),
			'php_errors'            => ! empty($flags['error_logs']) ? $recent_errors : array(),
			'active_plugins'        => ! empty($flags['plugins']) ? $active_plugins : array(),
			'theme'                 => $theme ? $theme->get('Name') : '',
			'wp_version'            => get_bloginfo('version'),
			'php_version'           => PHP_VERSION,
			'recent_logs'           => (array) get_option('wudt_operation_logs', array()),
			'malware_scan_results'  => (array) get_option('wudt_enterprise_malware_results', array()),
			'file_content'          => ! empty($flags['file']) ? $this->mask_secrets($selected_file_content) : '',
			'client_chat_transcript'=> ! empty($flags['chat_transcript']) ? $this->mask_secrets($chat_transcript) : '',
			'database_issues'       => ! empty($flags['database']) ? $db_issues : array(),
			'system_info'           => ! empty($flags['system']) ? array(
				'site_url'      => home_url('/'),
				'memory_limit'  => ini_get('memory_limit'),
				'max_execution' => ini_get('max_execution_time'),
			) : array(),
		);
		$context['recent_logs'] = array_slice((array) $context['recent_logs'], -30);
		return $this->sanitize_context($context);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function sanitize_context(array $context): array {
		$json = wp_json_encode($context);
		$text = false !== $json ? (string) $json : '';
		$text = $this->mask_secrets($text);
		$val  = json_decode($text, true);
		return is_array($val) ? $val : $context;
	}

	private function mask_secrets(string $text): string {
		$masked = preg_replace('/(DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY)\s*[\'"]?\s*[,=]\s*[\'"][^\'"]+[\'"]/', '$1=***', $text);
		$masked = is_string($masked) ? $masked : $text;
		$masked = preg_replace('/(api[_-]?key|token|secret)\s*[:=]\s*[\'"]?([a-zA-Z0-9_\-]{8,})[\'"]?/i', '$1=***', $masked);
		return is_string($masked) ? $masked : $text;
	}
}
