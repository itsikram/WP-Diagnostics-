<?php
/**
 * Remote Client - signed HTTP client for the Migration_API of another site.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Remote_Client {
	private string $url;
	private string $key;
	private string $transport;
	private int $clock_offset = 0;
	private string $redirected_url = '';

	public function __construct(string $url, string $key, string $transport = '') {
		$this->url = untrailingslashit(trim($url));
		$this->key = trim($key);
		$this->transport = $transport;
	}

	public function get_transport(): string {
		return $this->transport;
	}

	/**
	 * URL the remote site redirected to (e.g. http → https), if any.
	 */
	public function get_redirected_url(): string {
		return $this->redirected_url;
	}

	/**
	 * Call an API action on the remote site.
	 *
	 * @throws \RuntimeException On any transport or remote error.
	 */
	public function call(string $action, array $params = array(), int $timeout = 90) {
		$order = '' !== $this->transport ? array($this->transport) : array('ajax', 'rest');
		$last_error = '';

		foreach ($order as $transport) {
			$attempts = 0;
			while ($attempts < 3) {
				$attempts++;
				$result = $this->send($transport, $action, $params, $timeout);
				if (isset($result['envelope'])) {
					$env = $result['envelope'];
					if (! empty($env['ok'])) {
						$this->transport = $transport;
						return $env['data'] ?? null;
					}
					$code = (string) ($env['code'] ?? '');
					if ('clock_skew' === $code && isset($env['now']) && $attempts < 3) {
						$this->clock_offset = (int) $env['now'] - time();
						continue;
					}
					if ('replay' === $code && $attempts < 3) {
						continue;
					}
					$this->transport = $transport;
					throw new \RuntimeException('Remote site: ' . (string) ($env['error'] ?? 'Unknown error'));
				}
				$last_error = (string) ($result['error'] ?? 'Unknown error');
				if (empty($result['retry'])) {
					break;
				}
				sleep($attempts);
			}
			if ('' !== $this->transport) {
				break;
			}
		}

		throw new \RuntimeException($last_error);
	}

	/**
	 * @return array{envelope?:array,error?:string,retry?:bool}
	 */
	private function send(string $transport, string $action, array $params, int $timeout): array {
		$json = wp_json_encode($params, JSON_INVALID_UTF8_SUBSTITUTE);
		if (! is_string($json)) {
			return array('error' => 'Could not encode request data.');
		}
		$enc = '';
		$body = $json;
		if (strlen($json) > 4096 && function_exists('gzdeflate')) {
			$body = (string) gzdeflate($json, 6);
			$enc = 'gzip';
		}

		$ts = (string) (time() + $this->clock_offset);
		$nonce = bin2hex(random_bytes(12));
		$headers = array(
			'Content-Type' => $enc ? 'application/octet-stream' : 'application/json',
			'X-WUDT-Time'  => $ts,
			'X-WUDT-Nonce' => $nonce,
			'X-WUDT-Sig'   => Migration_API::sign($this->key, $action, $ts, $nonce, $body),
			'X-WUDT-Enc'   => $enc,
		);

		$endpoint = $this->endpoint($transport, $action);
		$response = null;
		for ($hop = 0; $hop < 3; $hop++) {
			$response = wp_remote_post($endpoint, array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => false,
				'user-agent'  => 'WUDT-Migration/' . (defined('WUDT_VERSION') ? WUDT_VERSION : '2'),
			));
			if (is_wp_error($response)) {
				return array('error' => 'Could not reach ' . $this->url . ': ' . $response->get_error_message(), 'retry' => true);
			}
			$code = (int) wp_remote_retrieve_response_code($response);
			if (in_array($code, array(301, 302, 303, 307, 308), true)) {
				$location = (string) wp_remote_retrieve_header($response, 'location');
				if ('' === $location) {
					break;
				}
				$new_base = $this->base_from_redirect($location, $transport);
				if ('' === $new_base || $new_base === $this->url) {
					break;
				}
				$this->url = $new_base;
				$this->redirected_url = $new_base;
				$endpoint = $this->endpoint($transport, $action);
				continue;
			}
			break;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw = (string) wp_remote_retrieve_body($response);
		$envelope = self::parse_envelope($raw);
		if (null !== $envelope) {
			return array('envelope' => $envelope);
		}

		if ($code >= 500) {
			return array('error' => sprintf('Remote server error (HTTP %d) during "%s". %s', $code, $action, self::excerpt($raw)), 'retry' => true);
		}
		if ('0' === trim($raw) || 400 === $code || 404 === $code) {
			return array('error' => 'WP Diagnostics (v1.6 or newer) is not active on ' . $this->url . '. Install/update and activate it on the remote site.');
		}
		if (401 === $code || 403 === $code) {
			return array('error' => sprintf('Access to %s was blocked (HTTP %d) — a firewall or security plugin may be blocking requests. %s', $this->url, $code, self::excerpt($raw)));
		}
		return array('error' => sprintf('Unexpected response from %s (HTTP %d): %s', $this->url, $code, self::excerpt($raw)));
	}

	private function endpoint(string $transport, string $action): string {
		if ('rest' === $transport) {
			return $this->url . '/?rest_route=' . rawurlencode('/wudt-migration/v2/api') . '&wudt_action=' . rawurlencode($action);
		}
		return $this->url . '/wp-admin/admin-ajax.php?action=wudt_migration_api&wudt_action=' . rawurlencode($action);
	}

	private function base_from_redirect(string $location, string $transport): string {
		$marker = 'rest' === $transport ? '/?rest_route=' : '/wp-admin/admin-ajax.php';
		$pos = strpos($location, $marker);
		if (false === $pos) {
			$pos = strpos($location, '/index.php?rest_route=');
		}
		return false === $pos ? '' : untrailingslashit(substr($location, 0, $pos));
	}

	/**
	 * Extract the API envelope even when other code printed notices before it.
	 */
	public static function parse_envelope(string $raw): ?array {
		$raw = trim($raw);
		$data = json_decode($raw, true);
		if (! is_array($data) || ! isset($data['wudt_api'])) {
			$pos = strpos($raw, '{"');
			while (false !== $pos) {
				$candidate = json_decode(substr($raw, $pos), true);
				if (is_array($candidate) && isset($candidate['wudt_api'])) {
					$data = $candidate;
					break;
				}
				$pos = strpos($raw, '{"', $pos + 2);
			}
		}
		if (! is_array($data) || ! isset($data['wudt_api'])) {
			return null;
		}
		if (isset($data['z'])) {
			$inflated = function_exists('gzinflate') ? @gzinflate((string) base64_decode((string) $data['z'])) : false;
			$data = is_string($inflated) ? json_decode($inflated, true) : null;
			if (! is_array($data)) {
				return array('ok' => false, 'error' => 'Could not decode compressed response.');
			}
		}
		return $data;
	}

	private static function excerpt(string $raw): string {
		$text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw)) ?? '');
		return '' === $text ? '' : '“' . mb_substr($text, 0, 180) . '”';
	}
}
