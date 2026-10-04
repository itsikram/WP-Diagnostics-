<?php
/**
 * Migration Runner - drives a push or pull job one bounded step at a time.
 *
 * The runner always executes on the site where the user clicked Push/Pull
 * (usually localhost, which a live server cannot reach). Each step is started
 * by the browser, so no WP-Cron or long-running request is needed.
 *
 * Job state lives in a JSON file outside the database, because a pull
 * replaces this site's database while the job is still running.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Runner {
	private const STALE_AFTER = 900;
	private const MAX_LOG = 150;
	private const MAX_HISTORY = 30;

	private array $state;
	private float $started;
	private ?Remote_Client $client = null;
	private ?Migration_API $local = null;

	private function __construct(array $state) {
		$this->state = $state;
		$this->started = microtime(true);
	}

	/* ---------------------------------------------------------------------
	 * Job lifecycle
	 * ------------------------------------------------------------------- */

	public static function jobs_dir(): string {
		return Migration_Engine::storage_dir('jobs');
	}

	private static function job_file(string $id): string {
		return self::jobs_dir() . '/' . Migration_Engine::sanitize_job_id($id) . '.json';
	}

	/**
	 * @param array $site    {id,label,url,api_key,transport}
	 * @param array $options {components:string[], tables:string[], skip_unchanged:bool, excludes:string[]}
	 */
	public static function create(array $site, string $direction, array $options, array $remote_info, array $local_info): self {
		$id = 'm' . gmdate('ymdHis') . '_' . wp_generate_password(10, false, false);
		$is_pull = 'pull' === $direction;
		$src = $is_pull ? $remote_info : $local_info;
		$dst = $is_pull ? $local_info : $remote_info;

		$components = array_values(array_intersect(Migration_Engine::COMPONENTS, (array) ($options['components'] ?? array())));
		$src_tables = array();
		foreach ((array) ($src['tables'] ?? array()) as $t) {
			$src_tables[$t['name']] = (int) $t['rows'];
		}
		$merge = 'merge' === ($options['db_mode'] ?? '');
		$wanted = (array) ($options['tables'] ?? array());
		$merge_groups = array_values(array_intersect(Migration_Engine::MERGE_GROUPS, (array) ($options['merge_groups'] ?? array())));
		if ($merge) {
			// Adding content needs the WordPress content tables only; settings and plugin tables are never merged.
			$wanted = empty($wanted) || empty($merge_groups) ? array() : array_map(static function ($suffix) use ($src) {
				return (string) ($src['prefix'] ?? '') . $suffix;
			}, Migration_Engine::MERGE_TABLES);
		}
		$tables = array();
		$total_rows = 0;
		foreach ($wanted as $t) {
			if (isset($src_tables[$t])) {
				$tables[] = (string) $t;
				$total_rows += $src_tables[$t];
			}
		}
		$pt_filter = in_array($options['pt_filter'] ?? '', array('active', 'inactive', 'selected'), true) ? (string) $options['pt_filter'] : 'all';
		$pt_selected = array(
			'plugins' => array_values(array_filter(array_map('strval', (array) ($options['pt_plugins'] ?? array())))),
			'themes'  => array_values(array_filter(array_map('strval', (array) ($options['pt_themes'] ?? array())))),
		);
		if ('selected' === $pt_filter) {
			foreach (array('plugins' => 'plugin', 'themes' => 'theme') as $c => $noun) {
				if (in_array($c, $components, true) && empty($pt_selected[$c])) {
					throw new \RuntimeException(sprintf('Select at least one %s to migrate, or untick %s.', $noun, ucfirst($c)));
				}
			}
		}

		$state = array(
			'id'         => $id,
			'token'      => wp_generate_password(40, false, false),
			'created'    => time(),
			'updated'    => time(),
			'status'     => 'running',
			'phase'      => empty($tables) ? 'scan' : 'database',
			'message'    => 'Starting…',
			'error'      => '',
			'direction'  => $is_pull ? 'pull' : 'push',
			'site'       => array(
				'id'    => (string) $site['id'],
				'label' => (string) $site['label'],
				'url'   => (string) $site['url'],
			),
			'key'        => (string) $site['api_key'],
			'transport'  => (string) ($site['transport'] ?? ''),
			'components' => $components,
			'tables'     => $tables,
			'override'   => ! empty($options['override']),
			// Override replaces everything, so tables are never skipped as "unchanged";
			// a merge needs every content table imported to map IDs.
			'skip_unchanged' => ! $merge && empty($options['override']) && ! empty($options['skip_unchanged']),
			'excludes'   => array_values(array_filter(array_map('trim', (array) ($options['excludes'] ?? array())))),
			'db_mode'    => $merge ? 'merge' : 'replace',
			'merge_groups' => $merge_groups,
			'pt_filter'  => $pt_filter,
			'pt_selected' => 'selected' === $pt_filter ? $pt_selected : array(),
			'component_excludes' => self::plugin_theme_excludes($src, $pt_filter, $pt_selected),
			'src'        => self::slim_info($src),
			'dst'        => self::slim_info($dst),
			'pairs'      => Migration_Replacer::build_pairs($src, $dst),
			'db'         => array(
				'queue'    => $tables,
				'i'        => 0,
				'cursor'   => null,
				'rows'     => 0,
				'total'    => max(1, $total_rows),
				'imported' => array(),
				'skipped'  => array(),
				'checked'  => false,
			),
			'files'      => array(
				'ci'          => 0,
				'offset'      => 0,
				'scanned'     => 0,
				'changed'     => 0,
				'bytes_total' => 0,
				'qpos'        => 0,
				'seg_offset'  => 0,
				'done_files'  => 0,
				'done_bytes'  => 0,
				'retries'     => array(),
				'skipped'     => array(),
			),
			'fin'        => array('offset' => 0, 'moved' => 0),
			'log'        => array(),
			'warnings'   => array(),
			'percent'    => 0,
		);
		if (empty($tables) && empty($components)) {
			throw new \RuntimeException('Select at least one table or file component to migrate.');
		}

		$runner = new self($state);
		$runner->log(sprintf('%s migration %s %s', ucfirst($state['direction']), $is_pull ? 'from' : 'to', $state['site']['url']));
		$pt_note = '';
		if ('selected' === $pt_filter) {
			$picked = array();
			foreach (array('plugins', 'themes') as $c) {
				if (in_array($c, $components, true)) {
					$picked[] = $c . ': ' . implode(', ', array_map(array(self::class, 'plugin_folder'), $pt_selected[$c]));
				}
			}
			$pt_note = ' (only ' . implode('; ', $picked) . ')';
		} elseif ('all' !== $pt_filter) {
			$pt_note = ' (' . $pt_filter . ' plugins & themes only)';
		}
		$runner->log(sprintf(
			'Selected — database: %s; files: %s.',
			empty($tables) ? 'not included' : ($merge ? 'add as new content (' . implode(', ', $merge_groups) . ')' : count($tables) . ' tables'),
			empty($components) ? 'not included' : implode(', ', $components) . $pt_note
		));
		$runner->save();
		return $runner;
	}

	/**
	 * Plugin and theme folders to leave out when only active, only deactivated or
	 * only specific plugins and themes are wanted.
	 *
	 * @param array $selected {plugins:string[] plugin files, themes:string[] theme slugs} for the "selected" filter.
	 * @return array<string,array<int,string>>
	 */
	private static function plugin_theme_excludes(array $src, string $filter, array $selected = array()): array {
		if ('all' === $filter) {
			return array();
		}
		$keep_active = 'active' === $filter;
		$out = array('plugins' => array(), 'themes' => array());
		$active_plugins = (array) ($src['active_plugins'] ?? array());
		$keep_plugins = array_map(array(self::class, 'plugin_folder'), (array) ($selected['plugins'] ?? array()));
		foreach (array_keys((array) ($src['plugins'] ?? array())) as $file) {
			$dir = self::plugin_folder((string) $file);
			$keep = 'selected' === $filter ? in_array($dir, $keep_plugins, true) : in_array($file, $active_plugins, true) === $keep_active;
			if (! $keep) {
				$out['plugins'][] = $dir;
			}
		}
		$active_themes = array((string) ($src['stylesheet'] ?? ''), (string) ($src['template'] ?? ''));
		$keep_themes = array_map('strval', (array) ($selected['themes'] ?? array()));
		foreach (array_keys((array) ($src['themes'] ?? array())) as $slug) {
			$keep = 'selected' === $filter ? in_array((string) $slug, $keep_themes, true) : in_array((string) $slug, $active_themes, true) === $keep_active;
			if (! $keep) {
				$out['themes'][] = (string) $slug;
			}
		}
		// A folder shared by a kept plugin file must not be excluded.
		$out['plugins'] = array_values(array_diff(array_unique($out['plugins']), $keep_plugins));
		return $out;
	}

	/**
	 * Folder of a plugin ("akismet/akismet.php" → "akismet"), or the file itself for single-file plugins.
	 */
	private static function plugin_folder(string $file): string {
		$dir = dirname($file);
		return '.' === $dir ? $file : $dir;
	}

	private static function slim_info(array $info): array {
		$keep = array('home', 'siteurl', 'abspath', 'abspath_raw', 'prefix', 'post_max_size', 'memory_limit', 'max_packet', 'self_plugin_dir', 'wp_version');
		return array_intersect_key($info, array_flip($keep));
	}

	public static function load(string $id): ?self {
		$file = self::job_file($id);
		if (! file_exists($file)) {
			return null;
		}
		$state = json_decode((string) file_get_contents($file), true);
		return is_array($state) ? new self($state) : null;
	}

	private function save(): void {
		$this->state['updated'] = time();
		$this->state['percent'] = $this->compute_percent();
		file_put_contents(self::job_file($this->state['id']), wp_json_encode($this->state), LOCK_EX);
	}

	public function check_token(string $token): bool {
		return '' !== $token && hash_equals((string) $this->state['token'], $token);
	}

	public function get_state(): array {
		return $this->state;
	}

	/**
	 * Public view of a job, safe to send to the browser.
	 */
	public function summary(): array {
		$s = $this->state;
		return array(
			'id'         => $s['id'],
			'status'     => $s['status'],
			'phase'      => $s['phase'],
			'message'    => $s['message'],
			'error'      => $s['error'],
			'percent'    => $s['percent'],
			'direction'  => $s['direction'],
			'site'       => $s['site'],
			'components' => $s['components'],
			'override'   => ! empty($s['override']),
			'tables'     => count($s['tables']),
			'db_mode'    => $s['db_mode'] ?? 'replace',
			'pt_filter'  => $s['pt_filter'] ?? 'all',
			'pt_selected' => $s['pt_selected'] ?? array(),
			'created'    => $s['created'],
			'updated'    => $s['updated'],
			'log'        => array_slice($s['log'], -40),
			'warnings'   => $s['warnings'],
			'stats'      => array(
				'rows'          => $s['db']['rows'],
				'tables_done'   => count($s['db']['imported']),
				'tables_skipped'=> count($s['db']['skipped']),
				'files_changed' => $s['files']['changed'],
				'files_scanned' => $s['files']['scanned'],
				'files_done'    => $s['files']['done_files'],
				'bytes_done'    => $s['files']['done_bytes'],
				'bytes_total'   => $s['files']['bytes_total'],
			),
			'stale'      => 'running' === $s['status'] && (time() - (int) $s['updated']) > self::STALE_AFTER,
		);
	}

	/**
	 * @return array<int,array>
	 */
	public static function history(): array {
		$jobs = array();
		foreach ((array) glob(self::jobs_dir() . '/*.json') as $file) {
			$state = json_decode((string) file_get_contents((string) $file), true);
			if (is_array($state) && isset($state['id'])) {
				$jobs[] = (new self($state))->summary();
			}
		}
		usort($jobs, static function ($a, $b) {
			return $b['created'] <=> $a['created'];
		});
		foreach (array_slice($jobs, self::MAX_HISTORY) as $old) {
			if ('running' !== $old['status']) {
				@unlink(self::job_file($old['id']));
			}
		}
		return array_slice($jobs, 0, self::MAX_HISTORY);
	}

	public static function active_job(): ?self {
		foreach (self::history() as $job) {
			if ('running' === $job['status'] && ! $job['stale']) {
				return self::load($job['id']);
			}
		}
		return null;
	}

	public static function delete(string $id): void {
		$runner = self::load($id);
		if ($runner && 'running' === $runner->state['status'] && ! $runner->summary()['stale']) {
			throw new \RuntimeException('Cancel the migration before deleting it.');
		}
		@unlink(self::job_file($id));
		foreach ((array) glob(Migration_Engine::storage_dir('work') . '/' . Migration_Engine::sanitize_job_id($id) . '-*') as $f) {
			@unlink((string) $f);
		}
	}

	/* ---------------------------------------------------------------------
	 * Execution
	 * ------------------------------------------------------------------- */

	/**
	 * Run the job for up to $budget seconds.
	 */
	public function step(float $budget = 20.0): array {
		if ('failed' === $this->state['status']) {
			// Resume: every phase only advances after a sub-step succeeds, so retrying is safe.
			$this->state['status'] = 'running';
			$this->state['error'] = '';
			$this->log('Resuming after error…');
		}
		if ('running' !== $this->state['status']) {
			return $this->summary();
		}

		$lock = @fopen(self::job_file($this->state['id']) . '.lock', 'c');
		if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
			$summary = $this->summary();
			$summary['busy'] = true;
			return $summary;
		}

		Migration_Engine::raise_limits();
		try {
			while ('running' === $this->state['status'] && (microtime(true) - $this->started) < $budget) {
				$this->run_phase();
				$this->save();
			}
		} catch (\Throwable $e) {
			$this->state['status'] = 'failed';
			$this->state['error'] = $e->getMessage();
			$this->state['message'] = 'Migration stopped: ' . $e->getMessage();
			$this->log('ERROR: ' . $e->getMessage());
			Operation_Logger::log('migration', 'Migration failed', array('job' => $this->state['id'], 'phase' => $this->state['phase'], 'error' => $e->getMessage()));
			$this->save();
		}

		flock($lock, LOCK_UN);
		fclose($lock);
		return $this->summary();
	}

	public function cancel(): array {
		if (in_array($this->state['phase'], array('cleanup', 'done'), true) && 'done' === $this->state['status']) {
			throw new \RuntimeException('This migration already finished. Use Rollback to undo it.');
		}
		if ('finalize_db' === $this->state['phase'] && ! empty($this->state['db']['finalized'])) {
			throw new \RuntimeException('The database was already replaced. Use Rollback to undo it.');
		}
		foreach (array('dst', 'src') as $side) {
			try {
				$this->call($side, 'cleanup', array('job' => $this->state['id']));
			} catch (\Throwable $e) {
				$this->log('Cleanup on ' . $side . ' failed: ' . $e->getMessage());
			}
		}
		if (file_exists($this->queue_file())) {
			unlink($this->queue_file());
		}
		$this->state['status'] = 'cancelled';
		$this->state['message'] = 'Migration cancelled. The destination site was not changed.';
		if (in_array($this->state['phase'], array('finalize_files', 'finalize_db'), true) && $this->state['fin']['moved'] > 0) {
			$this->state['message'] = 'Migration cancelled. Some files were already replaced — use Rollback to restore them.';
		}
		if ('finalize_db' === $this->state['phase'] && 'merge' === ($this->state['db_mode'] ?? '')) {
			$this->state['message'] = 'Migration cancelled. Some content may already have been added — use Rollback to remove it.';
		}
		$this->log('Cancelled by user.');
		$this->save();
		return $this->summary();
	}

	private function run_phase(): void {
		switch ($this->state['phase']) {
			case 'database':
				$this->phase_database();
				return;
			case 'scan':
				$this->phase_scan();
				return;
			case 'transfer':
				$this->phase_transfer();
				return;
			case 'finalize_files':
				$this->phase_finalize_files();
				return;
			case 'finalize_db':
				$this->phase_finalize_db();
				return;
			case 'cleanup':
				$this->phase_cleanup();
				return;
		}
		$this->state['status'] = 'done';
	}

	private function next_phase(string $phase): void {
		$this->state['phase'] = $phase;
	}

	/* ------------------------------ Database ---------------------------- */

	private function phase_database(): void {
		$db = &$this->state['db'];

		if (! $db['checked']) {
			$db['checked'] = true;
			// A new database phase always starts from clean temporary tables.
			$this->call('dst', 'cleanup', array('job' => $this->state['id']));
			if ($this->state['skip_unchanged'] && ! empty($db['queue'])) {
				$this->state['message'] = 'Comparing tables…';
				$this->skip_unchanged_tables();
			}
			if (empty($db['queue'])) {
				$this->log('All selected tables are already identical on the destination.');
				$this->next_phase('scan');
				return;
			}
		}

		if ($db['i'] >= count($db['queue'])) {
			$this->log(sprintf('Database copied: %d tables, %s rows.', count($db['imported']), number_format_i18n($db['rows'])));
			$this->next_phase('scan');
			return;
		}

		$table = (string) $db['queue'][$db['i']];
		$this->state['message'] = sprintf('Copying table %s (%d/%d)…', $table, $db['i'] + 1, count($db['queue']));

		$max_bytes = 3 * MB_IN_BYTES;
		if ('push' === $this->state['direction']) {
			$post_max = (int) ($this->state['dst']['post_max_size'] ?? 0);
			if ($post_max > 0) {
				$max_bytes = (int) max(256 * KB_IN_BYTES, min($max_bytes, $post_max / 2));
			}
		}

		$chunk = $this->call('src', 'export', array(
			'table'     => $table,
			'cursor'    => $db['cursor'],
			'pairs'     => $this->state['pairs'],
			'max_bytes' => $max_bytes,
			'budget'    => 10,
		));
		if (! is_array($chunk)) {
			throw new \RuntimeException('Empty export response for ' . $table);
		}
		$this->call('dst', 'import', array(
			'job'           => $this->state['id'],
			'chunk'         => $chunk,
			'source_prefix' => $this->state['src']['prefix'],
		));

		$db['rows'] += count((array) ($chunk['rows'] ?? array()));
		$db['cursor'] = $chunk['cursor'] ?? null;
		if (! empty($chunk['done'])) {
			$db['imported'][] = $table;
			$db['i']++;
			$db['cursor'] = null;
		}
	}

	private function skip_unchanged_tables(): void {
		$db = &$this->state['db'];
		$src_prefix = (string) $this->state['src']['prefix'];
		$dst_prefix = (string) $this->state['dst']['prefix'];
		$mapped = array();
		foreach ($db['queue'] as $t) {
			$mapped[$t] = $dst_prefix . substr((string) $t, strlen($src_prefix));
		}
		$src_sums = (array) $this->call('src', 'checksums', array('tables' => array_keys($mapped), 'budget' => 15));
		$dst_sums = (array) $this->call('dst', 'checksums', array('tables' => array_values($mapped), 'budget' => 15));
		$keep = array();
		foreach ($mapped as $src_table => $dst_table) {
			$a = $src_sums[$src_table] ?? null;
			$b = $dst_sums[$dst_table] ?? null;
			if (null !== $a && null !== $b && (string) $a === (string) $b) {
				$db['skipped'][] = $src_table;
			} else {
				$keep[] = $src_table;
			}
		}
		if (! empty($db['skipped'])) {
			$this->log(sprintf('Skipping %d unchanged tables.', count($db['skipped'])));
		}
		$db['queue'] = $keep;
	}

	/* ------------------------------ Files ------------------------------- */

	private function queue_file(): string {
		return Migration_Engine::storage_dir('work') . '/' . $this->state['id'] . '-queue.jsonl';
	}

	private function phase_scan(): void {
		$f = &$this->state['files'];
		$components = $this->state['components'];
		if ($f['ci'] >= count($components)) {
			$this->log(sprintf('Scan complete: %s of %s files changed (%s).', number_format_i18n($f['changed']), number_format_i18n($f['scanned']), size_format($f['bytes_total'])));
			$this->next_phase($f['changed'] > 0 ? 'transfer' : 'finalize_files');
			return;
		}
		$component = (string) $components[$f['ci']];
		$this->state['message'] = sprintf('Comparing %s files… (%s checked)', $component, number_format_i18n($f['scanned']));
		if (0 === $f['offset'] && 0 === $f['ci'] && file_exists($this->queue_file())) {
			unlink($this->queue_file());
		}
		$override = ! empty($this->state['override']);
		if ($override) {
			$this->state['message'] = sprintf('Listing %s files (override: no comparison)… %s found', $component, number_format_i18n($f['scanned']));
		}

		$page = (array) $this->call('src', 'manifest', array(
			'job'       => $this->state['id'],
			'component' => $component,
			'offset'    => $f['offset'],
			'excludes'  => array_merge($this->state['excludes'], (array) ($this->state['component_excludes'][$component] ?? array())),
			'budget'    => 12,
			'hash'      => ! $override,
		));
		$entries = (array) ($page['entries'] ?? array());
		if (! empty($entries)) {
			// Override mode sends every file without asking the destination what it has.
			$changed = $override
				? $entries
				: (array) (((array) $this->call('dst', 'diff', array('component' => $component, 'entries' => $entries, 'budget' => 12)))['changed'] ?? array());
			$lines = '';
			foreach ($changed as $entry) {
				$lines .= wp_json_encode(array('c' => $component, 'p' => $entry['p'], 's' => (int) $entry['s'])) . "\n";
				$f['changed']++;
				$f['bytes_total'] += (int) $entry['s'];
			}
			if ('' !== $lines) {
				file_put_contents($this->queue_file(), $lines, FILE_APPEND | LOCK_EX);
			}
			$f['scanned'] += count($entries);
		}
		$f['offset'] = (int) ($page['next'] ?? 0);
		$this->state['message'] = $override
			? sprintf('Listing %s files (override)… %s queued', $component, number_format_i18n($f['changed']))
			: sprintf('Comparing %s files… (%s checked, %s changed)', $component, number_format_i18n($f['scanned']), number_format_i18n($f['changed']));
		if (! empty($page['done'])) {
			$this->log(sprintf('Checked %s files.', $component));
			$f['ci']++;
			$f['offset'] = 0;
		}
	}

	private function phase_transfer(): void {
		$f = &$this->state['files'];
		$queue = $this->queue_file();
		if (! file_exists($queue)) {
			throw new \RuntimeException('The file transfer queue is missing. Cancel and start the migration again.');
		}

		$limit = 4 * MB_IN_BYTES;
		if ('push' === $this->state['direction']) {
			$post_max = (int) ($this->state['dst']['post_max_size'] ?? 0);
			if ($post_max > 0) {
				$limit = (int) max(256 * KB_IN_BYTES, min($limit, $post_max / 2.5));
			}
		}

		$fh = fopen($queue, 'rb');
		fseek($fh, (int) $f['qpos']);
		$requests = array();
		$positions = array(); // Line end offsets, parallel to $requests.
		$planned = 0;
		$partial = false;
		while (count($requests) < 250 && $planned < $limit) {
			$line = fgets($fh);
			if (false === $line) {
				break;
			}
			$end = ftell($fh);
			$entry = json_decode(trim($line), true);
			if (! is_array($entry)) {
				$positions[] = $end;
				$requests[] = null;
				continue;
			}
			$offset = empty($requests) ? (int) $f['seg_offset'] : 0;
			$remaining = max(0, (int) $entry['s'] - $offset);
			$length = min($remaining, $limit - $planned);
			if ($length <= 0 && $remaining > 0) {
				break;
			}
			$requests[] = array('c' => $entry['c'], 'p' => $entry['p'], 'o' => $offset, 'l' => $length);
			$positions[] = $end;
			$planned += $length;
			if ($length < $remaining) {
				$partial = true;
				break;
			}
		}
		fclose($fh);

		$valid = array_values(array_filter($requests));
		if (empty($requests)) {
			$this->log(sprintf('Transferred %s files (%s).', number_format_i18n($f['done_files']), size_format($f['done_bytes'])));
			$this->next_phase('finalize_files');
			return;
		}

		$this->state['message'] = sprintf('Transferring files… %s / %s', size_format($f['done_bytes']), size_format(max($f['bytes_total'], $f['done_bytes'])));

		$segments = empty($valid) ? array() : (array) $this->call('src', 'read', array('requests' => $valid));
		$write = array();
		foreach ($segments as $seg) {
			if (! empty($seg['missing'])) {
				$f['skipped'][] = $seg['c'] . '/' . $seg['p'];
				$this->log('Skipped (no longer exists on source): ' . $seg['c'] . '/' . $seg['p']);
				continue;
			}
			$write[] = $seg;
		}
		$result = empty($write) ? array('failed' => array(), 'bytes' => 0) : (array) $this->call('dst', 'write', array('job' => $this->state['id'], 'segments' => $write));

		$sizes = array();
		foreach ($write as $seg) {
			$sizes[$seg['c'] . '/' . $seg['p']] = (int) ($seg['s'] ?? 0);
		}
		foreach ((array) ($result['failed'] ?? array()) as $failed) {
			$key = $failed['c'] . '/' . $failed['p'];
			$tries = (int) ($f['retries'][$key] ?? 0);
			if ($tries < 2) {
				$f['retries'][$key] = $tries + 1;
				file_put_contents($queue, wp_json_encode(array('c' => $failed['c'], 'p' => $failed['p'], 's' => $sizes[$key] ?? 0)) . "\n", FILE_APPEND | LOCK_EX);
				$this->log('File changed during copy, retrying: ' . $key);
			} else {
				$f['skipped'][] = $key;
				$this->state['warnings'][] = 'Skipped a file that kept changing during transfer: ' . $key;
			}
		}

		// Advance the queue pointer.
		$last_seg = end($segments);
		$count = count($requests);
		if ($partial && is_array($last_seg) && empty($last_seg['eof']) && empty($last_seg['missing'])) {
			// Last file continues in the next step.
			$f['qpos'] = $count > 1 ? $positions[$count - 2] : (int) $f['qpos'];
			$f['seg_offset'] = (int) $last_seg['o'] + strlen((string) base64_decode((string) $last_seg['d']));
			$f['done_files'] += max(0, $count - 1);
		} else {
			$f['qpos'] = $positions[$count - 1];
			$f['seg_offset'] = 0;
			$f['done_files'] += $count;
		}
		$f['done_bytes'] += (int) ($result['bytes'] ?? 0);
	}

	/* ------------------------------ Finalize ---------------------------- */

	private function phase_finalize_files(): void {
		if (empty($this->state['components'])) {
			$this->next_phase('finalize_db');
			return;
		}
		$this->state['message'] = 'Moving files into place…';
		$res = (array) $this->call('dst', 'finalize_files', array('job' => $this->state['id'], 'offset' => $this->state['fin']['offset'], 'budget' => 15));
		$this->state['fin']['offset'] = (int) ($res['next'] ?? 0);
		$this->state['fin']['moved'] += (int) ($res['moved'] ?? 0);
		if (! empty($res['done'])) {
			if ($this->state['fin']['moved'] > 0) {
				$this->log(sprintf('Updated %s files on the destination.', number_format_i18n($this->state['fin']['moved'])));
			}
			$this->next_phase('finalize_db');
		}
	}

	private function phase_finalize_db(): void {
		$imported = $this->state['db']['imported'];
		if (! empty($imported) && empty($this->state['db']['finalized']) && 'merge' === ($this->state['db_mode'] ?? '')) {
			$this->state['message'] = 'Adding content as new items…';
			$res = (array) $this->call('dst', 'merge_db', array(
				'job'           => $this->state['id'],
				'source_prefix' => $this->state['src']['prefix'],
				'source_key'    => substr(md5((string) $this->state['src']['home']), 0, 12),
				'groups'        => $this->state['merge_groups'],
				'budget'        => 15,
			));
			$s = (array) ($res['stats'] ?? array());
			$this->state['message'] = sprintf('Adding content as new items… %s posts, %s terms, %s comments, %s users added', number_format_i18n((int) ($s['posts'] ?? 0)), number_format_i18n((int) ($s['terms'] ?? 0)), number_format_i18n((int) ($s['comments'] ?? 0)), number_format_i18n((int) ($s['users'] ?? 0)));
			if (empty($res['done'])) {
				return; // Continue in the next step.
			}
			$this->state['db']['finalized'] = true;
			$this->log(sprintf(
				'Content added as new items: %d posts, %d terms, %d comments, %d users (%d posts already added earlier were skipped). Existing content was not changed.',
				(int) ($s['posts'] ?? 0),
				(int) ($s['terms'] ?? 0),
				(int) ($s['comments'] ?? 0),
				(int) ($s['users'] ?? 0),
				(int) ($s['skipped'] ?? 0)
			));
		}
		if (! empty($imported) && empty($this->state['db']['finalized'])) {
			$this->state['message'] = 'Activating the new database…';
			$this->save();
			$this->call('dst', 'finalize_db', array(
				'job'           => $this->state['id'],
				'tables'        => $imported,
				'source_prefix' => $this->state['src']['prefix'],
			));
			$this->state['db']['finalized'] = true;
			$this->log(sprintf('Database switched over (%d tables).', count($imported)));
		}
		$this->next_phase('cleanup');
	}

	private function phase_cleanup(): void {
		$this->state['message'] = 'Cleaning up…';
		foreach (array('dst', 'src') as $side) {
			try {
				$this->call($side, 'cleanup', array('job' => $this->state['id']));
			} catch (\Throwable $e) {
				$this->log('Cleanup warning: ' . $e->getMessage());
			}
		}
		if (file_exists($this->queue_file())) {
			unlink($this->queue_file());
		}

		$this->state['status'] = 'done';
		$this->next_phase('done');
		$this->state['message'] = 'Migration complete!';
		$this->log('Migration complete.');
		Operation_Logger::log('migration', 'Migration completed', array(
			'job'       => $this->state['id'],
			'direction' => $this->state['direction'],
			'site'      => $this->state['site']['url'],
		));
	}

	/* ------------------------------ Transport --------------------------- */

	/**
	 * Call an action on the source or destination side.
	 */
	private function call(string $side, string $action, array $params) {
		$is_remote = ('pull' === $this->state['direction']) ? ('src' === $side) : ('dst' === $side);
		if (! $is_remote) {
			if (null === $this->local) {
				$this->local = new Migration_API();
			}
			return $this->local->dispatch($action, $params);
		}
		if (null === $this->client) {
			$this->client = new Remote_Client($this->state['site']['url'], $this->state['key'], $this->state['transport']);
		}
		$result = $this->client->call($action, $params, 120);
		if ($this->client->get_transport() !== $this->state['transport']) {
			$this->state['transport'] = $this->client->get_transport();
		}
		return $result;
	}

	private function log(string $message): void {
		$this->state['log'][] = array('t' => time(), 'm' => $message);
		if (count($this->state['log']) > self::MAX_LOG) {
			$this->state['log'] = array_slice($this->state['log'], -self::MAX_LOG);
		}
	}

	private function compute_percent(): int {
		$s = $this->state;
		if ('done' === $s['status']) {
			return 100;
		}
		$has_db = ! empty($s['tables']);
		$has_files = ! empty($s['components']);
		$weights = array(
			'database' => $has_db ? 45 : 0,
			'scan'     => $has_files ? 10 : 0,
			'transfer' => $has_files ? 35 : 0,
			'finalize' => 10,
		);
		$order = array('database', 'scan', 'transfer', 'finalize_files', 'finalize_db', 'cleanup', 'done');
		$current = array_search($s['phase'], $order, true);
		$fraction = array(
			'database' => 0.0,
			'scan'     => 0.0,
			'transfer' => 0.0,
			'finalize' => 0.0,
		);
		if ($current > 0) {
			$fraction['database'] = 1.0;
		} else {
			$fraction['database'] = min(1.0, $s['db']['rows'] / max(1, $s['db']['total']));
		}
		if ($current > 1) {
			$fraction['scan'] = 1.0;
		} elseif (1 === $current) {
			$fraction['scan'] = min(0.95, $s['files']['ci'] / max(1, count($s['components'])));
		}
		if ($current > 2) {
			$fraction['transfer'] = 1.0;
		} elseif (2 === $current) {
			$fraction['transfer'] = min(1.0, $s['files']['done_bytes'] / max(1, $s['files']['bytes_total']));
		}
		if ($current >= 5) {
			$fraction['finalize'] = 0.9;
		} elseif ($current >= 3) {
			$fraction['finalize'] = 0.4;
		}
		$total = array_sum($weights);
		$done = 0.0;
		foreach ($weights as $k => $w) {
			$done += $w * $fraction[$k];
		}
		return (int) min(99, floor(($done / max(1, $total)) * 100));
	}
}
