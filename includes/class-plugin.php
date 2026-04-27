<?php
/**
 * Main plugin bootstrapper.
 */

declare(strict_types=1);

namespace WUDT;

use WUDT\Admin\Admin_Page;
use WUDT\Admin\Pro_Admin_Page;
use WUDT\Modules\Conflict_Detector_Module;
use WUDT\Modules\Cron_Module;
use WUDT\Modules\DB_Tools_Module;
use WUDT\Modules\Error_Logger_Module;
use WUDT\Modules\External_Requests_Module;
use WUDT\Modules\File_Integrity_Module;
use WUDT\Modules\Performance_Module;
use WUDT\Modules\Pro_Logs_Module;
use WUDT\Modules\REST_API_Module;
use WUDT\Modules\Security_Module;
use WUDT\Modules\System_Info_Module;
use WUDT\Modules\Advanced_Diagnostics_Module;
use WUDT\Modules\DatabaseManager\Database_Manager_Module;
use WUDT\Modules\FileManager\File_Manager_Module;
use WUDT\Modules\MalwareScanner\Malware_Scanner_Module;

if (! defined('ABSPATH')) {
	exit;
}

class Plugin {
	/**
	 * @var array<int,\WUDT\Includes\Module_Base>
	 */
	private array $modules = array();
	/**
	 * @var array<int,\WUDT\Includes\Module_Base>
	 */
	private array $pro_modules = array();

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
		$this->pro_modules = array(
			new System_Info_Module(),
			new File_Manager_Module(),
			new Database_Manager_Module(),
			new Malware_Scanner_Module(),
			new Pro_Logs_Module(),
			new Performance_Module(),
			new Security_Module(),
			new Advanced_Diagnostics_Module(),
			new External_Requests_Module(),
		);

		foreach ($this->modules as $module) {
			$module->register_hooks();
		}
		foreach ($this->pro_modules as $module) {
			$module->register_hooks();
		}

		$admin_page = new Admin_Page($this->modules);
		$admin_page->register_hooks();
		$pro_page = new Pro_Admin_Page($this->pro_modules);
		$pro_page->register_hooks();
	}
}
