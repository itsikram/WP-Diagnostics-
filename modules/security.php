<?php
/**
 * Security quick scan module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class Security_Module extends Module_Base {
	public function register_hooks(): void {}

	public function get_key(): string {
		return 'security';
	}

	public function get_label(): string {
		return __('Security', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$issues = array();
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$issues[] = __('WP_DEBUG is enabled. Disable in production.', 'wp-ultimate-diagnostics-toolkit');
		}
		$wp_config = ABSPATH . 'wp-config.php';
		if (file_exists($wp_config)) {
			$perm = substr(sprintf('%o', (int) fileperms($wp_config)), -4);
			if ((int) $perm > 640) {
				$issues[] = sprintf(__('wp-config.php permissions are too open (%s).', 'wp-ultimate-diagnostics-toolkit'), $perm);
			}
		}
		$files = array('.env', '.git/config', 'composer.json', 'composer.lock');
		$exposed = array();
		foreach ($files as $file) {
			if (file_exists(ABSPATH . $file)) {
				$exposed[] = $file;
			}
		}
		if (! empty($exposed)) {
			$issues[] = __('Sensitive files found in web root: ', 'wp-ultimate-diagnostics-toolkit') . implode(', ', $exposed);
		}

		return array(
			'issues'         => $issues,
			'wp_debug'       => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
			'file_integrity' => file_exists($wp_config) ? substr(sprintf('%o', (int) fileperms($wp_config)), -4) : 'N/A',
		);
	}
}
