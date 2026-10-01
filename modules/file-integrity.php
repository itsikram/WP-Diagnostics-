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
		$cached = get_transient('wudt_file_integrity');
		if (is_array($cached)) {
			return $cached;
		}
		$data = array(
			'modified_core_files' => $this->modified_core_files(),
			'suspicious_files'    => $this->scan_suspicious_files(8.0),
			'scanned_at'          => current_time('mysql'),
		);
		set_transient('wudt_file_integrity', $data, 6 * HOUR_IN_SECONDS);
		return $data;
	}

	/**
	 * Core files whose checksum differs from the official WordPress release.
	 *
	 * @return array<int,string>
	 */
	private function modified_core_files(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$checksums = get_core_checksums(get_bloginfo('version'), get_locale() ?: 'en_US');
		if (! is_array($checksums)) {
			$checksums = get_core_checksums(get_bloginfo('version'), 'en_US');
		}
		$mismatches = array();
		foreach ((array) $checksums as $rel_path => $hash) {
			// wp-content ships sample themes/plugins that sites legitimately change.
			if (0 === strpos((string) $rel_path, 'wp-content/')) {
				continue;
			}
			$full = ABSPATH . $rel_path;
			if (is_file($full) && md5_file($full) !== $hash) {
				$mismatches[] = (string) $rel_path;
			}
		}
		return $mismatches;
	}

	/**
	 * Executable or obfuscated code inside the uploads folder, where it should never be.
	 *
	 * @return array<int,string>
	 */
	private function scan_suspicious_files(float $budget): array {
		$uploads = wp_get_upload_dir();
		$directory = (string) $uploads['basedir'];
		if (! is_dir($directory)) {
			return array();
		}
		$started = microtime(true);
		$suspicious = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ($iterator as $file) {
			if ((microtime(true) - $started) > $budget || count($suspicious) >= 200) {
				break;
			}
			if (! $file->isFile()) {
				continue;
			}
			$path = wp_normalize_path((string) $file->getPathname());
			if (false !== strpos($path, '/wudt-')) {
				continue;
			}
			if (preg_match('/\.(php\d?|phtml|phar|pht|shtml|cgi|pl)$/i', $path)) {
				$suspicious[] = $path;
				continue;
			}
			// Only small text-like files can hide code; skip images, video and archives.
			if ($file->getSize() > 512 * 1024 || ! preg_match('/\.(js|txt|htaccess|ico|svg|html?|json)$/i', $path)) {
				continue;
			}
			$contents = (string) @file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if (preg_match('/<\?php|eval\s*\(\s*(base64_decode|gzinflate|str_rot13)|shell_exec\s*\(|assert\s*\(\s*\$_(POST|GET|REQUEST)/i', $contents)) {
				$suspicious[] = $path;
			}
		}
		return array_values(array_unique($suspicious));
	}
}
