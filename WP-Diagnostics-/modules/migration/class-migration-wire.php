<?php
/**
 * Migration Wire - compact binary framing for migration API messages.
 *
 * JSON cannot carry binary data, so file contents used to travel base64
 * encoded (+33%) and were then deflated and base64 encoded again for the
 * response envelope. The wire format sends the JSON part deflated and every
 * binary value (array key "raw") as raw bytes after it.
 *
 * Two ways of compressing the blobs:
 * - per blob: each blob is deflated on its own when that makes it smaller.
 * - solid (peers with the "solid" feature): all compressible blobs are
 *   concatenated and deflated as one stream, so the many small, similar files
 *   of a WordPress site (PHP, JS, CSS, JSON…) compress against each other and
 *   tiny files are compressed too. Already-compressed data (images, archives,
 *   fonts, video) is still sent as it is.
 *
 * Layout: "WUDTW1" | flags (1 byte) | JSON length (uint32 BE) | JSON
 *         | [flag 2: solid length (uint32 BE) | deflated solid stream] | blobs
 * Flags: bit 0 = JSON deflated, bit 1 = solid stream present.
 * A blob reference in the JSON is {"@w":[offset,length,mode]}; mode 0 = raw
 * blob, 1 = deflated blob, 2 = slice of the inflated solid stream.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Wire {
	public const MAGIC = 'WUDTW1';
	public const CONTENT_TYPE = 'application/x-wudt-wire';

	private const FLAG_JSON_DEFLATED = 1;
	private const FLAG_SOLID = 2;

	/** Signatures of formats that are already compressed. */
	private const COMPRESSED_MAGIC = array(
		"\x89PNG", "\xFF\xD8\xFF", 'GIF8', "PK\x03\x04", "PK\x05\x06", "\x1F\x8B", 'BZh', "\xFD7zXZ",
		"7z\xBC\xAF", 'Rar!', 'wOFF', 'wOF2', 'OggS', 'ID3', "\xFF\xFB", 'fLaC', "\x28\xB5\x2F\xFD",
	);

	/**
	 * @param bool $solid Compress the blobs as one stream (only for peers with the "solid" feature).
	 */
	public static function pack(array $data, bool $solid = false): string {
		$solid = $solid && function_exists('gzdeflate');
		$blobs = array();
		$size = 0;
		$stream = array();
		$stream_size = 0;
		$data = self::extract($data, $blobs, $size, $solid, $stream, $stream_size);
		$json = (string) wp_json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		$flags = 0;
		if (strlen($json) > 1024 && function_exists('gzdeflate')) {
			$z = gzdeflate($json, self::level());
			if (is_string($z) && strlen($z) < strlen($json)) {
				$json = $z;
				$flags |= self::FLAG_JSON_DEFLATED;
			}
		}
		$solid_part = '';
		if ($stream_size > 0) {
			$z = gzdeflate(implode('', $stream), self::level());
			if (! is_string($z)) {
				throw new \RuntimeException('Could not compress file data.');
			}
			$flags |= self::FLAG_SOLID;
			$solid_part = pack('N', strlen($z)) . $z;
		}
		return self::MAGIC . chr($flags) . pack('N', strlen($json)) . $json . $solid_part . implode('', $blobs);
	}

	/**
	 * @throws \RuntimeException When the message is truncated or corrupt.
	 */
	public static function unpack(string $raw): array {
		$pos = strpos($raw, self::MAGIC);
		if (false === $pos) {
			throw new \RuntimeException('Not a migration wire message.');
		}
		if ($pos > 0) {
			// Other code printed something (notices) before the message.
			$raw = substr($raw, $pos);
		}
		if (strlen($raw) < 11) {
			throw new \RuntimeException('Truncated migration message.');
		}
		$flags = ord($raw[6]);
		$len = (int) unpack('N', substr($raw, 7, 4))[1];
		if (11 + $len > strlen($raw)) {
			throw new \RuntimeException('Truncated migration message.');
		}
		$json = substr($raw, 11, $len);
		if ($flags & self::FLAG_JSON_DEFLATED) {
			$json = function_exists('gzinflate') ? @gzinflate($json) : false;
			if (! is_string($json)) {
				throw new \RuntimeException('Could not decompress migration message.');
			}
		}
		$data = json_decode($json, true);
		if (! is_array($data)) {
			throw new \RuntimeException('Invalid migration message.');
		}
		$blob_start = 11 + $len;
		$solid = '';
		if ($flags & self::FLAG_SOLID) {
			if ($blob_start + 4 > strlen($raw)) {
				throw new \RuntimeException('Truncated migration message.');
			}
			$solid_len = (int) unpack('N', substr($raw, $blob_start, 4))[1];
			if ($blob_start + 4 + $solid_len > strlen($raw)) {
				throw new \RuntimeException('Truncated migration message.');
			}
			$solid = function_exists('gzinflate') ? @gzinflate(substr($raw, $blob_start + 4, $solid_len)) : false;
			if (! is_string($solid)) {
				throw new \RuntimeException('Could not decompress file data.');
			}
			$blob_start += 4 + $solid_len;
		}
		return self::inject($data, $raw, $blob_start, strlen($raw) - $blob_start, $solid);
	}

	public static function is_wire(string $raw): bool {
		return false !== strpos(substr($raw, 0, 4096), self::MAGIC);
	}

	/**
	 * Deflate level: 5 cuts ~17% more bytes than level 3 for little extra CPU; filterable
	 * (lower for slow CPUs on fast links, higher for slow links).
	 */
	private static function level(): int {
		return max(1, min(9, (int) apply_filters('wudt_migration_compression_level', 5)));
	}

	private static function extract(array $data, array &$blobs, int &$size, bool $solid, array &$stream, int &$stream_size): array {
		foreach ($data as $k => $v) {
			if ('raw' === $k && is_string($v)) {
				if ($solid && self::compressible($v, true)) {
					$data[$k] = array('@w' => array($stream_size, strlen($v), 2));
					$stream[] = $v;
					$stream_size += strlen($v);
					continue;
				}
				$z = 0;
				if (! $solid && strlen($v) >= 512 && self::compressible($v, false)) {
					$c = gzdeflate($v, self::level());
					if (is_string($c) && strlen($c) < strlen($v)) {
						$v = $c;
						$z = 1;
					}
				}
				$data[$k] = array('@w' => array($size, strlen($v), $z));
				$blobs[] = $v;
				$size += strlen($v);
			} elseif (is_array($v)) {
				$data[$k] = self::extract($v, $blobs, $size, $solid, $stream, $stream_size);
			}
		}
		return $data;
	}

	private static function inject(array $data, string $raw, int $start, int $blob_len, string $solid): array {
		foreach ($data as $k => $v) {
			if (! is_array($v)) {
				continue;
			}
			if ('raw' === $k && isset($v['@w']) && is_array($v['@w'])) {
				list($off, $len, $z) = array_map('intval', $v['@w'] + array(0, 0, 0));
				$limit = 2 === $z ? strlen($solid) : $blob_len;
				if ($off < 0 || $len < 0 || $off + $len > $limit) {
					throw new \RuntimeException('Corrupt migration message.');
				}
				if (2 === $z) {
					$data[$k] = (string) substr($solid, $off, $len);
					continue;
				}
				$bytes = (string) substr($raw, $start + $off, $len);
				if (1 === $z) {
					$bytes = @gzinflate($bytes);
					if (! is_string($bytes)) {
						throw new \RuntimeException('Could not decompress file data.');
					}
				}
				$data[$k] = $bytes;
			} else {
				$data[$k] = self::inject($v, $raw, $start, $blob_len, $solid);
			}
		}
		return $data;
	}

	/**
	 * Already-compressed data (JPEG, PNG, ZIP, WOFF2…) is not worth deflating.
	 * Known formats are recognised by their signature; larger blobs are also
	 * checked by test-compressing a sample.
	 *
	 * @param bool $small_ok Tiny blobs count as compressible (they share a solid stream).
	 */
	private static function compressible(string $v, bool $small_ok): bool {
		if (! function_exists('gzdeflate')) {
			return false;
		}
		$head = substr($v, 0, 12);
		foreach (self::COMPRESSED_MAGIC as $magic) {
			if (0 === strncmp($head, $magic, strlen($magic))) {
				return false;
			}
		}
		// RIFF (WebP, AVI, WAV) and ISO media (MP4, MOV, HEIC, AVIF).
		if ('RIFF' === substr($head, 0, 4) && in_array(substr($head, 8, 4), array('WEBP', 'AVI '), true)) {
			return false;
		}
		if ('ftyp' === substr($head, 4, 4)) {
			return false;
		}
		if ($small_ok && strlen($v) <= 65536) {
			return true;
		}
		$sample = strlen($v) > 32768 ? substr($v, 0, 16384) . substr($v, (int) (strlen($v) / 2), 16384) : $v;
		$z = gzdeflate($sample, 1);
		return is_string($z) && strlen($z) < strlen($sample) * 0.85;
	}
}
