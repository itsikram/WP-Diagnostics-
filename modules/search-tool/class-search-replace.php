<?php
/**
 * Search & Replace Engine
 * Preview, selective apply and undo backups for database replacements
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Replace
 *
 * Database values are replaced with serialized data unpacked first, so
 * string lengths stay valid.
 */
class Search_Replace {
	private const PREVIEW_ITEMS   = 300;
	private const PREVIEW_SAMPLES = 5;

	private DB_Search $db_search;

	public function __construct() {
		$this->db_search = new DB_Search();
	}

	/* ------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function preview_db_replacements(string $search_text, string $replace_text, array $options): array {
		$search = $this->db_search->search($search_text, $options);
		if (! $search['success']) {
			return $search;
		}

		$matcher = new Search_Matcher($search_text, $options);
		$items   = array();
		$total   = 0;
		$changes = 0;

		foreach ($search['results'] as $row) {
			$item = array(
				'key'           => self::row_key($row),
				'table'         => $row['table'],
				'column'        => $row['column'],
				'row_id'        => $row['row_id'],
				'serialized'    => $row['serialized'],
				'replace_count' => $row['match_count'],
				'can_replace'   => null !== $row['row_id'],
				'reason'        => null === $row['row_id'] ? __('Table has no single-column primary key.', 'diagnostics-toolkit') : '',
			);

			if ($item['can_replace']) {
				$value = $this->db_search->fetch_value($row['table'], $row['column'], (string) $row['row_id']);
				$skip = '';
				try {
					$after = null === $value ? null : $this->replace_value($value, $matcher, $replace_text, $skip);
				} catch (\RuntimeException $e) {
					$after = $value;
					$skip  = $e->getMessage();
				}

				if (null === $value || $after === $value) {
					$item['can_replace'] = false;
					$item['reason']      = $skip ?: __('Nothing would change.', 'diagnostics-toolkit');
				}
			}

			if ($item['can_replace']) {
				$changes++;
				$total += $row['match_count'];
			}

			if (count($items) < self::PREVIEW_ITEMS) {
				$before         = array(
					'segments'  => $row['segments'],
					'cut_start' => $row['cut_start'],
					'cut_end'   => $row['cut_end'],
				);
				$item['before'] = $before;
				$item['after']  = $matcher->replace_segments($before, $replace_text);
				$items[]        = $item;
			}
		}

		return array(
			'success'            => true,
			'items'              => $items,
			'total_items'        => $changes,
			'total_replacements' => $total,
			'truncated'          => $search['truncated'],
		);
	}

	/**
	 * @param array<string,mixed>    $options
	 * @param array<int,string>|null $only_keys Row keys chosen in the preview; null = all.
	 * @return array<string,mixed>
	 */
	public function execute_db_replacements(string $search_text, string $replace_text, array $options, bool $create_backup = true, ?array $only_keys = null): array {
		global $wpdb;

		$search = $this->db_search->search($search_text, $options);
		if (! $search['success']) {
			return $search;
		}

		$matcher  = new Search_Matcher($search_text, $options);
		$backup   = $create_backup ? new Search_Backup(array('search' => $search_text, 'replace' => $replace_text, 'target' => 'database')) : null;
		$selected = null === $only_keys ? null : array_flip($only_keys);
		$modified = 0;
		$count    = 0;
		$errors   = array();

		foreach ($search['results'] as $row) {
			$key = self::row_key($row);
			if (null === $row['row_id'] || (null !== $selected && ! isset($selected[ $key ]))) {
				continue;
			}

			$value = $this->db_search->fetch_value($row['table'], $row['column'], (string) $row['row_id']);
			if (null === $value) {
				continue;
			}

			try {
				$skip  = '';
				$after = $this->replace_value($value, $matcher, $replace_text, $skip);
			} catch (\RuntimeException $e) {
				$errors[] = array('item' => $key, 'error' => $e->getMessage());
				continue;
			}

			if ($after === $value) {
				if ('' !== $skip) {
					$errors[] = array('item' => $key, 'error' => $skip);
				}
				continue;
			}

			$result = $wpdb->update(
				$row['table'],
				array($row['column'] => $after),
				array($row['primary_key'] => (string) $row['row_id']),
				array('%s'),
				array('%s')
			);

			if (false === $result) {
				$errors[] = array('item' => $key, 'error' => $wpdb->last_error ?: __('Update failed.', 'diagnostics-toolkit'));
				continue;
			}

			if ($backup) {
				$backup->add_row($row['table'], $row['column'], (string) $row['primary_key'], (string) $row['row_id'], $value, $after);
			}
			$modified++;
			$count += $row['match_count'];
		}

		if ($modified > 0) {
			wp_cache_flush();
		}

		return array(
			'success'       => true,
			'rows_modified' => $modified,
			'rows_failed'   => count($errors),
			'replacements'  => $count,
			'errors'        => $errors,
			'backup_id'     => $this->save_backup($backup, $errors),
			'truncated'     => $search['truncated'],
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function row_key(array $row): string {
		return $row['table'] . '|' . $row['column'] . '|' . (string) $row['row_id'];
	}

	/**
	 * Replace inside a database value, unpacking serialized data so lengths stay valid.
	 *
	 * @param string $skip Set to a reason when the value is left untouched on purpose.
	 * @throws \RuntimeException
	 */
	private function replace_value(string $value, Search_Matcher $matcher, string $replace, string &$skip): string {
		if (! is_serialized($value, false)) {
			return $matcher->replace($value, $replace);
		}

		$data = @unserialize($value, array('allowed_classes' => array('stdClass'))); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes restricted.
		if (false === $data && 'b:0;' !== $value) {
			$skip = __('Serialized data is corrupt; left untouched.', 'diagnostics-toolkit');
			return $value;
		}
		if ($this->has_incomplete_object($data)) {
			$skip = __('Serialized data contains objects; left untouched.', 'diagnostics-toolkit');
			return $value;
		}

		$changed = false;
		$data    = $this->replace_deep($data, $matcher, $replace, $changed, $skip);
		return $changed ? serialize($data) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private function replace_deep($data, Search_Matcher $matcher, string $replace, bool &$changed, string &$skip) {
		if (is_string($data)) {
			$new = $this->replace_value($data, $matcher, $replace, $skip);
			if ($new !== $data) {
				$changed = true;
			}
			return $new;
		}
		if (is_array($data)) {
			foreach ($data as $key => $item) {
				$data[ $key ] = $this->replace_deep($item, $matcher, $replace, $changed, $skip);
			}
			return $data;
		}
		if ($data instanceof \stdClass) {
			foreach (get_object_vars($data) as $key => $item) {
				$data->$key = $this->replace_deep($item, $matcher, $replace, $changed, $skip);
			}
		}
		return $data;
	}

	/**
	 * @param mixed $data
	 */
	private function has_incomplete_object($data, int $depth = 0): bool {
		if ($depth > 64 || $data instanceof \__PHP_Incomplete_Class) {
			return true;
		}
		if (is_array($data) || $data instanceof \stdClass) {
			foreach ((array) $data as $item) {
				if ((is_array($item) || is_object($item)) && $this->has_incomplete_object($item, $depth + 1)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<int,array<string,string>> $errors
	 */
	private function save_backup(?Search_Backup $backup, array &$errors): string {
		if (! $backup) {
			return '';
		}
		try {
			return $backup->save() ? $backup->get_id() : '';
		} catch (\RuntimeException $e) {
			$errors[] = array('item' => __('Undo backup', 'diagnostics-toolkit'), 'error' => $e->getMessage());
			return '';
		}
	}
}
