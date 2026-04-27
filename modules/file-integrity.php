<?php
/**
 * File integrity scanner module.
 */

declare(strict_types=1);

namespace WUDT\Modules;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WUDT\Includes\Module_Base;

if (! defined('ABSPATH')) {
	exit;
}

class File_Integrity_Module extends Module_Base {
	public function register_hooks(): void {}

	public function get_key(): string {
		return 'file_integrity';
	}

	public function get_label(): string {
		return __('File Integrity', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$checksums       = get_core_checksums(get_bloginfo('version'), get_locale());
		$core_mismatches = array();

		if (is_array($checksums)) {
			foreach ($checksums as $rel_path => $hash) {
				$full = ABSPATH . $rel_path;
				if (file_exists($full) && md5_file($full) !== $hash) {
					$core_mismatches[] = $rel_path;
				}
			}
		}

		return array(
			'modified_core_files' => $core_mismatches,
			'suspicious_files'    => $this->scan_suspicious_files(),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function scan_suspicious_files(): array {
		$targets    = array(WP_CONTENT_DIR . '/uploads', WP_CONTENT_DIR . '/plugins');
		$suspicious = array();
		foreach ($targets as $directory) {
			if (! is_dir($directory)) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
			foreach ($iterator as $file) {
				if (! $file->isFile()) {
					continue;
				}
				$path = (string) $file->getPathname();
				if (preg_match('/\.(php|phtml|phar)$/i', $path)) {
					$suspicious[] = $path;
					continue;
				}
				$contents = @file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if (false !== $contents && preg_match('/(base64_decode|eval\(|shell_exec|gzinflate)/i', $contents)) {
					$suspicious[] = $path;
				}
			}
		}
		return array_slice(array_unique($suspicious), 0, 200);
	}
}
