<?php
/**
 * Performance analyzer module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Performance_Module extends Module_Base {
	private const OPTION_KEY = 'wudt_perf_samples';

	private float $start_time = 0.0;

	public function register_hooks(): void {
		add_action('init', array($this, 'mark_start_time'), 1);
		add_action('shutdown', array($this, 'capture_sample'), 1);
	}

	public function get_key(): string {
		return 'performance';
	}

	public function get_label(): string {
		return __('Performance', 'wp-ultimate-diagnostics-toolkit');
	}

	public function mark_start_time(): void {
		$this->start_time = microtime(true);
	}

	public function capture_sample(): void {
		global $wpdb;

		// Check if options table exists before writing (during restore it may not exist)
		if (!isset($wpdb) || !$wpdb->ready) {
			return;
		}

		// Suppress database errors during check to prevent race condition output
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			return;
		}

		$sample = array(
			'time'         => current_time('mysql'),
			'url'          => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '',
			'load_time_ms' => round((microtime(true) - (float) $this->start_time) * 1000, 2),
			'memory_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
			'queries'      => isset($wpdb->num_queries) ? (int) $wpdb->num_queries : 0,
		);

		$samples   = (array) get_option(self::OPTION_KEY, array());
		$samples[] = $sample;
		if (count($samples) > 250) {
			$samples = array_slice($samples, -250);
		}
		update_option(self::OPTION_KEY, $samples, false);
	}

	public function get_dashboard_data(): array {
		global $wpdb;

		// Check if options table exists before querying
		if (!isset($wpdb) || !$wpdb->ready) {
			return array(
				'latest_samples'   => array(),
				'large_autoloaded' => array(),
				'optimizations'    => array(),
			);
		}

		// Suppress database errors during check to prevent race condition output
		$wpdb->suppress_errors(true);
		$table_name = $wpdb->prefix . 'options';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors(false);

		if (!$table_exists) {
			return array(
				'latest_samples'   => array(),
				'large_autoloaded' => array(),
				'optimizations'    => array(),
			);
		}

		$autoload_large = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload='yes' ORDER BY size DESC LIMIT 20",
			ARRAY_A
		);

		return array(
			'latest_samples'   => array_slice((array) get_option(self::OPTION_KEY, array()), -50),
			'large_autoloaded' => is_array($autoload_large) ? $autoload_large : array(),
			'optimizations'    => array(
				__('Review autoloaded options larger than 100KB.', 'wp-ultimate-diagnostics-toolkit'),
				__('Disable non-critical plugins on high-traffic pages.', 'wp-ultimate-diagnostics-toolkit'),
				__('Cache expensive REST and database responses.', 'wp-ultimate-diagnostics-toolkit'),
			),
		);
	}
}
