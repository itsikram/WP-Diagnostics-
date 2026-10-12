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
	// Seconds between progress saves inside a step.
	private const PROGRESS_EVERY = 0.4;
	// Seconds between progress reports to the other site.
	private const REPORT_EVERY = 3.0;
	// Seconds a step may spend in remote calls before stopping, well under the
	// 300 s time limit raise_limits() sets, so a slow call never kills the request.
	private const CALL_WINDOW = 200;
	// Smallest upload per request when the connection is slow.
	private const MIN_CHUNK = 256 * KB_IN_BYTES;
	// Most files per transfer request: small files share one request, so the other
	// site loads WordPress once for many of them.
	private const BATCH_FILES = 2000;

	private array $state;
	private float $started;
	private float $last_save = 0.0;
	// Set when a stage starts, so its first message is saved right away.
	private bool $new_stage = true;
	private float $last_report = 0.0;
	private ?Remote_Client $client = null;
	private ?Migration_API $local = null;
	// Seconds this step may run (set by step()).
	private float $budget = 20.0;
	// While files stream, progress reports to the other site join the stream instead of blocking it.
	private bool $streaming = false;
	private ?array $pending_report = null;

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
		// Specific files and folders: a files-only job limited to the selected paths.
		$picked = self::normalize_files((array) ($options['files'] ?? array()));
		$only = array();
		if (! empty($picked)) {
			$options['tables'] = array();
			$options['pt_filter'] = 'all';
			$options['excludes'] = array();
			$options['exclude_dev'] = false;
			$whole = array();
			foreach ($picked as $item) {
				foreach (Migration_Engine::expand_selection($item['c'], $item['p']) as $part) {
					if ('' === $part['p']) {
						$whole[$part['c']] = true;
					} else {
						$only[$part['c']][] = $part['p'];
					}
				}
			}
			foreach (array_keys($whole) as $c) {
				unset($only[$c]);
			}
			$components = array_values(array_intersect(Migration_Engine::COMPONENTS, array_merge(array_keys($whole), array_keys($only))));
			foreach ($only as $c => $paths) {
				$only[$c] = array_values(array_unique($paths));
			}
		}
		$src_tables = array();
		foreach ((array) ($src['tables'] ?? array()) as $t) {
			$src_tables[$t['name']] = (int) $t['rows'];
		}
		$db_mode = in_array(($options['db_mode'] ?? ''), array('replace', 'replace_content', 'merge', 'post_types'), true)
			? (string) $options['db_mode']
			: 'replace';
		$merge = 'merge' === $db_mode;
		$replace_content = 'replace_content' === $db_mode;
		$by_type = 'post_types' === $db_mode;
		$wanted = (array) ($options['tables'] ?? array());
		$merge_groups = array_values(array_intersect(Migration_Engine::MERGE_GROUPS, (array) ($options['merge_groups'] ?? array())));
		$post_types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($options['post_types'] ?? array())))));
		$post_type_sync = 'add' === ($options['post_type_sync'] ?? '') ? 'add' : 'replace';
		if ($by_type) {
			if (! empty($wanted) && empty($post_types)) {
				throw new \RuntimeException('Select at least one post type to migrate.');
			}
			// Posts and the terms they use always come along; comments and new authors are optional.
			$merge_groups = array_values(array_unique(array_merge(array('posts', 'terms'), array_intersect(array('comments', 'users'), $merge_groups))));
			$wanted = empty($wanted) ? array() : array_map(static function ($suffix) use ($src) {
				return (string) ($src['prefix'] ?? '') . $suffix;
			}, Migration_Engine::MERGE_TABLES);
		} elseif ($merge) {
			// Adding content uses core content tables, never site settings or plugin tables.
			$wanted = empty($wanted) || empty($merge_groups) ? array() : array_map(static function ($suffix) use ($src) {
				return (string) ($src['prefix'] ?? '') . $suffix;
			}, Migration_Engine::MERGE_TABLES);
		} elseif ($replace_content) {
			// Replace only the coordinated WordPress content tables; preserve settings and plugin tables.
			$wanted = empty($wanted) ? array() : array_map(static function ($suffix) use ($src) {
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

		$token = wp_generate_password(40, false, false);
		$component_excludes = self::plugin_theme_excludes($src, $pt_filter, $pt_selected);
		$repo_items = empty($picked) && ! empty($options['repo'])
			? self::repo_candidates($src, $dst, $components, $component_excludes, ! empty($options['override']))
			: array();
		$live_media = null;
		if ($is_pull && ! empty($options['live_media']) && '' !== (string) ($src['uploads_url'] ?? '')) {
			$live_media = array(
				'url'  => untrailingslashit((string) $src['uploads_url']),
				'mode' => 'all' === ($options['live_media_mode'] ?? '') ? 'all' : 'missing',
			);
			$components = array_values(array_diff($components, array('uploads')));
		}

		$state = array(
			'id'         => $id,
			'token'      => $token,
			// Live progress for the browser, readable without loading WordPress.
			'progress'   => self::progress_location($token),
			// Keep the replaced files and tables so the migration can be rolled back.
			'keep_backup' => ! array_key_exists('keep_backup', $options) || ! empty($options['keep_backup']),
			'phase_started' => time(),
			'created'    => time(),
			'updated'    => time(),
			'status'     => 'running',
			'phase'      => empty($tables) ? 'scan' : 'database',
			'message'    => empty($tables) ? 'Preparing to compare files…' : 'Preparing to copy the database…',
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
			'picked'     => $picked,
			// Component => relative paths to copy; a component missing here is copied whole.
			'only'       => $only,
			'sites_snapshot' => (array) ($options['sites_snapshot'] ?? array()),
			'override'   => ! empty($options['override']),
			// Override replaces everything, so tables are never skipped as "unchanged";
			// a merge needs every content table imported to map IDs.
			'skip_unchanged' => ! $merge && ! $by_type && empty($options['override']) && ! empty($options['skip_unchanged']),
			'custom_excludes' => array_values(array_unique(array_filter(array_map('trim', (array) ($options['excludes'] ?? array()))))),
			'excludes'   => array_values(array_unique(array_merge(
				array_filter(array_map('trim', (array) ($options['excludes'] ?? array()))),
				! empty($options['exclude_dev']) ? Migration_Engine::DEV_EXCLUDES : array()
			))),
			'exclude_dev' => ! empty($options['exclude_dev']),
			'db_mode'    => $db_mode,
			'merge_groups' => $merge_groups,
			'post_types' => $by_type ? $post_types : array(),
			'post_type_sync' => $post_type_sync,
			'pt_filter'  => $pt_filter,
			'pt_selected' => 'selected' === $pt_filter ? $pt_selected : array(),
			'component_excludes' => $component_excludes,
			// Plugins and themes the destination may install from WordPress.org.
			'repo'       => array(
				'enabled'    => ! empty($repo_items),
				'items'      => $repo_items,
				'offset'     => 0,
				'checked'    => false,
				'installed'  => 0,
				'bytes'      => 0,
				'requeued'   => false,
			),
			// Pull only: this site loads media from the source site instead of copying uploads.
			'live_media' => $live_media,
			// The schedule that started this job ({id, name}); it then runs in the background.
			'scheduled'  => self::scheduled_ref((array) ($options['scheduled'] ?? array())),
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
			$pt_names = array();
			foreach (array('plugins', 'themes') as $c) {
				if (in_array($c, $components, true)) {
					$pt_names[] = $c . ': ' . implode(', ', array_map(array(self::class, 'plugin_folder'), $pt_selected[$c]));
				}
			}
			$pt_note = ' (only ' . implode('; ', $pt_names) . ')';
		} elseif ('all' !== $pt_filter) {
			$pt_note = ' (' . $pt_filter . ' plugins & themes only)';
		}
		if (empty($state['keep_backup'])) {
			$runner->log('Backup turned off: replaced files and tables will not be kept for rollback.');
		}
		if (! empty($repo_items)) {
			$runner->log(sprintf('WordPress.org: %d plugins/themes will be checked; unmodified ones are installed from WordPress.org instead of being copied.', count($repo_items)));
		}
		if (null !== $live_media) {
			$runner->log('Media uploads are not copied: this site will load media from ' . $live_media['url'] . '.');
		}
		if (! empty($picked)) {
			$runner->log(sprintf(
				'Selected %d specific file%s/folder%s: %s',
				count($picked),
				1 === count($picked) ? '' : 's',
				1 === count($picked) ? '' : 's',
				implode(', ', array_map(static function ($item) {
					return $item['c'] . ('' === $item['p'] ? '' : '/' . $item['p']);
				}, array_slice($picked, 0, 10))) . (count($picked) > 10 ? ' …' : '')
			));
		}
		$runner->log(sprintf(
			'Selected — database: %s; files: %s.',
			empty($tables) ? 'not included' : (
				$merge ? 'add as new content (' . implode(', ', $merge_groups) . ')'
					: ($by_type ? $post_type_sync . ' post types ' . implode(', ', $post_types) . ' (with ' . implode(', ', $merge_groups) . ')'
					: ($replace_content ? 'replace selected WordPress content tables' : count($tables) . ' tables'))
			),
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
	 * Plugins and themes that could come from WordPress.org: included in the job,
	 * in their own folder (named like their WordPress.org slug) and different from
	 * the destination's version (or every one, with override).
	 *
	 * @return array<int,array{c:string,slug:string,version:string,status:string}>
	 */
	private static function repo_candidates(array $src, array $dst, array $components, array $component_excludes, bool $override): array {
		$out = array();
		if (in_array('plugins', $components, true)) {
			$dst_plugins = (array) ($dst['plugins'] ?? array());
			$seen = array();
			foreach ((array) ($src['plugins'] ?? array()) as $file => $version) {
				$dir = dirname((string) $file);
				if ('.' === $dir || isset($seen[$dir]) || $dir === (string) ($src['self_plugin_dir'] ?? '') || in_array($dir, (array) ($component_excludes['plugins'] ?? array()), true)) {
					continue;
				}
				$seen[$dir] = true;
				if ('' === (string) $version || (! $override && isset($dst_plugins[$file]) && (string) $dst_plugins[$file] === (string) $version)) {
					continue; // Same version on both sides: the normal comparison sends only what differs.
				}
				$out[] = array('c' => 'plugins', 'slug' => $dir, 'version' => (string) $version, 'status' => 'pending');
			}
		}
		if (in_array('themes', $components, true)) {
			$dst_themes = (array) ($dst['themes'] ?? array());
			foreach ((array) ($src['themes'] ?? array()) as $slug => $version) {
				$slug = (string) $slug;
				if ('' === (string) $version || in_array($slug, (array) ($component_excludes['themes'] ?? array()), true)) {
					continue;
				}
				if (! $override && isset($dst_themes[$slug]) && (string) $dst_themes[$slug] === (string) $version) {
					continue;
				}
				$out[] = array('c' => 'themes', 'slug' => $slug, 'version' => (string) $version, 'status' => 'pending');
			}
		}
		return $out;
	}

	/**
	 * Clean a list of picked paths: [{c: component, p: relative path}], without duplicates.
	 *
	 * @return array<int,array{c:string,p:string}>
	 */
	public static function normalize_files(array $files): array {
		$out = array();
		foreach ($files as $item) {
			if (! is_array($item)) {
				continue;
			}
			$c = (string) ($item['c'] ?? '');
			$p = trim(str_replace('\\', '/', (string) ($item['p'] ?? '')), '/');
			if (! in_array($c, Migration_Engine::COMPONENTS, true) || str_contains($p, "\0") || preg_match('#(^|/)\.\.(/|$)#', $p) || preg_match('#^[a-zA-Z]:#', $p)) {
				continue;
			}
			$out[$c . '|' . $p] = array('c' => $c, 'p' => $p);
		}
		return array_values($out);
	}

	/**
	 * Folder of a plugin ("akismet/akismet.php" → "akismet"), or the file itself for single-file plugins.
	 */
	private static function plugin_folder(string $file): string {
		$dir = dirname($file);
		return '.' === $dir ? $file : $dir;
	}

	private static function scheduled_ref(array $scheduled): ?array {
		if (empty($scheduled['id'])) {
			return null;
		}
		return array('id' => (string) $scheduled['id'], 'name' => (string) ($scheduled['name'] ?? ''));
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
		$this->state['seq'] = (int) ($this->state['seq'] ?? 0) + 1;
		$this->state['percent'] = $this->compute_percent();
		file_put_contents(self::job_file($this->state['id']), wp_json_encode($this->state), LOCK_EX);
		$this->write_progress();
		$this->last_save = microtime(true);
		$this->report_to_peer('running' !== $this->state['status']);
	}

	/**
	 * Tell the other site how the migration is going, so its Site Migration page
	 * can show it too. Best effort: a failed report never stops the migration.
	 */
	private function report_to_peer(bool $force): void {
		if (! $this->remote_has('progress') || (! $force && microtime(true) - $this->last_report < self::REPORT_EVERY)) {
			return;
		}
		// After a failed report, pause reports for a minute instead of adding a slow request every few seconds.
		if (time() < (int) ($this->state['report_off_until'] ?? 0)) {
			return;
		}
		$this->last_report = microtime(true);
		$s = $this->summary();
		$report = array(
			'id'          => $s['id'],
			'from'        => untrailingslashit(home_url()),
			'from_label'  => get_bloginfo('name'),
			'direction'   => $s['direction'],
			'status'      => $s['status'],
			'phase'       => $s['phase'],
			'message'     => $s['message'],
			'error'       => $s['error'],
			'percent'     => $s['percent'],
			'tables'      => $s['tables'],
			'components'  => $s['components'],
			'files_count' => $s['files_count'],
			'stats'       => $s['stats'],
			'log'         => array_slice($s['log'], -20),
		);
		if ($this->streaming) {
			$this->pending_report = $report;
			return;
		}
		try {
			$this->client()->call('progress', $report, 5, 1);
		} catch (\Throwable $e) {
			// Not worth slowing the migration down.
			$this->state['report_off_until'] = time() + 60;
		}
	}

	/**
	 * Show a new status message, saving it right away (at most a few times a second)
	 * so the browser sees it while the step is still running.
	 */
	private function progress(string $message, bool $force = false): void {
		$this->state['message'] = $message;
		if ($force || $this->new_stage || microtime(true) - $this->last_save >= self::PROGRESS_EVERY) {
			$this->new_stage = false;
			$this->save();
		}
	}

	/**
	 * Where the live progress of a job is published. The name is derived from the
	 * job's secret token, so only the browser that started the job knows it.
	 *
	 * @return array{file:string,url:string}
	 */
	private static function progress_location(string $token): array {
		$dir = wp_normalize_path(WP_CONTENT_DIR) . '/wudt-progress';
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		if (! file_exists($dir . '/index.php')) {
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		}
		$name = substr(hash('sha256', 'wudt-progress|' . $token), 0, 40) . '.json';
		return array('file' => $dir . '/' . $name, 'url' => content_url('/wudt-progress/' . $name));
	}

	private function write_progress(): void {
		$file = (string) ($this->state['progress']['file'] ?? '');
		if ('' === $file || ! is_dir(dirname($file))) {
			return;
		}
		$summary = $this->summary();
		$summary['now'] = time();
		$tmp = $file . '.' . getmypid() . '.tmp';
		// Write then rename, so a reader never sees a half-written file.
		if (false !== @file_put_contents($tmp, wp_json_encode($summary)) && ! @rename($tmp, $file)) {
			@file_put_contents($file, wp_json_encode($summary));
			@unlink($tmp);
		}
	}

	private static function delete_progress(array $state): void {
		$file = (string) ($state['progress']['file'] ?? '');
		if ('' !== $file && 0 === strpos(wp_normalize_path($file), wp_normalize_path(WP_CONTENT_DIR) . '/wudt-progress/')) {
			@unlink($file);
		}
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
			'post_types'       => $s['post_types'] ?? array(),
			'post_type_sync'   => $s['post_type_sync'] ?? 'replace',
			'pt_filter'        => $s['pt_filter'] ?? 'all',
			'pt_selected'      => $s['pt_selected'] ?? array(),
			'files'            => array_slice((array) ($s['picked'] ?? array()), 0, 200),
			'files_count'      => count((array) ($s['picked'] ?? array())),
			'keep_backup'      => ! isset($s['keep_backup']) || ! empty($s['keep_backup']),
			'repo'             => self::repo_summary($s),
			'live_media'       => $s['live_media'] ?? null,
			'scheduled'        => $s['scheduled'] ?? null,
			'progress_url'     => (string) ($s['progress']['url'] ?? ''),
			'phase_started'    => (int) ($s['phase_started'] ?? $s['created']),
			'seq'              => (int) ($s['seq'] ?? 0),
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

	private static function repo_summary(array $s): array {
		$repo = (array) ($s['repo'] ?? array());
		if (empty($repo['enabled'])) {
			return array('enabled' => false);
		}
		$count = array('verified' => 0, 'installed' => 0, 'failed' => 0, 'skip' => 0, 'pending' => 0);
		$names = array();
		foreach ((array) ($repo['items'] ?? array()) as $item) {
			$status = (string) ($item['status'] ?? 'pending');
			$count[$status] = ($count[$status] ?? 0) + 1;
			if ('installed' === $status) {
				$names[] = (string) $item['slug'];
			}
		}
		return array(
			'enabled'   => true,
			'checked'   => ! empty($repo['checked']),
			'total'     => count((array) ($repo['items'] ?? array())),
			'eligible'  => $count['verified'] + $count['installed'] + $count['failed'],
			'installed' => $count['installed'],
			'failed'    => $count['failed'],
			'bytes'     => (int) ($repo['bytes'] ?? 0),
			'names'     => array_slice($names, 0, 30),
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
				$runner = self::load($old['id']);
				if ($runner) {
					self::delete_progress($runner->state);
				}
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
		if ($runner) {
			self::delete_progress($runner->state);
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
		$this->budget = $budget;
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
		if ('finalize_db' === $this->state['phase'] && in_array($this->state['db_mode'] ?? '', array('merge', 'post_types'), true)) {
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
			case 'packages':
				$this->phase_packages();
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
		$this->state['phase_started'] = time();
		$this->new_stage = true;
		$this->state['message'] = 'Starting the next stage…';
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
			$this->progress('Preparing the destination database…');
			$this->call('dst', 'cleanup', array('job' => $this->state['id']));
			if ($this->state['skip_unchanged'] && ! empty($db['queue'])) {
				$this->progress(sprintf('Comparing %d tables to skip the ones already identical…', count($db['queue'])));
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
		$this->progress(sprintf(
			'Copying table%s %s (%d of %d done, %s rows)…',
			count($names) > 1 ? 's' : '',
			implode(', ', $names),
			count($db['imported']),
			count($db['queue']),
			number_format_i18n($db['rows'])
		));

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
		if (! empty($this->state['repo']['enabled']) && empty($this->state['repo']['checked'])) {
			$this->repo_check_step();
			return;
		}
		if ($f['ci'] >= count($components)) {
			$this->log(sprintf('Scan complete: %s of %s files changed (%s).', number_format_i18n($f['changed']), number_format_i18n($f['scanned']), size_format($f['bytes_total'])));
			$this->next_phase($f['changed'] > 0 ? 'transfer' : $this->after_transfer());
			return;
		}
		$component = (string) $components[$f['ci']];
		if (0 === $f['offset'] && 0 === $f['ci'] && file_exists($this->queue_file())) {
			unlink($this->queue_file());
		}
		$override = ! empty($this->state['override']);
		$this->progress($override
			? sprintf('Listing %s files (override: no comparison)… %s found', $component, number_format_i18n($f['scanned']))
			: (0 === $f['offset']
				? sprintf('Listing %s files on the source site…', $component)
				: sprintf('Comparing %s files… (%s checked, %s changed)', $component, number_format_i18n($f['scanned']), number_format_i18n($f['changed']))));
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
		) + (isset($this->state['only'][$component]) ? array('only' => $this->state['only'][$component]) : array()));
		foreach ((array) ($page['skipped'] ?? array()) as $missing) {
			$this->state['warnings'][] = sprintf('Skipped %s/%s: it does not exist on the source site or cannot be migrated.', $component, (string) $missing);
		}
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
		$this->progress(sprintf('Comparing %s %s files with the destination… (%s checked so far)', number_format_i18n(count($entries)), $component, number_format_i18n($this->state['files']['scanned'])));
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
		$lanes = $this->parallel();
		$pull = 'pull' === $this->state['direction'];
		$min_chunk = 64 * KB_IN_BYTES;
		// Older destinations truncate a file when its first chunk arrives, so that
		// chunk must be written before any other chunk of the file is sent.
		$parallel_chunks = ! $this->is_remote('dst') || $this->remote_has('fast');

		$fh = fopen($queue, 'rb');
		$reader = array(
			'pos'      => (int) $f['qpos'],
			'seg'      => (int) $f['seg_offset'],
			'line_end' => (int) $f['qpos'],
			'entry'    => null,
			'eof'      => false,
		);
		fseek($fh, $reader['pos']);

		// Unfinished chunks of split files (file key => count): the last chunk of a
		// file waits for them, because it checks the whole file on the destination.
		$pending = array();

		/*
		 * The next batch from the queue: whole files up to the size limit, or one
		 * chunk of a large file filling the rest of the batch. Null when nothing can
		 * be sent now (the queue is done or a last chunk waits for earlier chunks).
		 */
		$build = static function () use ($fh, &$reader, &$pending, $limit, $min_chunk, $parallel_chunks): ?array {
			$batch = array('reqs' => array(), 'bytes' => 0, 'files' => 0, 'final' => array(), 'chunks' => array(), 'pos' => $reader['pos'], 'seg' => $reader['seg']);
			while (true) {
				if (null === $reader['entry']) {
					$line = fgets($fh);
					if (false === $line) {
						$reader['eof'] = true;
						break;
					}
					$reader['line_end'] = ftell($fh);
					$entry = json_decode(trim($line), true);
					if (! is_array($entry)) {
						$reader['pos'] = $reader['line_end'];
						$reader['seg'] = 0;
						continue;
					}
					$reader['entry'] = $entry;
				}
				$entry = $reader['entry'];
				$key = $entry['c'] . '/' . $entry['p'];
				$seg = (int) $reader['seg'];
				$remaining = max(0, (int) $entry['s'] - $seg);
				$space = $limit - $batch['bytes'];
				if ($remaining <= $space) {
					if ($seg > 0 && ! empty($pending[$key])) {
						break; // The last chunk waits until the earlier ones are written.
					}
					$batch['reqs'][] = array('c' => $entry['c'], 'p' => $entry['p'], 'o' => $seg, 'l' => $remaining);
					$batch['final'][$key] = true;
					$batch['bytes'] += $remaining;
					$batch['files']++;
					$reader['entry'] = null;
					$reader['pos'] = $reader['line_end'];
					$reader['seg'] = 0;
					$batch['pos'] = $reader['pos'];
					$batch['seg'] = 0;
					if (count($batch['reqs']) >= self::BATCH_FILES) {
						break;
					}
					continue;
				}
				if ($space < $min_chunk && ! empty($batch['reqs'])) {
					break;
				}
				if ($seg > 0 && ! $parallel_chunks && ! empty($pending[$key])) {
					break;
				}
				$batch['reqs'][] = array('c' => $entry['c'], 'p' => $entry['p'], 'o' => $seg, 'l' => $space);
				$batch['bytes'] += $space;
				$batch['chunks'][] = $key;
				$reader['seg'] = $seg + $space;
				// Resume from the start of this file's line, continuing at the next chunk.
				$batch['pos'] = $reader['pos'];
				$batch['seg'] = $reader['seg'];
				break;
			}
			if (empty($batch['reqs'])) {
				return null;
			}
			foreach ($batch['chunks'] as $key) {
				$pending[$key] = ($pending[$key] ?? 0) + 1;
			}
			return $batch;
		};

		$batches = array();   // Sent and not yet counted, by sequence number.
		$results = array();   // Finished batches waiting for the ones before them.
		$errors = array();
		$timed_out = false;
		$seq = 0;
		$counted = 0;
		$stop = false;
		$deadline = $this->started + $this->budget;

		// Count finished batches in queue order, so a resumed job never skips a file.
		$commit = function () use (&$f, &$batches, &$results, &$counted, $queue): void {
			while (isset($results[$counted])) {
				$batch = $batches[$counted];
				$result = $results[$counted];
				unset($batches[$counted], $results[$counted]);
				$counted++;

				$requeue = array();
				foreach ((array) ($result['failed'] ?? array()) as $failed) {
					$requeue[$failed['c'] . '/' . $failed['p']] = 'File changed during copy, retrying: ';
				}
				$sizes = array();
				foreach ($batch['sent'] as $s) {
					$key = $s['c'] . '/' . $s['p'];
					if (empty($s['eof']) && isset($batch['final'][$key])) {
						$requeue[$key] = 'File grew during copy, retrying: ';
					}
					$sizes[$key] = array($s['c'], $s['p'], (int) ($s['s'] ?? 0));
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
			$this->progress(sprintf('Transferring files… %s / %s (%s of %s files)', size_format($f['done_bytes']), size_format(max($f['bytes_total'], $f['done_bytes'])), number_format_i18n($f['done_files']), number_format_i18n($f['changed'])));
		};

		// Segments read from the source, without files that disappeared meanwhile.
		$usable = function (array $segments) use (&$f): array {
			$out = array();
			foreach ($segments as $s) {
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
				$out[] = $s;
			}
			return $out;
		};

		$release = static function (array $batch) use (&$pending): void {
			foreach ($batch['chunks'] as $key) {
				if (--$pending[$key] <= 0) {
					unset($pending[$key]);
				}
			}
		};

		$next = function () use (&$batches, &$results, &$errors, &$seq, &$stop, $deadline, $build, $usable, $release, $pull, $commit): ?array {
			if (null !== $this->pending_report) {
				$report = $this->pending_report;
				$this->pending_report = null;
				return array('report', 'progress', $report, array('timeout' => 5, 'retry' => false));
			}
			// At least one batch per call, then only while the step has time left.
			while (! $stop && (0 === $seq || microtime(true) < $deadline)) {
				$batch = $build();
				if (null === $batch) {
					return null;
				}
				$id = $seq++;
				if ($pull) {
					$batches[$id] = $batch;
					return array($id, 'read', array('requests' => $batch['reqs'], 'raw' => true));
				}
				// Push: read from this site just before sending.
				$batches[$id] = $batch;
				try {
					$batch['sent'] = $usable((array) $this->local_api()->dispatch('read', array('requests' => $batch['reqs'], 'raw' => true)));
				} catch (\Throwable $e) {
					$release($batch);
					$errors[$id] = $e->getMessage();
					$stop = true;
					return null;
				}
				$batches[$id] = $batch;
				if (! empty($batch['sent'])) {
					return array($id, 'write', array('job' => $this->state['id'], 'segments' => $batch['sent']));
				}
				$release($batch);
				$results[$id] = array('bytes' => 0, 'failed' => array());
				$commit();
			}
			return null;
		};

		$done = function ($id, $data, ?string $error, bool $timeout) use (&$batches, &$results, &$errors, &$timed_out, &$stop, $usable, $release, $pull, $commit): void {
			if ('report' === $id) {
				if (null !== $error) {
					// Not worth slowing the migration down.
					$this->state['report_off_until'] = time() + 60;
				}
				return;
			}
			$batch = &$batches[$id];
			if (null === $error && $pull) {
				// Pull: write what the source sent into this site's staging area right away.
				$batch['sent'] = $usable((array) $data);
				try {
					$data = empty($batch['sent'])
						? array('bytes' => 0, 'failed' => array())
						: $this->local_api()->dispatch('write', array('job' => $this->state['id'], 'segments' => $batch['sent']));
				} catch (\Throwable $e) {
					$error = $e->getMessage();
				}
				foreach ($batch['sent'] as &$s) {
					unset($s['raw']); // Only the file details are needed from here on.
				}
				unset($s);
			} elseif (null === $error) {
				foreach ($batch['sent'] as &$s) {
					unset($s['raw']);
				}
				unset($s);
			}
			$release($batch);
			unset($batch);
			if (null !== $error) {
				$errors[$id] = $error;
				$timed_out = $timed_out || $timeout;
				$stop = true; // Finish what is running, start nothing new.
				return;
			}
			$results[$id] = (array) $data;
			$commit();
		};

		$this->progress(sprintf('Transferring files… %s / %s (%s of %s files)', size_format($f['done_bytes']), size_format(max($f['bytes_total'], $f['done_bytes'])), number_format_i18n($f['done_files']), number_format_i18n($f['changed'])), true);
		$this->streaming = true;
		try {
			$this->client()->call_stream($next, $done, $lanes, 120);
		} finally {
			$this->streaming = false;
			$this->pending_report = null; // Out of date by now; the next save reports again.
			fclose($fh);
		}
		$this->sync_transport();

		if (! empty($errors)) {
			ksort($errors);
			$error = (string) reset($errors);
			if ($timed_out || self::is_timeout($error)) {
				// Too much data per request for this connection: retry the rest with smaller requests.
				if ($this->shrink_transfer($limit)) {
					return;
				}
			} elseif ($lanes > 1) {
				// The server may be refusing parallel requests: use fewer from now on.
				$this->state['par'] = max(1, intdiv($lanes, 2));
				$this->log(sprintf('Request failed; reducing parallel requests to %d.', $this->state['par']));
			}
			throw new \RuntimeException($error);
		}
		clearstatcache(true, $queue);
		// Files queued again during this call (they changed while being copied) are still to send.
		if ($reader['eof'] && null === $reader['entry'] && $counted === $seq && (int) filesize($queue) <= $reader['pos']) {
			$f['qpos'] = $reader['pos'];
			$f['seg_offset'] = 0;
			$this->log(sprintf('Transferred %s files (%s).', number_format_i18n($f['done_files']), size_format($f['done_bytes'])));
			$this->next_phase($this->after_transfer());
			return;
		}
		// Faster again after slowing down: allow larger requests, step by step.
		if (! empty($f['chunk_cap'])) {
			$f['chunk_cap'] = (int) ($f['chunk_cap'] * 1.5);
		}
	}

	/* ------------------------------ WordPress.org ---------------------- */

	/**
	 * Ask the source which candidate plugins and themes are unmodified WordPress.org
	 * releases. Those are left out of the file copy and installed by the destination.
	 */
	private function repo_check_step(): void {
		$repo = &$this->state['repo'];
		$items = (array) $repo['items'];
		$this->progress(sprintf('Checking plugins and themes against WordPress.org… (%d of %d)', (int) $repo['offset'], count($items)));
		try {
			$res = (array) $this->call('src', 'repo_check', array(
				'items'    => array_map(static function ($item) {
					return array('c' => $item['c'], 'slug' => $item['slug'], 'version' => $item['version']);
				}, $items),
				'offset'   => (int) $repo['offset'],
				'excludes' => $this->state['excludes'],
				'budget'   => 15,
			));
		} catch (\Throwable $e) {
			// Not fatal: everything is copied as usual.
			$this->log('WordPress.org check failed, copying all plugins and themes instead: ' . $e->getMessage());
			$repo['enabled'] = false;
			$repo['checked'] = true;
			return;
		}
		foreach ((array) ($res['results'] ?? array()) as $result) {
			foreach ($repo['items'] as $k => $item) {
				if ($item['c'] === ($result['c'] ?? '') && $item['slug'] === ($result['slug'] ?? '') && 'pending' === $item['status']) {
					$repo['items'][$k]['status'] = ! empty($result['ok']) ? 'verified' : 'skip';
					$repo['items'][$k]['reason'] = (string) ($result['reason'] ?? '');
					$repo['items'][$k]['bytes'] = (int) ($result['bytes'] ?? 0);
					break;
				}
			}
		}
		$repo['offset'] = (int) ($res['next'] ?? count($items));
		if (empty($res['done']) && $repo['offset'] < count($items)) {
			return;
		}
		$repo['checked'] = true;
		$verified = array();
		$copied = array();
		foreach ($repo['items'] as $item) {
			if ('verified' === $item['status']) {
				// The destination installs these: leave them out of the file comparison.
				$this->state['component_excludes'][$item['c']][] = $item['slug'];
				$verified[] = $item['slug'] . ' ' . $item['version'];
			} else {
				$copied[] = $item['slug'] . ' (' . ($item['reason'] ?? '') . ')';
			}
		}
		$this->log(empty($verified)
			? 'WordPress.org: no unmodified WordPress.org plugins or themes to install; all are copied.'
			: sprintf('WordPress.org: %d will be installed from WordPress.org: %s.', count($verified), implode(', ', $verified)));
		if (! empty($copied)) {
			$this->log('Copied from the source instead: ' . implode('; ', array_slice($copied, 0, 20)) . (count($copied) > 20 ? ' …' : ''));
		}
	}

	/**
	 * Next stage after the file transfer: install WordPress.org packages if any are left.
	 */
	private function after_transfer(): string {
		foreach ((array) ($this->state['repo']['items'] ?? array()) as $item) {
			if ('verified' === ($item['status'] ?? '')) {
				return 'packages';
			}
		}
		return 'finalize_files';
	}

	/**
	 * The destination downloads verified plugins and themes from WordPress.org into
	 * its staging area. A package that cannot be installed is copied from the source.
	 */
	private function phase_packages(): void {
		$repo = &$this->state['repo'];
		$batch = array();
		foreach ($repo['items'] as $k => $item) {
			if ('verified' === $item['status']) {
				$batch[$k] = $item;
				if (count($batch) >= $this->parallel()) {
					break;
				}
			}
		}
		if (empty($batch)) {
			$failed = array_filter($repo['items'], static function ($item) {
				return 'failed' === $item['status'];
			});
			if (! empty($failed) && empty($repo['requeued'])) {
				$this->requeue_failed_packages($failed);
				return;
			}
			$this->log(sprintf('Installed %d plugins/themes from WordPress.org (%s not uploaded).', (int) $repo['installed'], size_format((int) $repo['bytes'])));
			$this->next_phase('finalize_files');
			return;
		}
		$this->progress(sprintf(
			'Installing from WordPress.org on the destination: %s… (%d of %d done)',
			implode(', ', array_column($batch, 'slug')),
			(int) $repo['installed'],
			count(array_filter($repo['items'], static function ($item) {
				return in_array($item['status'], array('verified', 'installed', 'failed'), true);
			}))
		), true);
		$calls = array();
		foreach ($batch as $k => $item) {
			$calls[$k] = array('job' => $this->state['id'], 'component' => $item['c'], 'slug' => $item['slug'], 'version' => $item['version']);
		}
		$errors = array();
		$done = $this->call_many('dst', 'install_package', $calls, $errors);
		foreach ($batch as $k => $item) {
			if (isset($done[$k])) {
				$repo['items'][$k]['status'] = 'installed';
				$repo['installed']++;
				$repo['bytes'] += (int) ($item['bytes'] ?? 0);
				$this->log(sprintf('Installed %s %s from WordPress.org (%d files).', $item['slug'], $item['version'], (int) ($done[$k]['files'] ?? 0)));
			} else {
				$repo['items'][$k]['status'] = 'failed';
				$repo['items'][$k]['reason'] = (string) ($errors[$k] ?? 'unknown error');
				$this->log(sprintf('Could not install %s from WordPress.org (%s); it will be copied from the source.', $item['slug'], $repo['items'][$k]['reason']));
			}
		}
	}

	/**
	 * Queue the files of packages that failed to install for a normal transfer.
	 */
	private function requeue_failed_packages(array $failed): void {
		$f = &$this->state['files'];
		foreach ($failed as $item) {
			$this->progress(sprintf('Copying %s from the source instead…', $item['slug']), true);
			$offset = 0;
			do {
				$page = (array) $this->call('src', 'manifest', array(
					'job'         => $this->state['id'] . 'r',
					'component'   => $item['c'],
					'offset'      => $offset,
					'excludes'    => $this->state['excludes'],
					'budget'      => 12,
					'hash'        => false,
					'max_entries' => 6000,
					'only'        => array($item['slug']),
				));
				$lines = '';
				foreach ((array) ($page['entries'] ?? array()) as $entry) {
					$lines .= wp_json_encode(array('c' => $item['c'], 'p' => $entry['p'], 's' => (int) $entry['s'])) . "\n";
					$f['changed']++;
					$f['bytes_total'] += (int) $entry['s'];
				}
				if ('' !== $lines) {
					file_put_contents($this->queue_file(), $lines, FILE_APPEND | LOCK_EX);
				}
				$offset = (int) ($page['next'] ?? 0);
			} while (empty($page['done']));
		}
		$this->state['repo']['requeued'] = true;
		$this->next_phase('transfer');
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
		// Lowered after uploads timed out (slow connection).
		if (! empty($this->state['files']['chunk_cap'])) {
			$limit = min($limit, (int) $this->state['files']['chunk_cap']);
		}
		return (int) max(self::MIN_CHUNK, $limit);
	}

	/**
	 * Requests timed out because they carry more data than the connection moves
	 * in time: send less per request from now on, instead of failing.
	 *
	 * @return bool False when already at the smallest size.
	 */
	private function shrink_transfer(int $current): bool {
		$f = &$this->state['files'];
		if ($current <= self::MIN_CHUNK && $this->parallel() <= 1) {
			return false;
		}
		$f['chunk_cap'] = max(self::MIN_CHUNK, intdiv($current, 2));
		$this->state['par'] = max(1, intdiv($this->parallel(), 2));
		$this->log(sprintf('The connection to the other site is slow: sending %s per request, %d at a time.', size_format($f['chunk_cap']), $this->parallel()));
		return true;
	}

	private static function is_timeout(string $error): bool {
		return (bool) preg_match('/timed out|timeout|did not answer in time/i', $error);
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
		$this->progress(! empty($this->state['keep_backup']) ? sprintf('Moving files into place and keeping the old ones for rollback… (%s done)', number_format_i18n($this->state['fin']['moved'])) : sprintf('Moving files into place… (%s done)', number_format_i18n($this->state['fin']['moved'])));
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
		if (! empty($imported) && empty($this->state['db']['finalized']) && 'post_types' === ($this->state['db_mode'] ?? '')) {
			$replace = 'add' !== ($this->state['post_type_sync'] ?? 'replace');
			$this->progress(($replace ? 'Replacing' : 'Adding') . ' posts of the selected post types…');
			$res = (array) $this->call('dst', 'merge_db', array(
				'job'           => $this->state['id'],
				'source_prefix' => $this->state['src']['prefix'],
				'source_key'    => substr(md5((string) $this->state['src']['home']), 0, 12),
				'groups'        => $this->state['merge_groups'],
				'post_types'    => $this->state['post_types'],
				'replace'       => $replace ? 1 : 0,
				'budget'        => 15,
			));
			$s = (array) ($res['stats'] ?? array());
			$this->state['message'] = sprintf(
				'%s posts of the selected post types… %s posts added%s',
				$replace ? 'Replacing' : 'Adding',
				number_format_i18n((int) ($s['posts'] ?? 0)),
				$replace ? ', ' . number_format_i18n((int) ($s['deleted'] ?? 0)) . ' replaced' : ''
			);
			if (empty($res['done'])) {
				return; // Continue in the next step.
			}
			$this->state['db']['finalized'] = true;
			$this->log(sprintf(
				'Post types %s: %d posts added%s, %d terms, %d comments, %d users added. Other content and settings were not changed.',
				implode(', ', $this->state['post_types']),
				(int) ($s['posts'] ?? 0),
				$replace ? sprintf(' (replacing %d existing)', (int) ($s['deleted'] ?? 0)) : sprintf(' (%d added earlier were skipped)', (int) ($s['skipped'] ?? 0)),
				(int) ($s['terms'] ?? 0),
				(int) ($s['comments'] ?? 0),
				(int) ($s['users'] ?? 0)
			));
		}
		if (! empty($imported) && empty($this->state['db']['finalized']) && 'merge' === ($this->state['db_mode'] ?? '')) {
			$this->progress('Adding content as new items…');
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
		if (empty($this->state['keep_backup']) && empty($this->state['backup_discarded'])) {
			$this->progress('Removing the replaced files and tables (backup turned off)…');
			try {
				$this->call('dst', 'discard_rollback', array());
				$this->log('Replaced files and tables were removed; this migration cannot be rolled back.');
			} catch (\Throwable $e) {
				$this->log('Could not remove the rollback copy: ' . $e->getMessage());
			}
			$this->state['backup_discarded'] = true;
		}
		$this->progress('Cleaning up temporary files…');
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

		if (! empty($this->state['live_media']) && 'pull' === $this->state['direction']) {
			Live_Media::enable((string) $this->state['live_media']['url'], (string) $this->state['live_media']['mode'], (string) $this->state['site']['label']);
			$this->log('Media now loads from ' . $this->state['live_media']['url'] . ' (' . ('all' === $this->state['live_media']['mode'] ? 'all media' : 'files missing on this site') . '). Change this under Site Migration → Live media.');
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
			$this->client->set_deadline($this->started + self::CALL_WINDOW);
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
		$repo = self::repo_summary($s);
		$weights = array(
			'database' => $has_db ? 45 : 0,
			'scan'     => $has_files ? 10 : 0,
			'transfer' => $has_files ? 35 : 0,
			'packages' => ! empty($repo['enabled']) && (! $repo['checked'] || $repo['eligible'] > 0) ? 10 : 0,
			'finalize' => 10,
		);
		$order = array('database', 'scan', 'transfer', 'packages', 'finalize_files', 'finalize_db', 'cleanup', 'done');
		$current = (int) array_search($s['phase'], $order, true);
		$fraction = array(
			'database' => 0.0,
			'scan'     => 0.0,
			'transfer' => 0.0,
			'packages' => 0.0,
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
		if ($current > 3) {
			$fraction['packages'] = 1.0;
		} elseif (3 === $current) {
			$fraction['packages'] = min(1.0, ($repo['installed'] + $repo['failed']) / max(1, $repo['eligible']));
		}
		if ($current >= 6) {
			$fraction['finalize'] = 0.9;
		} elseif ($current >= 4) {
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
