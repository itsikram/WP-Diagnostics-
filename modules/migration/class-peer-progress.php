<?php
/**
 * Peer Progress - migrations another site is running with this one.
 *
 * The site that runs a migration (it drives every step from its browser) reports
 * its progress here through the signed API, so this site's Site Migration page
 * can show what is being sent to or received from it.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Peer_Progress {
	// Without a report for this long, a running migration is shown as paused.
	public const STALE_AFTER = 300;
	private const KEEP_FOR = DAY_IN_SECONDS;
	private const MAX_JOBS = 10;
	private const MAX_LOG = 20;

	private static function dir(): string {
		return Migration_Engine::storage_dir('peer-jobs');
	}

	private static function file(string $id): string {
		return self::dir() . '/' . Migration_Engine::sanitize_job_id($id) . '.json';
	}

	/**
	 * Save a progress report (called through the signed API).
	 */
	public static function record(array $p): array {
		$id = Migration_Engine::sanitize_job_id((string) ($p['id'] ?? ''));
		$from = esc_url_raw(untrailingslashit((string) ($p['from'] ?? '')));
		$existing = self::read(self::file($id));
		// The live progress file name is secret, like the one of the site running the job.
		$token = (string) ($existing['token'] ?? '') ?: wp_generate_password(32, false, false);

		$stats = array();
		foreach ((array) ($p['stats'] ?? array()) as $k => $v) {
			$stats[sanitize_key((string) $k)] = (int) $v;
		}
		$log = array();
		foreach (array_slice((array) ($p['log'] ?? array()), -self::MAX_LOG) as $line) {
			if (is_array($line)) {
				$log[] = array('t' => (int) ($line['t'] ?? 0), 'm' => sanitize_text_field((string) ($line['m'] ?? '')));
			}
		}
		$status = sanitize_key((string) ($p['status'] ?? 'running'));
		$job = array(
			'id'            => $id,
			'token'         => $token,
			'status'        => in_array($status, array('running', 'done', 'failed', 'cancelled'), true) ? $status : 'running',
			'phase'         => sanitize_key((string) ($p['phase'] ?? '')),
			'message'       => sanitize_text_field((string) ($p['message'] ?? '')),
			'error'         => sanitize_text_field((string) ($p['error'] ?? '')),
			'percent'       => max(0, min(100, (int) ($p['percent'] ?? 0))),
			// From this site's point of view: a push to here is received, a pull from here is sent.
			'role'          => 'pull' === ($p['direction'] ?? '') ? 'sending' : 'receiving',
			'peer'          => array(
				'url'   => $from,
				'label' => sanitize_text_field((string) ($p['from_label'] ?? '')) ?: (string) wp_parse_url($from, PHP_URL_HOST),
			),
			'tables'        => (int) ($p['tables'] ?? 0),
			'components'    => array_values(array_map('sanitize_key', (array) ($p['components'] ?? array()))),
			'files_count'   => (int) ($p['files_count'] ?? 0),
			'stats'         => $stats,
			'log'           => $log,
			// This site's clock, so "elapsed" and "paused" do not depend on the other server's time.
			'created'       => (int) ($existing['created'] ?? time()),
			'phase_started' => ($existing['phase'] ?? '') === ($p['phase'] ?? '') ? (int) ($existing['phase_started'] ?? time()) : time(),
			'updated'       => time(),
			'seq'           => (int) ($existing['seq'] ?? 0) + 1,
		);
		$json = (string) wp_json_encode($job);
		file_put_contents(self::file($id), $json, LOCK_EX);
		self::write_public($job);
		if (empty($existing)) {
			self::prune();
		}
		return array('recorded' => true);
	}

	/**
	 * Recent migrations other sites ran with this one, newest first, safe for the browser.
	 *
	 * @return array<int,array>
	 */
	public static function recent(): array {
		$jobs = array();
		foreach ((array) glob(self::dir() . '/*.json') as $file) {
			$job = self::read((string) $file);
			if (! empty($job['id'])) {
				$jobs[] = self::summary($job);
			}
		}
		usort($jobs, static function ($a, $b) {
			return $b['updated'] <=> $a['updated'];
		});
		return array_slice($jobs, 0, self::MAX_JOBS);
	}

	public static function dismiss(string $id): void {
		$file = self::file($id);
		$job = self::read($file);
		if (! empty($job['token'])) {
			wp_delete_file(self::public_location((string) $job['token'])['file']);
		}
		wp_delete_file($file);
	}

	private static function summary(array $job): array {
		$out = $job;
		unset($out['token']);
		$out['progress_url'] = self::public_location((string) $job['token'])['url'];
		$out['stale'] = 'running' === $job['status'] && time() - (int) $job['updated'] > self::STALE_AFTER;
		$out['now'] = time();
		return $out;
	}

	/**
	 * Live copy readable without loading WordPress, so the page keeps updating
	 * even when a received database logs the viewer out.
	 *
	 * @return array{file:string,url:string}
	 */
	private static function public_location(string $token): array {
		$dir = wp_normalize_path(WP_CONTENT_DIR) . '/wudt-progress';
		$name = 'peer-' . substr(hash('sha256', 'wudt-peer-progress|' . $token), 0, 40) . '.json';
		return array('file' => $dir . '/' . $name, 'url' => content_url('/wudt-progress/' . $name));
	}

	private static function write_public(array $job): void {
		$loc = self::public_location((string) $job['token']);
		$dir = dirname($loc['file']);
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		if (! file_exists($dir . '/index.php')) {
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		}
		$tmp = $loc['file'] . '.' . getmypid() . '.tmp';
		$json = (string) wp_json_encode(self::summary($job));
		if (false !== @file_put_contents($tmp, $json) && ! @rename($tmp, $loc['file'])) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace of the plugin's own progress file.
			@file_put_contents($loc['file'], $json);
			wp_delete_file($tmp);
		}
	}

	/**
	 * Forget finished reports after a day, and keep only the most recent ones.
	 */
	private static function prune(): void {
		$jobs = array();
		foreach ((array) glob(self::dir() . '/*.json') as $file) {
			$job = self::read((string) $file);
			$jobs[] = array('id' => (string) ($job['id'] ?? ''), 'file' => (string) $file, 'updated' => (int) ($job['updated'] ?? 0));
		}
		usort($jobs, static function ($a, $b) {
			return $b['updated'] <=> $a['updated'];
		});
		foreach ($jobs as $i => $job) {
			if ($i >= self::MAX_JOBS || time() - $job['updated'] > self::KEEP_FOR) {
				if ('' !== $job['id']) {
					self::dismiss($job['id']);
				} else {
					wp_delete_file($job['file']);
				}
			}
		}
	}

	private static function read(string $file): array {
		if (! is_file($file)) {
			return array();
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : array();
	}
}
