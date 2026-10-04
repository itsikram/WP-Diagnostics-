<?php
/**
 * UI state persistence module.
 */

declare(strict_types=1);

namespace WUDT\Modules\State;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

class State_Module extends Module_Base {
	private const META_KEY = 'wudt_file_manager_state';

	public function register_hooks(): void {
		add_action('wp_ajax_wudt_state_get', array($this, 'ajax_get_state'));
		add_action('wp_ajax_wudt_state_save', array($this, 'ajax_save_state'));
	}

	public function get_key(): string {
		return 'state_sync';
	}

	public function get_label(): string {
		return __('State Sync', 'diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		$user_id = get_current_user_id();
		return array(
			'file_manager' => (array) get_user_meta($user_id, self::META_KEY, true),
		);
	}

	public function ajax_get_state(): void {
		Security_Guard::assert_ajax_admin();
		$user_id = get_current_user_id();
		wp_send_json_success(array('state' => (array) get_user_meta($user_id, self::META_KEY, true)));
	}

	public function ajax_save_state(): void {
		Security_Guard::assert_ajax_admin();
		$raw = isset($_POST['state']) ? (array) json_decode(sanitize_text_field(wp_unslash($_POST['state'])), true) : array();
		$state = array(
			'lastPath'   => sanitize_text_field((string) ($raw['lastPath'] ?? '')),
			'openFile'   => sanitize_text_field((string) ($raw['openFile'] ?? '')),
			'editorOpen' => ! empty($raw['editorOpen']),
			'savedAt'    => current_time('mysql'),
		);
		update_user_meta(get_current_user_id(), self::META_KEY, $state);
		wp_send_json_success(array('state' => $state));
	}
}
