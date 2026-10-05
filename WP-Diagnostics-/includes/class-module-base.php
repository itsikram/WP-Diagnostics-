<?php
/**
 * Base module contract.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

abstract class Module_Base {
	/**
	 * Register hooks for module.
	 *
	 * @return void
	 */
	abstract public function register_hooks(): void;

	/**
	 * Get tab key.
	 *
	 * @return string
	 */
	abstract public function get_key(): string;

	/**
	 * Get tab label.
	 *
	 * @return string
	 */
	abstract public function get_label(): string;

	/**
	 * Get module payload for initial UI render.
	 *
	 * @return array<string,mixed>
	 */
	abstract public function get_dashboard_data(): array;
}
