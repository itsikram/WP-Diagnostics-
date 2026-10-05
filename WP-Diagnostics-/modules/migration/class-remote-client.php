<?php
/**
 * Remote Client - signed HTTP client for the Migration_API of another site.
 *
 * Uses cURL directly when available so connections (and TLS sessions) are
 * reused between requests and several requests can run in parallel; falls
 * back to the WordPress HTTP API otherwise.
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
	private bool $wire = false;

	/** @var resource|\CurlMultiHandle|null Shared multi handle: keeps connections alive between calls. */
	private static $multi = null;
	/** @var array<int,resource|\CurlHandle> Reusable easy handles, one per parallel slot. */
	private static array $handles = array();

	public function __construct(string $url, string $key, string $transport = '') {
		$this->url = untrailingslashit(trim($url));
		$this->key = trim($key);
		$this->transport = $transport;
	}

	public function get_transport(): string {
		return $this->transport;
	}

	/**
	 * Use the binary wire format (the remote site must list the "wire" feature).
	 */
	public function set_wire(bool $wire): void {
		$this->wire = $wire;
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
	 * Run the same action with several parameter sets in parallel.
	 * Requests that fail for transient reasons are retried one by one.
	 *
	 * @param array<int|string,array>       $list
	 * @param array<int|string,string>|null $errors When given, failed requests are reported
	 *                                              here (same keys) instead of thrown.
	 * @return array<int|string,mixed> Results of the successful requests, keyed like $list.
	 * @throws \RuntimeException When a request fails for good and $errors is not given.
	 */
	public function call_multi(string $action, array $list, int $timeout = 120, ?array &$errors = null): array {
		$collect = null !== $errors;
		$out = array();
		$fail = static function ($k, string $message) use ($collect, &$errors): void {
			if (! $collect) {
				throw new \RuntimeException($message);
			}
			$errors[$k] = $message;
		};

		if (count($list) < 2 || '' === $this->transport || ! self::use_curl()) {
			foreach ($list as $k => $params) {
				try {
					$out[$k] = $this->call($action, $params, $timeout);
				} catch (\RuntimeException $e) {
					$fail($k, $e->getMessage());
				}
			}
			return $out;
		}

		$requests = array();
		$slot = 0;
		foreach ($list as $k => $params) {
			$req = $this->build_request($this->transport, $action, $params);
			if (isset($req['error'])) {
				$fail($k, $req['error']);
				continue;
			}
			$req['slot'] = $slot++;
			$req['timeout'] = $timeout;
			$requests[$k] = $req;
		}
		$responses = empty($requests) ? array() : $this->curl_execute($requests);

		$retry = array();
		foreach (array_keys($requests) as $k) {
			$result = $this->interpret($responses[$k], $action);
			if (isset($result['envelope'])) {
				$env = $result['envelope'];
				if (! empty($env['ok'])) {
					$out[$k] = $env['data'] ?? null;
					continue;
				}
				$code = (string) ($env['code'] ?? '');
				if ('clock_skew' === $code && isset($env['now'])) {
					$this->clock_offset = (int) $env['now'] - time();
				}
				if (! in_array($code, array('clock_skew', 'replay'), true)) {
					$fail($k, 'Remote site: ' . (string) ($env['error'] ?? 'Unknown error'));
					continue;
				}
			} elseif (empty($result['retry']) && empty($responses[$k]['redirect'])) {
				$fail($k, (string) ($result['error'] ?? 'Unknown error'));
				continue;
			}
			$retry[] = $k;
		}
		foreach ($retry as $k) {
			try {
				$out[$k] = $this->call($action, $list[$k], $timeout);
			} catch (\RuntimeException $e) {
				$fail($k, $e->getMessage());
			}
		}
		$sorted = array();
		foreach (array_keys($list) as $k) {
			if (array_key_exists($k, $out)) {
				$sorted[$k] = $out[$k];
			}
		}
		return $sorted;
	}

	/**
	 * @return array{envelope?:array,error?:string,retry?:bool}
	 */
	private function send(string $transport, string $action, array $params, int $timeout): array {
		$req = $this->build_request($transport, $action, $params);
		if (isset($req['error'])) {
			return $req;
		}

		$response = array();
		for ($hop = 0; $hop < 3; $hop++) {
			if (self::use_curl()) {
				$req['slot'] = 0;
				$req['timeout'] = $timeout;
				$response = $this->curl_execute(array($req))[0];
			} else {
				$response = $this->wp_post($req, $timeout);
			}
			if (! empty($response['redirect'])) {
				$new_base = $this->base_from_redirect((string) $response['redirect'], $transport);
				if ('' === $new_base || $new_base === $this->url) {
					break;
				}
				$this->url = $new_base;
				$this->redirected_url = $new_base;
				$req['endpoint'] = $this->endpoint($transport, $action);
				continue;
			}
			break;
		}
		return $this->interpret($response, $action);
	}

	/**
	 * Encode and sign a request.
	 *
	 * @return array{endpoint?:string,headers?:array,body?:string,error?:string}
	 */
	private function build_request(string $transport, string $action, array $params): array {
		$enc = '';
		if ($this->wire) {
			$body = Migration_Wire::pack($params);
			$enc = 'wire';
		} else {
			$params = self::raw_to_base64($params);
			$json = wp_json_encode($params, JSON_INVALID_UTF8_SUBSTITUTE);
			if (! is_string($json)) {
				return array('error' => 'Could not encode request data.');
			}
			$body = $json;
			if (strlen($json) > 4096 && function_exists('gzdeflate')) {
				$body = (string) gzdeflate($json, 6);
				$enc = 'gzip';
			}
		}

		$ts = (string) (time() + $this->clock_offset);
		$nonce = bin2hex(random_bytes(12));
		$headers = array(
			'Content-Type' => '' !== $enc ? 'application/octet-stream' : 'application/json',
			'X-WUDT-Time'  => $ts,
			'X-WUDT-Nonce' => $nonce,
			'X-WUDT-Sig'   => Migration_API::sign($this->key, $action, $ts, $nonce, $body),
			'X-WUDT-Enc'   => $enc,
		);
		if ($this->wire) {
			$headers['X-WUDT-Accept'] = 'wire';
		}
		return array('endpoint' => $this->endpoint($transport, $action), 'headers' => $headers, 'body' => $body);
	}

	/**
	 * Older remote sites only understand base64 file data in the "d" key.
	 */
	private static function raw_to_base64(array $params): array {
		foreach ($params as $k => $v) {
			if ('raw' === $k && is_string($v)) {
				unset($params['raw']);
				$params['d'] = base64_encode($v);
			} elseif (is_array($v)) {
				$params[$k] = self::raw_to_base64($v);
			}
		}
		return $params;
	}

	/**
	 * @param array{code?:int,body?:string,error?:string,redirect?:string} $response
	 * @return array{envelope?:array,error?:string,retry?:bool}
	 */
	private function interpret(array $response, string $action): array {
		if (isset($response['error'])) {
			return array('error' => 'Could not reach ' . $this->url . ': ' . $response['error'], 'retry' => true);
		}
		if (! empty($response['redirect'])) {
			return array('error' => 'The remote site redirected the request to ' . $response['redirect'], 'retry' => true);
		}
		$code = (int) ($response['code'] ?? 0);
		$raw = (string) ($response['body'] ?? '');
		$envelope = self::parse_envelope($raw);
		if (null !== $envelope) {
			return array('envelope' => $envelope);
		}

		if ($code >= 500) {
			return array('error' => sprintf('Remote server error (HTTP %d) during "%s". %s', $code, $action, self::excerpt($raw)), 'retry' => true);
		}
		if ('0' === trim($raw) || 400 === $code || 404 === $code) {
			return array('error' => 'Diagnostics Toolkit (v1.6 or newer) is not active on ' . $this->url . '. Install/update and activate it on the remote site.');
		}
		if (401 === $code || 403 === $code) {
			return array('error' => sprintf('Access to %s was blocked (HTTP %d) — a firewall or security plugin may be blocking requests. %s', $this->url, $code, self::excerpt($raw)));
		}
		if (429 === $code) {
			return array('error' => sprintf('The remote server is rate limiting requests (HTTP 429) during "%s".', $action), 'retry' => true);
		}
		return array('error' => sprintf('Unexpected response from %s (HTTP %d): %s', $this->url, $code, self::excerpt($raw)));
	}

	/* ------------------------------ HTTP -------------------------------- */

	private static function use_curl(): bool {
		$ok = function_exists('curl_multi_init') && ! (defined('WP_PROXY_HOST') && WP_PROXY_HOST);
		return (bool) apply_filters('wudt_migration_use_curl', $ok);
	}

	/**
	 * @return array{code?:int,body?:string,error?:string,redirect?:string}
	 */
	private function wp_post(array $req, int $timeout): array {
		$response = wp_remote_post($req['endpoint'], array(
			'headers'     => $req['headers'],
			'body'        => $req['body'],
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => $this->verify_ssl(),
			'user-agent'  => self::user_agent(),
		));
		if (is_wp_error($response)) {
			return array('error' => $response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if (in_array($code, array(301, 302, 303, 307, 308), true) && '' !== (string) wp_remote_retrieve_header($response, 'location')) {
			return array('code' => $code, 'redirect' => (string) wp_remote_retrieve_header($response, 'location'));
		}
		return array('code' => $code, 'body' => (string) wp_remote_retrieve_body($response));
	}

	/**
	 * Run requests concurrently on reusable handles.
	 *
	 * @param array<int|string,array> $requests Each: endpoint, headers, body, slot, timeout.
	 * @return array<int|string,array{code?:int,body?:string,error?:string,redirect?:string}>
	 */
	private function curl_execute(array $requests): array {
		if (null === self::$multi) {
			self::$multi = curl_multi_init();
		}
		$mh = self::$multi;
		$verify = $this->verify_ssl();
		$ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

		$chs = array();
		$locations = array();
		foreach ($requests as $k => $req) {
			$slot = (int) $req['slot'];
			if (! isset(self::$handles[$slot])) {
				self::$handles[$slot] = curl_init();
			} else {
				curl_reset(self::$handles[$slot]); // Keeps the connection open.
			}
			$ch = self::$handles[$slot];
			$headers = array('Expect:'); // No 100-continue round trip before large bodies.
			foreach ($req['headers'] as $name => $value) {
				$headers[] = $name . ': ' . $value;
			}
			$locations[$k] = '';
			curl_setopt_array($ch, array(
				CURLOPT_URL            => $req['endpoint'],
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $req['body'],
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 20,
				CURLOPT_TIMEOUT        => (int) $req['timeout'],
				CURLOPT_SSL_VERIFYPEER => $verify,
				CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
				CURLOPT_USERAGENT      => self::user_agent(),
				CURLOPT_ENCODING       => '',
				CURLOPT_TCP_NODELAY    => true,
				CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$locations, $k): int {
					if (0 === stripos($line, 'location:')) {
						$locations[$k] = trim(substr($line, 9));
					}
					return strlen($line);
				},
			));
			if ($verify && is_readable($ca)) {
				curl_setopt($ch, CURLOPT_CAINFO, $ca);
			}
			curl_multi_add_handle($mh, $ch);
			$chs[$k] = $ch;
		}

		$errors = array();
		do {
			$status = curl_multi_exec($mh, $running);
			if ($running && -1 === curl_multi_select($mh, 1.0)) {
				usleep(1000);
			}
			while ($info = curl_multi_info_read($mh)) {
				foreach ($chs as $k => $ch) {
					if ($ch === $info['handle']) {
						$errors[$k] = (int) $info['result'];
					}
				}
			}
		} while ($running && CURLM_OK === $status);

		$out = array();
		foreach ($chs as $k => $ch) {
			$errno = $errors[$k] ?? curl_errno($ch);
			if (0 !== $errno) {
				$msg = curl_error($ch);
				$out[$k] = array('error' => '' !== $msg ? $msg : (function_exists('curl_strerror') ? curl_strerror($errno) : 'cURL error ' . $errno));
			} else {
				$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				$out[$k] = in_array($code, array(301, 302, 303, 307, 308), true) && '' !== $locations[$k]
					? array('code' => $code, 'redirect' => $locations[$k])
					: array('code' => $code, 'body' => (string) curl_multi_getcontent($ch));
			}
			curl_multi_remove_handle($mh, $ch);
		}
		return $out;
	}

	private static function user_agent(): string {
		return 'WUDT-Migration/' . (defined('WUDT_VERSION') ? WUDT_VERSION : '2');
	}

	/**
	 * Verify TLS certificates, except for local development hosts, which usually
	 * use self-signed certificates. Filterable for other private setups.
	 */
	private function verify_ssl(): bool {
		$host = strtolower((string) wp_parse_url($this->url, PHP_URL_HOST));
		$local = in_array($host, array('localhost', '127.0.0.1', '::1'), true)
			|| (bool) preg_match('/\.(localhost|local|test)$/', $host);
		return (bool) apply_filters('wudt_migration_sslverify', ! $local, $this->url);
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
	 * Extract the API envelope (wire or JSON) even when other code printed notices before it.
	 */
	public static function parse_envelope(string $raw): ?array {
		if (Migration_Wire::is_wire($raw)) {
			try {
				$data = Migration_Wire::unpack($raw);
			} catch (\Throwable $e) {
				return array('ok' => false, 'error' => $e->getMessage());
			}
			return isset($data['wudt_api']) ? $data : null;
		}
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
