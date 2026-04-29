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
		$root = rtrim(wp_normalize_path(ABSPATH), '/') . '/';
		
		// Try to resolve real path
		$real = @realpath($normalized);
		
		if (false !== $real) {
			// Path exists - validate it's inside WordPress root
			$resolved = wp_normalize_path($real);
			$resolved_lower = strtolower($resolved . '/');
			$root_lower = strtolower($root);
			
			if (0 === strpos($resolved_lower, $root_lower) || rtrim($resolved, '/') === rtrim($root, '/')) {
				return $resolved;
			}
			throw new \RuntimeException('Path outside WordPress root. Resolved: ' . $resolved . ', Root: ' . $root);
		}
		
		// Path doesn't exist yet - traverse up the tree to find an existing parent
		$current_dir = dirname($normalized);
		$max_depth = 20; // Prevent infinite loops
		$depth = 0;
		
		while ($depth < $max_depth && $current_dir !== '/' && $current_dir !== '.' && $current_dir !== '') {
			$current_real = @realpath($current_dir);
			
			if (false !== $current_real) {
				$current_resolved = wp_normalize_path($current_real) . '/';
				$current_lower = strtolower($current_resolved);
				$root_lower = strtolower($root);
				
				// Check if this existing directory is inside WordPress root
				if (0 === strpos($current_lower, $root_lower)) {
					return $normalized;
				} else {
					// Found an existing directory but it's outside WordPress root
					throw new \RuntimeException('Path outside WordPress root. Path: ' . $path . ', Existing parent: ' . $current_resolved . ', Root: ' . $root);
				}
			}
			
			// Move up one level
			$parent_dir = dirname($current_dir);
			if ($parent_dir === $current_dir) {
				break; // Reached root of filesystem
			}
			$current_dir = $parent_dir;
			$depth++;
		}
		
		// If we get here, we couldn't find an existing parent directory inside WordPress root
		throw new \RuntimeException('Path outside WordPress root. Path: ' . $path . ', Normalized: ' . $normalized . ', Root: ' . $root);
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
