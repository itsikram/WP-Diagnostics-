<?php
/**
 * AI Agent - tool-using agent loop with approvals, driven one step per request.
 */

declare(strict_types=1);

namespace WUDT\Modules\AIAssistant;

use WUDT\Includes\AI_Config;
use WUDT\Includes\Operation_Logger;

if (! defined('ABSPATH')) {
	exit;
}

class AI_Agent {
	private const DB_VERSION = '1';
	private const MAX_STEPS = 40;

	private AI_Client $client;
	private AI_Tools $tools;

	public function __construct() {
		$this->client = new AI_Client();
		$this->tools = new AI_Tools();
	}

	public function tools(): AI_Tools {
		return $this->tools;
	}

	public function client(): AI_Client {
		return $this->client;
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wudt_ai_conversations';
	}

	public static function maybe_install(): void {
		if (self::DB_VERSION === get_option('wudt_ai_conv_db')) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta("CREATE TABLE {$table} (
			id VARCHAR(32) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(190) NOT NULL DEFAULT '',
			provider VARCHAR(32) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			messages LONGTEXT NOT NULL,
			state LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_updated (user_id, updated_at)
		) " . $wpdb->get_charset_collate() . ';');
		update_option('wudt_ai_conv_db', self::DB_VERSION, false);
	}

	private function load(string $id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		if (! $row) {
			return null;
		}
		$row['messages'] = json_decode((string) $row['messages'], true) ?: array();
		$row['state'] = json_decode((string) $row['state'], true) ?: array();
		return $row;
	}

	private function save(array $conv): void {
		global $wpdb;
		$wpdb->replace(self::table(), array(
			'id'         => $conv['id'],
			'user_id'    => (int) $conv['user_id'],
			'title'      => mb_substr((string) $conv['title'], 0, 190),
			'provider'   => (string) $conv['provider'],
			'model'      => (string) $conv['model'],
			'messages'   => (string) wp_json_encode($conv['messages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'state'      => (string) wp_json_encode($conv['state'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'created_at' => $conv['created_at'],
			'updated_at' => current_time('mysql'),
		));
		if ($wpdb->last_error) {
			throw new \RuntimeException('Could not save the conversation: ' . $wpdb->last_error);
		}
	}

	public function list_conversations(): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT id, title, provider, model, updated_at FROM ' . self::table() . ' WHERE user_id = %d ORDER BY updated_at DESC LIMIT 60', get_current_user_id()), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array($rows) ? $rows : array();
	}

	public function delete_conversation(string $id): void {
		global $wpdb;
		$wpdb->delete(self::table(), array('id' => $id, 'user_id' => get_current_user_id()));
	}

	private function owned(string $id): array {
		$conv = $this->load($id);
		if (! $conv || (int) $conv['user_id'] !== get_current_user_id()) {
			throw new \RuntimeException('Conversation not found.');
		}
		return $conv;
	}

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	public function get_display(string $id): array {
		$conv = $this->owned($id);
		return array(
			'id'       => $conv['id'],
			'title'    => $conv['title'],
			'provider' => $conv['provider'],
			'model'    => $conv['model'],
			'items'    => $this->display_items($conv),
			'status'   => ! empty($conv['state']['pending']) ? 'approval' : 'done',
		);
	}

	/**
	 * Add a user message and run one step.
	 */
	public function send(string $id, string $message, string $provider, string $model, bool $auto_approve): array {
		$message = trim($message);
		if ('' === $message) {
			throw new \RuntimeException('Message is empty.');
		}
		$conv = '' !== $id ? $this->owned($id) : $this->create($message);
		$before = count($conv['messages']);

		// A new message while actions await approval rejects them.
		if (! empty($conv['state']['pending'])) {
			$this->resolve_pending($conv, array(), false, 'The user sent a new message instead of approving this action; it was not executed.');
		}
		$conv['messages'][] = array('role' => 'user', 'text' => $message, 'time' => time());
		$conv['state']['steps'] = 0;
		$conv['state']['auto_approve'] = $auto_approve;
		return $this->step($conv, $provider, $model, $before);
	}

	/**
	 * Continue the loop: apply approval decisions and/or call the model again.
	 *
	 * @param array<string,string> $decisions call_id => approve|reject
	 */
	public function proceed(string $id, string $provider, string $model, array $decisions, bool $approve_all, bool $auto_approve): array {
		$conv = $this->owned($id);
		$before = count($conv['messages']);
		$conv['state']['auto_approve'] = $auto_approve;
		if (! empty($conv['state']['pending'])) {
			if (empty($decisions) && ! $approve_all) {
				return $this->response($conv, 'approval', $before);
			}
			$this->resolve_pending($conv, $decisions, $approve_all);
			$this->save($conv);
			// Report the executed actions right away; the next request asks the model.
			return $this->response($conv, 'continue', $before);
		}
		return $this->step($conv, $provider, $model, $before);
	}

	/* ---------------------------------------------------------------------
	 * Loop
	 * ------------------------------------------------------------------- */

	private function create(string $first_message): array {
		$title = wp_strip_all_tags($first_message);
		$title = mb_strlen($title) > 70 ? mb_substr($title, 0, 67) . '…' : $title;
		return array(
			'id'         => 'ai' . strtolower(wp_generate_password(20, false, false)),
			'user_id'    => get_current_user_id(),
			'title'      => $title,
			'provider'   => '',
			'model'      => '',
			'messages'   => array(),
			'state'      => array('steps' => 0, 'pending' => array(), 'pending_results' => array()),
			'created_at' => current_time('mysql'),
		);
	}

	private function step(array $conv, string $provider, string $model, int $before): array {
		if (! AI_Config::is_provider($provider)) {
			$provider = AI_Config::active_provider();
		}
		if ('' === $model) {
			$model = AI_Config::get_model($provider);
		}
		$conv['provider'] = $provider;
		$conv['model'] = $model;

		$last = end($conv['messages']);
		if (! $last || 'assistant' === $last['role']) {
			$this->save($conv);
			return $this->response($conv, 'done', $before);
		}

		if ((int) ($conv['state']['steps'] ?? 0) >= self::MAX_STEPS) {
			$conv['messages'][] = array('role' => 'assistant', 'text' => 'I have taken ' . self::MAX_STEPS . ' steps on this task. Reply “continue” if you want me to keep going.', 'calls' => array(), 'provider' => 'system');
			$this->save($conv);
			return $this->response($conv, 'done', $before);
		}
		$conv['state']['steps'] = (int) ($conv['state']['steps'] ?? 0) + 1;
		$this->tools->set_conversation($conv['id']);

		try {
			$res = $this->client->chat($provider, $model, $this->system_prompt(), $this->compact($conv['messages']), $this->tool_schemas(), array(
				'max_tokens' => AI_Config::max_tokens(),
			));
		} catch (\Throwable $e) {
			$this->save($conv);
			$out = $this->response($conv, 'error', $before);
			$out['error'] = $e->getMessage();
			return $out;
		}

		$calls = array();
		foreach ($res['calls'] as $call) {
			if ('' === $call['name']) {
				continue;
			}
			$calls[] = array('id' => $call['id'] ?: 'call_' . wp_generate_password(12, false, false), 'name' => $call['name'], 'args' => (array) $call['args']);
		}

		if ('max_tokens' === $res['stop'] && ! empty($calls)) {
			// A tool call cut off mid-way is unusable; ask the model to work in smaller pieces.
			$conv['messages'][] = array('role' => 'assistant', 'text' => $res['text'], 'calls' => array(), 'provider' => $provider, 'native' => null, 'usage' => $res['usage']);
			$conv['messages'][] = array('role' => 'user', 'text' => '[Automatic note] Your last response was cut off because it exceeded the maximum output length, so the tool call was not executed. Continue by doing the work in smaller pieces — for Elementor, create the page with the first section only and then add the remaining sections one or two at a time with mode "append".', 'auto' => true);
			$this->save($conv);
			return $this->response($conv, 'continue', $before);
		}

		$conv['messages'][] = array(
			'role'     => 'assistant',
			'text'     => $res['text'],
			'calls'    => $calls,
			'provider' => $provider,
			'native'   => $res['native'],
			'usage'    => $res['usage'],
		);

		if (empty($calls)) {
			if ('max_tokens' === $res['stop']) {
				$conv['messages'][count($conv['messages']) - 1]['text'] .= "\n\n_(The answer was cut off at the output limit.)_";
			}
			$this->save($conv);
			return $this->response($conv, 'done', $before);
		}

		// Run read-only tools (and everything in auto mode); queue the rest for approval.
		$auto = ! empty($conv['state']['auto_approve']);
		$results = array();
		$pending = array();
		foreach ($calls as $call) {
			if (! $this->tools->exists($call['name'])) {
				$results[$call['id']] = array('id' => $call['id'], 'name' => $call['name'], 'content' => 'Unknown tool "' . $call['name'] . '".', 'error' => true);
			} elseif ($auto || ! $this->tools->is_write($call['name'])) {
				$results[$call['id']] = $this->run_tool($call);
			} else {
				$pending[] = $call;
			}
		}

		if (! empty($pending)) {
			$conv['state']['pending'] = $pending;
			$conv['state']['pending_results'] = $results;
			$this->save($conv);
			return $this->response($conv, 'approval', $before);
		}

		$conv['messages'][] = array('role' => 'tool', 'results' => $this->ordered_results($calls, $results));
		$this->save($conv);
		return $this->response($conv, 'continue', $before);
	}

	private function run_tool(array $call): array {
		$started = microtime(true);
		$out = $this->tools->execute($call['name'], (array) $call['args']);
		if ($this->tools->is_write($call['name']) && ! $out['error']) {
			Operation_Logger::log('ai', 'AI agent action: ' . $call['name'], array('summary' => $this->tools->describe($call['name'], (array) $call['args'])));
		}
		return array(
			'id'      => $call['id'],
			'name'    => $call['name'],
			'content' => $out['content'],
			'error'   => (bool) $out['error'],
			'meta'    => array_merge((array) $out['meta'], array('ms' => (int) ((microtime(true) - $started) * 1000))),
		);
	}

	private function resolve_pending(array &$conv, array $decisions, bool $approve_all, string $reject_message = 'The user rejected this action. Do not retry it unless the user asks; suggest an alternative if useful.'): void {
		$results = (array) ($conv['state']['pending_results'] ?? array());
		foreach ((array) $conv['state']['pending'] as $call) {
			$decision = $approve_all ? 'approve' : (string) ($decisions[$call['id']] ?? 'reject');
			if ('approve' === $decision) {
				$results[$call['id']] = $this->run_tool($call);
			} else {
				$results[$call['id']] = array('id' => $call['id'], 'name' => $call['name'], 'content' => $reject_message, 'error' => true, 'meta' => array('rejected' => true));
			}
		}
		$last_assistant = null;
		for ($i = count($conv['messages']) - 1; $i >= 0; $i--) {
			if ('assistant' === $conv['messages'][$i]['role']) {
				$last_assistant = $conv['messages'][$i];
				break;
			}
		}
		$conv['messages'][] = array('role' => 'tool', 'results' => $this->ordered_results((array) ($last_assistant['calls'] ?? array()), $results));
		$conv['state']['pending'] = array();
		$conv['state']['pending_results'] = array();
	}

	private function ordered_results(array $calls, array $results): array {
		$out = array();
		foreach ($calls as $call) {
			if (isset($results[$call['id']])) {
				$out[] = $results[$call['id']];
			}
		}
		return $out;
	}

	private function tool_schemas(): array {
		return array_map(static function ($d) {
			return array('name' => $d['name'], 'description' => $d['description'], 'parameters' => $d['parameters']);
		}, $this->tools->definitions());
	}

	/**
	 * Shrink earlier turns so long conversations stay within the model's context.
	 */
	private function compact(array $messages): array {
		$last_user = 0;
		foreach ($messages as $i => $m) {
			if ('user' === $m['role'] && empty($m['auto'])) {
				$last_user = $i;
			}
		}
		foreach ($messages as $i => &$m) {
			unset($m['usage'], $m['time']);
			if ($i >= $last_user) {
				continue;
			}
			if ('tool' === $m['role']) {
				foreach ($m['results'] as &$r) {
					unset($r['meta']);
					if (strlen((string) $r['content']) > 1500) {
						$r['content'] = substr((string) $r['content'], 0, 1500) . '…[older output truncated]';
					}
				}
				unset($r);
			} elseif ('assistant' === $m['role'] && ! empty($m['calls'])) {
				foreach ($m['calls'] as &$c) {
					$c['args'] = self::shrink_args((array) $c['args']);
				}
				unset($c);
				// Rebuild from the shrunk calls instead of replaying large native payloads.
				$m['provider'] = 'compacted';
				$m['native'] = null;
			}
		}
		unset($m);
		foreach ($messages as &$m) {
			if ('tool' === $m['role']) {
				foreach ($m['results'] as &$r) {
					unset($r['meta']);
				}
				unset($r);
			}
		}
		unset($m);
		return $messages;
	}

	private static function shrink_args(array $args): array {
		foreach ($args as $k => $v) {
			if (is_string($v) && strlen($v) > 800) {
				$args[$k] = substr($v, 0, 400) . '…[' . strlen($v) . ' chars omitted]';
			} elseif (is_array($v)) {
				$json = (string) wp_json_encode($v);
				if (strlen($json) > 800) {
					$args[$k] = substr($json, 0, 400) . '…[omitted]';
				}
			}
		}
		return $args;
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	private function response(array $conv, string $status, int $before): array {
		$items = $this->display_items($conv);
		return array(
			'conversation' => array('id' => $conv['id'], 'title' => $conv['title'], 'provider' => $conv['provider'], 'model' => $conv['model']),
			'status'       => $status,
			'items'        => $items,
		);
	}

	/**
	 * Convert stored messages to UI items.
	 */
	private function display_items(array $conv): array {
		$items = array();
		$results = array();
		foreach ($conv['messages'] as $m) {
			if ('tool' === $m['role']) {
				foreach ($m['results'] as $r) {
					$results[$r['id']] = $r;
				}
			}
		}
		$pending_ids = array();
		foreach ((array) ($conv['state']['pending'] ?? array()) as $p) {
			$pending_ids[$p['id']] = true;
		}
		$pending_results = (array) ($conv['state']['pending_results'] ?? array());

		foreach ($conv['messages'] as $m) {
			if ('user' === $m['role']) {
				if (empty($m['auto'])) {
					$items[] = array('type' => 'user', 'text' => $m['text']);
				}
				continue;
			}
			if ('assistant' !== $m['role']) {
				continue;
			}
			if ('' !== trim((string) $m['text'])) {
				$items[] = array('type' => 'assistant', 'text' => $m['text']);
			}
			foreach ((array) ($m['calls'] ?? array()) as $c) {
				$r = $results[$c['id']] ?? ($pending_results[$c['id']] ?? null);
				$status = 'running';
				if (isset($pending_ids[$c['id']])) {
					$status = 'pending';
				} elseif ($r) {
					$status = ! empty($r['meta']['rejected']) ? 'rejected' : (! empty($r['error']) ? 'error' : 'done');
				}
				$items[] = array(
					'type'    => 'tool',
					'id'      => $c['id'],
					'name'    => $c['name'],
					'write'   => $this->tools->is_write($c['name']),
					'summary' => $this->tools->describe($c['name'], (array) $c['args']),
					'args'    => self::preview_args((array) $c['args']),
					'status'  => $status,
					'result'  => $r ? mb_substr((string) $r['content'], 0, 4000) : '',
					'meta'    => $r['meta'] ?? array(),
				);
			}
		}
		return $items;
	}

	private static function preview_args(array $args): array {
		foreach ($args as $k => $v) {
			if (! is_string($v)) {
				$v = (string) wp_json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			$args[$k] = mb_strlen($v) > 6000 ? mb_substr($v, 0, 6000) . '…' : $v;
		}
		return $args;
	}

	/* ---------------------------------------------------------------------
	 * System prompt
	 * ------------------------------------------------------------------- */

	private function system_prompt(): string {
		$theme = wp_get_theme();
		$elementor = defined('ELEMENTOR_VERSION') ? 'Elementor ' . ELEMENTOR_VERSION . (defined('ELEMENTOR_PRO_VERSION') ? ' + Pro ' . ELEMENTOR_PRO_VERSION : '') : 'Elementor not active';
		$containers = false;
		try {
			$containers = class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->experiments->is_feature_active('container');
		} catch (\Throwable $e) {
			$containers = false;
		}

		$site = sprintf(
			"Site: %s (%s) · WordPress %s · PHP %s · Theme: %s · %s%s · Today: %s",
			get_bloginfo('name'),
			home_url('/'),
			get_bloginfo('version'),
			PHP_VERSION,
			$theme->get('Name'),
			$elementor,
			defined('ELEMENTOR_VERSION') ? ($containers ? ' (flexbox containers ACTIVE — build with "container")' : ' (containers inactive — build with section > column > widget)') : '',
			wp_date('Y-m-d')
		);

		return <<<PROMPT
You are the Diagnostics Toolkit AI agent, an expert WordPress engineer and web designer working directly on the user's live WordPress site through tools. You fix errors, build and edit pages (especially with Elementor), and manage plugins, themes, files and the database.

{$site}

HOW TO WORK
- Act, don't just advise: use the tools to inspect and then make the change. Keep going until the task is done, then give a short summary of what you changed (with links) and anything the user must do.
- Investigate before changing: site_overview, read_error_log, check_site_health, read_file, search_files, db_query. Base fixes on evidence (error messages, file and line numbers).
- Make the smallest correct change. Prefer edit_file with an exact, unique old_string over rewriting whole files. Never guess file contents — read them first.
- After every fix, verify with check_site_health (and read_error_log) and iterate until the error is gone.
- Every change is backed up and can be undone with undo_change. PHP files are syntax-checked and reverted automatically if they crash the site.
- Changing actions may need the user's approval in the UI; if one is rejected, don't retry it — offer alternatives.
- Paths are relative to the WordPress root (e.g. wp-content/themes/mytheme/functions.php). Use {prefix} in SQL for the table prefix.
- Don't edit WordPress core files (wp-admin, wp-includes) unless the user asks. For theme customizations prefer a child theme. Never touch the Diagnostics Toolkit plugin itself unless asked.
- Fixing a fatal error: read the log → locate the file/line → read the code → fix it (or, if it's a third-party plugin you can't safely fix, deactivate it with manage_plugin and explain) → verify.
- Be concise. Use Markdown. Answer in the user's language.

ELEMENTOR DESIGN GUIDE
- Use elementor_save_page with "elements" = JSON array. IDs are optional (generated automatically).
- Build complete, modern, responsive, visually polished designs: clear hierarchy, generous spacing, consistent colors and fonts, real persuasive copy (never lorem ipsum), strong calls to action. Usually: hero, features/services, about/benefits, social proof/testimonials, CTA, contact/footer section.
- Big pages: create the page with the first 1-2 sections, then add the rest with mode "append" (1-2 sections per call). Edit one part with mode "replace_element" + element_id (use elementor_get_page to find ids).
- Default template "elementor_header_footer" (keeps theme header/footer); "elementor_canvas" for standalone landing pages. Status "draft" unless the user wants it live ("publish"). Use set_as_homepage when asked.
- Use elementor_get_kit / elementor_update_kit to set global colors & fonts for a coherent brand. Check elementor_list_widgets before using Pro or third-party widgets.
- Containers (when active): {"elType":"container","settings":{...},"elements":[...]}. Useful settings: content_width "boxed"|"full", flex_direction "row"|"column", flex_wrap "wrap", flex_justify_content "center"|"space-between", flex_align_items "center", flex_gap {"unit":"px","size":24,"column":"24","row":"24"}, width {"unit":"%","size":50} (child containers in a row), min_height {"unit":"vh","size":80}, padding {"unit":"px","top":"80","right":"20","bottom":"80","left":"20","isLinked":false}, background_background "classic"|"gradient", background_color "#0F172A", background_image {"url":"..."}, background_overlay_background "classic", background_overlay_color "#000000", background_overlay_opacity {"size":0.5}, background_color_b / background_gradient_angle for gradients, border_radius {"unit":"px","top":"16","right":"16","bottom":"16","left":"16","isLinked":true}, box_shadow_box_shadow_type "yes". Responsive values use suffixes _tablet and _mobile (e.g. flex_direction_mobile "column", width_mobile {"unit":"%","size":100}).
- Sections/columns (when containers are inactive): {"elType":"section","settings":{...},"elements":[{"elType":"column","settings":{"_column_size":50},"elements":[widgets]}]}.
- Widgets: {"elType":"widget","widgetType":"heading","settings":{"title":"…","header_size":"h1","align":"center","title_color":"#fff","typography_typography":"custom","typography_font_family":"Poppins","typography_font_size":{"unit":"px","size":56},"typography_font_weight":"700"}}.
  heading: title, header_size, align, title_color, typography_*. text-editor: editor (HTML), text_color, align. button: text, link {"url":"#contact"}, align, size "md"|"lg", background_color, button_text_color, border_radius, selected_icon {"value":"fas fa-arrow-right","library":"fa-solid"}. image: image {"url":"…","id":""}, image_size "full", width, border_radius. icon-box / image-box: title_text, description_text, selected_icon, image, position "top"|"left", title_color. icon-list: icon_list [{"text":"…","selected_icon":{"value":"fas fa-check","library":"fa-solid"}}]. counter: starting_number, ending_number, suffix, title. testimonial: testimonial_content, testimonial_name, testimonial_job, testimonial_image. star-rating: rating. divider, spacer (space {"unit":"px","size":40}), video (youtube_url), google_maps (address), social-icons (social_icon_list [{"social_icon":{"value":"fab fa-facebook","library":"fa-brands"},"link":{"url":"…"}}]), accordion/toggle (tabs [{"tab_title","tab_content"}]), html (html). Pro only: form, posts, slides, price-table, call-to-action, flip-box, nav-menu.
  Common widget styling: _padding, _margin, _background_background, _background_color, _border_radius, _element_width "initial" with _element_custom_width.
- Images: use real images the user provides, images from the Media Library, upload_media_from_url for image URLs. When no image is available, leave the image empty and tell the user which images to add.
PROMPT;
	}
}
