<?php
/**
 * Shared security checks for privileged operations.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

class Security_Guard {
	public static function assert_ajax_admin(string $nonce_field = 'nonce', string $action = 'wudt_admin_nonce'): void {
		check_ajax_referer($action, $nonce_field);
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
	}

	public static function normalize_inside_wp(string $path): string {
		$normalized = wp_normalize_path((string) $path);
		
		// Try to resolve real path, but if it doesn't exist, use the normalized path
		// This is important for backup files that are being created
		$real = @realpath($normalized);
		if (false === $real) {
			// Path doesn't exist yet, check if parent directory is inside WordPress root
			$parent_dir = dirname($normalized);
			$parent_real = @realpath($parent_dir);
			
			if (false !== $parent_real) {
				// Parent exists, use normalized path for the file
				$resolved = $normalized;
			} else {
				// Parent also doesn't exist, use normalized path
				$resolved = $normalized;
			}
		} else {
			$resolved = wp_normalize_path($real);
		}
		
		$root = rtrim(wp_normalize_path(ABSPATH), '/') . '/';
		
		// On Windows, make case-insensitive comparison
		$resolved_lower = strtolower($resolved . '/');
		$root_lower     = strtolower($root);
		
		// Also check parent directory if the path itself doesn't exist
		if (false === $real) {
			$parent_dir = dirname($normalized);
			$parent_real = @realpath($parent_dir);
			if (false !== $parent_real) {
				$parent_resolved = wp_normalize_path($parent_real) . '/';
				$parent_lower = strtolower($parent_resolved);
				if (0 === strpos($parent_lower, $root_lower)) {
					// Parent is inside WordPress root, path is valid
					return $resolved;
				}
			}
		}
		
		if (0 !== strpos($resolved_lower, $root_lower) && rtrim($resolved, '/') !== rtrim($root, '/')) {
			throw new \RuntimeException('Path outside WordPress root.');
		}
		return $resolved;
	}

	public static function safe_zip_entry_name(string $name): string {
		$entry = str_replace('\\', '/', $name);
		$entry = ltrim($entry, '/');
		if ('' === $entry || false !== strpos($entry, '../') || str_contains($entry, "\0")) {
			throw new \RuntimeException('Invalid archive entry path.');
		}
		return $entry;
	}
}
