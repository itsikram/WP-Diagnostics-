<?php
/**
 * Main plugin bootstrapper.
 */

declare(strict_types=1);

namespace WUDT;

use WUDT\Admin\Admin_Page;
use WUDT\Modules\Conflict_Detector_Module;
use WUDT\Modules\Cron_Module;
use WUDT\Modules\DB_Tools_Module;
use WUDT\Modules\Error_Logger_Module;
use WUDT\Modules\External_Requests_Module;
use WUDT\Modules\File_Integrity_Module;
use WUDT\Modules\Performance_Module;
use WUDT\Modules\REST_API_Module;
use WUDT\Modules\Security_Module;
use WUDT\Modules\System_Info_Module;

if (! defined('ABSPATH')) {
	exit;
}

class Plugin {
	/**
	 * @var array<int,\WUDT\Includes\Module_Base>
	 */
	private array $modules = array();

	public function register(): void {
		$this->modules = array(
			new System_Info_Module(),
			new Error_Logger_Module(),
			new Conflict_Detector_Module(),
			new Performance_Module(),
			new DB_Tools_Module(),
			new REST_API_Module(),
			new Cron_Module(),
			new File_Integrity_Module(),
			new Security_Module(),
			new External_Requests_Module(),
		);

		foreach ($this->modules as $module) {
			$module->register_hooks();
		}

		$admin_page = new Admin_Page($this->modules);
		$admin_page->register_hooks();
	}
}
