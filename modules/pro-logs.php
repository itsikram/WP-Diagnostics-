<?php
/**
 * Pro logs module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Pro_Logs_Module extends Module_Base {
	public function register_hooks(): void {}

	public function get_key(): string {
		return 'logs';
	}

	public function get_label(): string {
		return __('Logs', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return array('entries' => array_slice(Operation_Logger::get_logs(), -500));
	}
}
