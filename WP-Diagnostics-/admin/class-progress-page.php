<?php
/**
 * Progress Monitor Page
 * Real-time dashboard showing progress of all debugging tools
 */

declare(strict_types=1);

namespace WUDT\Admin;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Progress_Page
 * 
 * Admin page for monitoring progress of all debugging operations
 */
class Progress_Page {
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_submenu_page(
			'wudt-diagnostics',
			__('Progress Monitor', 'diagnostics-toolkit'),
			__('Progress Monitor', 'diagnostics-toolkit'),
			'manage_options',
			'wudt-progress-monitor',
			array($this, 'render_page')
		);
	}

	public function enqueue_assets(string $hook): void {
		if ('wp-diagnostics_page_wudt-progress-monitor' !== $hook) {
			return;
		}

		wp_enqueue_style('wudt-admin-modern', WUDT_PLUGIN_URL . 'assets/css/admin-modern.css', array(), WUDT_VERSION);
		wp_enqueue_style('wudt-admin', WUDT_PLUGIN_URL . 'assets/css/admin.css', array('wudt-admin-modern'), WUDT_VERSION);
		wp_enqueue_script('wudt-ui-utils', WUDT_PLUGIN_URL . 'assets/js/ui-utils.js', array('jquery'), WUDT_VERSION, true);
		wp_enqueue_script('wudt-progress-monitor', WUDT_PLUGIN_URL . 'assets/js/progress-monitor.js', array('jquery', 'wudt-ui-utils'), WUDT_VERSION, true);
		
		wp_localize_script('wudt-progress-monitor', 'wudtProgressMonitor', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wudt_admin_nonce'),
			'labels'  => array(
				'ready'     => __('Ready', 'diagnostics-toolkit'),
				'running'   => __('Running', 'diagnostics-toolkit'),
				'completed' => __('Completed', 'diagnostics-toolkit'),
				'failed'    => __('Failed', 'diagnostics-toolkit'),
				'cancelled' => __('Cancelled', 'diagnostics-toolkit'),
			),
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'diagnostics-toolkit'));
		}
		?>
		<div class="wudt-fullscreen-page">
			<div style="padding: 20px; overflow-y: auto;">
				<h1><?php echo esc_html__('Progress Monitor', 'diagnostics-toolkit'); ?></h1>
				<p class="description">
					<?php esc_html_e('Real-time monitoring of all debugging operations and their progress.', 'diagnostics-toolkit'); ?>
				</p>

				<!-- Overall Status Card -->
				<div class="wudt-progress-overview">
					<div class="wudt-card wudt-status-summary">
						<h2><?php esc_html_e('System Status', 'diagnostics-toolkit'); ?></h2>
						<div class="wudt-status-grid" id="wudt-status-grid">
							<div class="wudt-status-item">
								<span class="wudt-status-value" id="active-operations-count">0</span>
								<span class="wudt-status-label"><?php esc_html_e('Active Operations', 'diagnostics-toolkit'); ?></span>
							</div>
							<div class="wudt-status-item">
								<span class="wudt-status-value" id="completed-today-count">0</span>
								<span class="wudt-status-label"><?php esc_html_e('Completed Today', 'diagnostics-toolkit'); ?></span>
							</div>
							<div class="wudt-status-item">
								<span class="wudt-status-value" id="modules-ready-count">0</span>
								<span class="wudt-status-label"><?php esc_html_e('Modules Ready', 'diagnostics-toolkit'); ?></span>
							</div>
							<div class="wudt-status-item">
								<span class="wudt-status-value" id="errors-count">0</span>
								<span class="wudt-status-label"><?php esc_html_e('Recent Errors', 'diagnostics-toolkit'); ?></span>
							</div>
						</div>
					</div>
				</div>

				<!-- Active Operations -->
				<div class="wudt-card wudt-active-operations">
					<h2>
						<?php esc_html_e('Active Operations', 'diagnostics-toolkit'); ?>
						<span class="wudt-live-indicator"><?php esc_html_e('LIVE', 'diagnostics-toolkit'); ?></span>
					</h2>
					<div id="wudt-active-operations-list">
						<p class="wudt-no-operations"><?php esc_html_e('No active operations at the moment.', 'diagnostics-toolkit'); ?></p>
					</div>
				</div>

				<!-- Module Status Grid -->
				<div class="wudt-card">
					<h2><?php esc_html_e('Module Status', 'diagnostics-toolkit'); ?></h2>
					<div class="wudt-modules-grid" id="wudt-modules-grid">
						<?php $this->render_modules_placeholder(); ?>
					</div>
				</div>

				<!-- Recent Operations -->
				<div class="wudt-card">
					<h2><?php esc_html_e('Recent Operations', 'diagnostics-toolkit'); ?></h2>
					<table class="wp-list-table widefat fixed striped" id="wudt-recent-operations">
						<thead>
							<tr>
								<th><?php esc_html_e('Operation', 'diagnostics-toolkit'); ?></th>
								<th><?php esc_html_e('Status', 'diagnostics-toolkit'); ?></th>
								<th><?php esc_html_e('Progress', 'diagnostics-toolkit'); ?></th>
								<th><?php esc_html_e('Started', 'diagnostics-toolkit'); ?></th>
								<th><?php esc_html_e('Duration', 'diagnostics-toolkit'); ?></th>
								<th><?php esc_html_e('Actions', 'diagnostics-toolkit'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr class="no-items">
								<td colspan="6"><?php esc_html_e('Loading...', 'diagnostics-toolkit'); ?></td>
							</tr>
						</tbody>
					</table>
					<p class="wudt-cleanup-actions">
						<button type="button" class="button" id="wudt-cleanup-old">
							<?php esc_html_e('Clean Up Old Operations', 'diagnostics-toolkit'); ?>
						</button>
					</p>
				</div>

				<!-- Auto-refresh toggle -->
				<div class="wudt-refresh-control">
					<label>
						<input type="checkbox" id="wudt-auto-refresh" checked />
						<?php esc_html_e('Auto-refresh progress (every 2 seconds)', 'diagnostics-toolkit'); ?>
					</label>
					<button type="button" class="button" id="wudt-refresh-now">
						<?php esc_html_e('Refresh Now', 'diagnostics-toolkit'); ?>
					</button>
				</div>
			</div>
		</div>

		<style>
			.wudt-progress-monitor .wudt-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
			}
			.wudt-progress-monitor h2 {
				margin-top: 0;
				border-bottom: 1px solid #c3c4c7;
				padding-bottom: 10px;
			}
			.wudt-module-status-link {
				color: #1d2327;
				text-decoration: none;
				transition: all 0.2s ease;
				display: inline-block;
			}
			.wudt-module-status-link:hover {
				color: #2271b1;
			}
			.wudt-module-status-link:hover .dashicons {
				color: #2271b1;
				transform: translateX(2px);
			}
			.wudt-module-status-link .dashicons {
				transition: transform 0.2s ease;
			}
			.wudt-status-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 20px;
				margin-top: 20px;
			}
			.wudt-status-item {
				text-align: center;
				padding: 15px;
				background: #f6f7f7;
				border-radius: 4px;
			}
			.wudt-status-value {
				display: block;
				font-size: 32px;
				font-weight: bold;
				color: #2271b1;
			}
			.wudt-status-label {
				display: block;
				font-size: 12px;
				color: #646970;
				margin-top: 5px;
			}
			.wudt-live-indicator {
				background: #00a32a;
				color: #fff;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: bold;
				margin-left: 10px;
				animation: pulse 2s infinite;
			}
			@keyframes pulse {
				0%, 100% { opacity: 1; }
				50% { opacity: 0.5; }
			}
			.wudt-modules-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
				gap: 15px;
				margin-top: 15px;
			}
			.wudt-module-card {
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 15px;
				position: relative;
			}
			.wudt-module-card.running {
				border-color: #2271b1;
				background: #f0f6fc;
			}
			.wudt-module-card .module-icon {
				font-size: 24px;
				margin-bottom: 10px;
			}
			.wudt-module-card h3 {
				margin: 0 0 10px;
				font-size: 14px;
			}
			.wudt-module-status {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 500;
				text-transform: uppercase;
			}
			.wudt-module-status.ready {
				background: #edfaef;
				color: #00a32a;
			}
			.wudt-module-status.running {
				background: #c5d9ed;
				color: #2271b1;
			}
			.wudt-module-pro {
				position: absolute;
				top: 10px;
				right: 10px;
				background: #dba617;
				color: #fff;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: bold;
			}
			.wudt-operation-progress {
				background: #f6f7f7;
				border-radius: 4px;
				padding: 15px;
				margin-bottom: 15px;
				border-left: 4px solid #2271b1;
			}
			.wudt-operation-progress .progress-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 10px;
			}
			.wudt-operation-progress .progress-title {
				font-weight: 600;
			}
			.wudt-operation-progress .progress-bar {
				height: 20px;
				background: #dcdcde;
				border-radius: 10px;
				overflow: hidden;
			}
			.wudt-operation-progress .progress-bar-fill {
				height: 100%;
				background: #2271b1;
				transition: width 0.3s ease;
				border-radius: 10px;
			}
			.wudt-operation-progress .progress-info {
				display: flex;
				justify-content: space-between;
				margin-top: 8px;
				font-size: 12px;
				color: #646970;
			}
			.wudt-refresh-control {
				margin-top: 20px;
				padding: 15px;
				background: #f6f7f7;
				border-radius: 4px;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.wudt-cancel-btn {
				background: #d63638;
				color: #fff;
				border: none;
				padding: 5px 12px;
				border-radius: 3px;
				cursor: pointer;
			}
			.wudt-cancel-btn:hover {
				background: #b32d2e;
			}
			.wudt-no-operations {
				color: #646970;
				font-style: italic;
				text-align: center;
				padding: 20px;
			}
			.wudt-cleanup-actions {
				margin-top: 15px;
			}
		</style>
		<?php
	}

	/**
	 * Render placeholder for modules grid
	 */
	private function render_modules_placeholder(): void {
		$modules = array(
			array('icon' => 'dashicons-desktop', 'label' => 'System Info', 'status' => 'ready'),
			array('icon' => 'dashicons-warning', 'label' => 'Error Logger', 'status' => 'ready'),
			array('icon' => 'dashicons-plugins-checked', 'label' => 'Conflict Detector', 'status' => 'ready'),
			array('icon' => 'dashicons-chart-line', 'label' => 'Performance', 'status' => 'ready'),
			array('icon' => 'dashicons-database', 'label' => 'Database Tools', 'status' => 'ready'),
			array('icon' => 'dashicons-rest-api', 'label' => 'REST API', 'status' => 'ready'),
			array('icon' => 'dashicons-clock', 'label' => 'Cron Jobs', 'status' => 'ready'),
			array('icon' => 'dashicons-media-document', 'label' => 'File Integrity', 'status' => 'ready'),
			array('icon' => 'dashicons-lock', 'label' => 'Security', 'status' => 'ready'),
			array('icon' => 'dashicons-external', 'label' => 'External Requests', 'status' => 'ready'),
			array('icon' => 'dashicons-open-folder', 'label' => 'File Manager', 'status' => 'ready'),
			array('icon' => 'dashicons-shield', 'label' => 'Malware Scanner', 'status' => 'ready'),
			array('icon' => 'dashicons-backup', 'label' => 'Backup', 'status' => 'ready'),
			array('icon' => 'dashicons-migrate', 'label' => 'Restore', 'status' => 'ready'),
			array('icon' => 'dashicons-art', 'label' => 'AI Assistant', 'status' => 'ready'),
		);

		foreach ($modules as $module) {
			$status_class = sanitize_html_class($module['status']);
			?>
			<div class="wudt-module-card <?php echo esc_attr($status_class); ?>" data-module-key="<?php echo esc_attr(sanitize_key(strtolower(str_replace(' ', '_', $module['label'])))); ?>">
				<div class="module-icon dashicons <?php echo esc_attr($module['icon']); ?>"></div>
				<h3><?php echo esc_html($module['label']); ?></h3>
				<span class="wudt-module-status <?php echo esc_attr($status_class); ?>">
					<?php echo esc_html(ucfirst($module['status'])); ?>
				</span>
			</div>
			<?php
		}
	}
}
