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
		$real       = realpath($normalized);
		$resolved   = wp_normalize_path(false !== $real ? $real : $normalized);
		$root       = rtrim(wp_normalize_path(ABSPATH), '/') . '/';
		
		// On Windows, make case-insensitive comparison
		$resolved_lower = strtolower($resolved . '/');
		$root_lower     = strtolower($root);
		
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
