<?php
/**
 * Backup & restore module (admin controller).
 */

declare(strict_types=1);

namespace WUDT\Modules\Backup;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;
use WUDT\Modules\Migration\Migration_Engine;

if (! defined('ABSPATH')) {
	exit;
}

class Backup_Module extends Module_Base {
	private const OPTION_SCHEDULE = 'wudt_backup_schedule';
	private const CRON_HOOK = 'wudt_scheduled_backup_event';
	private const CONTINUE_HOOK = 'wudt_backup_continue';

	public function register_hooks(): void {
		$admin = array(
			'wudt_backup_state'          => 'ajax_state',
			'wudt_backup_start'          => 'ajax_start',
			'wudt_backup_inspect'        => 'ajax_inspect',
			'wudt_backup_restore_start'  => 'ajax_restore_start',
			'wudt_backup_delete'         => 'ajax_delete',
			'wudt_backup_download'       => 'ajax_download',
			'wudt_backup_upload_chunk'   => 'ajax_upload_chunk',
			'wudt_backup_schedule'       => 'ajax_schedule',
			'wudt_backup_rollback'       => 'ajax_rollback',
			'wudt_backup_discard_rollback' => 'ajax_discard_rollback',
		);
		foreach ($admin as $action => $method) {
			add_action('wp_ajax_' . $action, array($this, $method));
		}
		// Steps authenticate with the job token: restoring the users table logs the admin out.
		foreach (array('wudt_backup_step' => 'ajax_step', 'wudt_backup_cancel' => 'ajax_cancel') as $action => $method) {
			add_action('wp_ajax_' . $action, array($this, $method));
			add_action('wp_ajax_nopriv_' . $action, array($this, $method));
		}
		add_action(self::CRON_HOOK, array($this, 'run_scheduled_backup'));
		add_action(self::CONTINUE_HOOK, array($this, 'continue_scheduled_backup'), 10, 1);
	}

	public function get_key(): string {
		return 'backup_suite';
	}

	public function get_label(): string {
		return __('Backups', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return $this->state();
	}

	private function state(): array {
		$active = Backup_Runner::active_job();
		$schedule = $this->schedule();
		$next = wp_next_scheduled(self::CRON_HOOK);
		return array(
			'backups'    => array_map(static function ($b) {
				unset($b['path']);
				return $b;
			}, Backup_Store::all()),
			'active_job' => $active ? $active->summary() : null,
			'schedule'   => $schedule,
			'next_run'   => $next ? (int) $next : 0,
			'rollback'   => (new Migration_Engine())->rollback_summary(),
			'components' => array_merge(array('database'), Migration_Engine::COMPONENTS),
			'limits'     => array(
				'free_space' => function_exists('disk_free_space') ? (int) @disk_free_space(WP_CONTENT_DIR) : 0,
				'zip'        => class_exists('ZipArchive'),
			),
		);
	}

	private function schedule(): array {
		$s = get_option(self::OPTION_SCHEDULE, array());
		$s = is_array($s) ? $s : array();
		return array(
			'enabled'    => ! empty($s['enabled']),
			'frequency'  => in_array($s['frequency'] ?? '', array('daily', 'twicedaily', 'weekly'), true) ? $s['frequency'] : 'daily',
			'components' => ! empty($s['components']) && is_array($s['components']) ? array_values($s['components']) : array('database', 'plugins', 'themes', 'uploads'),
			'keep'       => max(1, min(30, (int) ($s['keep'] ?? 5))),
		);
	}

	/* ------------------------------ AJAX ------------------------------- */

	public function ajax_state(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success($this->state());
	}

	public function ajax_start(): void {
		Security_Guard::assert_ajax_admin();
		try {
			if (Backup_Runner::active_job()) {
				throw new \RuntimeException(__('Another backup or restore is running. Wait for it to finish.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$components = json_decode((string) wp_unslash($_POST['components'] ?? '[]'), true);
			$runner = Backup_Runner::create_backup(is_array($components) ? $components : array(), (string) wp_unslash($_POST['note'] ?? ''));
			$state = $runner->get_state();
			wp_send_json_success(array('job' => $runner->summary(), 'token' => $state['token']));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_step(): void {
		$runner = $this->require_job();
		wp_send_json_success(array('job' => $runner->step(20.0)));
	}

	public function ajax_cancel(): void {
		$runner = $this->require_job();
		try {
			wp_send_json_success(array('job' => $runner->cancel()));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_inspect(): void {
		Security_Guard::assert_ajax_admin();
		try {
			$info = Backup_Runner::inspect(Backup_Store::path((string) wp_unslash($_POST['name'] ?? '')));
			wp_send_json_success(array(
				'format'     => $info['format'],
				'components' => $info['components'],
				'site'       => array('home' => $info['site']['home'] ?? '', 'name' => $info['site']['name'] ?? '', 'wp_version' => $info['site']['wp_version'] ?? ''),
				'created'    => $info['created'],
				'note'       => $info['note'],
				'files'      => $info['file_count'],
				'same_site'  => untrailingslashit((string) ($info['site']['home'] ?? '')) === untrailingslashit(home_url()),
			));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_restore_start(): void {
		Security_Guard::assert_ajax_admin();
		try {
			if (Backup_Runner::active_job()) {
				throw new \RuntimeException(__('Another backup or restore is running. Wait for it to finish.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$components = json_decode((string) wp_unslash($_POST['components'] ?? '[]'), true);
			$runner = Backup_Runner::create_restore(Backup_Store::path((string) wp_unslash($_POST['name'] ?? '')), is_array($components) ? $components : array());
			$state = $runner->get_state();
			wp_send_json_success(array('job' => $runner->summary(), 'token' => $state['token']));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_delete(): void {
		Security_Guard::assert_ajax_admin();
		try {
			Backup_Store::delete((string) wp_unslash($_POST['name'] ?? ''));
			Operation_Logger::log('backup', 'Backup deleted', array('name' => (string) wp_unslash($_POST['name'] ?? '')));
			wp_send_json_success($this->state());
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	/**
	 * Stream a backup to an administrator (backups are never publicly reachable).
	 */
	public function ajax_download(): void {
		if (! current_user_can('manage_options') || ! wp_verify_nonce((string) wp_unslash($_GET['nonce'] ?? ''), 'wudt_admin_nonce')) {
			wp_die(esc_html__('Your session expired. Reload the page and try again.', 'wp-ultimate-diagnostics-toolkit'), 403);
		}
		try {
			$path = Backup_Store::path((string) wp_unslash($_GET['name'] ?? ''));
		} catch (\Throwable $e) {
			wp_die(esc_html($e->getMessage()), 404);
		}
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
		@set_time_limit(0);
		nocache_headers();
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
		header('Content-Length: ' . (string) filesize($path));
		$fh = fopen($path, 'rb');
		while ($fh && ! feof($fh)) {
			echo fread($fh, 1048576); // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
		}
		if ($fh) {
			fclose($fh);
		}
		exit;
	}

	/**
	 * Receive a backup file from the browser in chunks (works past upload limits).
	 */
	public function ajax_upload_chunk(): void {
		Security_Guard::assert_ajax_admin();
		try {
			$upload_id = preg_replace('/[^a-zA-Z0-9]/', '', (string) wp_unslash($_POST['upload_id'] ?? ''));
			$offset = (int) ($_POST['offset'] ?? 0);
			$total = (int) ($_POST['total'] ?? 0);
			$name = sanitize_file_name((string) wp_unslash($_POST['name'] ?? 'backup.zip'));
			if (strlen((string) $upload_id) < 12 || ! preg_match('/\.zip$/i', $name)) {
				throw new \RuntimeException(__('Only .zip backup files can be uploaded.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$file = $_FILES['chunk'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if (! is_array($file) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file((string) $file['tmp_name'])) {
				throw new \RuntimeException(__('A part of the upload was rejected by the server. Try again.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$dir = Backup_Runner::backup_dir();
			$partial = $dir . '/.upload-' . $upload_id . '.part';
			$current = is_file($partial) ? (int) filesize($partial) : 0;
			if ($offset !== $current) {
				wp_send_json_success(array('received' => $current, 'resync' => true));
			}
			$in = fopen((string) $file['tmp_name'], 'rb');
			$out = fopen($partial, 'ab');
			stream_copy_to_stream($in, $out);
			fclose($in);
			fclose($out);
			clearstatcache(true, $partial);
			$received = (int) filesize($partial);
			if ($total > 0 && $received >= $total) {
				$final = $dir . '/' . $name;
				if (file_exists($final)) {
					$final = $dir . '/' . preg_replace('/\.zip$/i', '', $name) . '-' . strtolower(wp_generate_password(4, false, false)) . '.zip';
				}
				rename($partial, $final);
				try {
					Backup_Runner::inspect($final);
				} catch (\Throwable $e) {
					@unlink($final);
					throw $e;
				}
				Backup_Store::save_meta(basename($final), array('note' => __('Uploaded', 'wp-ultimate-diagnostics-toolkit'), 'components' => Backup_Runner::inspect($final)['components'], 'trigger' => 'uploaded', 'created' => time()));
				Operation_Logger::log('backup', 'Backup uploaded', array('name' => basename($final)));
				wp_send_json_success(array('received' => $received, 'done' => true, 'name' => basename($final), 'state' => $this->state()));
			}
			wp_send_json_success(array('received' => $received));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_schedule(): void {
		Security_Guard::assert_ajax_admin();
		$components = json_decode((string) wp_unslash($_POST['components'] ?? '[]'), true);
		$config = array(
			'enabled'    => ! empty($_POST['enabled']),
			'frequency'  => sanitize_key((string) wp_unslash($_POST['frequency'] ?? 'daily')),
			'components' => is_array($components) ? array_values(array_intersect(array_merge(array('database'), Migration_Engine::COMPONENTS), $components)) : array(),
			'keep'       => max(1, min(30, (int) ($_POST['keep'] ?? 5))),
		);
		update_option(self::OPTION_SCHEDULE, $config, false);
		$config = $this->schedule();
		wp_clear_scheduled_hook(self::CRON_HOOK);
		if ($config['enabled'] && ! empty($config['components'])) {
			wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, $config['frequency'], self::CRON_HOOK);
		}
		Operation_Logger::log('backup', 'Backup schedule updated', $config);
		wp_send_json_success($this->state());
	}

	public function ajax_rollback(): void {
		Security_Guard::assert_ajax_admin();
		Migration_Engine::raise_limits();
		try {
			$result = (new Migration_Engine())->rollback();
			wp_send_json_success(array(
				/* translators: 1: tables, 2: files */
				'message' => sprintf(__('Rolled back: %1$d tables and %2$d files restored to how they were before.', 'wp-ultimate-diagnostics-toolkit'), (int) $result['tables'], (int) $result['files']),
			));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_discard_rollback(): void {
		Security_Guard::assert_ajax_admin();
		(new Migration_Engine())->discard_rollback();
		wp_send_json_success($this->state());
	}

	private function require_job(): Backup_Runner {
		$id = (string) wp_unslash($_POST['job_id'] ?? '');
		$token = (string) wp_unslash($_POST['token'] ?? '');
		try {
			$runner = Backup_Runner::load($id);
		} catch (\Throwable $e) {
			$runner = null;
		}
		$is_admin = current_user_can('manage_options') && false !== check_ajax_referer('wudt_admin_nonce', 'nonce', false);
		if (! $runner || (! $runner->check_token($token) && ! $is_admin)) {
			wp_send_json_error(array('message' => __('Job not found or access denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		return $runner;
	}

	/* ------------------------------ Cron -------------------------------- */

	public function run_scheduled_backup(): void {
		$config = $this->schedule();
		if (! $config['enabled'] || empty($config['components']) || Backup_Runner::active_job()) {
			return;
		}
		try {
			$runner = Backup_Runner::create_backup($config['components'], __('Scheduled backup', 'wp-ultimate-diagnostics-toolkit'), 'scheduled');
			$this->continue_scheduled_backup($runner->get_state()['id']);
		} catch (\Throwable $e) {
			Operation_Logger::log('backup', 'Scheduled backup failed', array('error' => $e->getMessage()));
		}
	}

	public function continue_scheduled_backup($job_id): void {
		$runner = Backup_Runner::load((string) $job_id);
		if (! $runner) {
			return;
		}
		$limit = (int) ini_get('max_execution_time');
		$summary = $runner->run_to_end($limit > 0 ? max(15, $limit - 15) : 240);
		if ('running' === $summary['status']) {
			wp_schedule_single_event(time() + 30, self::CONTINUE_HOOK, array((string) $job_id));
		} elseif ('done' === $summary['status']) {
			Backup_Store::apply_retention($this->schedule()['keep']);
		}
	}

	/* ------------------------------ API --------------------------------- */

	/**
	 * Create a complete backup synchronously (used before risky operations).
	 *
	 * @param array<int,string> $components
	 * @return array<string,mixed>
	 */
	public function create_backup_package(array $components, bool $gzip = false, string $password = ''): array {
		unset($gzip, $password);
		$map = array('core' => null);
		$components = array_values(array_filter($components, static function ($c) use ($map) {
			return ! array_key_exists($c, $map);
		}));
		$runner = Backup_Runner::create_backup($components, __('Automatic safety backup', 'wp-ultimate-diagnostics-toolkit'), 'auto');
		$summary = $runner->run_to_end();
		if ('done' !== $summary['status']) {
			throw new \RuntimeException($summary['error'] ?: __('Backup did not complete.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$state = $runner->get_state();
		return array(
			'file'       => $state['file'],
			'name'       => $state['name'],
			'size'       => (int) filesize($state['file']),
			'components' => $state['components'],
			'time'       => current_time('mysql'),
		);
	}
}
