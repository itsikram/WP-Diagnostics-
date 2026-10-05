<?php
/**
 * Migration Engine - site-side primitives used by both ends of a migration.
 *
 * Every method here is resumable and bounded in time so that it can run on
 * shared hosts with short execution limits. The same code runs locally (when
 * this site is the source or destination of the job it drives) and remotely
 * (invoked through the signed Migration_API endpoint).
 *
 * Destination safety model:
 *  - Database rows are imported into temporary tables. Live tables are only
 *    replaced at finalize time with a single atomic RENAME TABLE statement,
 *    and the replaced tables are kept as rollback copies.
 *  - Files are written to a staging directory and moved into place at
 *    finalize time; overwritten files are moved to a rollback directory.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Engine {
	public const API_VERSION = 2;
	// 'core' stays last so WordPress core files are moved into place after everything else.
	public const COMPONENTS = array('plugins', 'themes', 'uploads', 'mu-plugins', 'languages', 'content', 'core');
	// Capabilities newer than API v2; the other side must list one before it is used.
	public const FEATURES = array('merge', 'content', 'core', 'peers', 'wire', 'fast');
	public const MERGE_GROUPS = array('posts', 'terms', 'comments', 'users');
	// Tables (without prefix) that "add as new content" mode reads.
	public const MERGE_TABLES = array('users', 'usermeta', 'terms', 'term_taxonomy', 'termmeta', 'term_relationships', 'posts', 'postmeta', 'comments', 'commentmeta');
	// Files the whole-folder components never overwrite: they hold this server's own settings.
	private const CORE_KEEP = array('wp-config.php', '.htaccess', 'web.config', '.user.ini', 'php.ini', '.maintenance');
	private const CONTENT_SKIP = array('wudt-migrations', 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'wpvividbackups', 'backups-dup-lite', 'wflogs', 'et-cache', 'litespeed','object-cache.php', 'advanced-cache.php');

	private const TMP_PREFIX = 'wudt_tmp_';
	private const BAK_PREFIX = 'wudt_bak_';
	private const MAX_INSERT_BYTES = 900000;

	private float $started;

	/** @var array<string,array<string,array{0:int,1:int,2:string}>> Component => path => [size, mtime, md5]. */
	private array $hash_cache = array();
	/** @var array<string,bool> */
	private array $hash_dirty = array();

	public function __construct() {
		$this->started = microtime(true);
	}

	/* ---------------------------------------------------------------------
	 * Storage helpers
	 * ------------------------------------------------------------------- */

	public static function storage_dir(string $sub = ''): string {
		$base = wp_normalize_path(WP_CONTENT_DIR) . '/wudt-migrations';
		if (! is_dir($base)) {
			wp_mkdir_p($base);
		}
		if (! file_exists($base . '/index.php')) {
			@file_put_contents($base . '/index.php', "<?php\n// Silence is golden.\n");
			@file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
			@file_put_contents($base . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
		}
		if ('' === $sub) {
			return $base;
		}
		$dir = $base . '/' . trim($sub, '/');
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		return $dir;
	}

	public static function sanitize_job_id(string $job_id): string {
		$clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $job_id);
		if (! is_string($clean) || strlen($clean) < 8 || strlen($clean) > 64) {
			throw new \RuntimeException('Invalid migration job id.');
		}
		return $clean;
	}

	private function time_left(float $budget): bool {
		return (microtime(true) - $this->started) < $budget;
	}

	public static function raise_limits(): void {
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}
		if (function_exists('ignore_user_abort')) {
			@ignore_user_abort(true);
		}
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('admin');
		}
	}

	/* ---------------------------------------------------------------------
	 * Site information
	 * ------------------------------------------------------------------- */

	public function site_info(): array {
		global $wpdb;

		$plugins = array();
		$names = array('plugins' => array(), 'themes' => array());
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ((array) get_plugins() as $file => $plugin) {
			$plugins[$file] = (string) ($plugin['Version'] ?? '');
			$names['plugins'][$file] = (string) ($plugin['Name'] ?? '');
		}
		$themes = array();
		foreach (wp_get_themes() as $slug => $theme) {
			$themes[(string) $slug] = (string) $theme->get('Version');
			$names['themes'][(string) $slug] = (string) $theme->get('Name');
		}

		$uploads = wp_get_upload_dir();

		return array(
			'api_version'     => self::API_VERSION,
			'features'        => self::FEATURES,
			'plugin_version'  => defined('WUDT_VERSION') ? WUDT_VERSION : '',
			'site_name'       => get_bloginfo('name'),
			'home'            => untrailingslashit((string) get_option('home')),
			'siteurl'         => untrailingslashit((string) get_option('siteurl')),
			'abspath'         => untrailingslashit(wp_normalize_path(ABSPATH)),
			'abspath_raw'     => untrailingslashit(ABSPATH),
			'content_dir'     => wp_normalize_path(WP_CONTENT_DIR),
			'uploads_dir'     => wp_normalize_path((string) $uploads['basedir']),
			'uploads_url'     => untrailingslashit((string) $uploads['baseurl']),
			'prefix'          => $wpdb->prefix,
			'multisite'       => is_multisite(),
			'wp_version'      => get_bloginfo('version'),
			'php_version'     => PHP_VERSION,
			'db_server'       => (string) $wpdb->get_var('SELECT VERSION()'),
			'db_charset'      => $wpdb->charset,
			'max_packet'      => (int) $wpdb->get_var('SELECT @@max_allowed_packet'),
			'post_max_size'   => wp_convert_hr_to_bytes((string) ini_get('post_max_size')),
			'memory_limit'    => wp_convert_hr_to_bytes((string) ini_get('memory_limit')),
			'max_exec'        => (int) ini_get('max_execution_time'),
			'free_space'      => function_exists('disk_free_space') ? (int) @disk_free_space(WP_CONTENT_DIR) : 0,
			'zlib'            => function_exists('gzdeflate'),
			'plugins'         => $plugins,
			'active_plugins'  => array_values((array) get_option('active_plugins', array())),
			'themes'          => $themes,
			'names'           => $names,
			'stylesheet'      => (string) get_option('stylesheet'),
			'template'        => (string) get_option('template'),
			'self_plugin_dir' => basename(dirname(WUDT_PLUGIN_FILE)),
			'tables'          => $this->list_tables(),
			'rollback'        => $this->rollback_summary(),
		);
	}

	/**
	 * Base tables that belong to this WordPress install (prefix match).
	 */
	public function list_tables(): array {
		global $wpdb;
		$like = $wpdb->esc_like($wpdb->prefix) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, (DATA_LENGTH + INDEX_LENGTH) AS size FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
				'BASE TABLE',
				$like
			),
			ARRAY_A
		);
		$tables = array();
		foreach ((array) $rows as $row) {
			$name = (string) $row['name'];
			if (0 === strpos($name, self::TMP_PREFIX) || 0 === strpos($name, self::BAK_PREFIX)) {
				continue;
			}
			$tables[] = array(
				'name' => $name,
				'rows' => (int) $row['row_count'],
				'size' => (int) $row['size'],
			);
		}
		return $tables;
	}

	/**
	 * CHECKSUM TABLE for a list of tables (skips very large tables).
	 */
	public function table_checksums(array $tables, float $budget = 15.0): array {
		global $wpdb;
		$existing = array();
		foreach ($this->list_tables() as $t) {
			$existing[$t['name']] = $t['size'];
		}
		$out = array();
		foreach ($tables as $table) {
			$table = (string) $table;
			if (! isset($existing[$table]) || $existing[$table] > 64 * MB_IN_BYTES || ! $this->time_left($budget)) {
				$out[$table] = null;
				continue;
			}
			$row = $wpdb->get_row('CHECKSUM TABLE `' . esc_sql($table) . '`', ARRAY_A);
			$out[$table] = isset($row['Checksum']) ? (string) $row['Checksum'] : null;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Database export (source side)
	 * ------------------------------------------------------------------- */

	/**
	 * Export one chunk of rows from a table.
	 *
	 * @param array<string,string> $pairs Search => replace pairs applied to every value.
	 */
	public function export_table_chunk(string $table, ?array $cursor, array $pairs, int $max_bytes = 2000000, float $budget = 12.0): array {
		global $wpdb;

		$this->assert_own_table($table);
		$columns = $this->table_columns($table);
		if (empty($columns)) {
			throw new \RuntimeException('Table has no readable columns: ' . $table);
		}
		$insertable = array();
		foreach ($columns as $col) {
			if (! $col['generated']) {
				$insertable[] = $col['name'];
			}
		}
		$pk = $this->primary_key($table);
		$cols_sql = '`' . implode('`, `', array_map('esc_sql', $insertable)) . '`';

		$result = array(
			'table'   => $table,
			'columns' => $insertable,
			'rows'    => array(),
			'done'    => false,
			'cursor'  => $cursor,
		);

		if (null === $cursor) {
			$create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($table) . '`', ARRAY_N);
			if (empty($create[1])) {
				throw new \RuntimeException('Unable to read table structure: ' . $table);
			}
			$result['create'] = (string) $create[1];
			$cursor = array('offset' => 0, 'last' => null);
		}

		$keyset = (1 === count($pk) && $this->is_integer_column($columns, $pk[0]));
		$batch = 500;
		$bytes = 0;
		$replace = ! empty($pairs);

		while (true) {
			if ($keyset) {
				$pk_col = '`' . esc_sql($pk[0]) . '`';
				if (null === $cursor['last']) {
					$sql = "SELECT {$cols_sql} FROM `" . esc_sql($table) . "` ORDER BY {$pk_col} ASC LIMIT {$batch}";
				} else {
					$sql = $wpdb->prepare("SELECT {$cols_sql} FROM `" . esc_sql($table) . "` WHERE {$pk_col} > %s ORDER BY {$pk_col} ASC LIMIT {$batch}", (string) $cursor['last']); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			} else {
				$order = empty($pk) ? '' : ' ORDER BY `' . implode('`, `', array_map('esc_sql', $pk)) . '`';
				$sql = "SELECT {$cols_sql} FROM `" . esc_sql($table) . "`{$order} LIMIT " . (int) $cursor['offset'] . ", {$batch}";
			}

			$rows = $wpdb->get_results($sql, ARRAY_N); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Export failed for ' . $table . ': ' . $wpdb->last_error);
			}
			$rows = is_array($rows) ? $rows : array();

			$pk_index = $keyset ? array_search($pk[0], $insertable, true) : false;
			foreach ($rows as $row) {
				$encoded = array();
				foreach ($row as $value) {
					if (null === $value) {
						$encoded[] = null;
						continue;
					}
					$value = (string) $value;
					if ($replace) {
						$value = Migration_Replacer::replace($value, $pairs);
					}
					if ('' !== $value && ! self::is_utf8($value)) {
						$encoded[] = array('b' => base64_encode($value));
					} else {
						$encoded[] = $value;
					}
					$bytes += strlen($value) + 4;
				}
				$result['rows'][] = $encoded;
				if ($keyset && false !== $pk_index) {
					$cursor['last'] = $row[$pk_index];
				}
			}
			$cursor['offset'] += count($rows);

			if (count($rows) < $batch) {
				$result['done'] = true;
				break;
			}
			if ($bytes >= $max_bytes || ! $this->time_left($budget)) {
				break;
			}
			// Adapt batch size to row size so a chunk stays near the byte target.
			$avg = $bytes / max(1, count($result['rows']));
			$batch = (int) max(20, min(2000, ($max_bytes - $bytes) / max(1, $avg)));
		}

		$result['cursor'] = $cursor;
		$result['bytes'] = $bytes;
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Database import (destination side, into temporary tables)
	 * ------------------------------------------------------------------- */

	public static function tmp_table(string $job_id, string $dest_table): string {
		return self::TMP_PREFIX . substr(md5($job_id . '|' . $dest_table), 0, 14);
	}

	public static function bak_table(string $dest_table): string {
		return self::BAK_PREFIX . substr(md5($dest_table), 0, 14);
	}

	/**
	 * Import an exported chunk into the temporary table for $dest_table.
	 */
	public function import_table_chunk(string $job_id, array $chunk, string $source_prefix, array $pairs = array()): array {
		global $wpdb;

		$job_id = self::sanitize_job_id($job_id);
		$source_table = (string) ($chunk['table'] ?? '');
		$dest_table = $this->map_table_name($source_table, $source_prefix);
		$tmp = self::tmp_table($job_id, $dest_table);

		$this->prepare_session();

		if (! empty($chunk['create'])) {
			$wpdb->query('DROP TABLE IF EXISTS `' . $tmp . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			$create = $this->rewrite_create_statement((string) $chunk['create'], $tmp, $source_prefix, $job_id);
			$wpdb->query($create); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Could not create table ' . $dest_table . ': ' . $wpdb->last_error);
			}
		}

		$rows = (array) ($chunk['rows'] ?? array());
		$columns = (array) ($chunk['columns'] ?? array());
		if (empty($rows)) {
			return array('table' => $dest_table, 'inserted' => 0);
		}
		if (empty($columns)) {
			throw new \RuntimeException('Missing column list for ' . $source_table);
		}

		$max_packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');
		$limit = max(65536, min(self::MAX_INSERT_BYTES, (int) ($max_packet * 0.8)));
		// REPLACE keeps a retried chunk idempotent for tables with a primary key.
		$head = 'REPLACE INTO `' . $tmp . '` (`' . implode('`, `', array_map('esc_sql', $columns)) . '`) VALUES ';
		$values = array();
		$size = strlen($head);
		$inserted = 0;

		foreach ($rows as $row) {
			$parts = array();
			foreach ((array) $row as $value) {
				if (! empty($pairs) && is_string($value)) {
					$value = Migration_Replacer::replace($value, $pairs);
				}
				$parts[] = $this->sql_literal($value);
			}
			$tuple = '(' . implode(',', $parts) . ')';
			if (! empty($values) && ($size + strlen($tuple) + 1) > $limit) {
				$this->run_insert($head . implode(',', $values), $dest_table);
				$inserted += count($values);
				$values = array();
				$size = strlen($head);
			}
			$values[] = $tuple;
			$size += strlen($tuple) + 1;
		}
		if (! empty($values)) {
			$this->run_insert($head . implode(',', $values), $dest_table);
			$inserted += count($values);
		}

		return array('table' => $dest_table, 'inserted' => $inserted);
	}

	private function run_insert(string $sql, string $table): void {
		global $wpdb;
		$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL
		if ($wpdb->last_error) {
			throw new \RuntimeException('Import failed for ' . $table . ': ' . substr($wpdb->last_error, 0, 300));
		}
		$wpdb->queries = array();
	}

	private function sql_literal($value): string {
		global $wpdb;
		if (null === $value) {
			return 'NULL';
		}
		if (is_array($value) && isset($value['b'])) {
			$raw = base64_decode((string) $value['b'], true);
			if (false === $raw || '' === $raw) {
				return "''";
			}
			return '0x' . bin2hex($raw);
		}
		$value = (string) $value;
		$dbh = $wpdb->dbh;
		if ($dbh instanceof \mysqli) {
			return "'" . mysqli_real_escape_string($dbh, $value) . "'";
		}
		return "'" . addslashes($value) . "'";
	}

	private function prepare_session(): void {
		global $wpdb;
		$wpdb->suppress_errors(true);
		$wpdb->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query('SET UNIQUE_CHECKS = 0');
		$wpdb->suppress_errors(false);
		$wpdb->last_error = '';
	}

	private function rewrite_create_statement(string $create, string $tmp, string $source_prefix, string $job_id): string {
		global $wpdb;
		// Table name.
		$create = (string) preg_replace('/^CREATE TABLE\s+`[^`]+`/i', 'CREATE TABLE `' . $tmp . '`', $create, 1);
		// Foreign key constraint names are database-global; make them unique.
		$suffix = substr(md5($job_id), 0, 6);
		$create = (string) preg_replace_callback(
			'/CONSTRAINT\s+`([^`]+)`/i',
			static function ($m) use ($suffix) {
				return 'CONSTRAINT `' . substr($m[1], 0, 50) . '_w' . $suffix . '`';
			},
			$create
		);
		// Point foreign key references at destination-prefixed tables.
		if ($source_prefix !== $wpdb->prefix) {
			$create = (string) preg_replace_callback(
				'/REFERENCES\s+`([^`]+)`/i',
				function ($m) use ($source_prefix) {
					return 'REFERENCES `' . $this->map_table_name($m[1], $source_prefix) . '`';
				},
				$create
			);
		}
		return $this->fix_collations($create);
	}

	/**
	 * Replace collations/charsets the destination server does not support.
	 */
	private function fix_collations(string $sql): string {
		global $wpdb;
		static $collations = null;
		static $charsets = null;
		if (null === $collations) {
			$collations = array();
			$charsets = array();
			foreach ((array) $wpdb->get_results('SHOW COLLATION', ARRAY_A) as $row) {
				$collations[strtolower((string) ($row['Collation'] ?? ''))] = strtolower((string) ($row['Charset'] ?? ''));
			}
			foreach ((array) $wpdb->get_col('SHOW CHARACTER SET') as $name) {
				$charsets[strtolower((string) $name)] = true;
			}
		}
		return self::rewrite_charsets($sql, $collations, $charsets);
	}

	/**
	 * Make charset/collation names in a CREATE TABLE statement valid for the
	 * destination server, without ever pairing a collation with a different
	 * character set (e.g. MariaDB "utf8mb3_unicode_ci" → MySQL 5.7 "utf8_unicode_ci").
	 *
	 * @param array<string,string> $collations Supported collation => its charset.
	 * @param array<string,bool>   $charsets   Supported character sets.
	 */
	public static function rewrite_charsets(string $sql, array $collations, array $charsets): string {
		if (empty($collations)) {
			return $sql;
		}
		// utf8 and utf8mb3 are the same charset under two names; use the one the server knows.
		$utf8_name = isset($charsets['utf8mb3']) ? 'utf8mb3' : 'utf8';
		$charset_alias = static function (string $charset) use ($charsets, $utf8_name): string {
			$charset = strtolower($charset);
			if ('utf8' === $charset || 'utf8mb3' === $charset) {
				return isset($charsets[$charset]) || empty($charsets) ? $charset : $utf8_name;
			}
			return $charset;
		};

		$sql = (string) preg_replace_callback(
			'/((?:DEFAULT\s+)?(?:CHARACTER\s+SET|CHARSET)\s*=?\s*)([a-z0-9_]+)/i',
			static function ($m) use ($charset_alias) {
				return $m[1] . $charset_alias($m[2]);
			},
			$sql
		);

		return (string) preg_replace_callback(
			'/(COLLATE\s*=?\s*)([a-z0-9_]+)/i',
			static function ($m) use ($collations) {
				$collation = strtolower($m[2]);
				if (isset($collations[$collation])) {
					return $m[0];
				}
				$charset = (string) strstr($collation, '_', true);
				$suffix = (string) substr($collation, strlen($charset));
				$candidates = array();
				// Same collation under the other utf8 name.
				if ('utf8mb3' === $charset) {
					$candidates[] = 'utf8' . $suffix;
				} elseif ('utf8' === $charset) {
					$candidates[] = 'utf8mb3' . $suffix;
				}
				$names = ('utf8mb3' === $charset || 'utf8' === $charset) ? array('utf8mb3', 'utf8') : array($charset);
				foreach ($names as $cs) {
					foreach (array('_unicode_520_ci', '_unicode_ci', '_general_ci', '_bin') as $tail) {
						$candidates[] = $cs . $tail;
					}
				}
				foreach ($candidates as $candidate) {
					if (isset($collations[$candidate])) {
						return $m[1] . $candidate;
					}
				}
				// Last resort: the server default collation for the same charset (drop COLLATE).
				return '';
			},
			$sql
		);
	}

	public function map_table_name(string $source_table, string $source_prefix): string {
		global $wpdb;
		if ('' !== $source_prefix && 0 === strpos($source_table, $source_prefix)) {
			$mapped = $wpdb->prefix . substr($source_table, strlen($source_prefix));
		} else {
			$mapped = $source_table;
		}
		if (! preg_match('/^[A-Za-z0-9_$]{1,64}$/', $mapped)) {
			throw new \RuntimeException('Invalid table name: ' . $source_table);
		}
		return $mapped;
	}

	/* ---------------------------------------------------------------------
	 * Files: manifest, diff, read, write (staging)
	 * ------------------------------------------------------------------- */

	public static function component_root(string $component): string {
		switch ($component) {
			case 'plugins':
				return wp_normalize_path(WP_PLUGIN_DIR);
			case 'themes':
				return wp_normalize_path(get_theme_root());
			case 'uploads':
				$uploads = wp_get_upload_dir();
				return wp_normalize_path((string) $uploads['basedir']);
			case 'mu-plugins':
				return wp_normalize_path(WPMU_PLUGIN_DIR);
			case 'languages':
				return wp_normalize_path(WP_LANG_DIR);
			case 'content':
				return untrailingslashit(wp_normalize_path(WP_CONTENT_DIR));
			case 'core':
				return untrailingslashit(wp_normalize_path(ABSPATH));
		}
		throw new \RuntimeException('Unknown component: ' . $component);
	}

	/**
	 * Folders (relative to a whole-folder component) that belong to another
	 * component, so each file is migrated by exactly one component.
	 *
	 * @return array<int,string>
	 */
	private static function nested_roots(string $component): array {
		static $cache = array();
		if (isset($cache[$component])) {
			return $cache[$component];
		}
		$children = array('plugins', 'themes', 'uploads', 'mu-plugins', 'languages');
		if ('core' === $component) {
			$children[] = 'content';
		}
		$root = self::component_root($component);
		$out = array();
		foreach ($children as $child) {
			try {
				$path = self::component_root($child);
			} catch (\Throwable $e) {
				continue;
			}
			if (0 === strpos($path, $root . '/')) {
				$out[] = substr($path, strlen($root) + 1);
			}
		}
		$cache[$component] = $out;
		return $out;
	}

	/**
	 * Returns true when a relative path must never be migrated for a component.
	 */
	public static function is_excluded(string $component, string $rel, array $extra = array()): bool {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		$first = strtok($rel, '/');
		$base = basename($rel);

		if (in_array($base, array('.DS_Store', 'Thumbs.db', 'error_log', 'debug.log'), true)) {
			return true;
		}
		if (preg_match('#(^|/)(\.git|\.svn|\.hg|node_modules/\.cache)(/|$)#', $rel)) {
			return true;
		}
		if ('plugins' === $component) {
			$self = basename(dirname(WUDT_PLUGIN_FILE));
			if ($first === $self) {
				return true;
			}
		}
		if ('mu-plugins' === $component && 0 === strpos($base, 'wudt-')) {
			return true;
		}
		if ('uploads' === $component) {
			if (is_string($first) && (0 === strpos($first, 'wudt-') || in_array($first, array('ai1wm-backups', 'wpvivid_uploads', 'wpvividbackups', 'backwpup-temp', 'cache'), true))) {
				return true;
			}
		}
		if ('core' === $component && in_array($rel, self::CORE_KEEP, true)) {
			return true;
		}
		if ('content' === $component && is_string($first) && (0 === strpos($first, 'wudt-') || in_array($first, self::CONTENT_SKIP, true))) {
			return true;
		}
		if ('core' === $component || 'content' === $component) {
			foreach (self::nested_roots($component) as $nested) {
				if ($rel === $nested || 0 === strpos($rel, $nested . '/')) {
					return true;
				}
			}
		}
		foreach ($extra as $pattern) {
			$pattern = trim(str_replace('\\', '/', (string) $pattern), '/ ');
			if ('' === $pattern) {
				continue;
			}
			if ($rel === $pattern || 0 === strpos($rel, $pattern . '/') || fnmatch($pattern, $rel) || fnmatch($pattern, $base)) {
				return true;
			}
		}
		return false;
	}

	private static function safe_rel(string $rel): string {
		$rel = str_replace('\\', '/', $rel);
		$rel = ltrim($rel, '/');
		if ('' === $rel || str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel) || preg_match('#^[a-zA-Z]:#', $rel)) {
			throw new \RuntimeException('Unsafe path rejected: ' . $rel);
		}
		return $rel;
	}

	/**
	 * Page through the files of a component. The first call (offset 0)
	 * walks the directory tree and caches the listing; later pages hash files.
	 */
	public function manifest_page(string $job_id, string $component, int $offset, array $excludes = array(), float $budget = 12.0, int $max_entries = 3000, bool $hash = true, bool $cached_only = false): array {
		$job_id = self::sanitize_job_id($job_id);
		$root = self::component_root($component);
		$list_file = self::storage_dir('work') . '/' . $job_id . '-list-' . $component . '.txt';

		if (0 === $offset || ! file_exists($list_file)) {
			$this->build_file_list($root, $component, $excludes, $list_file);
		}

		$entries = array();
		$total = 0;
		$handle = @fopen($list_file, 'rb');
		if (! $handle) {
			throw new \RuntimeException('Unable to read file list for ' . $component);
		}
		$line_no = 0;
		$next = $offset;
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if ('' === $line) {
				continue;
			}
			if ($line_no++ < $offset) {
				continue;
			}
			if (count($entries) >= $max_entries || ! $this->time_left($budget)) {
				break;
			}
			$abs = $root . '/' . $line;
			if (! is_file($abs) || ! is_readable($abs)) {
				$next++;
				continue;
			}
			// With $cached_only, only known hashes are sent; the destination asks
			// for the rest when it has a file of the same size (see diff_entries()).
			$entries[] = array(
				'p' => $line,
				's' => (int) filesize($abs),
				'h' => $hash ? $this->file_hash($component, $line, $abs, ! $cached_only) : '',
			);
			$next++;
		}
		$eof = feof($handle) && count($entries) < $max_entries;
		fclose($handle);
		$this->save_hash_cache();
		$total = (int) get_transient('wudt_mig_count_' . md5($list_file));

		if ($eof) {
			@unlink($list_file);
		}

		return array(
			'component' => $component,
			'entries'   => $entries,
			'next'      => $next,
			'done'      => $eof,
			'total'     => $total,
		);
	}

	/**
	 * Write the list of files of a component to a work file (one relative path per line).
	 *
	 * @return array{file:string,count:int,root:string}
	 */
	public function prepare_file_list(string $job_id, string $component, array $excludes = array()): array {
		$job_id = self::sanitize_job_id($job_id);
		$root = self::component_root($component);
		$list_file = self::storage_dir('work') . '/' . $job_id . '-files-' . $component . '.txt';
		$this->build_file_list($root, $component, $excludes, $list_file);
		return array('file' => $list_file, 'count' => (int) get_transient('wudt_mig_count_' . md5($list_file)), 'root' => $root);
	}

	/**
	 * Import one statement from a legacy (v1) SQL dump into the job's temporary tables.
	 *
	 * @return string|null Source table name when the statement created a table.
	 */
	public function import_legacy_statement(string $job_id, string $sql, string $source_prefix): ?string {
		global $wpdb;
		$job_id = self::sanitize_job_id($job_id);
		$sql = trim($sql);
		if ('' === $sql || 0 === strpos($sql, '--')) {
			return null;
		}
		// Old backups replaced every "%" with a per-request placeholder; restore it.
		$sql = (string) preg_replace('/\{[0-9a-f]{64}\}/', '%', $sql);
		static $session = false;
		if (! $session) {
			$this->prepare_session();
			$session = true;
		}
		// Legacy dumps contained every table in the database; only restore this site's own.
		if (preg_match('/^(?:CREATE TABLE|INSERT INTO)\s+`([^`]+)`/i', $sql, $t) && ! self::is_site_table($t[1], $source_prefix)) {
			return null;
		}
		if (preg_match('/^CREATE TABLE\s+`([^`]+)`/i', $sql, $m)) {
			$dest = $this->map_table_name($m[1], $source_prefix);
			$tmp = self::tmp_table($job_id, $dest);
			$wpdb->query('DROP TABLE IF EXISTS `' . $tmp . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query($this->rewrite_create_statement($sql, $tmp, $source_prefix, $job_id)); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Could not create table ' . $dest . ': ' . $wpdb->last_error);
			}
			return $m[1];
		}
		if (preg_match('/^INSERT INTO\s+`([^`]+)`/i', $sql, $m)) {
			$dest = $this->map_table_name($m[1], $source_prefix);
			$tmp = self::tmp_table($job_id, $dest);
			$sql = 'REPLACE INTO `' . $tmp . '`' . substr($sql, strlen($m[0]));
			$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Import failed for ' . $dest . ': ' . substr($wpdb->last_error, 0, 300));
			}
			$wpdb->queries = array();
		}
		return null;
	}

	public static function is_site_table(string $table, string $prefix): bool {
		if (0 === strpos($table, self::TMP_PREFIX) || 0 === strpos($table, self::BAK_PREFIX)) {
			return false;
		}
		return '' === $prefix || 0 === strpos($table, $prefix);
	}

	/**
	 * Serialization-safe search & replace inside an imported temporary table.
	 *
	 * @return array{cursor:int,done:bool}
	 */
	public function replace_in_tmp_table(string $job_id, string $source_table, string $source_prefix, array $pairs, int $offset, float $budget = 12.0): array {
		global $wpdb;
		$dest = $this->map_table_name($source_table, $source_prefix);
		$tmp = self::tmp_table(self::sanitize_job_id($job_id), $dest);
		$pk = $this->primary_key($tmp);
		if (empty($pairs) || empty($pk)) {
			return array('cursor' => 0, 'done' => true);
		}
		$columns = $this->table_columns($tmp);
		$text_cols = array();
		foreach ($columns as $col) {
			if (! $col['generated'] && preg_match('/(char|text|blob|json)/', $col['type']) && ! in_array($col['name'], $pk, true)) {
				$text_cols[] = $col['name'];
			}
		}
		if (empty($text_cols)) {
			return array('cursor' => 0, 'done' => true);
		}
		$select = '`' . implode('`, `', array_merge($pk, $text_cols)) . '`';
		$order = '`' . implode('`, `', $pk) . '`';
		while ($this->time_left($budget)) {
			$rows = $wpdb->get_results("SELECT {$select} FROM `{$tmp}` ORDER BY {$order} LIMIT {$offset}, 500", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
			if (empty($rows)) {
				return array('cursor' => $offset, 'done' => true);
			}
			foreach ($rows as $row) {
				$changes = array();
				foreach ($text_cols as $col) {
					if (is_string($row[$col]) && '' !== $row[$col]) {
						$new = Migration_Replacer::replace($row[$col], $pairs);
						if ($new !== $row[$col]) {
							$changes[$col] = $new;
						}
					}
				}
				if (! empty($changes)) {
					$where = array();
					foreach ($pk as $k) {
						$where[$k] = $row[$k];
					}
					$wpdb->update($tmp, $changes, $where);
				}
			}
			$offset += count($rows);
			if (count($rows) < 500) {
				return array('cursor' => $offset, 'done' => true);
			}
		}
		return array('cursor' => $offset, 'done' => false);
	}

	private function build_file_list(string $root, string $component, array $excludes, string $list_file): void {
		$out = @fopen($list_file, 'wb');
		if (! $out) {
			throw new \RuntimeException('Unable to write migration work file. Check that wp-content is writable.');
		}
		$count = 0;
		if (is_dir($root)) {
			$root_len = strlen(rtrim($root, '/')) + 1;
			$dir_iter = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);
			$filter = new \RecursiveCallbackFilterIterator(
				$dir_iter,
				static function (\SplFileInfo $current) use ($component, $excludes, $root_len) {
					if ($current->isLink()) {
						return false;
					}
					// Another WordPress install inside this one is not part of this site.
					if ('core' === $component && $current->isDir() && (is_file($current->getPathname() . '/wp-config.php') || is_file($current->getPathname() . '/wp-load.php'))) {
						return false;
					}
					$rel = substr(wp_normalize_path($current->getPathname()), $root_len);
					return ! self::is_excluded($component, $rel, $excludes);
				}
			);
			$iter = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
			foreach ($iter as $file) {
				/** @var \SplFileInfo $file */
				if (! $file->isFile()) {
					continue;
				}
				$rel = substr(wp_normalize_path($file->getPathname()), $root_len);
				if ('' === $rel || str_contains($rel, "\n")) {
					continue;
				}
				fwrite($out, $rel . "\n");
				$count++;
			}
		}
		fclose($out);
		set_transient('wudt_mig_count_' . md5($list_file), $count, DAY_IN_SECONDS);
	}

	/**
	 * Return the subset of entries that differ on this site (missing, other size or hash).
	 */
	public function diff_entries(string $component, array $entries, float $budget = 15.0, bool $lazy = false): array {
		$root = self::component_root($component);
		$changed = array();
		$verify = array();
		$checked = 0;
		foreach ($entries as $entry) {
			$rel = self::safe_rel((string) ($entry['p'] ?? ''));
			$abs = $root . '/' . $rel;
			$size = (int) ($entry['s'] ?? -1);
			if (! is_file($abs) || (int) filesize($abs) !== $size) {
				$changed[] = $entry;
			} elseif (! $this->time_left($budget)) {
				// When out of time we transfer rather than risk skipping a changed file.
				$changed[] = $entry;
			} elseif ($lazy && '' === (string) ($entry['h'] ?? '')) {
				// The source has not hashed this file yet: it compares against our hash.
				$verify[] = array('p' => $entry['p'], 's' => $size, 'h' => $this->file_hash($component, $rel, $abs));
			} elseif ($this->file_hash($component, $rel, $abs) !== (string) ($entry['h'] ?? '')) {
				$changed[] = $entry;
			}
			$checked++;
		}
		$this->save_hash_cache();
		return array('changed' => $changed, 'verify' => $verify, 'checked' => $checked);
	}

	/**
	 * Hashes of files of a component (source side of a lazy comparison).
	 *
	 * @return array<string,string> Path => md5; paths missing from the result were not hashed in time.
	 */
	public function file_hashes(string $component, array $paths, float $budget = 15.0): array {
		$root = self::component_root($component);
		$out = array();
		foreach ($paths as $path) {
			if (! $this->time_left($budget)) {
				break;
			}
			$rel = self::safe_rel((string) $path);
			$abs = $root . '/' . $rel;
			$out[(string) $path] = is_file($abs) && is_readable($abs) ? $this->file_hash($component, $rel, $abs) : '';
		}
		$this->save_hash_cache();
		return $out;
	}

	/**
	 * md5 of a file, reusing the hash from the previous migration while size and mtime are unchanged.
	 */
	private function file_hash(string $component, string $rel, string $abs, bool $compute = true): string {
		if (! isset($this->hash_cache[$component])) {
			$data = json_decode((string) @file_get_contents(self::hash_cache_file($component)), true);
			$this->hash_cache[$component] = is_array($data) ? $data : array();
		}
		$size = (int) @filesize($abs);
		$mtime = (int) @filemtime($abs);
		$known = $this->hash_cache[$component][$rel] ?? null;
		if (is_array($known) && isset($known[2]) && (int) $known[0] === $size && (int) $known[1] === $mtime) {
			return (string) $known[2];
		}
		if (! $compute) {
			return '';
		}
		$hash = (string) md5_file($abs);
		$this->hash_cache[$component][$rel] = array($size, $mtime, $hash);
		$this->hash_dirty[$component] = true;
		return $hash;
	}

	private static function hash_cache_file(string $component): string {
		return self::storage_dir('cache') . '/hashes-' . preg_replace('/[^a-z-]/', '', $component) . '.json';
	}

	private function save_hash_cache(): void {
		foreach (array_keys($this->hash_dirty) as $component) {
			@file_put_contents(self::hash_cache_file($component), (string) wp_json_encode($this->hash_cache[$component]), LOCK_EX);
		}
		$this->hash_dirty = array();
	}

	/**
	 * Read file segments. $requests: [{c: component, p: path, o: offset, l: length}]
	 */
	public function read_files(array $requests, bool $raw = false): array {
		$out = array();
		foreach ($requests as $req) {
			$component = (string) ($req['c'] ?? '');
			$rel = self::safe_rel((string) ($req['p'] ?? ''));
			if (self::is_excluded($component, $rel)) {
				throw new \RuntimeException('Path is excluded from migration: ' . $rel);
			}
			$abs = self::component_root($component) . '/' . $rel;
			$offset = max(0, (int) ($req['o'] ?? 0));
			$length = max(0, (int) ($req['l'] ?? 0));
			if (! is_file($abs) || ! is_readable($abs)) {
				$out[] = array('c' => $component, 'p' => $rel, 'missing' => true);
				continue;
			}
			$size = (int) filesize($abs);
			$data = '';
			if ($length > 0 && $offset < $size) {
				$fh = fopen($abs, 'rb');
				if ($fh) {
					fseek($fh, $offset);
					$data = (string) fread($fh, $length);
					fclose($fh);
				}
			}
			$eof = ($offset + strlen($data)) >= $size;
			$segment = array(
				'c'   => $component,
				'p'   => $rel,
				'o'   => $offset,
				's'   => $size,
				'eof' => $eof,
			);
			if ($raw) {
				$segment['raw'] = $data;
			} else {
				$segment['d'] = base64_encode($data);
			}
			if ($eof) {
				$segment['h'] = (0 === $offset) ? md5($data) : (string) md5_file($abs);
			}
			$out[] = $segment;
		}
		return $out;
	}

	/**
	 * Write file segments into the staging area. $segments: [{c, p, o, d, eof, h}]
	 */
	public function write_files(string $job_id, array $segments): array {
		$job_id = self::sanitize_job_id($job_id);
		$stage = self::storage_dir('stage-' . $job_id);
		$list = $stage . '/.staged-list';
		$written = 0;
		$completed = 0;
		$failed = array();

		foreach ($segments as $seg) {
			$component = (string) ($seg['c'] ?? '');
			if (! in_array($component, self::COMPONENTS, true)) {
				throw new \RuntimeException('Unknown component: ' . $component);
			}
			$rel = self::safe_rel((string) ($seg['p'] ?? ''));
			if (self::is_excluded($component, $rel)) {
				continue;
			}
			$target = $stage . '/' . $component . '/' . $rel;
			$dir = dirname($target);
			if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
				throw new \RuntimeException('Unable to create staging directory for ' . $rel);
			}
			$data = isset($seg['raw']) && is_string($seg['raw']) ? $seg['raw'] : base64_decode((string) ($seg['d'] ?? ''), true);
			if (false === $data) {
				throw new \RuntimeException('Corrupt file data received for ' . $rel);
			}
			$offset = max(0, (int) ($seg['o'] ?? 0));
			$eof = ! empty($seg['eof']);
			// Chunks of one file may arrive in parallel and in any order, so only a
			// whole-file segment truncates; the last chunk trims the file to size.
			$fh = fopen($target, 0 === $offset && $eof ? 'wb' : 'c+b');
			if (! $fh) {
				throw new \RuntimeException('Unable to write staged file ' . $rel);
			}
			if ($offset > 0) {
				fseek($fh, $offset);
			}
			fwrite($fh, $data);
			if ($eof && $offset > 0) {
				ftruncate($fh, $offset + strlen($data));
			}
			fclose($fh);
			$written += strlen($data);

			if (! empty($seg['eof'])) {
				clearstatcache(true, $target);
				// A whole file in one segment can be checked without reading it back.
				$actual = 0 === $offset ? md5($data) : (string) md5_file($target);
				if (! empty($seg['h']) && $actual !== (string) $seg['h']) {
					// The source file changed while it was being copied; let the driver retry it.
					@unlink($target);
					$failed[] = array('c' => $component, 'p' => $rel);
					continue;
				}
				file_put_contents($list, $component . '|' . $rel . "\n", FILE_APPEND | LOCK_EX);
				$completed++;
			}
		}

		return array('bytes' => $written, 'completed' => $completed, 'failed' => $failed);
	}

	/* ---------------------------------------------------------------------
	 * Finalize: move staged files, swap tables, post-process
	 * ------------------------------------------------------------------- */

	/**
	 * Move staged files into place. Resumable via $offset (line index in the staged list).
	 */
	public function finalize_files(string $job_id, int $offset, float $budget = 15.0): array {
		$job_id = self::sanitize_job_id($job_id);
		$stage = self::storage_dir('stage-' . $job_id);
		$list = $stage . '/.staged-list';
		if (! file_exists($list)) {
			return array('next' => $offset, 'done' => true, 'moved' => 0);
		}

		if (0 === $offset && ! $this->rollback_set_matches($job_id)) {
			$this->start_rollback_set($job_id);
		}
		$rb_dir = self::storage_dir('rollback/files');
		$rb_log = self::storage_dir('rollback') . '/files.log';

		$handle = fopen($list, 'rb');
		$line_no = 0;
		$moved = 0;
		$next = $offset;
		$done = true;
		// Rollback entries are written in batches and flushed before any error is thrown.
		$log = '';
		$flush = static function () use (&$log, $rb_log): void {
			if ('' !== $log) {
				file_put_contents($rb_log, $log, FILE_APPEND | LOCK_EX);
				$log = '';
			}
		};
		while (($line = fgets($handle)) !== false) {
			if ($line_no++ < $offset) {
				continue;
			}
			$line = rtrim($line, "\r\n");
			// Core files are all swapped in one request: a half-replaced WordPress
			// core might not be able to load the request that finishes the job.
			if (0 !== strpos($line, 'core|') && ! $this->time_left($budget)) {
				$done = false;
				break;
			}
			$next++;
			if ('' === $line || false === strpos($line, '|')) {
				continue;
			}
			list($component, $rel) = explode('|', $line, 2);
			$rel = self::safe_rel($rel);
			$src = $stage . '/' . $component . '/' . $rel;
			if (! is_file($src)) {
				continue;
			}
			$target = self::component_root($component) . '/' . $rel;
			$backup = '';
			if (file_exists($target)) {
				$backup = $rb_dir . '/' . $component . '/' . $rel;
				wp_mkdir_p(dirname($backup));
				if (! self::move_file($target, $backup)) {
					fclose($handle);
					$flush();
					throw new \RuntimeException('Unable to replace ' . $component . '/' . $rel . ' (file is locked or not writable).');
				}
			}
			wp_mkdir_p(dirname($target));
			if (! self::move_file($src, $target)) {
				if ('' !== $backup) {
					self::move_file($backup, $target);
				}
				fclose($handle);
				$flush();
				throw new \RuntimeException('Unable to write ' . $component . '/' . $rel . '. Check file permissions.');
			}
			$log .= wp_json_encode(array('t' => $target, 'b' => $backup)) . "\n";
			if (strlen($log) > 65536) {
				$flush();
			}
			$moved++;
		}
		fclose($handle);
		$flush();

		if ($done && function_exists('opcache_reset')) {
			@opcache_reset();
		}

		return array('next' => $next, 'done' => $done, 'moved' => $moved);
	}

	private static function move_file(string $from, string $to): bool {
		if (@rename($from, $to)) {
			return true;
		}
		if (@copy($from, $to)) {
			@unlink($from);
			return true;
		}
		return false;
	}

	/**
	 * Swap imported temporary tables into place and fix up the site.
	 *
	 * @param array<int,string> $source_tables Tables (source names) that were imported.
	 */
	public function finalize_database(string $job_id, array $source_tables, string $source_prefix, array $source_info = array()): array {
		global $wpdb;
		$job_id = self::sanitize_job_id($job_id);

		if (empty($source_tables)) {
			return array('swapped' => 0);
		}

		$options_table = $wpdb->options;
		$preserved = $this->collect_preserved_options();

		$pairs = array();
		$rollback_tables = array();
		foreach ($source_tables as $source_table) {
			$dest = $this->map_table_name((string) $source_table, $source_prefix);
			$tmp = self::tmp_table($job_id, $dest);
			if ($tmp !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tmp))) {
				throw new \RuntimeException('Imported data for ' . $dest . ' is missing. Nothing was changed; please run the migration again.');
			}
			$pairs[$dest] = $tmp;
		}

		$this->prepare_session();
		if (! $this->rollback_set_matches($job_id)) {
			$this->start_rollback_set($job_id);
		}

		$renames = array();
		foreach ($pairs as $dest => $tmp) {
			$bak = self::bak_table($dest);
			$wpdb->query('DROP TABLE IF EXISTS `' . $bak . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			$exists = ($dest === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dest)));
			if ($exists) {
				$renames[] = '`' . $dest . '` TO `' . $bak . '`';
				$rollback_tables[$dest] = $bak;
			} else {
				$rollback_tables[$dest] = '';
			}
			$renames[] = '`' . $tmp . '` TO `' . $dest . '`';
		}

		// Record rollback data before the swap so a crash mid-way stays recoverable.
		$this->write_rollback_tables($rollback_tables);

		$wpdb->query('RENAME TABLE ' . implode(', ', $renames)); // phpcs:ignore WordPress.DB.PreparedSQL
		if ($wpdb->last_error) {
			throw new \RuntimeException('Table swap failed (' . $wpdb->last_error . '). Your existing data was not changed.');
		}
		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

		$options_swapped = isset($pairs[$options_table]);
		$usermeta_swapped = isset($pairs[$wpdb->usermeta]);
		$this->after_swap($source_prefix, $preserved, $options_swapped, $usermeta_swapped);

		return array('swapped' => count($pairs));
	}

	/**
	 * Options that must survive a database replacement on this site.
	 */
	private function collect_preserved_options(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name IN ('siteurl', 'home')",
				$wpdb->esc_like('wudt_') . '%'
			),
			ARRAY_A
		);
		return is_array($rows) ? $rows : array();
	}

	private function after_swap(string $source_prefix, array $preserved, bool $options_swapped, bool $usermeta_swapped): void {
		global $wpdb;

		$dest_prefix = $wpdb->prefix;
		if ($source_prefix !== $dest_prefix && '' !== $source_prefix) {
			if ($options_swapped) {
				$wpdb->update($wpdb->options, array('option_name' => $dest_prefix . 'user_roles'), array('option_name' => $source_prefix . 'user_roles'));
			}
			if ($usermeta_swapped) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
						$dest_prefix,
						strlen($source_prefix) + 1,
						$wpdb->esc_like($source_prefix) . '%'
					)
				);
			}
		}

		if ($options_swapped) {
			// Keep this site's own URLs, connection keys and plugin configuration.
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wudt_') . '%'));
			foreach ($preserved as $row) {
				$wpdb->replace(
					$wpdb->options,
					array(
						'option_name'  => $row['option_name'],
						'option_value' => $row['option_value'],
						'autoload'     => $row['autoload'],
					)
				);
			}

			// Keep this plugin active under this site's folder name.
			$active = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins')));
			$active = is_array($active) ? $active : array();
			$self = plugin_basename(WUDT_PLUGIN_FILE);
			$main = basename(WUDT_PLUGIN_FILE);
			$active = array_values(array_filter($active, static function ($p) use ($main) {
				return basename((string) $p) !== $main;
			}));
			$active[] = $self;
			sort($active);
			$wpdb->update($wpdb->options, array('option_value' => serialize($active)), array('option_name' => 'active_plugins'));

			$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name IN ('rewrite_rules', 'recovery_mode_email_last_sent', '_elementor_global_css', 'elementor-custom-breakpoints-files') OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'");
		}

		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_elementor_css'));

		$this->clear_elementor_css_files();
		wp_cache_flush();
		self::schedule_rewrite_flush();
	}

	/**
	 * Rewrite rules (and .htaccess) are rebuilt on the next request, when the
	 * new permalink settings are loaded.
	 */
	public static function schedule_rewrite_flush(): void {
		global $wpdb;
		$wpdb->replace($wpdb->options, array('option_name' => 'wudt_migration_flush_rewrite', 'option_value' => '1', 'autoload' => 'yes'));
		wp_cache_delete('alloptions', 'options');
	}

	public static function maybe_flush_rewrite(): void {
		// Command-line requests cannot detect mod_rewrite, so leave it for a web request.
		if ('1' !== (string) get_option('wudt_migration_flush_rewrite', '') || 'cli' === PHP_SAPI) {
			return;
		}
		delete_option('wudt_migration_flush_rewrite');
		if (! function_exists('save_mod_rewrite_rules')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if (! function_exists('get_home_path')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		flush_rewrite_rules(true);
	}

	private function clear_elementor_css_files(): void {
		$uploads = wp_get_upload_dir();
		$css_dir = wp_normalize_path((string) $uploads['basedir']) . '/elementor/css';
		if (! is_dir($css_dir)) {
			return;
		}
		foreach ((array) glob($css_dir . '/*.css') as $file) {
			@unlink((string) $file);
		}
	}

	/* ---------------------------------------------------------------------
	 * Merge: add imported content as new items instead of replacing tables
	 * ------------------------------------------------------------------- */

	private array $mc = array();

	/**
	 * Table that maps source IDs to the IDs they got here. It doubles as the
	 * rollback record (rows with created = 1 were inserted by the merge).
	 */
	public static function merge_map_table(string $job_id): string {
		return self::BAK_PREFIX . 'm' . substr(md5('merge|' . $job_id), 0, 13);
	}

	/**
	 * Add the content in the job's temporary tables to this site's live tables
	 * with new IDs. Existing posts, users, terms and comments are never changed.
	 * Resumable: call repeatedly until 'done' is true.
	 *
	 * @param string $source_key Identifies the source site so a repeated merge skips posts it already added.
	 * @param array  $groups     Subset of MERGE_GROUPS to add.
	 */
	public function merge_database(string $job_id, string $source_prefix, string $source_key, array $groups, float $budget = 12.0): array {
		global $wpdb;
		$job_id = self::sanitize_job_id($job_id);
		$state_file = self::storage_dir('work') . '/' . $job_id . '-merge.json';
		$st = $this->read_json($state_file);
		if (empty($st)) {
			$st = array('step' => 0, 'cursor' => 0, 'stats' => array('posts' => 0, 'skipped' => 0, 'terms' => 0, 'comments' => 0, 'users' => 0));
		}
		$map = self::merge_map_table($job_id);

		$this->prepare_session();
		if (empty($st['started'])) {
			if (! $this->rollback_set_matches($job_id)) {
				$this->start_rollback_set($job_id);
			}
			$wpdb->query("CREATE TABLE IF NOT EXISTS `{$map}` (kind CHAR(1) NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id BIGINT UNSIGNED NOT NULL, created TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (kind, old_id), KEY new_lookup (kind, new_id))"); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Could not create the merge map table: ' . $wpdb->last_error);
			}
			$meta = $this->read_json($this->rollback_meta_file());
			$meta['merge_map'] = $map;
			file_put_contents($this->rollback_meta_file(), wp_json_encode($meta));
			$st['started'] = true;
		}

		$tmp = array();
		foreach (self::MERGE_TABLES as $suffix) {
			$name = self::tmp_table($job_id, $wpdb->prefix . $suffix);
			$tmp[$suffix] = ($name === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name))) ? $name : '';
		}
		$this->mc = array(
			'map'      => $map,
			'tmp'      => $tmp,
			'groups'   => array_values(array_intersect(self::MERGE_GROUPS, $groups)),
			'src_pfx'  => $source_prefix,
			'src_key'  => substr(preg_replace('/[^a-z0-9]/', '', strtolower($source_key)) ?: 'src', 0, 32),
			'fallback' => $this->merge_fallback_author(),
			'budget'   => $budget,
		);

		$steps = array('users', 'usermeta', 'terms', 'term_parents', 'termmeta', 'posts_seen', 'posts', 'post_parents', 'postmeta', 'postmeta_ids', 'relationships', 'comments', 'comment_parents', 'commentmeta', 'recount');
		while ($st['step'] < count($steps) && $this->time_left($budget)) {
			$method = 'merge_step_' . $steps[$st['step']];
			$res = $this->$method((int) $st['cursor'], $st['stats']);
			if (! empty($res['done'])) {
				$st['step']++;
				$st['cursor'] = 0;
			} else {
				$st['cursor'] = (int) $res['cursor'];
			}
			file_put_contents($state_file, wp_json_encode($st), LOCK_EX);
		}

		$done = $st['step'] >= count($steps);
		if ($done) {
			wp_cache_flush();
			self::schedule_rewrite_flush();
		}
		return array(
			'done'  => $done,
			'step'  => $done ? 'done' : $steps[$st['step']],
			'stats' => $st['stats'],
		);
	}

	private function merge_has(string $group): bool {
		return in_array($group, $this->mc['groups'], true);
	}

	private function merge_fallback_author(): int {
		$ids = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'));
		return empty($ids) ? 0 : (int) $ids[0];
	}

	private function map_put(string $kind, int $old, int $new, bool $created): void {
		global $wpdb;
		$wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$this->mc['map']}` (kind, old_id, new_id, created) VALUES (%s, %d, %d, %d)", $kind, $old, $new, $created ? 1 : 0)); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @return array<int,int> old ID => new ID
	 */
	private function map_get(string $kind, array $old_ids, bool $created_only = false): array {
		global $wpdb;
		$old_ids = array_values(array_unique(array_filter(array_map('intval', $old_ids))));
		if (empty($old_ids)) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT old_id, new_id FROM `{$this->mc['map']}` WHERE kind = %s AND old_id IN (" . implode(',', $old_ids) . ')' . ($created_only ? ' AND created = 1' : ''), $kind), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$out = array();
		foreach ((array) $rows as $row) {
			$out[(int) $row['old_id']] = (int) $row['new_id'];
		}
		return $out;
	}

	/**
	 * Keep only the columns the live table has (the source may have extra ones).
	 */
	private function live_row(string $table, array $row): array {
		static $cols = array();
		if (! isset($cols[$table])) {
			$cols[$table] = array();
			foreach ($this->table_columns($table) as $col) {
				if (! $col['generated']) {
					$cols[$table][$col['name']] = true;
				}
			}
		}
		return array_intersect_key($row, $cols[$table]);
	}

	private function merge_insert(string $table, array $row): int {
		global $wpdb;
		if (false === $wpdb->insert($table, $this->live_row($table, $row))) {
			throw new \RuntimeException('Could not add a row to ' . $table . ': ' . $wpdb->last_error);
		}
		$wpdb->queries = array();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Run an INSERT … SELECT over a source table in ID ranges, bounded in time.
	 *
	 * @param string $sql Statement with two %d placeholders for the range (exclusive, inclusive).
	 */
	private function merge_range(string $tmp, string $id_col, int $cursor, string $sql): array {
		global $wpdb;
		$max = (int) $wpdb->get_var("SELECT MAX(`{$id_col}`) FROM `{$tmp}`"); // phpcs:ignore WordPress.DB.PreparedSQL
		while ($cursor < $max && $this->time_left($this->mc['budget'])) {
			$to = $cursor + 5000;
			$wpdb->query(sprintf($sql, $cursor, $to)); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Merge failed: ' . substr($wpdb->last_error, 0, 300));
			}
			$cursor = $to;
		}
		return array('done' => $cursor >= $max, 'cursor' => $cursor);
	}

	private function merge_query(string $sql): void {
		global $wpdb;
		$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL
		if ($wpdb->last_error) {
			throw new \RuntimeException('Merge failed: ' . substr($wpdb->last_error, 0, 300));
		}
	}

	private function merge_step_users(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['users'];
		if ('' === $t) {
			return array('done' => true);
		}
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t}` WHERE ID > %d ORDER BY ID LIMIT 200", $cursor), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ((array) $rows as $row) {
			$old = (int) $row['ID'];
			$cursor = $old;
			$existing = 0;
			if ('' !== (string) $row['user_email']) {
				$existing = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 1", $row['user_email']));
			}
			if (! $existing) {
				$existing = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $row['user_login']));
			}
			if ($existing) {
				$this->map_put('u', $old, $existing, false);
			} elseif ($this->merge_has('users')) {
				unset($row['ID']);
				$new = $this->merge_insert($wpdb->users, $row);
				$this->map_put('u', $old, $new, true);
				$stats['users']++;
			} elseif ($this->mc['fallback']) {
				$this->map_put('u', $old, $this->mc['fallback'], false);
			}
		}
		return array('done' => count((array) $rows) < 200, 'cursor' => $cursor);
	}

	private function merge_step_usermeta(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['usermeta'];
		if ('' === $t || ! $this->merge_has('users')) {
			return array('done' => true);
		}
		$src = esc_sql($this->mc['src_pfx']);
		$len = strlen($this->mc['src_pfx']);
		$dst = esc_sql($wpdb->prefix);
		// Role and capability keys carry the table prefix; rename them for this site.
		$key = '' === $src ? 'o.meta_key' : "IF(LEFT(o.meta_key, {$len}) = '{$src}', CONCAT('{$dst}', SUBSTRING(o.meta_key, " . ($len + 1) . ')), o.meta_key)';
		$sql = "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT m.new_id, {$key}, o.meta_value FROM `{$t}` o"
			. " JOIN `{$this->mc['map']}` m ON m.kind = 'u' AND m.old_id = o.user_id AND m.created = 1"
			. " WHERE o.umeta_id > %d AND o.umeta_id <= %d AND o.meta_key <> 'session_tokens'";
		return $this->merge_range($t, 'umeta_id', $cursor, $sql);
	}

	private function merge_step_terms(int $cursor, array &$stats): array {
		global $wpdb;
		$tt = $this->mc['tmp']['term_taxonomy'];
		$terms = $this->mc['tmp']['terms'];
		if ('' === $tt || '' === $terms) {
			return array('done' => true);
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, t.name, t.slug, t.term_group FROM `{$tt}` tt JOIN `{$terms}` t ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id > %d ORDER BY tt.term_taxonomy_id LIMIT 300", $cursor), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		foreach ((array) $rows as $row) {
			$cursor = (int) $row['term_taxonomy_id'];
			$existing = $wpdb->get_row(
				$wpdb->prepare("SELECT tt.term_id, tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1", $row['taxonomy'], $row['slug']),
				ARRAY_A
			);
			if ($existing) {
				$this->map_put('t', (int) $row['term_id'], (int) $existing['term_id'], false);
				$this->map_put('x', (int) $row['term_taxonomy_id'], (int) $existing['term_taxonomy_id'], false);
			} elseif ($this->merge_has('terms')) {
				$term_id = $this->merge_insert($wpdb->terms, array('name' => $row['name'], 'slug' => $row['slug'], 'term_group' => $row['term_group']));
				$tt_id = $this->merge_insert($wpdb->term_taxonomy, array('term_id' => $term_id, 'taxonomy' => $row['taxonomy'], 'description' => $row['description'], 'parent' => 0, 'count' => 0));
				$this->map_put('t', (int) $row['term_id'], $term_id, true);
				$this->map_put('x', (int) $row['term_taxonomy_id'], $tt_id, true);
				$stats['terms']++;
			}
		}
		return array('done' => count((array) $rows) < 300, 'cursor' => $cursor);
	}

	private function merge_step_term_parents(int $cursor, array &$stats): array {
		global $wpdb;
		$tt = $this->mc['tmp']['term_taxonomy'];
		if ('' !== $tt) {
			$m = $this->mc['map'];
			$this->merge_query("UPDATE {$wpdb->term_taxonomy} l JOIN `{$m}` mx ON mx.kind = 'x' AND mx.new_id = l.term_taxonomy_id AND mx.created = 1 JOIN `{$tt}` o ON o.term_taxonomy_id = mx.old_id JOIN `{$m}` mt ON mt.kind = 't' AND mt.old_id = o.parent SET l.parent = mt.new_id WHERE o.parent > 0");
		}
		return array('done' => true);
	}

	private function merge_step_termmeta(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['termmeta'];
		if ('' === $t) {
			return array('done' => true);
		}
		$sql = "INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value) SELECT m.new_id, o.meta_key, o.meta_value FROM `{$t}` o"
			. " JOIN `{$this->mc['map']}` m ON m.kind = 't' AND m.old_id = o.term_id AND m.created = 1"
			. ' WHERE o.meta_id > %d AND o.meta_id <= %d';
		return $this->merge_range($t, 'meta_id', $cursor, $sql);
	}

	/**
	 * Map posts that an earlier merge from the same source already added, so they are skipped.
	 */
	private function merge_step_posts_seen(int $cursor, array &$stats): array {
		global $wpdb;
		$prefix = $this->mc['src_key'] . ':';
		$this->merge_query($wpdb->prepare(
			"INSERT IGNORE INTO `{$this->mc['map']}` (kind, old_id, new_id, created) SELECT 'p', CAST(SUBSTRING(pm.meta_value, %d) AS UNSIGNED), pm.post_id, 0 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wudt_merge_source' AND pm.meta_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
			strlen($prefix) + 1,
			$wpdb->esc_like($prefix) . '%'
		));
		return array('done' => true);
	}

	private function merge_step_posts(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['posts'];
		if ('' === $t || ! $this->merge_has('posts')) {
			return array('done' => true);
		}
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t}` WHERE ID > %d ORDER BY ID LIMIT 200", $cursor), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		$seen = $this->map_get('p', array_column($rows, 'ID'));
		$authors = $this->map_get('u', array_column($rows, 'post_author'));
		foreach ($rows as $row) {
			$old = (int) $row['ID'];
			$cursor = $old;
			if (isset($seen[$old])) {
				$stats['skipped']++;
				continue;
			}
			// Revisions and unsaved drafts are not content worth duplicating.
			if ('revision' === $row['post_type'] || 'auto-draft' === $row['post_status']) {
				continue;
			}
			unset($row['ID']);
			$row['post_author'] = $authors[(int) $row['post_author']] ?? $this->mc['fallback'];
			$row['post_parent'] = 0; // Set once every post has its new ID.
			$new = $this->merge_insert($wpdb->posts, $row);
			if ('' !== (string) $row['post_name'] && $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d LIMIT 1", $row['post_name'], $row['post_type'], $new))) {
				$slug = wp_unique_post_slug((string) $row['post_name'], $new, (string) $row['post_status'], (string) $row['post_type'], 0);
				$wpdb->update($wpdb->posts, array('post_name' => $slug), array('ID' => $new));
			}
			$wpdb->insert($wpdb->postmeta, array('post_id' => $new, 'meta_key' => '_wudt_merge_source', 'meta_value' => $this->mc['src_key'] . ':' . $old)); // phpcs:ignore WordPress.DB.SlowDBQuery
			$this->map_put('p', $old, $new, true);
			$stats['posts']++;
		}
		return array('done' => count($rows) < 200, 'cursor' => $cursor);
	}

	private function merge_step_post_parents(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['posts'];
		if ('' !== $t) {
			$m = $this->mc['map'];
			$this->merge_query("UPDATE {$wpdb->posts} l JOIN `{$m}` mp ON mp.kind = 'p' AND mp.new_id = l.ID AND mp.created = 1 JOIN `{$t}` o ON o.ID = mp.old_id JOIN `{$m}` pp ON pp.kind = 'p' AND pp.old_id = o.post_parent SET l.post_parent = pp.new_id WHERE o.post_parent > 0");
		}
		return array('done' => true);
	}

	private function merge_step_postmeta(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['postmeta'];
		if ('' === $t) {
			return array('done' => true);
		}
		$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT m.new_id, o.meta_key, o.meta_value FROM `{$t}` o"
			. " JOIN `{$this->mc['map']}` m ON m.kind = 'p' AND m.old_id = o.post_id AND m.created = 1"
			. " WHERE o.meta_id > %d AND o.meta_id <= %d AND o.meta_key NOT IN ('_edit_lock', '_wudt_merge_source')";
		return $this->merge_range($t, 'meta_id', $cursor, $sql);
	}

	/**
	 * Point meta values that hold post, term or user IDs at the new IDs.
	 */
	private function merge_step_postmeta_ids(int $cursor, array &$stats): array {
		global $wpdb;
		$m = $this->mc['map'];
		$pm = $wpdb->postmeta;
		$own = "JOIN `{$m}` mp ON mp.kind = 'p' AND mp.new_id = l.post_id AND mp.created = 1";
		$num = "l.meta_value REGEXP '^[0-9]+$'";

		// A featured image that was not migrated would point at an unrelated post here.
		$this->merge_query("DELETE l FROM {$pm} l {$own} LEFT JOIN `{$m}` ma ON ma.kind = 'p' AND ma.old_id = CAST(l.meta_value AS UNSIGNED) WHERE l.meta_key = '_thumbnail_id' AND ma.old_id IS NULL");
		$this->merge_query("UPDATE {$pm} l {$own} JOIN `{$m}` ma ON ma.kind = 'p' AND ma.old_id = CAST(l.meta_value AS UNSIGNED) SET l.meta_value = ma.new_id WHERE l.meta_key IN ('_thumbnail_id', '_menu_item_menu_item_parent') AND {$num}");
		$this->merge_query("UPDATE {$pm} l {$own} JOIN {$pm} ty ON ty.post_id = l.post_id AND ty.meta_key = '_menu_item_type' JOIN `{$m}` ma ON ma.kind = IF(ty.meta_value = 'taxonomy', 't', 'p') AND ma.old_id = CAST(l.meta_value AS UNSIGNED) SET l.meta_value = ma.new_id WHERE l.meta_key = '_menu_item_object_id' AND ty.meta_value IN ('post_type', 'taxonomy') AND {$num}");
		$this->merge_query("UPDATE {$pm} l {$own} JOIN `{$m}` mu ON mu.kind = 'u' AND mu.old_id = CAST(l.meta_value AS UNSIGNED) SET l.meta_value = mu.new_id WHERE l.meta_key = '_edit_last' AND {$num}");

		// WooCommerce product galleries: comma-separated attachment IDs.
		$rows = (array) $wpdb->get_results("SELECT l.meta_id, l.meta_value FROM {$pm} l {$own} WHERE l.meta_key = '_product_image_gallery' AND l.meta_value <> ''", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ($rows as $row) {
			$ids = array_filter(array_map('intval', explode(',', (string) $row['meta_value'])));
			$mapped = $this->map_get('p', $ids);
			$new = array();
			foreach ($ids as $id) {
				if (isset($mapped[$id])) {
					$new[] = $mapped[$id];
				}
			}
			$wpdb->update($pm, array('meta_value' => implode(',', $new)), array('meta_id' => (int) $row['meta_id'])); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array('done' => true);
	}

	private function merge_step_relationships(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['term_relationships'];
		if ('' !== $t) {
			$m = $this->mc['map'];
			$this->merge_query("INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) SELECT mp.new_id, mx.new_id, o.term_order FROM `{$t}` o JOIN `{$m}` mp ON mp.kind = 'p' AND mp.old_id = o.object_id AND mp.created = 1 JOIN `{$m}` mx ON mx.kind = 'x' AND mx.old_id = o.term_taxonomy_id");
		}
		return array('done' => true);
	}

	private function merge_step_comments(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['comments'];
		if ('' === $t || ! $this->merge_has('comments')) {
			return array('done' => true);
		}
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t}` WHERE comment_ID > %d ORDER BY comment_ID LIMIT 300", $cursor), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		// Only comments of posts added by this merge, so a repeated merge adds no duplicates.
		$posts = $this->map_get('p', array_column($rows, 'comment_post_ID'), true);
		$users = $this->map_get('u', array_column($rows, 'user_id'));
		foreach ($rows as $row) {
			$old = (int) $row['comment_ID'];
			$cursor = $old;
			if (! isset($posts[(int) $row['comment_post_ID']])) {
				continue;
			}
			unset($row['comment_ID']);
			$row['comment_post_ID'] = $posts[(int) $row['comment_post_ID']];
			$row['user_id'] = $users[(int) $row['user_id']] ?? 0;
			$row['comment_parent'] = 0;
			$new = $this->merge_insert($wpdb->comments, $row);
			$this->map_put('c', $old, $new, true);
			$stats['comments']++;
		}
		return array('done' => count($rows) < 300, 'cursor' => $cursor);
	}

	private function merge_step_comment_parents(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['comments'];
		if ('' !== $t) {
			$m = $this->mc['map'];
			$this->merge_query("UPDATE {$wpdb->comments} l JOIN `{$m}` mc ON mc.kind = 'c' AND mc.new_id = l.comment_ID AND mc.created = 1 JOIN `{$t}` o ON o.comment_ID = mc.old_id JOIN `{$m}` pc ON pc.kind = 'c' AND pc.old_id = o.comment_parent SET l.comment_parent = pc.new_id WHERE o.comment_parent > 0");
		}
		return array('done' => true);
	}

	private function merge_step_commentmeta(int $cursor, array &$stats): array {
		global $wpdb;
		$t = $this->mc['tmp']['commentmeta'];
		if ('' === $t) {
			return array('done' => true);
		}
		$sql = "INSERT INTO {$wpdb->commentmeta} (comment_id, meta_key, meta_value) SELECT m.new_id, o.meta_key, o.meta_value FROM `{$t}` o"
			. " JOIN `{$this->mc['map']}` m ON m.kind = 'c' AND m.old_id = o.comment_id AND m.created = 1"
			. ' WHERE o.meta_id > %d AND o.meta_id <= %d';
		return $this->merge_range($t, 'meta_id', $cursor, $sql);
	}

	private function merge_step_recount(int $cursor, array &$stats): array {
		$this->merge_recount($this->mc['map']);
		return array('done' => true);
	}

	private function merge_recount(string $map): void {
		global $wpdb;
		$this->merge_query("UPDATE {$wpdb->term_taxonomy} tt JOIN `{$map}` mx ON mx.kind = 'x' AND mx.new_id = tt.term_taxonomy_id SET tt.count = (SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)");
		$this->merge_query("UPDATE {$wpdb->posts} p JOIN `{$map}` mp ON mp.kind = 'p' AND mp.new_id = p.ID AND mp.created = 1 SET p.comment_count = (SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1')");
	}

	/**
	 * Delete everything a merge added (used by rollback).
	 */
	private function rollback_merge(string $map): int {
		global $wpdb;
		if ($map !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map))) {
			return 0;
		}
		$removed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$map}` WHERE created = 1"); // phpcs:ignore WordPress.DB.PreparedSQL
		$deletes = array(
			array($wpdb->postmeta, 'post_id', 'p'),
			array($wpdb->term_relationships, 'object_id', 'p'),
			array($wpdb->posts, 'ID', 'p'),
			array($wpdb->commentmeta, 'comment_id', 'c'),
			array($wpdb->comments, 'comment_ID', 'c'),
			array($wpdb->termmeta, 'term_id', 't'),
			array($wpdb->term_taxonomy, 'term_taxonomy_id', 'x'),
			array($wpdb->terms, 'term_id', 't'),
			array($wpdb->usermeta, 'user_id', 'u'),
			array($wpdb->users, 'ID', 'u'),
		);
		foreach ($deletes as $d) {
			$this->merge_query("DELETE l FROM {$d[0]} l JOIN `{$map}` m ON m.kind = '{$d[2]}' AND m.created = 1 AND m.new_id = l.`{$d[1]}`");
		}
		$this->merge_recount($map);
		$wpdb->query("DROP TABLE IF EXISTS `{$map}`"); // phpcs:ignore WordPress.DB.PreparedSQL
		return $removed;
	}

	/* ---------------------------------------------------------------------
	 * Rollback
	 * ------------------------------------------------------------------- */

	private function rollback_meta_file(): string {
		return self::storage_dir('rollback') . '/meta.json';
	}

	private function rollback_set_matches(string $job_id): bool {
		$meta = $this->read_json($this->rollback_meta_file());
		return ($meta['job_id'] ?? '') === $job_id;
	}

	/**
	 * Start a new rollback set, discarding the previous one.
	 */
	private function start_rollback_set(string $job_id): void {
		$this->discard_rollback();
		self::storage_dir('rollback');
		file_put_contents($this->rollback_meta_file(), wp_json_encode(array(
			'job_id'  => $job_id,
			'created' => time(),
			'tables'  => array(),
		)));
	}

	private function write_rollback_tables(array $tables): void {
		$meta = $this->read_json($this->rollback_meta_file());
		$meta['tables'] = array_merge((array) ($meta['tables'] ?? array()), $tables);
		file_put_contents($this->rollback_meta_file(), wp_json_encode($meta));
	}

	public function rollback_summary(): array {
		$meta = $this->read_json($this->rollback_meta_file());
		if (empty($meta['job_id'])) {
			return array('available' => false);
		}
		$files = 0;
		$log = self::storage_dir('rollback') . '/files.log';
		if (file_exists($log)) {
			$files = count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array());
		}
		return array(
			'available' => true,
			'job_id'    => $meta['job_id'],
			'created'   => (int) ($meta['created'] ?? 0),
			'tables'    => count((array) ($meta['tables'] ?? array())),
			'files'     => $files,
		);
	}

	/**
	 * Restore the tables and files replaced by the last migration.
	 */
	public function rollback(): array {
		global $wpdb;
		$meta = $this->read_json($this->rollback_meta_file());
		if (empty($meta['job_id'])) {
			throw new \RuntimeException('There is no migration to roll back on this site.');
		}

		$preserved = $this->collect_preserved_options();
		$this->prepare_session();

		$renames = array();
		$drop_after = array();
		foreach ((array) ($meta['tables'] ?? array()) as $dest => $bak) {
			$dest = (string) $dest;
			if ('' === $bak) {
				$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($dest) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
				continue;
			}
			if ($bak !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $bak))) {
				continue;
			}
			$discard = self::TMP_PREFIX . 'rb' . substr(md5($dest . microtime()), 0, 10);
			$renames[] = '`' . esc_sql($dest) . '` TO `' . $discard . '`';
			$renames[] = '`' . $bak . '` TO `' . esc_sql($dest) . '`';
			$drop_after[] = $discard;
		}
		if (! empty($renames)) {
			$wpdb->query('RENAME TABLE ' . implode(', ', $renames)); // phpcs:ignore WordPress.DB.PreparedSQL
			if ($wpdb->last_error) {
				throw new \RuntimeException('Rollback failed: ' . $wpdb->last_error);
			}
			foreach ($drop_after as $t) {
				$wpdb->query('DROP TABLE IF EXISTS `' . $t . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}

		$merged = empty($meta['merge_map']) ? 0 : $this->rollback_merge((string) $meta['merge_map']);

		// Restore preserved plugin options (the restored tables are older).
		foreach ($preserved as $row) {
			if (in_array($row['option_name'], array('siteurl', 'home'), true)) {
				continue;
			}
			$wpdb->replace($wpdb->options, $row);
		}

		$restored = 0;
		$log = self::storage_dir('rollback') . '/files.log';
		if (file_exists($log)) {
			$lines = array_reverse(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array());
			foreach ($lines as $line) {
				$entry = json_decode($line, true);
				if (! is_array($entry) || empty($entry['t'])) {
					continue;
				}
				$target = (string) $entry['t'];
				$backup = (string) ($entry['b'] ?? '');
				if ('' === $backup) {
					@unlink($target);
				} elseif (is_file($backup)) {
					@unlink($target);
					self::move_file($backup, $target);
				}
				$restored++;
			}
		}

		$this->clear_elementor_css_files();
		wp_cache_flush();
		self::schedule_rewrite_flush();
		$this->discard_rollback(false);

		return array('tables' => count((array) ($meta['tables'] ?? array())), 'files' => $restored, 'merged_removed' => $merged);
	}

	public function discard_rollback(bool $drop_tables = true): void {
		global $wpdb;
		$meta = $this->read_json($this->rollback_meta_file());
		if ($drop_tables) {
			foreach ((array) ($meta['tables'] ?? array()) as $bak) {
				if ('' !== $bak && 0 === strpos((string) $bak, self::BAK_PREFIX)) {
					$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql((string) $bak) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			}
			$map = (string) ($meta['merge_map'] ?? '');
			if (0 === strpos($map, self::BAK_PREFIX)) {
				$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($map) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
		self::delete_tree(self::storage_dir() . '/rollback');
	}

	/* ---------------------------------------------------------------------
	 * Cleanup
	 * ------------------------------------------------------------------- */

	/**
	 * Remove temporary tables and staged files for a job.
	 */
	public function cleanup(string $job_id): array {
		global $wpdb;
		$job_id = self::sanitize_job_id($job_id);
		$dropped = 0;
		// Temporary tables of this job are those whose names hash from this job id; drop any
		// leftover temp tables older jobs may have left behind as well.
		$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::TMP_PREFIX) . '%'));
		foreach ((array) $tables as $t) {
			$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql((string) $t) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
			$dropped++;
		}
		self::delete_tree(self::storage_dir() . '/stage-' . $job_id);
		foreach ((array) glob(self::storage_dir('work') . '/' . $job_id . '-*') as $f) {
			@unlink((string) $f);
		}
		return array('dropped' => $dropped);
	}

	public static function delete_tree(string $path): void {
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (! is_dir($path)) {
			return;
		}
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iter as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($path);
	}

	/* ---------------------------------------------------------------------
	 * Misc helpers
	 * ------------------------------------------------------------------- */

	private function assert_own_table(string $table): void {
		global $wpdb;
		if (! preg_match('/^[A-Za-z0-9_$]{1,64}$/', $table) || 0 !== strpos($table, $wpdb->prefix)) {
			throw new \RuntimeException('Table is not part of this site: ' . $table);
		}
	}

	private function table_columns(string $table): array {
		global $wpdb;
		$rows = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . esc_sql($table) . '`', ARRAY_A);
		$cols = array();
		foreach ((array) $rows as $row) {
			$extra = strtolower((string) ($row['Extra'] ?? ''));
			$cols[] = array(
				'name'      => (string) $row['Field'],
				'type'      => strtolower((string) $row['Type']),
				'generated' => (false !== strpos($extra, 'generated') || false !== strpos($extra, 'virtual') || false !== strpos($extra, 'persistent')),
			);
		}
		return $cols;
	}

	private function primary_key(string $table): array {
		global $wpdb;
		$rows = $wpdb->get_results('SHOW KEYS FROM `' . esc_sql($table) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A);
		$cols = array();
		foreach ((array) $rows as $row) {
			$cols[(int) $row['Seq_in_index']] = (string) $row['Column_name'];
		}
		ksort($cols);
		return array_values($cols);
	}

	private function is_integer_column(array $columns, string $name): bool {
		foreach ($columns as $col) {
			if ($col['name'] === $name) {
				return (bool) preg_match('/^(tiny|small|medium|big)?int/', $col['type']);
			}
		}
		return false;
	}

	public static function is_utf8(string $value): bool {
		return function_exists('mb_check_encoding') ? mb_check_encoding($value, 'UTF-8') : (bool) preg_match('//u', $value);
	}

	private function read_json(string $file): array {
		if (! file_exists($file)) {
			return array();
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : array();
	}
}
