<?php
/**
 * Migration Job - Job queue and status management.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Job {
	private const OPTION_JOBS = 'wudt_migration_jobs';
	private const MAX_JOBS = 100;
	private const ACTIVE_STATUSES = array('pending', 'initiating', 'waiting', 'downloading', 'uploading', 'backing_up', 'importing', 'restoring_remote');

	/**
	 * Create a new migration job.
	 */
	public function create_job(array $source_site, array $components, string $direction = 'pull'): array {
		$job_id = $this->generate_job_id();
		
		$job = array(
			'job_id' => $job_id,
			'source_site' => $source_site,
			'direction' => $direction, // 'pull' or 'push'
			'components' => $components,
			'status' => 'pending',
			'progress' => 0,
			'local_backup_path' => '',
			'started_at' => current_time('mysql'),
			'completed_at' => null,
			'error' => '',
			'message' => __('Job created', 'wp-ultimate-diagnostics-toolkit'),
		);

		$jobs = $this->get_all_jobs(self::MAX_JOBS);
		array_unshift($jobs, $job);
		
		// Limit job history
		$jobs = array_slice($jobs, 0, self::MAX_JOBS);
		
		update_option(self::OPTION_JOBS, $jobs, false);
		
		return $job;
	}

	/**
	 * Get job by ID.
	 */
	public function get_job(string $job_id): ?array {
		$jobs = $this->get_all_jobs();
		
		foreach ($jobs as $job) {
			if ($job['job_id'] === $job_id) {
				return $job;
			}
		}
		
		return null;
	}

	/**
	 * Get all jobs.
	 */
	public function get_all_jobs(int $limit = 100): array {
		$jobs = (array) get_option(self::OPTION_JOBS, array());
		return array_slice($jobs, 0, $limit);
	}

	/**
	 * Get recent completed jobs.
	 */
	public function get_recent_jobs(int $limit = 10): array {
		$jobs = $this->get_all_jobs($limit * 2);
		$completed = array_filter($jobs, function($job) {
			return in_array($job['status'], array('complete', 'failed', 'cancelled'), true);
		});
		return array_slice(array_values($completed), 0, $limit);
	}

	/**
	 * Get currently active job.
	 */
	public function get_active_job(): ?array {
		$jobs = $this->get_all_jobs();
		
		foreach ($jobs as $job) {
			if (in_array($job['status'], self::ACTIVE_STATUSES, true)) {
				return $job;
			}
		}
		
		return null;
	}

	/**
	 * Check if there is an active job.
	 */
	public function has_active_job(): bool {
		return $this->get_active_job() !== null;
	}

	/**
	 * Update job status.
	 */
	public function update_job_status(string $job_id, string $status): bool {
		$jobs = $this->get_all_jobs();
		
		foreach ($jobs as &$job) {
			if ($job['job_id'] === $job_id) {
				$job['status'] = $status;
				if ($status === 'complete' || $status === 'failed') {
					$job['completed_at'] = current_time('mysql');
				}
				update_option(self::OPTION_JOBS, $jobs, false);
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Update specific job data field.
	 */
	public function update_job_data(string $job_id, string $field, $value): bool {
		$jobs = $this->get_all_jobs();
		
		foreach ($jobs as &$job) {
			if ($job['job_id'] === $job_id) {
				$job[$field] = $value;
				update_option(self::OPTION_JOBS, $jobs, false);
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Update job progress.
	 */
	public function update_job_progress(string $job_id, int $progress, string $message = ''): bool {
		$jobs = $this->get_all_jobs();
		
		foreach ($jobs as &$job) {
			if ($job['job_id'] === $job_id) {
				$job['progress'] = max(0, min(100, $progress));
				if (!empty($message)) {
					$job['message'] = $message;
				}
				update_option(self::OPTION_JOBS, $jobs, false);
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Get job progress from transient.
	 */
	public function get_job_progress(string $job_id): array {
		$transient_key = 'wudt_migration_progress_' . $job_id;
		$progress = get_transient($transient_key);
		
		if (!is_array($progress)) {
			$job = $this->get_job($job_id);
			if ($job) {
				return array(
					'status' => $job['status'],
					'percent' => $job['progress'],
					'message' => $job['message'] ?? '',
					'timestamp' => time(),
				);
			}
			return array(
				'status' => 'unknown',
				'percent' => 0,
				'message' => __('Job not found', 'wp-ultimate-diagnostics-toolkit'),
				'timestamp' => time(),
			);
		}
		
		return $progress;
	}

	/**
	 * Delete a job.
	 */
	public function delete_job(string $job_id): bool {
		$jobs = $this->get_all_jobs();
		$found = false;
		
		foreach ($jobs as $key => $job) {
			if ($job['job_id'] === $job_id) {
				// Clean up downloaded file if exists
				if (!empty($job['local_backup_path']) && file_exists($job['local_backup_path'])) {
					unlink($job['local_backup_path']);
				}
				// Clean up transient
				delete_transient('wudt_migration_progress_' . $job_id);
				unset($jobs[$key]);
				$found = true;
				break;
			}
		}
		
		if ($found) {
			$jobs = array_values($jobs);
			update_option(self::OPTION_JOBS, $jobs, false);
		}
		
		return $found;
	}

	/**
	 * Cancel a job.
	 */
	public function cancel_job(string $job_id): bool {
		$job = $this->get_job($job_id);
		
		if (!$job) {
			return false;
		}
		
		// Can only cancel active jobs
		if (!in_array($job['status'], self::ACTIVE_STATUSES, true)) {
			return false;
		}
		
		return $this->update_job_status($job_id, 'cancelled');
	}

	/**
	 * Clean up old completed jobs.
	 */
	public function cleanup_old_jobs(int $days = 30): int {
		$jobs = $this->get_all_jobs(self::MAX_JOBS);
		$cutoff = strtotime("-{$days} days");
		$removed = 0;
		
		foreach ($jobs as $key => $job) {
			if (in_array($job['status'], array('complete', 'failed', 'cancelled'), true)) {
				$completed_time = strtotime($job['completed_at'] ?? $job['started_at']);
				if ($completed_time < $cutoff) {
					// Clean up file
					if (!empty($job['local_backup_path']) && file_exists($job['local_backup_path'])) {
						unlink($job['local_backup_path']);
					}
					delete_transient('wudt_migration_progress_' . $job['job_id']);
					unset($jobs[$key]);
					$removed++;
				}
			}
		}
		
		if ($removed > 0) {
			$jobs = array_values($jobs);
			update_option(self::OPTION_JOBS, $jobs, false);
		}
		
		return $removed;
	}

	/**
	 * Get job statistics.
	 */
	public function get_statistics(): array {
		$jobs = $this->get_all_jobs();
		$stats = array(
			'total' => count($jobs),
			'pending' => 0,
			'running' => 0,
			'complete' => 0,
			'failed' => 0,
			'cancelled' => 0,
		);
		
		foreach ($jobs as $job) {
			if (in_array($job['status'], self::ACTIVE_STATUSES, true)) {
				$stats['running']++;
			} else {
				if (isset($stats[$job['status']])) {
					$stats[$job['status']]++;
				}
			}
		}
		
		return $stats;
	}

	/**
	 * Generate unique job ID.
	 */
	private function generate_job_id(): string {
		return 'wudt_' . uniqid() . '_' . wp_generate_password(8, false, false);
	}
}
