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

		// Get database tables info
		$db_tables = array();
		if (!empty($flags['database'])) {
			$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
			$db_tables = array_slice(array_column($tables, 0), 0, 20);
		}

		// Get plugin details
		$plugin_details = array();
		if (!empty($flags['plugins']) && function_exists('get_plugins')) {
			$all_plugins = get_plugins();
			foreach ($active_plugins as $plugin_file) {
				if (isset($all_plugins[$plugin_file])) {
					$plugin_details[] = array(
						'name'    => $all_plugins[$plugin_file]['Name'],
						'version' => $all_plugins[$plugin_file]['Version'],
						'file'    => $plugin_file,
					);
				}
			}
		}

		// Get theme details
		$theme_info = array();
		if ($theme) {
			$theme_info = array(
				'name'       => $theme->get('Name'),
				'version'    => $theme->get('Version'),
				'template'   => $theme->get_template(),
				'stylesheet' => $theme->get_stylesheet(),
			);
		}

		// Get WordPress config info
		$wp_config = array(
			'wp_version'       => get_bloginfo('version'),
			'site_url'         => home_url('/'),
			'db_prefix'        => $wpdb->prefix,
			'wp_debug'         => defined('WP_DEBUG') && WP_DEBUG,
			'wp_debug_log'     => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
			'script_debug'     => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
			'wp_cache'         => defined('WP_CACHE') && WP_CACHE,
			'filesystem_method'=> get_filesystem_method(),
		);

		// Get PHP info
		$php_info = array(
			'version'            => PHP_VERSION,
			'memory_limit'       => ini_get('memory_limit'),
			'max_execution_time' => ini_get('max_execution_time'),
			'max_input_vars'     => ini_get('max_input_vars'),
			'post_max_size'      => ini_get('post_max_size'),
			'upload_max_filesize'=> ini_get('upload_max_filesize'),
			'display_errors'     => ini_get('display_errors'),
			'extensions'         => array_slice(get_loaded_extensions(), 0, 15),
		);

		// Get available file editor paths
		$file_paths = array(
			'wp_content_dir'     => WP_CONTENT_DIR,
			'plugin_dir'         => WP_PLUGIN_DIR,
			'theme_dir'          => get_theme_root(),
			'uploads_dir'        => wp_upload_dir()['basedir'],
			'abspath'            => ABSPATH,
		);

		$context = array(
			'user_prompt'           => sanitize_textarea_field($user_prompt),
			'php_errors'            => ! empty($flags['error_logs']) ? $recent_errors : array(),
			'active_plugins'        => ! empty($flags['plugins']) ? $plugin_details : array(),
			'theme'                 => $theme_info,
			'wp_config'             => $wp_config,
			'php_info'              => $php_info,
			'database_tables'       => $db_tables,
			'file_paths'            => $file_paths,
			'recent_logs'           => (array) get_option('wudt_operation_logs', array()),
			'malware_scan_results'  => (array) get_option('wudt_enterprise_malware_results', array()),
			'file_content'          => ! empty($flags['file']) ? $this->mask_secrets($selected_file_content) : '',
			'client_chat_transcript'=> ! empty($flags['chat_transcript']) ? $this->mask_secrets($chat_transcript) : '',
			'database_issues'       => ! empty($flags['database']) ? $db_issues : array(),
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
