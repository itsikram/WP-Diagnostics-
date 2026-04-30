<?php
/**
 * AI assistant controller and AJAX endpoints.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Controller extends Module_Base {
	private Context_Builder $context_builder;
	private AI_Service $service;
	private Response_Parser $parser;

	public function __construct() {
		$this->context_builder = new Context_Builder();
		$this->service         = new AI_Service();
		$this->parser          = new Response_Parser();
	}

	public function register_hooks(): void {
		add_action('admin_init', array($this, 'maybe_create_table'));
		add_action('wp_ajax_diagnostics_ai_chat', array($this, 'ajax_chat'));
		add_action('wp_ajax_diagnostics_ai_chat_stream', array($this, 'ajax_chat_stream'));
		add_action('wp_ajax_diagnostics_ai_history', array($this, 'ajax_history'));
		add_action('wp_ajax_diagnostics_ai_clear_history', array($this, 'ajax_clear_history'));
		add_action('wp_ajax_diagnostics_ai_apply_fix', array($this, 'ajax_apply_fix'));
		add_action('wp_ajax_diagnostics_ai_execute_batch', array($this, 'ajax_execute_batch'));
		add_action('wp_ajax_diagnostics_ai_task_status', array($this, 'ajax_task_status'));
		add_action('wp_ajax_diagnostics_ai_autodebug', array($this, 'ajax_autodebug'));
		add_action('wp_ajax_diagnostics_ai_models', array($this, 'ajax_models'));
		add_action('wp_ajax_diagnostics_ai_test_api', array($this, 'ajax_test_api'));
	}

	public function get_key(): string {
		return 'ai_assistant';
	}

	public function get_label(): string {
		return __('AI Assistant', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'history' => $this->get_history(20),
			'rate_limit' => array('max_per_minute' => 12),
			'modes' => array('ask', 'agent'),
			'models' => $this->service->list_models(),
		);
	}

	public function maybe_create_table(): void {
		global $wpdb;
		$table = $this->table_name();
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists === $table) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			content LONGTEXT NOT NULL,
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta($sql);
	}

	public function ajax_chat(): void {
		Security_Guard::assert_ajax_admin();
		$this->enforce_rate_limit();
		$prompt   = sanitize_textarea_field((string) wp_unslash($_POST['prompt'] ?? ''));
		$flags    = isset($_POST['context_flags']) ? (array) json_decode((string) wp_unslash($_POST['context_flags']), true) : array();
		$file     = isset($_POST['file_content']) ? (string) wp_unslash($_POST['file_content']) : '';
		$chat_txt = isset($_POST['chat_transcript']) ? (string) wp_unslash($_POST['chat_transcript']) : '';
		$model    = sanitize_text_field((string) wp_unslash($_POST['model'] ?? ''));
		$mode     = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'ask'));
		$history  = $this->get_history(10);
		$context  = $this->context_builder->build($prompt, $flags, $file, $chat_txt);
		$result   = $this->service->complete($prompt, $context, $history, array('model' => $model, 'mode' => $mode));
		$parsed   = $this->parser->parse((string) ($result['content'] ?? ''));
		$text     = (string) ($parsed['text'] ?? '');
		if ('agent' !== $mode) {
			$text = $this->sanitize_ask_mode_text($text);
			$parsed['action'] = array();
		}
		$this->push_history('user', $prompt, $context);
		$this->push_history('assistant', $text, array('model' => $result['model'] ?? 'unknown', 'mode' => $mode));
		Operation_Logger::log('ai', 'AI diagnosis generated', array('prompt_len' => strlen($prompt)));
		$response = array(
		'message' => $text,
			'model'   => $result['model'] ?? 'unknown',
		);
		
		// Include debug info if available (when WP_DEBUG is enabled)
		if (! empty($result['debug_info'])) {
			$response['debug_info'] = $result['debug_info'];
		}
		
		wp_send_json_success($response);
	}

	public function ajax_chat_stream(): void {
		Security_Guard::assert_ajax_admin();
		$this->enforce_rate_limit();
		$prompt  = sanitize_textarea_field((string) wp_unslash($_POST['prompt'] ?? ''));
		$flags   = isset($_POST['context_flags']) ? (array) json_decode((string) wp_unslash($_POST['context_flags']), true) : array();
		$file    = isset($_POST['file_content']) ? (string) wp_unslash($_POST['file_content']) : '';
		$chat_txt = isset($_POST['chat_transcript']) ? (string) wp_unslash($_POST['chat_transcript']) : '';
		$model   = sanitize_text_field((string) wp_unslash($_POST['model'] ?? ''));
		$mode    = sanitize_key((string) wp_unslash($_POST['mode'] ?? 'ask'));
		$history = $this->get_history(10);
		$context = $this->context_builder->build($prompt, $flags, $file, $chat_txt);
		$result  = $this->service->complete($prompt, $context, $history, array('model' => $model, 'mode' => $mode));
		$text    = (string) ($result['content'] ?? '');
		$parsed  = $this->parser->parse($text);
		$text    = (string) ($parsed['text'] ?? $text);
		if ('agent' !== $mode) {
			$text = $this->sanitize_ask_mode_text($text);
			$parsed['action'] = array();
		}
		$this->push_history('user', $prompt, $context);
		$this->push_history('assistant', $text, array('streamed' => true, 'mode' => $mode, 'model' => $model));

		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		$chunk_size = 260;
		$total = strlen($text);
		for ($i = 0; $i < $total; $i += $chunk_size) {
			$chunk = substr($text, $i, $chunk_size);
			echo wp_json_encode(array('type' => 'chunk', 'content' => $chunk)) . "\n";
			@ob_flush();
			@flush();
			usleep(30000);
		}
		// Include debug info if available (when WP_DEBUG is enabled)
		if (! empty($result['debug_info'])) {
			echo wp_json_encode(array('type' => 'debug', 'content' => $result['debug_info'])) . "\n";
			@ob_flush();
			@flush();
		}
		echo wp_json_encode(array(
			'type'     => 'done',
			'action'   => $parsed['action'] ?? array(),
			'actions'  => $parsed['actions'] ?? array()
		)) . "\n";
		exit;
	}

	private function sanitize_ask_mode_text(string $text): string {
		$patterns = array(
			'/\r?\n\s*Action Block[\s\S]*$/i',
			'/\r?\n\s*The following actions will be executed[\s\S]*$/i',
			'/\r?\n\s*Please confirm the execution of these actions\.*.*$/i',
			'/```json[\s\S]*?```/i',
			'/`{1,3}\{[\s\S]*?\}`{1,3}/',
		);
		$clean = preg_replace($patterns, '', $text);
		if (! is_string($clean)) {
			return trim(preg_replace('/\n{3,}/', "\n\n", trim($text)));
		}
		$clean = preg_replace('/\n{3,}/', "\n\n", trim($clean));
		return trim($clean);
	}

	public function ajax_history(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('history' => $this->get_history(80)));
	}

	public function ajax_clear_history(): void {
		Security_Guard::assert_ajax_admin();
		global $wpdb;
		$wpdb->delete($this->table_name(), array('user_id' => get_current_user_id()), array('%d'));
		wp_send_json_success(array('cleared' => true));
	}

	public function ajax_apply_fix(): void {
		Security_Guard::assert_ajax_admin();
		$action = sanitize_key((string) wp_unslash($_POST['action_type'] ?? ''));
		$params = isset($_POST['params']) ? (array) json_decode((string) wp_unslash($_POST['params']), true) : array();
		$result = array('applied' => false, 'message' => '', 'data' => null);

		switch ($action) {
			case 'activate_plugin':
				$result = $this->action_activate_plugin($params);
				break;
			case 'install_plugin':
				$result = $this->action_install_plugin($params);
				break;
			case 'install_theme':
				$result = $this->action_install_theme($params);
				break;
			case 'activate_theme':
				$result = $this->action_activate_theme($params);
				break;
			case 'disable_plugin':
				$result = $this->action_disable_plugin($params);
				break;
			case 'run_sql':
				$result = $this->action_run_sql($params);
				break;
			case 'edit_file':
				$result = $this->action_edit_file($params);
				break;
			case 'create_file':
				$result = $this->action_create_file($params);
				break;
			case 'delete_file':
				$result = $this->action_delete_file($params);
				break;
			case 'read_file':
				$result = $this->action_read_file($params);
				break;
			case 'toggle_wp_debug':
				$result = $this->action_toggle_wp_debug($params);
				break;
			case 'schedule_cron':
				$result = $this->action_schedule_cron($params);
				break;
			case 'unschedule_cron':
				$result = $this->action_unschedule_cron($params);
				break;
			case 'search_replace_db':
				$result = $this->action_search_replace_db($params);
				break;
			case 'search_files':
				$result = $this->action_search_files($params);
				break;
			case 'chmod':
				$result = $this->action_chmod($params);
				break;
			case 'compress':
				$result = $this->action_compress($params);
				break;
			case 'extract':
				$result = $this->action_extract($params);
				break;
			case 'rename':
				$result = $this->action_rename($params);
				break;
			case 'list_directory':
				$result = $this->action_list_directory($params);
				break;
			case 'optimize_tables':
				$result = $this->action_optimize_tables($params);
				break;
			case 'repair_tables':
				$result = $this->action_repair_tables($params);
				break;
			case 'get_system_info':
				$result = $this->action_get_system_info($params);
				break;
			default:
				$result['message'] = 'Unknown action: ' . $action;
				break;
		}

		if ($result['applied']) {
			Operation_Logger::log('ai', 'AI action applied: ' . $action, array('params' => $params));
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Activate a plugin action
	 */
	private function action_activate_plugin(array $params): array {
		if (empty($params['plugin'])) {
			return array('applied' => false, 'message' => 'Plugin parameter missing');
		}
		$plugin = sanitize_text_field((string) $params['plugin']);
		
		// Check if plugin file exists
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin;
		if (!file_exists($plugin_path)) {
			return array('applied' => false, 'message' => 'Plugin file not found: ' . $plugin);
		}
		
		// Check if already active
		if (is_plugin_active($plugin)) {
			return array('applied' => true, 'message' => 'Plugin already active: ' . $plugin);
		}
		
		// Include plugin.php if not already included
		if (!function_exists('activate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		// Activate the plugin
		$result = activate_plugin($plugin);
		if (is_wp_error($result)) {
			return array('applied' => false, 'message' => 'Activation failed: ' . $result->get_error_message());
		}
		
		return array('applied' => true, 'message' => 'Plugin activated: ' . $plugin);
	}

	/**
	 * Install a plugin action
	 */
	private function action_install_plugin(array $params): array {
		if (empty($params['plugin_slug']) && empty($params['plugin'])) {
			return array('applied' => false, 'message' => 'Plugin slug or name missing');
		}
		
		$plugin_value = (string) ($params['plugin_slug'] ?? $params['plugin']);
		$plugin_value = sanitize_text_field($plugin_value);
		$plugin_slug = $this->normalize_plugin_slug($plugin_value);
		$activate = !empty($params['activate']) && $params['activate'] === true;
		
		// Check if already installed
		$plugin_file = $this->find_plugin_file($plugin_slug);
		if ($plugin_file) {
			// Already installed, maybe activate it
			if ($activate && !is_plugin_active($plugin_file)) {
				$result = activate_plugin($plugin_file);
				if (is_wp_error($result)) {
					return array('applied' => false, 'message' => 'Plugin installed but activation failed: ' . $result->get_error_message());
				}
				return array('applied' => true, 'message' => 'Plugin already installed and now activated: ' . $plugin_slug, 'data' => array('plugin_file' => $plugin_file));
			}
			return array('applied' => true, 'message' => 'Plugin already installed: ' . $plugin_slug, 'data' => array('plugin_file' => $plugin_file));
		}
		
		// Include required files for plugin installation
		if (!function_exists('plugins_api')) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if (!function_exists('wp_upgrader')) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if (!function_exists('Plugin_Upgrader')) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}
		if (!function_exists('Plugin_Installer_Skin')) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		}
		
		// Get plugin info from WordPress.org
		$api = plugins_api('plugin_information', array(
			'slug'   => $plugin_slug,
			'fields' => array('sections' => false),
		));
		
		if (is_wp_error($api)) {
			return array('applied' => false, 'message' => 'Plugin not found on WordPress.org: ' . $plugin_slug);
		}
		
		// Install the plugin
		$upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
		$result = $upgrader->install($api->download_link);
		
		if (is_wp_error($result) || !$result) {
			return array('applied' => false, 'message' => 'Installation failed: ' . (is_wp_error($result) ? $result->get_error_message() : 'Unknown error'));
		}
		
		// Find the installed plugin file
		$plugin_file = $this->find_plugin_file($plugin_slug);
		
		if (!$plugin_file) {
			return array('applied' => true, 'message' => 'Plugin installed but could not determine plugin file: ' . $plugin_slug);
		}
		
		// Activate if requested
		if ($activate) {
			$result = activate_plugin($plugin_file);
			if (is_wp_error($result)) {
				return array('applied' => true, 'message' => 'Plugin installed but activation failed: ' . $result->get_error_message(), 'data' => array('plugin_file' => $plugin_file));
			}
			return array('applied' => true, 'message' => 'Plugin installed and activated: ' . $plugin_slug, 'data' => array('plugin_file' => $plugin_file));
		}
		
		return array('applied' => true, 'message' => 'Plugin installed successfully: ' . $plugin_slug, 'data' => array('plugin_file' => $plugin_file));
	}
	
	/**
	 * Find plugin file by slug
	 */
	private function normalize_plugin_slug(string $plugin_value): string {
		$plugin_value = trim($plugin_value);
		if (strpos($plugin_value, '/') !== false) {
			$parts = explode('/', $plugin_value);
			$plugin_value = $parts[0];
		}
		if (substr($plugin_value, -4) === '.php') {
			$plugin_value = basename($plugin_value, '.php');
		}
		return sanitize_key($plugin_value);
	}

	private function find_plugin_file(string $plugin_slug): ?string {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		$all_plugins = get_plugins();
		
		// Direct match
		if (isset($all_plugins[$plugin_slug . '/' . $plugin_slug . '.php'])) {
			return $plugin_slug . '/' . $plugin_slug . '.php';
		}
		
		// Search for plugin folder matching slug
		foreach ($all_plugins as $plugin_file => $plugin_data) {
			$parts = explode('/', $plugin_file);
			if (strtolower($parts[0]) === strtolower($plugin_slug)) {
				return $plugin_file;
			}
		}
		
		return null;
	}

	/**
	 * Install theme action - downloads and installs from WordPress.org
	 */
	private function action_install_theme(array $params): array {
		if (empty($params['theme_slug'])) {
			return array('applied' => false, 'message' => 'Theme slug missing');
		}
		
		$theme_slug = sanitize_text_field((string) $params['theme_slug']);
		$activate = !empty($params['activate']);
		
		// Check if theme is already installed
		$theme = wp_get_theme($theme_slug);
		if ($theme->exists()) {
			// Theme exists, activate if requested
			if ($activate) {
				switch_theme($theme_slug);
				return array('applied' => true, 'message' => 'Theme already installed and now activated: ' . $theme_slug);
			}
			return array('applied' => true, 'message' => 'Theme already installed: ' . $theme_slug);
		}
		
		// Include required files for theme installation
		if (!function_exists('themes_api')) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if (!function_exists('wp_upgrader')) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if (!class_exists('Theme_Upgrader')) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		}
		
		// Get theme info from WordPress.org
		$api = themes_api('theme_information', array(
			'slug'   => $theme_slug,
			'fields' => array('sections' => false),
		));
		
		if (is_wp_error($api)) {
			return array('applied' => false, 'message' => 'Theme not found on WordPress.org: ' . $theme_slug);
		}
		
		// Install the theme
		$upgrader = new \Theme_Upgrader(new \WP_Ajax_Upgrader_Skin());
		$result = $upgrader->install($api->download_link);
		
		if (is_wp_error($result) || !$result) {
			return array('applied' => false, 'message' => 'Theme installation failed: ' . (is_wp_error($result) ? $result->get_error_message() : 'Unknown error'));
		}
		
		// Activate if requested
		if ($activate) {
			switch_theme($theme_slug);
			return array('applied' => true, 'message' => 'Theme installed and activated: ' . $theme_slug);
		}
		
		return array('applied' => true, 'message' => 'Theme installed successfully: ' . $theme_slug);
	}

	/**
	 * Activate theme action - switch active theme
	 */
	private function action_activate_theme(array $params): array {
		if (empty($params['theme_slug'])) {
			return array('applied' => false, 'message' => 'Theme slug missing');
		}
		
		$theme_slug = sanitize_text_field((string) $params['theme_slug']);
		
		// Check if theme exists
		$theme = wp_get_theme($theme_slug);
		if (!$theme->exists()) {
			return array('applied' => false, 'message' => 'Theme not found: ' . $theme_slug . '. Please install it first.');
		}
		
		// Check if already active
		$current_theme = wp_get_theme();
		if ($current_theme->get_stylesheet() === $theme_slug) {
			return array('applied' => false, 'message' => 'Theme is already active: ' . $theme_slug);
		}
		
		// Switch theme
		switch_theme($theme_slug);
		
		// Verify switch
		$new_theme = wp_get_theme();
		if ($new_theme->get_stylesheet() === $theme_slug) {
			return array('applied' => true, 'message' => 'Theme activated successfully: ' . $theme->get('Name') . ' (' . $theme_slug . ')');
		}
		
		return array('applied' => false, 'message' => 'Failed to activate theme: ' . $theme_slug);
	}

	/**
	 * Disable a plugin action
	 */
	private function action_disable_plugin(array $params): array {
		if (empty($params['plugin'])) {
			return array('applied' => false, 'message' => 'Plugin parameter missing');
		}
		$plugin = sanitize_text_field((string) $params['plugin']);
		$protected = array('wp-ultimate-diagnostics-toolkit/wp-ultimate-diagnostics-toolkit.php');
		if (in_array($plugin, $protected, true)) {
			return array('applied' => false, 'message' => 'Cannot disable this plugin');
		}
		if (!is_plugin_active($plugin)) {
			return array('applied' => false, 'message' => 'Plugin is not active');
		}
		deactivate_plugins($plugin, true);
		return array('applied' => true, 'message' => 'Plugin disabled: ' . $plugin);
	}

	/**
	 * Run SQL query action
	 */
	private function action_run_sql(array $params): array {
		global $wpdb;
		if (empty($params['sql'])) {
			return array('applied' => false, 'message' => 'SQL query missing');
		}
		$sql = sanitize_textarea_field((string) $params['sql']);
		$readonly = !empty($params['readonly']);

		// Block dangerous queries
		$dangerous = array('DROP DATABASE', 'TRUNCATE DATABASE', 'ALTER DATABASE', 'CREATE DATABASE', 'GRANT', 'REVOKE');
		$sql_upper = strtoupper($sql);
		foreach ($dangerous as $word) {
			if (strpos($sql_upper, $word) !== false) {
				return array('applied' => false, 'message' => 'Query contains blocked keyword: ' . $word);
			}
		}

		// Determine query type
		$is_select = strpos($sql_upper, 'SELECT') === 0 || strpos($sql_upper, 'SHOW') === 0 || strpos($sql_upper, 'DESCRIBE') === 0;
		$is_write = !$is_select && (strpos($sql_upper, 'INSERT') === 0 || strpos($sql_upper, 'UPDATE') === 0 || strpos($sql_upper, 'DELETE') === 0);

		if ($readonly && $is_write) {
			return array('applied' => false, 'message' => 'Write operations not allowed in readonly mode');
		}

		// Replace wp_ prefix placeholder
		$sql = str_replace('wp_', $wpdb->prefix, $sql);

		$wpdb->suppress_errors = true;
		if ($is_select) {
			$rows = $wpdb->get_results($sql, ARRAY_A);
			if ($wpdb->last_error) {
				return array('applied' => false, 'message' => 'SQL Error: ' . $wpdb->last_error);
			}
			return array(
				'applied' => true,
				'message' => 'Query executed successfully. Rows: ' . count($rows),
				'data' => array('rows' => $rows, 'row_count' => count($rows))
			);
		} else {
			$rows_affected = $wpdb->query($sql);
			if ($wpdb->last_error) {
				return array('applied' => false, 'message' => 'SQL Error: ' . $wpdb->last_error);
			}
			return array(
				'applied' => true,
				'message' => 'Query executed. Rows affected: ' . $rows_affected,
				'data' => array('rows_affected' => $rows_affected)
			);
		}
	}

	/**
	 * Edit file action
	 */
	private function action_edit_file(array $params): array {
		if (empty($params['path']) || !isset($params['content'])) {
			return array('applied' => false, 'message' => 'File path or content missing');
		}
		$path = sanitize_text_field((string) $params['path']);
		$content = (string) wp_unslash($params['content']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath) {
			// File doesn't exist yet, try to resolve parent
			$fullpath = $path;
		}
		if (strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path - must be within WordPress directory');
		}

		if (!file_exists($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist: ' . $path);
		}

		// Check if writable
		if (!is_writable($fullpath)) {
			return array('applied' => false, 'message' => 'File is not writable: ' . $path);
		}

		// Create backup before editing
		$backup_path = $fullpath . '.backup.' . time();
		copy($fullpath, $backup_path);

		$result = file_put_contents($fullpath, $content, LOCK_EX);
		if ($result === false) {
			return array('applied' => false, 'message' => 'Failed to write file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File edited successfully: ' . $path,
			'data' => array('bytes_written' => $result, 'backup_path' => $backup_path)
		);
	}

	/**
	 * Create file action
	 */
	private function action_create_file(array $params): array {
		if (empty($params['path']) || !isset($params['content'])) {
			return array('applied' => false, 'message' => 'File path or content missing');
		}
		$path = sanitize_text_field((string) $params['path']);
		$content = (string) wp_unslash($params['content']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		if (strpos($path, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path - must be within WordPress directory');
		}

		if (file_exists($path)) {
			return array('applied' => false, 'message' => 'File already exists: ' . $path);
		}

		// Ensure directory exists
		$dir = dirname($path);
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		$result = file_put_contents($path, $content, LOCK_EX);
		if ($result === false) {
			return array('applied' => false, 'message' => 'Failed to create file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File created successfully: ' . $path,
			'data' => array('bytes_written' => $result)
		);
	}

	/**
	 * Delete file action
	 */
	private function action_delete_file(array $params): array {
		if (empty($params['path'])) {
			return array('applied' => false, 'message' => 'File path missing');
		}
		$path = sanitize_text_field((string) $params['path']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath || strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path');
		}

		// Block deletion of critical files
		$protected_files = array('wp-config.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php');
		$basename = basename($fullpath);
		if (in_array($basename, $protected_files, true)) {
			return array('applied' => false, 'message' => 'Cannot delete critical WordPress file: ' . $basename);
		}

		if (!file_exists($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist: ' . $path);
		}

		if (!is_writable($fullpath)) {
			return array('applied' => false, 'message' => 'File is not deletable: ' . $path);
		}

		if (unlink($fullpath)) {
			return array('applied' => true, 'message' => 'File deleted: ' . $path);
		} else {
			return array('applied' => false, 'message' => 'Failed to delete file: ' . $path);
		}
	}

	/**
	 * Toggle WP_DEBUG action - specifically for wp-config.php
	 */
	private function action_toggle_wp_debug(array $params): array {
		$wp_config_path = ABSPATH . 'wp-config.php';
		
		if (!file_exists($wp_config_path)) {
			return array('applied' => false, 'message' => 'wp-config.php not found');
		}
		
		if (!is_readable($wp_config_path)) {
			return array('applied' => false, 'message' => 'wp-config.php is not readable');
		}
		
		if (!is_writable($wp_config_path)) {
			return array('applied' => false, 'message' => 'wp-config.php is not writable - check file permissions');
		}
		
		$content = file_get_contents($wp_config_path);
		if ($content === false) {
			return array('applied' => false, 'message' => 'Failed to read wp-config.php');
		}
		
		$enable = !empty($params['enable']);
		$enable_log = isset($params['enable_log']) ? !empty($params['enable_log']) : $enable;
		$changes = array();
		
		// Handle WP_DEBUG
		if ($enable) {
			// Enable WP_DEBUG
			if (preg_match("/define\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|1|false|0)\s*\)/i", $content)) {
				$content = preg_replace("/define\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|1|false|0)\s*\)/i", "define( 'WP_DEBUG', true )", $content);
			} else {
				// Add WP_DEBUG before /* That's all, stop editing! */
				$content = str_replace("/* That's all, stop editing! */", "define( 'WP_DEBUG', true );\n\n/* That's all, stop editing! */", $content);
			}
			$changes[] = 'WP_DEBUG enabled';
		} else {
			// Disable WP_DEBUG
			$content = preg_replace("/define\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|1)\s*\)/i", "define( 'WP_DEBUG', false )", $content);
			$changes[] = 'WP_DEBUG disabled';
		}
		
		// Handle WP_DEBUG_LOG
		if ($enable_log && $enable) {
			// Enable WP_DEBUG_LOG
			if (preg_match("/define\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|1|false|0)\s*\)/i", $content)) {
				$content = preg_replace("/define\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|1|false|0)\s*\)/i", "define( 'WP_DEBUG_LOG', true )", $content);
			} else {
				// Add after WP_DEBUG
				$content = preg_replace("/define\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|1)\s*\)/i", "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true )", $content);
			}
			$changes[] = 'WP_DEBUG_LOG enabled';
		} else {
			// Disable WP_DEBUG_LOG
			$content = preg_replace("/define\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(true|1)\s*\)/i", "define( 'WP_DEBUG_LOG', false )", $content);
			if (!$enable) {
				$changes[] = 'WP_DEBUG_LOG disabled';
			}
		}
		
		// Handle WP_DEBUG_DISPLAY (disable display in production)
		if (!$enable) {
			$content = preg_replace("/define\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*(true|1)\s*\)/i", "define( 'WP_DEBUG_DISPLAY', false )", $content);
		}
		
		// Create backup
		$backup_path = $wp_config_path . '.backup.' . time();
		copy($wp_config_path, $backup_path);
		
		$result = file_put_contents($wp_config_path, $content, LOCK_EX);
		if ($result === false) {
			return array('applied' => false, 'message' => 'Failed to write wp-config.php');
		}
		
		return array(
			'applied' => true,
			'message' => 'wp-config.php updated: ' . implode(', ', $changes),
			'data' => array(
				'changes' => $changes,
				'backup_path' => $backup_path,
				'wp_debug_enabled' => $enable,
				'wp_debug_log_enabled' => $enable_log
			)
		);
	}

	/**
	 * Read file action
	 */
	private function action_read_file(array $params): array {
		if (empty($params['path'])) {
			return array('applied' => false, 'message' => 'File path missing');
		}
		$path = sanitize_text_field((string) $params['path']);

		// Validate path is within WordPress
		$abspath = realpath(ABSPATH);
		$fullpath = realpath($path);
		if (!$fullpath || strpos($fullpath, $abspath) !== 0) {
			return array('applied' => false, 'message' => 'Invalid file path');
		}

		if (!file_exists($fullpath) || !is_readable($fullpath)) {
			return array('applied' => false, 'message' => 'File does not exist or is not readable: ' . $path);
		}

		// Limit file size to prevent memory issues
		$max_size = 1024 * 1024; // 1MB
		$size = filesize($fullpath);
		if ($size > $max_size) {
			return array('applied' => false, 'message' => 'File too large to read (>1MB)');
		}

		$content = file_get_contents($fullpath);
		if ($content === false) {
			return array('applied' => false, 'message' => 'Failed to read file: ' . $path);
		}

		return array(
			'applied' => true,
			'message' => 'File read successfully: ' . $path,
			'data' => array(
				'content' => $content,
				'size' => $size,
				'modified' => date('Y-m-d H:i:s', filemtime($fullpath))
			)
		);
	}

	public function ajax_autodebug(): void {
		Security_Guard::assert_ajax_admin();
		$error_entries = (array) get_option('wudt_error_log_entries', array());
		$recent = array_slice($error_entries, -5);
		$malware = (array) get_option('wudt_enterprise_malware_results', array());
		$signal = array(
			'new_errors' => $recent,
			'malware_hits' => count($malware),
		);
		$prompt = 'Perform proactive diagnostics summary and recommend immediate safe actions.';
		$result = $this->service->complete($prompt, $signal, array(), array('mode' => 'agent'));
		wp_send_json_success(array('suggestion' => (string) ($result['content'] ?? '')));
	}

	public function ajax_models(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('models' => $this->service->list_models()));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_history(int $limit = 20): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, created_at FROM {$this->table_name()} WHERE user_id=%d ORDER BY id DESC LIMIT %d",
				get_current_user_id(),
				$limit
			),
			ARRAY_A
		);
		$rows = is_array($rows) ? array_reverse($rows) : array();
		return $rows;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function push_history(string $role, string $content, array $context = array()): void {
		global $wpdb;
		$wpdb->insert(
			$this->table_name(),
			array(
				'user_id'     => get_current_user_id(),
				'role'        => sanitize_key($role),
				'content'     => $content,
				'context_json'=> wp_json_encode($context),
				'created_at'  => current_time('mysql'),
			),
			array('%d', '%s', '%s', '%s', '%s')
		);
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wudt_ai_chat_history';
	}

	private function enforce_rate_limit(): void {
		$key = 'wudt_ai_rate_' . get_current_user_id();
		$count = (int) get_transient($key);
		if ($count >= 12) {
			wp_send_json_error(array('message' => 'Rate limit reached. Please wait a minute.'), 429);
		}
		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
	}

	/**
	 * Test API connection and return diagnostic info
	 */
	/**
	 * Execute batch of AI actions automatically
	 */
	public function ajax_execute_batch(): void {
		Security_Guard::assert_ajax_admin();
		$actions = isset($_POST['actions']) ? (array) json_decode((string) wp_unslash($_POST['actions']), true) : array();
		$execution_id = sanitize_key((string) wp_unslash($_POST['execution_id'] ?? uniqid('exec_')));
		
		if (empty($actions)) {
			wp_send_json_error(array('message' => 'No actions provided'));
			return;
		}

		$results = array();
		$all_success = true;
		$completed_count = 0;

		foreach ($actions as $index => $action) {
			if (!is_array($action) || empty($action['action'])) {
				$results[] = array(
					'index' => $index,
					'success' => false,
					'message' => 'Invalid action format',
				);
				$all_success = false;
				continue;
			}

			$action_type = sanitize_key((string) $action['action']);
			$result = $this->execute_single_action($action_type, $action);
			
			$results[] = array(
				'index' => $index,
				'action' => $action_type,
				'success' => $result['applied'] ?? false,
				'message' => $result['message'] ?? '',
				'data' => $result['data'] ?? null,
			);

			if (!($result['applied'] ?? false)) {
				$all_success = false;
			} else {
				$completed_count++;
			}
		}

		// Store execution results for status tracking
		set_transient('wudt_ai_exec_' . $execution_id, array(
			'execution_id' => $execution_id,
			'total' => count($actions),
			'completed' => $completed_count,
			'success' => $all_success,
			'results' => $results,
			'timestamp' => time(),
		), HOUR_IN_SECONDS);

		Operation_Logger::log('ai', 'AI batch execution completed', array(
			'execution_id' => $execution_id,
			'total' => count($actions),
			'completed' => $completed_count,
		));

		wp_send_json_success(array(
			'execution_id' => $execution_id,
			'total' => count($actions),
			'completed' => $completed_count,
			'all_success' => $all_success,
			'results' => $results,
			'summary' => $this->build_execution_summary($results),
		));
	}

	/**
	 * Get task status and optionally send to AI for completion message
	 */
	public function ajax_task_status(): void {
		Security_Guard::assert_ajax_admin();
		$execution_id = sanitize_key((string) wp_unslash($_POST['execution_id'] ?? ''));
		$get_completion = !empty($_POST['get_completion']);
		$original_prompt = sanitize_textarea_field((string) wp_unslash($_POST['original_prompt'] ?? ''));
		
		if (empty($execution_id)) {
			wp_send_json_error(array('message' => 'Execution ID required'));
			return;
		}

		$status = get_transient('wudt_ai_exec_' . $execution_id);
		if (empty($status)) {
			wp_send_json_error(array('message' => 'Execution not found or expired'));
			return;
		}

		// If user wants AI completion message
		if ($get_completion && !empty($original_prompt)) {
			$summary = $this->build_execution_summary($status['results'] ?? array());
			$completion_prompt = "Task execution completed.\n\n";
			$completion_prompt .= "Original request: " . $original_prompt . "\n\n";
			$completion_prompt .= "Execution Summary:\n" . $summary . "\n\n";
			$completion_prompt .= "Please provide a brief status message confirming what was done and if any issues occurred.";
			
			$context = array('execution_results' => $status);
			$history = $this->get_history(5);
			$result = $this->service->complete($completion_prompt, $context, $history, array('mode' => 'ask'));
			
			$status['ai_completion'] = $result['content'] ?? 'Task completed.';
			
			// Store completion message
			$this->push_history('assistant', $status['ai_completion'], array(
				'type' => 'task_completion',
				'execution_id' => $execution_id,
			));
		}

		wp_send_json_success($status);
	}

	/**
	 * Execute a single action
	 */
	private function execute_single_action(string $action_type, array $params): array {
		switch ($action_type) {
			case 'activate_plugin':
				return $this->action_activate_plugin($params);
			case 'install_plugin':
				return $this->action_install_plugin($params);
			case 'install_theme':
				return $this->action_install_theme($params);
			case 'activate_theme':
				return $this->action_activate_theme($params);
			case 'disable_plugin':
				return $this->action_disable_plugin($params);
			case 'run_sql':
				return $this->action_run_sql($params);
			case 'edit_file':
				return $this->action_edit_file($params);
			case 'create_file':
				return $this->action_create_file($params);
			case 'delete_file':
				return $this->action_delete_file($params);
			case 'read_file':
				return $this->action_read_file($params);
			case 'toggle_wp_debug':
				return $this->action_toggle_wp_debug($params);
			case 'schedule_cron':
				return $this->action_schedule_cron($params);
			case 'unschedule_cron':
				return $this->action_unschedule_cron($params);
			case 'search_replace_db':
				return $this->action_search_replace_db($params);
			case 'search_files':
				return $this->action_search_files($params);
			case 'chmod':
				return $this->action_chmod($params);
			case 'compress':
				return $this->action_compress($params);
			case 'extract':
				return $this->action_extract($params);
			case 'rename':
				return $this->action_rename($params);
			case 'list_directory':
				return $this->action_list_directory($params);
			case 'optimize_tables':
				return $this->action_optimize_tables($params);
			case 'repair_tables':
				return $this->action_repair_tables($params);
			case 'get_system_info':
				return $this->action_get_system_info($params);
			default:
				return array('applied' => false, 'message' => 'Unknown action: ' . $action_type);
		}
	}

	/**
	 * Build human-readable execution summary
	 */
	private function build_execution_summary(array $results): string {
		$lines = array();
		$success_count = 0;
		$fail_count = 0;

		foreach ($results as $result) {
			if ($result['success'] ?? false) {
				$success_count++;
				$lines[] = '✓ ' . ($result['action'] ?? 'action') . ': ' . ($result['message'] ?? 'Success');
			} else {
				$fail_count++;
				$lines[] = '✗ ' . ($result['action'] ?? 'action') . ': ' . ($result['message'] ?? 'Failed');
			}
		}

		$summary = "Results: {$success_count} succeeded, {$fail_count} failed\n";
		$summary .= "Details:\n" . implode("\n", $lines);
		return $summary;
	}

	public function ajax_test_api(): void {
		Security_Guard::assert_ajax_admin();

		$api_key = $this->service->api_key();
		$provider = \WUDT\Admin\Settings_Page::get_ai_provider();
		$model_setting = \WUDT\Admin\Settings_Page::get_ai_model();
		$model = sanitize_text_field((string) ($_POST['model'] ?? $model_setting));

		if ('' === $api_key) {
			wp_send_json_error(array('message' => 'API key not configured. Please set it in WP Diagnostics → Settings.'));
			return;
		}

		// Test with a simple prompt
		$test_prompt = 'Say "API connection successful" and nothing else.';

		// Build endpoint based on provider
		$is_gemini = 'gemini' === $provider;
		$is_openrouter = 'openrouter' === $provider;

		switch ($provider) {
			case 'gemini':
				$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
				$endpoint = add_query_arg('key', $api_key, $endpoint);
				break;
			case 'anthropic':
				$endpoint = 'https://api.anthropic.com/v1/messages';
				break;
			case 'openrouter':
				$endpoint = 'https://openrouter.ai/api/v1/chat/completions';
				break;
			case 'openai':
			default:
				$endpoint = 'https://api.openai.com/v1/chat/completions';
				break;
		}

		// Build request body based on provider format
		if ($is_gemini) {
			$request_body = array(
				'contents' => array(
					array(
						'parts' => array(
							array('text' => $test_prompt),
						),
					),
				),
				'generationConfig' => array(
					'temperature' => 0.1,
					'maxOutputTokens' => 50,
				),
			);
		} else {
			$request_body = array(
				'model' => $model,
				'messages' => array(
					array('role' => 'user', 'content' => $test_prompt),
				),
				'temperature' => 0.1,
				'max_tokens' => 50,
			);
		}

		// Build headers array
		$headers = array(
			'Content-Type: application/json',
		);
		if (! $is_gemini) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
		}
		if ($is_openrouter) {
			$headers[] = 'HTTP-Referer: ' . get_site_url();
			$headers[] = 'X-Title: WP Ultimate Diagnostics Toolkit';
		}

		$body_json = wp_json_encode($request_body);

		// Try cURL first (more reliable for Authorization headers)
		if (function_exists('curl_init')) {
			$ch = curl_init($endpoint);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

			$body = curl_exec($ch);
			$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_error = curl_error($ch);
			curl_close($ch);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[WUDT AI Test] Using cURL - Status: ' . $status_code);
				if ($curl_error) {
					error_log('[WUDT AI Test] cURL Error: ' . $curl_error);
				}
			}

			if ($body === false || $curl_error) {
				wp_send_json_error(array(
					'provider' => $provider,
					'model_setting' => $model_setting,
					'model_used' => $model,
					'error' => 'cURL Error: ' . ($curl_error ?: 'Unknown error'),
					'method' => 'curl',
				));
				return;
			}
		} else {
			// Fallback to wp_remote_post
			$request_args = array(
				'timeout' => 30,
				'headers' => array('Content-Type' => 'application/json'),
				'body' => $body_json,
				'sslverify' => false,
			);

			if (! $is_gemini) {
				$request_args['headers']['Authorization'] = 'Bearer ' . $api_key;
			}
			if ($is_openrouter) {
				$request_args['headers']['HTTP-Referer'] = get_site_url();
				$request_args['headers']['X-Title'] = 'WP Ultimate Diagnostics Toolkit';
			}

			$auth_filter = function($args, $url) use ($request_args) {
				if (isset($request_args['headers']['Authorization'])) {
					$args['headers']['Authorization'] = $request_args['headers']['Authorization'];
				}
				if (isset($request_args['sslverify'])) {
					$args['sslverify'] = $request_args['sslverify'];
				}
				return $args;
			};
			add_filter('http_request_args', $auth_filter, 10, 2);

			$response = wp_remote_post($endpoint, $request_args);
			remove_filter('http_request_args', $auth_filter, 10);

			if (is_wp_error($response)) {
				wp_send_json_error(array(
					'provider' => $provider,
					'model_setting' => $model_setting,
					'model_used' => $model,
					'error' => $response->get_error_message(),
					'error_code' => $response->get_error_code(),
					'method' => 'wp_remote_post',
				));
				return;
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
		}

		$data = json_decode((string) $body, true);

		$result = array(
			'provider' => $provider,
			'model_setting' => $model_setting,
			'model_used' => $model,
			'endpoint' => str_replace($api_key, '***', $endpoint),
			'status_code' => $status_code,
			'raw_response' => $data,
		);

		if ($status_code >= 200 && $status_code < 300) {
			$response_text = '';
			if ($is_gemini && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
				$response_text = $data['candidates'][0]['content']['parts'][0]['text'];
			} elseif (isset($data['choices'][0]['message']['content'])) {
				$response_text = $data['choices'][0]['message']['content'];
			}

			if (! empty($response_text)) {
				$result['success'] = true;
				$result['response_text'] = $response_text;
				wp_send_json_success($result);
			} else {
				$result['success'] = false;
				$result['error'] = 'No content in response';
				wp_send_json_error($result);
			}
		} else {
			$result['success'] = false;
			$result['error'] = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $status_code;
			wp_send_json_error($result);
		}
	}

	/**
	 * Schedule cron action - schedule a WordPress cron event
	 */
	private function action_schedule_cron(array $params): array {
		if (empty($params['hook']) || empty($params['timestamp'])) {
			return array('applied' => false, 'message' => 'Hook name and timestamp required');
		}
		
		$hook = sanitize_key((string) $params['hook']);
		$timestamp = is_numeric($params['timestamp']) ? (int) $params['timestamp'] : strtotime((string) $params['timestamp']);
		$args = isset($params['args']) ? (array) $params['args'] : array();
		$recurring = !empty($params['recurring']);
		$interval = sanitize_key((string) ($params['interval'] ?? 'hourly'));
		
		if (!$timestamp || $timestamp <= time()) {
			return array('applied' => false, 'message' => 'Timestamp must be in the future');
		}
		
		if ($recurring) {
			// Schedule recurring event
			if (!wp_next_scheduled($hook, $args)) {
				$result = wp_schedule_event($timestamp, $interval, $hook, $args);
				if ($result !== false) {
					return array('applied' => true, 'message' => "Recurring cron scheduled: {$hook} every {$interval} starting " . wp_date('Y-m-d H:i:s', $timestamp));
				}
				return array('applied' => false, 'message' => 'Failed to schedule recurring cron');
			}
			return array('applied' => false, 'message' => 'Recurring cron already exists: ' . $hook);
		}
		
		// Schedule single event
		$result = wp_schedule_single_event($timestamp, $hook, $args);
		if ($result !== false) {
			return array('applied' => true, 'message' => 'Cron scheduled: ' . $hook . ' at ' . wp_date('Y-m-d H:i:s', $timestamp));
		}
		return array('applied' => false, 'message' => 'Failed to schedule cron event');
	}

	/**
	 * Unschedule cron action - remove a scheduled cron event
	 */
	private function action_unschedule_cron(array $params): array {
		if (empty($params['hook'])) {
			return array('applied' => false, 'message' => 'Hook name required');
		}
		
		$hook = sanitize_key((string) $params['hook']);
		$args = isset($params['args']) ? (array) $params['args'] : array();
		
		$next_run = wp_next_scheduled($hook, $args);
		if (!$next_run) {
			return array('applied' => false, 'message' => 'No scheduled event found for: ' . $hook);
		}
		
		$result = wp_unschedule_event($next_run, $hook, $args);
		if ($result !== false) {
			return array('applied' => true, 'message' => 'Unscheduled: ' . $hook);
		}
		return array('applied' => false, 'message' => 'Failed to unschedule: ' . $hook);
	}

	/**
	 * Search and replace in database
	 */
	private function action_search_replace_db(array $params): array {
		if (empty($params['search'])) {
			return array('applied' => false, 'message' => 'Search term required');
		}
		
		$search = (string) $params['search'];
		$replace = isset($params['replace']) ? (string) $params['replace'] : '';
		$dry_run = !empty($params['dry_run']);
		$tables = isset($params['tables']) ? (array) $params['tables'] : array();
		
		global $wpdb;
		
		// If no tables specified, get all tables
		if (empty($tables)) {
			$tables = $wpdb->get_col("SHOW TABLES");
		}
		
		$results = array();
		$total_replacements = 0;
		
		foreach ($tables as $table) {
			$table = sanitize_text_field($table);
			$columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
			$table_replacements = 0;
			
			foreach ($columns as $column) {
				if (strpos($column['Type'], 'char') !== false || strpos($column['Type'], 'text') !== false) {
					$column_name = $column['Field'];
					
					if ($dry_run) {
						// Count matches
						$count = $wpdb->get_var($wpdb->prepare(
							"SELECT COUNT(*) FROM `{$table}` WHERE `{$column_name}` LIKE %s",
							'%' . $wpdb->esc_like($search) . '%'
						));
						$table_replacements += (int) $count;
					} else {
						// Perform replacement
						$updated = $wpdb->query($wpdb->prepare(
							"UPDATE `{$table}` SET `{$column_name}` = REPLACE(`{$column_name}`, %s, %s) WHERE `{$column_name}` LIKE %s",
							$search,
							$replace,
							'%' . $wpdb->esc_like($search) . '%'
						));
						$table_replacements += (int) $updated;
					}
				}
			}
			
			if ($table_replacements > 0) {
				$results[$table] = $table_replacements;
				$total_replacements += $table_replacements;
			}
		}
		
		$mode = $dry_run ? 'Found' : 'Replaced';
		return array(
			'applied' => $total_replacements > 0,
			'message' => "{$mode} {$total_replacements} occurrences across " . count($results) . " tables",
			'data' => array('tables' => $results, 'total' => $total_replacements, 'dry_run' => $dry_run)
		);
	}

	/**
	 * Search in files
	 */
	private function action_search_files(array $params): array {
		if (empty($params['query'])) {
			return array('applied' => false, 'message' => 'Search query required');
		}
		
		$query = sanitize_text_field((string) $params['query']);
		$path = isset($params['path']) ? sanitize_text_field((string) $params['path']) : ABSPATH;
		$extension = isset($params['extension']) ? sanitize_text_field((string) $params['extension']) : '';
		$limit = isset($params['limit']) ? (int) $params['limit'] : 50;
		
		if (!is_dir($path)) {
			return array('applied' => false, 'message' => 'Invalid path: ' . $path);
		}
		
		$hits = array();
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS));
		
		foreach ($iterator as $file) {
			if (count($hits) >= $limit) break;
			
			if (!$file->isFile()) continue;
			if ($file->getSize() > 5 * 1024 * 1024) continue; // Skip files > 5MB
			
			$file_path = $file->getPathname();
			
			// Check extension filter
			if ($extension && !str_ends_with(strtolower($file_path), strtolower($extension))) {
				continue;
			}
			
			// Search in filename
			if (stripos(basename($file_path), $query) !== false) {
				$hits[] = array('path' => $file_path, 'type' => 'filename');
				continue;
			}
			
			// Search in content
			$content = @file_get_contents($file_path);
			if ($content !== false && stripos($content, $query) !== false) {
				$line = 1;
				$lines = explode("\n", $content);
				foreach ($lines as $i => $l) {
					if (stripos($l, $query) !== false) {
						$line = $i + 1;
						break;
					}
				}
				$hits[] = array('path' => $file_path, 'type' => 'content', 'line' => $line);
			}
		}
		
		return array(
			'applied' => true,
			'message' => 'Found ' . count($hits) . ' matches for: ' . $query,
			'data' => array('hits' => $hits, 'total' => count($hits))
		);
	}

	/**
	 * Change file permissions (chmod)
	 */
	private function action_chmod(array $params): array {
		if (empty($params['path']) || empty($params['mode'])) {
			return array('applied' => false, 'message' => 'Path and mode (permissions) required');
		}
		
		$path = sanitize_text_field((string) $params['path']);
		$mode = is_numeric($params['mode']) ? octdec((int) $params['mode']) : octdec(644);
		$recursive = !empty($params['recursive']);
		
		$fullpath = realpath($path);
		if (!$fullpath || strpos($fullpath, realpath(ABSPATH)) !== 0) {
			return array('applied' => false, 'message' => 'Invalid path');
		}
		
		if (!file_exists($fullpath)) {
			return array('applied' => false, 'message' => 'File not found: ' . $path);
		}
		
		$changed = 0;
		
		if ($recursive && is_dir($fullpath)) {
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullpath));
			foreach ($iterator as $file) {
				if (@chmod($file->getPathname(), $mode)) {
					$changed++;
				}
			}
		} else {
			if (@chmod($fullpath, $mode)) {
				$changed = 1;
			}
		}
		
		return array(
			'applied' => $changed > 0,
			'message' => "Changed permissions on {$changed} item(s) to " . sprintf('%03o', $mode),
			'data' => array('changed' => $changed, 'mode' => sprintf('%03o', $mode))
		);
	}

	/**
	 * Compress files/directories to zip
	 */
	private function action_compress(array $params): array {
		if (empty($params['paths']) || empty($params['destination'])) {
			return array('applied' => false, 'message' => 'Paths and destination required');
		}
		
		$paths = (array) $params['paths'];
		$destination = sanitize_text_field((string) $params['destination']);
		
		if (!class_exists('ZipArchive')) {
			return array('applied' => false, 'message' => 'ZipArchive extension not available');
		}
		
		$zip = new \ZipArchive();
		if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return array('applied' => false, 'message' => 'Cannot create zip file');
		}
		
		$added = 0;
		foreach ($paths as $path) {
			$fullpath = realpath($path);
			if (!$fullpath) continue;
			
			if (is_dir($fullpath)) {
				$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullpath));
				foreach ($iterator as $file) {
					if ($file->isDir()) continue;
					$zip->addFile($file->getPathname(), str_replace(ABSPATH, '', $file->getPathname()));
					$added++;
				}
			} else if (is_file($fullpath)) {
				$zip->addFile($fullpath, basename($fullpath));
				$added++;
			}
		}
		
		$zip->close();
		
		return array(
			'applied' => true,
			'message' => "Created archive with {$added} file(s): " . $destination,
			'data' => array('archive' => $destination, 'files' => $added)
		);
	}

	/**
	 * Extract zip archive
	 */
	private function action_extract(array $params): array {
		if (empty($params['archive']) || empty($params['destination'])) {
			return array('applied' => false, 'message' => 'Archive path and destination required');
		}
		
		$archive = sanitize_text_field((string) $params['archive']);
		$destination = sanitize_text_field((string) $params['destination']);
		
		if (!class_exists('ZipArchive')) {
			return array('applied' => false, 'message' => 'ZipArchive extension not available');
		}
		
		$full_archive = realpath($archive);
		if (!$full_archive || !file_exists($full_archive)) {
			return array('applied' => false, 'message' => 'Archive not found: ' . $archive);
		}
		
		if (!is_dir($destination)) {
			wp_mkdir_p($destination);
		}
		
		$zip = new \ZipArchive();
		if ($zip->open($full_archive) !== true) {
			return array('applied' => false, 'message' => 'Cannot open archive');
		}
		
		$extracted = $zip->extractTo($destination);
		$count = $zip->numFiles;
		$zip->close();
		
		if ($extracted) {
			return array(
				'applied' => true,
				'message' => "Extracted {$count} file(s) to: " . $destination,
				'data' => array('destination' => $destination, 'files' => $count)
			);
		}
		return array('applied' => false, 'message' => 'Extraction failed');
	}

	/**
	 * Rename/move file or directory
	 */
	private function action_rename(array $params): array {
		if (empty($params['old_path']) || empty($params['new_path'])) {
			return array('applied' => false, 'message' => 'Old path and new path required');
		}
		
		$old_path = sanitize_text_field((string) $params['old_path']);
		$new_path = sanitize_text_field((string) $params['new_path']);
		
		$old_full = realpath($old_path);
		if (!$old_full || strpos($old_full, realpath(ABSPATH)) !== 0) {
			return array('applied' => false, 'message' => 'Invalid source path');
		}
		
		if (file_exists($new_path)) {
			return array('applied' => false, 'message' => 'Destination already exists: ' . $new_path);
		}
		
		if (@rename($old_full, $new_path)) {
			return array(
				'applied' => true,
				'message' => 'Renamed: ' . basename($old_path) . ' → ' . basename($new_path),
				'data' => array('from' => $old_path, 'to' => $new_path)
			);
		}
		return array('applied' => false, 'message' => 'Rename failed - check permissions');
	}

	/**
	 * List directory contents
	 */
	private function action_list_directory(array $params): array {
		$path = isset($params['path']) ? sanitize_text_field((string) $params['path']) : ABSPATH;
		$fullpath = realpath($path);
		
		if (!$fullpath || strpos($fullpath, realpath(ABSPATH)) !== 0) {
			return array('applied' => false, 'message' => 'Invalid path');
		}
		
		if (!is_dir($fullpath)) {
			return array('applied' => false, 'message' => 'Not a directory: ' . $path);
		}
		
		$items = array();
		$dirs = array();
		$files = array();
		
		foreach (new \DirectoryIterator($fullpath) as $item) {
			if ($item->isDot()) continue;
			
			$info = array(
				'name' => $item->getFilename(),
				'size' => $item->getSize(),
				'modified' => $item->getMTime(),
				'permissions' => substr(sprintf('%o', $item->getPerms()), -4),
			);
			
			if ($item->isDir()) {
				$dirs[] = $info;
			} else {
				$files[] = $info;
			}
		}
		
		usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
		usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
		
		return array(
			'applied' => true,
			'message' => 'Directory listing: ' . $path,
			'data' => array(
				'path' => $path,
				'directories' => $dirs,
				'files' => $files,
				'total_dirs' => count($dirs),
				'total_files' => count($files)
			)
		);
	}

	/**
	 * Optimize database tables
	 */
	private function action_optimize_tables(array $params): array {
		global $wpdb;
		
		$tables = isset($params['tables']) ? (array) $params['tables'] : array();
		
		// If no tables specified, optimize all
		if (empty($tables)) {
			$tables = $wpdb->get_col("SHOW TABLES");
		}
		
		$results = array();
		foreach ($tables as $table) {
			$table = sanitize_text_field($table);
			$result = $wpdb->get_results("OPTIMIZE TABLE `{$table}`", ARRAY_A);
			$results[$table] = $result[0]['Msg_text'] ?? 'Unknown';
		}
		
		return array(
			'applied' => true,
			'message' => 'Optimized ' . count($results) . ' table(s)',
			'data' => array('results' => $results)
		);
	}

	/**
	 * Repair database tables
	 */
	private function action_repair_tables(array $params): array {
		global $wpdb;
		
		$tables = isset($params['tables']) ? (array) $params['tables'] : array();
		
		// If no tables specified, repair all
		if (empty($tables)) {
			$tables = $wpdb->get_col("SHOW TABLES");
		}
		
		$results = array();
		foreach ($tables as $table) {
			$table = sanitize_text_field($table);
			$result = $wpdb->get_results("REPAIR TABLE `{$table}`", ARRAY_A);
			$results[$table] = $result[0]['Msg_text'] ?? 'Unknown';
		}
		
		return array(
			'applied' => true,
			'message' => 'Repaired ' . count($results) . ' table(s)',
			'data' => array('results' => $results)
		);
	}

	/**
	 * Get system information
	 */
	private function action_get_system_info(array $params): array {
		$info = array(
			'wordpress_version' => get_bloginfo('version'),
			'php_version' => phpversion(),
			'mysql_version' => $GLOBALS['wpdb']->db_version(),
			'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
			'os' => php_uname('s') . ' ' . php_uname('r'),
			'memory_limit' => ini_get('memory_limit'),
			'max_execution_time' => ini_get('max_execution_time'),
			'upload_max_filesize' => ini_get('upload_max_filesize'),
			'disk_free_space' => function_exists('disk_free_space') ? disk_free_space(ABSPATH) : null,
			'active_plugins' => get_option('active_plugins', array()),
			'active_theme' => wp_get_theme()->get('Name'),
			'theme_version' => wp_get_theme()->get('Version'),
			'locale' => get_locale(),
			'timezone' => wp_timezone_string(),
		);
		
		return array(
			'applied' => true,
			'message' => 'System information retrieved',
			'data' => $info
		);
	}
}
