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
		add_action('wp_ajax_wudt_pro_load_tab', array($this, 'ajax_load_tab'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Admin Tools', 'diagnostics-toolkit'),
			__('Admin Tools', 'diagnostics-toolkit'),
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
		wp_enqueue_style('wudt-migration', WUDT_PLUGIN_URL . 'assets/css/migration.css', array('wudt-admin'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/css/migration.css'));
		wp_enqueue_script('wudt-migration', WUDT_PLUGIN_URL . 'assets/js/migration.js', array('jquery'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/js/migration.js'), true);
		wp_enqueue_style('wudt-ai-agent', WUDT_PLUGIN_URL . 'assets/css/ai-agent.css', array('wudt-admin'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/css/ai-agent.css'));
		wp_enqueue_script('wudt-ai-agent', WUDT_PLUGIN_URL . 'assets/js/ai-agent.js', array('jquery'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/js/ai-agent.js'), true);
		wp_enqueue_script('wudt-malware', WUDT_PLUGIN_URL . 'assets/js/malware.js', array('jquery'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/js/malware.js'), true);
		wp_enqueue_script('wudt-backup', WUDT_PLUGIN_URL . 'assets/js/backup.js', array('jquery'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/js/backup.js'), true);
		wp_enqueue_script('wudt-pro-admin', WUDT_PLUGIN_URL . 'assets/js/pro-admin.js', array('jquery', 'wudt-migration', 'wudt-ai-agent', 'wudt-backup', 'wudt-malware'), WUDT_VERSION . '.' . (int) @filemtime(WUDT_PLUGIN_DIR . 'assets/js/pro-admin.js'), true);
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
				'ajaxUrl'     => admin_url('admin-ajax.php'),
				'nonce'       => wp_create_nonce('wudt_admin_nonce'),
				'data'        => $data,
				'defaultTab'  => 'dashboard',
			)
		);
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}
		?>
		<div class="wudt-fullscreen-page">
			<div class="wudt-pro-wrap" style="padding: 20px; overflow-y: auto;">
				<div class="wudt-pro-header">
					<img src="<?php echo esc_url(WUDT_PLUGIN_URL . 'assets/img/logo.svg'); ?>" alt="" class="wudt-pro-logo">
					<div class="wudt-pro-title">
						<h1><?php esc_html_e('Diagnostics Toolkit – Admin Tools', 'diagnostics-toolkit'); ?></h1>
						<p><?php esc_html_e('Advanced file operations, database manager, malware scanner, logs, performance and security controls.', 'diagnostics-toolkit'); ?></p>
					</div>
				</div>
				<div id="wudt-pro-admin-app"></div>
			</div>
		</div>
		<?php
	}

	public function ajax_refresh_dashboard(): void {
		$this->check_permissions();
		wp_send_json_success($this->get_full_report());
	}

	public function ajax_load_tab(): void {
		$this->check_permissions();

		$tab = isset($_POST['tab']) ? sanitize_text_field(wp_unslash($_POST['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if (empty($tab)) {
			wp_send_json_error(new WP_Error('invalid_tab', __('No tab specified.', 'diagnostics-toolkit')), 400);
			return;
		}

		// Find the module matching the requested tab
		foreach ($this->modules as $module) {
			if ($module->get_key() === $tab) {
				wp_send_json_success($module->get_dashboard_data());
				return;
			}
		}

		// If no module found, return empty data
		wp_send_json_success(array());
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
