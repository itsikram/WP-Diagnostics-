<?php
/**
 * Migration Scheduler - runs push and pull migrations on a schedule.
 *
 * A scheduled job runs without a browser: WP-Cron (or a server cron job that
 * calls the cron URL) checks the schedules every minute and starts due ones,
 * and each job then steps itself through a chain of background requests to
 * this site. When a request in the chain dies, the next check picks the job
 * up again, and a failed job is retried a few times before it is reported.
 *
 * Schedules live in a file, not the database: a pull replaces this site's
 * database (including the WP-Cron list, which is re-created on the next load).
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Operation_Logger;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Scheduler {
	public const CRON_HOOK = 'wudt_migration_scheduler_tick';
	private const CRON_INTERVAL = 'wudt_every_minute';
	public const FREQUENCIES = array('once', 'hourly', 'every6h', 'every12h', 'daily', 'weekly', 'monthly');
	private const MAX_SCHEDULES = 50;
	private const MAX_RUNS = 10;
	// Retries of a job that stopped with an error, each one a few minutes after the last.
	private const MAX_RETRIES = 3;
	private const RETRY_DELAY = 120;
	// Seconds a background request may run the job before handing over to the next one.
	private const STEP_BUDGET = 25.0;
	// Seconds without progress before a check restarts the background requests.
	private const KICK_AFTER = 90;
	// Seconds a due schedule waits while another migration is running.
	private const BUSY_DELAY = 300;

	private Migration_Module $module;

	public function __construct(Migration_Module $module) {
		$this->module = $module;
	}

	public function register_hooks(): void {
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action('init', array($this, 'ensure_cron'), 20);
		add_action(self::CRON_HOOK, array($this, 'cron_tick'));

		// Background steps authenticate with the job token; the loopback carries no login cookie.
		add_action('wp_ajax_nopriv_wudt_migration_bg_step', array($this, 'ajax_bg_step'));
		add_action('wp_ajax_wudt_migration_bg_step', array($this, 'ajax_bg_step'));
		// For a real cron job on the server, authenticated with the cron key.
		add_action('wp_ajax_nopriv_wudt_migration_cron', array($this, 'ajax_cron'));
		add_action('wp_ajax_wudt_migration_cron', array($this, 'ajax_cron'));

		add_action('wp_ajax_wudt_migration_save_schedule', array($this, 'ajax_save_schedule'));
		add_action('wp_ajax_wudt_migration_schedule_action', array($this, 'ajax_schedule_action'));
	}

	/* ------------------------------ Cron -------------------------------- */

	public function cron_schedules(array $schedules): array {
		$schedules[self::CRON_INTERVAL] = array(
			'interval' => 60,
			'display'  => __('Every minute (Diagnostics Toolkit migrations)', 'diagnostics-toolkit'),
		);
		return $schedules;
	}

	/**
	 * Keep the check scheduled while there is something to run. Also re-adds it
	 * after a pull replaced the database (and with it the WP-Cron list).
	 */
	public function ensure_cron(): void {
		// Runs on every request: only read the schedules when the event is missing.
		if (wp_next_scheduled(self::CRON_HOOK) || ! self::needs_cron(self::read())) {
			return;
		}
		wp_schedule_event(time() + 30, self::CRON_INTERVAL, self::CRON_HOOK);
	}

	/**
	 * Add or remove the check after schedules changed.
	 */
	private function sync_cron(): void {
		if (! self::needs_cron(self::read())) {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		} elseif (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 30, self::CRON_INTERVAL, self::CRON_HOOK);
		}
	}

	private static function needs_cron(array $data): bool {
		if (! empty($data['current'])) {
			return true;
		}
		foreach ($data['schedules'] as $schedule) {
			if (! empty($schedule['enabled'])) {
				return true;
			}
		}
		return false;
	}

	public function cron_tick(): void {
		$this->tick('wp-cron');
	}

	/**
	 * admin-ajax.php?action=wudt_migration_cron&key=… for a server cron job, so
	 * schedules run on time even when nobody visits the site.
	 */
	public function ajax_cron(): void {
		$key = sanitize_text_field(wp_unslash($_REQUEST['key'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		$data = self::read();
		if ('' === $key || '' === (string) $data['cron_key'] || ! hash_equals((string) $data['cron_key'], $key)) {
			wp_send_json_error(array('message' => 'Invalid cron key.'), 403);
		}
		$result = $this->tick('server-cron');
		wp_send_json_success($result);
	}

	/**
	 * Check on the running scheduled job and start the next due schedule.
	 *
	 * @return array{action:string,job?:string}
	 */
	public function tick(string $source = 'wp-cron'): array {
		$lock = self::lock();
		if (! $lock) {
			return array('action' => 'busy');
		}
		$kick = null;
		$inline = null;
		$notify = array();
		$result = array('action' => 'idle');
		try {
			$data = self::read();
			$data['last_tick'] = time();
			$data['last_tick_source'] = $source;

			if (! empty($data['current'])) {
				$result = $this->check_current($data, $kick, $inline, $notify);
			} else {
				$due = $this->next_due($data);
				if (null !== $due) {
					$result = $this->start_schedule($data, $due, false, $kick, $notify);
				}
			}
			self::write($data);
		} finally {
			self::unlock($lock);
		}
		if (! self::needs_cron($data)) {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		}

		foreach ($notify as $mail) {
			self::send_mail($mail);
		}
		if (null !== $inline) {
			// The background requests did not get through (loopback blocked?): run a step here instead.
			$runner = Migration_Runner::load($inline['job']);
			if ($runner) {
				$runner->step(20.0);
				$this->after_step($runner);
			}
		} elseif (null !== $kick) {
			self::kick($kick['job'], $kick['token']);
		}
		return $result;
	}

	/**
	 * The running scheduled job: restart it when it stalled, retry it after an
	 * error, or record how it ended.
	 */
	private function check_current(array &$data, ?array &$kick, ?array &$inline, array &$notify): array {
		$cur = $data['current'];
		$runner = Migration_Runner::load((string) $cur['job']);
		if (! $runner) {
			$this->finish($data, 'failed', __('The migration job disappeared (it was deleted).', 'diagnostics-toolkit'), $notify);
			return array('action' => 'finished');
		}
		$state = $runner->get_state();
		$status = (string) $state['status'];
		$now = time();

		if ('running' === $status) {
			$idle = $now - (int) $state['updated'];
			if ($idle < self::KICK_AFTER) {
				return array('action' => 'running', 'job' => $state['id']);
			}
			// Kicked before and still no progress: the loopback requests are not arriving.
			if (! empty($cur['kicked']) && (int) $cur['kicked'] >= (int) $state['updated'] && $now - (int) $cur['kicked'] >= self::KICK_AFTER) {
				$inline = array('job' => $state['id']);
			} else {
				$kick = array('job' => $state['id'], 'token' => $state['token']);
			}
			$data['current']['kicked'] = $now;
			return array('action' => 'resumed', 'job' => $state['id']);
		}

		if ('failed' === $status) {
			$retries = (int) ($cur['retries'] ?? 0);
			if ($retries < self::MAX_RETRIES) {
				$retry_at = (int) ($cur['retry_at'] ?? 0);
				if (0 === $retry_at) {
					$data['current']['retry_at'] = $now + self::RETRY_DELAY * ($retries + 1);
					return array('action' => 'waiting', 'job' => $state['id']);
				}
				if ($now < $retry_at) {
					return array('action' => 'waiting', 'job' => $state['id']);
				}
				$data['current']['retries'] = $retries + 1;
				$data['current']['retry_at'] = 0;
				$data['current']['kicked'] = $now;
				$kick = array('job' => $state['id'], 'token' => $state['token']);
				return array('action' => 'retry', 'job' => $state['id']);
			}
			$this->finish($data, 'failed', (string) $state['error'], $notify);
			return array('action' => 'finished', 'job' => $state['id']);
		}

		$this->finish($data, $status, 'cancelled' === $status ? __('Cancelled.', 'diagnostics-toolkit') : '', $notify);
		return array('action' => 'finished', 'job' => $state['id']);
	}

	/**
	 * Record how the running scheduled job ended.
	 */
	private function finish(array &$data, string $status, string $error, array &$notify): void {
		$cur = $data['current'];
		$data['current'] = null;
		$id = (string) $cur['schedule'];
		if (! isset($data['schedules'][$id])) {
			return;
		}
		$schedule = &$data['schedules'][$id];
		$run = array(
			'job'      => (string) $cur['job'],
			'started'  => (int) $cur['started'],
			'finished' => time(),
			'status'   => $status,
			'error'    => $error,
			'retries'  => (int) ($cur['retries'] ?? 0),
			'manual'   => ! empty($cur['manual']),
		);
		$schedule['last_run'] = $run;
		array_unshift($schedule['runs'], $run);
		$schedule['runs'] = array_slice($schedule['runs'], 0, self::MAX_RUNS);
		if ('done' === $status) {
			$schedule['failures'] = 0;
		} elseif ('failed' === $status) {
			$schedule['failures'] = (int) ($schedule['failures'] ?? 0) + 1;
		}
		Operation_Logger::log('migration', 'Scheduled migration ' . $status, array('schedule' => $schedule['name'], 'job' => $run['job'], 'error' => $error));
		$mail = $this->mail_for($schedule, $run);
		if (null !== $mail) {
			$notify[] = $mail;
		}
	}

	private function next_due(array $data): ?string {
		$due = null;
		$due_at = PHP_INT_MAX;
		foreach ($data['schedules'] as $id => $schedule) {
			$at = (int) ($schedule['next_run'] ?? 0);
			if (! empty($schedule['enabled']) && $at > 0 && $at <= time() && $at < $due_at) {
				$due = (string) $id;
				$due_at = $at;
			}
		}
		return $due;
	}

	/**
	 * Start a schedule's migration (when it is due, or right away for "Run now").
	 */
	private function start_schedule(array &$data, string $id, bool $manual, ?array &$kick, array &$notify): array {
		$schedule = &$data['schedules'][$id];
		$now = time();

		if (Migration_Runner::active_job()) {
			if ($manual) {
				throw new \RuntimeException(__('Another migration is already running. Wait for it to finish or cancel it first.', 'diagnostics-toolkit'));
			}
			$schedule['next_run'] = $now + self::BUSY_DELAY;
			$schedule['note'] = __('Postponed: another migration was running.', 'diagnostics-toolkit');
			return array('action' => 'postponed');
		}

		if (! $manual) {
			// Missed runs are not made up: the next one is counted from now.
			if ('once' === $schedule['frequency']) {
				$schedule['enabled'] = false;
				$schedule['next_run'] = 0;
			} else {
				$schedule['next_run'] = self::compute_next($schedule, $now);
			}
		}
		$schedule['note'] = '';

		try {
			$site = $this->module->find_site((string) $schedule['site_id']);
			if (null === $site) {
				throw new \RuntimeException(__('The connected site of this schedule was removed. Add it again and edit the schedule.', 'diagnostics-toolkit'));
			}
			if (Remote_Client::unreachable_from_here((string) $site['url'])) {
				throw new \RuntimeException(__('This server cannot connect to that site.', 'diagnostics-toolkit'));
			}
			$runner = $this->module->start_job($site, (string) $schedule['direction'], (array) $schedule['options'], array('id' => $id, 'name' => (string) $schedule['name']));
		} catch (\Throwable $e) {
			$run = array('job' => '', 'started' => $now, 'finished' => time(), 'status' => 'failed', 'error' => $e->getMessage(), 'retries' => 0, 'manual' => $manual);
			$schedule['last_run'] = $run;
			array_unshift($schedule['runs'], $run);
			$schedule['runs'] = array_slice($schedule['runs'], 0, self::MAX_RUNS);
			$schedule['failures'] = (int) ($schedule['failures'] ?? 0) + 1;
			Operation_Logger::log('migration', 'Scheduled migration could not start', array('schedule' => $schedule['name'], 'error' => $e->getMessage()));
			$mail = $this->mail_for($schedule, $run);
			if (null !== $mail) {
				$notify[] = $mail;
			}
			return array('action' => 'start_failed', 'error' => $e->getMessage());
		}

		$state = $runner->get_state();
		$data['current'] = array(
			'schedule' => $id,
			'job'      => (string) $state['id'],
			'started'  => $now,
			'kicked'   => $now,
			'retries'  => 0,
			'retry_at' => 0,
			'manual'   => $manual,
		);
		$schedule['last_run'] = array('job' => (string) $state['id'], 'started' => $now, 'finished' => 0, 'status' => 'running', 'error' => '', 'retries' => 0, 'manual' => $manual);
		$kick = array('job' => (string) $state['id'], 'token' => (string) $state['token']);
		return array('action' => 'started', 'job' => (string) $state['id']);
	}

	/* ------------------------------ Background steps --------------------- */

	/**
	 * Start the next background step of a job with a request to this site that
	 * does not wait for the answer.
	 */
	public static function kick(string $job_id, string $token): void {
		wp_remote_post(admin_url('admin-ajax.php'), array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
			'body'      => array(
				'action' => 'wudt_migration_bg_step',
				'job_id' => $job_id,
				'token'  => $token,
			),
		));
	}

	public function ajax_bg_step(): void {
		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification -- authenticated by the job token.
		$token = sanitize_text_field(wp_unslash($_POST['token'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		try {
			$runner = Migration_Runner::load($job_id);
		} catch (\Throwable $e) {
			$runner = null;
		}
		if (! $runner || ! $runner->check_token($token) || empty($runner->get_state()['scheduled'])) {
			wp_send_json_error(array('message' => 'Migration job not found or access denied.'), 403);
		}
		// Finished and cancelled jobs stay that way (a failed one is resumed: it is a retry).
		if (! in_array($runner->get_state()['status'], array('running', 'failed'), true)) {
			wp_send_json_success(array('status' => $runner->get_state()['status']));
		}
		Migration_Engine::raise_limits();
		$summary = $runner->step(self::STEP_BUDGET);
		if (empty($summary['busy'])) {
			$this->after_step($runner);
		}
		wp_send_json_success(array('status' => $summary['status'], 'percent' => $summary['percent']));
	}

	/**
	 * Continue a running job in a new request, or record right away how it ended.
	 */
	private function after_step(Migration_Runner $runner): void {
		$state = $runner->get_state();
		if ('running' === $state['status']) {
			$lock = self::lock();
			if ($lock) {
				$data = self::read();
				if (! empty($data['current']) && $data['current']['job'] === $state['id']) {
					$data['current']['kicked'] = time();
					self::write($data);
				}
				self::unlock($lock);
			}
			self::kick((string) $state['id'], (string) $state['token']);
			return;
		}
		if (in_array($state['status'], array('done', 'cancelled'), true)) {
			$this->tick('job-end');
		}
	}

	/* ------------------------------ Admin AJAX -------------------------- */

	public function ajax_save_schedule(): void {
		Security_Guard::assert_ajax_admin();
		$id = sanitize_key((string) wp_unslash($_POST['schedule_id'] ?? ''));
		$site_id = sanitize_key((string) wp_unslash($_POST['site_id'] ?? ''));
		$direction = 'push' === sanitize_key(wp_unslash($_POST['direction'] ?? '')) ? 'push' : 'pull';
		try {
			$site = $this->module->find_site($site_id);
			if (null === $site) {
				throw new \RuntimeException(__('Site not found. Add it again.', 'diagnostics-toolkit'));
			}
			if (Remote_Client::unreachable_from_here((string) $site['url'])) {
				throw new \RuntimeException(__('This server cannot connect to that site, so it cannot run scheduled migrations with it. Schedule them on that site instead.', 'diagnostics-toolkit'));
			}
			$options = Migration_Module::job_options_from_request();
			if (empty($options['components']) && empty($options['tables']) && empty($options['tables_all']) && empty($options['files'])) {
				throw new \RuntimeException(__('Select at least one table or file component to migrate.', 'diagnostics-toolkit'));
			}
			$timing = self::timing_from_request();
			$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
			if ('' === $name) {
				$name = ('pull' === $direction ? __('Pull from', 'diagnostics-toolkit') : __('Push to', 'diagnostics-toolkit')) . ' ' . (string) $site['label'];
			}
			$notify = sanitize_key((string) wp_unslash($_POST['notify'] ?? 'failure'));
			$notify = in_array($notify, array('never', 'failure', 'always'), true) ? $notify : 'failure';
			$email = sanitize_email((string) wp_unslash($_POST['notify_email'] ?? ''));

			$lock = self::lock(10);
			if (! $lock) {
				throw new \RuntimeException(__('The scheduler is busy. Try again in a moment.', 'diagnostics-toolkit'));
			}
			try {
				$data = self::read();
				if ('' === $id || ! isset($data['schedules'][$id])) {
					if (count($data['schedules']) >= self::MAX_SCHEDULES) {
						throw new \RuntimeException(__('Too many schedules. Delete one first.', 'diagnostics-toolkit'));
					}
					$id = 'sch_' . strtolower(wp_generate_password(10, false, false));
					$data['schedules'][$id] = array(
						'id'       => $id,
						'created'  => time(),
						'last_run' => null,
						'runs'     => array(),
						'failures' => 0,
					);
				}
				$schedule = array_merge($data['schedules'][$id], $timing, array(
					'name'         => $name,
					'site_id'      => $site_id,
					'direction'    => $direction,
					'options'      => $options,
					'notify'       => $notify,
					'notify_email' => $email,
					'enabled'      => true,
					'note'         => '',
					'updated'      => time(),
				));
				$schedule['next_run'] = self::compute_next($schedule, time());
				$data['schedules'][$id] = $schedule;
				self::write($data);
			} finally {
				self::unlock($lock);
			}
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
		$this->sync_cron();
		Operation_Logger::log('migration', 'Migration schedule saved', array('schedule' => $name, 'direction' => $direction, 'site' => $site['url']));
		wp_send_json_success(array('schedule_id' => $id, 'state' => $this->module->state()));
	}

	public function ajax_schedule_action(): void {
		Security_Guard::assert_ajax_admin();
		$id = sanitize_key((string) wp_unslash($_POST['schedule_id'] ?? ''));
		$op = sanitize_key((string) wp_unslash($_POST['op'] ?? ''));
		$kick = null;
		$notify = array();
		$job = null;
		$message = '';
		$error = '';
		try {
			$lock = self::lock('run' === $op ? 60 : 10);
			if (! $lock) {
				throw new \RuntimeException(__('The scheduler is busy. Try again in a moment.', 'diagnostics-toolkit'));
			}
			try {
				$data = self::read();
				if (! isset($data['schedules'][$id])) {
					throw new \RuntimeException(__('Schedule not found.', 'diagnostics-toolkit'));
				}
				$schedule = &$data['schedules'][$id];
				switch ($op) {
					case 'pause':
						$schedule['enabled'] = false;
						$message = __('Schedule paused.', 'diagnostics-toolkit');
						break;
					case 'resume':
						if ('once' === $schedule['frequency'] && (int) $schedule['once_at'] <= time()) {
							throw new \RuntimeException(__('That date has passed. Edit the schedule and choose a new date.', 'diagnostics-toolkit'));
						}
						$schedule['enabled'] = true;
						$schedule['note'] = '';
						$schedule['next_run'] = self::compute_next($schedule, time());
						$message = __('Schedule turned on.', 'diagnostics-toolkit');
						break;
					case 'delete':
						unset($data['schedules'][$id]);
						$message = __('Schedule deleted.', 'diagnostics-toolkit');
						break;
					case 'run':
						if (! empty($data['current'])) {
							throw new \RuntimeException(__('A scheduled migration is already running.', 'diagnostics-toolkit'));
						}
						Migration_Engine::raise_limits();
						$started = $this->start_schedule($data, $id, true, $kick, $notify);
						if ('started' !== $started['action']) {
							// Saved below with the failed run, then reported.
							$error = (string) ($started['error'] ?? __('The migration could not start.', 'diagnostics-toolkit'));
							break;
						}
						$runner = Migration_Runner::load((string) $data['current']['job']);
						$job = $runner ? $runner->summary() : null;
						$message = __('The migration started and runs in the background. You can leave this page.', 'diagnostics-toolkit');
						break;
					default:
						throw new \RuntimeException('Unknown action.');
				}
				unset($schedule);
				self::write($data);
			} finally {
				self::unlock($lock);
			}
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}
		foreach ($notify as $mail) {
			self::send_mail($mail);
		}
		if ('' !== $error) {
			wp_send_json_error(array('message' => $error, 'state' => $this->module->state()));
		}
		if (null !== $kick) {
			self::kick($kick['job'], $kick['token']);
		}
		$this->sync_cron();
		wp_send_json_success(array('message' => $message, 'job' => $job, 'state' => $this->module->state()));
	}

	/* ------------------------------ Timing ------------------------------ */

	/**
	 * @return array{frequency:string,time:string,weekday:int,monthday:int,once_at:int}
	 */
	private static function timing_from_request(): array {
		$frequency = sanitize_key((string) wp_unslash($_POST['frequency'] ?? 'daily'));
		if (! in_array($frequency, self::FREQUENCIES, true)) {
			throw new \RuntimeException(__('Choose how often the migration runs.', 'diagnostics-toolkit'));
		}
		$time = sanitize_text_field(wp_unslash($_POST['time'] ?? '03:00'));
		if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
			throw new \RuntimeException(__('Enter the time as HH:MM, e.g. 03:00.', 'diagnostics-toolkit'));
		}
		$weekday = max(0, min(6, (int) ($_POST['weekday'] ?? 1)));
		$monthday = max(1, min(31, (int) ($_POST['monthday'] ?? 1)));
		$once_at = 0;
		if ('once' === $frequency) {
			$raw = sanitize_text_field(wp_unslash($_POST['once_at'] ?? ''));
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, wp_timezone());
			if (! $date) {
				throw new \RuntimeException(__('Choose the date and time to run the migration.', 'diagnostics-toolkit'));
			}
			$once_at = $date->getTimestamp();
			if ($once_at <= time()) {
				throw new \RuntimeException(__('Choose a date and time in the future.', 'diagnostics-toolkit'));
			}
			$time = $date->format('H:i');
		}
		return array(
			'frequency' => $frequency,
			'time'      => $time,
			'weekday'   => $weekday,
			'monthday'  => $monthday,
			'once_at'   => $once_at,
		);
	}

	/**
	 * The first run time after $after, in this site's time zone.
	 */
	public static function compute_next(array $schedule, int $after): int {
		$frequency = (string) ($schedule['frequency'] ?? 'daily');
		if ('once' === $frequency) {
			return (int) ($schedule['once_at'] ?? 0);
		}
		$tz = wp_timezone();
		$now = (new \DateTimeImmutable('@' . $after))->setTimezone($tz);
		list($hour, $minute) = array_map('intval', explode(':', (string) ($schedule['time'] ?? '03:00')) + array(0, 0));

		switch ($frequency) {
			case 'hourly':
				$next = $now->setTime((int) $now->format('G'), $minute);
				while ($next->getTimestamp() <= $after) {
					$next = $next->modify('+1 hour');
				}
				return $next->getTimestamp();
			case 'every6h':
			case 'every12h':
				$step = 'every6h' === $frequency ? 6 : 12;
				$next = $now->setTime($hour % $step, $minute);
				while ($next->getTimestamp() <= $after) {
					$next = $next->modify('+' . $step . ' hours');
				}
				return $next->getTimestamp();
			case 'weekly':
				$weekday = (int) ($schedule['weekday'] ?? 1);
				$next = $now->setTime($hour, $minute);
				for ($i = 0; $i < 8 && ((int) $next->format('w') !== $weekday || $next->getTimestamp() <= $after); $i++) {
					$next = $next->modify('+1 day')->setTime($hour, $minute);
				}
				return $next->getTimestamp();
			case 'monthly':
				$day = (int) ($schedule['monthday'] ?? 1);
				$month = $now->modify('first day of this month');
				for ($i = 0; $i < 3; $i++) {
					// Days past the end of a month run on its last day.
					$next = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min($day, (int) $month->format('t')))->setTime($hour, $minute);
					if ($next->getTimestamp() > $after) {
						return $next->getTimestamp();
					}
					$month = $month->modify('first day of next month');
				}
				return $next->getTimestamp();
			default: // daily
				$next = $now->setTime($hour, $minute);
				if ($next->getTimestamp() <= $after) {
					$next = $next->modify('+1 day')->setTime($hour, $minute);
				}
				return $next->getTimestamp();
		}
	}

	/* ------------------------------ Notifications ----------------------- */

	private function mail_for(array $schedule, array $run): ?array {
		$notify = (string) ($schedule['notify'] ?? 'failure');
		$ok = 'done' === $run['status'];
		if ('never' === $notify || ('failure' === $notify && ($ok || 'cancelled' === $run['status']))) {
			return null;
		}
		$to = (string) ($schedule['notify_email'] ?? '') ?: (string) get_option('admin_email');
		if ('' === $to) {
			return null;
		}
		$site = $this->module->find_site((string) $schedule['site_id']);
		$other = $site ? (string) $site['url'] : '';
		$what = 'pull' === $schedule['direction'] ? sprintf('Pull from %s', $other) : sprintf('Push to %s', $other);
		$status = $ok ? 'completed' : ('cancelled' === $run['status'] ? 'was cancelled' : 'failed');
		$lines = array(
			sprintf('Scheduled migration "%s" %s.', $schedule['name'], $status),
			'',
			'Site: ' . untrailingslashit(home_url()),
			'Migration: ' . $what,
			'Started: ' . wp_date('Y-m-d H:i', (int) $run['started']),
			'Finished: ' . wp_date('Y-m-d H:i', (int) ($run['finished'] ?: time())),
		);
		if ('' !== (string) $run['error']) {
			$lines[] = 'Error: ' . $run['error'];
		}
		if ((int) $run['retries'] > 0) {
			$lines[] = sprintf('Retried %d times.', (int) $run['retries']);
		}
		$next = (int) ($schedule['next_run'] ?? 0);
		if (! empty($schedule['enabled']) && $next > 0) {
			$lines[] = 'Next run: ' . wp_date('Y-m-d H:i', $next);
		}
		$lines[] = '';
		$lines[] = 'Details: ' . admin_url('admin.php?page=wudt-site-migration');
		return array(
			'to'      => $to,
			'subject' => sprintf('[%s] Scheduled migration %s: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $status, $schedule['name']),
			'body'    => implode("\n", $lines),
		);
	}

	private static function send_mail(array $mail): void {
		try {
			wp_mail($mail['to'], $mail['subject'], $mail['body']);
		} catch (\Throwable $e) {
			Operation_Logger::log('migration', 'Scheduled migration email failed', array('error' => $e->getMessage()));
		}
	}

	/* ------------------------------ State for the page ------------------ */

	public function public_state(): array {
		$data = self::read();
		$list = array();
		foreach ($data['schedules'] as $schedule) {
			$options = (array) $schedule['options'];
			$running = ! empty($data['current']) && $data['current']['schedule'] === $schedule['id'];
			$list[] = array(
				'id'           => $schedule['id'],
				'name'         => $schedule['name'],
				'site_id'      => $schedule['site_id'],
				'direction'    => $schedule['direction'],
				'frequency'    => $schedule['frequency'],
				'time'         => $schedule['time'],
				'weekday'      => (int) $schedule['weekday'],
				'monthday'     => (int) $schedule['monthday'],
				'once_at'      => (int) $schedule['once_at'],
				'once_local'   => $schedule['once_at'] ? wp_date('Y-m-d\TH:i', (int) $schedule['once_at']) : '',
				'enabled'      => ! empty($schedule['enabled']),
				'next_run'     => (int) $schedule['next_run'],
				'next_text'    => $schedule['enabled'] && $schedule['next_run'] ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $schedule['next_run']) : '',
				'notify'       => $schedule['notify'],
				'notify_email' => $schedule['notify_email'],
				'note'         => (string) ($schedule['note'] ?? ''),
				'last_run'     => $schedule['last_run'],
				'runs'         => $schedule['runs'],
				'failures'     => (int) ($schedule['failures'] ?? 0),
				'running'      => $running,
				'job'          => $running ? (string) $data['current']['job'] : '',
				// Same shape as a job summary, so the wizard can restore the choices.
				'options'      => array(
					'selected_tables' => (array) $options['tables'],
					'tables_all'      => ! empty($options['tables_all']),
					'components'      => (array) $options['components'],
					'skip_unchanged'  => ! empty($options['skip_unchanged']),
					'override'        => ! empty($options['override']),
					'keep_backup'     => ! empty($options['keep_backup']),
					'repo'            => array('enabled' => ! empty($options['repo'])),
					'live_media'      => ! empty($options['live_media']) ? array('mode' => $options['live_media_mode']) : null,
					'files'           => (array) $options['files'],
					'files_count'     => count((array) $options['files']),
					'db_mode'         => $options['db_mode'],
					'merge_groups'    => (array) $options['merge_groups'],
					'post_types'      => (array) $options['post_types'],
					'post_type_sync'  => $options['post_type_sync'],
					'excludes'        => (array) $options['excludes'],
					'exclude_dev'     => ! empty($options['exclude_dev']),
					'pt_filter'       => $options['pt_filter'],
					'pt_selected'     => array('plugins' => (array) $options['pt_plugins'], 'themes' => (array) $options['pt_themes']),
				),
			);
		}
		usort($list, static function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});
		if ('' === (string) $data['cron_key']) {
			$data['cron_key'] = bin2hex(random_bytes(16));
			$lock = self::lock(5);
			if ($lock) {
				$fresh = self::read();
				if ('' === (string) $fresh['cron_key']) {
					$fresh['cron_key'] = $data['cron_key'];
					self::write($fresh);
				} else {
					$data['cron_key'] = $fresh['cron_key'];
				}
				self::unlock($lock);
			}
		}
		$next_cron = wp_next_scheduled(self::CRON_HOOK);
		return array(
			'schedules' => $list,
			'runner'    => array(
				'last_tick'        => (int) $data['last_tick'],
				'last_tick_source' => (string) $data['last_tick_source'],
				'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
				'next_cron'        => $next_cron ? (int) $next_cron : 0,
				'cron_url'         => add_query_arg(array('action' => 'wudt_migration_cron', 'key' => $data['cron_key']), admin_url('admin-ajax.php')),
				'timezone'         => wp_timezone_string(),
				'now_local'        => wp_date('Y-m-d H:i'),
			),
		);
	}

	/* ------------------------------ Storage ----------------------------- */

	private static function file(bool $create = true): string {
		$dir = $create ? Migration_Engine::storage_dir('schedules') : wp_normalize_path(WP_CONTENT_DIR) . '/wudt-migrations/schedules';
		return $dir . '/schedules.json';
	}

	private static function read(): array {
		$file = self::file(false);
		$data = file_exists($file) ? json_decode((string) file_get_contents($file), true) : null;
		$data = is_array($data) ? $data : array();
		$data += array(
			'schedules'        => array(),
			'current'          => null,
			'cron_key'         => '',
			'last_tick'        => 0,
			'last_tick_source' => '',
		);
		$data['schedules'] = is_array($data['schedules']) ? $data['schedules'] : array();
		foreach ($data['schedules'] as $id => $schedule) {
			$data['schedules'][$id] = (array) $schedule + array(
				'id' => (string) $id, 'name' => '', 'site_id' => '', 'direction' => 'pull', 'options' => array(),
				'frequency' => 'daily', 'time' => '03:00', 'weekday' => 1, 'monthday' => 1, 'once_at' => 0,
				'enabled' => false, 'next_run' => 0, 'notify' => 'failure', 'notify_email' => '', 'note' => '',
				'last_run' => null, 'runs' => array(), 'failures' => 0,
			);
			$data['schedules'][$id]['options'] = (array) $data['schedules'][$id]['options'] + array(
				'components' => array(), 'tables' => array(), 'tables_all' => false, 'skip_unchanged' => false,
				'override' => false, 'excludes' => array(), 'exclude_dev' => false, 'db_mode' => 'replace',
				'merge_groups' => array(), 'post_types' => array(), 'post_type_sync' => 'replace', 'pt_filter' => 'all', 'pt_plugins' => array(), 'pt_themes' => array(),
				'files' => array(), 'keep_backup' => true, 'repo' => false, 'live_media' => false, 'live_media_mode' => 'missing',
			);
		}
		return $data;
	}

	private static function write(array $data): void {
		$file = self::file();
		$tmp = $file . '.' . getmypid() . '.tmp';
		// Write then rename, so a reader never sees a half-written file.
		if (false === @file_put_contents($tmp, wp_json_encode($data)) || ! @rename($tmp, $file)) {
			@unlink($tmp);
			file_put_contents($file, wp_json_encode($data), LOCK_EX);
		}
	}

	/**
	 * @return resource|null
	 */
	private static function lock(int $wait = 0) {
		$handle = @fopen(Migration_Engine::storage_dir('schedules') . '/schedules.lock', 'c');
		if (! $handle) {
			return null;
		}
		$until = microtime(true) + $wait;
		do {
			if (flock($handle, LOCK_EX | LOCK_NB)) {
				return $handle;
			}
			usleep(200000);
		} while (microtime(true) < $until);
		fclose($handle);
		return null;
	}

	/**
	 * @param resource $handle
	 */
	private static function unlock($handle): void {
		flock($handle, LOCK_UN);
		fclose($handle);
	}

	/**
	 * Remove the WP-Cron event (plugin deactivation).
	 */
	public static function clear_cron(): void {
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}
}
