<?php
/**
 * Progress Monitor Module
 * Displays working progress of all debugging tools
 */

declare(strict_types=1);

namespace WUDT\Modules\ProgressMonitor;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Progress_Monitor_Module
 * 
 * Tracks and displays real-time progress of all debugging operations
 */
class Progress_Monitor_Module extends Module_Base {
	/** @var Progress_Tracker|null Current tracker instance */
	private ?Progress_Tracker $tracker = null;

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_get_progress', array($this, 'ajax_get_progress'));
		add_action('wp_ajax_wudt_get_all_operations', array($this, 'ajax_get_all_operations'));
		add_action('wp_ajax_wudt_cancel_operation', array($this, 'ajax_cancel_operation'));
		add_action('wp_ajax_wudt_cleanup_operations', array($this, 'ajax_cleanup_operations'));
		add_action('wp_ajax_wudt_get_module_progress', array($this, 'ajax_get_module_progress'));
	}

	public function get_key(): string {
		return 'progress_monitor';
	}

	public function get_label(): string {
		return __('Progress Monitor', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array(
			'active_operations' => Progress_Tracker::get_all_active(),
			'recent_operations' => array_slice(Progress_Tracker::get_all_operations(10), 0, 10),
			'module_status'     => $this->get_all_module_status(),
		);
	}

	/**
	 * Get progress for a specific operation
	 */
	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();
		
		$operation_id = sanitize_text_field((string) wp_unslash($_POST['operation_id'] ?? ''));
		
		if (empty($operation_id)) {
			wp_send_json_error(array('message' => 'Operation ID required'));
		}

		$tracker = new Progress_Tracker('', $operation_id);
		$data = $tracker->get();
		
		if (! $data) {
			wp_send_json_error(array('message' => 'Operation not found'));
		}

		wp_send_json_success(array('progress' => $data));
	}

	/**
	 * Get all operations
	 */
	public function ajax_get_all_operations(): void {
		Security_Guard::assert_ajax_admin();
		
		$type = sanitize_key((string) wp_unslash($_POST['type'] ?? ''));
		$status = sanitize_key((string) wp_unslash($_POST['status'] ?? ''));
		$limit = min(100, (int) ($_POST['limit'] ?? 50));
		
		$operations = Progress_Tracker::get_all_operations($limit);
		
		// Filter by type if specified
		if (! empty($type)) {
			$operations = array_filter($operations, function($op) use ($type) {
				return ($op['operation_type'] ?? '') === $type;
			});
		}
		
		// Filter by status if specified
		if (! empty($status)) {
			$operations = array_filter($operations, function($op) use ($status) {
				return ($op['status'] ?? '') === $status;
			});
		}
		
		wp_send_json_success(array(
			'operations' => array_values($operations),
			'active_count' => count(array_filter($operations, fn($op) => ($op['status'] ?? '') === 'running')),
			'total_count' => count($operations),
		));
	}

	/**
	 * Cancel an operation
	 */
	public function ajax_cancel_operation(): void {
		Security_Guard::assert_ajax_admin();
		
		$operation_id = sanitize_text_field((string) wp_unslash($_POST['operation_id'] ?? ''));
		
		if (empty($operation_id)) {
			wp_send_json_error(array('message' => 'Operation ID required'));
		}

		$tracker = new Progress_Tracker('', $operation_id);
		
		if (! $tracker->is_running()) {
			wp_send_json_error(array('message' => 'Operation not running'));
		}

		$tracker->cancel(__('Cancelled by user', 'wp-ultimate-diagnostics-toolkit'));
		
		wp_send_json_success(array(
			'cancelled' => true,
			'operation_id' => $operation_id,
		));
	}

	/**
	 * Cleanup old operations
	 */
	public function ajax_cleanup_operations(): void {
		Security_Guard::assert_ajax_admin();
		
		$older_than = (int) ($_POST['older_than_hours'] ?? 24);
		$deleted = Progress_Tracker::cleanup_old($older_than * HOUR_IN_SECONDS);
		
		wp_send_json_success(array(
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of deleted operations */
				__('Cleaned up %d old operations', 'wp-ultimate-diagnostics-toolkit'),
				$deleted
			),
		));
	}

	/**
	 * Get progress for all modules
	 */
	public function ajax_get_module_progress(): void {
		Security_Guard::assert_ajax_admin();
		
		$modules = $this->get_all_module_status();
		$active = Progress_Tracker::get_all_active();
		
		// Group active operations by type
		$operations_by_type = array();
		foreach ($active as $op) {
			$type = $op['operation_type'] ?? 'unknown';
			if (! isset($operations_by_type[$type])) {
				$operations_by_type[$type] = array();
			}
			$operations_by_type[$type][] = $op;
		}
		
		wp_send_json_success(array(
			'modules' => $modules,
			'active_operations' => $active,
			'operations_by_type' => $operations_by_type,
			'has_active_operations' => ! empty($active),
		));
	}

	/**
	 * Get status for all debugging modules
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_all_module_status(): array {
		$modules = array(
			'system_info' => array(
				'label' => __('System Info', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-desktop',
				'status' => 'ready',
				'last_run' => get_option('wudt_system_info_last_run', null),
				'can_run' => true,
				'estimated_time' => '< 1 second',
			),
			'error_logger' => array(
				'label' => __('Error Logger', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-warning',
				'status' => 'ready',
				'last_run' => get_option('wudt_error_logger_last_run', null),
				'error_count' => $this->get_error_count(),
				'can_run' => true,
				'estimated_time' => '< 1 second',
			),
			'conflict_detector' => array(
				'label' => __('Conflict Detector', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-plug',
				'status' => 'ready',
				'last_run' => get_option('wudt_conflict_detector_last_run', null),
				'conflict_count' => $this->get_conflict_count(),
				'can_run' => true,
				'estimated_time' => '2-5 seconds',
			),
			'performance' => array(
				'label' => __('Performance', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-performance',
				'status' => 'ready',
				'last_run' => get_option('wudt_performance_last_run', null),
				'can_run' => true,
				'estimated_time' => '5-10 seconds',
			),
			'db_tools' => array(
				'label' => __('Database Tools', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-database',
				'status' => 'ready',
				'last_run' => get_option('wudt_db_tools_last_run', null),
				'table_count' => $this->get_table_count(),
				'can_run' => true,
				'estimated_time' => '2-5 seconds',
			),
			'rest_api' => array(
				'label' => __('REST API', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-rest-api',
				'status' => 'ready',
				'last_run' => get_option('wudt_rest_api_last_run', null),
				'can_run' => true,
				'estimated_time' => '< 1 second',
			),
			'cron' => array(
				'label' => __('Cron Jobs', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-clock',
				'status' => 'ready',
				'last_run' => get_option('wudt_cron_last_run', null),
				'cron_count' => $this->get_cron_count(),
				'can_run' => true,
				'estimated_time' => '< 1 second',
			),
			'file_integrity' => array(
				'label' => __('File Integrity', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-media-document',
				'status' => 'ready',
				'last_run' => get_option('wudt_file_integrity_last_run', null),
				'can_run' => true,
				'estimated_time' => '30-60 seconds',
				'is_long_running' => true,
			),
			'security' => array(
				'label' => __('Security', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-lock',
				'status' => 'ready',
				'last_run' => get_option('wudt_security_last_run', null),
				'can_run' => true,
				'estimated_time' => '5-10 seconds',
			),
			'external_requests' => array(
				'label' => __('External Requests', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-external',
				'status' => 'ready',
				'last_run' => get_option('wudt_external_requests_last_run', null),
				'can_run' => true,
				'estimated_time' => '5-10 seconds',
			),
			'file_manager' => array(
				'label' => __('File Manager', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-open-folder',
				'status' => 'ready',
				'last_run' => null,
				'can_run' => true,
				'estimated_time' => 'On demand',
				'is_pro' => true,
			),
			'database_manager' => array(
				'label' => __('Database Manager', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-database',
				'status' => 'ready',
				'last_run' => null,
				'can_run' => true,
				'estimated_time' => 'On demand',
				'is_pro' => true,
			),
			'malware_scanner' => array(
				'label' => __('Malware Scanner', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-shield',
				'status' => 'ready',
				'last_run' => get_option('wudt_malware_last_run', null),
				'can_run' => true,
				'estimated_time' => '1-5 minutes',
				'is_long_running' => true,
				'is_pro' => true,
			),
			'backup' => array(
				'label' => __('Backup', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-backup',
				'status' => 'ready',
				'last_run' => get_option('wudt_backup_last_run', null),
				'backup_count' => $this->get_backup_count(),
				'can_run' => true,
				'estimated_time' => '1-10 minutes',
				'is_long_running' => true,
				'is_pro' => true,
			),
			'restore' => array(
				'label' => __('Restore', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-migrate',
				'status' => 'ready',
				'last_run' => null,
				'can_run' => true,
				'estimated_time' => '1-10 minutes',
				'is_long_running' => true,
				'is_pro' => true,
			),
			'ai_assistant' => array(
				'label' => __('AI Assistant', 'wp-ultimate-diagnostics-toolkit'),
				'icon' => 'dash dash-art',
				'status' => 'ready',
				'last_run' => null,
				'can_run' => true,
				'estimated_time' => '2-10 seconds',
				'is_pro' => true,
			),
		);

		// Check for active operations and update status
		$active = Progress_Tracker::get_all_active();
		foreach ($active as $op) {
			$type = $op['operation_type'] ?? '';
			if (isset($modules[$type])) {
				$modules[$type]['status'] = 'running';
				$modules[$type]['current_operation'] = $op;
				$modules[$type]['can_run'] = false;
			}
		}

		return $modules;
	}

	/**
	 * Get error count from error logger
	 *
	 * @return int
	 */
	private function get_error_count(): int {
		$entries = get_option('wudt_error_log_entries', array());
		return is_array($entries) ? count($entries) : 0;
	}

	/**
	 * Get conflict count
	 *
	 * @return int
	 */
	private function get_conflict_count(): int {
		$conflicts = get_option('wudt_conflict_list', array());
		return is_array($conflicts) ? count($conflicts) : 0;
	}

	/**
	 * Get database table count
	 *
	 * @return int
	 */
	private function get_table_count(): int {
		global $wpdb;
		$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		return count($tables);
	}

	/**
	 * Get cron job count
	 *
	 * @return int
	 */
	private function get_cron_count(): int {
		$cron = get_option('cron', array());
		$count = 0;
		foreach ($cron as $timestamp => $hooks) {
			if (is_array($hooks)) {
				$count += count($hooks);
			}
		}
		return $count;
	}

	/**
	 * Get backup count
	 *
	 * @return int
	 */
	private function get_backup_count(): int {
		$backups = get_option('wudt_backups', array());
		return is_array($backups) ? count($backups) : 0;
	}

	/**
	 * Create a progress tracker for an operation
	 *
	 * @param string $operation_type
	 * @return Progress_Tracker
	 */
	public function create_tracker(string $operation_type): Progress_Tracker {
		$this->tracker = new Progress_Tracker($operation_type);
		return $this->tracker;
	}

	/**
	 * Get current tracker
	 *
	 * @return Progress_Tracker|null
	 */
	public function get_tracker(): ?Progress_Tracker {
		return $this->tracker;
	}
}
