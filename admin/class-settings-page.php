<?php
/**
 * Plugin settings page: debug mode, PHP limits and media source.
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WUDT\Modules\Migration\Live_Media;

if (! defined('ABSPATH')) {
	exit;
}

class Settings_Page {

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
		// The hook name starts with the menu title, so match the page slug at the end.
		if ('_page_wudt-settings' !== substr($hook, -strlen('_page_wudt-settings'))) {
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
		check_admin_referer('wudt_save_settings');
		$this->authorize_action();


		// WordPress Debug Settings - Also update wp-config.php for 100% functionality
		$wp_debug = isset($_POST['wp_debug']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug']));
		update_option(self::OPTION_WP_DEBUG, $wp_debug, false);

		$wp_debug_log = isset($_POST['wp_debug_log']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug_log']));
		update_option(self::OPTION_WP_DEBUG_LOG, $wp_debug_log, false);

		$wp_debug_display = isset($_POST['wp_debug_display']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug_display']));
		update_option(self::OPTION_WP_DEBUG_DISPLAY, $wp_debug_display, false);

		$wp_cache = isset($_POST['wp_cache']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_cache']));
		update_option(self::OPTION_WP_CACHE, $wp_cache, false);

		// Update wp-config.php with WordPress debug constants
		$wp_config_result = $this->update_wp_config_debug_settings(
			$wp_debug,
			$wp_debug_log,
			$wp_debug_display,
			$wp_cache
		);

		// PHP Settings
		$memory_limit = isset($_POST['memory_limit']) ? sanitize_text_field(wp_unslash($_POST['memory_limit'])) : '256M';
		update_option(self::OPTION_MEMORY_LIMIT, $memory_limit, false);

		$max_upload_size = isset($_POST['max_upload_size']) ? sanitize_text_field(wp_unslash($_POST['max_upload_size'])) : '64M';
		update_option(self::OPTION_MAX_UPLOAD_SIZE, $max_upload_size, false);

		$max_post_size = isset($_POST['max_post_size']) ? sanitize_text_field(wp_unslash($_POST['max_post_size'])) : '64M';
		update_option(self::OPTION_MAX_POST_SIZE, $max_post_size, false);

		$max_execution_time = isset($_POST['max_execution_time']) ? absint(wp_unslash($_POST['max_execution_time'])) : 300;
		update_option(self::OPTION_MAX_EXECUTION_TIME, max(30, min(600, $max_execution_time)), false);

		$media_error = $this->save_media_source_fields();

		// Redirect with success message
		$redirect_url = admin_url('admin.php?page=wudt-settings&saved=1');
		if (is_wp_error($wp_config_result)) {
			$redirect_url = admin_url('admin.php?page=wudt-settings&saved=1&wp_config_error=' . urlencode($wp_config_result->get_error_message()));
		}
		if ('' !== $media_error) {
			$redirect_url = add_query_arg('media_error', rawurlencode($media_error), $redirect_url);
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
		
		if (! wp_is_writable($config_file)) {
			return new \WP_Error('config_not_writable', __('wp-config.php is not writable. Please check file permissions.', 'diagnostics-toolkit'));
		}
		
		// Read current config
		$config_content = file_get_contents($config_file);
		if (false === $config_content) {
			return new \WP_Error('config_read_failed', __('Failed to read wp-config.php.', 'diagnostics-toolkit'));
		}
		
		// Create backup
		$backup_file = $config_file . '.backup-' . gmdate('Y-m-d-H-i-s');
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
				/* translators: 1: expected value, 2: value found in wp-config.php. */ __('WP_DEBUG value mismatch: expected %1$s but found %2$s.', 'diagnostics-toolkit'),
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
	public function ajax_save_settings(): void {
		check_ajax_referer('wudt_save_settings_async', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'diagnostics-toolkit')));
			return;
		}

		// WordPress Debug Settings
		$wp_debug = isset($_POST['wp_debug']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug']));
		update_option(self::OPTION_WP_DEBUG, $wp_debug, false);

		$wp_debug_log = isset($_POST['wp_debug_log']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug_log']));
		update_option(self::OPTION_WP_DEBUG_LOG, $wp_debug_log, false);

		$wp_debug_display = isset($_POST['wp_debug_display']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_debug_display']));
		update_option(self::OPTION_WP_DEBUG_DISPLAY, $wp_debug_display, false);

		$wp_cache = isset($_POST['wp_cache']) && '1' === sanitize_text_field(wp_unslash($_POST['wp_cache']));
		update_option(self::OPTION_WP_CACHE, $wp_cache, false);

		// Update wp-config.php with WordPress debug constants
		$wp_config_result = $this->update_wp_config_debug_settings(
			$wp_debug,
			$wp_debug_log,
			$wp_debug_display,
			$wp_cache
		);

		// PHP Settings
		$memory_limit = isset($_POST['memory_limit']) ? sanitize_text_field(wp_unslash($_POST['memory_limit'])) : '256M';
		update_option(self::OPTION_MEMORY_LIMIT, $memory_limit, false);

		$max_upload_size = isset($_POST['max_upload_size']) ? sanitize_text_field(wp_unslash($_POST['max_upload_size'])) : '64M';
		update_option(self::OPTION_MAX_UPLOAD_SIZE, $max_upload_size, false);

		$max_post_size = isset($_POST['max_post_size']) ? sanitize_text_field(wp_unslash($_POST['max_post_size'])) : '64M';
		update_option(self::OPTION_MAX_POST_SIZE, $max_post_size, false);

		$max_execution_time = isset($_POST['max_execution_time']) ? absint(wp_unslash($_POST['max_execution_time'])) : 300;
		update_option(self::OPTION_MAX_EXECUTION_TIME, max(30, min(600, $max_execution_time)), false);

		$media_error = $this->save_media_source_fields();

		$response = array(
			'success' => true,
			'message' => __('Settings saved successfully.', 'diagnostics-toolkit'),
			'media'   => Live_Media::settings(),
		);
		
		if (is_wp_error($wp_config_result)) {
			$response['wp_config_error'] = $wp_config_result->get_error_message();
		}
		if ('' !== $media_error) {
			$response['media_error'] = $media_error;
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
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
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

		$media = Live_Media::settings();
		$media_local_url = untrailingslashit((string) wp_get_upload_dir()['baseurl']);
		$media_error = isset($_GET['media_error']) ? sanitize_text_field(wp_unslash($_GET['media_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Notice flags added by this page's own redirect after saving; they only choose which notice to show.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$saved = isset($_GET['saved']) && '1' === sanitize_text_field(wp_unslash($_GET['saved']));
		$wp_config_error = isset($_GET['wp_config_error']) ? sanitize_text_field(wp_unslash($_GET['wp_config_error'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wudt-fullscreen-page">
			<div style="padding: 20px; overflow-y: auto;">
				<h1><?php esc_html_e('Diagnostics Toolkit Settings', 'diagnostics-toolkit'); ?></h1>
				<p><?php esc_html_e('Configure debug mode, PHP limits and media settings.', 'diagnostics-toolkit'); ?></p>

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

				<?php if ('' !== $media_error) : ?>
					<div class="notice notice-error is-dismissible">
						<p><strong><?php esc_html_e('Media source was not changed:', 'diagnostics-toolkit'); ?></strong> <?php echo esc_html($media_error); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wudt-settings-form">
					<input type="hidden" name="action" value="wudt_save_settings" />
					<?php wp_nonce_field('wudt_save_settings'); ?>

				<div class="wudt-card" style="max-width: 860px;">
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
				</div>

				<div class="wudt-card" id="wudt-media-source" style="max-width: 860px; margin-top: 20px;">
					<h2><?php esc_html_e('Media Source', 'diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Load images and other media from the source website instead of keeping a copy of the uploads folder on this site. Attachment URLs, image sizes and media links in page content are rewritten to the source website.', 'diagnostics-toolkit'); ?>
					</p>
					<input type="hidden" name="media_source_form" value="1" />

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e('Use media from source website', 'diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="media_source_enabled" id="media_source_enabled" value="1" <?php checked($media['enabled']); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $media['enabled'] ? esc_html__('Enabled', 'diagnostics-toolkit') : esc_html__('Disabled', 'diagnostics-toolkit'); ?>
									</span>
									<?php if ($media['enabled'] && $media['since'] > 0) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: 1: source site, 2: date */
												esc_html__('Active since %2$s%1$s.', 'diagnostics-toolkit'),
												'' !== $media['site'] ? ' ' . esc_html(sprintf(/* translators: %s: name of the connected site. */ __('(set up by migration from %s)', 'diagnostics-toolkit'), $media['site'])) : '',
												esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $media['since']))
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>

							<tr class="wudt-media-source-field">
								<th scope="row">
									<label for="media_source_url"><?php esc_html_e('Source media URL', 'diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="url" name="media_source_url" id="media_source_url" class="regular-text"
										value="<?php echo esc_attr($media['url']); ?>"
										placeholder="https://example.com/wp-content/uploads" />
									<p class="description">
										<?php esc_html_e('The uploads URL of the source website. If you enter only the site address, /wp-content/uploads is added. This site\'s uploads URL:', 'diagnostics-toolkit'); ?>
										<code><?php echo esc_html($media_local_url); ?></code>
									</p>
								</td>
							</tr>

							<tr class="wudt-media-source-field">
								<th scope="row"><?php esc_html_e('Which files', 'diagnostics-toolkit'); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="radio" name="media_source_mode" value="missing" <?php checked('missing', $media['mode']); ?>>
											<?php esc_html_e('Only files missing on this site (recommended: new uploads here keep working)', 'diagnostics-toolkit'); ?>
										</label><br>
										<label>
											<input type="radio" name="media_source_mode" value="all" <?php checked('all', $media['mode']); ?>>
											<?php esc_html_e('All media (fastest; files uploaded to this site will not be shown)', 'diagnostics-toolkit'); ?>
										</label>
									</fieldset>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e('Save All Settings', 'diagnostics-toolkit'); ?>
						</button>
					</p>
				</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Turn "use media from the source website" on or off, or change its URL or mode.
	 *
	 * @return string Error message, or '' when saved (or nothing to change).
	 */
	private function save_media_source_fields(): string {
		if (empty($_POST['media_source_form'])) { // phpcs:ignore WordPress.Security.NonceVerification -- verified by the callers.
			return '';
		}
		$current = Live_Media::settings();
		if (empty($_POST['media_source_enabled'])) { // phpcs:ignore WordPress.Security.NonceVerification
			if ($current['enabled']) {
				Live_Media::disable();
				\WUDT\Includes\Operation_Logger::log('migration', 'Live media turned off', array('from' => 'settings'));
			}
			return '';
		}

		$mode = sanitize_key((string) wp_unslash($_POST['media_source_mode'] ?? 'missing')); // phpcs:ignore WordPress.Security.NonceVerification
		$url = trim(esc_url_raw((string) wp_unslash($_POST['media_source_url'] ?? ''), array('http', 'https'))); // phpcs:ignore WordPress.Security.NonceVerification
		$parts = '' !== $url ? wp_parse_url($url) : false;
		if (! is_array($parts) || empty($parts['host'])) {
			return __('Enter the full URL of the source website\'s uploads folder, starting with http:// or https://.', 'diagnostics-toolkit');
		}
		$url = untrailingslashit($url);
		if ('' === trim((string) ($parts['path'] ?? ''), '/')) {
			$url .= '/wp-content/uploads';
		}

		$local = untrailingslashit((string) wp_get_upload_dir()['baseurl']);
		$strip = static function (string $u): string {
			return strtolower((string) preg_replace('#^https?://#i', '', $u));
		};
		if ($strip($url) === $strip($local)) {
			return __('The source media URL is this site\'s own uploads URL. Enter the uploads URL of the source website.', 'diagnostics-toolkit');
		}

		if ($current['enabled'] && $current['url'] === $url && $current['mode'] === ('all' === $mode ? 'all' : 'missing')) {
			return '';
		}
		try {
			// Keep the label of the migration that set it up while the URL stays the same.
			Live_Media::enable($url, $mode, $current['url'] === $url ? $current['site'] : '');
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
		\WUDT\Includes\Operation_Logger::log('migration', 'Live media turned on', array('url' => $url, 'mode' => $mode, 'from' => 'settings'));
		return '';
	}

	private function authorize_action(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'diagnostics-toolkit'));
		}
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
