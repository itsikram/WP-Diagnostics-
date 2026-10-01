<?php
/**
 * Serialization-safe search & replace used while exporting database rows.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Replacer {
	/**
	 * Build replacement pairs that move a site from one URL/path to another.
	 *
	 * Covers scheme variants, JSON-escaped URLs (Elementor, block editor),
	 * URL-encoded URLs, protocol-relative URLs and filesystem paths.
	 *
	 * @return array<string,string>
	 */
	public static function build_pairs(array $from, array $to): array {
		$pairs = array();

		$url_sets = array(
			array((string) ($from['home'] ?? ''), (string) ($to['home'] ?? '')),
			array((string) ($from['siteurl'] ?? ''), (string) ($to['siteurl'] ?? '')),
		);
		foreach ($url_sets as $set) {
			list($old, $new) = $set;
			$old = untrailingslashit($old);
			$new = untrailingslashit($new);
			if ('' === $old || '' === $new || $old === $new) {
				continue;
			}
			$old_hostpath = preg_replace('#^https?://#i', '', $old);
			$new_hostpath = preg_replace('#^https?://#i', '', $new);
			$variants = array($old, 'http://' . $old_hostpath, 'https://' . $old_hostpath);
			foreach ($variants as $variant) {
				$pairs[$variant] = $new;
				$pairs[self::json_escape($variant)] = self::json_escape($new);
				$pairs[rawurlencode($variant)] = rawurlencode($new);
				$pairs[urlencode($variant)] = urlencode($new);
			}
			$pairs['//' . $old_hostpath] = '//' . $new_hostpath;
			$pairs[self::json_escape('//' . $old_hostpath)] = self::json_escape('//' . $new_hostpath);
		}

		$old_path = untrailingslashit(wp_normalize_path((string) ($from['abspath'] ?? '')));
		$new_path = untrailingslashit(wp_normalize_path((string) ($to['abspath'] ?? '')));
		if ('' !== $old_path && '' !== $new_path && $old_path !== $new_path && strlen($old_path) > 3) {
			$path_variants = array_unique(array(
				$old_path,
				str_replace('/', '\\', $old_path),
				untrailingslashit((string) ($from['abspath_raw'] ?? $old_path)),
			));
			foreach ($path_variants as $variant) {
				if ('' === $variant) {
					continue;
				}
				$pairs[$variant] = $new_path;
				$pairs[self::json_escape($variant)] = self::json_escape($new_path);
			}
		}

		// Never map a string to itself.
		foreach ($pairs as $k => $v) {
			if ('' === $k || $k === $v) {
				unset($pairs[$k]);
			}
		}
		return $pairs;
	}

	private static function json_escape(string $value): string {
		$json = wp_json_encode($value);
		return is_string($json) ? substr($json, 1, -1) : $value;
	}

	/**
	 * Replace inside a database value, keeping serialized data valid.
	 *
	 * @param array<string,string> $pairs
	 */
	public static function replace(string $value, array $pairs): string {
		if ('' === $value || empty($pairs) || ! self::contains_any($value, $pairs)) {
			return $value;
		}
		if (is_serialized($value, false)) {
			$data = @unserialize($value, array('allowed_classes' => array('stdClass')));
			if (false !== $data || 'b:0;' === $value) {
				if (self::has_incomplete_object($data)) {
					// Objects of unknown classes cannot be rebuilt safely; leave untouched.
					return $value;
				}
				return serialize(self::replace_deep($data, $pairs));
			}
			// Corrupt serialized data: plain replacement would make it worse.
			return $value;
		}
		return strtr($value, $pairs);
	}

	private static function replace_deep($data, array $pairs) {
		if (is_string($data)) {
			return self::replace($data, $pairs);
		}
		if (is_array($data)) {
			$out = array();
			foreach ($data as $key => $item) {
				$out[$key] = self::replace_deep($item, $pairs);
			}
			return $out;
		}
		if ($data instanceof \stdClass) {
			foreach (get_object_vars($data) as $key => $item) {
				$data->$key = self::replace_deep($item, $pairs);
			}
			return $data;
		}
		return $data;
	}

	private static function has_incomplete_object($data, int $depth = 0): bool {
		if ($depth > 64) {
			return true;
		}
		if ($data instanceof \__PHP_Incomplete_Class) {
			return true;
		}
		if (is_array($data) || $data instanceof \stdClass) {
			foreach ((array) $data as $item) {
				if ((is_array($item) || is_object($item)) && self::has_incomplete_object($item, $depth + 1)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function contains_any(string $value, array $pairs): bool {
		foreach ($pairs as $needle => $unused) {
			if (false !== strpos($value, (string) $needle)) {
				return true;
			}
		}
		return false;
	}
}
