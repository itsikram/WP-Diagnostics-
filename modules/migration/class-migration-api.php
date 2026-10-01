<?php
/**
 * Migration API - authenticated endpoint other sites call during a migration.
 *
 * Reachable both through admin-ajax.php (action=wudt_migration_api) and the
 * REST API (wudt-migration/v2/api) so it keeps working when one of them is
 * blocked by a security plugin or permalink configuration.
 *
 * Requests are signed with HMAC-SHA256 using this site's connection key; the
 * key itself never travels over the network.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_API {
	public const KEY_OPTION = 'wudt_local_api_key';
	private const MAX_SKEW = 900;
	private const FAIL_LIMIT = 25;

	public function register_hooks(): void {
		add_action('wp_ajax_nopriv_wudt_migration_api', array($this, 'handle_ajax'));
		add_action('wp_ajax_wudt_migration_api', array($this, 'handle_ajax'));
		add_action('rest_api_init', array($this, 'register_rest'));
	}

	public function register_rest(): void {
		register_rest_route('wudt-migration/v2', '/api', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'handle_rest'),
			'permission_callback' => '__return_true', // Authenticated by signature inside the handler.
		));
	}

	public static function get_local_key(): string {
		$key = (string) get_option(self::KEY_OPTION, '');
		if (strlen($key) < 32) {
			$key = bin2hex(random_bytes(24));
			update_option(self::KEY_OPTION, $key, false);
		}
		return $key;
	}

	public static function connection_string(): string {
		$payload = wp_json_encode(array('u' => untrailingslashit(home_url()), 'k' => self::get_local_key()));
		return 'wudt:' . rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
	}

	/**
	 * @return array{url:string,key:string}|null
	 */
	public static function parse_connection_string(string $value): ?array {
		$value = trim($value);
		if (0 !== strpos($value, 'wudt:')) {
			return null;
		}
		$raw = base64_decode(strtr(substr($value, 5), '-_', '+/'), true);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (! is_array($data) || empty($data['u']) || empty($data['k'])) {
			return null;
		}
		return array('url' => (string) $data['u'], 'key' => (string) $data['k']);
	}

	public static function sign(string $key, string $action, string $ts, string $nonce, string $body): string {
		return hash_hmac('sha256', "v2\n{$action}\n{$ts}\n{$nonce}\n" . hash('sha256', $body), $key);
	}

	public function handle_ajax(): void {
		$body = (string) file_get_contents('php://input');
		$headers = array(
			'action' => isset($_GET['wudt_action']) ? sanitize_key((string) wp_unslash($_GET['wudt_action'])) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'ts'     => (string) ($_SERVER['HTTP_X_WUDT_TIME'] ?? ''),
			'nonce'  => (string) ($_SERVER['HTTP_X_WUDT_NONCE'] ?? ''),
			'sig'    => (string) ($_SERVER['HTTP_X_WUDT_SIG'] ?? ''),
			'enc'    => (string) ($_SERVER['HTTP_X_WUDT_ENC'] ?? ''),
		);
		$this->respond($this->process($headers, $body));
	}

	public function handle_rest(\WP_REST_Request $request): void {
		$headers = array(
			'action' => sanitize_key((string) $request->get_param('wudt_action')),
			'ts'     => (string) $request->get_header('x_wudt_time'),
			'nonce'  => (string) $request->get_header('x_wudt_nonce'),
			'sig'    => (string) $request->get_header('x_wudt_sig'),
			'enc'    => (string) $request->get_header('x_wudt_enc'),
		);
		$this->respond($this->process($headers, (string) $request->get_body()));
	}

	private function process(array $h, string $body): array {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$fail_key = 'wudt_mig_fail_' . md5($ip);
		$fails = (int) get_transient($fail_key);
		if ($fails >= self::FAIL_LIMIT) {
			return array('ok' => false, 'code' => 'locked', 'error' => 'Too many failed authentication attempts. Try again in 15 minutes.');
		}

		$key = (string) get_option(self::KEY_OPTION, '');
		if (strlen($key) < 32) {
			return array('ok' => false, 'code' => 'no_key', 'error' => 'The connection key on this site has not been generated yet. Open WP Diagnostics → Site Migration on this site once.');
		}
		if ('' === $h['action'] || '' === $h['ts'] || '' === $h['sig'] || strlen($h['nonce']) < 16) {
			return array('ok' => false, 'code' => 'bad_request', 'error' => 'Unsigned migration request.');
		}

		$expected = self::sign($key, $h['action'], $h['ts'], $h['nonce'], $body);
		if (! hash_equals($expected, $h['sig'])) {
			set_transient($fail_key, $fails + 1, 15 * MINUTE_IN_SECONDS);
			return array('ok' => false, 'code' => 'bad_signature', 'error' => 'Authentication failed: the connection key does not match this site. Copy the key again from the remote site.');
		}
		$skew = time() - (int) $h['ts'];
		if (abs($skew) > self::MAX_SKEW) {
			return array('ok' => false, 'code' => 'clock_skew', 'now' => time(), 'error' => 'Server clocks differ too much.');
		}
		$nonce_key = 'wudt_mig_n_' . md5($h['nonce']);
		if (get_transient($nonce_key)) {
			return array('ok' => false, 'code' => 'replay', 'error' => 'Duplicate request rejected.');
		}
		set_transient($nonce_key, 1, 2 * self::MAX_SKEW);

		if ('gzip' === $h['enc']) {
			$inflated = function_exists('gzinflate') ? @gzinflate($body) : false;
			if (false === $inflated) {
				return array('ok' => false, 'code' => 'bad_body', 'error' => 'Could not decompress request.');
			}
			$body = $inflated;
		}
		$params = json_decode($body, true);
		if (! is_array($params)) {
			return array('ok' => false, 'code' => 'bad_body', 'error' => 'Invalid request body.');
		}

		Migration_Engine::raise_limits();
		try {
			$data = $this->dispatch($h['action'], $params);
			return array('ok' => true, 'data' => $data);
		} catch (\Throwable $e) {
			Operation_Logger::log('migration', 'Migration API error', array('action' => $h['action'], 'error' => $e->getMessage()));
			return array('ok' => false, 'code' => 'error', 'error' => $e->getMessage());
		}
	}

	public function dispatch(string $action, array $p) {
		$engine = new Migration_Engine();
		$budget = min(25.0, max(3.0, (float) ($p['budget'] ?? 12)));
		switch ($action) {
			case 'info':
				return $engine->site_info();
			case 'checksums':
				return $engine->table_checksums((array) ($p['tables'] ?? array()), $budget);
			case 'export':
				return $engine->export_table_chunk(
					(string) ($p['table'] ?? ''),
					isset($p['cursor']) && is_array($p['cursor']) ? $p['cursor'] : null,
					(array) ($p['pairs'] ?? array()),
					(int) ($p['max_bytes'] ?? 2000000),
					$budget
				);
			case 'import':
				return $engine->import_table_chunk((string) ($p['job'] ?? ''), (array) ($p['chunk'] ?? array()), (string) ($p['source_prefix'] ?? ''));
			case 'manifest':
				return $engine->manifest_page((string) ($p['job'] ?? ''), (string) ($p['component'] ?? ''), (int) ($p['offset'] ?? 0), (array) ($p['excludes'] ?? array()), $budget, 3000, ! isset($p['hash']) || ! empty($p['hash']));
			case 'diff':
				return $engine->diff_entries((string) ($p['component'] ?? ''), (array) ($p['entries'] ?? array()), $budget);
			case 'read':
				return $engine->read_files((array) ($p['requests'] ?? array()));
			case 'write':
				return $engine->write_files((string) ($p['job'] ?? ''), (array) ($p['segments'] ?? array()));
			case 'finalize_files':
				return $engine->finalize_files((string) ($p['job'] ?? ''), (int) ($p['offset'] ?? 0), $budget);
			case 'finalize_db':
				$result = $engine->finalize_database((string) ($p['job'] ?? ''), (array) ($p['tables'] ?? array()), (string) ($p['source_prefix'] ?? ''));
				Operation_Logger::log('migration', 'Incoming migration applied', array('job' => (string) ($p['job'] ?? '')));
				return $result;
			case 'cleanup':
				return $engine->cleanup((string) ($p['job'] ?? ''));
			case 'rollback':
				$result = $engine->rollback();
				Operation_Logger::log('migration', 'Migration rolled back (remote request)', $result);
				return $result;
			case 'discard_rollback':
				$engine->discard_rollback();
				return array('discarded' => true);
		}
		throw new \RuntimeException('Unknown migration action: ' . $action);
	}

	private function respond(array $payload): void {
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
		$payload['wudt_api'] = Migration_Engine::API_VERSION;
		$json = wp_json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
		if (! is_string($json)) {
			$json = '{"wudt_api":2,"ok":false,"error":"Response encoding failed"}';
		}
		// Compress large responses: wrap deflated JSON in a small envelope.
		if (strlen($json) > 8192 && function_exists('gzdeflate')) {
			$json = '{"wudt_api":2,"z":"' . base64_encode((string) gzdeflate($json, 6)) . '"}';
		}
		if (! headers_sent()) {
			status_header(200);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store, no-cache, must-revalidate');
			header('X-Robots-Tag: noindex');
		}
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
