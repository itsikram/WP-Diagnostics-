<?php
/**
 * Site Migration Module - admin controller for push/pull migrations.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

use WUDT\Includes\Module_Base;
use WUDT\Includes\Security_Guard;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Module extends Module_Base {
	private const OPTION_SITES = 'wudt_migration_sites';

	private Migration_API $api;

	public function __construct() {
		$this->api = new Migration_API();
	}

	public function register_hooks(): void {
		$this->api->register_hooks();
		add_action('init', array(Migration_Engine::class, 'maybe_flush_rewrite'), 999);

		$admin_actions = array(
			'wudt_migration_get_state'        => 'ajax_get_state',
			'wudt_migration_save_site'        => 'ajax_save_site',
			'wudt_migration_delete_site'      => 'ajax_delete_site',
			'wudt_migration_test_connection'  => 'ajax_test_connection',
			'wudt_migration_preflight'        => 'ajax_preflight',
			'wudt_migration_start'            => 'ajax_start',
			'wudt_migration_delete_job'       => 'ajax_delete_job',
			'wudt_migration_regenerate_key'   => 'ajax_regenerate_key',
			'wudt_migration_rollback'         => 'ajax_rollback',
			'wudt_migration_discard_rollback' => 'ajax_discard_rollback',
		);
		foreach ($admin_actions as $action => $method) {
			add_action('wp_ajax_' . $action, array($this, $method));
		}

		// Steps authenticate with the job token: a pull replaces this site's users
		// table, so the admin's login cookie stops being valid mid-job.
		foreach (array('wudt_migration_step' => 'ajax_step', 'wudt_migration_cancel' => 'ajax_cancel') as $action => $method) {
			add_action('wp_ajax_' . $action, array($this, $method));
			add_action('wp_ajax_nopriv_' . $action, array($this, $method));
		}
	}

	public function get_key(): string {
		return 'site_migration';
	}

	public function get_label(): string {
		return __('Site Migration', 'wp-ultimate-diagnostics-toolkit');
	}

	public function get_dashboard_data(): array {
		return $this->build_state();
	}

	private function build_state(): array {
		$sites = array();
		foreach ($this->get_sites() as $site) {
			$sites[] = array(
				'id'         => $site['id'],
				'label'      => $site['label'],
				'url'        => $site['url'],
				'key_hint'   => substr((string) $site['api_key'], 0, 4) . '…' . substr((string) $site['api_key'], -4),
				'last_check' => $site['last_check'] ?? null,
			);
		}
		$active = null;
		$history = array();
		try {
			$active_runner = Migration_Runner::active_job();
			$active = $active_runner ? $active_runner->summary() : null;
			$history = Migration_Runner::history();
		} catch (\Throwable $e) {
			$history = array();
		}
		$engine = new Migration_Engine();
		return array(
			'sites'             => $sites,
			'local_site_url'    => untrailingslashit(home_url()),
			'local_api_key'     => Migration_API::get_local_key(),
			'connection_string' => Migration_API::connection_string(),
			'active_job'        => $active,
			'history'           => $history,
			'rollback'          => $engine->rollback_summary(),
			'components'        => Migration_Engine::COMPONENTS,
			'api_version'       => Migration_Engine::API_VERSION,
		);
	}

	/* ------------------------------ Sites ------------------------------- */

	public function ajax_get_state(): void {
		Security_Guard::assert_ajax_admin();
		wp_send_json_success($this->build_state());
	}

	public function ajax_save_site(): void {
		Security_Guard::assert_ajax_admin();

		$site_id = sanitize_key((string) wp_unslash($_POST['site_id'] ?? ''));
		$label = sanitize_text_field((string) wp_unslash($_POST['label'] ?? ''));
		$url = trim((string) wp_unslash($_POST['url'] ?? ''));
		$api_key = trim((string) wp_unslash($_POST['api_key'] ?? ''));
		$connection = trim((string) wp_unslash($_POST['connection'] ?? ''));

		if ('' !== $connection) {
			$parsed = Migration_API::parse_connection_string($connection);
			if (null === $parsed) {
				wp_send_json_error(array('message' => __('That connection key is not valid. Copy it again from the other site (it starts with "wudt:").', 'wp-ultimate-diagnostics-toolkit')));
			}
			$url = $parsed['url'];
			$api_key = $parsed['key'];
		} elseif (0 === strpos($api_key, 'wudt:')) {
			$parsed = Migration_API::parse_connection_string($api_key);
			if ($parsed) {
				$url = '' !== $url ? $url : $parsed['url'];
				$api_key = $parsed['key'];
			}
		}

		$url = esc_url_raw(untrailingslashit($url));
		if ('' === $url || ! preg_match('#^https?://#i', $url)) {
			wp_send_json_error(array('message' => __('Enter the full site URL, e.g. https://example.com', 'wp-ultimate-diagnostics-toolkit')));
		}
		if (strlen($api_key) < 32 || ! preg_match('/^[A-Za-z0-9]+$/', $api_key)) {
			wp_send_json_error(array('message' => __('The API key looks wrong. Copy the key (or the connection key) from the other site’s Site Migration tab.', 'wp-ultimate-diagnostics-toolkit')));
		}
		if (untrailingslashit(home_url()) === $url) {
			wp_send_json_error(array('message' => __('That is this site. Add the other site instead.', 'wp-ultimate-diagnostics-toolkit')));
		}
		$auto_label = '' === $label;
		if ($auto_label) {
			$label = (string) wp_parse_url($url, PHP_URL_HOST);
		}

		$sites = $this->get_sites();
		if ('' === $site_id || ! isset($sites[$site_id])) {
			$site_id = 'site_' . strtolower(wp_generate_password(10, false, false));
		}
		$sites[$site_id] = array(
			'id'         => $site_id,
			'label'      => $label,
			'url'        => $url,
			'api_key'    => $api_key,
			'transport'  => '',
			'created_at' => current_time('mysql'),
		);

		// Verify immediately so problems surface before a migration starts.
		$info = null;
		$warning = '';
		try {
			$client = new Remote_Client($url, $api_key);
			$info = $client->call('info', array(), 30);
			$sites[$site_id]['transport'] = $client->get_transport();
			if ('' !== $client->get_redirected_url()) {
				$sites[$site_id]['url'] = $client->get_redirected_url();
			}
			$sites[$site_id]['last_check'] = array('ok' => true, 'time' => time(), 'name' => (string) ($info['site_name'] ?? ''));
			if ($auto_label && ! empty($info['site_name'])) {
				$sites[$site_id]['label'] = (string) $info['site_name'];
			}
		} catch (\Throwable $e) {
			$warning = $e->getMessage();
			$sites[$site_id]['last_check'] = array('ok' => false, 'time' => time(), 'error' => $warning);
		}

		update_option(self::OPTION_SITES, $sites, false);
		Operation_Logger::log('migration', 'Site connection saved', array('site_id' => $site_id, 'url' => $url));

		wp_send_json_success(array(
			'site_id' => $site_id,
			'warning' => $warning,
			'state'   => $this->build_state(),
		));
	}

	public function ajax_delete_site(): void {
		Security_Guard::assert_ajax_admin();
		$site_id = sanitize_key((string) wp_unslash($_POST['site_id'] ?? ''));
		$sites = $this->get_sites();
		unset($sites[$site_id]);
		update_option(self::OPTION_SITES, $sites, false);
		wp_send_json_success(array('state' => $this->build_state()));
	}

	public function ajax_test_connection(): void {
		Security_Guard::assert_ajax_admin();
		$site = $this->require_site();
		try {
			$info = $this->remote_info($site);
			wp_send_json_success(array(
				'message' => sprintf(
					/* translators: 1: site name, 2: WordPress version */
					__('Connected to “%1$s” (WordPress %2$s).', 'wp-ultimate-diagnostics-toolkit'),
					(string) ($info['site_name'] ?? ''),
					(string) ($info['wp_version'] ?? '')
				),
				'info'    => $this->public_info($info),
			));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_regenerate_key(): void {
		Security_Guard::assert_ajax_admin();
		update_option(Migration_API::KEY_OPTION, bin2hex(random_bytes(24)), false);
		Operation_Logger::log('migration', 'Local connection key regenerated', array());
		wp_send_json_success(array('state' => $this->build_state()));
	}

	/* ------------------------------ Jobs -------------------------------- */

	public function ajax_preflight(): void {
		Security_Guard::assert_ajax_admin();
		$site = $this->require_site();
		$direction = 'push' === ($_POST['direction'] ?? '') ? 'push' : 'pull';

		try {
			$remote = $this->remote_info($site);
			$local = (new Migration_Engine())->site_info();
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}

		$src = 'pull' === $direction ? $remote : $local;
		$dst = 'pull' === $direction ? $local : $remote;
		list($errors, $warnings) = $this->compatibility_checks($src, $dst, $direction);

		wp_send_json_success(array(
			'direction' => $direction,
			'source'    => $this->public_info($src),
			'dest'      => $this->public_info($dst),
			'tables'    => $src['tables'],
			'errors'    => $errors,
			'warnings'  => $warnings,
		));
	}

	public function ajax_start(): void {
		Security_Guard::assert_ajax_admin();
		$site = $this->require_site();
		$direction = 'push' === ($_POST['direction'] ?? '') ? 'push' : 'pull';

		$components = json_decode((string) wp_unslash($_POST['components'] ?? '[]'), true);
		$tables = json_decode((string) wp_unslash($_POST['tables'] ?? '[]'), true);
		$excludes_raw = (string) wp_unslash($_POST['excludes'] ?? '');
		$excludes = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $excludes_raw) ?: array()));

		$active = Migration_Runner::active_job();
		if ($active) {
			wp_send_json_error(array('message' => __('Another migration is already running. Wait for it to finish or cancel it first.', 'wp-ultimate-diagnostics-toolkit')));
		}

		try {
			$remote = $this->remote_info($site);
			$local = (new Migration_Engine())->site_info();
			$src = 'pull' === $direction ? $remote : $local;
			$dst = 'pull' === $direction ? $local : $remote;
			list($errors) = $this->compatibility_checks($src, $dst, $direction);
			if (! empty($errors)) {
				throw new \RuntimeException(implode(' ', $errors));
			}
			$site = $this->get_sites()[$site['id']] ?? $site;
			$runner = Migration_Runner::create(
				$site,
				$direction,
				array(
					'components'     => is_array($components) ? $components : array(),
					'tables'         => is_array($tables) ? $tables : array(),
					'skip_unchanged' => ! empty($_POST['skip_unchanged']),
					'override'       => ! empty($_POST['override']),
					'excludes'       => $excludes,
				),
				$remote,
				$local
			);
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}

		$state = $runner->get_state();
		Operation_Logger::log('migration', 'Migration started', array('job' => $state['id'], 'direction' => $direction, 'site' => $site['url']));
		wp_send_json_success(array(
			'job'   => $runner->summary(),
			'token' => $state['token'],
		));
	}

	public function ajax_step(): void {
		$runner = $this->require_job_with_token();
		wp_send_json_success(array('job' => $runner->step(20.0)));
	}

	public function ajax_cancel(): void {
		$runner = $this->require_job_with_token();
		try {
			wp_send_json_success(array('job' => $runner->cancel()));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}

	public function ajax_delete_job(): void {
		Security_Guard::assert_ajax_admin();
		try {
			Migration_Runner::delete((string) wp_unslash($_POST['job_id'] ?? ''));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
		wp_send_json_success(array('state' => $this->build_state()));
	}

	/**
	 * Roll back the last migration received by this site or by a remote site.
	 */
	public function ajax_rollback(): void {
		Security_Guard::assert_ajax_admin();
		$target = sanitize_key((string) wp_unslash($_POST['site_id'] ?? 'local'));
		Migration_Engine::raise_limits();
		try {
			if ('local' === $target || '' === $target) {
				$result = (new Migration_Engine())->rollback();
			} else {
				$site = $this->require_site();
				$result = (array) (new Remote_Client($site['url'], $site['api_key'], (string) ($site['transport'] ?? '')))->call('rollback', array(), 300);
			}
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
		Operation_Logger::log('migration', 'Migration rolled back', array('target' => $target));
		wp_send_json_success(array(
			'message' => sprintf(
				/* translators: 1: tables, 2: files */
				__('Rollback complete: %1$d tables and %2$d files restored.', 'wp-ultimate-diagnostics-toolkit'),
				(int) ($result['tables'] ?? 0),
				(int) ($result['files'] ?? 0)
			),
		));
	}

	public function ajax_discard_rollback(): void {
		Security_Guard::assert_ajax_admin();
		(new Migration_Engine())->discard_rollback();
		wp_send_json_success(array('state' => $this->build_state()));
	}

	/* ------------------------------ Helpers ----------------------------- */

	private function require_job_with_token(): Migration_Runner {
		$job_id = (string) wp_unslash($_POST['job_id'] ?? '');
		$token = (string) wp_unslash($_POST['token'] ?? '');
		try {
			$runner = Migration_Runner::load($job_id);
		} catch (\Throwable $e) {
			$runner = null;
		}
		$is_admin = current_user_can('manage_options') && false !== check_ajax_referer('wudt_admin_nonce', 'nonce', false);
		if (! $runner || (! $runner->check_token($token) && ! $is_admin)) {
			wp_send_json_error(array('message' => __('Migration job not found or access denied.', 'wp-ultimate-diagnostics-toolkit')), 403);
		}
		return $runner;
	}

	private function require_site(): array {
		$site_id = sanitize_key((string) wp_unslash($_POST['site_id'] ?? ''));
		$sites = $this->get_sites();
		if ('' === $site_id || ! isset($sites[$site_id])) {
			wp_send_json_error(array('message' => __('Site not found. Add it again.', 'wp-ultimate-diagnostics-toolkit')));
		}
		return $sites[$site_id];
	}

	private function remote_info(array $site): array {
		$client = new Remote_Client($site['url'], $site['api_key'], (string) ($site['transport'] ?? ''));
		try {
			$info = $client->call('info', array(), 45);
		} catch (\Throwable $e) {
			$this->update_site($site['id'], array('last_check' => array('ok' => false, 'time' => time(), 'error' => $e->getMessage())));
			throw $e;
		}
		if (! is_array($info) || (int) ($info['api_version'] ?? 0) < Migration_Engine::API_VERSION) {
			throw new \RuntimeException(__('The remote site runs an older WP Diagnostics version. Update the plugin on the remote site and try again.', 'wp-ultimate-diagnostics-toolkit'));
		}
		$changes = array(
			'transport'  => $client->get_transport(),
			'last_check' => array('ok' => true, 'time' => time(), 'name' => (string) ($info['site_name'] ?? '')),
		);
		if ('' !== $client->get_redirected_url()) {
			$changes['url'] = $client->get_redirected_url();
		}
		$this->update_site($site['id'], $changes);
		return $info;
	}

	private function update_site(string $site_id, array $changes): void {
		$sites = $this->get_sites();
		if (isset($sites[$site_id])) {
			$sites[$site_id] = array_merge($sites[$site_id], $changes);
			update_option(self::OPTION_SITES, $sites, false);
		}
	}

	private function public_info(array $info): array {
		return array(
			'site_name'   => (string) ($info['site_name'] ?? ''),
			'home'        => (string) ($info['home'] ?? ''),
			'wp_version'  => (string) ($info['wp_version'] ?? ''),
			'php_version' => (string) ($info['php_version'] ?? ''),
			'db_server'   => (string) ($info['db_server'] ?? ''),
			'prefix'      => (string) ($info['prefix'] ?? ''),
			'theme'       => (string) ($info['stylesheet'] ?? ''),
			'plugins'     => count((array) ($info['active_plugins'] ?? array())),
			'free_space'  => (int) ($info['free_space'] ?? 0),
			'rollback'    => $info['rollback'] ?? array('available' => false),
		);
	}

	/**
	 * @return array{0:array<int,string>,1:array<int,string>}
	 */
	private function compatibility_checks(array $src, array $dst, string $direction): array {
		$errors = array();
		$warnings = array();

		if (! empty($src['multisite']) || ! empty($dst['multisite'])) {
			$errors[] = __('Multisite networks are not supported by Site Migration.', 'wp-ultimate-diagnostics-toolkit');
		}
		if (untrailingslashit((string) $src['home']) === untrailingslashit((string) $dst['home'])) {
			$errors[] = __('Both ends report the same site URL — you are connected to this same site.', 'wp-ultimate-diagnostics-toolkit');
		}

		$dst_themes = (array) ($dst['themes'] ?? array());
		foreach (array_unique(array((string) $src['stylesheet'], (string) $src['template'])) as $theme) {
			if ('' !== $theme && ! isset($dst_themes[$theme])) {
				/* translators: %s: theme slug */
				$warnings[] = sprintf(__('Theme “%s” is not installed on the destination — include Themes.', 'wp-ultimate-diagnostics-toolkit'), $theme);
			}
		}
		$dst_plugins = (array) ($dst['plugins'] ?? array());
		$missing = array();
		foreach ((array) $src['active_plugins'] as $plugin) {
			if (basename((string) $plugin) === basename(WUDT_PLUGIN_FILE)) {
				continue;
			}
			if (! isset($dst_plugins[$plugin])) {
				$missing[] = dirname((string) $plugin);
			}
		}
		if (! empty($missing)) {
			/* translators: %s: plugin list */
			$warnings[] = sprintf(__('Active plugins missing on the destination (include Plugins): %s', 'wp-ultimate-diagnostics-toolkit'), implode(', ', array_slice($missing, 0, 12)));
		}
		if (version_compare((string) $src['wp_version'], (string) $dst['wp_version'], '>')) {
			/* translators: 1: source version, 2: destination version */
			$warnings[] = sprintf(__('Source runs WordPress %1$s but the destination runs %2$s. Update WordPress on the destination first.', 'wp-ultimate-diagnostics-toolkit'), $src['wp_version'], $dst['wp_version']);
		}
		if (version_compare((string) $src['php_version'], (string) $dst['php_version'], '>') && version_compare((string) $dst['php_version'], '8.0', '<')) {
			/* translators: %s: PHP version */
			$warnings[] = sprintf(__('The destination runs an older PHP (%s); some plugins may not work there.', 'wp-ultimate-diagnostics-toolkit'), $dst['php_version']);
		}
		$src_size = 0;
		foreach ((array) $src['tables'] as $t) {
			$src_size += (int) $t['size'];
		}
		if (! empty($dst['free_space']) && $dst['free_space'] < $src_size * 3) {
			/* translators: %s: free space */
			$warnings[] = sprintf(__('Low disk space on the destination (%s free).', 'wp-ultimate-diagnostics-toolkit'), size_format((int) $dst['free_space']));
		}
		if ('pull' === $direction) {
			$warnings[] = __('After the database is pulled you will log in to this site with the remote site’s username and password.', 'wp-ultimate-diagnostics-toolkit');
		} else {
			$warnings[] = __('Pushing the users table replaces the remote site’s users with this site’s users.', 'wp-ultimate-diagnostics-toolkit');
		}
		return array($errors, $warnings);
	}

	private function get_sites(): array {
		$sites = get_option(self::OPTION_SITES, array());
		return is_array($sites) ? $sites : array();
	}
}
