<?php
/**
 * Advanced diagnostics tools.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Advanced_Diagnostics_Module extends Module_Base {
	private const MEM_OPTION   = 'wudt_memory_samples';
	private const QUERY_OPTION = 'wudt_query_samples';

	private float $start_time = 0.0;

	public function register_hooks(): void {
		add_action('init', array($this, 'start_request_sample'), 1);
		add_action('shutdown', array($this, 'capture_request_sample'), 1);
		add_action('activated_plugin', array($this, 'track_plugin_activation'), 10, 2);
		add_action('deleted_plugin', array($this, 'track_plugin_deletion'), 10, 2);
		add_action('switch_theme', array($this, 'track_theme_switch'), 10, 3);
		add_action('upgrader_process_complete', array($this, 'track_upgrader_actions'), 10, 2);
	}

	public function get_key(): string {
		return 'advanced_tools';
	}

	public function get_label(): string {
		return __('Advanced Tools', 'wp-ultimate-diagnostics-toolkit');
	}

	public function start_request_sample(): void {
		$this->start_time = microtime(true);
	}

	public function capture_request_sample(): void {
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

		$memory_samples   = (array) get_option(self::MEM_OPTION, array());
		$memory_samples[] = array(
			'time'           => current_time('mysql'),
			'url'            => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '',
			'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
			'memory_end_mb'  => round(memory_get_usage(true) / 1024 / 1024, 2),
			'elapsed_ms'     => round((microtime(true) - $this->start_time) * 1000, 2),
		);
		update_option(self::MEM_OPTION, array_slice($memory_samples, -300), false);

		$query_samples = (array) get_option(self::QUERY_OPTION, array());
		if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries)) {
			$slow_queries = array();
			foreach ($wpdb->queries as $item) {
				if (! isset($item[0], $item[1])) {
					continue;
				}
				$seconds = (float) $item[1];
				if ($seconds >= 0.05) {
					$slow_queries[] = array(
						'sql'    => (string) $item[0],
						'time_s' => $seconds,
					);
				}
			}
			$query_samples[] = array(
				'time'         => current_time('mysql'),
				'slow_queries' => array_slice($slow_queries, 0, 100),
			);
			update_option(self::QUERY_OPTION, array_slice($query_samples, -150), false);
		}
	}

	public function track_plugin_activation(string $plugin, bool $network_wide): void {
		Operation_Logger::log('activity', 'Plugin activated', array('plugin' => $plugin, 'network' => $network_wide));
	}

	public function track_plugin_deletion(string $plugin, bool $deleted): void {
		Operation_Logger::log('activity', 'Plugin deleted', array('plugin' => $plugin, 'deleted' => $deleted));
	}

	/**
	 * @param string $new_name New name.
	 * @param \WP_Theme $new_theme New theme.
	 * @param \WP_Theme $old_theme Old theme.
	 */
	public function track_theme_switch(string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme): void {
		Operation_Logger::log(
			'activity',
			'Theme switched',
			array('new' => $new_theme->get_stylesheet(), 'old' => $old_theme->get_stylesheet())
		);
	}

	/**
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array<string,mixed> $hook_extra Hook extra.
	 */
	public function track_upgrader_actions(\WP_Upgrader $upgrader, array $hook_extra): void {
		Operation_Logger::log('activity', 'Upgrader event', $hook_extra);
	}

	public function get_dashboard_data(): array {
		$limits = array(
			'memory_limit'       => (string) ini_get('memory_limit'),
			'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
			'post_max_size'      => (string) ini_get('post_max_size'),
			'max_execution_time' => (string) ini_get('max_execution_time'),
			'max_input_vars'     => (string) ini_get('max_input_vars'),
		);

		return array(
			'memory_leak_detector' => array_slice((array) get_option(self::MEM_OPTION, array()), -100),
			'query_monitor_lite'   => array_slice((array) get_option(self::QUERY_OPTION, array()), -100),
			'user_activity'        => array_slice(Operation_Logger::get_logs(), -200),
			'environment_limits'   => $limits,
			'recommendations'      => array(
				'memory_limit'        => '256M or higher',
				'upload_max_filesize' => '64M or higher',
				'max_execution_time'  => '120 or higher',
			),
		);
	}
}
