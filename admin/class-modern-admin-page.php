<?php
/**
 * Modern Admin Page
 * Improved UI/UX with modern design system
 */

declare(strict_types=1);

namespace WUDT\Admin;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Modern_Admin_Page
 * 
 * Enhanced admin interface with modern UI components
 */
class Modern_Admin_Page {
	/**
	 * @var array<int,Module_Base>
	 */
	private array $modules;

	/**
	 * @param array<int,Module_Base> $modules
	 */
	public function __construct(array $modules) {
		$this->modules = $modules;
	}

	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_wudt_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
		add_action('wp_ajax_wudt_run_module', array($this, 'ajax_run_module'));
	}

	public function register_menu(): void {
		add_management_page(
			__('WP Diagnostics Toolkit', 'wp-ultimate-diagnostics-toolkit'),
			__('Diagnostics Toolkit', 'wp-ultimate-diagnostics-toolkit'),
			'manage_options',
			'wudt-diagnostics',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('tools_page_wudt-diagnostics' !== $hook) {
			return;
		}

		// Enqueue modern styles
		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);

		// Enqueue scripts
		wp_enqueue_script('wudt-ui-utils', WUDT_PLUGIN_URL . 'assets/js/ui-utils.js', array('jquery'), WUDT_VERSION, true);
		wp_enqueue_script('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/js/admin-modern.js', array('jquery', 'wudt-ui-utils'), WUDT_VERSION, true);

		// Localize data
		$tabs = array();
		foreach ($this->modules as $module) {
			$tabs[] = array(
				'key'   => $module->get_key(),
				'label' => $module->get_label(),
				'data'  => $module->get_dashboard_data(),
			);
		}

		wp_localize_script('wudt-admin-modern', 'wudtModernAdmin', array(
			'ajaxUrl'      => admin_url('admin-ajax.php'),
			'nonce'        => wp_create_nonce('wudt_admin_nonce'),
			'tabs'         => $tabs,
			'generated_at' => current_time('mysql'),
			'labels'       => array(
				'loading'   => __('Loading...', 'wp-ultimate-diagnostics-toolkit'),
				'error'     => __('Error', 'wp-ultimate-diagnostics-toolkit'),
				'success'   => __('Success', 'wp-ultimate-diagnostics-toolkit'),
				'refresh'   => __('Refresh', 'wp-ultimate-diagnostics-toolkit'),
				'run_check' => __('Run Check', 'wp-ultimate-diagnostics-toolkit'),
			),
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-ultimate-diagnostics-toolkit'));
		}
		?>
		<div class="wrap wudt-wrap wudt-modern">
			<div class="wudt-header-bar">
				<h1><?php echo esc_html__('WP Ultimate Diagnostics Toolkit', 'wp-ultimate-diagnostics-toolkit'); ?></h1>
				<div class="wudt-header-actions">
					<button type="button" class="wudt-btn wudt-btn--secondary" id="wudt-dark-mode-toggle">
						<span class="dashicons dashicons-visibility"></span>
						<?php esc_html_e('Toggle Dark Mode', 'wp-ultimate-diagnostics-toolkit'); ?>
					</button>
					<a href="<?php echo esc_url(admin_url('admin.php?page=wudt-ai-assistant')); ?>" class="wudt-btn wudt-btn--primary">
						<span class="dashicons dashicons-art"></span>
						<?php esc_html_e('AI Assistant', 'wp-ultimate-diagnostics-toolkit'); ?>
					</a>
				</div>
			</div>

			<!-- Dashboard Stats -->
			<div class="wudt-stats-grid" id="wudt-stats-grid">
				<?php $this->render_stat_cards(); ?>
			</div>

			<!-- Module Navigation -->
			<div class="wudt-tabs" id="wudt-module-tabs">
				<?php foreach ($this->modules as $module): ?>
					<button class="wudt-tab" data-tab="<?php echo esc_attr($module->get_key()); ?>">
						<?php echo esc_html($module->get_label()); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<!-- Module Content Panels -->
			<div class="wudt-panels-container" id="wudt-panels-container">
				<?php foreach ($this->modules as $module): ?>
					<div class="wudt-panel" data-panel="<?php echo esc_attr($module->get_key()); ?>" style="display: none;">
						<div class="wudt-panel__header">
							<h2><?php echo esc_html($module->get_label()); ?></h2>
							<div class="wudt-panel__actions">
								<button type="button" class="wudt-btn wudt-btn--secondary wudt-btn--sm wudt-run-module" data-module="<?php echo esc_attr($module->get_key()); ?>">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e('Run Check', 'wp-ultimate-diagnostics-toolkit'); ?>
								</button>
							</div>
						</div>
						<div class="wudt-panel__content">
							<div class="wudt-skeleton-loader">
								<div class="wudt-skeleton wudt-skeleton--title"></div>
								<div class="wudt-skeleton wudt-skeleton--text"></div>
								<div class="wudt-skeleton wudt-skeleton--text"></div>
								<div class="wudt-skeleton wudt-skeleton--text"></div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Quick Actions -->
			<div class="wudt-quick-actions">
				<h3><?php esc_html_e('Quick Actions', 'wp-ultimate-diagnostics-toolkit'); ?></h3>
				<div class="wudt-quick-actions__grid">
					<button type="button" class="wudt-quick-action" id="wudt-quick-scan">
						<span class="wudt-quick-action__icon">🔍</span>
						<span class="wudt-quick-action__label"><?php esc_html_e('Full System Scan', 'wp-ultimate-diagnostics-toolkit'); ?></span>
					</button>
					<button type="button" class="wudt-quick-action" id="wudt-quick-clear-cache">
						<span class="wudt-quick-action__icon">🧹</span>
						<span class="wudt-quick-action__label"><?php esc_html_e('Clear Cache', 'wp-ultimate-diagnostics-toolkit'); ?></span>
					</button>
					<button type="button" class="wudt-quick-action" id="wudt-quick-export">
						<span class="wudt-quick-action__icon">📊</span>
						<span class="wudt-quick-action__label"><?php esc_html_e('Export Report', 'wp-ultimate-diagnostics-toolkit'); ?></span>
					</button>
					<button type="button" class="wudt-quick-action" id="wudt-quick-help">
						<span class="wudt-quick-action__icon">❓</span>
						<span class="wudt-quick-action__label"><?php esc_html_e('Get Help', 'wp-ultimate-diagnostics-toolkit'); ?></span>
					</button>
				</div>
			</div>
		</div>

		<style>
			/* Header Bar */
			.wudt-header-bar {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 20px;
				padding-bottom: 20px;
				border-bottom: 1px solid var(--wudt-gray-200);
			}

			.wudt-header-bar h1 {
				margin: 0;
			}

			.wudt-header-actions {
				display: flex;
				gap: 10px;
			}

			/* Stats Grid */
			.wudt-stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 16px;
				margin-bottom: 24px;
			}

			/* Panel Styles */
			.wudt-panels-container {
				margin-top: 20px;
			}

			.wudt-panel {
				background: #fff;
				border: 1px solid var(--wudt-gray-200);
				border-radius: 12px;
				overflow: hidden;
			}

			.wudt-panel__header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 16px 20px;
				border-bottom: 1px solid var(--wudt-gray-200);
				background: var(--wudt-gray-50);
			}

			.wudt-panel__header h2 {
				margin: 0;
				font-size: 1.1rem;
			}

			.wudt-panel__actions {
				display: flex;
				gap: 8px;
			}

			.wudt-panel__content {
				padding: 20px;
				min-height: 200px;
			}

			/* Quick Actions */
			.wudt-quick-actions {
				margin-top: 30px;
				padding-top: 20px;
				border-top: 1px solid var(--wudt-gray-200);
			}

			.wudt-quick-actions h3 {
				margin-bottom: 16px;
			}

			.wudt-quick-actions__grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 12px;
			}

			.wudt-quick-action {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 8px;
				padding: 20px;
				background: #fff;
				border: 1px solid var(--wudt-gray-200);
				border-radius: 12px;
				cursor: pointer;
				transition: all 0.2s ease;
			}

			.wudt-quick-action:hover {
				border-color: var(--wudt-primary);
				box-shadow: 0 4px 12px rgba(34, 113, 177, 0.15);
				transform: translateY(-2px);
			}

			.wudt-quick-action__icon {
				font-size: 28px;
			}

			.wudt-quick-action__label {
				font-size: 0.875rem;
				font-weight: 500;
				text-align: center;
			}

			/* Skeleton Loader in Panel */
			.wudt-skeleton-loader {
				padding: 20px;
			}
		</style>
		<?php
	}

	/**
	 * Render stat cards for dashboard
	 */
	private function render_stat_cards(): void {
		global $wpdb;

		$stats = array(
			array(
				'icon'  => 'dashicons-wordpress',
				'label' => __('WP Version', 'wp-ultimate-diagnostics-toolkit'),
				'value' => get_bloginfo('version'),
				'color' => 'primary',
			),
			array(
				'icon'  => 'dashicons-plugins-checked',
				'label' => __('Active Plugins', 'wp-ultimate-diagnostics-toolkit'),
				'value' => count(get_option('active_plugins', array())),
				'color' => 'neutral',
			),
			array(
				'icon'  => 'dashicons-database',
				'label' => __('DB Tables', 'wp-ultimate-diagnostics-toolkit'),
				'value' => $wpdb->get_var("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"),
				'color' => 'neutral',
			),
			array(
				'icon'  => 'dashicons-performance',
				'label' => __('PHP Version', 'wp-ultimate-diagnostics-toolkit'),
				'value' => phpversion(),
				'color' => version_compare(phpversion(), '8.0', '>=') ? 'success' : 'warning',
			),
		);

		foreach ($stats as $stat):
			$color_class = 'wudt-badge--' . $stat['color'];
		?>
			<div class="wudt-stat-card">
				<div class="wudt-stat-card__icon <?php echo esc_attr($color_class); ?>">
					<span class="dashicons <?php echo esc_attr($stat['icon']); ?>"></span>
				</div>
				<div class="wudt-stat-card__content">
					<div class="wudt-stat-card__value"><?php echo esc_html($stat['value']); ?></div>
					<div class="wudt-stat-card__label"><?php echo esc_html($stat['label']); ?></div>
				</div>
			</div>
		<?php
		endforeach;
	}

	/**
	 * AJAX: Get dashboard data
	 */
	public function ajax_get_dashboard_data(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$tab = sanitize_key($_POST['tab'] ?? '');
		
		foreach ($this->modules as $module) {
			if ($module->get_key() === $tab) {
				wp_send_json_success(array(
					'key'  => $module->get_key(),
					'data' => $module->get_dashboard_data(),
				));
			}
		}

		wp_send_json_error(array('message' => __('Module not found.', 'wp-ultimate-diagnostics-toolkit')));
	}

	/**
	 * AJAX: Run module check
	 */
	public function ajax_run_module(): void {
		check_ajax_referer('wudt_admin_nonce', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')));
		}

		$module_key = sanitize_key($_POST['module'] ?? '');
		
		// Trigger module refresh/update
		do_action('wudt_module_run', $module_key);

		foreach ($this->modules as $module) {
			if ($module->get_key() === $module_key) {
				wp_send_json_success(array(
					'key'  => $module->get_key(),
					'data' => $module->get_dashboard_data(),
					'message' => sprintf(
						/* translators: %s: Module label */
						__('%s check completed.', 'wp-ultimate-diagnostics-toolkit'),
						$module->get_label()
					),
				));
			}
		}

		wp_send_json_error(array('message' => __('Module not found.', 'wp-ultimate-diagnostics-toolkit')));
	}
}
