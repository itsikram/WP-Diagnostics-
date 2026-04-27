<?php
/**
 * Pro admin page with professional operations suite tabs.
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WP_Error;
use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Pro_Admin_Page {
	/**
	 * @var array<int,Module_Base>
	 */
	private array $modules;

	/**
	 * @param array<int,Module_Base> $modules Modules.
	 */
	public function __construct(array $modules) {
		$this->modules = $modules;
	}

	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_wudt_pro_refresh_dashboard', array($this, 'ajax_refresh_dashboard'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('WP Diagnostics Pro', 'wp-ultimate-diagnostics-toolkit'),
			__('WP Diagnostics Pro', 'wp-ultimate-diagnostics-toolkit'),
			'manage_options',
			'wudt-diagnostics-pro',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		$page = isset($_GET['page']) ? sanitize_text_field((string) wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ('wudt-diagnostics_page_wudt-diagnostics-pro' !== $hook && 'wudt-diagnostics-pro' !== $page) {
			return;
		}
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array(), WUDT_VERSION);
		wp_enqueue_script('wudt-pro-admin', WUDT_PLUGIN_URL . 'assets/js/pro-admin.js', array('jquery'), WUDT_VERSION, true);
		$data = array(
			'generated_at' => current_time('mysql'),
			'tabs'         => array(),
		);
		try {
			$data = $this->get_full_report();
		} catch (\Throwable $e) {
			$data = array(
				'generated_at' => current_time('mysql'),
				'tabs'         => array(),
				'error'        => $e->getMessage(),
			);
		}
		wp_localize_script(
			'wudt-pro-admin',
			'wudtProAdmin',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('wudt_admin_nonce'),
				'data'    => $data,
			)
		);
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-ultimate-diagnostics-toolkit'));
		}
		?>
		<div class="wrap wudt-wrap">
			<h1><?php esc_html_e('WP Diagnostics Pro - Admin Operations Suite', 'wp-ultimate-diagnostics-toolkit'); ?></h1>
			<p><?php esc_html_e('Advanced file operations, database manager, malware scanner, logs, performance and security controls.', 'wp-ultimate-diagnostics-toolkit'); ?></p>
			<div id="wudt-pro-admin-app"></div>
		</div>
		<?php
	}

	public function ajax_refresh_dashboard(): void {
		$this->check_permissions();
		wp_send_json_success($this->get_full_report());
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_full_report(): array {
		$tabs = array();
		foreach ($this->modules as $module) {
			$tabs[] = array(
				'key'   => $module->get_key(),
				'label' => $module->get_label(),
				'data'  => $module->get_dashboard_data(),
			);
		}
		return array(
			'generated_at' => current_time('mysql'),
			'tabs'         => $tabs,
		);
	}

	private function check_permissions(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(new WP_Error('forbidden', __('Insufficient permissions.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
	}
}
