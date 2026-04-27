<?php
/**
 * File Search Engine
 * Memory-efficient recursive file search with context preview
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
 * Fast, memory-efficient file search across WordPress filesystem
 */
class File_Search {
	/**
	 * Search options
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Results collection
	 * @var array<int,array<string,mixed>>
	 */
	private array $results = array();

	/**
	 * Files scanned counter
	 */
	private int $files_scanned = 0;

	/**
	 * Maximum results to collect (prevent memory issues)
	 */
	private int $max_results = 1000;

	/**
	 * @param array<string,mixed> $options Search configuration
	 */
	public function __construct(array $options = array()) {
		$this->options = wp_parse_args(
			$options,
			array(
				'directory'        => ABSPATH,
				'extensions'       => array('php', 'js', 'css', 'html', 'txt'),
				'exclude_dirs'     => array('node_modules', '.git', 'vendor', 'cache', 'upgrade'),
				'case_sensitive'   => false,
				'whole_word'       => false,
				'regex'            => false,
				'context_lines'    => 2,
				'max_file_size'    => 10 * 1024 * 1024, // 10MB
				'max_depth'        => 20,
			)
		);
	}

	/**
	 * Search for text in files
	 *
	 * @param string $search_text Text to search for
	 * @return array<string,mixed> Search results and metadata
	 */
	public function search(string $search_text): array {
		if (empty($search_text)) {
			return array(
				'success'       => false,
				'error'         => 'Search text cannot be empty',
				'results'       => array(),
				'files_scanned' => 0,
			);
		}

		$this->results = array();
		$this->files_scanned = 0;

		$directory = $this->options['directory'];

		// Validate directory is within WordPress
		try {
			$directory = Security_Guard::normalize_inside_wp($directory);
		} catch (\RuntimeException $e) {
			return array(
				'success'       => false,
				'error'         => $e->getMessage(),
				'results'       => array(),
				'files_scanned' => 0,
			);
		}

		if (! is_dir($directory)) {
			return array(
				'success'       => false,
				'error'         => 'Directory does not exist: ' . $directory,
				'results'       => array(),
				'files_scanned' => 0,
			);
		}

		$this->scan_directory($directory, $search_text, 0);

		return array(
			'success'       => true,
			'results'       => $this->results,
			'files_scanned' => $this->files_scanned,
			'match_count'   => count($this->results),
			'has_more'      => count($this->results) >= $this->max_results,
		);
	}

	/**
	 * Recursively scan directory
	 *
	 * @param string $directory Directory to scan
	 * @param string $search_text Text to search for
	 * @param int    $depth Current recursion depth
	 */
	private function scan_directory(string $directory, string $search_text, int $depth): void {
		if ($depth > $this->options['max_depth']) {
			return;
		}

		if (! is_readable($directory)) {
			return;
		}

		$iterator = new \DirectoryIterator($directory);

		foreach ($iterator as $fileinfo) {
			// Stop if we've collected enough results
			if (count($this->results) >= $this->max_results) {
				return;
			}

			if ($fileinfo->isDot()) {
				continue;
			}

			$pathname = $fileinfo->getPathname();
			$basename = $fileinfo->getBasename();

			// Check excluded directories
			if ($fileinfo->isDir()) {
				if (in_array($basename, $this->options['exclude_dirs'], true)) {
					continue;
				}
				$this->scan_directory($pathname, $search_text, $depth + 1);
				continue;
			}

			// Check file extension
			if (! $this->is_allowed_extension($pathname)) {
				continue;
			}

			// Check file size
			if ($fileinfo->getSize() > $this->options['max_file_size']) {
				continue;
			}

			$this->scan_file($pathname, $search_text);
		}
	}

	/**
	 * Check if file has allowed extension
	 *
	 * @param string $filepath File path
	 * @return bool
	 */
	private function is_allowed_extension(string $filepath): bool {
		$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
		return in_array($ext, $this->options['extensions'], true);
	}

	/**
	 * Scan individual file for matches
	 * Memory-efficient line-by-line reading
	 *
	 * @param string $filepath File to scan
	 * @param string $search_text Text to search for
	 */
	private function scan_file(string $filepath, string $search_text): void {
		$this->files_scanned++;

		$handle = @fopen($filepath, 'r');
		if (! $handle) {
			return;
		}

		$line_number = 0;
		$context_buffer = array();
		$context_size = $this->options['context_lines'];

		while (($line = fgets($handle)) !== false) {
			$line_number++;
			$line_content = rtrim($line, "\r\n");

			// Keep context buffer
			$context_buffer[] = array(
				'line'    => $line_number,
				'content' => $line_content,
			);

			if (count($context_buffer) > ($context_size * 2 + 1)) {
				array_shift($context_buffer);
			}

			// Check for match
			if ($this->line_contains($line_content, $search_text)) {
				$this->add_result($filepath, $line_number, $line_content, $context_buffer);

				// Stop if we've collected enough results
				if (count($this->results) >= $this->max_results) {
					fclose($handle);
					return;
				}
			}
		}

		fclose($handle);
	}

	/**
	 * Check if line contains search text
	 *
	 * @param string $line Line content
	 * @param string $search Search text
	 * @return bool
	 */
	private function line_contains(string $line, string $search): bool {
		if ($this->options['regex']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			return preg_match('/' . $search . '/' . $flags, $line) === 1;
		}

		if ($this->options['whole_word']) {
			$flags = $this->options['case_sensitive'] ? '' : 'i';
			$pattern = '/\b' . preg_quote($search, '/') . '\b/' . $flags;
			return preg_match($pattern, $line) === 1;
		}

		if ($this->options['case_sensitive']) {
			return strpos($line, $search) !== false;
		}

		return stripos($line, $search) !== false;
	}

	/**
	 * Add match result
	 *
	 * @param string                      $filepath File path
	 * @param int                         $line_number Line number
	 * @param string                      $match_line Matched line content
	 * @param array<int,array<string,mixed>> $context Context buffer
	 */
	private function add_result(string $filepath, int $line_number, string $match_line, array $context): void {
		// Find the match position in context buffer
		$match_index = -1;
		foreach ($context as $index => $ctx) {
			if ($ctx['line'] === $line_number) {
				$match_index = $index;
				break;
			}
		}

		// Build context lines
		$context_lines = array();
		if ($match_index >= 0) {
			$start = max(0, $match_index - $this->options['context_lines']);
			$end = min(count($context), $match_index + $this->options['context_lines'] + 1);

			for ($i = $start; $i < $end; $i++) {
				$context_lines[] = $context[$i];
			}
		}

		$this->results[] = array(
			'file'          => str_replace(ABSPATH, '/', $filepath),
			'full_path'     => $filepath,
			'line'          => $line_number,
			'match'         => $match_line,
			'context_lines' => $context_lines,
		);
	}

	/**
	 * Get predefined malware patterns
	 *
	 * @return array<string,string>
	 */
	public static function get_malware_patterns(): array {
		return array(
			'eval_base64'     => 'eval\s*\(\s*base64_decode',
			'eval_gzinflate'  => 'eval\s*\(\s*gzinflate',
			'base64_decode'   => 'base64_decode\s*\(',
			'shell_exec'      => 'shell_exec\s*\(',
			'exec'            => 'exec\s*\(',
			'system'          => 'system\s*\(',
			'passthru'        => 'passthru\s*\(',
			'iframe_inject'   => '<iframe[^>]*src\s*=\s*["\']https?://',
			'file_get_remote' => 'file_get_contents\s*\(\s*["\']https?://',
			'curl_exec'       => 'curl_exec\s*\(',
			'preg_replace_e'  => 'preg_replace\s*\([^,]*\/e',
			'error_reporting_off' => 'error_reporting\s*\(\s*0\s*\)',
			'disable_magic_quotes' => '@\s*ini_set\s*\(\s*[\'"]magic_quotes',
		);
	}

	/**
	 * Get common WordPress directories
	 *
	 * @return array<string,string>
	 */
	public static function get_wp_directories(): array {
		return array(
			'wp-content' => WP_CONTENT_DIR,
			'plugins'    => WP_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'uploads'    => wp_upload_dir()['basedir'],
			'root'       => ABSPATH,
		);
	}
}
