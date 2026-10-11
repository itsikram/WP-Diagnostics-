<?php
/**
 * Live media: load this site's media from another site (usually the live site)
 * instead of keeping a copy of the uploads folder here.
 *
 * Attachment URLs, image srcsets and front-end HTML that point to this site's
 * uploads folder are rewritten to the other site's uploads URL. In "missing"
 * mode a file that exists here (a new upload, generated CSS…) is still served
 * from this site.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Live_Media {
	public const OPTION = 'wudt_live_media';

	private string $remote = '';
	private string $mode = 'missing';
	private string $basedir = '';
	/** @var array<int,string> This site's uploads URL, with and without https. */
	private array $locals = array();
	/** @var array<string,bool> */
	private array $exists = array();

	/**
	 * @return array{enabled:bool,url:string,mode:string,site:string,since:int}
	 */
	public static function settings(): array {
		$s = get_option(self::OPTION, array());
		$s = is_array($s) ? $s : array();
		return array(
			'enabled' => ! empty($s['enabled']) && '' !== (string) ($s['url'] ?? ''),
			'url'     => (string) ($s['url'] ?? ''),
			'mode'    => 'all' === ($s['mode'] ?? '') ? 'all' : 'missing',
			'site'    => (string) ($s['site'] ?? ''),
			'since'   => (int) ($s['since'] ?? 0),
		);
	}

	public static function enable(string $url, string $mode, string $site = ''): void {
		$url = untrailingslashit(esc_url_raw($url));
		if ('' === $url || ! preg_match('#^https?://#i', $url)) {
			throw new \RuntimeException('Invalid media URL.');
		}
		update_option(self::OPTION, array(
			'enabled' => true,
			'url'     => $url,
			'mode'    => 'all' === $mode ? 'all' : 'missing',
			'site'    => sanitize_text_field($site),
			'since'   => time(),
		), true);
	}

	public static function set_mode(string $mode): void {
		$s = self::settings();
		if ($s['enabled']) {
			self::enable($s['url'], $mode, $s['site']);
		}
	}

	public static function disable(): void {
		delete_option(self::OPTION);
	}

	public function register_hooks(): void {
		$s = self::settings();
		if (! $s['enabled']) {
			return;
		}
		$uploads = wp_get_upload_dir();
		$local = untrailingslashit((string) $uploads['baseurl']);
		$this->remote = $s['url'];
		if ('' === $local || $local === $this->remote) {
			return;
		}
		$this->mode = $s['mode'];
		$this->basedir = untrailingslashit(wp_normalize_path((string) $uploads['basedir']));
		$hostpath = (string) preg_replace('#^https?://#i', '', $local);
		$this->locals = array('https://' . $hostpath, 'http://' . $hostpath);

		add_filter('wp_get_attachment_url', array($this, 'rewrite_url'), 99);
		add_filter('wp_get_attachment_image_src', array($this, 'filter_image_src'), 99);
		add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 99);
		add_action('template_redirect', array($this, 'start_buffer'), 0);
	}

	/**
	 * @param mixed $url
	 * @return mixed
	 */
	public function rewrite_url($url) {
		return is_string($url) ? $this->rewrite($url) : $url;
	}

	/**
	 * @param mixed $image
	 * @return mixed
	 */
	public function filter_image_src($image) {
		if (is_array($image) && isset($image[0]) && is_string($image[0])) {
			$image[0] = $this->rewrite($image[0]);
		}
		return $image;
	}

	/**
	 * @param mixed $sources
	 * @return mixed
	 */
	public function filter_srcset($sources) {
		if (is_array($sources)) {
			foreach ($sources as $k => $source) {
				if (is_array($source) && isset($source['url']) && is_string($source['url'])) {
					$sources[$k]['url'] = $this->rewrite($source['url']);
				}
			}
		}
		return $sources;
	}

	/**
	 * Rewrite media URLs in the whole front-end page (page builders, inline CSS…).
	 */
	public function start_buffer(): void {
		if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		ob_start(array($this, 'rewrite'));
	}

	/**
	 * Point every uploads URL in $text to the other site (plain and JSON-escaped).
	 */
	public function rewrite(string $text): string {
		if ('' === $text || '' === $this->remote) {
			return $text;
		}
		foreach ($this->locals as $local) {
			if (false !== strpos($text, $local)) {
				$text = (string) preg_replace_callback(
					'#' . preg_quote($local, '#') . '((?:/[^\s"\'<>()\\\\?\#,]+)+)#',
					function (array $m): string {
						return $this->target($m[0], $m[1], false);
					},
					$text
				);
			}
			$escaped = str_replace('/', '\/', $local);
			if (false !== strpos($text, $escaped)) {
				$text = (string) preg_replace_callback(
					'#' . preg_quote($escaped, '#') . '((?:\\\\/[^\s"\'<>()\\\\?\#,]+)+)#',
					function (array $m): string {
						return $this->target($m[0], str_replace('\/', '/', $m[1]), true);
					},
					$text
				);
			}
		}
		return $text;
	}

	private function target(string $original, string $path, bool $escaped): string {
		if ('missing' === $this->mode && $this->exists_here($path)) {
			return $original;
		}
		$url = $this->remote . $path;
		return $escaped ? str_replace('/', '\/', $url) : $url;
	}

	private function exists_here(string $path): bool {
		if (! isset($this->exists[$path])) {
			$rel = rawurldecode($path);
			$this->exists[$path] = ! str_contains($rel, '..') && is_file($this->basedir . $rel);
		}
		return $this->exists[$path];
	}
}
