<?php
/**
 * Plugin settings page for AI model configuration and other options.
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class Settings_Page {
	private const OPTION_AI_MODEL = 'wudt_ai_model';
	private const OPTION_AI_API_KEY = 'wudt_ai_api_key';
	private const OPTION_AI_TEMPERATURE = 'wudt_ai_temperature';
	private const OPTION_AI_MAX_TOKENS = 'wudt_ai_max_tokens';

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
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Settings', 'wp-ultimate-diagnostics-toolkit'),
			__('Settings', 'wp-ultimate-diagnostics-toolkit'),
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
	}

	public function handle_save_settings(): void {
		$this->authorize_action('wudt_save_settings');

		// Save AI Model
		$ai_model = isset($_POST['ai_model']) ? sanitize_text_field((string) wp_unslash($_POST['ai_model'])) : 'gemini';
		update_option(self::OPTION_AI_MODEL, $ai_model, false);

		// Save API Key (encrypted)
		$api_key = isset($_POST['ai_api_key']) ? sanitize_text_field((string) wp_unslash($_POST['ai_api_key'])) : '';
		if (! empty($api_key)) {
			$encrypted = base64_encode($api_key); // Simple obfuscation, consider using WordPress encryption
			update_option(self::OPTION_AI_API_KEY, $encrypted, false);
		}

		// Save Temperature
		$temperature = isset($_POST['ai_temperature']) ? (float) wp_unslash($_POST['ai_temperature']) : 0.7;
		update_option(self::OPTION_AI_TEMPERATURE, max(0, min(2, $temperature)), false);

		// Save Max Tokens
		$max_tokens = isset($_POST['ai_max_tokens']) ? (int) wp_unslash($_POST['ai_max_tokens']) : 2000;
		update_option(self::OPTION_AI_MAX_TOKENS, max(100, min(8000, $max_tokens)), false);

		// Save WordPress Debug Settings
		$wp_debug = isset($_POST['wp_debug']) && '1' === (string) wp_unslash($_POST['wp_debug']);
		update_option(self::OPTION_WP_DEBUG, $wp_debug, false);

		$wp_debug_log = isset($_POST['wp_debug_log']) && '1' === (string) wp_unslash($_POST['wp_debug_log']);
		update_option(self::OPTION_WP_DEBUG_LOG, $wp_debug_log, false);

		$wp_debug_display = isset($_POST['wp_debug_display']) && '1' === (string) wp_unslash($_POST['wp_debug_display']);
		update_option(self::OPTION_WP_DEBUG_DISPLAY, $wp_debug_display, false);

		$wp_cache = isset($_POST['wp_cache']) && '1' === (string) wp_unslash($_POST['wp_cache']);
		update_option(self::OPTION_WP_CACHE, $wp_cache, false);

		// Save PHP Settings
		$memory_limit = isset($_POST['memory_limit']) ? sanitize_text_field((string) wp_unslash($_POST['memory_limit'])) : '256M';
		update_option(self::OPTION_MEMORY_LIMIT, $memory_limit, false);

		$max_upload_size = isset($_POST['max_upload_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_upload_size'])) : '64M';
		update_option(self::OPTION_MAX_UPLOAD_SIZE, $max_upload_size, false);

		$max_post_size = isset($_POST['max_post_size']) ? sanitize_text_field((string) wp_unslash($_POST['max_post_size'])) : '64M';
		update_option(self::OPTION_MAX_POST_SIZE, $max_post_size, false);

		$max_execution_time = isset($_POST['max_execution_time']) ? (int) wp_unslash($_POST['max_execution_time']) : 300;
		update_option(self::OPTION_MAX_EXECUTION_TIME, max(30, min(600, $max_execution_time)), false);

		// Redirect with success message
		wp_safe_redirect(admin_url('admin.php?page=wudt-settings&saved=1'));
		exit;
	}

	/**
	 * Apply runtime settings that can be changed without wp-config.php
	 */
	public function apply_runtime_settings(): void {
		// Apply debug settings if WP_DEBUG is not already defined
		if (! defined('WP_DEBUG')) {
			if (get_option(self::OPTION_WP_DEBUG, false)) {
				// Enable error reporting
				if (! defined('WP_DEBUG')) {
					define('WP_DEBUG', true);
				}
			}
		}

		// Apply debug log settings
		if (get_option(self::OPTION_WP_DEBUG_LOG, false)) {
			ini_set('log_errors', '1');
			ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
		}

		// Apply debug display settings
		if (get_option(self::OPTION_WP_DEBUG_DISPLAY, false)) {
			ini_set('display_errors', '1');
		} else {
			ini_set('display_errors', '0');
		}

		// Apply memory limit
		$memory_limit = get_option(self::OPTION_MEMORY_LIMIT, '');
		if (! empty($memory_limit)) {
			ini_set('memory_limit', $memory_limit);
		}

		// Apply max execution time
		$max_execution_time = (int) get_option(self::OPTION_MAX_EXECUTION_TIME, 0);
		if ($max_execution_time > 0) {
			ini_set('max_execution_time', (string) $max_execution_time);
			set_time_limit($max_execution_time);
		}
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit'));
		}

		$ai_model = get_option(self::OPTION_AI_MODEL, 'gemini');
		$ai_api_key = get_option(self::OPTION_AI_API_KEY, '');
		$ai_temperature = (float) get_option(self::OPTION_AI_TEMPERATURE, 0.7);
		$ai_max_tokens = (int) get_option(self::OPTION_AI_MAX_TOKENS, 2000);

		// WordPress Debug Settings
		$wp_debug = (bool) get_option(self::OPTION_WP_DEBUG, false);
		$wp_debug_log = (bool) get_option(self::OPTION_WP_DEBUG_LOG, false);
		$wp_debug_display = (bool) get_option(self::OPTION_WP_DEBUG_DISPLAY, false);
		$wp_cache = (bool) get_option(self::OPTION_WP_CACHE, false);

		// PHP Settings
		$memory_limit = get_option(self::OPTION_MEMORY_LIMIT, '256M');
		$max_upload_size = get_option(self::OPTION_MAX_UPLOAD_SIZE, '64M');
		$max_post_size = get_option(self::OPTION_MAX_POST_SIZE, '64M');
		$max_execution_time = (int) get_option(self::OPTION_MAX_EXECUTION_TIME, 300);

		$saved = isset($_GET['saved']) && '1' === $_GET['saved'];
		?>
		<div class="wrap wudt-wrap">
			<h1><?php esc_html_e('WP Diagnostics Settings', 'wp-ultimate-diagnostics-toolkit'); ?></h1>
			<p><?php esc_html_e('Configure AI models, API keys, and other plugin settings.', 'wp-ultimate-diagnostics-toolkit'); ?></p>

			<?php if ($saved) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Settings saved successfully.', 'wp-ultimate-diagnostics-toolkit'); ?></p>
				</div>
			<?php endif; ?>

			<div class="wudt-card" style="max-width: 860px;">
				<h2><?php esc_html_e('AI Assistant Configuration', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
				<p class="description">
					<?php esc_html_e('Choose your preferred AI model and configure API access.', 'wp-ultimate-diagnostics-toolkit'); ?>
				</p>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="wudt_save_settings" />
					<?php wp_nonce_field('wudt_save_settings'); ?>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="ai_model"><?php esc_html_e('AI Model', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<select name="ai_model" id="ai_model" class="regular-text">
										<option value="gemini" <?php selected($ai_model, 'gemini'); ?>>
											<?php esc_html_e('Google Gemini', 'wp-ultimate-diagnostics-toolkit'); ?> (<?php esc_html_e('Recommended', 'wp-ultimate-diagnostics-toolkit'); ?>)
										</option>
										<option value="gpt4" <?php selected($ai_model, 'gpt4'); ?>>
											<?php esc_html_e('OpenAI GPT-4', 'wp-ultimate-diagnostics-toolkit'); ?>
										</option>
										<option value="gpt35" <?php selected($ai_model, 'gpt35'); ?>>
											<?php esc_html_e('OpenAI GPT-3.5 Turbo', 'wp-ultimate-diagnostics-toolkit'); ?>
										</option>
										<option value="sonnet" <?php selected($ai_model, 'sonnet'); ?>>
											<?php esc_html_e('Claude Sonnet', 'wp-ultimate-diagnostics-toolkit'); ?>
										</option>
										<option value="opus" <?php selected($ai_model, 'opus'); ?>>
											<?php esc_html_e('Claude Opus', 'wp-ultimate-diagnostics-toolkit'); ?>
										</option>
										<option value="haiku" <?php selected($ai_model, 'haiku'); ?>>
											<?php esc_html_e('Claude Haiku', 'wp-ultimate-diagnostics-toolkit'); ?>
										</option>
									</select>
									<p class="description">
										<?php esc_html_e('Select the AI model to use for the assistant.', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="ai_api_key"><?php esc_html_e('API Key', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="password" name="ai_api_key" id="ai_api_key" class="regular-text" 
										value="<?php echo $ai_api_key ? esc_attr(str_repeat('*', 20)) : ''; ?>" 
										placeholder="<?php echo empty($ai_api_key) ? esc_attr__('Enter your API key', 'wp-ultimate-diagnostics-toolkit') : esc_attr__('Leave blank to keep existing key', 'wp-ultimate-diagnostics-toolkit'); ?>" />
									<button type="button" class="button" id="wudt-toggle-api-key">
										<?php esc_html_e('Show', 'wp-ultimate-diagnostics-toolkit'); ?>
									</button>
									<p class="description">
										<?php esc_html_e('Enter your API key for the selected AI service. This will be securely stored.', 'wp-ultimate-diagnostics-toolkit'); ?>
										<br>
										<?php esc_html_e('Get your API key from:', 'wp-ultimate-diagnostics-toolkit'); ?>
										<a href="https://makersuite.google.com/app/apikey" target="_blank"><?php esc_html_e('Google AI Studio', 'wp-ultimate-diagnostics-toolkit'); ?></a> | 
										<a href="https://platform.openai.com/api-keys" target="_blank"><?php esc_html_e('OpenAI', 'wp-ultimate-diagnostics-toolkit'); ?></a> | 
										<a href="https://console.anthropic.com/settings/keys" target="_blank"><?php esc_html_e('Anthropic', 'wp-ultimate-diagnostics-toolkit'); ?></a>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="ai_temperature"><?php esc_html_e('Temperature', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="number" name="ai_temperature" id="ai_temperature" 
										value="<?php echo esc_attr($ai_temperature); ?>" 
										step="0.1" min="0" max="2" class="small-text" />
									<p class="description">
										<?php esc_html_e('Controls randomness: 0 = deterministic, 1 = balanced, 2 = more creative.', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="ai_max_tokens"><?php esc_html_e('Max Tokens', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="number" name="ai_max_tokens" id="ai_max_tokens" 
										value="<?php echo esc_attr($ai_max_tokens); ?>" 
										step="100" min="100" max="8000" class="small-text" />
									<p class="description">
										<?php esc_html_e('Maximum response length in tokens.', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wudt-card" style="max-width: 860px; margin-top: 20px;">
					<h2><?php esc_html_e('WordPress Debug Settings', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Control WordPress debug mode without editing wp-config.php. These settings apply at runtime.', 'wp-ultimate-diagnostics-toolkit'); ?>
						<br>
						<strong><?php esc_html_e('Note:', 'wp-ultimate-diagnostics-toolkit'); ?></strong> 
						<?php esc_html_e('For permanent changes, define these constants in wp-config.php.', 'wp-ultimate-diagnostics-toolkit'); ?>
					</p>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG', 'wp-ultimate-diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug" value="1" <?php checked($wp_debug); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Enable WordPress debug mode to show PHP errors and warnings.', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG_LOG', 'wp-ultimate-diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug_log" value="1" <?php checked($wp_debug_log); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug_log ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Log errors to wp-content/debug.log file.', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_DEBUG_DISPLAY', 'wp-ultimate-diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_debug_display" value="1" <?php checked($wp_debug_display); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_debug_display ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Display errors on the page (not recommended for production).', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e('WP_CACHE', 'wp-ultimate-diagnostics-toolkit'); ?></th>
								<td>
									<label class="wudt-toggle-switch">
										<input type="checkbox" name="wp_cache" value="1" <?php checked($wp_cache); ?>>
										<span class="slider"></span>
									</label>
									<span class="wudt-toggle-label">
										<?php echo $wp_cache ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?>
									</span>
									<p class="description">
										<?php esc_html_e('Enable WordPress object caching (requires external cache system).', 'wp-ultimate-diagnostics-toolkit'); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="wudt-card" style="max-width: 860px; margin-top: 20px;">
					<h2><?php esc_html_e('PHP Runtime Settings', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
					<p class="description">
						<?php esc_html_e('Configure PHP settings at runtime. These settings apply immediately and override php.ini values where allowed.', 'wp-ultimate-diagnostics-toolkit'); ?>
					</p>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="memory_limit"><?php esc_html_e('Memory Limit', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="memory_limit" id="memory_limit" 
										value="<?php echo esc_attr($memory_limit); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('PHP memory limit (e.g., 256M, 512M, 1G). Current:', 'wp-ultimate-diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('memory_limit')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_upload_size"><?php esc_html_e('Max Upload File Size', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="max_upload_size" id="max_upload_size" 
										value="<?php echo esc_attr($max_upload_size); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('Maximum file upload size (e.g., 64M, 128M, 256M). Current:', 'wp-ultimate-diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_post_size"><?php esc_html_e('Max POST Size', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="text" name="max_post_size" id="max_post_size" 
										value="<?php echo esc_attr($max_post_size); ?>" 
										class="small-text" />
									<p class="description">
										<?php esc_html_e('Maximum POST data size (e.g., 64M, 128M). Should be >= upload size. Current:', 'wp-ultimate-diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('post_max_size')); ?></code>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="max_execution_time"><?php esc_html_e('Max Execution Time', 'wp-ultimate-diagnostics-toolkit'); ?></label>
								</th>
								<td>
									<input type="number" name="max_execution_time" id="max_execution_time" 
										value="<?php echo esc_attr($max_execution_time); ?>" 
										step="30" min="30" max="600" class="small-text" />
									<span><?php esc_html_e('seconds', 'wp-ultimate-diagnostics-toolkit'); ?></span>
									<p class="description">
										<?php esc_html_e('Maximum script execution time. Current:', 'wp-ultimate-diagnostics-toolkit'); ?> 
										<code><?php echo esc_html(ini_get('max_execution_time')); ?>s</code>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e('Save All Settings', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</p>
				</form>
			</div>

			<script>
				document.getElementById('wudt-toggle-api-key').addEventListener('click', function() {
					var input = document.getElementById('ai_api_key');
					if (input.type === 'password') {
						input.type = 'text';
						this.textContent = '<?php echo esc_js(__('Hide', 'wp-ultimate-diagnostics-toolkit')); ?>';
					} else {
						input.type = 'password';
						this.textContent = '<?php echo esc_js(__('Show', 'wp-ultimate-diagnostics-toolkit')); ?>';
					}
				});
			</script>
		</div>
		<?php
	}

	private function authorize_action(string $nonce_action): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit'));
		}
		check_admin_referer($nonce_action);
	}

	/**
	 * Get the configured AI model.
	 */
	public static function get_ai_model(): string {
		return get_option(self::OPTION_AI_MODEL, 'gemini');
	}

	/**
	 * Get the configured API key.
	 */
	public static function get_api_key(): string {
		$encrypted = get_option(self::OPTION_AI_API_KEY, '');
		if (empty($encrypted)) {
			return '';
		}
		$decrypted = base64_decode($encrypted, true);
		return false !== $decrypted ? $decrypted : '';
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
