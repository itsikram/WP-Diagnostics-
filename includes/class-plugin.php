<?php
/**
 * Main plugin bootstrapper.
 */

declare(strict_types=1);

namespace WUDT;

if (! defined('ABSPATH')) {
	exit;
}

use WUDT\Admin\Admin_Page;
use WUDT\Admin\Debug_Page;
use WUDT\Admin\Settings_Page;
use WUDT\Admin\Pro_Admin_Page;
use WUDT\Admin\Backup_Page;
use WUDT\Admin\AI_Assistant_Page;
use WUDT\Admin\File_Manager_Page;
use WUDT\Admin\Progress_Page;
use WUDT\Admin\Modern_Admin_Page;
use WUDT\Admin\Search_Page;
use WUDT\Includes\Failsafe_Manager;
use WUDT\Includes\Operation_Logger;
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
use WUDT\Modules\Backup\Backup_Module;
use WUDT\Modules\Restore\Restore_Module;
use WUDT\Modules\Migration\Migration_Module;
use WUDT\Modules\Malware\Enterprise_Malware_Module;
use WUDT\Modules\Recovery\Crash_Recovery_Module;
use WUDT\Modules\AutoRecovery\Auto_Recovery_Module;
use WUDT\Modules\State\State_Module;
use WUDT\Modules\AIAssistant\AI_Controller;
use WUDT\Modules\ProgressMonitor\Progress_Monitor_Module;
use WUDT\Modules\SearchTool\Search_Controller;
use WUDT\Modules\SMTP\SMTP_Module;

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
	/**
	 * One instance per module class, shared by both module sets.
	 *
	 * @var array<string,\WUDT\Includes\Module_Base>
	 */
	private array $instances = array();

	public function register(): void {
		$failsafe = new Failsafe_Manager();
		$failsafe->register_hooks();

		$emergency = $failsafe->is_emergency_mode();
		$this->modules = $this->build_module_set(
			array(
				System_Info_Module::class,
				Error_Logger_Module::class,
				Conflict_Detector_Module::class,
				Performance_Module::class,
				DB_Tools_Module::class,
				REST_API_Module::class,
				Cron_Module::class,
				File_Integrity_Module::class,
				Security_Module::class,
				External_Requests_Module::class,
			),
			$emergency
		);
		$this->pro_modules = $this->build_module_set(
			array(
				System_Info_Module::class,
				File_Manager_Module::class,
				Database_Manager_Module::class,
				Malware_Scanner_Module::class,
				Enterprise_Malware_Module::class,
				Backup_Module::class,
				Restore_Module::class,
				Migration_Module::class,
				Crash_Recovery_Module::class,
				Auto_Recovery_Module::class,
				State_Module::class,
				AI_Controller::class,
				Pro_Logs_Module::class,
				Performance_Module::class,
				Security_Module::class,
				Advanced_Diagnostics_Module::class,
				External_Requests_Module::class,
				Progress_Monitor_Module::class,
				Search_Controller::class,
				SMTP_Module::class,
			),
			$emergency
		);

		foreach ($this->instances as $module) {
			$module->register_hooks();
		}

		$admin_page = new Admin_Page($this->modules);
		$admin_page->register_hooks();
		$pro_page = new Pro_Admin_Page($this->pro_modules);
		$pro_page->register_hooks();
		$debug_page = new Debug_Page();
		$debug_page->register_hooks();
		$settings_page = new Settings_Page();
		$settings_page->register_hooks();
		$backup_page = new Backup_Page();
		$backup_page->register_hooks();
		$ai_page = new AI_Assistant_Page();
		$ai_page->register_hooks();
		$file_manager_page = new File_Manager_Page();
		$file_manager_page->register_hooks();
		$progress_page = new Progress_Page();
		$progress_page->register_hooks();
		// Combine both module sets for Modern_Admin_Page to show all tabs
		$all_modules = array_merge($this->modules, $this->pro_modules);
		$modern_admin = new Modern_Admin_Page($all_modules);
		$modern_admin->register_hooks();
		$search_page = new Search_Page();
		$search_page->register_hooks();
	}

	/**
	 * @param array<int,string> $classes Module class names.
	 * @return array<int,\WUDT\Includes\Module_Base>
	 */
	private function build_module_set(array $classes, bool $emergency): array {
		$modules = array();
		foreach ($classes as $class) {
			if ($emergency && in_array($class, array(File_Integrity_Module::class, Malware_Scanner_Module::class), true)) {
				continue;
			}
			if (isset($this->instances[$class])) {
				$modules[] = $this->instances[$class];
				continue;
			}
			try {
				$instance = new $class();
				$this->instances[$class] = $instance;
				$modules[] = $instance;
			} catch (\Throwable $e) {
				Operation_Logger::log(
					'failsafe',
					'Module skipped after initialization error',
					array(
						'class' => $class,
						'error' => $e->getMessage(),
					)
				);
			}
		}
		return $modules;
	}
}
