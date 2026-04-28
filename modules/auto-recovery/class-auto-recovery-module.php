<?php
/**
 * Auto Recovery Module - Automatic 500 error detection and recovery
 * Detects fatal errors from logs, identifies source plugin/theme,
 * and automatically deactivates them while protecting WUDT itself.
 */

declare(strict_types=1);

namespace WUDT\Modules\AutoRecovery;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Auto_Recovery_Module extends Module_Base {
	private const OPTION_RECOVERY_LOG = 'wudt_auto_recovery_log';
	private const OPTION_SAFE_MODE = 'wudt_safe_mode_active';
	private const OPTION_LAST_CHECK = 'wudt_auto_recovery_last_check';
	private const OPTION_DISABLED_PLUGINS = 'wudt_auto_recovery_disabled_plugins';
	private const OPTION_DISABLED_THEME = 'wudt_auto_recovery_disabled_theme';
	private const RECOVERY_COOKIE = 'wudt_recovery_check';
	
	private string $plugin_file;
	private string $plugin_folder;

	public function __construct() {
		$this->plugin_file = plugin_basename(WUDT_PLUGIN_FILE);
		$this->plugin_folder = basename(WUDT_PLUGIN_DIR);
	}

	public function register_hooks(): void {
		// Early hook to catch fatal errors during startup
		add_action('muplugins_loaded', array($this, 'early_fatal_check'), 1);
		add_action('plugins_loaded', array($this, 'check_and_recover'), 1);
		add_action('shutdown', array($this, 'shutdown_error_handler'));
		
		// Admin notices for recovery actions
		add_action('admin_notices', array($this, 'render_recovery_notices'));
		
		// AJAX handlers for manual recovery actions
		add_action('wp_ajax_wudt_restore_plugin', array($this, 'ajax_restore_plugin'));
		add_action('wp_ajax_wudt_restore_theme', array($this, 'ajax_restore_theme'));
		add_action('wp_ajax_wudt_disable_safe_mode', array($this, 'ajax_disable_safe_mode'));
		
		// Add safe mode indicator to admin bar
		add_action('admin_bar_menu', array($this, 'add_safe_mode_indicator'), 100);
	}

	public function get_key(): string {
		return 'auto_recovery';
	}

	public function get_label(): string {
		return __('Auto Recovery', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'safe_mode_active' => $this->is_safe_mode_active(),
			'recovery_log' => $this->get_recovery_log(),
			'disabled_plugins' => get_option(self::OPTION_DISABLED_PLUGINS, array()),
			'disabled_theme' => get_option(self::OPTION_DISABLED_THEME, ''),
		);
	}

	/**
	 * Check if safe mode is active
	 */
	public function is_safe_mode_active(): bool {
		return (bool) get_option(self::OPTION_SAFE_MODE, false);
	}

	/**
	 * Early check before plugins load to catch startup fatal errors
	 */
	public function early_fatal_check(): void {
		$this->scan_error_logs();
	}

	/**
	 * Main recovery check - runs after plugins are loaded
	 */
	public function check_and_recover(): void {
		// Don't run if we're in the middle of a recovery action
		if ($this->is_recovery_request()) {
			return;
		}

		// Check if we need to enter safe mode for WUDT
		if ($this->should_enter_safe_mode()) {
			$this->enter_safe_mode();
			return;
		}

		// Scan error logs for new errors
		$this->scan_error_logs();
	}

	/**
	 * Shutdown handler for catching fatal errors
	 */
	public function shutdown_error_handler(): void {
		$error = error_get_last();
		if (! is_array($error)) {
			return;
		}

		$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (! in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
			return;
		}

		// Log the fatal error for next request recovery
		$this->log_fatal_for_recovery($error);
	}

	/**
	 * Scan various error log sources for 500/fatal errors
	 */
	private function scan_error_logs(): void {
		$last_check = (int) get_option(self::OPTION_LAST_CHECK, 0);
		$current_time = time();
		
		// Only check every 30 seconds to avoid excessive I/O
		if (($current_time - $last_check) < 30) {
			return;
		}
		
		update_option(self::OPTION_LAST_CHECK, $current_time, false);

		$errors = array_merge(
			$this->scan_php_error_log(),
			$this->scan_wordpress_debug_log(),
			$this->get_logged_fatal_errors()
		);

		foreach ($errors as $error) {
			$this->process_error($error);
		}
	}

	/**
	 * Scan PHP error log for fatal errors
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_php_error_log(): array {
		$errors = array();
		$error_log = ini_get('error_log');
		
		if (empty($error_log) || ! file_exists($error_log) || ! is_readable($error_log)) {
			return $errors;
		}

		$lines = $this->tail_file($error_log, 100);
		foreach ($lines as $line) {
			if ($this->is_fatal_error_line($line)) {
				$parsed = $this->parse_error_line($line);
				if ($parsed) {
					$errors[] = $parsed;
				}
			}
		}
		
		return $errors;
	}

	/**
	 * Scan WordPress debug.log for errors
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_wordpress_debug_log(): array {
		$errors = array();
		$debug_log = WP_CONTENT_DIR . '/debug.log';
		
		if (! file_exists($debug_log) || ! is_readable($debug_log)) {
			return $errors;
		}

		$lines = $this->tail_file($debug_log, 100);
		foreach ($lines as $line) {
			if ($this->is_fatal_error_line($line)) {
				$parsed = $this->parse_error_line($line);
				if ($parsed) {
					$errors[] = $parsed;
				}
			}
		}
		
		return $errors;
	}

	/**
	 * Get fatal errors logged from shutdown handler
	 * @return array<int,array<string,mixed>>
	 */
	private function get_logged_fatal_errors(): array {
		$fatal_errors = get_option('wudt_pending_fatal_errors', array());
		delete_option('wudt_pending_fatal_errors');
		return is_array($fatal_errors) ? $fatal_errors : array();
	}

	/**
	 * Read last N lines from a file
	 * @return array<int,string>
	 */
	private function tail_file(string $file, int $lines): array {
		if (! file_exists($file) || ! is_readable($file)) {
			return array();
		}

		$content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (! is_array($content)) {
			return array();
		}

		return array_slice($content, -$lines);
	}

	/**
	 * Check if error line contains fatal/500 error
	 */
	private function is_fatal_error_line(string $line): bool {
		$fatal_keywords = array(
			'fatal error',
			'parse error',
			'syntax error',
			'uncaught exception',
			'500 internal server error',
			'call to undefined function',
			'call to undefined method',
			'class not found',
			'failed to open stream',
			'cannot redeclare',
		);
		
		$line_lower = strtolower($line);
		foreach ($fatal_keywords as $keyword) {
			if (str_contains($line_lower, $keyword)) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Parse error line to extract error details
	 * @return array<string,mixed>|null
	 */
	private function parse_error_line(string $line): ?array {
		// Pattern: [date] PHP Error Type: message in file on line N
		if (preg_match('/\[(\d{4}-\d{2}-\d{2}[^\]]*)\]\s*PHP\s+(\w+\s+error):\s*(.+?)\s+in\s+(.+?)\s+on\s+line\s+(\d+)/i', $line, $matches)) {
			return array(
				'timestamp' => strtotime($matches[1]),
				'type' => $matches[2],
				'message' => $matches[3],
				'file' => $matches[4],
				'line' => (int) $matches[5],
				'source' => 'php_error_log',
			);
		}
		
		// WordPress debug.log pattern
		if (preg_match('/\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(.+)/', $line, $matches)) {
			$message = $matches[2];
			// Try to extract file path from message
			$file = '';
			$line_num = 0;
			
			if (preg_match('/in\s+(.+?)\s+on\s+line\s+(\d+)/i', $message, $file_matches)) {
				$file = $file_matches[1];
				$line_num = (int) $file_matches[2];
			} elseif (preg_match('/([\/\w\-]+\.php)/', $message, $file_matches)) {
				$file = $file_matches[1];
			}
			
			return array(
				'timestamp' => strtotime($matches[1]),
				'type' => 'fatal_error',
				'message' => $message,
				'file' => $file,
				'line' => $line_num,
				'source' => 'debug_log',
			);
		}
		
		return null;
	}

	/**
	 * Process an error and determine recovery action
	 * @param array<string,mixed> $error
	 */
	private function process_error(array $error): void {
		$file = (string) ($error['file'] ?? '');
		if (empty($file)) {
			return;
		}

		// Normalize path
		$file = wp_normalize_path($file);
		
		// Check if error is from a plugin
		if (str_contains($file, '/plugins/')) {
			$this->handle_plugin_error($error, $file);
			return;
		}
		
		// Check if error is from a theme
		if (str_contains($file, '/themes/')) {
			$this->handle_theme_error($error, $file);
			return;
		}
	}

	/**
	 * Handle plugin-related fatal error
	 * @param array<string,mixed> $error
	 */
	private function handle_plugin_error(array $error, string $file): void {
		$plugin = $this->identify_plugin_from_path($file);
		
		if (empty($plugin)) {
			return;
		}

		// Never deactivate this plugin
		if ($plugin === $this->plugin_file || str_contains($plugin, $this->plugin_folder)) {
			$this->log_recovery_event('wudt_fatal_detected', $error, 'wudt');
			// Schedule safe mode entry for next request
			update_option('wudt_pending_safe_mode', true, false);
			return;
		}

		// Check if plugin is already deactivated
		if (! $this->is_plugin_active($plugin)) {
			return;
		}

		// Calculate confidence score
		$confidence = $this->calculate_confidence($error);
		
		if ($confidence >= 70) {
			$this->deactivate_plugin($plugin, $error, $confidence);
		} else {
			$this->log_recovery_event('low_confidence_detected', $error, $plugin, $confidence);
		}
	}

	/**
	 * Handle theme-related fatal error
	 * @param array<string,mixed> $error
	 */
	private function handle_theme_error(array $error, string $file): void {
		$theme = $this->identify_theme_from_path($file);
		
		if (empty($theme)) {
			return;
		}

		$current_theme = get_option('stylesheet');
		if ($theme !== $current_theme) {
			return;
		}

		$confidence = $this->calculate_confidence($error);
		
		if ($confidence >= 70) {
			$this->switch_to_default_theme($theme, $error, $confidence);
		}
	}

	/**
	 * Identify plugin from file path
	 */
	private function identify_plugin_from_path(string $file): string {
		$marker = '/plugins/';
		$pos = strpos($file, $marker);
		if (false === $pos) {
			return '';
		}

		$rel = substr($file, $pos + strlen($marker));
		$parts = explode('/', $rel);
		$slug = $parts[0] ?? '';
		
		if (empty($slug)) {
			return '';
		}

		// Find the actual plugin file
		$active = (array) get_option('active_plugins', array());
		
		// Check for main plugin file pattern
		$candidate = $slug . '/' . $slug . '.php';
		if (in_array($candidate, $active, true)) {
			return $candidate;
		}
		
		// Check any file in the plugin folder
		foreach ($active as $plugin) {
			if (str_starts_with((string) $plugin, $slug . '/')) {
				return (string) $plugin;
			}
		}

		return '';
	}

	/**
	 * Identify theme from file path
	 */
	private function identify_theme_from_path(string $file): string {
		$marker = '/themes/';
		$pos = strpos($file, $marker);
		if (false === $pos) {
			return '';
		}

		$rel = substr($file, $pos + strlen($marker));
		$parts = explode('/', $rel);
		
		return $parts[0] ?? '';
	}

	/**
	 * Check if plugin is active
	 */
	private function is_plugin_active(string $plugin): bool {
		$active = (array) get_option('active_plugins', array());
		return in_array($plugin, $active, true);
	}

	/**
	 * Calculate confidence score for error source
	 * @param array<string,mixed> $error
	 */
	private function calculate_confidence(array $error): int {
		$score = 50;
		$message = strtolower((string) ($error['message'] ?? ''));
		$file = (string) ($error['file'] ?? '');
		
		// High confidence indicators
		if (str_contains($message, 'fatal error')) {
			$score += 20;
		}
		if (str_contains($message, 'parse error') || str_contains($message, 'syntax error')) {
			$score += 25;
		}
		if (str_contains($message, 'uncaught exception')) {
			$score += 15;
		}
		if (str_contains($file, '/plugins/') || str_contains($file, '/themes/')) {
			$score += 10;
		}
		
		// Low confidence indicators (reduce score)
		if (str_contains($message, 'memory size') || str_contains($message, 'memory exhausted')) {
			$score -= 20;
		}
		if (str_contains($message, 'maximum execution time')) {
			$score -= 15;
		}
		
		return max(0, min(100, $score));
	}

	/**
	 * Deactivate a problematic plugin
	 * @param array<string,mixed> $error
	 */
	private function deactivate_plugin(string $plugin, array $error, int $confidence): void {
		if (! function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		
		deactivate_plugins($plugin, true); // true = silent
		
		// Store for potential restoration
		$disabled = (array) get_option(self::OPTION_DISABLED_PLUGINS, array());
		$disabled[$plugin] = array(
			'time' => current_time('mysql'),
			'error' => $error,
			'confidence' => $confidence,
		);
		update_option(self::OPTION_DISABLED_PLUGINS, $disabled, false);
		
		$this->log_recovery_event('auto_deactivated', $error, $plugin, $confidence);
		Operation_Logger::log(
			'auto_recovery',
			'Auto-deactivated crashing plugin',
			array('plugin' => $plugin, 'confidence' => $confidence, 'error' => $error)
		);
	}

	/**
	 * Switch to default WordPress theme
	 * @param array<string,mixed> $error
	 */
	private function switch_to_default_theme(string $theme, array $error, int $confidence): void {
		$current_theme = get_option('stylesheet');
		
		// Find a default theme
		$default_themes = array('twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty');
		$new_theme = '';
		
		foreach ($default_themes as $default) {
			if (wp_get_theme($default)->exists()) {
				$new_theme = $default;
				break;
			}
		}
		
		if (empty($new_theme)) {
			return;
		}
		
		// Store current theme for restoration
		update_option(self::OPTION_DISABLED_THEME, array(
			'theme' => $current_theme,
			'time' => current_time('mysql'),
			'error' => $error,
			'confidence' => $confidence,
		), false);
		
		// Switch theme
		switch_theme($new_theme);
		
		$this->log_recovery_event('auto_theme_switch', $error, $theme, $confidence);
		Operation_Logger::log(
			'auto_recovery',
			'Auto-switched to default theme',
			array(
				'from_theme' => $current_theme,
				'to_theme' => $new_theme,
				'confidence' => $confidence,
				'error' => $error
			)
		);
	}

	/**
	 * Enter safe mode for WUDT itself
	 */
	private function enter_safe_mode(): void {
		update_option(self::OPTION_SAFE_MODE, true, false);
		delete_option('wudt_pending_safe_mode');
		
		$this->log_recovery_event('safe_mode_entered', array(
			'message' => 'WUDT detected fatal error in itself',
			'time' => current_time('mysql'),
		), 'wudt');
		
		Operation_Logger::log(
			'auto_recovery',
			'Entered safe mode due to internal fatal error',
			array('action' => 'safe_mode_enabled')
		);
	}

	/**
	 * Check if we should enter safe mode
	 */
	private function should_enter_safe_mode(): bool {
		return (bool) get_option('wudt_pending_safe_mode', false);
	}

	/**
	 * Check if current request is a recovery action
	 */
	private function is_recovery_request(): bool {
		$actions = array('wudt_restore_plugin', 'wudt_restore_theme', 'wudt_disable_safe_mode');
		
		foreach ($actions as $action) {
			if (isset($_REQUEST['action']) && $_REQUEST['action'] === $action) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Log fatal error for next request recovery
	 * @param array<string,mixed> $error
	 */
	private function log_fatal_for_recovery(array $error): void {
		$pending = (array) get_option('wudt_pending_fatal_errors', array());
		$pending[] = array(
			'timestamp' => time(),
			'type' => 'fatal',
			'message' => (string) ($error['message'] ?? ''),
			'file' => (string) ($error['file'] ?? ''),
			'line' => (int) ($error['line'] ?? 0),
		);
		
		// Keep only recent errors
		$pending = array_slice($pending, -10);
		update_option('wudt_pending_fatal_errors', $pending, false);
	}

	/**
	 * Log recovery event
	 * @param array<string,mixed> $error
	 * @param array<string,mixed>|null $data
	 */
	private function log_recovery_event(string $action, array $error, string $target = '', int $confidence = 0, ?array $data = null): void {
		$log = (array) get_option(self::OPTION_RECOVERY_LOG, array());
		
		$log[] = array(
			'time' => current_time('mysql'),
			'action' => $action,
			'target' => $target,
			'confidence' => $confidence,
			'error_message' => (string) ($error['message'] ?? ''),
			'error_file' => (string) ($error['file'] ?? ''),
			'error_line' => (int) ($error['line'] ?? 0),
			'data' => $data,
		);
		
		// Keep last 100 events
		$log = array_slice($log, -100);
		update_option(self::OPTION_RECOVERY_LOG, $log, false);
	}

	/**
	 * Get recovery log
	 * @return array<int,array<string,mixed>>
	 */
	private function get_recovery_log(): array {
		return (array) get_option(self::OPTION_RECOVERY_LOG, array());
	}

	/**
	 * Render admin notices for recovery actions
	 */
	public function render_recovery_notices(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		// Safe mode notice
		if ($this->is_safe_mode_active()) {
			$this->render_safe_mode_notice();
		}

		// Deactivated plugins notice
		$disabled_plugins = get_option(self::OPTION_DISABLED_PLUGINS, array());
		if (! empty($disabled_plugins)) {
			$this->render_plugin_recovery_notice($disabled_plugins);
		}

		// Switched theme notice
		$disabled_theme = get_option(self::OPTION_DISABLED_THEME, '');
		if (! empty($disabled_theme) && is_array($disabled_theme)) {
			$this->render_theme_recovery_notice($disabled_theme);
		}
	}

	/**
	 * Render safe mode notice
	 */
	private function render_safe_mode_notice(): void {
		$dismiss_url = wp_nonce_url(
			add_query_arg('wudt_disable_safe_mode', '1'),
			'wudt_disable_safe_mode'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php esc_html_e('WP Ultimate Diagnostics - Safe Mode Active', 'wp-ultimate-diagnostics-toolkit'); ?></strong></p>
			<p><?php esc_html_e('The plugin detected a fatal error in itself and entered safe mode. Advanced features are temporarily disabled.', 'wp-ultimate-diagnostics-toolkit'); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url($dismiss_url); ?>">
					<?php esc_html_e('Exit Safe Mode', 'wp-ultimate-diagnostics-toolkit'); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render plugin recovery notice
	 * @param array<string,array<string,mixed>> $disabled_plugins
	 */
	private function render_plugin_recovery_notice(array $disabled_plugins): void {
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php esc_html_e('WP Ultimate Diagnostics - Plugin Recovery', 'wp-ultimate-diagnostics-toolkit'); ?></strong></p>
			<p><?php esc_html_e('The following plugins were automatically deactivated due to fatal errors:', 'wp-ultimate-diagnostics-toolkit'); ?></p>
			<ul>
			<?php foreach ($disabled_plugins as $plugin => $data) : ?>
				<li>
					<code><?php echo esc_html($plugin); ?></code>
					<?php if (! empty($data['error']['message'])) : ?>
						<br><small><?php echo esc_html($data['error']['message']); ?></small>
					<?php endif; ?>
					<?php
					$restore_url = wp_nonce_url(
						add_query_arg(array('wudt_restore_plugin' => '1', 'plugin' => urlencode($plugin))),
						'wudt_restore_plugin_' . $plugin
					);
					?>
					<a href="<?php echo esc_url($restore_url); ?>" class="button button-small" style="margin-left: 10px;">
						<?php esc_html_e('Restore', 'wp-ultimate-diagnostics-toolkit'); ?>
					</a>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render theme recovery notice
	 * @param array<string,mixed> $disabled_theme
	 */
	private function render_theme_recovery_notice(array $disabled_theme): void {
		$theme_name = (string) ($disabled_theme['theme'] ?? 'Unknown');
		$error_message = (string) (($disabled_theme['error']['message'] ?? ''));
		
		$restore_url = wp_nonce_url(
			add_query_arg('wudt_restore_theme', '1'),
			'wudt_restore_theme'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php esc_html_e('WP Ultimate Diagnostics - Theme Recovery', 'wp-ultimate-diagnostics-toolkit'); ?></strong></p>
			<p>
				<?php 
				echo esc_html(
					sprintf(
						/* translators: %s: Theme name */
						__('The theme "%s" was automatically deactivated due to a fatal error. A default theme is now active.', 'wp-ultimate-diagnostics-toolkit'),
						$theme_name
					)
				); 
				?>
			</p>
			<?php if ($error_message) : ?>
				<p><code><?php echo esc_html($error_message); ?></code></p>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url($restore_url); ?>">
					<?php esc_html_e('Restore Previous Theme', 'wp-ultimate-diagnostics-toolkit'); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler to restore a plugin
	 */
	public function ajax_restore_plugin(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}

		$plugin = isset($_POST['plugin']) ? sanitize_text_field((string) wp_unslash($_POST['plugin'])) : '';
		
		if (empty($plugin)) {
			wp_send_json_error(array('message' => __('No plugin specified.', 'wp-ultimate-diagnostics-toolkit')));
		}

		if (! function_exists('activate_plugin')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin($plugin, '', false, true);
		
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		// Remove from disabled list
		$disabled = (array) get_option(self::OPTION_DISABLED_PLUGINS, array());
		unset($disabled[$plugin]);
		update_option(self::OPTION_DISABLED_PLUGINS, $disabled, false);
		
		$this->log_recovery_event('manual_restore', array('message' => 'Plugin manually restored'), $plugin);
		
		wp_send_json_success(array('message' => __('Plugin restored successfully.', 'wp-ultimate-diagnostics-toolkit')));
	}

	/**
	 * AJAX handler to restore theme
	 */
	public function ajax_restore_theme(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}

		$theme_data = get_option(self::OPTION_DISABLED_THEME, array());
		$theme = (string) ($theme_data['theme'] ?? '');
		
		if (empty($theme) || ! wp_get_theme($theme)->exists()) {
			wp_send_json_error(array('message' => __('Theme not found or invalid.', 'wp-ultimate-diagnostics-toolkit')));
		}

		switch_theme($theme);
		delete_option(self::OPTION_DISABLED_THEME);
		
		$this->log_recovery_event('manual_theme_restore', array('message' => 'Theme manually restored'), $theme);
		
		wp_send_json_success(array('message' => __('Theme restored successfully.', 'wp-ultimate-diagnostics-toolkit')));
	}

	/**
	 * AJAX handler to disable safe mode
	 */
	public function ajax_disable_safe_mode(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}

		delete_option(self::OPTION_SAFE_MODE);
		
		$this->log_recovery_event('safe_mode_exited', array('message' => 'Safe mode manually disabled'), 'wudt');
		
		wp_send_json_success(array('message' => __('Safe mode disabled. Advanced features are now enabled.', 'wp-ultimate-diagnostics-toolkit')));
	}

	/**
	 * Add safe mode indicator to admin bar
	 */
	public function add_safe_mode_indicator($wp_admin_bar): void {
		if (! $this->is_safe_mode_active() || ! is_admin_bar_showing()) {
			return;
		}

		$wp_admin_bar->add_node(array(
			'id' => 'wudt-safe-mode',
			'title' => '<span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">WUDT SAFE MODE</span>',
			'href' => admin_url('admin.php?page=wp-ultimate-diagnostics'),
			'meta' => array(
				'title' => __('WP Ultimate Diagnostics is in safe mode due to a detected fatal error', 'wp-ultimate-diagnostics-toolkit'),
			),
		));
	}
}
