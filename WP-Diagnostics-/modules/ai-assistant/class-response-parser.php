<?php
/**
 * Parse AI responses for smart actions.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

if (! defined('ABSPATH')) {
	exit;
}

class Response_Parser {
	/**
	 * @return array<string,mixed>
	 */
	public function parse(string $content): array {
		$actions = array();
		$clean_text = $content;

		// Find all JSON action blocks in the response
		// Match both single JSON objects and arrays of actions
		$pattern = '/```json\s*([\s\S]*?)```|`\{[^`]*"action"[^`]*\`|\{[\s\S]*?"action"\s*:\s*"[^"]+"[\s\S]*?\}/';

		if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[0] as $match) {
				$json_str = $match[0];
				$pos = $match[1];

				// Clean up the match
				$json_str = preg_replace('/^```json\s*/', '', $json_str);
				$json_str = preg_replace('/```$/', '', $json_str);
				$json_str = preg_replace('/^`/', '', $json_str);
				$json_str = preg_replace('/`$/', '', $json_str);

				$decoded = json_decode($json_str, true);

				if (is_array($decoded)) {
					// Single action object
					if (isset($decoded['action'])) {
						$actions[] = $this->sanitize_action($decoded);
					}
					// Array of actions
					elseif (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['action'])) {
						foreach ($decoded as $action_item) {
							if (is_array($action_item) && isset($action_item['action'])) {
								$actions[] = $this->sanitize_action($action_item);
							}
						}
					}
				}

				// Remove the JSON from the text
				$clean_text = substr_replace($clean_text, '', $pos, strlen($match[0]));
			}
		}

		// Also try to find inline JSON objects
		if (empty($actions)) {
			if (preg_match('/\{[\s\S]*?"action"\s*:\s*"([^"]+)"[\s\S]*?\}/', $content, $inline_match)) {
				$start = strpos($content, '{');
				$end = strrpos($content, '}');
				if (false !== $start && false !== $end && $end > $start) {
					$raw = substr($content, $start, $end - $start + 1);
					$decoded = json_decode($raw, true);
					if (is_array($decoded) && isset($decoded['action'])) {
						$actions[] = $this->sanitize_action($decoded);
					}
				}
			}
		}

		// Clean up the text - remove empty lines and trim
		$clean_text = preg_replace('/\n{3,}/', "\n\n", trim($clean_text));

		return array(
			'text'    => $clean_text,
			'action'  => !empty($actions) ? $actions[0] : array(), // Backward compatibility
			'actions' => $actions, // New: multiple actions support
		);
	}

	/**
	 * Sanitize action parameters
	 */
	private function sanitize_action(array $action): array {
		$sanitized = array(
			'action'      => sanitize_key((string) ($action['action'] ?? 'unknown')),
			'description' => sanitize_text_field((string) ($action['description'] ?? '')),
		);

		// Copy all other parameters
		foreach ($action as $key => $value) {
			if (!in_array($key, array('action', 'description'), true)) {
				if (is_string($value)) {
					$sanitized[$key] = $value; // Keep as-is for content, sql, etc.
				} else {
					$sanitized[$key] = $value;
				}
			}
		}

		return $sanitized;
	}
}
