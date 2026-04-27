<?php
/**
 * SMTP Module - Configure and manage SMTP settings for WordPress emails.
 */

declare(strict_types=1);

namespace WUDT\Modules\SMTP;

use WUDT\Includes\Module_Base;
use PHPMailer\PHPMailer\PHPMailer;

if (! defined('ABSPATH')) {
	exit;
}

class SMTP_Module extends Module_Base {
	// Option keys for storing SMTP settings
	private const OPTION_SMTP_ENABLED = 'wudt_smtp_enabled';
	private const OPTION_SMTP_HOST = 'wudt_smtp_host';
	private const OPTION_SMTP_PORT = 'wudt_smtp_port';
	private const OPTION_SMTP_ENCRYPTION = 'wudt_smtp_encryption';
	private const OPTION_SMTP_AUTH = 'wudt_smtp_auth';
	private const OPTION_SMTP_USER = 'wudt_smtp_user';
	private const OPTION_SMTP_PASS = 'wudt_smtp_pass';
	private const OPTION_SMTP_FROM_EMAIL = 'wudt_smtp_from_email';
	private const OPTION_SMTP_FROM_NAME = 'wudt_smtp_from_name';

	public function register_hooks(): void {
		// Configure PHPMailer with SMTP settings
		add_action('phpmailer_init', array($this, 'configure_phpmailer'));
		
		// Override wp_mail_from and wp_mail_from_name
		add_filter('wp_mail_from', array($this, 'get_from_email'));
		add_filter('wp_mail_from_name', array($this, 'get_from_name'));
		
		// AJAX handlers
		add_action('wp_ajax_wudt_save_smtp_settings', array($this, 'ajax_save_settings'));
		add_action('wp_ajax_wudt_send_test_email', array($this, 'ajax_send_test_email'));
		add_action('wp_ajax_wudt_get_smtp_settings', array($this, 'ajax_get_settings'));
	}

	public function get_key(): string {
		return 'smtp';
	}

	public function get_label(): string {
		return __('SMTP Mail', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'enabled'      => $this->is_enabled(),
			'host'         => get_option(self::OPTION_SMTP_HOST, ''),
			'port'         => (int) get_option(self::OPTION_SMTP_PORT, 587),
			'encryption'   => get_option(self::OPTION_SMTP_ENCRYPTION, 'tls'),
			'auth'         => (bool) get_option(self::OPTION_SMTP_AUTH, true),
			'user'         => get_option(self::OPTION_SMTP_USER, ''),
			'pass_set'     => ! empty(get_option(self::OPTION_SMTP_PASS, '')),
			'from_email'   => $this->get_from_email(),
			'from_name'    => $this->get_from_name(),
			'mail_test'    => $this->test_smtp_connection(),
		);
	}

	/**
	 * Configure PHPMailer with SMTP settings
	 */
	public function configure_phpmailer(PHPMailer $phpmailer): void {
		if (! $this->is_enabled()) {
			return;
		}

		$host = get_option(self::OPTION_SMTP_HOST, '');
		$port = (int) get_option(self::OPTION_SMTP_PORT, 587);
		$encryption = get_option(self::OPTION_SMTP_ENCRYPTION, 'tls');
		$auth = (bool) get_option(self::OPTION_SMTP_AUTH, true);

		if (empty($host)) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $host;
		$phpmailer->Port = $port;
		$phpmailer->SMTPAuth = $auth;

		if ($auth) {
			$phpmailer->Username = get_option(self::OPTION_SMTP_USER, '');
			$pass = get_option(self::OPTION_SMTP_PASS, '');
			if (! empty($pass)) {
				$phpmailer->Password = $this->decrypt_password($pass);
			}
		}

		// Set encryption
		if ($encryption === 'tls') {
			$phpmailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		} elseif ($encryption === 'ssl') {
			$phpmailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		} else {
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		// Enable debug mode for troubleshooting (only in admin)
		if (is_admin() && defined('WP_DEBUG') && WP_DEBUG) {
			$phpmailer->SMTPDebug = 2;
			$phpmailer->Debugoutput = 'error_log';
		}
	}

	/**
	 * Get from email address
	 */
	public function get_from_email(): string {
		$from_email = get_option(self::OPTION_SMTP_FROM_EMAIL, '');
		if (! empty($from_email) && is_email($from_email)) {
			return $from_email;
		}
		return get_option('admin_email');
	}

	/**
	 * Get from name
	 */
	public function get_from_name(): string {
		$from_name = get_option(self::OPTION_SMTP_FROM_NAME, '');
		if (! empty($from_name)) {
			return $from_name;
		}
		return get_option('blogname', 'WordPress');
	}

	/**
	 * Check if SMTP is enabled
	 */
	public function is_enabled(): bool {
		return (bool) get_option(self::OPTION_SMTP_ENABLED, false);
	}

	/**
	 * AJAX: Save SMTP settings
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit')), 403);
			return;
		}

		$enabled = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
		$host = isset($_POST['host']) ? sanitize_text_field((string) wp_unslash($_POST['host'])) : '';
		$port = isset($_POST['port']) ? (int) wp_unslash($_POST['port']) : 587;
		$encryption = isset($_POST['encryption']) ? sanitize_text_field((string) wp_unslash($_POST['encryption'])) : 'tls';
		$auth = isset($_POST['auth']) && '1' === (string) wp_unslash($_POST['auth']);
		$user = isset($_POST['user']) ? sanitize_text_field((string) wp_unslash($_POST['user'])) : '';
		$pass = isset($_POST['pass']) ? (string) wp_unslash($_POST['pass']) : '';
		$from_email = isset($_POST['from_email']) ? sanitize_email((string) wp_unslash($_POST['from_email'])) : '';
		$from_name = isset($_POST['from_name']) ? sanitize_text_field((string) wp_unslash($_POST['from_name'])) : '';

		// Validate
		if ($enabled && empty($host)) {
			wp_send_json_error(array('message' => __('SMTP Host is required when SMTP is enabled.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		if ($enabled && $auth && (empty($user) || empty($pass))) {
			wp_send_json_error(array('message' => __('SMTP Username and Password are required when authentication is enabled.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		// Save settings
		update_option(self::OPTION_SMTP_ENABLED, $enabled, false);
		update_option(self::OPTION_SMTP_HOST, $host, false);
		update_option(self::OPTION_SMTP_PORT, max(1, min(65535, $port)), false);
		update_option(self::OPTION_SMTP_ENCRYPTION, in_array($encryption, array('tls', 'ssl', 'none'), true) ? $encryption : 'tls', false);
		update_option(self::OPTION_SMTP_AUTH, $auth, false);
		update_option(self::OPTION_SMTP_USER, $user, false);
		
		// Only update password if provided
		if (! empty($pass) && $pass !== str_repeat('*', 10)) {
			update_option(self::OPTION_SMTP_PASS, $this->encrypt_password($pass), false);
		}
		
		update_option(self::OPTION_SMTP_FROM_EMAIL, $from_email, false);
		update_option(self::OPTION_SMTP_FROM_NAME, $from_name, false);

		// Test connection
		$test_result = $this->test_smtp_connection();

		wp_send_json_success(array(
			'message'    => __('Settings saved successfully.', 'wp-ultimate-diagnostics-toolkit'),
			'test_result' => $test_result,
		));
	}

	/**
	 * AJAX: Send test email
	 */
	public function ajax_send_test_email(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit')), 403);
			return;
		}

		$to = isset($_POST['to']) ? sanitize_email((string) wp_unslash($_POST['to'])) : '';
		if (empty($to) || ! is_email($to)) {
			wp_send_json_error(array('message' => __('Please enter a valid email address.', 'wp-ultimate-diagnostics-toolkit')), 400);
			return;
		}

		$subject = sprintf(__('Test Email from %s', 'wp-ultimate-diagnostics-toolkit'), get_bloginfo('name'));
		$message = sprintf(
			"This is a test email from WP Ultimate Diagnostics Toolkit.\n\n" .
			"SMTP Configuration:\n" .
			"- Host: %s\n" .
			"- Port: %d\n" .
			"- Encryption: %s\n" .
			"- From: %s <%s>\n\n" .
			"If you received this email, your SMTP configuration is working correctly!",
			get_option(self::OPTION_SMTP_HOST, ''),
			(int) get_option(self::OPTION_SMTP_PORT, 587),
			get_option(self::OPTION_SMTP_ENCRYPTION, 'tls'),
			$this->get_from_name(),
			$this->get_from_email()
		);

		add_action('wp_mail_failed', array($this, 'capture_mail_error'), 10, 1);
		
		$result = wp_mail($to, $subject, $message);
		
		remove_action('wp_mail_failed', array($this, 'capture_mail_error'), 10);

		if ($result) {
			wp_send_json_success(array(
				'message' => sprintf(__('Test email sent successfully to %s', 'wp-ultimate-diagnostics-toolkit'), $to),
			));
		} else {
			$error_message = $this->last_mail_error ?: __('Unknown error occurred.', 'wp-ultimate-diagnostics-toolkit');
			wp_send_json_error(array('message' => $error_message), 500);
		}
	}

	private ?string $last_mail_error = null;

	public function capture_mail_error($error): void {
		if (is_wp_error($error)) {
			$this->last_mail_error = $error->get_error_message();
		}
	}

	/**
	 * AJAX: Get current SMTP settings
	 */
	public function ajax_get_settings(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit')), 403);
			return;
		}

		wp_send_json_success($this->get_dashboard_data());
	}

	/**
	 * Test SMTP connection
	 */
	private function test_smtp_connection(): array {
		if (! $this->is_enabled()) {
			return array(
				'success' => false,
				'message' => __('SMTP is not enabled.', 'wp-ultimate-diagnostics-toolkit'),
			);
		}

		$host = get_option(self::OPTION_SMTP_HOST, '');
		$port = (int) get_option(self::OPTION_SMTP_PORT, 587);
		
		if (empty($host)) {
			return array(
				'success' => false,
				'message' => __('SMTP host is not configured.', 'wp-ultimate-diagnostics-toolkit'),
			);
		}

		// Try to connect
		$connection = @fsockopen($host, $port, $errno, $errstr, 10);
		
		if ($connection) {
			fclose($connection);
			return array(
				'success' => true,
				'message' => sprintf(__('Successfully connected to %s:%d', 'wp-ultimate-diagnostics-toolkit'), $host, $port),
			);
		} else {
			return array(
				'success' => false,
				'message' => sprintf(__('Failed to connect to %s:%d - %s', 'wp-ultimate-diagnostics-toolkit'), $host, $port, $errstr ?: __('Connection timeout', 'wp-ultimate-diagnostics-toolkit')),
			);
		}
	}

	/**
	 * Simple encryption for password storage
	 */
	private function encrypt_password(string $password): string {
		// Use WordPress salt if available for better security
		$key = defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : 'wudt-smtp-key';
		return base64_encode(openssl_encrypt($password, 'AES-128-ECB', $key, OPENSSL_RAW_DATA));
	}

	/**
	 * Decrypt stored password
	 */
	private function decrypt_password(string $encrypted): string {
		$key = defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : 'wudt-smtp-key';
		$decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
		return false !== $decrypted ? $decrypted : '';
	}
}
