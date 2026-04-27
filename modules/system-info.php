<?php
/**
 * System info module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class System_Info_Module extends Module_Base {
	public function register_hooks(): void {}

	public function get_key(): string {
		return 'system_info';
	}

	public function get_label(): string {
		return __('System Info', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		global $wpdb;
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme           = wp_get_theme();
		$all_plugins     = get_plugins();
		$active_plugins  = array_flip((array) get_option('active_plugins', array()));
		$plugins_payload = array();

		foreach ($all_plugins as $file => $plugin) {
			$plugins_payload[] = array(
				'name'    => $plugin['Name'] ?? $file,
				'version' => $plugin['Version'] ?? '',
				'active'  => isset($active_plugins[ $file ]),
			);
		}

		return array(
			'wordpress_version' => get_bloginfo('version'),
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'server_software'   => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'Unknown',
			'active_theme'      => array(
				'name'    => $theme->get('Name'),
				'version' => $theme->get('Version'),
			),
			'plugins'           => $plugins_payload,
		);
	}
}
