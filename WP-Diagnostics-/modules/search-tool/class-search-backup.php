<?php
/**
 * Search & Replace undo backups
 * Stores the original content of every file and database cell a replace run changes.
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Backup
 *
 * Layout: uploads/wudt-search-backups/<id>/manifest.json, rows.json, files/<n>.bak
 */
class Search_Backup {
	private const KEEP = 20;

	private string $id;
	private string $dir;

	/**
	 * @var array<string,mixed>
	 */
	private array $manifest;

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private array $rows = array();

	/**
	 * @param array<string,mixed> $meta search, replace, target, options
	 */
	public function __construct(array $meta) {
		$this->id       = gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
		$this->dir      = self::base_dir() . '/' . $this->id;
		$this->manifest = array(
			'id'         => $this->id,
			'created'    => time(),
			'user'       => get_current_user_id(),
			'search'     => (string) ($meta['search'] ?? ''),
			'replace'    => (string) ($meta['replace'] ?? ''),
			'target'     => (string) ($meta['target'] ?? ''),
			'files'      => array(),
			'rows_count' => 0,
			'restored'   => 0,
		);
	}

	public function get_id(): string {
		return $this->id;
	}

	/**
	 * @throws \RuntimeException When the backup folder can't be written.
	 */
	public function add_file(string $relative_path, string $content, string $new_content): void {
		$this->ensure_dir();
		$index = count($this->manifest['files']);
		$name  = $index . '.bak';
		if (false === file_put_contents($this->dir . '/files/' . $name, $content)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException(__('Could not write the undo backup.', 'diagnostics-toolkit'));
		}
		$this->manifest['files'][] = array(
			'path' => $relative_path,
			'blob' => $name,
			'hash' => md5($new_content),
		);
	}

	public function add_row(string $table, string $column, string $primary_key, string $row_id, string $old_value, string $new_value): void {
		$this->rows[] = array(
			'table'  => $table,
			'column' => $column,
			'pk'     => $primary_key,
			'id'     => $row_id,
			'old'    => $old_value,
			'hash'   => md5($new_value),
		);
	}

	/**
	 * Write the manifest. Returns false when nothing was backed up.
	 *
	 * @throws \RuntimeException
	 */
	public function save(): bool {
		if (empty($this->manifest['files']) && empty($this->rows)) {
			return false;
		}
		$this->ensure_dir();
		$this->manifest['rows_count'] = count($this->rows);
		if ($this->rows && false === file_put_contents($this->dir . '/rows.json', wp_json_encode($this->rows))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException(__('Could not write the undo backup.', 'diagnostics-toolkit'));
		}
		file_put_contents($this->dir . '/manifest.json', wp_json_encode($this->manifest)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		self::prune();
		return true;
	}

	private function ensure_dir(): void {
		if (is_dir($this->dir . '/files')) {
			return;
		}
		self::protect_base();
		if (! wp_mkdir_p($this->dir . '/files')) {
			throw new \RuntimeException(__('Could not create the undo backup folder.', 'diagnostics-toolkit'));
		}
	}

	public static function base_dir(): string {
		return wp_normalize_path(wp_upload_dir(null, false)['basedir']) . '/wudt-search-backups';
	}

	private static function protect_base(): void {
		$base = self::base_dir();
		wp_mkdir_p($base);
		if (! file_exists($base . '/.htaccess')) {
			file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if (! file_exists($base . '/index.php')) {
			file_put_contents($base . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if (! file_exists($base . '/web.config')) {
			file_put_contents($base . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	private static function valid_id(string $id): bool {
		return 1 === preg_match('/^\d{8}-\d{6}-[A-Za-z0-9]{6}$/', $id);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function read_manifest(string $id): ?array {
		if (! self::valid_id($id)) {
			return null;
		}
		$file = self::base_dir() . '/' . $id . '/manifest.json';
		if (! is_readable($file)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array($data) ? $data : null;
	}

	/**
	 * Backups, newest first, without file contents.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_backups(): array {
		$base = self::base_dir();
		if (! is_dir($base)) {
			return array();
		}
		$ids = array();
		foreach ((array) scandir($base) as $entry) {
			if (self::valid_id((string) $entry)) {
				$ids[] = (string) $entry;
			}
		}
		rsort($ids);

		$list = array();
		foreach ($ids as $id) {
			$manifest = self::read_manifest($id);
			if (! $manifest) {
				continue;
			}
			$list[] = array(
				'id'       => $id,
				'created'  => (int) $manifest['created'],
				'search'   => Search_Matcher::clip((string) $manifest['search'], 120),
				'replace'  => Search_Matcher::clip((string) $manifest['replace'], 120),
				'target'   => (string) $manifest['target'],
				'files'    => count((array) $manifest['files']),
				'rows'     => (int) $manifest['rows_count'],
				'restored' => (int) $manifest['restored'],
			);
		}
		return $list;
	}

	/**
	 * Put every backed-up file and cell back. Items changed again since the
	 * replace are skipped unless $force is set.
	 *
	 * @return array<string,mixed>
	 */
	public static function restore(string $id, bool $force = false): array {
		global $wpdb;

		$manifest = self::read_manifest($id);
		if (! $manifest) {
			return array('success' => false, 'error' => __('Backup not found.', 'diagnostics-toolkit'));
		}

		$dir      = self::base_dir() . '/' . $id;
		$restored = array('files' => 0, 'rows' => 0);
		$skipped  = array();

		foreach ((array) $manifest['files'] as $entry) {
			$relative = (string) $entry['path'];
			try {
				$target = Security_Guard::normalize_inside_wp(ABSPATH . $relative);
			} catch (\RuntimeException $e) {
				$skipped[] = $relative;
				continue;
			}
			$blob = $dir . '/files/' . basename((string) $entry['blob']);
			$data = is_readable($blob) ? file_get_contents($blob) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$changed = ! $force && is_file($target) && md5_file($target) !== $entry['hash'];
			if (false === $data || $changed || ! wp_is_writable($target)) {
				$skipped[] = $relative;
				continue;
			}
			if (false !== file_put_contents($target, $data)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$restored['files']++;
			} else {
				$skipped[] = $relative;
			}
		}

		$rows_file = $dir . '/rows.json';
		$rows      = is_readable($rows_file) ? json_decode((string) file_get_contents($rows_file), true) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$db        = new DB_Search();

		foreach ((array) $rows as $row) {
			$table  = (string) $row['table'];
			$column = (string) $row['column'];
			$label  = $table . '.' . $column . ' #' . $row['id'];

			if (! $db->is_valid_table($table) || $db->get_primary_key($table) !== $row['pk'] || ! in_array($column, $db->get_text_columns($table), true)) {
				$skipped[] = $label;
				continue;
			}
			$current = $db->fetch_value($table, $column, (string) $row['id']);
			if (null === $current || (! $force && md5($current) !== $row['hash'])) {
				$skipped[] = $label;
				continue;
			}
			$result = $wpdb->update($table, array($column => (string) $row['old']), array((string) $row['pk'] => (string) $row['id']), array('%s'), array('%s'));
			if (false !== $result) {
				$restored['rows']++;
			} else {
				$skipped[] = $label;
			}
		}

		if ($restored['rows'] > 0) {
			wp_cache_flush();
		}

		$manifest['restored'] = time();
		file_put_contents($dir . '/manifest.json', wp_json_encode($manifest)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array(
			'success'        => true,
			'files_restored' => $restored['files'],
			'rows_restored'  => $restored['rows'],
			'skipped'        => $skipped,
		);
	}

	public static function delete(string $id): bool {
		if (! self::valid_id($id)) {
			return false;
		}
		$dir = self::base_dir() . '/' . $id;
		if (! is_dir($dir)) {
			return false;
		}
		self::remove_dir($dir);
		return ! is_dir($dir);
	}

	private static function prune(): void {
		$list = self::list_backups();
		foreach (array_slice($list, self::KEEP) as $old) {
			self::delete((string) $old['id']);
		}
	}

	private static function remove_dir(string $dir): void {
		foreach ((array) scandir($dir) as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_dir($path)) {
				self::remove_dir($path);
			} else {
				wp_delete_file($path);
			}
		}
		@rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
