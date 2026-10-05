<?php
/**
 * Migration Wire - compact binary framing for migration API messages.
 *
 * JSON cannot carry binary data, so file contents used to travel base64
 * encoded (+33%) and were then deflated and base64 encoded again for the
 * response envelope. The wire format sends the JSON part deflated and every
 * binary value (array key "raw") as raw bytes after it, deflating each blob
 * only when that actually makes it smaller (images, zips and fonts are sent
 * as they are).
 *
 * Layout: "WUDTW1" | flags (1 byte, bit 0 = JSON deflated) | JSON length (uint32 BE) | JSON | blobs
 * A blob reference in the JSON is {"@w":[offset,length,deflated]}.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Migration_Wire {
	public const MAGIC = 'WUDTW1';
	public const CONTENT_TYPE = 'application/x-wudt-wire';

	public static function pack(array $data): string {
		$blobs = array();
		$size = 0;
		$data = self::extract($data, $blobs, $size);
		$json = (string) wp_json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		$flags = 0;
		if (strlen($json) > 1024 && function_exists('gzdeflate')) {
			$z = gzdeflate($json, 3);
			if (is_string($z) && strlen($z) < strlen($json)) {
				$json = $z;
				$flags |= 1;
			}
		}
		return self::MAGIC . chr($flags) . pack('N', strlen($json)) . $json . implode('', $blobs);
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
		if ($flags & 1) {
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
		return self::inject($data, $raw, $blob_start, strlen($raw) - $blob_start);
	}

	public static function is_wire(string $raw): bool {
		return false !== strpos(substr($raw, 0, 4096), self::MAGIC);
	}

	private static function extract(array $data, array &$blobs, int &$size): array {
		foreach ($data as $k => $v) {
			if ('raw' === $k && is_string($v)) {
				$z = 0;
				if (strlen($v) >= 512 && self::compressible($v)) {
					$c = gzdeflate($v, 3);
					if (is_string($c) && strlen($c) < strlen($v)) {
						$v = $c;
						$z = 1;
					}
				}
				$data[$k] = array('@w' => array($size, strlen($v), $z));
				$blobs[] = $v;
				$size += strlen($v);
			} elseif (is_array($v)) {
				$data[$k] = self::extract($v, $blobs, $size);
			}
		}
		return $data;
	}

	private static function inject(array $data, string $raw, int $start, int $blob_len): array {
		foreach ($data as $k => $v) {
			if (! is_array($v)) {
				continue;
			}
			if ('raw' === $k && isset($v['@w']) && is_array($v['@w'])) {
				list($off, $len, $z) = array_map('intval', $v['@w'] + array(0, 0, 0));
				if ($off < 0 || $len < 0 || $off + $len > $blob_len) {
					throw new \RuntimeException('Corrupt migration message.');
				}
				$bytes = (string) substr($raw, $start + $off, $len);
				if ($z) {
					$bytes = @gzinflate($bytes);
					if (! is_string($bytes)) {
						throw new \RuntimeException('Could not decompress file data.');
					}
				}
				$data[$k] = $bytes;
			} else {
				$data[$k] = self::inject($v, $raw, $start, $blob_len);
			}
		}
		return $data;
	}

	/**
	 * Quick check on a sample: already-compressed data (JPEG, PNG, ZIP, WOFF2…) is not worth deflating.
	 */
	private static function compressible(string $v): bool {
		if (! function_exists('gzdeflate')) {
			return false;
		}
		$sample = strlen($v) > 32768 ? substr($v, 0, 16384) . substr($v, (int) (strlen($v) / 2), 16384) : $v;
		$z = gzdeflate($sample, 1);
		return is_string($z) && strlen($z) < strlen($sample) * 0.85;
	}
}
