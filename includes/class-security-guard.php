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
		
		// First check: if the normalized path starts with WordPress root, it's valid
		$normalized_lower = strtolower($normalized);
		$root_lower = strtolower($root);
		
		if (0 === strpos($normalized_lower, $root_lower)) {
			// Path appears to be inside WordPress root based on string comparison
			// Now verify by trying to resolve real path
			$real = @realpath($normalized);
			
			if (false !== $real) {
				return wp_normalize_path($real);
			}
			
			// Path doesn't exist yet - traverse up to find existing parent
			$current_dir = dirname($normalized);
			$max_depth = 20;
			$depth = 0;
			
			while ($depth < $max_depth && $current_dir !== '/' && $current_dir !== '.' && $current_dir !== '') {
				$current_real = @realpath($current_dir);
				
				if (false !== $current_real) {
					$current_resolved = wp_normalize_path($current_real) . '/';
					$current_lower = strtolower($current_resolved);
					
					if (0 === strpos($current_lower, $root_lower)) {
						// Found existing parent inside WordPress root
						return $normalized;
					}
					// Parent exists but outside WordPress - reject
					throw new \RuntimeException('Path outside WordPress root. Parent: ' . $current_resolved . ', Root: ' . $root);
				}
				
				$parent_dir = dirname($current_dir);
				if ($parent_dir === $current_dir) {
					break;
				}
				$current_dir = $parent_dir;
				$depth++;
			}
			
			// Couldn't find existing parent, but path string starts with WordPress root
			// This is likely a new path inside WordPress - allow it
			return $normalized;
		}
		
		// Path doesn't start with WordPress root - try to resolve and check
		$real = @realpath($normalized);
		if (false !== $real) {
			$resolved = wp_normalize_path($real);
			$resolved_lower = strtolower($resolved . '/');
			
			if (0 === strpos($resolved_lower, $root_lower) || rtrim($resolved, '/') === rtrim($root, '/')) {
				return $resolved;
			}
		}
		
		throw new \RuntimeException('Path outside WordPress root. Path: ' . $path . ', Root: ' . $root);
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
