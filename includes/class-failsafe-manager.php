<?php
/**
 * Failsafe manager for critical error resilience.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Failsafe_Manager {
	private const FATAL_OPTION = 'wudt_last_fatal_error';
	private const MODE_OPTION  = 'wudt_emergency_mode';

	public function register_hooks(): void {
		register_shutdown_function(array($this, 'capture_fatal_error'));
		add_action('admin_init', array($this, 'handle_emergency_toggle'));
		add_action('admin_notices', array($this, 'render_admin_notice'));
	}

	public function is_emergency_mode(): bool {
		if (isset($_GET['wudt_emergency']) && '1' === (string) wp_unslash($_GET['wudt_emergency'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return (bool) get_option(self::MODE_OPTION, false);
	}

	public function capture_fatal_error(): void {
		$error = error_get_last();
		if (! is_array($error)) {
			return;
		}
		$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (! in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
			return;
		}
		update_option(
			self::FATAL_OPTION,
			array(
				'time'    => current_time('mysql'),
				'message' => (string) ($error['message'] ?? ''),
				'file'    => (string) ($error['file'] ?? ''),
				'line'    => (int) ($error['line'] ?? 0),
			),
			false
		);
	}

	public function handle_emergency_toggle(): void {
		if (! is_admin() || ! current_user_can('manage_options')) {
			return;
		}
		
		// Handle dismiss error action
		if (isset($_GET['wudt_dismiss_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer('wudt_dismiss_error_nonce');
			delete_option(self::FATAL_OPTION);
			wp_safe_redirect(remove_query_arg(array('_wpnonce', 'wudt_dismiss_error')));
			exit;
		}
		
		// Handle emergency mode toggle
		if (! isset($_GET['wudt_set_emergency'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		check_admin_referer('wudt_toggle_emergency');
		$enabled = '1' === (string) wp_unslash($_GET['wudt_set_emergency']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		update_option(self::MODE_OPTION, $enabled);
		wp_safe_redirect(remove_query_arg(array('_wpnonce', 'wudt_set_emergency')));
		exit;
	}

	public function render_admin_notice(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		$fatal = get_option(self::FATAL_OPTION);
		if (! is_array($fatal) || empty($fatal['message'])) {
			return;
		}
		$mode      = $this->is_emergency_mode();
		$toggle_to = $mode ? '0' : '1';
		$toggle    = wp_nonce_url(
			add_query_arg('wudt_set_emergency', $toggle_to),
			'wudt_toggle_emergency'
		);
		$dismiss = wp_nonce_url(
			add_query_arg('wudt_dismiss_error', '1'),
			'wudt_dismiss_error_nonce'
		);
		
		// Format the error time
		$error_time = isset($fatal['time']) ? $fatal['time'] : '';
		$time_display = '';
		if ($error_time) {
			$timestamp = strtotime($error_time);
			if ($timestamp) {
				$time_display = human_time_diff($timestamp, current_time('timestamp')) . ' ago (' . $error_time . ')';
				$time_display = sprintf(
					/* translators: %s: Human readable time ago with timestamp */
					__('Error occurred: %s', 'wp-ultimate-diagnostics-toolkit'),
					$time_display
				);
			}
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php esc_html_e('WP Diagnostics detected a recent fatal error.', 'wp-ultimate-diagnostics-toolkit'); ?></strong></p>
			<?php if ($time_display) : ?>
				<p style="color: #646970; font-size: 12px; margin-bottom: 4px;"><?php echo esc_html($time_display); ?></p>
			<?php endif; ?>
			<p><?php echo esc_html((string) $fatal['message']); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url($toggle); ?>">
					<?php echo esc_html($mode ? __('Disable Emergency Mode', 'wp-ultimate-diagnostics-toolkit') : __('Enable Emergency Mode', 'wp-ultimate-diagnostics-toolkit')); ?>
				</a>
				<a class="button button-link" href="<?php echo esc_url($dismiss); ?>" style="color: #646970; margin-left: 8px;">
					<?php esc_html_e('Dismiss', 'wp-ultimate-diagnostics-toolkit'); ?> ✕
				</a>
			</p>
		</div>
		<?php
	}
}
