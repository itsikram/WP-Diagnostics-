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

require_once WUDT_PLUGIN_DIR . 'includes/class-module-base.php';
require_once WUDT_PLUGIN_DIR . 'includes/class-operation-logger.php';
require_once WUDT_PLUGIN_DIR . 'modules/system-info.php';
require_once WUDT_PLUGIN_DIR . 'modules/error-logger.php';
require_once WUDT_PLUGIN_DIR . 'modules/conflict-detector.php';
require_once WUDT_PLUGIN_DIR . 'modules/performance.php';
require_once WUDT_PLUGIN_DIR . 'modules/db-tools.php';
require_once WUDT_PLUGIN_DIR . 'modules/security.php';
require_once WUDT_PLUGIN_DIR . 'modules/cron.php';
require_once WUDT_PLUGIN_DIR . 'modules/rest-api.php';
require_once WUDT_PLUGIN_DIR . 'modules/file-integrity.php';
require_once WUDT_PLUGIN_DIR . 'modules/external-requests.php';
require_once WUDT_PLUGIN_DIR . 'modules/advanced-diagnostics.php';
require_once WUDT_PLUGIN_DIR . 'modules/pro-logs.php';
require_once WUDT_PLUGIN_DIR . 'modules/file-manager/class-file-manager-module.php';
require_once WUDT_PLUGIN_DIR . 'modules/database-manager/class-database-manager-module.php';
require_once WUDT_PLUGIN_DIR . 'modules/malware-scanner/class-malware-scanner-module.php';
require_once WUDT_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once WUDT_PLUGIN_DIR . 'admin/class-pro-admin-page.php';
require_once WUDT_PLUGIN_DIR . 'includes/class-plugin.php';

function bootstrap(): void {
	$plugin = new Plugin();
	$plugin->register();
}

add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap');
