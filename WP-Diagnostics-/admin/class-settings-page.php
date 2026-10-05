<?php
/**
 * Plugin settings page for AI model configuration and other options.
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WUDT\Includes\AI_Config;

if (! defined('ABSPATH')) {
	exit;
}

class Settings_Page {
	private const OPTION_AI_PROVIDER = 'wudt_ai_provider';
	private const OPTION_AI_MODEL = 'wudt_ai_model';
	private const OPTION_AI_API_KEY = 'wudt_ai_api_key';
	private const OPTION_AI_TEMPERATURE = 'wudt_ai_temperature';
	private const OPTION_AI_MAX_TOKENS = 'wudt_ai_max_tokens';
	private const OPTION_AI_ALLOW_FILE_OPS = 'wudt_ai_allow_file_ops';

	// WordPress Debug Settings
	private const OPTION_WP_DEBUG = 'wudt_wp_debug_enabled';
	private const OPTION_WP_DEBUG_LOG = 'wudt_wp_debug_log_enabled';
	private const OPTION_WP_DEBUG_DISPLAY = 'wudt_wp_debug_display_enabled';
	private const OPTION_WP_CACHE = 'wudt_wp_cache_enabled';

	// PHP Settings
	private const OPTION_MEMORY_LIMIT = 'wudt_memory_limit';
	private const OPTION_MAX_UPLOAD_SIZE = 'wudt_max_upload_size';
	private const OPTION_MAX_POST_SIZE = 'wudt_max_post_size';
	private const OPTION_MAX_EXECUTION_TIME = 'wudt_max_execution_time';

	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_post_wudt_save_settings', array($this, 'handle_save_settings'));
		add_action('admin_post_wudt_create_admin_user', array($this, 'handle_create_admin_user'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('init', array($this, 'apply_runtime_settings'), 1);
		add_action('wp_ajax_wudt_save_settings_async', array($this, 'ajax_save_settings'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Settings', 'diagnostics-toolkit'),
			__('Settings', 'diagnostics-toolkit'),
			'manage_options',
			'wudt-settings',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('wp-diagnostics_page_wudt-settings' !== $hook) {
			return;
		}

		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);
		wp_enqueue_script('wudt-settings', WUDT_PLUGIN_URL . 'assets/js/settings.js', array('jquery'), WUDT_VERSION, true);
		wp_localize_script('wudt-settings', 'wudtSettings', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wudt_save_settings_async'),
		));
	}

	public function handle_save_settings(): void {
		$this->authorize_action('wudt_save_settings');

		$this->save_ai_fields();

		// WordPress Debug Settings - Also update wp-config.php for 100% functionality
		$wp_debug = isset($_POST['wp_debug']) && '1' === (string) wp_unslash($_POST['wp_debug']);
		update_option(self::OPTION_WP_DEBUG, $wp_debug, false);

		$wp_debug_log = isset($_POST['wp_debug_log']) && '1' === (string) wp_unslash($_POST['wp_debug_log']);
		update_option(self::OPTION_WP_DEBUG_LOG, $wp_debug_log, false);

		$wp_debug_display = isset($_POST['wp_debug_display']) && '1' === (string) wp_unslash($_POST['wp_debug_display']);
		update_option(self::OPTION_WP_DEBUG_DISPLAY, $wp_debug_display, false);

		$wp_cache = isset($_POST['wp_cache']) && '1' === (string) wp_unslash($_POST['wp_cache']);
		update_option(self::OPTION_WP_CACHE, $wp_cache, false);

		// Update wp-config.php with WordPress debug constants
		$wp_config_result = $this->update_wp_config_debug_settings(
			$wp_debug,
			$wp_debug_log,
			$wp_debug_display,
			$wp_cache
		);

		// PHP Settings
		$memory_limit = isset($_POST['memory_limit']) ? sanitize_text_field((string) wp_unslash($_POST['memory_limit'])) : '256M';
		update_option(self::OPTION_MEMORY_LIMIT, $memory_limit, false);

		$max_upload_size = isset($_POST['max_upload_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_upload_size'])) : '64M';
		update_option(self::OPTION_MAX_UPLOAD_SIZE, $max_upload_size, false);

		$max_post_size = isset($_POST['max_post_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_post_size'])) : '64M';
		update_option(self::OPTION_MAX_POST_SIZE, $max_post_size, false);

		$max_execution_time = isset($_POST['max_execution_time']) ? (int) wp_unslash($_POST['max_execution_time']) : 300;
		update_option(self::OPTION_MAX_EXECUTION_TIME, max(30, min(600, $max_execution_time)), false);

		// Redirect with success message
		$redirect_url = admin_url('admin.php?page=wudt-settings&saved=1');
		if (is_wp_error($wp_config_result)) {
			$redirect_url = admin_url('admin.php?page=wudt-settings&saved=1&wp_config_error=' . urlencode($wp_config_result->get_error_message()));
		}
		wp_safe_redirect($redirect_url);
		exit;
	}

	/**
	 * Update wp-config.php with WordPress debug constants.
	 * Creates a backup before modifying.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure
	 */
	private function update_wp_config_debug_settings(bool $wp_debug, bool $wp_debug_log, bool $wp_debug_display, bool $wp_cache) {
		$config_file = ABSPATH . 'wp-config.php';
		
		// Check if wp-config.php exists and is writable
		if (! file_exists($config_file)) {
			$config_file = dirname(ABSPATH) . '/wp-config.php';
			if (! file_exists($config_file)) {
				return new \WP_Error('config_not_found', __('wp-config.php not found.', 'diagnostics-toolkit'));
			}
		}
		
		if (! is_readable($config_file)) {
			return new \WP_Error('config_not_readable', __('wp-config.php is not readable.', 'diagnostics-toolkit'));
		}
		
		if (! is_writable($config_file)) {
			return new \WP_Error('config_not_writable', __('wp-config.php is not writable. Please check file permissions.', 'diagnostics-toolkit'));
		}
		
		// Read current config
		$config_content = file_get_contents($config_file);
		if (false === $config_content) {
			return new \WP_Error('config_read_failed', __('Failed to read wp-config.php.', 'diagnostics-toolkit'));
		}
		
		// Create backup
		$backup_file = $config_file . '.backup-' . date('Y-m-d-H-i-s');
		if (false === file_put_contents($backup_file, $config_content)) {
			return new \WP_Error('backup_failed', __('Failed to create wp-config.php backup.', 'diagnostics-toolkit'));
		}
		
		// Update or add WP_DEBUG
		$config_content = $this->update_wp_config_constant($config_content, 'WP_DEBUG', $wp_debug ? 'true' : 'false');
		
		// Update or add WP_DEBUG_LOG
		$config_content = $this->update_wp_config_constant($config_content, 'WP_DEBUG_LOG', $wp_debug_log ? 'true' : 'false');
		
		// Update or add WP_DEBUG_DISPLAY
		$config_content = $this->update_wp_config_constant($config_content, 'WP_DEBUG_DISPLAY', $wp_debug_display ? 'true' : 'false');
		
		// Update or add WP_CACHE
		$config_content = $this->update_wp_config_constant($config_content, 'WP_CACHE', $wp_cache ? 'true' : 'false');
		
		// Write updated config
		$write_result = file_put_contents($config_file, $config_content);
		if (false === $write_result) {
			// Restore backup on failure
			$backup_content = file_get_contents($backup_file);
			if (false !== $backup_content) {
				file_put_contents($config_file, $backup_content);
			}
			return new \WP_Error('config_write_failed', __('Failed to write wp-config.php. Check file permissions.', 'diagnostics-toolkit'));
		}
		
		// Verify the write was successful by re-reading the file
		$verify_content = file_get_contents($config_file);
		if (false === $verify_content) {
			return new \WP_Error('config_verify_failed', __('Could not verify wp-config.php changes.', 'diagnostics-toolkit'));
		}
		
		// Check if the constants were actually written
		$debug_pattern = "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*(true|false)\s*\)\s*;/i";
		if (! preg_match($debug_pattern, $verify_content, $matches)) {
			return new \WP_Error('config_constant_missing', __('WP_DEBUG constant not found in wp-config.php after update.', 'diagnostics-toolkit'));
		}
		
		// Verify the value matches what we intended
		$expected_value = $wp_debug ? 'true' : 'false';
		if (strtolower($matches[1]) !== $expected_value) {
			return new \WP_Error('config_value_mismatch', sprintf(
				__('WP_DEBUG value mismatch: expected %s but found %s.', 'diagnostics-toolkit'),
				$expected_value,
				$matches[1]
			));
		}
		
		return true;
	}
	
	/**
	 * Update or add a constant in wp-config.php content
	 */
	private function update_wp_config_constant(string $content, string $constant, string $value): string {
		// Pattern to match the constant definition
		$pattern = "/define\s*\(\s*['\"]" . preg_quote($constant, '/') . "['\"]\s*,\s*(true|false|'[^']*'|\"[^\"]*\"|\d+)\s*\)\s*;/i";
		
		// New constant line
		$new_line = "define( '{$constant}', {$value} );";
		
		if (preg_match($pattern, $content)) {
			// Update existing constant
			$content = preg_replace($pattern, $new_line, $content);
		} else {
			// Add new constant before "That's all, stop editing!"
			$stop_pattern = "/(\/\*\s*That's all,\s*stop editing!)/i";
			if (preg_match($stop_pattern, $content)) {
				$content = preg_replace($stop_pattern, $new_line . "\n\n$1", $content);
			} else {
				// Add at the end if stop editing line not found
				$content .= "\n" . $new_line . "\n";
			}
		}
		
		return $content;
	}
	
	/**
	 * Get current WordPress constants from wp-config.php
	 */
	private function get_wp_config_constants(): array {
		$config_file = ABSPATH . 'wp-config.php';
		
		if (! file_exists($config_file)) {
			$config_file = dirname(ABSPATH) . '/wp-config.php';
		}
		
		if (! file_exists($config_file) || ! is_readable($config_file)) {
			return array();
		}
		
		$content = file_get_contents($config_file);
		if (false === $content) {
			return array();
		}
		
		$constants = array();
		$constants_to_check = array('WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'WP_CACHE');
		
		foreach ($constants_to_check as $constant) {
			$pattern = "/define\s*\(\s*['\"]" . preg_quote($constant, '/') . "['\"]\s*,\s*(true|false)\s*\)\s*;/i";
			if (preg_match($pattern, $content, $matches)) {
				$constants[$constant] = strtolower($matches[1]) === 'true';
			}
		}
		
		return $constants;
	}

	/**
	 * Handle admin user creation with example credentials
	 */
	public function handle_create_admin_user(): void {
		$this->authorize_action('wudt_create_admin_user');

		$username = isset($_POST['admin_username']) ? sanitize_user((string) wp_unslash($_POST['admin_username'])) : '';
		$email = isset($_POST['admin_email']) ? sanitize_email((string) wp_unslash($_POST['admin_email'])) : '';
		$password = isset($_POST['admin_password']) ? (string) wp_unslash($_POST['admin_password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered; passed to wp_insert_user().
		$role = isset($_POST['admin_role']) ? sanitize_text_field((string) wp_unslash($_POST['admin_role'])) : 'administrator';

		// Validation
		if (empty($username) || empty($email) || empty($password)) {
			wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_error=1&message=' . urlencode(__('All fields are required.', 'diagnostics-toolkit'))));
			exit;
		}

		if (! is_email($email)) {
			wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_error=1&message=' . urlencode(__('Invalid email address.', 'diagnostics-toolkit'))));
			exit;
		}

		if (username_exists($username)) {
			wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_error=1&message=' . urlencode(__('Username already exists.', 'diagnostics-toolkit'))));
			exit;
		}

		if (email_exists($email)) {
			wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_error=1&message=' . urlencode(__('Email already exists.', 'diagnostics-toolkit'))));
			exit;
		}

		// Validate role
		$valid_roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
		if (! in_array($role, $valid_roles, true)) {
			$role = 'administrator';
		}

		// Create user
		$user_id = wp_create_user($username, $password, $email);

		if (is_wp_error($user_id)) {
			wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_error=1&message=' . urlencode($user_id->get_error_message())));
			exit;
		}

		// Set role
		$user = new \WP_User($user_id);
		$user->set_role($role);

		// Send notification
		wp_new_user_notification($user_id, null, 'user');

		// Redirect with success
		wp_safe_redirect(admin_url('admin.php?page=wudt-settings&user_created=1&username=' . urlencode($username) . '&email=' . urlencode($email)));
		exit;
	}

	/**
	 * AJAX handler for async settings save
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer('wudt_save_settings_async', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'diagnostics-toolkit')));
			return;
		}

		$this->save_ai_fields();

		// WordPress Debug Settings
		$wp_debug = isset($_POST['wp_debug']) && '1' === (string) wp_unslash($_POST['wp_debug']);
		update_option(self::OPTION_WP_DEBUG, $wp_debug, false);

		$wp_debug_log = isset($_POST['wp_debug_log']) && '1' === (string) wp_unslash($_POST['wp_debug_log']);
		update_option(self::OPTION_WP_DEBUG_LOG, $wp_debug_log, false);

		$wp_debug_display = isset($_POST['wp_debug_display']) && '1' === (string) wp_unslash($_POST['wp_debug_display']);
		update_option(self::OPTION_WP_DEBUG_DISPLAY, $wp_debug_display, false);

		$wp_cache = isset($_POST['wp_cache']) && '1' === (string) wp_unslash($_POST['wp_cache']);
		update_option(self::OPTION_WP_CACHE, $wp_cache, false);

		// Update wp-config.php with WordPress debug constants
		$wp_config_result = $this->update_wp_config_debug_settings(
			$wp_debug,
			$wp_debug_log,
			$wp_debug_display,
			$wp_cache
		);

		// PHP Settings
		$memory_limit = isset($_POST['memory_limit']) ? sanitize_text_field((string) wp_unslash($_POST['memory_limit'])) : '256M';
		update_option(self::OPTION_MEMORY_LIMIT, $memory_limit, false);

		$max_upload_size = isset($_POST['max_upload_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_upload_size'])) : '64M';
		update_option(self::OPTION_MAX_UPLOAD_SIZE, $max_upload_size, false);

		$max_post_size = isset($_POST['max_post_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_post_size'])) : '64M';
		update_option(self::OPTION_MAX_POST_SIZE, $max_post_size, false);

		$max_execution_time = isset($_POST['max_execution_time']) ? (int) wp_unslash($_POST['max_execution_time']) : 300;
		update_option(self::OPTION_MAX_EXECUTION_TIME, max(30, min(600, $max_execution_time)), false);

		$response = array(
			'success' => true,
			'message' => __('Settings saved successfully.', 'diagnostics-toolkit'),
		);
		
		if (is_wp_error($wp_config_result)) {
			$response['wp_config_error'] = $wp_config_result->get_error_message();
		}
		
		wp_send_json_success($response);
	}

	/**
	 * Apply runtime settings that can be changed without wp-config.php
	 */
	public function apply_runtime_settings(): void {
		// Check if options table exists before querying (during restore it may not exist)
		global $wpdb;
		if (!isset($wpdb) || !$wpdb->ready) {
			return;
		}

		// Suppress database errors during check to prevent race condition output
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			return;
		}

		// WP_DEBUG itself is written to wp-config.php by the settings form; these only
		// apply what the administrator explicitly switched on in Diagnostics Toolkit.
		// phpcs:disable WordPress.PHP.IniSet.Risky, WordPress.PHP.IniSet.display_errors_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- opt-in debugging settings of a debugging plugin.
		if (get_option(self::OPTION_WP_DEBUG_LOG, false)) {
			ini_set('log_errors', '1');
			ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
		}

		if (get_option(self::OPTION_WP_DEBUG_DISPLAY, false)) {
			ini_set('display_errors', '1');
		}

		$memory_limit = (string) get_option(self::OPTION_MEMORY_LIMIT, '');
		if ('' !== $memory_limit && wp_convert_hr_to_bytes($memory_limit) > wp_convert_hr_to_bytes((string) ini_get('memory_limit'))) {
			ini_set('memory_limit', $memory_limit);
		}

		$max_execution_time = (int) get_option(self::OPTION_MAX_EXECUTION_TIME, 0);
		if ($max_execution_time > 0) {
			set_time_limit($max_execution_time);
		}
		// phpcs:enable
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'diagnostics-toolkit'));
		}

		$ai_provider = get_option(self::OPTION_AI_PROVIDER, 'gemini');
		$ai_model = get_option(self::OPTION_AI_MODEL, 'gemini-2.5-flash');
		$ai_api_key = get_option(self::OPTION_AI_API_KEY, '');
		$ai_temperature = (float) get_option(self::OPTION_AI_TEMPERATURE, 0.7);
		$ai_max_tokens = (int) get_option(self::OPTION_AI_MAX_TOKENS, 2000);
		$ai_allow_file_ops = (bool) get_option(self::OPTION_AI_ALLOW_FILE_OPS, false);

		// WordPress Debug Settings - Read from wp-config.php for 100% accuracy
		$wp_config_constants = $this->get_wp_config_constants();
		$wp_debug = $wp_config_constants['WP_DEBUG'] ?? (bool) get_option(self::OPTION_WP_DEBUG, false);
		$wp_debug_log = $wp_config_constants['WP_DEBUG_LOG'] ?? (bool) get_option(self::OPTION_WP_DEBUG_LOG, false);
		$wp_debug_display = $wp_config_constants['WP_DEBUG_DISPLAY'] ?? (bool) get_option(self::OPTION_WP_DEBUG_DISPLAY, false);
		$wp_cache = $wp_config_constants['WP_CACHE'] ?? (bool) get_option(self::OPTION_WP_CACHE, false);

		// PHP Settings
		$memory_limit = get_option(self::OPTION_MEMORY_LIMIT, '256M');
		$max_upload_size = get_option(self::OPTION_MAX_UPLOAD_SIZE, '64M');
		$max_post_size = get_option(self::OPTION_MAX_POST_SIZE, '64M');
		$max_execution_time = (int) get_option(self::OPTION_MAX_EXECUTION_TIME, 300);

		$saved = isset($_GET['saved']) && '1' === $_GET['saved'];
		$wp_config_error = isset($_GET['wp_config_error']) ? sanitize_text_field((string) wp_unslash($_GET['wp_config_error'])) : '';
		?>
		<div class="wudt-fullscreen-page">
			<div style="padding: 20px; overflow-y: auto;">
				<h1><?php esc_html_e('Diagnostics Toolkit Settings', 'diagnostics-toolkit'); ?></h1>
				<p><?php esc_html_e('Configure AI models, API keys, and other plugin settings.', 'diagnostics-toolkit'); ?></p>

				<?php if ($saved) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e('Settings saved successfully.', 'diagnostics-toolkit'); ?></p>
					</div>
				<?php endif; ?>

				<?php if (! empty($wp_config_error)) : ?>
					<div class="notice notice-error is-dismissible">
						<p><strong><?php esc_html_e('wp-config.php Error:', 'diagnostics-toolkit'); ?></strong> <?php echo esc_html($wp_config_error); ?></p>
						<p><?php esc_html_e('Other settings were saved, but WordPress debug constants could not be written to wp-config.php.', 'diagnostics-toolkit'); ?></p>
					</div>
				<?php endif; ?>

				<div class="wudt-card" style="max-width: 860px;">
					<h2><?php esc_html_e('AI Assistant Configuration', 'diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Choose your preferred AI model and configure API access.', 'diagnostics-toolkit'); ?>
					</p>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wudt-settings-form">
					<input type="hidden" name="action" value="wudt_save_settings" />
					<?php wp_nonce_field('wudt_save_settings'); ?>

					<p class="description"><?php esc_html_e('Add a key for Google Gemini and/or Anthropic Claude (both can be saved; switch any time in the AI Assistant). Keys are stored encrypted.', 'diagnostics-toolkit'); ?></p>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><label for="ai_provider"><?php esc_html_e('Default provider', 'diagnostics-toolkit'); ?></label></th>
								<td>
									<select name="ai_provider" id="ai_provider">
										<?php foreach (AI_Config::providers() as $pid => $pinfo) : ?>
											<option value="<?php echo esc_attr($pid); ?>" <?php selected(AI_Config::active_provider(), $pid); ?>><?php echo esc_html($pinfo['label']); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<?php foreach (AI_Config::providers() as $pid => $pinfo) : $preview = AI_Config::key_preview($pid); ?>
							<tr>
								<th scope="row"><label for="ai_key_<?php echo esc_attr($pid); ?>"><?php echo esc_html($pinfo['label']); ?></label></th>
								<td>
									<input type="password" autocomplete="new-password" name="ai_key[<?php echo esc_attr($pid); ?>]" id="ai_key_<?php echo esc_attr($pid); ?>" class="regular-text"
										placeholder="<?php echo esc_attr($preview ? sprintf(__('Saved (%s) — leave blank to keep', 'diagnostics-toolkit'), $preview) : sprintf(__('API key (%s)', 'diagnostics-toolkit'), $pinfo['key_hint'])); ?>" />
									<input type="text" name="ai_model[<?php echo esc_attr($pid); ?>]" value="<?php echo esc_attr(AI_Config::get_model($pid)); ?>" class="regular-text" list="wudt-models-<?php echo esc_attr($pid); ?>" style="max-width:240px" aria-label="<?php esc_attr_e('Model', 'diagnostics-toolkit'); ?>" />
									<datalist id="wudt-models-<?php echo esc_attr($pid); ?>">
										<?php foreach ($pinfo['models'] as $m) : ?><option value="<?php echo esc_attr($m); ?>"></option><?php endforeach; ?>
									</datalist>
									<?php if ($preview) : ?>
										<label style="margin-left:8px"><input type="checkbox" name="ai_key_remove[<?php echo esc_attr($pid); ?>]" value="1"> <?php esc_html_e('Remove key', 'diagnostics-toolkit'); ?></label>
									<?php endif; ?>
									<p class="description"><a href="<?php echo esc_url($pinfo['key_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get an API key', 'diagnostics-toolkit'); ?></a></p>
								</td>
							</tr>
							<?php endforeach; ?>
							<tr>
								<th scope="row"><?php esc_html_e('Agent changes', 'diagnostics-toolkit'); ?></th>
								<td>
									<label><input type="checkbox" name="ai_auto_approve" value="1" <?php checked(AI_Config::auto_approve()); ?>> <?php esc_html_e('Let the agent make changes without asking for approval each time', 'diagnostics-toolkit'); ?></label>
									<p class="description"><?php esc_html_e('Every change is backed up and can be undone from the AI Assistant either way.', 'diagnostics-toolkit'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ai_temperature"><?php esc_html_e('Temperature', 'diagnostics-toolkit'); ?></label></th>
								<td><input type="number" name="ai_temperature" id="ai_temperature" value="<?php echo esc_attr((string) AI_Config::temperature()); ?>" step="0.1" min="0" max="1" class="small-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ai_max_tokens"><?php esc_html_e('Max output tokens', 'diagnostics-toolkit'); ?></label></th>
								<td>
									<input type="number" name="ai_max_tokens" id="ai_max_tokens" value="<?php echo esc_attr((string) AI_Config::max_tokens()); ?>" step="1000" min="1024" max="64000" class="small-text" />
									<p class="description"><?php esc_html_e('Large Elementor pages need a high limit (16000 or more).', 'diagnostics-toolkit'); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wudt-card" style="max-width: 860px; margin-top: 20px;">
					<h2><?php esc_html_e('WordPress Debug Settings', 'diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Control WordPress debug mode by directly editing wp-config.php. These settings are 100% functional and persistent.', 'diagnostics-toolkit'); ?>
						<br>
						<strong><?php esc_html_e('Note:', 'diagnostics-toolkit'); ?></strong> 
						<?php esc_html_e('Changes are written directly to wp-config.php. A backup is created before modification.', 'diagnostics-toolkit'); ?>
						<?php if (defined('WP_DEBUG')) : ?>
							<br><code><?php esc_html_e('WP_DEBUG is currently defined as: ', 'diagnostics-toolkit'); echo WP_DEBUG ? 'true' : 'false'; ?></code>
						<?php endif; ?>
					</p>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG', 'diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug" value="1" <?php checked($wp_debug); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug ? esc_html__('Enabled', 'diagnostics-toolkit') : esc_html__('Disabled', 'diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Enable WordPress debug mode to show PHP errors and warnings.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG_LOG', 'diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug_log" value="1" <?php checked($wp_debug_log); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug_log ? esc_html__('Enabled', 'diagnostics-toolkit') : esc_html__('Disabled', 'diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Log errors to wp-content/debug.log file.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG_DISPLAY', 'diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug_display" value="1" <?php checked($wp_debug_display); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug_display ? esc_html__('Enabled', 'diagnostics-toolkit') : esc_html__('Disabled', 'diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Display errors on the page (not recommended for production).', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_CACHE', 'diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_cache" value="1" <?php checked($wp_cache); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_cache ? esc_html__('Enabled', 'diagnostics-toolkit') : esc_html__('Disabled', 'diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Enable WordPress object caching (requires external cache system).', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wudt-card" style="max-width: 860px; margin-top: 20px;">
					<h2><?php esc_html_e('PHP Runtime Settings', 'diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Configure PHP settings at runtime. These settings apply immediately and override php.ini values where allowed.', 'diagnostics-toolkit'); ?>
					</p>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="memory_limit"><?php esc_html_e('Memory Limit', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="memory_limit" id="memory_limit" 
										value="<?php echo esc_attr($memory_limit); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('PHP memory limit (e.g., 256M, 512M, 1G). Current:', 'diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('memory_limit')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_upload_size"><?php esc_html_e('Max Upload File Size', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="max_upload_size" id="max_upload_size" 
										value="<?php echo esc_attr($max_upload_size); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('Maximum file upload size (e.g., 64M, 128M, 256M). Current:', 'diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_post_size"><?php esc_html_e('Max POST Size', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="max_post_size" id="max_post_size" 
										value="<?php echo esc_attr($max_post_size); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('Maximum POST data size (e.g., 64M, 128M). Should be >= upload size. Current:', 'diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('post_max_size')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_execution_time"><?php esc_html_e('Max Execution Time', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="number" name="max_execution_time" id="max_execution_time" 
										value="<?php echo esc_attr($max_execution_time); ?>" 
										step="30" min="30" max="600" class="small-text" />
									<span><?php esc_html_e('seconds', 'diagnostics-toolkit'); ?></span>
									<p class="description">
										<?php esc_html_e('Maximum script execution time. Current:', 'diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('max_execution_time')); ?>s</code>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e('Save All Settings', 'diagnostics-toolkit'); ?>
						</button>
					</p>
				</form>
			</div>

			<!-- Admin User Creation Section -->
			<div class="wudt-card" style="max-width: 860px; margin-top: 20px;">
				<h2><?php esc_html_e('Create Admin User', 'diagnostics-toolkit'); ?></h2>
				<p class="description">
					<?php esc_html_e('Create a new WordPress admin user with example credentials. All fields are required.', 'diagnostics-toolkit'); ?>
				</p>

				<?php if (isset($_GET['user_created']) && '1' === $_GET['user_created']) : ?>
					<div class="notice notice-success is-dismissible">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: Username, 2: Email */
									__('User created successfully! Username: %1$s, Email: %2$s', 'diagnostics-toolkit'),
									sanitize_text_field((string) wp_unslash($_GET['username'] ?? '')),
									sanitize_email((string) wp_unslash($_GET['email'] ?? ''))
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if (isset($_GET['user_error']) && '1' === $_GET['user_error']) : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php echo esc_html(sanitize_text_field((string) wp_unslash($_GET['message'] ?? __('An error occurred.', 'diagnostics-toolkit')))); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="wudt_create_admin_user" />
					<?php wp_nonce_field('wudt_create_admin_user'); ?>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="admin_username"><?php esc_html_e('Username', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="admin_username" id="admin_username" class="regular-text" 
										placeholder="<?php esc_attr_e('e.g., admin2024', 'diagnostics-toolkit'); ?>" required />
									<p class="description">
										<?php esc_html_e('Unique username for the new admin user.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="admin_email"><?php esc_html_e('Email', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="email" name="admin_email" id="admin_email" class="regular-text" 
										placeholder="<?php esc_attr_e('e.g., admin@example.com', 'diagnostics-toolkit'); ?>" required />
									<p class="description">
										<?php esc_html_e('Valid email address for the user.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="admin_password"><?php esc_html_e('Password', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="admin_password" id="admin_password" class="regular-text" 
										value="AdminPass123!" required />
									<button type="button" class="button" onclick="document.getElementById('admin_password').value = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2).toUpperCase() + '!@';">
										<?php esc_html_e('Generate Random', 'diagnostics-toolkit'); ?>
									</button>
									<p class="description">
										<?php esc_html_e('Strong password for the user. Click generate for a random password.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="admin_role"><?php esc_html_e('Role', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<select name="admin_role" id="admin_role" class="regular-text">
										<option value="administrator"><?php esc_html_e('Administrator', 'diagnostics-toolkit'); ?></option>
										<option value="editor"><?php esc_html_e('Editor', 'diagnostics-toolkit'); ?></option>
										<option value="author"><?php esc_html_e('Author', 'diagnostics-toolkit'); ?></option>
										<option value="contributor"><?php esc_html_e('Contributor', 'diagnostics-toolkit'); ?></option>
										<option value="subscriber"><?php esc_html_e('Subscriber', 'diagnostics-toolkit'); ?></option>
									</select>
									<p class="description">
										<?php esc_html_e('User role. Administrator has full access.', 'diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e('Create User', 'diagnostics-toolkit'); ?>
						</button>
					</p>
				</form>
			</div>

		</div>
		<?php
	}

	/**
	 * Save AI provider settings (per-provider keys and models).
	 */
	private function save_ai_fields(): void {
		$keys = isset($_POST['ai_key']) && is_array($_POST['ai_key']) ? wp_unslash($_POST['ai_key']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ($keys as $provider => $key) {
			$key = trim((string) $key);
			if ('' !== $key && AI_Config::is_provider((string) $provider)) {
				AI_Config::set_key((string) $provider, $key);
			}
		}
		$remove = isset($_POST['ai_key_remove']) && is_array($_POST['ai_key_remove']) ? array_keys(wp_unslash($_POST['ai_key_remove'])) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ($remove as $provider) {
			AI_Config::set_key((string) $provider, '');
		}
		$models = isset($_POST['ai_model']) && is_array($_POST['ai_model']) ? wp_unslash($_POST['ai_model']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ($models as $provider => $model) {
			if (AI_Config::is_provider((string) $provider) && '' !== trim((string) $model)) {
				AI_Config::set_model((string) $provider, sanitize_text_field((string) $model));
			}
		}
		if (isset($_POST['ai_provider'])) {
			AI_Config::set_active_provider(sanitize_key((string) wp_unslash($_POST['ai_provider'])));
		}
		update_option(AI_Config::OPTION_AUTO_APPROVE, ! empty($_POST['ai_auto_approve']), false);
		if (isset($_POST['ai_temperature'])) {
			update_option(self::OPTION_AI_TEMPERATURE, max(0, min(1, (float) wp_unslash($_POST['ai_temperature']))), false);
		}
		if (isset($_POST['ai_max_tokens'])) {
			update_option(self::OPTION_AI_MAX_TOKENS, max(1024, min(64000, (int) wp_unslash($_POST['ai_max_tokens']))), false);
		}
	}

	private function authorize_action(string $nonce_action): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'diagnostics-toolkit'));
		}
		check_admin_referer($nonce_action);
	}

	/**
	 * Get the configured AI provider.
	 */
	public static function get_ai_provider(): string {
		return AI_Config::active_provider();
	}

	/**
	 * Get the configured AI model.
	 */
	public static function get_ai_model(): string {
		return AI_Config::get_model(AI_Config::active_provider());
	}

	public static function is_ai_file_ops_allowed(): bool {
		return (bool) get_option(self::OPTION_AI_ALLOW_FILE_OPS, false);
	}

	/**
	 * Get the configured API key.
	 */
	public static function get_api_key(): string {
		return AI_Config::get_key(AI_Config::active_provider());
	}

	/**
	 * Get the configured temperature.
	 */
	public static function get_temperature(): float {
		return (float) get_option(self::OPTION_AI_TEMPERATURE, 0.7);
	}

	/**
	 * Get the configured max tokens.
	 */
	public static function get_max_tokens(): int {
		return (int) get_option(self::OPTION_AI_MAX_TOKENS, 2000);
	}

	/**
	 * Check if WP_DEBUG is enabled.
	 */
	public static function is_wp_debug_enabled(): bool {
		return (bool) get_option(self::OPTION_WP_DEBUG, false);
	}

	/**
	 * Check if WP_DEBUG_LOG is enabled.
	 */
	public static function is_wp_debug_log_enabled(): bool {
		return (bool) get_option(self::OPTION_WP_DEBUG_LOG, false);
	}

	/**
	 * Check if WP_DEBUG_DISPLAY is enabled.
	 */
	public static function is_wp_debug_display_enabled(): bool {
		return (bool) get_option(self::OPTION_WP_DEBUG_DISPLAY, false);
	}

	/**
	 * Check if WP_CACHE is enabled.
	 */
	public static function is_wp_cache_enabled(): bool {
		return (bool) get_option(self::OPTION_WP_CACHE, false);
	}

	/**
	 * Get the configured memory limit.
	 */
	public static function get_memory_limit(): string {
		return get_option(self::OPTION_MEMORY_LIMIT, '256M');
	}

	/**
	 * Get the configured max upload size.
	 */
	public static function get_max_upload_size(): string {
		return get_option(self::OPTION_MAX_UPLOAD_SIZE, '64M');
	}

	/**
	 * Get the configured max post size.
	 */
	public static function get_max_post_size(): string {
		return get_option(self::OPTION_MAX_POST_SIZE, '64M');
	}

	/**
	 * Get the configured max execution time.
	 */
	public static function get_max_execution_time(): int {
		return (int) get_option(self::OPTION_MAX_EXECUTION_TIME, 300);
	}
}
