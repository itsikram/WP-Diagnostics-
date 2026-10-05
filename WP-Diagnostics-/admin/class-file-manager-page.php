<?php
/**
 * File manager dedicated page.
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class File_Manager_Page {
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('File Manager', 'diagnostics-toolkit'),
			__('File Manager', 'diagnostics-toolkit'),
			'manage_options',
			'wudt-file-manager',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		$page = isset($_GET['page']) ? sanitize_text_field((string) wp_unslash($_GET['page'])) : '';
		if ('wp-diagnostics_page_wudt-file-manager' !== $hook && 'wudt-file-manager' !== $page) {
			return;
		}
		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);
		wp_enqueue_script('wudt-pro-admin', WUDT_PLUGIN_URL . 'assets/js/pro-admin.js', array('jquery'), WUDT_VERSION, true);
		wp_localize_script('wudt-pro-admin', 'wudtProAdmin', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wudt_admin_nonce'),
			'data'    => array('generated_at' => current_time('mysql'), 'tabs' => array()),
			'defaultTab' => 'file_manager',
			'diagnosticsUrl' => admin_url('admin.php?page=wp-ultimate-diagnostics'),
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}
		echo '<div class="wudt-ai-fullscreen"><div id="wudt-pro-admin-app"></div></div>';
	}
}
