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
	public const COMPONENTS = array('plugins', 'themes', 'uploads', 'mu-plugins', 'languages');

	private const TMP_PREFIX = 'wudt_tmp_';
	private const BAK_PREFIX = 'wudt_bak_';
	private const MAX_INSERT_BYTES = 900000;

	private float $started;

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
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ((array) get_plugins() as $file => $plugin) {
			$plugins[$file] = (string) ($plugin['Version'] ?? '');
		}
		$themes = array();
		foreach (wp_get_themes() as $slug => $theme) {
			$themes[(string) $slug] = (string) $theme->get('Version');
		}

		$uploads = wp_get_upload_dir();

		return array(
			'api_version'     => self::API_VERSION,
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
	public function import_table_chunk(string $job_id, array $chunk, string $source_prefix): array {
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
		}
		throw new \RuntimeException('Unknown component: ' . $component);
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
	public function manifest_page(string $job_id, string $component, int $offset, array $excludes = array(), float $budget = 12.0, int $max_entries = 3000): array {
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
			$entries[] = array(
				'p' => $line,
				's' => (int) filesize($abs),
				'h' => (string) md5_file($abs),
			);
			$next++;
		}
		$eof = feof($handle) && count($entries) < $max_entries;
		fclose($handle);
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
	public function diff_entries(string $component, array $entries, float $budget = 15.0): array {
		$root = self::component_root($component);
		$changed = array();
		$checked = 0;
		foreach ($entries as $entry) {
			$rel = self::safe_rel((string) ($entry['p'] ?? ''));
			$abs = $root . '/' . $rel;
			$size = (int) ($entry['s'] ?? -1);
			if (! is_file($abs) || (int) filesize($abs) !== $size) {
				$changed[] = $entry;
			} elseif (! $this->time_left($budget) || md5_file($abs) !== (string) ($entry['h'] ?? '')) {
				// When out of time we transfer rather than risk skipping a changed file.
				$changed[] = $entry;
			}
			$checked++;
		}
		return array('changed' => $changed, 'checked' => $checked);
	}

	/**
	 * Read file segments. $requests: [{c: component, p: path, o: offset, l: length}]
	 */
	public function read_files(array $requests): array {
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
				'd'   => base64_encode($data),
				'eof' => $eof,
			);
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
			$data = base64_decode((string) ($seg['d'] ?? ''), true);
			if (false === $data) {
				throw new \RuntimeException('Corrupt file data received for ' . $rel);
			}
			$offset = max(0, (int) ($seg['o'] ?? 0));
			$fh = fopen($target, 0 === $offset ? 'wb' : 'c+b');
			if (! $fh) {
				throw new \RuntimeException('Unable to write staged file ' . $rel);
			}
			if ($offset > 0) {
				fseek($fh, $offset);
			}
			fwrite($fh, $data);
			fclose($fh);
			$written += strlen($data);

			if (! empty($seg['eof'])) {
				clearstatcache(true, $target);
				if (! empty($seg['h']) && md5_file($target) !== (string) $seg['h']) {
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
		while (($line = fgets($handle)) !== false) {
			if ($line_no++ < $offset) {
				continue;
			}
			if (! $this->time_left($budget)) {
				$done = false;
				break;
			}
			$next++;
			$line = rtrim($line, "\r\n");
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
					throw new \RuntimeException('Unable to replace ' . $component . '/' . $rel . ' (file is locked or not writable).');
				}
			}
			wp_mkdir_p(dirname($target));
			if (! self::move_file($src, $target)) {
				if ('' !== $backup) {
					self::move_file($backup, $target);
				}
				fclose($handle);
				throw new \RuntimeException('Unable to write ' . $component . '/' . $rel . '. Check file permissions.');
			}
			file_put_contents($rb_log, wp_json_encode(array('t' => $target, 'b' => $backup)) . "\n", FILE_APPEND | LOCK_EX);
			$moved++;
		}
		fclose($handle);

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

		return array('tables' => count((array) ($meta['tables'] ?? array())), 'files' => $restored);
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
