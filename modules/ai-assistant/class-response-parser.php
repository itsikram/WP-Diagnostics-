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
		$action = array();
		if (preg_match('/\{[\s\S]*"action"\s*:\s*"([^"]+)"[\s\S]*\}/', $content, $matches)) {
			$start = strpos($content, '{');
			$end   = strrpos($content, '}');
			if (false !== $start && false !== $end && $end > $start) {
				$raw = substr($content, $start, $end - $start + 1);
				$decoded = json_decode($raw, true);
				if (is_array($decoded) && isset($decoded['action'])) {
					$action = $decoded;
				} elseif (! empty($matches[1])) {
					$action = array('action' => sanitize_key((string) $matches[1]));
				}
			}
		}
		return array(
			'text'   => $content,
			'action' => $action,
		);
	}
}
