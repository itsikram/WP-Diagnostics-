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
		if ('agent' !== $mode) {
			$parsed['action'] = array();
		}
		$this->push_history('user', $prompt, $context);
		$this->push_history('assistant', (string) ($parsed['text'] ?? ''), array('model' => $result['model'] ?? 'unknown', 'mode' => $mode));
		Operation_Logger::log('ai', 'AI diagnosis generated', array('prompt_len' => strlen($prompt)));
		$response = array(
			'message' => $parsed['text'] ?? '',
			'action'  => $parsed['action'] ?? array(),
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
		
		$plugin_slug = sanitize_key((string) ($params['plugin_slug'] ?? $params['plugin']));
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
		$model_setting = 'gemini-2.5-flash';//\WUDT\Admin\Settings_Page::get_ai_model();
		$model =  "gemini-2.5-flash"; //sanitize_text_field((string) ($_POST['model'] ?? ''));
		
		if ('' === $api_key) {
			wp_send_json_error(array('message' => 'API key not configured'));
			return;
		}
		
		// Test with a simple prompt
		$test_prompt = 'Say "API connection successful" and nothing else.';
		
		// Build endpoint manually for testing
		$endpoint = '';
		if ($model_setting === 'gemini' || $model_setting === 'gemini-2.5-flash') {
			$model_to_use = $model ?: 'gemini-2.5-flash';
			$endpoint = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=AIzaSyD2d7kTyxbr2IV8Q0mml5DhqHqBcyBphyM';
			// $endpoint = 'https://generativelanguage.googleapis.com/v1/models/' . $model_to_use . ':generateContent?key=' . $api_key;
		} else {
			wp_send_json_error(array('message' => 'Test only supports Gemini currently'));
			return;
		}
		
		// Make direct test request
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
		
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array('Content-Type' => 'application/json'),
				'body' => wp_json_encode($request_body),
			)
		);
		
		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string) $body, true);
		
		$result = array(
			'model_setting' =>   $model_setting,
			'model_used' => $model ?: 'gemini-2.5-flash',
			'endpoint' => str_replace($api_key, '***', $endpoint),
			'status_code' => $status_code,
			'raw_response' => $data,
		);
		
		if ($status_code >= 200 && $status_code < 300) {
			if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
				$result['success'] = true;
				$result['response_text'] = $data['candidates'][0]['content']['parts'][0]['text'];
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
}
