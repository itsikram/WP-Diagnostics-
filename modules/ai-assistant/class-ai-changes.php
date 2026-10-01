<?php
/**
 * Change journal for the AI agent: every modification is backed up so it can be undone.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Changes {
	private const OPTION = 'wudt_ai_changes';
	private const MAX = 150;

	public static function backup_dir(): string {
		$dir = wp_normalize_path(WP_CONTENT_DIR) . '/wudt-ai-backups';
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		}
		return $dir;
	}

	/**
	 * @param string $type    file|option|post|elementor|plugin|theme|kit
	 * @param array  $undo    Data needed to undo (small values only; large payloads use $payload).
	 * @param string|null $payload Large backup payload stored on disk.
	 */
	public static function record(string $type, string $target, string $description, array $undo, ?string $payload = null, string $conversation = ''): string {
		$id = 'c' . gmdate('ymdHis') . strtolower(wp_generate_password(5, false, false));
		if (null !== $payload) {
			file_put_contents(self::backup_dir() . '/' . $id . '.bak', $payload);
			$undo['payload'] = $id . '.bak';
		}
		$list = self::all();
		array_unshift($list, array(
			'id'           => $id,
			'time'         => time(),
			'type'         => $type,
			'target'       => $target,
			'description'  => $description,
			'undo'         => $undo,
			'conversation' => $conversation,
			'undone'       => false,
		));
		foreach (array_slice($list, self::MAX) as $old) {
			if (! empty($old['undo']['payload'])) {
				@unlink(self::backup_dir() . '/' . basename((string) $old['undo']['payload']));
			}
		}
		update_option(self::OPTION, array_slice($list, 0, self::MAX), false);
		return $id;
	}

	public static function all(): array {
		$list = get_option(self::OPTION, array());
		return is_array($list) ? $list : array();
	}

	public static function get(string $id): ?array {
		foreach (self::all() as $c) {
			if ($c['id'] === $id) {
				return $c;
			}
		}
		return null;
	}

	private static function payload(array $change): ?string {
		if (empty($change['undo']['payload'])) {
			return null;
		}
		$file = self::backup_dir() . '/' . basename((string) $change['undo']['payload']);
		return file_exists($file) ? (string) file_get_contents($file) : null;
	}

	/**
	 * Mark a change as undone without applying anything (it was already reverted).
	 */
	public static function undo_silently(string $id): void {
		$list = self::all();
		foreach ($list as &$c) {
			if ($c['id'] === $id) {
				$c['undone'] = true;
				$c['description'] .= ' (reverted automatically)';
			}
		}
		update_option(self::OPTION, $list, false);
	}

	/**
	 * Undo a recorded change.
	 */
	public static function undo(string $id): string {
		$change = self::get($id);
		if (! $change) {
			throw new \RuntimeException('Change not found: ' . $id);
		}
		if (! empty($change['undone'])) {
			throw new \RuntimeException('This change was already undone.');
		}
		$u = $change['undo'];
		switch ($change['type']) {
			case 'file':
				$path = (string) $u['path'];
				if (! empty($u['created'])) {
					if (file_exists($path)) {
						@unlink($path);
					}
				} else {
					$content = self::payload($change);
					if (null === $content) {
						throw new \RuntimeException('The backup for this change is missing.');
					}
					wp_mkdir_p(dirname($path));
					if (false === file_put_contents($path, $content)) {
						throw new \RuntimeException('Could not restore ' . $path);
					}
				}
				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($path, true);
				}
				break;
			case 'option':
				if (! empty($u['missing'])) {
					delete_option((string) $u['name']);
				} else {
					update_option((string) $u['name'], maybe_unserialize((string) self::payload($change)));
				}
				break;
			case 'post':
				if (! empty($u['created'])) {
					wp_trash_post((int) $u['id']);
				} else {
					$old = json_decode((string) self::payload($change), true);
					if (is_array($old)) {
						wp_update_post(wp_slash($old));
					}
				}
				break;
			case 'elementor':
				$post_id = (int) $u['id'];
				if (! empty($u['created'])) {
					wp_trash_post($post_id);
				} else {
					$old = json_decode((string) self::payload($change), true);
					if (is_array($old)) {
						update_post_meta($post_id, '_elementor_data', wp_slash((string) $old['data']));
						if (isset($old['settings'])) {
							update_post_meta($post_id, '_elementor_page_settings', $old['settings']);
						}
						if (isset($old['template'])) {
							update_post_meta($post_id, '_wp_page_template', (string) $old['template']);
						}
						delete_post_meta($post_id, '_elementor_css');
					}
				}
				break;
			case 'kit':
				$old = json_decode((string) self::payload($change), true);
				update_post_meta((int) $u['id'], '_elementor_page_settings', is_array($old) ? $old : array());
				delete_post_meta((int) $u['id'], '_elementor_css');
				break;
			case 'plugin':
				if (! function_exists('activate_plugin')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ('deactivate' === $u['revert']) {
					deactivate_plugins((string) $u['plugin']);
				} elseif ('activate' === $u['revert']) {
					$r = activate_plugin((string) $u['plugin']);
					if (is_wp_error($r)) {
						throw new \RuntimeException($r->get_error_message());
					}
				}
				break;
			case 'theme':
				switch_theme((string) $u['stylesheet']);
				break;
			default:
				throw new \RuntimeException('This change cannot be undone automatically.');
		}

		$list = self::all();
		foreach ($list as &$c) {
			if ($c['id'] === $id) {
				$c['undone'] = true;
			}
		}
		update_option(self::OPTION, $list, false);
		return 'Undid: ' . $change['description'];
	}
}
