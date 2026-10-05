<?php
/**
 * File Search Engine
 * Recursive file search with per-line matches and context preview
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

use WUDT\Includes\Security_Guard;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class File_Search
 *
 * Searches WordPress files line by line. Results are grouped per file.
 */
class File_Search {
	/**
	 * Default search configuration.
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Matched files, keyed by relative path.
	 * @var array<string,array<string,mixed>>
	 */
	private array $files = array();

	private int $files_scanned  = 0;
	private int $files_skipped  = 0;
	private int $match_count    = 0;
	private int $lines_stored   = 0;
	private string $truncated   = '';
	private float $started_at   = 0.0;
	private string $root        = '';

	/**
	 * @param array<string,mixed> $options Search configuration
	 */
	public function __construct(array $options = array()) {
		$this->options = wp_parse_args($options, self::default_options());
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function default_options(): array {
		return array(
			'directory'          => WP_CONTENT_DIR,
			'extensions'         => array('php', 'js', 'css', 'html', 'txt'),
			'exclude_dirs'       => array('node_modules', '.git', '.svn', 'vendor', 'cache', 'upgrade', 'wudt-search-backups'),
			'skip_minified'      => true,
			'case_sensitive'     => false,
			'whole_word'         => false,
			'regex'              => false,
			'context_lines'      => 2,
			'max_file_size'      => 5 * 1024 * 1024,
			'max_depth'          => 25,
			'max_lines'          => 3000,  // Matched lines kept in the response.
			'max_lines_per_file' => 100,
			'time_limit'         => 25,    // Seconds.
		);
	}

	/**
	 * Search for text in files.
	 *
	 * @param string              $search_text Text or pattern to search for
	 * @param array<string,mixed> $options     Overrides for this search
	 * @return array<string,mixed>
	 */
	public function search(string $search_text, array $options = array()): array {
		$opts = wp_parse_args($options, $this->options);

		try {
			$matcher = new Search_Matcher($search_text, $opts);
		} catch (\InvalidArgumentException $e) {
			return self::error($e->getMessage());
		}

		try {
			$directory = Security_Guard::normalize_inside_wp((string) $opts['directory']);
		} catch (\RuntimeException $e) {
			return self::error($e->getMessage());
		}

		if (! is_dir($directory)) {
			return self::error(__('The selected folder does not exist.', 'diagnostics-toolkit'));
		}

		$this->files         = array();
		$this->files_scanned = 0;
		$this->files_skipped = 0;
		$this->match_count   = 0;
		$this->lines_stored  = 0;
		$this->truncated     = '';
		$this->started_at    = microtime(true);
		$this->root          = rtrim(wp_normalize_path(ABSPATH), '/') . '/';

		$opts['extensions']   = array_values(array_filter(array_map('strtolower', (array) $opts['extensions'])));
		$opts['exclude_dirs'] = array_values(array_filter(array_map('strtolower', (array) $opts['exclude_dirs'])));

		if (empty($opts['extensions'])) {
			return self::error(__('Select at least one file type.', 'diagnostics-toolkit'));
		}

		$this->scan_directory($directory, $matcher, $opts, 0);

		return array(
			'success'       => true,
			'files'         => array_values($this->files),
			'match_count'   => $this->match_count,
			'files_matched' => count($this->files),
			'files_scanned' => $this->files_scanned,
			'files_skipped' => $this->files_skipped,
			'directory'     => $this->relative($directory) ?: '/',
			'truncated'     => $this->truncated,
			'duration'      => round(microtime(true) - $this->started_at, 2),
		);
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function scan_directory(string $directory, Search_Matcher $matcher, array $opts, int $depth): void {
		if ($depth > (int) $opts['max_depth'] || '' !== $this->truncated) {
			return;
		}

		try {
			$iterator = new \DirectoryIterator($directory);
		} catch (\Exception $e) {
			return;
		}

		$subdirs = array();
		foreach ($iterator as $fileinfo) {
			if ($fileinfo->isDot() || $fileinfo->isLink()) {
				continue;
			}

			if ($fileinfo->isDir()) {
				if (! in_array(strtolower($fileinfo->getBasename()), $opts['exclude_dirs'], true)) {
					$subdirs[] = $fileinfo->getPathname();
				}
				continue;
			}

			if (! $this->should_scan($fileinfo, $opts)) {
				continue;
			}

			$this->scan_file($fileinfo->getPathname(), $matcher, $opts);

			if ($this->out_of_budget($opts)) {
				return;
			}
		}

		// Files first, then folders, both alphabetical, so results read naturally.
		sort($subdirs, SORT_NATURAL | SORT_FLAG_CASE);
		foreach ($subdirs as $subdir) {
			$this->scan_directory($subdir, $matcher, $opts, $depth + 1);
			if ('' !== $this->truncated) {
				return;
			}
		}
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function should_scan(\SplFileInfo $fileinfo, array $opts): bool {
		$name = strtolower($fileinfo->getBasename());
		$ext  = strtolower($fileinfo->getExtension());

		if (! in_array($ext, $opts['extensions'], true)) {
			return false;
		}

		if (! empty($opts['skip_minified']) && preg_match('/\.min\.(js|css)$/', $name)) {
			return false;
		}

		if ($fileinfo->getSize() > (int) $opts['max_file_size'] || ! $fileinfo->isReadable()) {
			$this->files_skipped++;
			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function out_of_budget(array $opts): bool {
		if ($this->lines_stored >= (int) $opts['max_lines']) {
			$this->truncated = 'limit';
		} elseif (microtime(true) - $this->started_at > (float) $opts['time_limit']) {
			$this->truncated = 'time';
		}
		return '' !== $this->truncated;
	}

	/**
	 * @param array<string,mixed> $opts
	 */
	private function scan_file(string $filepath, Search_Matcher $matcher, array $opts): void {
		$content = @file_get_contents($filepath); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
		if (false === $content) {
			$this->files_skipped++;
			return;
		}

		$this->files_scanned++;

		// Binary files (images renamed .php, archives, etc.) are not searchable text.
		if (false !== strpos(substr($content, 0, 8000), "\0")) {
			return;
		}

		if (! $matcher->may_match_text($content)) {
			return;
		}

		$lines   = preg_split('/\r\n|\n|\r/', $content);
		$context = max(0, (int) $opts['context_lines']);
		$total   = count($lines);
		$matches = array();
		$count   = 0;

		foreach ($lines as $index => $line) {
			if (! $matcher->matches($line)) {
				continue;
			}

			$count += max(1, $matcher->count($line));

			if (count($matches) >= (int) $opts['max_lines_per_file']) {
				continue;
			}

			$segmented = $matcher->segments($line);
			$matches[] = array(
				'line'      => $index + 1,
				'segments'  => $segmented['segments'],
				'cut_start' => $segmented['cut_start'],
				'cut_end'   => $segmented['cut_end'],
				'before'    => $this->context_slice($lines, max(0, $index - $context), $index),
				'after'     => $this->context_slice($lines, $index + 1, min($total, $index + 1 + $context)),
			);
			$this->lines_stored++;
		}

		if (0 === $count) {
			return;
		}

		$relative           = $this->relative($filepath);
		$this->match_count += $count;
		$this->files[ $relative ] = array(
			'path'        => $relative,
			'size'        => strlen($content),
			'modified'    => (int) @filemtime($filepath),
			'writable'    => wp_is_writable($filepath),
			'match_count' => $count,
			'lines_shown' => count($matches),
			'matches'     => $matches,
		);
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<int,array{0:int,1:string}>
	 */
	private function context_slice(array $lines, int $from, int $to): array {
		$slice = array();
		for ($i = $from; $i < $to; $i++) {
			$slice[] = array($i + 1, Search_Matcher::clip($lines[ $i ]));
		}
		return $slice;
	}

	private function relative(string $path): string {
		$path = wp_normalize_path($path);
		if (0 === stripos($path, $this->root)) {
			return substr($path, strlen($this->root));
		}
		return ltrim($path, '/');
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function error(string $message): array {
		return array(
			'success'     => false,
			'error'       => $message,
			'files'       => array(),
			'match_count' => 0,
		);
	}

	/**
	 * Get predefined suspicious-code patterns (regular expressions).
	 *
	 * @return array<string,string>
	 */
	public static function get_malware_patterns(): array {
		return array(
			'eval_base64'          => 'eval\s*\(\s*base64_decode',
			'eval_gzinflate'       => 'eval\s*\(\s*gzinflate',
			'base64_decode'        => 'base64_decode\s*\(',
			'shell_exec'           => 'shell_exec\s*\(',
			'exec'                 => '\bexec\s*\(',
			'system'               => '\bsystem\s*\(',
			'passthru'             => 'passthru\s*\(',
			'iframe_inject'        => '<iframe[^>]*src\s*=\s*["\']https?://',
			'file_get_remote'      => 'file_get_contents\s*\(\s*["\']https?://',
			'curl_exec'            => 'curl_exec\s*\(',
			'preg_replace_e'       => 'preg_replace\s*\(\s*["\'].*\/[a-z]*e[a-z]*["\']\s*,',
			'error_reporting_off'  => 'error_reporting\s*\(\s*0\s*\)',
			'disable_magic_quotes' => '@\s*ini_set\s*\(\s*[\'"]magic_quotes',
		);
	}

	/**
	 * Human labels for the suspicious-code patterns.
	 *
	 * @return array<string,string>
	 */
	public static function get_malware_pattern_labels(): array {
		return array(
			'eval_base64'          => 'eval(base64_decode)',
			'eval_gzinflate'       => 'eval(gzinflate)',
			'base64_decode'        => 'base64_decode()',
			'shell_exec'           => 'shell_exec()',
			'exec'                 => 'exec()',
			'system'               => 'system()',
			'passthru'             => 'passthru()',
			'iframe_inject'        => __('Remote iframe', 'diagnostics-toolkit'),
			'file_get_remote'      => __('Remote file_get_contents', 'diagnostics-toolkit'),
			'curl_exec'            => 'curl_exec()',
			'preg_replace_e'       => 'preg_replace /e',
			'error_reporting_off'  => 'error_reporting(0)',
			'disable_magic_quotes' => 'ini_set magic_quotes',
		);
	}

	/**
	 * Get common WordPress directories.
	 *
	 * @return array<string,string>
	 */
	public static function get_wp_directories(): array {
		$dirs = array(
			'wp-content' => WP_CONTENT_DIR,
			'plugins'    => WP_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'uploads'    => wp_upload_dir(null, false)['basedir'],
		);
		if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
			$dirs['mu-plugins'] = WPMU_PLUGIN_DIR;
		}
		$dirs['root'] = ABSPATH;
		return $dirs;
	}

	/**
	 * Folder choices for the UI, grouped. Keys are what the client sends back.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_directory_choices(): array {
		$general = array(
			'wp-content' => 'wp-content/',
			'plugins'    => 'wp-content/plugins/',
			'themes'     => 'wp-content/themes/',
			'uploads'    => 'wp-content/uploads/',
		);
		if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
			$general['mu-plugins'] = 'wp-content/mu-plugins/';
		}
		$general['root'] = __('Entire WordPress install', 'diagnostics-toolkit');

		$plugins = array();
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach (get_plugins() as $file => $data) {
			$slug = dirname($file);
			if ('.' === $slug) {
				continue;
			}
			$plugins[ 'plugin:' . $slug ] = $data['Name'] ? $data['Name'] : $slug;
		}
		asort($plugins, SORT_NATURAL | SORT_FLAG_CASE);

		$themes = array();
		foreach (wp_get_themes() as $stylesheet => $theme) {
			$themes[ 'theme:' . $stylesheet ] = $theme->get('Name') ? $theme->get('Name') : $stylesheet;
		}
		asort($themes, SORT_NATURAL | SORT_FLAG_CASE);

		return array(
			'general' => $general,
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Turn a folder key from the UI into an absolute path.
	 */
	public static function resolve_directory(string $key): ?string {
		$dirs = self::get_wp_directories();
		if (isset($dirs[ $key ])) {
			return $dirs[ $key ];
		}

		// A single folder name: no separators, no dot-only names (sanitize_file_name() would alter valid names like "my-plugin-").
		$is_folder_name = static function (string $name): bool {
			return 1 === preg_match('/^[A-Za-z0-9._\-]+$/', $name) && '' !== trim($name, '.');
		};

		if (0 === strpos($key, 'plugin:')) {
			$slug = substr($key, 7);
			$path = WP_PLUGIN_DIR . '/' . $slug;
			return ($is_folder_name($slug) && is_dir($path)) ? $path : null;
		}

		if (0 === strpos($key, 'theme:')) {
			$slug = substr($key, 6);
			if (! $is_folder_name($slug)) {
				return null;
			}
			$theme = wp_get_theme($slug);
			return $theme->exists() ? $theme->get_stylesheet_directory() : null;
		}

		return null;
	}
}
