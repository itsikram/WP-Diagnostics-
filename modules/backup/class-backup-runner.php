<?php
/**
 * Backup Runner - resumable backup and restore jobs.
 *
 * Backups (format v2) are a single ZIP:
 *   manifest.json            site info, components, tables
 *   db/00001.json …          database chunks (rows as JSON, serialization-safe)
 *   files/<component>/…      plugins, themes, uploads, mu-plugins, languages
 *
 * Every step runs for a bounded time, so huge sites work on shared hosting.
 * Restores import into temporary tables and switch over atomically at the end,
 * keeping the replaced tables/files for one-click rollback (Migration_Engine).
 * Legacy (v1) backups with database.sql are restored too.
 */

declare(strict_types=1);

namespace WUDT\Modules\Backup;

use WUDT\Includes\Operation_Logger;
use WUDT\Modules\Migration\Migration_Engine;
use WUDT\Modules\Migration\Migration_Replacer;

if (! defined('ABSPATH')) {
	exit;
}

class Backup_Runner {
	public const FORMAT = 2;
	private const MAX_LOG = 100;
	private const STORE_EXT = '/\.(jpe?g|png|gif|webp|avif|mp4|m4v|mov|webm|mp3|m4a|ogg|zip|gz|rar|7z|woff2?|pdf)$/i';

	private array $state;
	private float $started;
	private Migration_Engine $engine;

	private function __construct(array $state) {
		$this->state = $state;
		$this->started = microtime(true);
		$this->engine = new Migration_Engine();
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Backups folder, locked against direct web access.
	 */
	public static function backup_dir(): string {
		$uploads = wp_get_upload_dir();
		$dir = wp_normalize_path((string) $uploads['basedir']) . '/wudt-backups';
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		if (! file_exists($dir . '/.htaccess') || false === strpos((string) @file_get_contents($dir . '/.htaccess'), 'denied')) {
			@file_put_contents($dir . '/.htaccess', "# WP Diagnostics: backups are only downloadable from wp-admin.\nOptions -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
		}
		if (! file_exists($dir . '/index.php')) {
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
			@file_put_contents($dir . '/index.html', '');
			@file_put_contents($dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
		}
		return $dir;
	}

	private static function jobs_dir(): string {
		return Migration_Engine::storage_dir('backup-jobs');
	}

	private static function job_file(string $id): string {
		return self::jobs_dir() . '/' . Migration_Engine::sanitize_job_id($id) . '.json';
	}

	public static function load(string $id): ?self {
		$file = self::job_file($id);
		if (! is_file($file)) {
			return null;
		}
		$state = json_decode((string) file_get_contents($file), true);
		return is_array($state) ? new self($state) : null;
	}

	private function save(): void {
		$this->state['updated'] = time();
		file_put_contents(self::job_file($this->state['id']), wp_json_encode($this->state), LOCK_EX);
	}

	public function get_state(): array {
		return $this->state;
	}

	public function check_token(string $token): bool {
		return '' !== $token && hash_equals((string) $this->state['token'], $token);
	}

	public static function active_job(): ?self {
		foreach ((array) glob(self::jobs_dir() . '/*.json') as $file) {
			$state = json_decode((string) file_get_contents((string) $file), true);
			if (is_array($state) && 'running' === ($state['status'] ?? '') && (time() - (int) $state['updated']) < 900) {
				return new self($state);
			}
		}
		return null;
	}

	private static function prune_jobs(): void {
		foreach ((array) glob(self::jobs_dir() . '/*.json') as $file) {
			if (filemtime((string) $file) < time() - 7 * DAY_IN_SECONDS) {
				@unlink((string) $file);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<int,string> $components database|plugins|themes|uploads|mu-plugins|languages
	 */
	public static function create_backup(array $components, string $note = '', string $trigger = 'manual'): self {
		self::prune_jobs();
		$components = array_values(array_intersect(array_merge(array('database'), Migration_Engine::COMPONENTS), $components));
		if (empty($components)) {
			throw new \RuntimeException(__('Select at least one thing to back up.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$host = preg_replace('/[^a-z0-9.-]/i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)) ?: 'site';
		$name = $host . '_' . wp_date('Y-m-d_H-i') . '_' . strtolower(wp_generate_password(8, false, false)) . '.zip';
		$id = 'b' . gmdate('ymdHis') . '_' . wp_generate_password(10, false, false);
		$tables = in_array('database', $components, true) ? array_column((new Migration_Engine())->list_tables(), 'name') : array();

		$runner = new self(array(
			'id'         => $id,
			'type'       => 'backup',
			'token'      => wp_generate_password(40, false, false),
			'status'     => 'running',
			'phase'      => 'database',
			'message'    => __('Starting backup…', 'wp-ultimate-diagnostics-toolkit'),
			'error'      => '',
			'created'    => time(),
			'updated'    => time(),
			'trigger'    => $trigger,
			'note'       => sanitize_text_field($note),
			'components' => $components,
			'file'       => self::backup_dir() . '/' . $name,
			'name'       => $name,
			'tables'     => $tables,
			'db'         => array('i' => 0, 'cursor' => null, 'part' => 0, 'rows' => 0, 'done_tables' => array()),
			'files'      => array('ci' => 0, 'pos' => 0, 'list' => '', 'count' => 0, 'total' => 0, 'bytes' => 0),
			'log'        => array(),
			'percent'    => 0,
		));
		$runner->log(sprintf('Backup started: %s', implode(', ', $components)));
		if (empty($tables)) {
			$runner->state['phase'] = 'files';
		}
		$runner->save();
		return $runner;
	}

	/**
	 * @param array<int,string> $components Components to restore (subset of the backup).
	 */
	public static function create_restore(string $file, array $components): self {
		self::prune_jobs();
		$info = self::inspect($file);
		$components = array_values(array_intersect($info['components'], $components));
		if (empty($components)) {
			throw new \RuntimeException(__('Select at least one part of the backup to restore.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$id = 'r' . gmdate('ymdHis') . '_' . wp_generate_password(10, false, false);
		$local = (new Migration_Engine())->site_info();
		$from = $info['site'];
		$pairs = Migration_Replacer::build_pairs($from, array(
			'home'    => $local['home'],
			'siteurl' => $local['siteurl'],
			'abspath' => $local['abspath'],
		));

		$runner = new self(array(
			'id'         => $id,
			'type'       => 'restore',
			'token'      => wp_generate_password(40, false, false),
			'status'     => 'running',
			'phase'      => in_array('database', $components, true) ? 'database' : 'files',
			'message'    => __('Starting restore…', 'wp-ultimate-diagnostics-toolkit'),
			'error'      => '',
			'created'    => time(),
			'updated'    => time(),
			'file'       => $file,
			'name'       => basename($file),
			'format'     => $info['format'],
			'components' => $components,
			'prefix'     => (string) ($from['prefix'] ?? $GLOBALS['wpdb']->prefix),
			'pairs'      => $pairs,
			'url_change' => untrailingslashit((string) ($from['home'] ?? '')) !== untrailingslashit((string) $local['home']),
			'db'         => array('i' => 0, 'offset' => 0, 'tables' => array(), 'entries' => $info['db_entries'], 'replace_i' => 0, 'replace_cursor' => 0, 'finalized' => false),
			'files'      => array('i' => 0, 'count' => 0, 'bytes' => 0, 'total' => $info['file_count']),
			'fin'        => array('offset' => 0, 'moved' => 0),
			'log'        => array(),
			'percent'    => 0,
		));
		$runner->engine->cleanup($id);
		$runner->log(sprintf('Restore of %s started (%s).', basename($file), implode(', ', $components)));
		if ($runner->state['url_change']) {
			$runner->log(sprintf('Backup was made on %s; links will be updated to %s.', (string) $from['home'], $local['home']));
		}
		$runner->save();
		return $runner;
	}

	/**
	 * Read what a backup archive contains.
	 *
	 * @return array{format:int,site:array,components:array<int,string>,db_entries:array<int,string>,file_count:int,created:string,note:string}
	 */
	public static function inspect(string $file): array {
		if (! class_exists('ZipArchive')) {
			throw new \RuntimeException(__('The PHP Zip extension is required.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$zip = new \ZipArchive();
		if (true !== $zip->open($file)) {
			throw new \RuntimeException(__('This backup file cannot be opened (damaged or incomplete).', 'wp-ultimate-diagnostics-toolkit'));
		}
		$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
		$result = array('format' => 1, 'site' => array(), 'components' => array(), 'db_entries' => array(), 'file_count' => 0, 'created' => '', 'note' => '');

		if (is_array($manifest) && (int) ($manifest['format'] ?? 0) >= 2) {
			$result['format'] = (int) $manifest['format'];
			$result['site'] = (array) ($manifest['site'] ?? array());
			$result['created'] = (string) ($manifest['created'] ?? '');
			$result['note'] = (string) ($manifest['note'] ?? '');
			$components = array();
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string) $zip->getNameIndex($i);
				if (0 === strpos($name, 'db/') && str_ends_with($name, '.json')) {
					$result['db_entries'][] = $name;
					$components['database'] = true;
				} elseif (preg_match('#^files/([a-z-]+)/.+[^/]$#', $name, $m)) {
					$components[$m[1]] = true;
					$result['file_count']++;
				}
			}
			sort($result['db_entries']);
			$result['components'] = array_keys($components);
		} else {
			// Legacy format: database.sql + plugins/ themes/ uploads/ folders.
			$config = json_decode((string) $zip->getFromName('config.json'), true);
			if (! is_array($config) && false === $zip->locateName('database.sql')) {
				$zip->close();
				throw new \RuntimeException(__('This file is not a WP Diagnostics backup.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$result['site'] = array(
				'home'   => (string) ($config['site_url'] ?? home_url()),
				'prefix' => (string) ($config['table_prefix'] ?? $GLOBALS['wpdb']->prefix),
			);
			$result['created'] = (string) ($config['created_at'] ?? '');
			$components = array();
			if (false !== $zip->locateName('database.sql')) {
				$components['database'] = true;
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string) $zip->getNameIndex($i);
				if (preg_match('#^(plugins|themes|uploads)/.+[^/]$#', $name, $m)) {
					$components[$m[1]] = true;
					$result['file_count']++;
				}
			}
			$result['components'] = array_keys($components);
		}
		$zip->close();
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Execution
	 * ------------------------------------------------------------------- */

	public function step(float $budget = 20.0): array {
		if ('failed' === $this->state['status']) {
			$this->state['status'] = 'running';
			$this->state['error'] = '';
			$this->log('Resuming after error…');
		}
		if ('running' !== $this->state['status']) {
			return $this->summary();
		}
		$lock = @fopen(self::job_file($this->state['id']) . '.lock', 'c');
		if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
			return array_merge($this->summary(), array('busy' => true));
		}
		Migration_Engine::raise_limits();
		try {
			while ('running' === $this->state['status'] && (microtime(true) - $this->started) < $budget) {
				if ('backup' === $this->state['type']) {
					$this->backup_step($budget);
				} else {
					$this->restore_step($budget);
				}
				$this->state['percent'] = $this->compute_percent();
				$this->save();
			}
		} catch (\Throwable $e) {
			$this->state['status'] = 'failed';
			$this->state['error'] = $e->getMessage();
			$this->state['message'] = $e->getMessage();
			$this->log('ERROR: ' . $e->getMessage());
			Operation_Logger::log('backup', ucfirst($this->state['type']) . ' failed', array('job' => $this->state['id'], 'error' => $e->getMessage()));
			$this->save();
		}
		flock($lock, LOCK_UN);
		fclose($lock);
		return $this->summary();
	}

	/**
	 * Run a job to completion in this request (cron / CLI / safety backups).
	 */
	public function run_to_end(float $max_seconds = 0): array {
		$deadline = $max_seconds > 0 ? microtime(true) + $max_seconds : 0;
		do {
			$this->started = microtime(true);
			$summary = $this->step(20.0);
		} while ('running' === $summary['status'] && (0 === $deadline || microtime(true) < $deadline));
		return $summary;
	}

	public function cancel(): array {
		if ('done' === $this->state['status']) {
			throw new \RuntimeException(__('This job already finished.', 'wp-ultimate-diagnostics-toolkit'));
		}
		if ('backup' === $this->state['type']) {
			@unlink($this->state['file']);
			$this->state['message'] = __('Backup cancelled.', 'wp-ultimate-diagnostics-toolkit');
		} else {
			if (! empty($this->state['db']['finalized']) || $this->state['fin']['moved'] > 0) {
				throw new \RuntimeException(__('The restore already started replacing data. Let it finish, then use Roll back if needed.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$this->engine->cleanup($this->state['id']);
			$this->state['message'] = __('Restore cancelled. Nothing was changed.', 'wp-ultimate-diagnostics-toolkit');
		}
		$this->cleanup_work_files();
		$this->state['status'] = 'cancelled';
		$this->log('Cancelled.');
		$this->save();
		return $this->summary();
	}

	public function summary(): array {
		$s = $this->state;
		return array(
			'id'         => $s['id'],
			'type'       => $s['type'],
			'status'     => $s['status'],
			'phase'      => $s['phase'],
			'message'    => $s['message'],
			'error'      => $s['error'],
			'percent'    => (int) $s['percent'],
			'name'       => $s['name'],
			'components' => $s['components'],
			'log'        => array_slice($s['log'], -30),
			'created'    => $s['created'],
			'size'       => ('backup' === $s['type'] && is_file($s['file'])) ? (int) filesize($s['file']) : 0,
		);
	}

	private function log(string $message): void {
		$this->state['log'][] = array('t' => time(), 'm' => $message);
		if (count($this->state['log']) > self::MAX_LOG) {
			$this->state['log'] = array_slice($this->state['log'], -self::MAX_LOG);
		}
	}

	private function time_left(float $budget): bool {
		return (microtime(true) - $this->started) < $budget;
	}

	/* ------------------------------ Backup ------------------------------ */

	private function open_zip(): \ZipArchive {
		$zip = new \ZipArchive();
		$flags = is_file($this->state['file']) ? 0 : \ZipArchive::CREATE;
		$res = $zip->open($this->state['file'], $flags);
		if (true !== $res) {
			throw new \RuntimeException(sprintf(__('Could not write the backup file (error %d). Check free disk space and permissions of wp-content/uploads.', 'wp-ultimate-diagnostics-toolkit'), (int) $res));
		}
		return $zip;
	}

	private function close_zip(\ZipArchive $zip): void {
		if (! $zip->close()) {
			throw new \RuntimeException(__('Could not save the backup file (disk full?).', 'wp-ultimate-diagnostics-toolkit'));
		}
	}

	private function backup_step(float $budget): void {
		switch ($this->state['phase']) {
			case 'database':
				$this->backup_database($budget);
				return;
			case 'files':
				$this->backup_files($budget);
				return;
			case 'finalize':
				$this->backup_finalize();
				return;
		}
		$this->state['status'] = 'done';
	}

	private function backup_database(float $budget): void {
		$db = &$this->state['db'];
		$tables = $this->state['tables'];
		$zip = $this->open_zip();
		$bytes_in_step = 0;
		while ($db['i'] < count($tables) && $this->time_left($budget - 4) && $bytes_in_step < 64 * MB_IN_BYTES) {
			$table = $tables[$db['i']];
			$this->state['message'] = sprintf(__('Backing up table %1$s (%2$d/%3$d)…', 'wp-ultimate-diagnostics-toolkit'), $table, $db['i'] + 1, count($tables));
			$chunk = $this->engine->export_table_chunk($table, $db['cursor'], array(), 4 * MB_IN_BYTES, 8);
			$json = (string) wp_json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			$db['part']++;
			$entry = sprintf('db/%05d.json', $db['part']);
			$zip->addFromString($entry, $json);
			$bytes_in_step += strlen($json);
			$db['rows'] += count((array) $chunk['rows']);
			$db['cursor'] = $chunk['cursor'];
			if (! empty($chunk['done'])) {
				$db['done_tables'][] = $table;
				$db['i']++;
				$db['cursor'] = null;
			}
		}
		$this->close_zip($zip);
		if ($db['i'] >= count($tables)) {
			$this->log(sprintf('Database saved: %d tables, %s rows.', count($tables), number_format_i18n($db['rows'])));
			$this->state['phase'] = 'files';
		}
	}

	private function backup_files(float $budget): void {
		$f = &$this->state['files'];
		$components = array_values(array_diff($this->state['components'], array('database')));
		if ($f['ci'] >= count($components)) {
			$this->state['phase'] = 'finalize';
			return;
		}
		$component = $components[$f['ci']];
		if ('' === $f['list']) {
			$this->state['message'] = sprintf(__('Listing %s…', 'wp-ultimate-diagnostics-toolkit'), $component);
			$list = $this->engine->prepare_file_list($this->state['id'], $component, array());
			$f['list'] = $list['file'];
			$f['root'] = $list['root'];
			$f['pos'] = 0;
			$f['total'] += $list['count'];
			return;
		}

		$zip = $this->open_zip();
		$fh = fopen($f['list'], 'rb');
		fseek($fh, (int) $f['pos']);
		$bytes = 0;
		$added = 0;
		// Files are read and compressed when the archive is closed, so the batch is sized
		// from the measured speed of earlier batches to keep each close near 8 seconds.
		$max_bytes = (int) ($f['batch_bytes'] ?? 24 * MB_IN_BYTES);
		$max_files = (int) ($f['batch_files'] ?? 600);
		while ($added < $max_files && $bytes < $max_bytes && $this->time_left($budget - 10)) {
			$line = fgets($fh);
			if (false === $line) {
				break;
			}
			$f['pos'] = ftell($fh);
			$rel = rtrim($line, "\r\n");
			$abs = $f['root'] . '/' . $rel;
			if ('' === $rel || ! is_file($abs) || ! is_readable($abs)) {
				continue;
			}
			$entry = 'files/' . $component . '/' . $rel;
			if ($zip->addFile($abs, $entry)) {
				if (preg_match(self::STORE_EXT, $rel)) {
					$zip->setCompressionName($entry, \ZipArchive::CM_STORE);
				}
				$size = (int) filesize($abs);
				$bytes += $size;
				$f['bytes'] += $size;
				$added++;
				$f['count']++;
			}
		}
		$eof = feof($fh) || false === fgets($fh);
		fclose($fh);
		$this->state['message'] = sprintf(__('Backing up %1$s… %2$s files (%3$s)', 'wp-ultimate-diagnostics-toolkit'), $component, number_format_i18n($f['count']), size_format($f['bytes']));
		$close_started = microtime(true);
		$this->close_zip($zip);
		$took = max(0.05, microtime(true) - $close_started);
		if ($added > 50) {
			$target = 8.0;
			$f['batch_bytes'] = (int) max(4 * MB_IN_BYTES, min(512 * MB_IN_BYTES, ($bytes / $took) * $target));
			$f['batch_files'] = (int) max(100, min(8000, ($added / $took) * $target));
		}

		if ($eof) {
			$this->log(sprintf('Saved %s.', $component));
			@unlink($f['list']);
			$f['list'] = '';
			$f['ci']++;
		}
	}

	private function backup_finalize(): void {
		global $wpdb;
		$this->state['message'] = __('Finishing…', 'wp-ultimate-diagnostics-toolkit');
		$info = $this->engine->site_info();
		$manifest = array(
			'format'     => self::FORMAT,
			'generator'  => 'WP Diagnostics ' . (defined('WUDT_VERSION') ? WUDT_VERSION : ''),
			'created'    => gmdate('c'),
			'note'       => $this->state['note'],
			'components' => $this->state['components'],
			'tables'     => $this->state['db']['done_tables'],
			'rows'       => $this->state['db']['rows'],
			'files'      => $this->state['files']['count'],
			'site'       => array(
				'name'        => $info['site_name'],
				'home'        => $info['home'],
				'siteurl'     => $info['siteurl'],
				'abspath'     => $info['abspath'],
				'abspath_raw' => $info['abspath_raw'],
				'prefix'      => $wpdb->prefix,
				'wp_version'  => $info['wp_version'],
				'php_version' => $info['php_version'],
			),
		);
		$zip = $this->open_zip();
		$zip->addFromString('manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$this->close_zip($zip);

		$check = new \ZipArchive();
		if (true !== $check->open($this->state['file'], \ZipArchive::CHECKCONS)) {
			throw new \RuntimeException(__('The finished backup failed its integrity check. Please run the backup again.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$check->close();

		$this->cleanup_work_files();
		$this->state['status'] = 'done';
		$this->state['phase'] = 'done';
		$this->state['message'] = __('Backup complete.', 'wp-ultimate-diagnostics-toolkit');
		$this->log(sprintf('Backup complete: %s (%s).', $this->state['name'], size_format((int) filesize($this->state['file']))));
		Operation_Logger::log('backup', 'Backup created', array('file' => $this->state['name'], 'size' => filesize($this->state['file']), 'trigger' => $this->state['trigger']));
		Backup_Store::save_meta($this->state['name'], array(
			'note'       => $this->state['note'],
			'components' => $this->state['components'],
			'trigger'    => $this->state['trigger'],
			'created'    => time(),
		));
	}

	/* ------------------------------ Restore ----------------------------- */

	private function restore_step(float $budget): void {
		switch ($this->state['phase']) {
			case 'database':
				2 === (int) $this->state['format'] || 3 === (int) $this->state['format'] ? $this->restore_database_v2($budget) : $this->restore_database_v1($budget);
				return;
			case 'replace':
				$this->restore_replace($budget);
				return;
			case 'files':
				$this->restore_files($budget);
				return;
			case 'finalize_files':
				$res = $this->engine->finalize_files($this->state['id'], (int) $this->state['fin']['offset'], max(5, $budget - (microtime(true) - $this->started) - 2));
				$this->state['fin']['offset'] = (int) $res['next'];
				$this->state['fin']['moved'] += (int) $res['moved'];
				$this->state['message'] = __('Putting files in place…', 'wp-ultimate-diagnostics-toolkit');
				if (! empty($res['done'])) {
					if ($this->state['fin']['moved'] > 0) {
						$this->log(sprintf('Restored %s files.', number_format_i18n($this->state['fin']['moved'])));
					}
					$this->state['phase'] = 'finalize_db';
				}
				return;
			case 'finalize_db':
				if (in_array('database', $this->state['components'], true) && ! empty($this->state['db']['tables']) && empty($this->state['db']['finalized'])) {
					$this->state['message'] = __('Switching to the restored database…', 'wp-ultimate-diagnostics-toolkit');
					$this->save();
					$this->engine->finalize_database($this->state['id'], $this->state['db']['tables'], $this->state['prefix']);
					$this->state['db']['finalized'] = true;
					$this->log(sprintf('Database restored (%d tables).', count($this->state['db']['tables'])));
				}
				$this->engine->cleanup($this->state['id']);
				$this->cleanup_work_files();
				$this->state['status'] = 'done';
				$this->state['phase'] = 'done';
				$this->state['message'] = __('Restore complete.', 'wp-ultimate-diagnostics-toolkit');
				$this->log('Restore complete.');
				Operation_Logger::log('backup', 'Backup restored', array('file' => $this->state['name'], 'components' => $this->state['components']));
				return;
		}
		$this->state['status'] = 'done';
	}

	private function after_database_phase(): void {
		$needs_replace = 1 === (int) $this->state['format'] && $this->state['url_change'] && ! empty($this->state['pairs']);
		$this->state['phase'] = $needs_replace ? 'replace' : 'files';
	}

	private function restore_database_v2(float $budget): void {
		$db = &$this->state['db'];
		$zip = new \ZipArchive();
		if (true !== $zip->open($this->state['file'])) {
			throw new \RuntimeException(__('The backup file cannot be opened.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$entries = $db['entries'];
		while ($db['i'] < count($entries) && $this->time_left($budget - 3)) {
			$chunk = json_decode((string) $zip->getFromName($entries[$db['i']]), true);
			if (! is_array($chunk) || empty($chunk['table'])) {
				$zip->close();
				throw new \RuntimeException(sprintf(__('Database data in the backup is damaged (%s).', 'wp-ultimate-diagnostics-toolkit'), $entries[$db['i']]));
			}
			$this->state['message'] = sprintf(__('Restoring table %1$s (%2$d%%)…', 'wp-ultimate-diagnostics-toolkit'), $chunk['table'], (int) (100 * $db['i'] / max(1, count($entries))));
			$this->engine->import_table_chunk($this->state['id'], $chunk, $this->state['prefix'], $this->state['url_change'] ? $this->state['pairs'] : array());
			if (! in_array($chunk['table'], $db['tables'], true)) {
				$db['tables'][] = $chunk['table'];
			}
			$db['i']++;
		}
		$zip->close();
		if ($db['i'] >= count($entries)) {
			$this->log(sprintf('Database imported (%d tables), not yet active.', count($db['tables'])));
			$this->after_database_phase();
		}
	}

	private function restore_database_v1(float $budget): void {
		$db = &$this->state['db'];
		$sql_file = Migration_Engine::storage_dir('work') . '/' . $this->state['id'] . '-database.sql';
		if (! is_file($sql_file)) {
			$zip = new \ZipArchive();
			if (true !== $zip->open($this->state['file'])) {
				throw new \RuntimeException(__('The backup file cannot be opened.', 'wp-ultimate-diagnostics-toolkit'));
			}
			$in = $zip->getStream('database.sql');
			$out = fopen($sql_file, 'wb');
			if (! $in || ! $out) {
				throw new \RuntimeException(__('Could not read database.sql from the backup.', 'wp-ultimate-diagnostics-toolkit'));
			}
			stream_copy_to_stream($in, $out);
			fclose($in);
			fclose($out);
			$zip->close();
			$db['size'] = (int) filesize($sql_file);
			return;
		}

		$fh = fopen($sql_file, 'rb');
		fseek($fh, (int) $db['offset']);
		$statement = '';
		$in_string = false;
		while ($this->time_left($budget - 2)) {
			$line = fgets($fh);
			if (false === $line) {
				break;
			}
			$statement .= $line;
			// Track quotes so a ";" inside a value does not end the statement.
			$len = strlen($line);
			for ($i = 0; $i < $len; $i++) {
				$c = $line[$i];
				if ('\\' === $c && $in_string) {
					$i++;
					continue;
				}
				if ("'" === $c) {
					$in_string = ! $in_string;
				}
			}
			if (! $in_string && ';' === substr(rtrim($statement), -1)) {
				$table = $this->engine->import_legacy_statement($this->state['id'], rtrim(rtrim($statement), ';'), $this->state['prefix']);
				if (null !== $table && ! in_array($table, $db['tables'], true)) {
					$db['tables'][] = $table;
				}
				$statement = '';
				$db['offset'] = ftell($fh);
			}
		}
		$eof = feof($fh);
		fclose($fh);
		$this->state['message'] = sprintf(__('Restoring database… %d%%', 'wp-ultimate-diagnostics-toolkit'), (int) (100 * $db['offset'] / max(1, (int) ($db['size'] ?? 1))));
		if ($eof) {
			@unlink($sql_file);
			$this->log(sprintf('Database imported (%d tables), not yet active.', count($db['tables'])));
			$this->after_database_phase();
		}
	}

	private function restore_replace(float $budget): void {
		$db = &$this->state['db'];
		if ($db['replace_i'] >= count($db['tables'])) {
			$this->log('Updated links for this site’s address.');
			$this->state['phase'] = 'files';
			return;
		}
		$table = $db['tables'][$db['replace_i']];
		$this->state['message'] = sprintf(__('Updating links in %s…', 'wp-ultimate-diagnostics-toolkit'), $table);
		$res = $this->engine->replace_in_tmp_table($this->state['id'], $table, $this->state['prefix'], $this->state['pairs'], (int) $db['replace_cursor'], max(4, $budget - (microtime(true) - $this->started) - 2));
		$db['replace_cursor'] = $res['cursor'];
		if ($res['done']) {
			$db['replace_i']++;
			$db['replace_cursor'] = 0;
		}
	}

	private function restore_files(float $budget): void {
		$f = &$this->state['files'];
		$components = array_values(array_diff($this->state['components'], array('database')));
		if (empty($components)) {
			$this->state['phase'] = 'finalize_db';
			return;
		}
		$zip = new \ZipArchive();
		if (true !== $zip->open($this->state['file'])) {
			throw new \RuntimeException(__('The backup file cannot be opened.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$stage = Migration_Engine::storage_dir('stage-' . $this->state['id']);
		$list = $stage . '/.staged-list';
		$lines = '';
		$v2 = (int) $this->state['format'] >= 2;
		while ($f['i'] < $zip->numFiles && $this->time_left($budget - 2)) {
			$name = (string) $zip->getNameIndex($f['i']);
			$f['i']++;
			if ('/' === substr($name, -1)) {
				continue;
			}
			if ($v2) {
				if (! preg_match('#^files/([a-z-]+)/(.+)$#', $name, $m)) {
					continue;
				}
			} elseif (! preg_match('#^(plugins|themes|uploads)/(.+)$#', $name, $m)) {
				continue;
			}
			list(, $component, $rel) = $m;
			if (! in_array($component, $components, true) || preg_match('#(^|/)\.\.(/|$)#', $rel) || Migration_Engine::is_excluded($component, $rel)) {
				continue;
			}
			$target = $stage . '/' . $component . '/' . $rel;
			wp_mkdir_p(dirname($target));
			$in = $zip->getStream($name);
			$out = @fopen($target, 'wb');
			if (! $in || ! $out) {
				throw new \RuntimeException(sprintf(__('Could not extract %s from the backup.', 'wp-ultimate-diagnostics-toolkit'), $rel));
			}
			$f['bytes'] += (int) stream_copy_to_stream($in, $out);
			fclose($in);
			fclose($out);
			$lines .= $component . '|' . $rel . "\n";
			$f['count']++;
		}
		$zip->close();
		if ('' !== $lines) {
			file_put_contents($list, $lines, FILE_APPEND | LOCK_EX);
		}
		$this->state['message'] = sprintf(__('Extracting files… %1$s (%2$s)', 'wp-ultimate-diagnostics-toolkit'), number_format_i18n($f['count']), size_format($f['bytes']));
		if ($f['i'] >= $this->num_entries()) {
			$this->log(sprintf('Extracted %s files.', number_format_i18n($f['count'])));
			$this->state['phase'] = 'finalize_files';
		}
	}

	private function num_entries(): int {
		static $count = null;
		if (null === $count) {
			$zip = new \ZipArchive();
			$count = true === $zip->open($this->state['file']) ? $zip->numFiles : 0;
			if ($count) {
				$zip->close();
			}
		}
		return $count;
	}

	/* ------------------------------ Misc -------------------------------- */

	private function cleanup_work_files(): void {
		foreach ((array) glob(Migration_Engine::storage_dir('work') . '/' . $this->state['id'] . '-*') as $file) {
			@unlink((string) $file);
		}
	}

	private function compute_percent(): int {
		$s = $this->state;
		if ('done' === $s['status']) {
			return 100;
		}
		if ('backup' === $s['type']) {
			$has_db = ! empty($s['tables']);
			$has_files = count(array_diff($s['components'], array('database'))) > 0;
			$db = $has_db ? min(1, $s['db']['i'] / max(1, count($s['tables']))) : 1;
			$files = 0.0;
			if ('files' === $s['phase'] && $s['files']['total'] > 0) {
				$files = min(0.95, $s['files']['count'] / max(1, $s['files']['total']));
			} elseif (in_array($s['phase'], array('finalize', 'done'), true)) {
				$files = 1;
			}
			$weights = array($has_db ? 30 : 0, $has_files ? 65 : 0);
			$total = array_sum($weights) ?: 1;
			return (int) min(99, (($weights[0] * $db + $weights[1] * $files) / $total) * 100);
		}
		$order = array('database' => 0, 'replace' => 1, 'files' => 2, 'finalize_files' => 3, 'finalize_db' => 4);
		$base = array(0, 40, 50, 85, 95);
		$i = $order[$s['phase']] ?? 4;
		$within = 0.0;
		if ('database' === $s['phase']) {
			$within = 2 <= (int) $s['format'] ? $s['db']['i'] / max(1, count($s['db']['entries'])) : (int) $s['db']['offset'] / max(1, (int) ($s['db']['size'] ?? 1));
		} elseif ('files' === $s['phase']) {
			$within = $s['files']['i'] / max(1, $this->num_entries());
		}
		$next = $base[$i + 1] ?? 99;
		return (int) min(99, $base[$i] + ($next - $base[$i]) * min(1, $within));
	}
}

/**
 * Listing, metadata and housekeeping for backup files.
 */
class Backup_Store {
	private const META_OPTION = 'wudt_backup_meta';

	public static function save_meta(string $name, array $meta): void {
		$all = get_option(self::META_OPTION, array());
		$all = is_array($all) ? $all : array();
		$all[$name] = $meta;
		update_option(self::META_OPTION, $all, false);
	}

	/**
	 * Resolve a backup name to its path (only files directly inside the backups folder,
	 * or legacy backups in their sub-folders).
	 */
	public static function path(string $name_or_path): string {
		$dir = Backup_Runner::backup_dir();
		$name_or_path = wp_normalize_path($name_or_path);
		foreach (self::all() as $item) {
			if ($item['name'] === basename($name_or_path) || $item['path'] === $name_or_path) {
				return $item['path'];
			}
		}
		throw new \RuntimeException(__('Backup not found.', 'wp-ultimate-diagnostics-toolkit'));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$dir = Backup_Runner::backup_dir();
		$meta = get_option(self::META_OPTION, array());
		$meta = is_array($meta) ? $meta : array();
		$items = array();
		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
		foreach ($iter as $file) {
			$name = $file->getFilename();
			if (! preg_match('/\.zip$/i', $name) || 0 === strpos($name, '.upload-') || false !== strpos(wp_normalize_path($file->getPathname()), '/tmp-')) {
				continue;
			}
			$m = $meta[$name] ?? array();
			$items[] = array(
				'name'       => $name,
				'path'       => wp_normalize_path($file->getPathname()),
				'size'       => (int) $file->getSize(),
				'created'    => (int) ($m['created'] ?? $file->getMTime()),
				'note'       => (string) ($m['note'] ?? ''),
				'components' => (array) ($m['components'] ?? array()),
				'trigger'    => (string) ($m['trigger'] ?? (str_contains(wp_normalize_path($file->getPathname()), '/backup-') ? 'legacy' : 'uploaded')),
			);
		}
		usort($items, static function ($a, $b) {
			return $b['created'] <=> $a['created'];
		});
		return $items;
	}

	public static function delete(string $name): void {
		$path = self::path($name);
		if (! @unlink($path)) {
			throw new \RuntimeException(__('Could not delete the backup file (permissions).', 'wp-ultimate-diagnostics-toolkit'));
		}
		$parent = dirname($path);
		if (wp_normalize_path($parent) !== wp_normalize_path(Backup_Runner::backup_dir()) && 0 === strpos(basename($parent), 'backup-') && 2 >= count((array) scandir($parent))) {
			@rmdir($parent);
		}
		$meta = get_option(self::META_OPTION, array());
		if (is_array($meta)) {
			unset($meta[basename($path)]);
			update_option(self::META_OPTION, $meta, false);
		}
	}

	/**
	 * Keep only the newest $keep scheduled backups.
	 */
	public static function apply_retention(int $keep): void {
		$scheduled = array_values(array_filter(self::all(), static function ($b) {
			return 'scheduled' === $b['trigger'];
		}));
		foreach (array_slice($scheduled, max(1, $keep)) as $old) {
			try {
				self::delete($old['name']);
			} catch (\Throwable $e) {
				unset($e);
			}
		}
	}
}
