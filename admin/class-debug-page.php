<?php
/**
 * Dedicated debug page for WP_DEBUG controls and log viewer.
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class Debug_Page {
	private const OPTION_RUNTIME_DEBUG = 'wudt_runtime_debug_enabled';

	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_post_wudt_toggle_runtime_debug', array($this, 'handle_toggle_runtime_debug'));
		add_action('admin_post_wudt_clear_debug_log', array($this, 'handle_clear_debug_log'));
		add_action('init', array($this, 'apply_runtime_debug_mode'), 1);
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('WP Debug & Logs', 'wp-ultimate-diagnostics-toolkit'),
			__('WP Debug & Logs', 'wp-ultimate-diagnostics-toolkit'),
			'manage_options',
			'wudt-debug-logs',
			array($this, 'render_page')
		);
	}

	public function apply_runtime_debug_mode(): void {
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

		if (! (bool) get_option(self::OPTION_RUNTIME_DEBUG, false)) {
			return;
		}
		// Fallback runtime debug mode when constants cannot be changed dynamically.
		error_reporting(E_ALL);
		ini_set('display_errors', '0');
		ini_set('log_errors', '1');
		ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
	}

	public function handle_toggle_runtime_debug(): void {
		$this->authorize_action('wudt_toggle_runtime_debug');
		$enabled = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
		update_option(self::OPTION_RUNTIME_DEBUG, $enabled, false);
		$this->redirect_back();
	}

	public function handle_clear_debug_log(): void {
		$this->authorize_action('wudt_clear_debug_log');
		$debug_file = WP_CONTENT_DIR . '/debug.log';
		if (file_exists($debug_file) && is_writable($debug_file)) {
			file_put_contents($debug_file, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$this->redirect_back();
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$runtime_debug = (bool) get_option(self::OPTION_RUNTIME_DEBUG, false);
		$lines         = $this->read_debug_log_lines();
		?>
		<div class="wudt-fullscreen-page">
			<div style="padding: 20px; overflow-y: auto;">
				<h1><?php esc_html_e('WP Debug & Logs', 'wp-ultimate-diagnostics-toolkit'); ?></h1>
				<p><?php esc_html_e('Manage runtime debugging and inspect debug.log quickly.', 'wp-ultimate-diagnostics-toolkit'); ?></p>

				<div class="wudt-card" style="max-width: 860px;">
					<h2><?php esc_html_e('Debug Mode Controls', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
					<p>
						<?php esc_html_e('WP_DEBUG constant:', 'wp-ultimate-diagnostics-toolkit'); ?>
						<strong><?php echo defined('WP_DEBUG') && WP_DEBUG ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?></strong>
					</p>
					<p>
						<?php esc_html_e('WP_DEBUG_LOG constant:', 'wp-ultimate-diagnostics-toolkit'); ?>
						<strong><?php echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?></strong>
					</p>
					<p>
						<?php esc_html_e('Runtime debug fallback:', 'wp-ultimate-diagnostics-toolkit'); ?>
						<strong><?php echo $runtime_debug ? esc_html__('Enabled', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Disabled', 'wp-ultimate-diagnostics-toolkit'); ?></strong>
					</p>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; flex-wrap:wrap;">
						<input type="hidden" name="action" value="wudt_toggle_runtime_debug" />
						<input type="hidden" name="enabled" value="<?php echo $runtime_debug ? '0' : '1'; ?>" />
						<?php wp_nonce_field('wudt_toggle_runtime_debug'); ?>
						<button type="submit" class="button button-primary">
							<?php echo $runtime_debug ? esc_html__('Disable Runtime Debug', 'wp-ultimate-diagnostics-toolkit') : esc_html__('Enable Runtime Debug', 'wp-ultimate-diagnostics-toolkit'); ?>
						</button>
					</form>
					<p class="description">
						<?php esc_html_e('Runtime debug mode enables PHP error logging to wp-content/debug.log without editing wp-config.php.', 'wp-ultimate-diagnostics-toolkit'); ?>
					</p>
				</div>

				<div class="wudt-card" style="margin-top: 16px;">
					<div class="wudt-toolbar">
						<h2 style="margin:0;"><?php esc_html_e('debug.log (latest entries)', 'wp-ultimate-diagnostics-toolkit'); ?></h2>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="wudt_clear_debug_log" />
							<?php wp_nonce_field('wudt_clear_debug_log'); ?>
							<button type="submit" class="button"><?php esc_html_e('Clear debug.log', 'wp-ultimate-diagnostics-toolkit'); ?></button>
						</form>
					</div>
					<pre class="wudt-pre" style="max-height:600px;"><?php echo esc_html(implode("\n", $lines)); ?></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function read_debug_log_lines(): array {
		$debug_file = WP_CONTENT_DIR . '/debug.log';
		if (! file_exists($debug_file)) {
			return array(__('debug.log not found in wp-content.', 'wp-ultimate-diagnostics-toolkit'));
		}
		if (! is_readable($debug_file)) {
			return array(__('debug.log is not readable.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$content = file($debug_file, FILE_IGNORE_NEW_LINES);
		if (! is_array($content)) {
			return array(__('Could not read debug.log.', 'wp-ultimate-diagnostics-toolkit'));
		}
		if (empty($content)) {
			return array(__('debug.log is currently empty.', 'wp-ultimate-diagnostics-toolkit'));
		}
		return array_slice($content, -500);
	}

	private function authorize_action(string $nonce_action): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit'));
		}
		check_admin_referer($nonce_action);
	}

	private function redirect_back(): void {
		wp_safe_redirect(admin_url('admin.php?page=wudt-debug-logs'));
		exit;
	}
}
