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
		$root = rtrim(wp_normalize_path(ABSPATH), '/') . '/';
		
		if (false === $real) {
			// Path doesn't exist yet, check if parent directory is inside WordPress root
			$parent_dir = dirname($normalized);
			$parent_real = @realpath($parent_dir);
			
			// Debug logging
			if (function_exists('WUDT\Includes\Operation_Logger::log')) {
				Operation_Logger::log('security', 'normalize_inside_wp - path does not exist', array(
					'path' => $path,
					'normalized' => $normalized,
					'parent_dir' => $parent_dir,
					'parent_real' => $parent_real,
					'root' => $root,
				));
			}
			
			if (false !== $parent_real) {
				$parent_resolved = wp_normalize_path($parent_real) . '/';
				$parent_lower = strtolower($parent_resolved);
				$root_lower = strtolower($root);
				
				if (0 === strpos($parent_lower, $root_lower)) {
					// Parent is inside WordPress root, path is valid
					return $normalized;
				}
			}
			
			// Check grandparent if parent doesn't exist
			$grandparent_dir = dirname($parent_dir);
			$grandparent_real = @realpath($grandparent_dir);
			if (false !== $grandparent_real) {
				$grandparent_resolved = wp_normalize_path($grandparent_real) . '/';
				$grandparent_lower = strtolower($grandparent_resolved);
				$root_lower = strtolower($root);
				
				if (0 === strpos($grandparent_lower, $root_lower)) {
					// Grandparent is inside WordPress root, path is valid
					return $normalized;
				}
			}
			
			// If we get here, the path is outside WordPress root
			throw new \RuntimeException('Path outside WordPress root. Path: ' . $path . ', Normalized: ' . $normalized . ', Root: ' . $root);
		}
		
		$resolved = wp_normalize_path($real);
		
		// On Windows, make case-insensitive comparison
		$resolved_lower = strtolower($resolved . '/');
		$root_lower     = strtolower($root);
		
		if (0 !== strpos($resolved_lower, $root_lower) && rtrim($resolved, '/') !== rtrim($root, '/')) {
			throw new \RuntimeException('Path outside WordPress root. Resolved: ' . $resolved . ', Root: ' . $root);
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
