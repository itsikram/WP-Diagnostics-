<?php
/**
 * Plugin Name: WP Ultimate Diagnostics Toolkit
 * Plugin URI: https://example.com/wp-ultimate-diagnostics-toolkit
 * Description: All-in-one diagnostics toolkit for performance, security, errors, conflicts, REST, cron, and database checks.
 * Version: 1.6.0
 * Author: Programmer Ikram
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: wp-ultimate-diagnostics-toolkit
 */

declare(strict_types=1);

namespace WUDT;

if (! defined('ABSPATH')) {
	exit;
}

if (! defined('WUDT_VERSION')) {
	define('WUDT_VERSION', '1.6.0');
}
if (! defined('WUDT_PLUGIN_FILE')) {
	define('WUDT_PLUGIN_FILE', __FILE__);
}
if (! defined('WUDT_PLUGIN_DIR')) {
	define('WUDT_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (! defined('WUDT_PLUGIN_URL')) {
	define('WUDT_PLUGIN_URL', plugin_dir_url(__FILE__));
}

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
		// Silently log to WUDT internal log only (not debug.log)
		$errors = (array) get_option('wudt_bootstrap_errors', array());
		$errors[] = array(
			'time' => current_time('mysql'),
			'file' => $relative_path,
			'error' => $e->getMessage(),
		);
		if (count($errors) > 50) {
			$errors = array_slice($errors, -50);
		}
		update_option('wudt_bootstrap_errors', $errors, false);
	}
}

/**
 * Load permission handler first to catch permission errors early
 */
safe_require('includes/class-permission-handler.php');

safe_require('includes/class-module-base.php');
safe_require('includes/class-operation-logger.php');
safe_require('includes/class-failsafe-manager.php');
safe_require('includes/class-security-guard.php');
safe_require('includes/class-ai-config.php');
safe_require('includes/class-rescue-manager.php');
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
safe_require('modules/backup/class-backup-module.php');
safe_require('modules/restore/class-media-url-handler.php');
safe_require('modules/restore/class-restore-module.php');
safe_require('modules/migration/class-migration-replacer.php');
safe_require('modules/migration/class-migration-engine.php');
safe_require('modules/migration/class-migration-api.php');
safe_require('modules/migration/class-remote-client.php');
safe_require('modules/migration/class-migration-runner.php');
safe_require('modules/migration/class-migration-module.php');
safe_require('modules/malware/class-enterprise-malware-module.php');
safe_require('modules/recovery/class-crash-recovery-module.php');
safe_require('modules/auto-recovery/class-auto-recovery-module.php');
safe_require('modules/state/class-state-module.php');
safe_require('admin/class-settings-page.php');
safe_require('modules/ai-assistant/class-context-builder.php');
safe_require('modules/ai-assistant/class-response-parser.php');
safe_require('modules/ai-assistant/class-ai-client.php');
safe_require('modules/ai-assistant/class-ai-changes.php');
safe_require('modules/ai-assistant/class-ai-tools.php');
safe_require('modules/ai-assistant/class-ai-agent.php');
safe_require('modules/ai-assistant/class-ai-service.php');
safe_require('modules/ai-assistant/class-ai-controller.php');
safe_require('modules/progress-monitor/class-progress-tracker.php');
safe_require('modules/progress-monitor/class-progress-monitor-module.php');
safe_require('modules/search-tool/class-file-search.php');
safe_require('modules/search-tool/class-db-search.php');
safe_require('modules/search-tool/class-search-replace.php');
safe_require('modules/search-tool/class-search-controller.php');
safe_require('modules/smtp/class-smtp-module.php');
safe_require('admin/class-admin-page.php');
safe_require('admin/class-pro-admin-page.php');
safe_require('admin/class-debug-page.php');
safe_require('admin/class-backup-page.php');
safe_require('admin/class-ai-assistant-page.php');
safe_require('admin/class-file-manager-page.php');
safe_require('admin/class-progress-page.php');
safe_require('admin/class-modern-admin-page.php');
safe_require('admin/class-search-page.php');
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

// Rescue access is registered outside the main bootstrap so it keeps working
// even if a module fails to load.
if (class_exists(__NAMESPACE__ . '\\Includes\\Rescue_Manager')) {
	(new Includes\Rescue_Manager())->register_hooks();
}

register_activation_hook(__FILE__, static function (): void {
	if (class_exists(__NAMESPACE__ . '\\Includes\\Rescue_Manager')) {
		Includes\Rescue_Manager::get_key();
		Includes\Rescue_Manager::install_loader();
	}
});

register_deactivation_hook(__FILE__, static function (): void {
	if (class_exists(__NAMESPACE__ . '\\Includes\\Rescue_Manager')) {
		Includes\Rescue_Manager::remove_loader();
	}
});
