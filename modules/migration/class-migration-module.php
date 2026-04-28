<?php
/**
 * Site Migration Module - Main controller for async site migration.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Security_Guard;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Module extends Module_Base {
	private const OPTION_SITES = 'wudt_migration_sites';
	private const OPTION_JOBS = 'wudt_migration_jobs';
	private const TRANSIENT_PREFIX = 'wudt_migration_progress_';
	private const API_NAMESPACE = 'wudt-migration/v1';

	private Migration_Job $job_manager;
	private Remote_Client $remote_client;
	private Stream_Importer $stream_importer;

	public function __construct() {
		$this->job_manager = new Migration_Job();
		$this->remote_client = new Remote_Client();
		$this->stream_importer = new Stream_Importer();
	}

	public function register_hooks(): void {
		// Admin AJAX endpoints
		add_action('wp_ajax_wudt_migration_get_sites', array($this, 'ajax_get_sites'));
		add_action('wp_ajax_wudt_migration_save_site', array($this, 'ajax_save_site'));
		add_action('wp_ajax_wudt_migration_delete_site', array($this, 'ajax_delete_site'));
		add_action('wp_ajax_wudt_migration_test_connection', array($this, 'ajax_test_connection'));
		add_action('wp_ajax_wudt_migration_start', array($this, 'ajax_start_migration'));
		add_action('wp_ajax_wudt_migration_progress', array($this, 'ajax_get_progress'));
		add_action('wp_ajax_wudt_migration_cancel', array($this, 'ajax_cancel_job'));
		add_action('wp_ajax_wudt_migration_history', array($this, 'ajax_get_history'));
		add_action('wp_ajax_wudt_migration_delete_job', array($this, 'ajax_delete_job'));
		add_action('wp_ajax_wudt_migration_regenerate_key', array($this, 'ajax_regenerate_key'));

		// REST API endpoints for remote server communication
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		// Background job processing
		add_action('wudt_migration_process_job', array($this, 'process_migration_job'), 10, 1);
	}

	public function get_key(): string {
		return 'site_migration';
	}

	public function get_label(): string {
		return __('Site Migration', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$sites = $this->get_sites();
		return array(
			'sites' => array_values($sites), // Convert to indexed array for JavaScript
			'jobs' => $this->job_manager->get_recent_jobs(10),
			'current_job' => $this->job_manager->get_active_job(),
			'local_site_url' => home_url('/'),
			'local_api_key' => $this->get_or_generate_local_api_key(),
		);
	}

	/**
	 * Get or generate the local API key for this site.
	 * Remote sites will use this key to authenticate when connecting.
	 */
	private function get_or_generate_local_api_key(): string {
		$key = get_option('wudt_local_api_key');
		if (empty($key)) {
			$key = $this->generate_api_key();
			update_option('wudt_local_api_key', $key, false);
		}
		return $key;
	}

	/**
	 * Generate a new random API key.
	 */
	private function generate_api_key(): string {
		return bin2hex(random_bytes(16)); // 32 character hex string
	}

	/**
	 * Register REST API routes for remote server communication.
	 */
	public function register_rest_routes(): void {
		// Connect/Verify endpoint
		register_rest_route(self::API_NAMESPACE, '/connect', array(
			'methods' => 'POST',
			'callback' => array($this, 'rest_connect'),
			'permission_callback' => array($this, 'rest_permission_check'),
		));

		// Backup status endpoint
		register_rest_route(self::API_NAMESPACE, '/backup-status/(?P<job_id>[a-zA-Z0-9_-]+)', array(
			'methods' => 'GET',
			'callback' => array($this, 'rest_backup_status'),
			'permission_callback' => array($this, 'rest_permission_check'),
		));

		// Download backup file endpoint
		register_rest_route(self::API_NAMESPACE, '/download/(?P<job_id>[a-zA-Z0-9_-]+)', array(
			'methods' => 'GET',
			'callback' => array($this, 'rest_download_backup'),
			'permission_callback' => array($this, 'rest_permission_check'),
		));

		// Initiate backup on remote server
		register_rest_route(self::API_NAMESPACE, '/initiate-backup', array(
			'methods' => 'POST',
			'callback' => array($this, 'rest_initiate_backup'),
			'permission_callback' => array($this, 'rest_permission_check'),
		));
	}

	/**
	 * REST API permission check using API key authentication.
	 */
	public function rest_permission_check(\WP_REST_Request $request): bool {
		$api_key = $request->get_header('X-WUDT-API-Key');
		$timestamp = $request->get_header('X-WUDT-Timestamp');
		$signature = $request->get_header('X-WUDT-Signature');

		if (empty($api_key) || empty($timestamp) || empty($signature)) {
			return false;
		}

		// Verify timestamp is within 5 minutes to prevent replay attacks
		$time_diff = abs(time() - (int) $timestamp);
		if ($time_diff > 300) {
			return false;
		}

		// Get stored API keys
		$sites = $this->get_sites();
		$valid_key = false;
		foreach ($sites as $site) {
			if ($site['api_key'] === $api_key) {
				$valid_key = true;
				break;
			}
		}

		if (!$valid_key) {
			// Also check if this is a local API key
			$local_key = get_option('wudt_local_api_key');
			if ($api_key !== $local_key) {
				return false;
			}
		}

		// Verify HMAC signature
		$expected_signature = hash_hmac('sha256', $api_key . $timestamp, $api_key);
		if (!hash_equals($expected_signature, $signature)) {
			return false;
		}

		return true;
	}

	/**
	 * REST: Connect/Verify endpoint.
	 */
	public function rest_connect(\WP_REST_Request $request): \WP_REST_Response {
		return new \WP_REST_Response(array(
			'success' => true,
			'site_url' => home_url('/'),
			'site_name' => get_bloginfo('name'),
			'wordpress_version' => get_bloginfo('version'),
			'plugin_version' => WUDT_VERSION,
		), 200);
	}

	/**
	 * REST: Get backup status for a job.
	 */
	public function rest_backup_status(\WP_REST_Request $request): \WP_REST_Response {
		$job_id = $request->get_param('job_id');
		$progress = get_transient(self::TRANSIENT_PREFIX . $job_id);

		if (!is_array($progress)) {
			return new \WP_REST_Response(array(
				'error' => 'Job not found',
			), 404);
		}

		return new \WP_REST_Response($progress, 200);
	}

	/**
	 * REST: Download backup file.
	 */
	public function rest_download_backup(\WP_REST_Request $request): void {
		$job_id = $request->get_param('job_id');
		$progress = get_transient(self::TRANSIENT_PREFIX . $job_id);

		if (!is_array($progress) || empty($progress['backup_file']) || !file_exists($progress['backup_file'])) {
			status_header(404);
			echo json_encode(array('error' => 'Backup file not found'));
			exit;
		}

		$file = $progress['backup_file'];
		$filename = basename($file);
		$file_size = filesize($file);

		// Handle range requests for chunked downloads
		$range = $request->get_header('Range');
		$start = 0;
		$end = $file_size - 1;

		if ($range) {
			if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
				$start = (int) $matches[1];
				if (!empty($matches[2])) {
					$end = (int) $matches[2];
				}
			}
			status_header(206);
			header("Content-Range: bytes {$start}-{$end}/{$file_size}");
		} else {
			status_header(200);
		}

		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . ($end - $start + 1));
		header('Cache-Control: no-cache, must-revalidate');
		header('Accept-Ranges: bytes');

		// Stream file
		$handle = fopen($file, 'rb');
		if ($handle) {
			fseek($handle, $start);
			$remaining = $end - $start + 1;
			while ($remaining > 0 && !feof($handle)) {
				$chunk_size = min(8192, $remaining);
				echo fread($handle, $chunk_size);
				$remaining -= $chunk_size;
				flush();
			}
			fclose($handle);
		}
		exit;
	}

	/**
	 * REST: Initiate backup on remote server.
	 */
	public function rest_initiate_backup(\WP_REST_Request $request): \WP_REST_Response {
		$params = $request->get_json_params();
		$components = isset($params['components']) ? (array) $params['components'] : array('database');
		$job_id = isset($params['job_id']) ? sanitize_text_field($params['job_id']) : uniqid('wudt_', true);

		// Ensure local API key exists
		$local_api_key = get_option('wudt_local_api_key');
		if (empty($local_api_key)) {
			$local_api_key = wp_generate_password(32, false, false);
			update_option('wudt_local_api_key', $local_api_key, false);
		}

		// Schedule async backup creation
		wp_schedule_single_event(time(), 'wudt_migration_create_remote_backup', array(
			'job_id' => $job_id,
			'components' => $components,
		));

		return new \WP_REST_Response(array(
			'success' => true,
			'job_id' => $job_id,
			'status' => 'initiated',
		), 200);
	}

	/**
	 * AJAX: Get configured remote sites.
	 */
	public function ajax_get_sites(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success(array('sites' => $this->get_sites()));
	}

	/**
	 * AJAX: Save remote site configuration.
	 */
	public function ajax_save_site(): void {
		Security_Guard::assert_ajax_admin();

		$site_id = isset($_POST['site_id']) ? sanitize_text_field((string) wp_unslash($_POST['site_id'])) : '';
		$label = isset($_POST['label']) ? sanitize_text_field((string) wp_unslash($_POST['label'])) : '';
		$url = isset($_POST['url']) ? esc_url_raw((string) wp_unslash($_POST['url'])) : '';
		$api_key = isset($_POST['api_key']) ? sanitize_text_field((string) wp_unslash($_POST['api_key'])) : '';

		if (empty($label) || empty($url) || empty($api_key)) {
			wp_send_json_error(array('message' => __('All fields are required.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		if (strlen($api_key) < 32) {
			wp_send_json_error(array('message' => __('API key must be at least 32 characters.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		$sites = $this->get_sites();

		if (empty($site_id)) {
			$site_id = 'site_' . uniqid();
		}

		$sites[$site_id] = array(
			'id' => $site_id,
			'label' => $label,
			'url' => rtrim($url, '/'),
			'api_key' => $api_key,
			'created_at' => current_time('mysql'),
		);

		update_option(self::OPTION_SITES, $sites, false);
		Operation_Logger::log('migration', 'Site configuration saved', array('site_id' => $site_id, 'label' => $label));

		wp_send_json_success(array('site' => $sites[$site_id]));
	}

	/**
	 * AJAX: Delete remote site configuration.
	 */
	public function ajax_delete_site(): void {
		Security_Guard::assert_ajax_admin();

		$site_id = isset($_POST['site_id']) ? sanitize_text_field((string) wp_unslash($_POST['site_id'])) : '';
		if (empty($site_id)) {
			wp_send_json_error(array('message' => __('Site ID required.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		$sites = $this->get_sites();
		if (isset($sites[$site_id])) {
			unset($sites[$site_id]);
			update_option(self::OPTION_SITES, $sites, false);
			Operation_Logger::log('migration', 'Site configuration deleted', array('site_id' => $site_id));
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Test connection to remote site.
	 */
	public function ajax_test_connection(): void {
		Security_Guard::assert_ajax_admin();

		$site_id = isset($_POST['site_id']) ? sanitize_text_field((string) wp_unslash($_POST['site_id'])) : '';
		$sites = $this->get_sites();

		if (empty($site_id) || !isset($sites[$site_id])) {
			wp_send_json_error(array('message' => __('Site not found.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		$site = $sites[$site_id];
		$result = $this->remote_client->test_connection($site['url'], $site['api_key']);

		if ($result['success']) {
			wp_send_json_success(array(
				'message' => __('Connection successful!', 'wp-ultimate-diagnostics-toolkit'),
				'site_info' => $result['data'],
			));
		} else {
			wp_send_json_error(array('message' => $result['error']));
		}
	}

	/**
	 * AJAX: Start migration job.
	 */
	public function ajax_start_migration(): void {
		Security_Guard::assert_ajax_admin();

		$site_id = isset($_POST['site_id']) ? sanitize_text_field((string) wp_unslash($_POST['site_id'])) : '';
		$components = isset($_POST['components']) ? (array) json_decode((string) wp_unslash($_POST['components']), true) : array('database');
		$direction = isset($_POST['direction']) ? sanitize_text_field((string) wp_unslash($_POST['direction'])) : 'pull'; // pull or push

		$sites = $this->get_sites();
		if (empty($site_id) || !isset($sites[$site_id])) {
			wp_send_json_error(array('message' => __('Site not found.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		$site = $sites[$site_id];

		// Check if job already running
		$active_job = $this->job_manager->get_active_job();
		if ($active_job) {
			wp_send_json_error(array('message' => __('A migration is already in progress.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		// Ensure local API key exists for push operations
		if ($direction === 'push') {
			$local_api_key = get_option('wudt_local_api_key');
			if (empty($local_api_key)) {
				$local_api_key = wp_generate_password(32, false, false);
				update_option('wudt_local_api_key', $local_api_key, false);
			}
		}

		// Create new job
		$job = $this->job_manager->create_job($site, $components, $direction);

		// Schedule job processing
		wp_schedule_single_event(time(), 'wudt_migration_process_job', array($job['job_id']));

		Operation_Logger::log('migration', 'Migration started', array(
			'job_id' => $job['job_id'],
			'site' => $site['label'],
			'direction' => $direction,
			'components' => $components,
		));

		wp_send_json_success(array('job' => $job));
	}

	/**
	 * AJAX: Get migration progress.
	 */
	public function ajax_get_progress(): void {
		Security_Guard::assert_ajax_admin();

		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';
		$progress = $this->job_manager->get_job_progress($job_id);

		wp_send_json_success(array('progress' => $progress));
	}

	/**
	 * AJAX: Cancel running migration.
	 */
	public function ajax_cancel_job(): void {
		Security_Guard::assert_ajax_admin();

		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';
		$job = $this->job_manager->get_job($job_id);

		if (!$job) {
			wp_send_json_error(array('message' => __('Job not found.', 'wp-ultimate-diagnostics-toolkit')));
			return;
		}

		// Update job status
		$this->job_manager->update_job_status($job_id, 'cancelled');
		set_transient(self::TRANSIENT_PREFIX . $job_id, array(
			'status' => 'cancelled',
			'percent' => 0,
			'message' => __('Migration cancelled', 'wp-ultimate-diagnostics-toolkit'),
		), 5 * MINUTE_IN_SECONDS);

		Operation_Logger::log('migration', 'Migration cancelled', array('job_id' => $job_id));
		wp_send_json_success();
	}

	/**
	 * AJAX: Get migration history.
	 */
	public function ajax_get_history(): void {
		Security_Guard::assert_ajax_admin();

		$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 20;
		$jobs = $this->job_manager->get_all_jobs($limit);

		wp_send_json_success(array('jobs' => $jobs));
	}

	/**
	 * AJAX: Delete migration job record.
	 */
	public function ajax_delete_job(): void {
		Security_Guard::assert_ajax_admin();

		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';
		$this->job_manager->delete_job($job_id);

		wp_send_json_success();
	}

	/**
	 * AJAX: Regenerate local API key.
	 */
	public function ajax_regenerate_key(): void {
		Security_Guard::assert_ajax_admin();

		$new_key = $this->generate_api_key();
		update_option('wudt_local_api_key', $new_key, false);

		Operation_Logger::log('migration', 'Local API key regenerated', array());

		wp_send_json_success(array('api_key' => $new_key));
	}

	/**
	 * Process migration job in background.
	 */
	public function process_migration_job(string $job_id): void {
		$job = $this->job_manager->get_job($job_id);
		if (!$job || $job['status'] === 'cancelled') {
			return;
		}

		if ($job['direction'] === 'pull') {
			$this->process_pull_migration($job);
		} else {
			$this->process_push_migration($job);
		}
	}

	/**
	 * Process pull migration: download from remote and restore locally.
	 */
	private function process_pull_migration(array $job): void {
		$job_id = $job['job_id'];
		$site = $job['source_site'];

		try {
			// Phase 1: Initiate backup on remote
			$this->job_manager->update_job_status($job_id, 'initiating');
			$this->set_job_progress($job_id, 'initiating', 5, __('Initiating backup on remote server...', 'wp-ultimate-diagnostics-toolkit'));

			$remote_job = $this->remote_client->initiate_backup(
				$site['url'],
				$site['api_key'],
				$job['components'],
				$job_id
			);

			if (!$remote_job['success']) {
				throw new \RuntimeException($remote_job['error'] ?? 'Failed to initiate remote backup');
			}

			$remote_job_id = $remote_job['data']['job_id'] ?? $job_id;

			// Phase 2: Poll for backup completion
			$this->job_manager->update_job_status($job_id, 'waiting');
			$this->set_job_progress($job_id, 'waiting', 10, __('Waiting for backup completion...', 'wp-ultimate-diagnostics-toolkit'));

			$backup_ready = false;
			$attempts = 0;
			$max_attempts = 300; // 15 minutes (3 second intervals)

			while (!$backup_ready && $attempts < $max_attempts) {
				sleep(3);
				$attempts++;

				// Check if job was cancelled
				$current_job = $this->job_manager->get_job($job_id);
				if ($current_job['status'] === 'cancelled') {
					return;
				}

				$status = $this->remote_client->get_backup_status(
					$site['url'],
					$site['api_key'],
					$remote_job_id
				);

				if ($status['success'] && isset($status['data']['status'])) {
					if ($status['data']['status'] === 'complete') {
						$backup_ready = true;
					} elseif ($status['data']['status'] === 'failed') {
						throw new \RuntimeException($status['data']['message'] ?? 'Remote backup failed');
					} else {
						// Update progress based on remote progress
						$percent = max(10, min(40, 10 + ($status['data']['percent'] ?? 0) * 0.3));
						$this->set_job_progress($job_id, 'waiting', (int) $percent, 
							$status['data']['message'] ?? __('Creating backup on remote...', 'wp-ultimate-diagnostics-toolkit')
						);
					}
				}
			}

			if (!$backup_ready) {
				throw new \RuntimeException(__('Backup creation timeout', 'wp-ultimate-diagnostics-toolkit'));
			}

			// Phase 3: Download backup
			$this->job_manager->update_job_status($job_id, 'downloading');

			$local_path = $this->stream_importer->download_backup(
				$job_id,
				$site['url'],
				$site['api_key'],
				$remote_job_id,
				function($percent, $message) use ($job_id) {
					$mapped_percent = 40 + (int) ($percent * 0.4); // Map 0-100 to 40-80
					$this->set_job_progress($job_id, 'downloading', $mapped_percent, $message);
				}
			);

			$this->job_manager->update_job_data($job_id, 'local_backup_path', $local_path);

			// Phase 4: Import/Restore
			$this->job_manager->update_job_status($job_id, 'importing');
			$this->set_job_progress($job_id, 'importing', 80, __('Restoring backup...', 'wp-ultimate-diagnostics-toolkit'));

			$result = $this->stream_importer->restore_backup(
				$local_path,
				$job['components'],
				$site['url'], // Old URL for search/replace
				home_url('/'), // New URL
				function($percent, $message) use ($job_id) {
					$mapped_percent = 80 + (int) ($percent * 0.2); // Map 0-100 to 80-100
					$this->set_job_progress($job_id, 'importing', $mapped_percent, $message);
				}
			);

			// Complete
			$this->job_manager->update_job_status($job_id, 'complete');
			$this->job_manager->update_job_data($job_id, 'completed_at', current_time('mysql'));
			$this->set_job_progress($job_id, 'complete', 100, __('Migration complete!', 'wp-ultimate-diagnostics-toolkit'));

			Operation_Logger::log('migration', 'Migration completed', array('job_id' => $job_id));

		} catch (\Exception $e) {
			$this->job_manager->update_job_status($job_id, 'failed');
			$this->job_manager->update_job_data($job_id, 'error', $e->getMessage());
			$this->set_job_progress($job_id, 'failed', 0, $e->getMessage());

			Operation_Logger::log('migration', 'Migration failed', array(
				'job_id' => $job_id,
				'error' => $e->getMessage(),
			));
		}
	}

	/**
	 * Process push migration: backup locally and send to remote.
	 */
	private function process_push_migration(array $job): void {
		$job_id = $job['job_id'];
		$site = $job['source_site'];

		try {
			// Phase 1: Create local backup
			$this->job_manager->update_job_status($job_id, 'backing_up');
			$this->set_job_progress($job_id, 'backing_up', 5, __('Creating local backup...', 'wp-ultimate-diagnostics-toolkit'));

			// Use Backup_Module to create backup
			$backup_module = new \WUDT\Modules\Backup\Backup_Module();
			$backup = $backup_module->create_backup_package($job['components'], true, '');

			$local_path = $backup['file'];
			$this->job_manager->update_job_data($job_id, 'local_backup_path', $local_path);
			$this->set_job_progress($job_id, 'backing_up', 40, __('Local backup created', 'wp-ultimate-diagnostics-toolkit'));

			// Phase 2: Upload to remote
			$this->job_manager->update_job_status($job_id, 'uploading');
			$this->set_job_progress($job_id, 'uploading', 45, __('Uploading to remote server...', 'wp-ultimate-diagnostics-toolkit'));

			$upload_result = $this->remote_client->upload_backup(
				$site['url'],
				$site['api_key'],
				$local_path,
				$job_id,
				function($percent, $message) use ($job_id) {
					$mapped_percent = 45 + (int) ($percent * 0.35); // Map 0-100 to 45-80
					$this->set_job_progress($job_id, 'uploading', $mapped_percent, $message);
				}
			);

			if (!$upload_result['success']) {
				throw new \RuntimeException($upload_result['error'] ?? 'Upload failed');
			}

			// Phase 3: Trigger restore on remote
			$this->job_manager->update_job_status($job_id, 'restoring_remote');
			$this->set_job_progress($job_id, 'restoring_remote', 85, __('Restoring on remote server...', 'wp-ultimate-diagnostics-toolkit'));

			$restore_result = $this->remote_client->trigger_restore(
				$site['url'],
				$site['api_key'],
				$job_id,
				$job['components'],
				home_url('/'), // Old URL
				$site['url'], // New URL
				function($percent, $message) use ($job_id) {
					$mapped_percent = 85 + (int) ($percent * 0.15); // Map 0-100 to 85-100
					$this->set_job_progress($job_id, 'restoring_remote', $mapped_percent, $message);
				}
			);

			if (!$restore_result['success']) {
				throw new \RuntimeException($restore_result['error'] ?? 'Remote restore failed');
			}

			// Complete
			$this->job_manager->update_job_status($job_id, 'complete');
			$this->job_manager->update_job_data($job_id, 'completed_at', current_time('mysql'));
			$this->set_job_progress($job_id, 'complete', 100, __('Migration complete!', 'wp-ultimate-diagnostics-toolkit'));

			Operation_Logger::log('migration', 'Push migration completed', array('job_id' => $job_id));

		} catch (\Exception $e) {
			$this->job_manager->update_job_status($job_id, 'failed');
			$this->job_manager->update_job_data($job_id, 'error', $e->getMessage());
			$this->set_job_progress($job_id, 'failed', 0, $e->getMessage());

			Operation_Logger::log('migration', 'Push migration failed', array(
				'job_id' => $job_id,
				'error' => $e->getMessage(),
			));
		}
	}

	/**
	 * Set job progress transient.
	 */
	private function set_job_progress(string $job_id, string $status, int $percent, string $message): void {
		set_transient(self::TRANSIENT_PREFIX . $job_id, array(
			'status' => $status,
			'percent' => max(0, min(100, $percent)),
			'message' => $message,
			'timestamp' => time(),
		), 30 * MINUTE_IN_SECONDS);
	}

	/**
	 * Get configured sites.
	 */
	private function get_sites(): array {
		return (array) get_option(self::OPTION_SITES, array());
	}
}
