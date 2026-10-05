<?php
/**
 * Progress Tracker Class
 * Tracks and manages progress of background debugging operations
 */

declare(strict_types=1);

namespace WUDT\Modules\ProgressMonitor;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Progress_Tracker
 * 
 * Manages progress state for long-running debugging operations
 * like malware scans, file integrity checks, etc.
 */
class Progress_Tracker {
	/** @var string Operation ID */
	private string $operation_id;

	/** @var string Operation type */
	private string $operation_type;

	/** @var string Transient key for storing progress */
	private string $transient_key;

	/**
	 * Constructor
	 *
	 * @param string $operation_type Type of operation (malware_scan, file_integrity, etc.)
	 * @param string|null $operation_id Optional custom operation ID
	 */
	public function __construct(string $operation_type, ?string $operation_id = null) {
		$this->operation_type = sanitize_key($operation_type);
		$this->operation_id = $operation_id ?: $this->generate_operation_id();
		$this->transient_key = 'wudt_progress_' . $this->operation_id;
	}

	/**
	 * Generate unique operation ID
	 *
	 * @return string
	 */
	private function generate_operation_id(): string {
		return $this->operation_type . '_' . wp_unique_id() . '_' . time();
	}

	/**
	 * Start a new operation
	 *
	 * @param string $title Operation title
	 * @param int $total_items Total items to process
	 * @param array<string,mixed> $metadata Additional metadata
	 * @return array<string,mixed> Operation info
	 */
	public function start(string $title, int $total_items = 0, array $metadata = array()): array {
		$data = array(
			'operation_id'   => $this->operation_id,
			'operation_type' => $this->operation_type,
			'title'          => sanitize_text_field($title),
			'status'         => 'running',
			'progress'       => 0,
			'processed'      => 0,
			'total_items'    => $total_items,
			'start_time'     => time(),
			'end_time'       => null,
			'elapsed'        => 0,
			'estimated'      => null,
			'message'        => __('Initializing...', 'diagnostics-toolkit'),
			'details'        => array(),
			'errors'         => array(),
			'warnings'       => array(),
			'metadata'       => $metadata,
		);

		$this->save($data);
		
		return array(
			'operation_id'   => $this->operation_id,
			'transient_key'  => $this->transient_key,
			'started'        => true,
		);
	}

	/**
	 * Update progress
	 *
	 * @param int $processed Number of items processed
	 * @param string $message Current status message
	 * @param array<string,mixed> $details Additional details
	 * @return bool Success
	 */
	public function update(int $processed, string $message = '', array $details = array()): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['processed'] = $processed;
		$data['total_items'] = max($data['total_items'], $processed);
		$data['progress'] = $data['total_items'] > 0 
			? min(100, round(($processed / $data['total_items']) * 100, 2)) 
			: 0;
		$data['elapsed'] = time() - $data['start_time'];
		
		if (! empty($message)) {
			$data['message'] = sanitize_text_field($message);
		}
		
		if (! empty($details)) {
			$data['details'] = array_merge($data['details'], $details);
		}

		// Calculate estimated time remaining
		if ($data['progress'] > 0 && $data['progress'] < 100) {
			$rate = $data['elapsed'] / $data['progress'];
			$data['estimated'] = round($rate * (100 - $data['progress']));
		}

		return $this->save($data);
	}

	/**
	 * Add error
	 *
	 * @param string $error Error message
	 * @param array<string,mixed> $context Error context
	 * @return bool Success
	 */
	public function add_error(string $error, array $context = array()): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['errors'][] = array(
			'time'    => current_time('mysql'),
			'message' => sanitize_text_field($error),
			'context' => $context,
		);

		return $this->save($data);
	}

	/**
	 * Add warning
	 *
	 * @param string $warning Warning message
	 * @param array<string,mixed> $context Warning context
	 * @return bool Success
	 */
	public function add_warning(string $warning, array $context = array()): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['warnings'][] = array(
			'time'    => current_time('mysql'),
			'message' => sanitize_text_field($warning),
			'context' => $context,
		);

		return $this->save($data);
	}

	/**
	 * Complete the operation
	 *
	 * @param string $message Completion message
	 * @param array<string,mixed> $results Final results
	 * @return bool Success
	 */
	public function complete(string $message = '', array $results = array()): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['status'] = 'completed';
		$data['progress'] = 100;
		$data['end_time'] = time();
		$data['elapsed'] = $data['end_time'] - $data['start_time'];
		$data['message'] = ! empty($message) ? sanitize_text_field($message) : __('Completed', 'diagnostics-toolkit');
		$data['results'] = $results;

		return $this->save($data);
	}

	/**
	 * Mark as failed
	 *
	 * @param string $error Error message
	 * @return bool Success
	 */
	public function fail(string $error): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['status'] = 'failed';
		$data['end_time'] = time();
		$data['elapsed'] = $data['end_time'] - $data['start_time'];
		$data['message'] = sanitize_text_field($error);
		$data['errors'][] = array(
			'time'    => current_time('mysql'),
			'message' => sanitize_text_field($error),
		);

		return $this->save($data);
	}

	/**
	 * Cancel the operation
	 *
	 * @param string $reason Cancellation reason
	 * @return bool Success
	 */
	public function cancel(string $reason = ''): bool {
		$data = $this->get();
		if (! $data) {
			return false;
		}

		$data['status'] = 'cancelled';
		$data['end_time'] = time();
		$data['elapsed'] = $data['end_time'] - $data['start_time'];
		$data['message'] = ! empty($reason) ? sanitize_text_field($reason) : __('Cancelled', 'diagnostics-toolkit');

		return $this->save($data);
	}

	/**
	 * Get current progress data
	 *
	 * @return array<string,mixed>|null
	 */
	public function get(): ?array {
		$data = get_transient($this->transient_key);
		return is_array($data) ? $data : null;
	}

	/**
	 * Save progress data
	 *
	 * @param array<string,mixed> $data
	 * @return bool
	 */
	private function save(array $data): bool {
		// Keep progress for 24 hours after completion
		$expiration = $data['status'] === 'running' ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		return set_transient($this->transient_key, $data, $expiration);
	}

	/**
	 * Check if operation is running
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		$data = $this->get();
		return $data && $data['status'] === 'running';
	}

	/**
	 * Get operation ID
	 *
	 * @return string
	 */
	public function get_operation_id(): string {
		return $this->operation_id;
	}

	/**
	 * Delete progress data
	 *
	 * @return bool
	 */
	public function delete(): bool {
		return delete_transient($this->transient_key);
	}

	/**
	 * Get all active operations
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_active(): array {
		global $wpdb;
		
		// Query transients for active operations
		$pattern = '_transient_wudt_progress_%';
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			),
			ARRAY_A
		);

		$active = array();
		foreach ($results as $row) {
			$data = maybe_unserialize($row['option_value']);
			if (is_array($data) && isset($data['status']) && $data['status'] === 'running') {
				$active[] = $data;
			}
		}

		return $active;
	}

	/**
	 * Get all operations (including completed)
	 *
	 * @param int $limit Maximum number to return
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_operations(int $limit = 50): array {
		global $wpdb;
		
		$pattern = '_transient_wudt_progress_%';
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
				$pattern,
				$limit
			),
			ARRAY_A
		);

		$operations = array();
		foreach ($results as $row) {
			$data = maybe_unserialize($row['option_value']);
			if (is_array($data)) {
				$operations[] = $data;
			}
		}

		return $operations;
	}

	/**
	 * Clean up old completed operations
	 *
	 * @param int $older_than_seconds Delete operations older than this
	 * @return int Number deleted
	 */
	public static function cleanup_old(int $older_than_seconds = 86400): int {
		global $wpdb;
		
		$cutoff = time() - $older_than_seconds;
		$pattern = '_transient_wudt_progress_%';
		
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			),
			ARRAY_A
		);

		$deleted = 0;
		foreach ($results as $row) {
			$data = maybe_unserialize($row['option_value']);
			if (is_array($data) && isset($data['end_time']) && $data['end_time'] < $cutoff) {
				$transient_name = str_replace('_transient_', '', $row['option_name']);
				if (delete_transient($transient_name)) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}
}
