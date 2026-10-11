<?php
/**
 * Remote Client - signed HTTP client for the Migration_API of another site.
 *
 * Single requests use the WordPress HTTP API. Several requests at once run in
 * parallel through the Requests library bundled with WordPress.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never printed as HTML: they are logged or returned with wp_send_json_*(), and the admin scripts escape them when displaying them.

class Remote_Client {
	private string $url;
	private string $key;
	private string $transport;
	private int $clock_offset = 0;
	private string $redirected_url = '';
	private bool $wire = false;
	private bool $solid = false;
	// Unix time by which every call must be finished (0: no limit).
	private float $deadline = 0.0;

	public function __construct(string $url, string $key, string $transport = '') {
		$this->url = untrailingslashit(trim($url));
		$this->key = trim($key);
		$this->transport = $transport;
	}

	public function get_transport(): string {
		return $this->transport;
	}

	/**
	 * Use the binary wire format (the remote site must list the "wire" feature),
	 * optionally with solid compression (the "solid" feature).
	 */
	public function set_wire(bool $wire, bool $solid = false): void {
		$this->wire = $wire;
		$this->solid = $wire && $solid;
	}

	/**
	 * Finish every call (including retries) before this Unix time, so the PHP
	 * request is never killed by max_execution_time in the middle of a step.
	 */
	public function set_deadline(float $deadline): void {
		$this->deadline = $deadline;
	}

	/**
	 * Timeout for the next request, shortened to fit the deadline.
	 *
	 * @throws \RuntimeException When no time is left.
	 */
	private function time_for(int $timeout): int {
		if ($this->deadline <= 0) {
			return $timeout;
		}
		$left = (int) floor($this->deadline - microtime(true));
		if ($left < 5) {
			throw new \RuntimeException('Timed out: the other site did not answer in time for this step.');
		}
		return min($timeout, $left);
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
	public function call(string $action, array $params = array(), int $timeout = 90, int $max_attempts = 3) {
		$this->assert_reachable();
		$order = '' !== $this->transport ? array($this->transport) : array('ajax', 'rest');
		$last_error = '';

		foreach ($order as $transport) {
			$attempts = 0;
			while ($attempts < $max_attempts) {
				$attempts++;
				$result = $this->send($transport, $action, $params, $this->time_for($timeout));
				if (isset($result['envelope'])) {
					$env = $result['envelope'];
					if (! empty($env['ok'])) {
						$this->transport = $transport;
						return $env['data'] ?? null;
					}
					$code = (string) ($env['code'] ?? '');
					if ('clock_skew' === $code && isset($env['now']) && $attempts < $max_attempts) {
						$this->clock_offset = (int) $env['now'] - time();
						continue;
					}
					if ('replay' === $code && $attempts < $max_attempts) {
						continue;
					}
					$this->transport = $transport;
					throw new \RuntimeException('Remote site: ' . (string) ($env['error'] ?? 'Unknown error'));
				}
				$last_error = (string) ($result['error'] ?? 'Unknown error');
				if (empty($result['retry']) || $attempts >= $max_attempts || ($this->deadline > 0 && $this->deadline - microtime(true) < $attempts + 10)) {
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
		$this->assert_reachable();
		$collect = null !== $errors;
		$out = array();
		$fail = static function ($k, string $message) use ($collect, &$errors): void {
			if (! $collect) {
				throw new \RuntimeException($message);
			}
			$errors[$k] = $message;
		};

		if (count($list) < 2 || '' === $this->transport || ! self::use_parallel()) {
			foreach ($list as $k => $params) {
				try {
					$out[$k] = $this->call($action, $params, $timeout);
				} catch (\RuntimeException $e) {
					$fail($k, $e->getMessage());
				}
			}
			return $out;
		}

		$timeout = $this->time_for($timeout);
		$requests = array();
		foreach ($list as $k => $params) {
			$req = $this->build_request($this->transport, $action, $params);
			if (isset($req['error'])) {
				$fail($k, $req['error']);
				continue;
			}
			$req['timeout'] = $timeout;
			$requests[$k] = $req;
		}
		$responses = empty($requests) ? array() : $this->http_multi($requests);

		$retry = array();
		foreach (array_keys($requests) as $k) {
			$result = $this->interpret($responses[$k], $action);
			if (! empty($responses[$k]['timed_out'])) {
				// Retrying the same large request one by one would only time out again
				// (and run past PHP's time limit): report it so the caller can send less.
				$fail($k, (string) $result['error']);
				continue;
			}
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
	 * Send requests up to $lanes at a time, in rounds, until $next has nothing
	 * more to start.
	 *
	 * Requests are prepared only when a round is filled, so $next can read and
	 * pack the data just in time. Requests that fail for transient reasons are retried
	 * once on their own.
	 *
	 * @param callable $next fn(): ?array — the next request as [key, action, params, options?]
	 *                       (options: timeout, retry), or null when none can start now (it is
	 *                       asked again after the round finishes; the stream ends when
	 *                       nothing runs).
	 * @param callable $done fn($key, $data, ?string $error, bool $timed_out): void
	 */
	public function call_stream(callable $next, callable $done, int $lanes, int $timeout = 120): void {
		$this->assert_reachable();
		if ('' === $this->transport || ! self::use_parallel()) {
			while (null !== ($item = $next())) {
				list($k, $action, $params) = $item;
				$opts = (array) ($item[3] ?? array());
				try {
					$data = $this->call($action, $params, (int) ($opts['timeout'] ?? $timeout), false === ($opts['retry'] ?? true) ? 1 : 3);
				} catch (\RuntimeException $e) {
					$done($k, null, $e->getMessage(), self::is_timeout_error($e->getMessage()));
					continue;
				}
				$done($k, $data, null, false);
			}
			return;
		}

		$lanes = max(1, $lanes);
		$out_of_time = false;
		while (true) {
			// Fill the lanes, send them together, then hand each result over.
			$round = array();
			while (count($round) < $lanes && ! $out_of_time && null !== ($item = $next())) {
				$opts = (array) ($item[3] ?? array());
				try {
					$req_timeout = $this->time_for((int) ($opts['timeout'] ?? $timeout));
				} catch (\RuntimeException $e) {
					$out_of_time = true;
					$done($item[0], null, $e->getMessage(), true);
					break;
				}
				$req = $this->build_request($this->transport, (string) $item[1], (array) $item[2]);
				if (isset($req['error'])) {
					$done($item[0], null, (string) $req['error'], false);
					continue;
				}
				$req['timeout'] = $req_timeout;
				$round[] = array('item' => $item, 'req' => $req);
			}
			if (empty($round)) {
				return;
			}
			$responses = $this->http_multi(array_column($round, 'req'));
			foreach ($round as $i => $entry) {
				$this->finish_stream_item($entry['item'], $responses[$i], $done, $timeout);
			}
		}
	}

	/**
	 * Hand one finished stream request to the caller, retrying it once on its
	 * own when it failed for a transient reason (as call_multi() does).
	 */
	private function finish_stream_item(array $item, array $response, callable $done, int $timeout): void {
		list($k, $action, $params) = $item;
		$result = $this->interpret($response, $action);
		if (! empty($response['timed_out'])) {
			// Sending the same large request again would only time out again.
			$done($k, null, (string) $result['error'], true);
			return;
		}
		if (isset($result['envelope'])) {
			$env = $result['envelope'];
			if (! empty($env['ok'])) {
				$done($k, $env['data'] ?? null, null, false);
				return;
			}
			$code = (string) ($env['code'] ?? '');
			if ('clock_skew' === $code && isset($env['now'])) {
				$this->clock_offset = (int) $env['now'] - time();
			}
			if (! in_array($code, array('clock_skew', 'replay'), true)) {
				$done($k, null, 'Remote site: ' . (string) ($env['error'] ?? 'Unknown error'), false);
				return;
			}
		} elseif (empty($result['retry']) && empty($response['redirect'])) {
			$done($k, null, (string) ($result['error'] ?? 'Unknown error'), false);
			return;
		}
		$opts = (array) ($item[3] ?? array());
		if (false === ($opts['retry'] ?? true)) {
			$done($k, null, (string) ($result['error'] ?? 'Request failed.'), false);
			return;
		}
		try {
			$data = $this->call($action, $params, $timeout, 2);
		} catch (\RuntimeException $e) {
			$done($k, null, $e->getMessage(), self::is_timeout_error($e->getMessage()));
			return;
		}
		$done($k, $data, null, false);
	}

	private static function is_timeout_error(string $error): bool {
		return (bool) preg_match('/timed out|timeout|did not answer in time/i', $error);
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
			$response = $this->wp_post($req, $timeout);
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
			$body = Migration_Wire::pack($params, $this->solid);
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
			$headers['X-WUDT-Accept'] = $this->solid ? 'wire2' : 'wire';
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

	/* --------------------------- Reachability --------------------------- */

	/**
	 * Whether a URL points at a computer that is not on the internet: localhost,
	 * a private IP address or a development domain (.local, .test…).
	 */
	public static function is_local_url(string $url): bool {
		$host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '[]'));
		if ('' === $host) {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return 0 === strpos($host, '127.') || '::1' === $host
				|| ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		}
		return 'localhost' === $host
			|| false === strpos($host, '.')
			|| (bool) preg_match('/\.(localhost|local|test|lan|internal|home\.arpa)$/', $host);
	}

	/**
	 * A site on the internet cannot open connections to a local address on
	 * someone's computer (it would reach its own server instead).
	 */
	public static function unreachable_from_here(string $url): bool {
		$blocked = self::is_local_url($url) && ! self::is_local_url((string) home_url());
		return (bool) apply_filters('wudt_migration_unreachable', $blocked, $url);
	}

	/**
	 * @throws \RuntimeException When this server cannot reach the other site.
	 */
	private function assert_reachable(): void {
		if (self::unreachable_from_here($this->url)) {
			throw new \RuntimeException(sprintf(
				'%1$s is a local address on your computer, and this site (%2$s) is on the internet, so it cannot connect to it. Start the migration from %1$s instead: open Site Migration there and use Push to send its content here, or Pull to bring this site there.',
				$this->url,
				untrailingslashit((string) home_url())
			));
		}
	}

	/* ------------------------------ HTTP -------------------------------- */

	/**
	 * Send several requests at once with the HTTP library bundled with WordPress.
	 * Not when WordPress is set up to use a proxy, which only wp_remote_*() applies.
	 */
	private static function use_parallel(): bool {
		$ok = class_exists('\\WpOrg\\Requests\\Requests') && ! (defined('WP_PROXY_HOST') && WP_PROXY_HOST);
		return (bool) apply_filters('wudt_migration_parallel_requests', $ok);
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
	 * Run requests concurrently with WordPress's bundled Requests library.
	 *
	 * @param array<int|string,array> $requests Each: endpoint, headers, body, timeout.
	 * @return array<int|string,array{code?:int,body?:string,error?:string,redirect?:string,timed_out?:bool}>
	 */
	private function http_multi(array $requests): array {
		$verify = $this->verify_ssl();
		$multi = array();
		foreach ($requests as $k => $req) {
			$headers = (array) $req['headers'];
			$headers['Expect'] = ''; // No 100-continue round trip before large bodies.
			$multi[$k] = array(
				'url'     => (string) $req['endpoint'],
				'headers' => $headers,
				'data'    => (string) $req['body'],
				'type'    => \WpOrg\Requests\Requests::POST,
				'options' => array(
					'timeout'          => (float) $req['timeout'],
					'connect_timeout'  => (float) min(20, (int) $req['timeout']),
					'follow_redirects' => false,
					'useragent'        => self::user_agent(),
					'verify'           => $verify ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
					'verifyname'       => $verify,
				),
			);
		}
		$responses = \WpOrg\Requests\Requests::request_multiple($multi);

		$out = array();
		foreach (array_keys($requests) as $k) {
			$response = $responses[$k] ?? null;
			if ($response instanceof \WpOrg\Requests\Response) {
				$code = (int) $response->status_code;
				$location = (string) ($response->headers['location'] ?? '');
				$out[$k] = in_array($code, array(301, 302, 303, 307, 308), true) && '' !== $location
					? array('code' => $code, 'redirect' => $location)
					: array('code' => $code, 'body' => (string) $response->body);
				continue;
			}
			$message = $response instanceof \Throwable ? $response->getMessage() : 'The request failed.';
			$out[$k] = array(
				'error'     => $message,
				// cURL error 28: operation timed out.
				'timed_out' => self::is_timeout_error($message) || ($response instanceof \WpOrg\Requests\Exception\Transport\Curl && 28 === (int) $response->getCode()),
			);
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
