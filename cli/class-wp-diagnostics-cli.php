<?php
/**
 * WP-CLI integration layer for WP Ultimate Diagnostics Toolkit.
 */

declare(strict_types=1);

use WUDT\Includes\Operation_Logger;
use WUDT\Modules\Advanced_Diagnostics_Module;
use WUDT\Modules\DatabaseManager\Database_Manager_Module;
use WUDT\Modules\File_Integrity_Module;
use WUDT\Modules\FileManager\File_Manager_Module;
use WUDT\Modules\MalwareScanner\Malware_Scanner_Module;
use WUDT\Modules\Performance_Module;
use WUDT\Modules\Security_Module;
use WUDT\Modules\System_Info_Module;
use WP_CLI\Utils;

if (! defined('ABSPATH')) {
	exit;
}

if (! class_exists('WP_CLI')) {
	return;
}

/**
 * Main diagnostics command.
 */
class WP_Diagnostics_CLI {
	/**
	 * Show compact health status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diagnostics status
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function status(array $args, array $assoc_args): void {
		$system      = (new System_Info_Module())->get_dashboard_data();
		$security    = (new Security_Module())->get_dashboard_data();
		$performance = (new Performance_Module())->get_dashboard_data();

		$latest_perf = isset($performance['latest_samples']) && is_array($performance['latest_samples']) ? end($performance['latest_samples']) : array();
		$rows        = array(
			array('check' => 'WordPress', 'value' => (string) ($system['wordpress_version'] ?? 'unknown')),
			array('check' => 'PHP', 'value' => (string) ($system['php_version'] ?? 'unknown')),
			array('check' => 'MySQL', 'value' => (string) ($system['mysql_version'] ?? 'unknown')),
			array('check' => 'Security issues', 'value' => (string) count((array) ($security['issues'] ?? array()))),
			array('check' => 'Latest memory (MB)', 'value' => (string) ($latest_perf['memory_mb'] ?? 'n/a')),
			array('check' => 'Latest load (ms)', 'value' => (string) ($latest_perf['load_time_ms'] ?? 'n/a')),
		);

		Utils\format_items('table', $rows, array('check', 'value'));
		$this->log_action('status', true, array('rows' => count($rows)));
		$this->verbose_log($assoc_args, 'Status command completed.');
	}

	/**
	 * Print detailed system diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diagnostics system --verbose
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function system(array $args, array $assoc_args): void {
		$format = isset($assoc_args['format']) ? sanitize_text_field((string) $assoc_args['format']) : 'table';
		$data   = (new System_Info_Module())->get_dashboard_data();

		$disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : false;
		$disk_free  = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
		$rows       = array(
			array('name' => 'wordpress_version', 'value' => (string) ($data['wordpress_version'] ?? '')),
			array('name' => 'php_version', 'value' => (string) ($data['php_version'] ?? '')),
			array('name' => 'mysql_version', 'value' => (string) ($data['mysql_version'] ?? '')),
			array('name' => 'memory_limit', 'value' => (string) ini_get('memory_limit')),
			array('name' => 'max_execution_time', 'value' => (string) ini_get('max_execution_time')),
			array('name' => 'disk_total_bytes', 'value' => false !== $disk_total ? (string) $disk_total : 'N/A'),
			array('name' => 'disk_free_bytes', 'value' => false !== $disk_free ? (string) $disk_free : 'N/A'),
		);

		if ('json' === $format) {
			WP_CLI::line((string) wp_json_encode(array('system' => $data, 'health' => $rows), JSON_PRETTY_PRINT));
		} else {
			Utils\format_items('table', $rows, array('name', 'value'));
		}

		$this->verbose_log($assoc_args, 'Loaded plugin list count: ' . count((array) ($data['plugins'] ?? array())));
		$this->log_action('system', true, array('format' => $format));
	}

	/**
	 * Show recent plugin operation logs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of rows. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diagnostics logs --limit=20
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function logs(array $args, array $assoc_args): void {
		$limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 50;
		$logs  = array_slice(Operation_Logger::get_logs(), -$limit);
		$rows  = array();
		foreach ($logs as $entry) {
			$rows[] = array(
				'time'    => (string) ($entry['time'] ?? ''),
				'type'    => (string) ($entry['type'] ?? ''),
				'message' => (string) ($entry['message'] ?? ''),
			);
		}
		Utils\format_items('table', $rows, array('time', 'type', 'message'));
		$this->log_action('logs', true, array('limit' => $limit, 'count' => count($rows)));
	}

	/**
	 * Show performance report.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function performance(array $args, array $assoc_args): void {
		$performance = (new Performance_Module())->get_dashboard_data();
		$advanced    = (new Advanced_Diagnostics_Module())->get_dashboard_data();
		$samples     = (array) ($performance['latest_samples'] ?? array());
		$latest      = ! empty($samples) ? end($samples) : array();

		$rows = array(
			array('metric' => 'latest_load_time_ms', 'value' => (string) ($latest['load_time_ms'] ?? 'n/a')),
			array('metric' => 'latest_memory_mb', 'value' => (string) ($latest['memory_mb'] ?? 'n/a')),
			array('metric' => 'latest_query_count', 'value' => (string) ($latest['queries'] ?? 'n/a')),
			array('metric' => 'autoload_large_options', 'value' => (string) count((array) ($performance['large_autoloaded'] ?? array()))),
			array('metric' => 'memory_samples', 'value' => (string) count((array) ($advanced['memory_leak_detector'] ?? array()))),
		);
		Utils\format_items('table', $rows, array('metric', 'value'));
		$this->verbose_log($assoc_args, 'Detailed performance data size: ' . strlen((string) wp_json_encode($performance)));
		$this->log_action('performance', true, array('rows' => count($rows)));
	}

	/**
	 * Clear object cache and transients.
	 *
	 * ## EXAMPLES
	 *
	 *     wp diagnostics cache clear
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function cache_clear(array $args, array $assoc_args): void {
		WP_CLI::log('Clearing object cache...');
		wp_cache_flush();
		$this->delete_site_transients();
		$this->log_action('cache_clear', true, array());
		WP_CLI::success('Cache cleared successfully.');
	}

	/**
	 * Run malware scan.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function malware_scan(array $args, array $assoc_args): void {
		WP_CLI::log('Starting malware scan...');
		$module  = new Malware_Scanner_Module();
		$results = $module->run_scan();
		Utils\format_items('table', $results, array('path', 'risk', 'size'));
		$this->verbose_log($assoc_args, 'Detected items: ' . count($results));
		$this->log_action('malware_scan', true, array('count' => count($results)));
		WP_CLI::success('Malware scan completed.');
	}

	/**
	 * Move suspicious file to quarantine.
	 *
	 * ## OPTIONS
	 *
	 * --path=<path>
	 * : Absolute file path to quarantine.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function malware_clean(array $args, array $assoc_args): void {
		$path = isset($assoc_args['path']) ? (string) $assoc_args['path'] : '';
		if ('' === $path) {
			WP_CLI::error('Please provide --path=<file>');
		}
		if (! isset($assoc_args['yes'])) {
			WP_CLI::confirm('This will move file to quarantine. Continue?');
		}
		$module = new Malware_Scanner_Module();
		$result = $module->quarantine_file($path);
		$this->log_action('malware_clean', true, $result);
		WP_CLI::success('File quarantined: ' . $result['dest']);
	}

	/**
	 * Perform database optimize action.
	 *
	 * ## OPTIONS
	 *
	 * [--table=<table>]
	 * : Specific table name. If omitted, all tables are optimized.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function db_optimize(array $args, array $assoc_args): void {
		$this->run_db_maintenance('optimize', $assoc_args);
	}

	/**
	 * Perform database repair action.
	 *
	 * ## OPTIONS
	 *
	 * [--table=<table>]
	 * : Specific table name. If omitted, all tables are repaired.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function db_repair(array $args, array $assoc_args): void {
		$this->run_db_maintenance('repair', $assoc_args);
	}

	/**
	 * Search files by name/content.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Base path to scan. Default ABSPATH.
	 *
	 * [--query=<query>]
	 * : Query text for name/content search.
	 *
	 * [--limit=<limit>]
	 * : Max results. Default 200.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function file_scan(array $args, array $assoc_args): void {
		$path   = isset($assoc_args['path']) ? (string) $assoc_args['path'] : ABSPATH;
		$query  = isset($assoc_args['query']) ? (string) $assoc_args['query'] : '';
		$limit  = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 200;
		$module = new File_Manager_Module();
		$rows   = '' !== $query ? $module->search_in_path($path, $query, $limit) : $module->get_directory_listing($path);
		if (empty($rows)) {
			WP_CLI::warning('No file scan results found.');
		} else {
			$fields = isset($rows[0]['kind']) ? array('path', 'kind') : array('name', 'path', 'type', 'size');
			Utils\format_items('table', $rows, $fields);
		}
		$this->log_action('file_scan', true, array('path' => $path, 'query' => $query, 'count' => count($rows)));
	}

	/**
	 * Run WordPress core file integrity check.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function file_integrity(array $args, array $assoc_args): void {
		$data       = (new File_Integrity_Module())->get_dashboard_data();
		$modified   = (array) ($data['modified_core_files'] ?? array());
		$rows       = array();
		foreach ($modified as $file) {
			$rows[] = array('file' => (string) $file, 'status' => 'modified');
		}
		if (empty($rows)) {
			WP_CLI::success('No modified core files detected.');
		} else {
			Utils\format_items('table', $rows, array('file', 'status'));
			WP_CLI::warning('Modified core files detected: ' . count($rows));
		}
		$this->log_action('file_integrity', true, array('modified_count' => count($rows)));
	}

	/**
	 * @param array<string,mixed> $assoc_args Args.
	 */
	private function run_db_maintenance(string $action, array $assoc_args): void {
		$table  = isset($assoc_args['table']) ? sanitize_text_field((string) $assoc_args['table']) : '';
		$module = new Database_Manager_Module();
		$stats  = $module->get_table_stats_report();
		$tables = array();

		if ('' !== $table) {
			$tables[] = $table;
		} else {
			foreach ($stats as $status) {
				if (! empty($status['Name'])) {
					$tables[] = (string) $status['Name'];
				}
			}
		}

		if (empty($tables)) {
			WP_CLI::warning('No tables found to process.');
			return;
		}
		if (! isset($assoc_args['yes'])) {
			WP_CLI::confirm('This will ' . $action . ' ' . count($tables) . ' table(s). Continue?');
		}

		$results = array();
		foreach ($tables as $current) {
			WP_CLI::log(strtoupper($action) . ': ' . $current);
			$result    = $module->run_table_maintenance($current, $action);
			$results[] = array(
				'table'  => $current,
				'action' => $action,
				'status' => empty($result) ? 'unknown' : 'ok',
			);
		}
		Utils\format_items('table', $results, array('table', 'action', 'status'));
		$this->log_action('db_' . $action, true, array('tables' => $tables));
		WP_CLI::success('Database ' . $action . ' finished.');
	}

	/**
	 * @param array<string,mixed> $assoc_args Args.
	 */
	private function verbose_log(array $assoc_args, string $message): void {
		if (! empty($assoc_args['verbose'])) {
			WP_CLI::log('[verbose] ' . $message);
		}
	}

	/**
	 * @param array<string,mixed> $details Details.
	 */
	private function log_action(string $action, bool $success, array $details): void {
		Operation_Logger::log(
			'cli',
			'CLI action executed',
			array(
				'action'  => $action,
				'success' => $success,
				'details' => $details,
			)
		);
	}

	private function delete_site_transients(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * Malware group subcommands.
 */
class WP_Diagnostics_CLI_Malware {
	/**
	 * Run scan.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function scan(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->malware_scan($args, $assoc_args);
	}

	/**
	 * Quarantine file.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function clean(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->malware_clean($args, $assoc_args);
	}
}

/**
 * Database group subcommands.
 */
class WP_Diagnostics_CLI_DB {
	/**
	 * Optimize database tables.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function optimize(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->db_optimize($args, $assoc_args);
	}

	/**
	 * Repair database tables.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function repair(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->db_repair($args, $assoc_args);
	}
}

/**
 * File group subcommands.
 */
class WP_Diagnostics_CLI_File {
	/**
	 * Run file scan.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function scan(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->file_scan($args, $assoc_args);
	}

	/**
	 * Run file integrity check.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function integrity(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->file_integrity($args, $assoc_args);
	}
}

/**
 * Cache group subcommands.
 */
class WP_Diagnostics_CLI_Cache {
	/**
	 * Clear cache.
	 *
	 * @param array<int,string> $args Positional args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 */
	public function clear(array $args, array $assoc_args): void {
		(new WP_Diagnostics_CLI())->cache_clear($args, $assoc_args);
	}
}

WP_CLI::add_command('diagnostics', 'WP_Diagnostics_CLI');
WP_CLI::add_command('diagnostics malware', 'WP_Diagnostics_CLI_Malware');
WP_CLI::add_command('diagnostics db', 'WP_Diagnostics_CLI_DB');
WP_CLI::add_command('diagnostics file', 'WP_Diagnostics_CLI_File');
WP_CLI::add_command('diagnostics cache', 'WP_Diagnostics_CLI_Cache');
