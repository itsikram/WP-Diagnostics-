<?php
/**
 * Dedicated backups page under Tools.
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class Backup_Page {
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_management_page(
			__('Diagnostics Backups', 'diagnostics-toolkit'),
			__('Diagnostics Backups', 'diagnostics-toolkit'),
			'manage_options',
			'wudt-diagnostics-backups',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('tools_page_wudt-diagnostics-backups' !== $hook) {
			return;
		}
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array(), WUDT_VERSION);
		wp_enqueue_script('wudt-pro-admin', WUDT_PLUGIN_URL . 'assets/js/pro-admin.js', array('jquery'), WUDT_VERSION, true);
		wp_localize_script('wudt-pro-admin', 'wudtProAdmin', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wudt_admin_nonce'),
			'data'    => array('generated_at' => current_time('mysql'), 'tabs' => array()),
			'defaultTab' => 'backup_suite',
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}
		echo '<div class="wudt-fullscreen-page"><div style="padding: 20px;"><h1>' . esc_html__('Tools -> Diagnostics -> Backups', 'diagnostics-toolkit') . '</h1><div id="wudt-pro-admin-app"></div></div></div>';
	}
}
