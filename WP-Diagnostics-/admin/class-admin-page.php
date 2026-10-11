<?php
/**
 * Admin page rendering and common AJAX controller.
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WP_Admin_Bar;
use WP_Error;
use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Admin_Page {
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
		add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_wudt_refresh_dashboard', array($this, 'ajax_refresh_dashboard'));
		add_action('wp_ajax_wudt_export_report', array($this, 'ajax_export_report'));
		add_action('wp_ajax_wudt_email_report', array($this, 'ajax_email_report'));
	}

	public function add_admin_bar_menu(WP_Admin_Bar $admin_bar): void {
		if (! is_admin_bar_showing() || ! current_user_can('manage_options')) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'wudt-diagnostics',
				'title' => __('Diagnostics Toolkit', 'diagnostics-toolkit'),
				'href'  => admin_url('admin.php?page=wudt-diagnostics'),
			)
		);

		$tools = array(
			'admin-tools' => array(__('Admin Tools', 'diagnostics-toolkit'), 'admin.php?page=wudt-diagnostics-pro'),
			'site-migration' => array(__('Site Migration', 'diagnostics-toolkit'), 'admin.php?page=wudt-site-migration'),
			'ai-assistant' => array(__('AI Assistant', 'diagnostics-toolkit'), 'admin.php?page=wudt-ai-assistant'),
			'file-manager' => array(__('File Manager', 'diagnostics-toolkit'), 'admin.php?page=wudt-file-manager'),
			'backups'     => array(__('Backups', 'diagnostics-toolkit'), 'tools.php?page=wudt-diagnostics-backups'),
			'search'      => array(__('Search & Replace', 'diagnostics-toolkit'), 'admin.php?page=wudt-search'),
			'debug-logs'  => array(__('Debug & Logs', 'diagnostics-toolkit'), 'admin.php?page=wudt-debug-logs'),
			'settings'    => array(__('Settings', 'diagnostics-toolkit'), 'admin.php?page=wudt-settings'),
		);

		foreach ($tools as $id => $tool) {
			$admin_bar->add_node(
				array(
					'id'     => 'wudt-' . $id,
					'parent' => 'wudt-diagnostics',
					'title'  => $tool[0],
					'href'   => admin_url($tool[1]),
				)
			);
		}
	}

	public function register_menu(): void {
		add_menu_page(
			__('Diagnostics Toolkit', 'diagnostics-toolkit'),
			__('Diagnostics Toolkit', 'diagnostics-toolkit'),
			'manage_options',
			'wudt-diagnostics',
			array($this, 'render_page'),
			'dashicons-admin-tools',
			58
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('toplevel_page_wudt-diagnostics' !== $hook) {
			return;
		}

		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);
		wp_enqueue_script('wudt-ui-utils', WUDT_PLUGIN_URL . 'assets/js/ui-utils.js', array('jquery'), WUDT_VERSION, true);
		wp_enqueue_script(
			'wudt-admin',
			WUDT_PLUGIN_URL . 'assets/js/admin.js',
			array('jquery', 'wudt-ui-utils'),
			WUDT_VERSION . '.' . (int) filemtime(WUDT_PLUGIN_DIR . 'assets/js/admin.js'),
			true
		);
		wp_localize_script(
			'wudt-admin',
			'wudtAdmin',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('wudt_admin_nonce'),
				'data'    => $this->get_full_report(),
			)
		);
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}
		?>
		<div class="wrap wudt-wrap">
			<h1><?php esc_html_e('Diagnostics Toolkit', 'diagnostics-toolkit'); ?></h1>
			<p><?php esc_html_e('Diagnose performance, security, errors, conflicts, cron, REST, and database health.', 'diagnostics-toolkit'); ?></p>
			<div id="wudt-admin-app"></div>
		</div>
		<?php
	}

	public function ajax_refresh_dashboard(): void {
		$this->check_permissions();
		wp_send_json_success($this->get_full_report());
	}

	public function ajax_export_report(): void {
		$this->check_permissions();
		$data = $this->get_full_report();
		$json = wp_json_encode($data, JSON_PRETTY_PRINT);
		if (false === $json) {
			wp_send_json_error(array('message' => __('Could not generate report JSON.', 'diagnostics-toolkit')), 500);
		}

		wp_send_json_success(
			array(
				'content'  => $json,
				'filename' => 'diagnostic-report-' . gmdate('Ymd-His') . '.json',
			)
		);
	}

	public function ajax_email_report(): void {
		$this->check_permissions();
		$email = isset($_POST['email']) ? sanitize_email((string) wp_unslash($_POST['email'])) : '';
		if (! is_email($email)) {
			wp_send_json_error(array('message' => __('Please provide a valid email address.', 'diagnostics-toolkit')), 400);
		}

		$report = wp_json_encode($this->get_full_report(), JSON_PRETTY_PRINT);
		if (false === $report) {
			wp_send_json_error(array('message' => __('Could not generate report JSON.', 'diagnostics-toolkit')), 500);
		}
		$sent   = wp_mail(
			$email,
			__('WordPress Diagnostic Report', 'diagnostics-toolkit'),
			(string) $report,
			array('Content-Type: text/plain; charset=UTF-8')
		);

		if (! $sent) {
			wp_send_json_error(array('message' => __('Failed to send email report.', 'diagnostics-toolkit')), 500);
		}
		wp_send_json_success(array('message' => __('Report sent successfully.', 'diagnostics-toolkit')));
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
			wp_send_json_error(new WP_Error('forbidden', __('Insufficient permissions.', 'diagnostics-toolkit')), 403);
		}
	}
}
