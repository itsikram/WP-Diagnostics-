<?php
/**
 * Plugin Name: WP Ultimate Diagnostics Toolkit
 * Plugin URI: https://example.com/wp-ultimate-diagnostics-toolkit
 * Description: All-in-one diagnostics toolkit for performance, security, errors, conflicts, REST, cron, and database checks.
 * Version: 1.0.0
 * Author: WP Ultimate Diagnostics
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: wp-ultimate-diagnostics-toolkit
 */

declare(strict_types=1);

namespace WUDT;

if (! defined('ABSPATH')) {
	exit;
}

define('WUDT_VERSION', '1.0.0');
define('WUDT_PLUGIN_FILE', __FILE__);
define('WUDT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WUDT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load a file without crashing plugin bootstrap.
 */
function safe_require(string $relative_path): void {
	$file = WUDT_PLUGIN_DIR . ltrim($relative_path, '/');
	if (! file_exists($file)) {
		return;
	}
	try {
		require_once $file;
	} catch (\Throwable $e) {
		error_log('[WUDT bootstrap] Failed loading ' . $relative_path . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

safe_require('includes/class-module-base.php');
safe_require('includes/class-operation-logger.php');
safe_require('includes/class-failsafe-manager.php');
safe_require('modules/system-info.php');
safe_require('modules/error-logger.php');
safe_require('modules/conflict-detector.php');
safe_require('modules/performance.php');
safe_require('modules/db-tools.php');
safe_require('modules/security.php');
safe_require('modules/cron.php');
safe_require('modules/rest-api.php');
safe_require('modules/file-integrity.php');
safe_require('modules/external-requests.php');
safe_require('modules/advanced-diagnostics.php');
safe_require('modules/pro-logs.php');
safe_require('modules/file-manager/class-file-manager-module.php');
safe_require('modules/database-manager/class-database-manager-module.php');
safe_require('modules/malware-scanner/class-malware-scanner-module.php');
safe_require('admin/class-admin-page.php');
safe_require('admin/class-pro-admin-page.php');
safe_require('admin/class-debug-page.php');
safe_require('includes/class-plugin.php');
if (defined('WP_CLI') && WP_CLI) {
	safe_require('cli/class-wp-diagnostics-cli.php');
}

function bootstrap(): void {
	if (! class_exists(__NAMESPACE__ . '\\Plugin')) {
		return;
	}
	$plugin = new Plugin();
	$plugin->register();
}

add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap');
