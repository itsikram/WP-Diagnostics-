<?php
/**
 * Remote Client - HTTP client for remote server API communication.
 */

declare(strict_types=1);

namespace WUDT\Modules\Migration;

if (! defined('ABSPATH')) {
	exit;
}

class Remote_Client {
	private const API_NAMESPACE = 'wudt-migration/v1';
	private const REQUEST_TIMEOUT = 30;
	private const MAX_RETRIES = 3;
	private const RETRY_DELAY = 2;

	/**
	 * Test connection to remote site.
	 */
	public function test_connection(string $site_url, string $api_key): array {
		$response = $this->make_request(
			$site_url,
			$api_key,
			'POST',
			'/connect',
			array(),
			5
		);

		if (is_wp_error($response)) {
			return array('success' => false, 'error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) === 200 && isset($data['success'])) {
			return array('success' => true, 'data' => $data);
		}

		return array('success' => false, 'error' => $data['message'] ?? __('Connection failed', 'wp-ultimate-diagnostics-toolkit'));
	}

	/**
	 * Initiate backup on remote server.
	 */
	public function initiate_backup(string $site_url, string $api_key, array $components, string $job_id): array {
		$response = $this->make_request(
			$site_url,
			$api_key,
			'POST',
			'/initiate-backup',
			array(
				'components' => $components,
				'job_id' => $job_id,
			)
		);

		if (is_wp_error($response)) {
			return array('success' => false, 'error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) === 200) {
			return array('success' => true, 'data' => $data);
		}

		return array('success' => false, 'error' => $data['message'] ?? __('Failed to initiate backup', 'wp-ultimate-diagnostics-toolkit'));
	}

	/**
	 * Get backup status from remote server.
	 */
	public function get_backup_status(string $site_url, string $api_key, string $remote_job_id): array {
		$response = $this->make_request(
			$site_url,
			$api_key,
			'GET',
			"/backup-status/{$remote_job_id}"
		);

		if (is_wp_error($response)) {
			return array('success' => false, 'error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) === 200) {
			return array('success' => true, 'data' => $data);
		}

		return array('success' => false, 'error' => $data['error'] ?? __('Failed to get backup status', 'wp-ultimate-diagnostics-toolkit'));
	}

	/**
	 * Download backup file with progress tracking.
	 */
	public function download_backup(
		string $site_url,
		string $api_key,
		string $remote_job_id,
		string $local_path,
		callable $progress_callback = null
	): array {
		$url = $this->build_api_url($site_url, "/download/{$remote_job_id}");
		$headers = $this->build_auth_headers($api_key);

		// Check available disk space
		$free_space = disk_free_space(dirname($local_path));
		if ($free_space === false || $free_space < 100 * 1024 * 1024) { // Less than 100MB
			return array('success' => false, 'error' => __('Insufficient disk space', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Open local file for writing
		$local_handle = fopen($local_path, 'wb');
		if (!$local_handle) {
			return array('success' => false, 'error' => __('Cannot create local file', 'wp-ultimate-diagnostics-toolkit'));
		}

		// First, get file size via HEAD request
		$head_response = wp_remote_head($url, array(
			'headers' => $headers,
			'timeout' => self::REQUEST_TIMEOUT,
			'sslverify' => false,
		));

		$total_size = 0;
		if (!is_wp_error($head_response)) {
			$content_length = wp_remote_retrieve_header($head_response, 'content-length');
			$total_size = (int) $content_length;
		}

		$downloaded = 0;
		$chunk_size = 256 * 1024; // 256KB chunks
		$retry_count = 0;
		$start_byte = 0;

		while ($start_byte < $total_size || $total_size === 0) {
			// Build range header for resumable download
			$end_byte = $total_size > 0 ? min($start_byte + $chunk_size - 1, $total_size - 1) : $start_byte + $chunk_size - 1;
			$range_headers = $headers;
			if ($total_size > 0) {
				$range_headers['Range'] = "bytes={$start_byte}-{$end_byte}";
			}

			$response = wp_remote_get($url, array(
				'headers' => $range_headers,
				'timeout' => self::REQUEST_TIMEOUT,
				'sslverify' => false,
				'stream' => false,
			));

			if (is_wp_error($response)) {
				$retry_count++;
				if ($retry_count > self::MAX_RETRIES) {
					fclose($local_handle);
					unlink($local_path);
					return array('success' => false, 'error' => $response->get_error_message());
				}
				sleep(self::RETRY_DELAY * $retry_count);
				continue;
			}

			$body = wp_remote_retrieve_body($response);
			$response_code = wp_remote_retrieve_response_code($response);

			if ($response_code === 200 || $response_code === 206) {
				fwrite($local_handle, $body);
				$downloaded += strlen($body);
				$start_byte += strlen($body);
				$retry_count = 0;

				// Update progress
				if ($progress_callback && $total_size > 0) {
					$percent = (int) (($downloaded / $total_size) * 100);
					$message = sprintf(
						__('Downloading: %s / %s', 'wp-ultimate-diagnostics-toolkit'),
						$this->format_bytes($downloaded),
						$this->format_bytes($total_size)
					);
					$progress_callback($percent, $message);
				}

				// Check if we got all the data
				if ($total_size > 0 && $start_byte >= $total_size) {
					break;
				}

				// For non-range requests, we're done when we get all data
				if ($total_size === 0 && strlen($body) < $chunk_size) {
					break;
				}
			} else {
				$retry_count++;
				if ($retry_count > self::MAX_RETRIES) {
					fclose($local_handle);
					unlink($local_path);
					return array('success' => false, 'error' => __('Download failed with HTTP ' . $response_code, 'wp-ultimate-diagnostics-toolkit'));
				}
				sleep(self::RETRY_DELAY * $retry_count);
			}

			// Small delay to prevent overwhelming the server
			usleep(10000); // 10ms
		}

		fclose($local_handle);

		// Verify downloaded file
		if (!file_exists($local_path) || filesize($local_path) === 0) {
			return array('success' => false, 'error' => __('Downloaded file is empty or missing', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Validate as ZIP
		$zip = new \ZipArchive();
		$opened = $zip->open($local_path);
		if ($opened !== true) {
			unlink($local_path);
			return array('success' => false, 'error' => __('Downloaded file is not a valid ZIP archive', 'wp-ultimate-diagnostics-toolkit'));
		}
		$zip->close();

		return array('success' => true, 'file_path' => $local_path, 'size' => $downloaded);
	}

	/**
	 * Upload backup to remote server.
	 */
	public function upload_backup(
		string $site_url,
		string $api_key,
		string $local_path,
		string $job_id,
		callable $progress_callback = null
	): array {
		if (!file_exists($local_path)) {
			return array('success' => false, 'error' => __('Local backup file not found', 'wp-ultimate-diagnostics-toolkit'));
		}

		$file_size = filesize($local_path);
		$uploaded = 0;
		$chunk_size = 512 * 1024; // 512KB chunks

		$url = $this->build_api_url($site_url, '/upload-backup');
		
		// Stream upload in chunks
		$handle = fopen($local_path, 'rb');
		if (!$handle) {
			return array('success' => false, 'error' => __('Cannot read local file', 'wp-ultimate-diagnostics-toolkit'));
		}

		// Build multipart request with file
		$boundary = uniqid('WUDT');
		$headers = $this->build_auth_headers($api_key);
		$headers['Content-Type'] = "multipart/form-data; boundary={$boundary}";

		while (!feof($handle)) {
			$chunk = fread($handle, $chunk_size);
			
			$body = "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"job_id\"\r\n\r\n{$job_id}\r\n";
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"backup.zip\"\r\n";
			$body .= "Content-Type: application/zip\r\n\r\n";
			$body .= $chunk . "\r\n";
			$body .= "--{$boundary}--\r\n";

			$response = wp_remote_post($url, array(
				'headers' => $headers,
				'body' => $body,
				'timeout' => self::REQUEST_TIMEOUT,
				'sslverify' => false,
			));

			if (is_wp_error($response)) {
				fclose($handle);
				return array('success' => false, 'error' => $response->get_error_message());
			}

			$uploaded += strlen($chunk);

			if ($progress_callback) {
				$percent = (int) (($uploaded / $file_size) * 100);
				$message = sprintf(
					__('Uploading: %s / %s', 'wp-ultimate-diagnostics-toolkit'),
					$this->format_bytes($uploaded),
					$this->format_bytes($file_size)
				);
				$progress_callback($percent, $message);
			}

			usleep(5000); // 5ms delay
		}

		fclose($handle);

		return array('success' => true, 'uploaded_size' => $uploaded);
	}

	/**
	 * Trigger restore on remote server.
	 */
	public function trigger_restore(
		string $site_url,
		string $api_key,
		string $job_id,
		array $components,
		string $old_url,
		string $new_url,
		callable $progress_callback = null
	): array {
		$url = $this->build_api_url($site_url, '/restore');
		$headers = $this->build_auth_headers($api_key);

		$response = wp_remote_post($url, array(
			'headers' => $headers,
			'body' => json_encode(array(
				'job_id' => $job_id,
				'components' => $components,
				'old_url' => $old_url,
				'new_url' => $new_url,
			)),
			'timeout' => 300, // 5 minutes for restore
			'sslverify' => false,
		));

		if (is_wp_error($response)) {
			return array('success' => false, 'error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (wp_remote_retrieve_response_code($response) === 200) {
			return array('success' => true, 'data' => $data);
		}

		return array('success' => false, 'error' => $data['error'] ?? __('Restore failed', 'wp-ultimate-diagnostics-toolkit'));
	}

	/**
	 * Make authenticated HTTP request with retry logic.
	 */
	private function make_request(
		string $site_url,
		string $api_key,
		string $method,
		string $endpoint,
		array $body = array(),
		int $timeout = null
	) {
		$url = $this->build_api_url($site_url, $endpoint);
		$headers = $this->build_auth_headers($api_key);
		$timeout = $timeout ?? self::REQUEST_TIMEOUT;

		$args = array(
			'headers' => $headers,
			'timeout' => $timeout,
			'sslverify' => false,
		);

		if (!empty($body)) {
			$args['body'] = json_encode($body);
			$headers['Content-Type'] = 'application/json';
		}

		for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
			if ($method === 'POST') {
				$response = wp_remote_post($url, $args);
			} else {
				$response = wp_remote_get($url, $args);
			}

			if (!is_wp_error($response)) {
				return $response;
			}

			if ($attempt < self::MAX_RETRIES - 1) {
				sleep(self::RETRY_DELAY * ($attempt + 1));
			}
		}

		return $response;
	}

	/**
	 * Build API URL.
	 */
	private function build_api_url(string $site_url, string $endpoint): string {
		return rtrim($site_url, '/') . '/wp-json/' . self::API_NAMESPACE . $endpoint;
	}

	/**
	 * Build authentication headers.
	 */
	private function build_auth_headers(string $api_key): array {
		$timestamp = time();
		$signature = hash_hmac('sha256', $api_key . $timestamp, $api_key);

		return array(
			'X-WUDT-API-Key' => $api_key,
			'X-WUDT-Timestamp' => $timestamp,
			'X-WUDT-Signature' => $signature,
		);
	}

	/**
	 * Format bytes to human readable.
	 */
	private function format_bytes(int $bytes, int $precision = 2): string {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		return round($bytes, $precision) . ' ' . $units[$pow];
	}
}
