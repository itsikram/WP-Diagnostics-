<?php
/**
 * Search Matcher
 * One place that decides what "a match" is, so search, highlighting and
 * replacement always agree with each other.
 */

declare(strict_types=1);

namespace WUDT\Modules\SearchTool;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Search_Matcher
 *
 * Supports plain text, whole-word and PCRE regular expression matching,
 * each optionally case sensitive.
 */
final class Search_Matcher {
	private string $search;
	private bool $regex;
	private bool $case_sensitive;
	private bool $whole_word;

	/**
	 * PCRE pattern, used for regex and whole-word modes.
	 */
	private ?string $pattern = null;

	/**
	 * @param string              $search  Search text or pattern.
	 * @param array<string,mixed> $options regex, case_sensitive, whole_word.
	 * @throws \InvalidArgumentException When the search is empty or the pattern is invalid.
	 */
	public function __construct(string $search, array $options = array()) {
		if ('' === $search) {
			throw new \InvalidArgumentException(__('Search text cannot be empty.', 'diagnostics-toolkit'));
		}

		$this->search         = $search;
		$this->regex          = ! empty($options['regex']);
		$this->case_sensitive = ! empty($options['case_sensitive']);
		$this->whole_word     = ! empty($options['whole_word']);

		if ($this->regex || $this->whole_word) {
			$body = $this->regex ? self::escape_delimiter($search) : preg_quote($search, '~');
			if ($this->whole_word) {
				$body = '(?<![A-Za-z0-9_])(?:' . $body . ')(?![A-Za-z0-9_])';
			}
			$this->pattern = '~' . $body . '~' . ($this->case_sensitive ? '' : 'i');
			self::assert_valid($this->pattern);
		}
	}

	public function is_regex(): bool {
		return $this->regex;
	}

	public function get_search(): string {
		return $this->search;
	}

	/**
	 * Whether the subject contains at least one match.
	 */
	public function matches(string $subject): bool {
		if (null !== $this->pattern) {
			return 1 === @preg_match($this->pattern, $subject);
		}
		return false !== ($this->case_sensitive ? strpos($subject, $this->search) : stripos($subject, $this->search));
	}

	/**
	 * Cheap whole-file check used to skip files before splitting them into lines.
	 * May return true for files without a per-line match, never false for files with one.
	 */
	public function may_match_text(string $content): bool {
		if (! $this->regex) {
			return $this->matches($content);
		}
		// Lookarounds can behave differently across line boundaries; don't risk a false negative.
		if (preg_match('/\(\?<?[=!]/', $this->search)) {
			return true;
		}
		return 1 === @preg_match($this->pattern . 'm', $content);
	}

	/**
	 * Byte ranges of every match in the subject.
	 *
	 * @return array<int,array{0:int,1:int}> List of [offset, length].
	 */
	public function ranges(string $subject, int $limit = 500): array {
		$ranges = array();

		if (null !== $this->pattern) {
			if (! @preg_match_all($this->pattern, $subject, $found, PREG_OFFSET_CAPTURE)) {
				return array();
			}
			foreach ($found[0] as $hit) {
				$length = strlen($hit[0]);
				if ($length > 0) {
					$ranges[] = array((int) $hit[1], $length);
				}
				if (count($ranges) >= $limit) {
					break;
				}
			}
			return $ranges;
		}

		$length = strlen($this->search);
		$offset = 0;
		while (count($ranges) < $limit) {
			$pos = $this->case_sensitive ? strpos($subject, $this->search, $offset) : stripos($subject, $this->search, $offset);
			if (false === $pos) {
				break;
			}
			$ranges[] = array($pos, $length);
			$offset   = $pos + $length;
		}
		return $ranges;
	}

	/**
	 * Number of matches in the subject.
	 */
	public function count(string $subject): int {
		if (null !== $this->pattern) {
			$count = @preg_match_all($this->pattern, $subject, $unused);
			return is_int($count) ? $count : 0;
		}
		if ($this->case_sensitive) {
			return substr_count($subject, $this->search);
		}
		return substr_count(strtolower($subject), strtolower($this->search));
	}

	/**
	 * Replace every match. In regex mode $1 / ${1} back-references are supported.
	 *
	 * @throws \RuntimeException When PCRE fails (e.g. backtrack limit).
	 */
	public function replace(string $subject, string $replace): string {
		if (null !== $this->pattern) {
			$replacement = $this->regex ? $replace : str_replace(array('\\', '$'), array('\\\\', '\\$'), $replace);
			$result      = @preg_replace($this->pattern, $replacement, $subject);
			if (null === $result) {
				throw new \RuntimeException(preg_last_error_msg());
			}
			return $result;
		}
		return $this->case_sensitive
			? str_replace($this->search, $replace, $subject)
			: str_ireplace($this->search, $replace, $subject);
	}

	/**
	 * Split a line into highlighted segments, windowed around the first match
	 * when the line is too long to display.
	 *
	 * @return array{segments:array<int,array{0:string,1:int}>,cut_start:bool,cut_end:bool}
	 */
	public function segments(string $text, int $max_length = 400): array {
		$ranges = $this->ranges($text);
		$start  = 0;
		$end    = strlen($text);

		if ($end > $max_length) {
			$first = $ranges ? $ranges[0][0] : 0;
			$start = self::char_boundary($text, max(0, $first - (int) floor($max_length / 4)));
			$end   = self::char_boundary($text, min($end, $start + $max_length));
		}

		$segments = array();
		$cursor   = $start;
		foreach ($ranges as $range) {
			$r_start = max($range[0], $start);
			$r_end   = min($range[0] + $range[1], $end);
			if ($r_end <= $r_start || $r_start < $cursor) {
				continue;
			}
			if ($r_start > $cursor) {
				$segments[] = array(substr($text, $cursor, $r_start - $cursor), 0);
			}
			$segments[] = array(substr($text, $r_start, $r_end - $r_start), 1);
			$cursor     = $r_end;
		}
		if ($cursor < $end) {
			$segments[] = array(substr($text, $cursor, $end - $cursor), 0);
		}

		return array(
			'segments'  => $segments,
			'cut_start' => $start > 0,
			'cut_end'   => $end < strlen($text),
		);
	}

	/**
	 * Build the "after" version of a segment list by replacing each match segment.
	 *
	 * @param array{segments:array<int,array{0:string,1:int}>,cut_start:bool,cut_end:bool} $segmented
	 * @return array{segments:array<int,array{0:string,1:int}>,cut_start:bool,cut_end:bool}
	 */
	public function replace_segments(array $segmented, string $replace): array {
		foreach ($segmented['segments'] as $i => $segment) {
			if (1 === $segment[1]) {
				try {
					$segmented['segments'][$i][0] = $this->replace($segment[0], $replace);
				} catch (\RuntimeException $e) {
					$segmented['segments'][$i][0] = $replace;
				}
			}
		}
		return $segmented;
	}

	/**
	 * Shorten text for display without splitting a UTF-8 character.
	 */
	public static function clip(string $text, int $max_length = 300): string {
		if (strlen($text) <= $max_length) {
			return $text;
		}
		return substr($text, 0, self::char_boundary($text, $max_length)) . '…';
	}

	/**
	 * Move a byte offset back to the start of a UTF-8 character.
	 */
	private static function char_boundary(string $text, int $offset): int {
		$length = strlen($text);
		while ($offset > 0 && $offset < $length && (ord($text[$offset]) & 0xC0) === 0x80) {
			$offset--;
		}
		return $offset;
	}

	/**
	 * Escape unescaped "~" so user patterns can use any character.
	 */
	private static function escape_delimiter(string $pattern): string {
		$out    = '';
		$length = strlen($pattern);
		for ($i = 0; $i < $length; $i++) {
			$char = $pattern[$i];
			if ('\\' === $char && $i + 1 < $length) {
				$out .= $char . $pattern[++$i];
				continue;
			}
			$out .= '~' === $char ? '\~' : $char;
		}
		return $out;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function assert_valid(string $pattern): void {
		$error = '';
		set_error_handler(static function (int $errno, string $errstr) use (&$error): bool {
			$error = preg_replace('/^preg_match\(\):\s*/', '', $errstr);
			return true;
		});
		$result = preg_match($pattern, '');
		restore_error_handler();

		if (false === $result) {
			/* translators: %s: PCRE error message */
			throw new \InvalidArgumentException(sprintf(__('Invalid regular expression: %s', 'diagnostics-toolkit'), $error ?: preg_last_error_msg()));
		}
		if (1 === $result) {
			throw new \InvalidArgumentException(__('This pattern matches empty text, so it would match everywhere. Make it more specific.', 'diagnostics-toolkit'));
		}
	}
}
