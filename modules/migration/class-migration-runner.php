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
	private const DEFAULT_PARALLEL = 4;

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
	 * @param array $options {components:string[], tables:string[], skip_unchanged:bool, excludes:string[], exclude_dev:bool, sites_snapshot:array}
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
			// What the other site's plugin version supports (binary transfer, lazy hashing…).
			'remote_features' => array_values((array) (($is_pull ? $src : $dst)['features'] ?? array())),
			'par'        => max(1, min(8, (int) apply_filters('wudt_migration_parallel', self::DEFAULT_PARALLEL))),
			'components' => $components,
			'tables'     => $tables,
			'sites_snapshot' => (array) ($options['sites_snapshot'] ?? array()),
			'override'   => ! empty($options['override']),
			// Override replaces everything, so tables are never skipped as "unchanged";
			// a merge needs every content table imported to map IDs.
			'skip_unchanged' => ! $merge && empty($options['override']) && ! empty($options['skip_unchanged']),
			'custom_excludes' => array_values(array_unique(array_filter(array_map('trim', (array) ($options['excludes'] ?? array()))))),
			'excludes'   => array_values(array_unique(array_merge(
				array_filter(array_map('trim', (array) ($options['excludes'] ?? array()))),
				! empty($options['exclude_dev']) ? Migration_Engine::DEV_EXCLUDES : array()
			))),
			'exclude_dev' => ! empty($options['exclude_dev']),
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
				'lanes'    => array(),
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
			'selected_tables'  => $s['tables'],
			'skip_unchanged'   => ! empty($s['skip_unchanged']),
			'excludes'         => $s['custom_excludes'] ?? array_values(array_diff(
				(array) $s['excludes'],
				! empty($s['exclude_dev']) ? Migration_Engine::DEV_EXCLUDES : array()
			)),
			'exclude_dev'      => ! empty($s['exclude_dev']),
			'override'         => ! empty($s['override']),
			'tables'           => count($s['tables']),
			'db_mode'          => $s['db_mode'] ?? 'replace',
			'merge_groups'     => $s['merge_groups'] ?? array(),
			'pt_filter'        => $s['pt_filter'] ?? 'all',
			'pt_selected'      => $s['pt_selected'] ?? array(),
			'created'          => $s['created'],
			'updated'          => $s['updated'],
			'log'              => array_slice($s['log'], -40),
			'warnings'         => $s['warnings'],
			'stats'            => array(
				'rows'           => $s['db']['rows'],
				'tables_done'    => count($s['db']['imported']),
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

	/**
	 * Copies several tables at once ("lanes"): each lane exports one chunk of
	 * its table from the source, then all chunks are imported on the destination.
	 * The remote side of each round runs in parallel.
	 */
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

		if (! isset($db['lanes'])) {
			// Job started by an older version: continue its single table as a lane.
			$db['lanes'] = array();
			if (null !== $db['cursor'] && $db['i'] < count($db['queue'])) {
				$db['lanes'][] = array('t' => (string) $db['queue'][$db['i']], 'c' => $db['cursor']);
				$db['i']++;
			}
		}
		$par = $this->parallel();
		while (count($db['lanes']) < $par && $db['i'] < count($db['queue'])) {
			$db['lanes'][] = array('t' => (string) $db['queue'][$db['i']], 'c' => null);
			$db['i']++;
		}
		if (empty($db['lanes'])) {
			$this->log(sprintf('Database copied: %d tables, %s rows.', count($db['imported']), number_format_i18n($db['rows'])));
			$this->next_phase('scan');
			return;
		}

		$names = array_column($db['lanes'], 't');
		$this->state['message'] = sprintf(
			'Copying table%s %s (%d/%d)…',
			count($names) > 1 ? 's' : '',
			implode(', ', $names),
			count($db['imported']) + 1,
			count($db['queue'])
		);

		$max_bytes = $this->db_chunk_bytes(count($db['lanes']));
		$exports = array();
		foreach ($db['lanes'] as $n => $lane) {
			$exports[$n] = array(
				'table'     => $lane['t'],
				'cursor'    => $lane['c'],
				'pairs'     => $this->state['pairs'],
				'max_bytes' => $max_bytes,
				'budget'    => 10,
			);
		}
		$errors = array();
		$chunks = $this->call_many('src', 'export', $exports, $errors);

		$imports = array();
		foreach ($chunks as $n => $chunk) {
			if (! is_array($chunk)) {
				$errors[$n] = 'Empty export response for ' . $db['lanes'][$n]['t'];
				continue;
			}
			$imports[$n] = array(
				'job'           => $this->state['id'],
				'chunk'         => $chunk,
				'source_prefix' => $this->state['src']['prefix'],
			);
		}
		$imported = empty($imports) ? array() : $this->call_many('dst', 'import', $imports, $errors);

		// Advance every lane whose chunk arrived, even when another one failed.
		foreach (array_keys($imported) as $n) {
			$chunk = $chunks[$n];
			$db['rows'] += count((array) ($chunk['rows'] ?? array()));
			if (! empty($chunk['done'])) {
				$db['imported'][] = $db['lanes'][$n]['t'];
				unset($db['lanes'][$n]);
			} else {
				$db['lanes'][$n]['c'] = $chunk['cursor'] ?? null;
			}
		}
		$db['lanes'] = array_values($db['lanes']);
		unset($chunks, $imports, $imported);

		if (! empty($errors)) {
			throw new \RuntimeException((string) reset($errors));
		}
	}

	private function db_chunk_bytes(int $lanes): int {
		$wire = $this->remote_has('wire');
		$max = ($wire ? 4 : 3) * MB_IN_BYTES;
		if ('push' === $this->state['direction']) {
			$post_max = (int) ($this->state['dst']['post_max_size'] ?? 0);
			if ($post_max > 0) {
				$max = min($max, (int) ($post_max / ($wire ? 1.5 : 2)));
			}
		}
		// Decoded rows take several times their size in PHP memory, on both ends.
		foreach (array($this->state['src']['memory_limit'] ?? 0, $this->state['dst']['memory_limit'] ?? 0) as $mem) {
			if ((int) $mem > 0) {
				$max = min($max, (int) ($mem / 16));
			}
		}
		$local = self::local_memory();
		if ($local > 0) {
			$max = min($max, (int) ($local / (max(1, $lanes) * 16)));
		}
		return (int) max(256 * KB_IN_BYTES, $max);
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
		// Lazy comparison: the source only hashes files the destination has with the same size.
		$lazy = ! $override && $this->remote_has('fast');

		$page = (array) $this->call('src', 'manifest', array(
			'job'         => $this->state['id'],
			'component'   => $component,
			'offset'      => $f['offset'],
			'excludes'    => array_merge($this->state['excludes'], (array) ($this->state['component_excludes'][$component] ?? array())),
			'budget'      => 12,
			'hash'        => ! $override,
			'cached_only' => $lazy,
			'max_entries' => $lazy ? 6000 : 3000,
		));
		$entries = (array) ($page['entries'] ?? array());
		if (! empty($entries)) {
			// Override mode sends every file without asking the destination what it has.
			$changed = $override ? $entries : $this->changed_entries($component, $entries, $lazy);
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

	/**
	 * Entries that differ on the destination. In lazy mode the destination returns
	 * same-size files with its hash and the source hashes just those to compare.
	 */
	private function changed_entries(string $component, array $entries, bool $lazy): array {
		$diff = (array) $this->call('dst', 'diff', array('component' => $component, 'entries' => $entries, 'budget' => 12, 'lazy' => $lazy));
		$changed = (array) ($diff['changed'] ?? array());
		$verify = array();
		foreach ((array) ($diff['verify'] ?? array()) as $v) {
			$verify[(string) $v['p']] = $v;
		}
		while (! empty($verify)) {
			$hashes = (array) $this->call('src', 'hashes', array('component' => $component, 'paths' => array_map('strval', array_keys($verify)), 'budget' => 15));
			if (empty($hashes)) {
				break; // No progress: transfer the rest rather than risk skipping changes.
			}
			foreach ($hashes as $path => $hash) {
				$path = (string) $path;
				if (! isset($verify[$path])) {
					continue;
				}
				if ('' === (string) $hash || (string) $hash !== (string) $verify[$path]['h']) {
					$changed[] = array('p' => $path, 's' => (int) $verify[$path]['s']);
				}
				unset($verify[$path]);
			}
		}
		foreach ($verify as $path => $v) {
			$changed[] = array('p' => (string) $path, 's' => (int) $v['s']);
		}
		return $changed;
	}

	/**
	 * Sends the next round of file data: up to `par` batches read from the
	 * source and written to the destination in parallel. A file larger than a
	 * batch is split into chunks; its last chunk (which verifies the hash) is
	 * always sent in a later round than its other chunks.
	 */
	private function phase_transfer(): void {
		$f = &$this->state['files'];
		$queue = $this->queue_file();
		if (! file_exists($queue)) {
			throw new \RuntimeException('The file transfer queue is missing. Cancel and start the migration again.');
		}

		$limit = $this->transfer_limit();
		$par = $this->parallel();
		$max_files = 400;
		$min_chunk = 64 * KB_IN_BYTES;
		// Older destinations truncate a file when its first chunk arrives, so that
		// chunk must be written before any other chunk of the file is sent.
		$parallel_chunks = ! $this->is_remote('dst') || $this->remote_has('fast');

		$fh = fopen($queue, 'rb');
		fseek($fh, (int) $f['qpos']);
		$pos = (int) $f['qpos'];
		$seg = (int) $f['seg_offset'];
		$batches = array();
		$new_batch = static function (): array {
			return array('reqs' => array(), 'bytes' => 0, 'files' => 0, 'final' => array(), 'pos' => 0, 'seg' => 0);
		};
		$cur = $new_batch();
		$split = array(); // Files chunked in this round.
		$entry = null;
		$line_end = $pos;
		while (count($batches) < $par) {
			if (null === $entry) {
				$line = fgets($fh);
				if (false === $line) {
					break;
				}
				$line_end = ftell($fh);
				$entry = json_decode(trim($line), true);
				if (! is_array($entry)) {
					$entry = null;
					$pos = $line_end;
					$seg = 0;
					$cur['pos'] = $pos;
					$cur['seg'] = 0;
					continue;
				}
			}
			$key = $entry['c'] . '/' . $entry['p'];
			$remaining = max(0, (int) $entry['s'] - $seg);
			$space = $limit - $cur['bytes'];
			if ($remaining <= $space) {
				if ($seg > 0 && isset($split[$key])) {
					break; // The last chunk waits until the earlier ones are written.
				}
				$cur['reqs'][] = array('c' => $entry['c'], 'p' => $entry['p'], 'o' => $seg, 'l' => $remaining);
				$cur['final'][$key] = true;
				$cur['bytes'] += $remaining;
				$cur['files']++;
				$entry = null;
				$pos = $line_end;
				$seg = 0;
				$cur['pos'] = $pos;
				$cur['seg'] = 0;
				if (count($cur['reqs']) >= $max_files) {
					$batches[] = $cur;
					$cur = $new_batch();
				}
				continue;
			}
			if ($space < $min_chunk && ! empty($cur['reqs'])) {
				$batches[] = $cur;
				$cur = $new_batch();
				continue;
			}
			$first_chunk = 0 === $seg;
			$cur['reqs'][] = array('c' => $entry['c'], 'p' => $entry['p'], 'o' => $seg, 'l' => $space);
			$cur['bytes'] += $space;
			$seg += $space;
			$split[$key] = true;
			$cur['pos'] = $pos; // Start of this file's line: it continues from $seg.
			$cur['seg'] = $seg;
			$batches[] = $cur;
			$cur = $new_batch();
			if ($first_chunk && ! $parallel_chunks) {
				break;
			}
		}
		fclose($fh);
		if (! empty($cur['reqs'])) {
			$batches[] = $cur;
		}

		if (empty($batches)) {
			$f['qpos'] = $pos;
			$f['seg_offset'] = 0;
			$this->log(sprintf('Transferred %s files (%s).', number_format_i18n($f['done_files']), size_format($f['done_bytes'])));
			$this->next_phase('finalize_files');
			return;
		}

		$this->state['message'] = sprintf('Transferring files… %s / %s', size_format($f['done_bytes']), size_format(max($f['bytes_total'], $f['done_bytes'])));

		$errors = array();
		$reads = array();
		foreach ($batches as $b => $batch) {
			$reads[$b] = array('requests' => $batch['reqs'], 'raw' => true);
		}
		$read = $this->call_many('src', 'read', $reads, $errors);

		$writes = array();
		$sent = array();
		foreach ($read as $b => $segments) {
			$write = array();
			foreach ((array) $segments as $s) {
				if (! empty($s['missing'])) {
					$f['skipped'][] = $s['c'] . '/' . $s['p'];
					$this->log('Skipped (no longer exists on source): ' . $s['c'] . '/' . $s['p']);
					continue;
				}
				if (! isset($s['raw'])) {
					// Older source sites send base64.
					$s['raw'] = (string) base64_decode((string) ($s['d'] ?? ''));
					unset($s['d']);
				}
				$write[] = $s;
			}
			$sent[$b] = $write;
			if (! empty($write)) {
				$writes[$b] = array('job' => $this->state['id'], 'segments' => $write);
			}
		}
		unset($read);
		$written = empty($writes) ? array() : $this->call_many('dst', 'write', $writes, $errors);

		// Advance through the batches in order, up to the first one that failed.
		foreach ($batches as $b => $batch) {
			if (isset($errors[$b]) || ! isset($sent[$b])) {
				break;
			}
			$result = (array) ($written[$b] ?? array('failed' => array(), 'bytes' => 0));
			$requeue = array();
			foreach ((array) ($result['failed'] ?? array()) as $failed) {
				$requeue[$failed['c'] . '/' . $failed['p']] = 'File changed during copy, retrying: ';
			}
			foreach ($sent[$b] as $s) {
				$key = $s['c'] . '/' . $s['p'];
				if (empty($s['eof']) && isset($batch['final'][$key])) {
					$requeue[$key] = 'File grew during copy, retrying: ';
				}
			}
			$sizes = array();
			foreach ($sent[$b] as $s) {
				$sizes[$s['c'] . '/' . $s['p']] = array($s['c'], $s['p'], (int) ($s['s'] ?? 0));
			}
			foreach ($requeue as $key => $why) {
				$tries = (int) ($f['retries'][$key] ?? 0);
				if ($tries < 2 && isset($sizes[$key])) {
					$f['retries'][$key] = $tries + 1;
					list($c, $path, $size) = $sizes[$key];
					file_put_contents($queue, wp_json_encode(array('c' => $c, 'p' => $path, 's' => $size)) . "\n", FILE_APPEND | LOCK_EX);
					$this->log($why . $key);
				} else {
					$f['skipped'][] = $key;
					$this->state['warnings'][] = 'Skipped a file that kept changing during transfer: ' . $key;
				}
			}
			$f['done_bytes'] += (int) ($result['bytes'] ?? 0);
			$f['done_files'] += (int) $batch['files'];
			$f['qpos'] = (int) $batch['pos'];
			$f['seg_offset'] = (int) $batch['seg'];
		}

		if (! empty($errors)) {
			throw new \RuntimeException((string) reset($errors));
		}
	}

	/**
	 * Bytes of file data per request, within both servers' upload and memory limits.
	 */
	private function transfer_limit(): int {
		$wire = $this->remote_has('wire');
		$limit = ($wire ? 8 : 4) * MB_IN_BYTES;
		if ('push' === $this->state['direction']) {
			$post_max = (int) ($this->state['dst']['post_max_size'] ?? 0);
			if ($post_max > 0) {
				$limit = min($limit, (int) ($post_max / ($wire ? 1.3 : 2.5)));
			}
		}
		foreach (array($this->state['src']['memory_limit'] ?? 0, $this->state['dst']['memory_limit'] ?? 0) as $mem) {
			if ((int) $mem > 0) {
				$limit = min($limit, (int) ($mem / 10));
			}
		}
		$local = self::local_memory();
		if ($local > 0) {
			// This site holds every parallel batch in memory at once.
			$limit = min($limit, (int) ($local / ($this->parallel() * 5)));
		}
		return (int) max(256 * KB_IN_BYTES, $limit);
	}

	private function parallel(): int {
		return max(1, min(8, (int) ($this->state['par'] ?? self::DEFAULT_PARALLEL)));
	}

	private static function local_memory(): int {
		return (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
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
			if ('pull' === $this->state['direction'] && ! empty($this->state['sites_snapshot'])) {
				update_option('wudt_migration_sites', $this->state['sites_snapshot'], false);
			}
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
		if (! $this->is_remote($side)) {
			return $this->local_api()->dispatch($action, $params);
		}
		$result = $this->client()->call($action, $params, 120);
		$this->sync_transport();
		return $result;
	}

	/**
	 * Call one action with several parameter sets; remote calls run in parallel.
	 * Failures are collected in $errors (same keys) instead of thrown, so the
	 * caller can keep the work that did succeed.
	 */
	private function call_many(string $side, string $action, array $list, array &$errors): array {
		$out = array();
		if (! $this->is_remote($side)) {
			foreach ($list as $k => $params) {
				try {
					$out[$k] = $this->local_api()->dispatch($action, $params);
				} catch (\Throwable $e) {
					$errors[$k] = $e->getMessage();
				}
			}
			return $out;
		}
		$failed = array();
		$out = $this->client()->call_multi($action, $list, 120, $failed);
		$this->sync_transport();
		if (! empty($failed)) {
			$errors += $failed;
			if (count($list) > 1 && $this->parallel() > 1) {
				// The server may be refusing parallel requests: use fewer from now on.
				$this->state['par'] = max(1, intdiv($this->parallel(), 2));
				$this->log(sprintf('Request failed; reducing parallel requests to %d.', $this->state['par']));
			}
		}
		return $out;
	}

	private function is_remote(string $side): bool {
		return ('pull' === $this->state['direction']) ? ('src' === $side) : ('dst' === $side);
	}

	private function remote_has(string $feature): bool {
		return in_array($feature, (array) ($this->state['remote_features'] ?? array()), true);
	}

	private function local_api(): Migration_API {
		if (null === $this->local) {
			$this->local = new Migration_API();
		}
		return $this->local;
	}

	private function client(): Remote_Client {
		if (null === $this->client) {
			$this->client = new Remote_Client($this->state['site']['url'], $this->state['key'], $this->state['transport']);
			$this->client->set_wire($this->remote_has('wire'), $this->remote_has('solid'));
		}
		return $this->client;
	}

	private function sync_transport(): void {
		if ($this->client->get_transport() !== $this->state['transport']) {
			$this->state['transport'] = $this->client->get_transport();
		}
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
