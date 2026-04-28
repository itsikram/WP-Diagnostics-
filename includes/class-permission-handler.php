<?php
/**
 * Permission Error Handler
 * Gracefully handles permission-related errors to prevent 500 errors
 * and avoid filling debug logs with permission warnings.
 */

declare(strict_types=1);

namespace WUDT\Includes;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Permission_Handler
 * 
 * Wraps file operations and other permission-sensitive operations
 * to catch permission errors gracefully without causing 500 errors.
 */
class Permission_Handler {
	
	/**
	 * Silenced error types - these won't trigger 500 errors or debug log spam
	 */
	private const SILENCED_ERRORS = array(
		E_WARNING,
		E_NOTICE,
		E_USER_WARNING,
		E_USER_NOTICE,
		E_DEPRECATED,
		E_USER_DEPRECATED,
	);
	
	/**
	 * Permission-related error patterns
	 */
	private const PERMISSION_PATTERNS = array(
		'permission denied',
		'access denied',
		'failed to open stream',
		'no such file or directory',
		'not allowed',
		'unable to access',
		'operation not permitted',
		'read-only file system',
		'cannot create directory',
		'unable to create',
		'failed to create',
		'unable to write',
		'failed to write',
	);
	
	private static ?self $instance = null;
	private array $silenced_operations = array();
	private bool $handler_registered = false;
	
	private function __construct() {
		$this->register_error_handler();
	}
	
	public static function get_instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Register custom error handler for permission issues
	 */
	private function register_error_handler(): void {
		if ($this->handler_registered) {
			return;
		}
		
		// Set custom error handler that catches permission errors
		set_error_handler(array($this, 'handle_error'), E_WARNING | E_NOTICE);
		
		$this->handler_registered = true;
	}
	
	/**
	 * Custom error handler - silences permission-related warnings
	 *
	 * @param int $errno Error number
	 * @param string $errstr Error message
	 * @param string $errfile File where error occurred
	 * @param int $errline Line number
	 * @return bool Whether the error was handled
	 */
	public function handle_error(int $errno, string $errstr, string $errfile, int $errline): bool {
		// Only handle WUDT-related errors
		if (! $this->is_wudt_file($errfile)) {
			return false; // Let PHP handle it
		}
		
		// Check if this is a permission-related error
		if ($this->is_permission_error($errstr)) {
			// Silently log to WUDT internal log only (not debug.log)
			$this->log_permission_error($errno, $errstr, $errfile, $errline);
			
			// Return true to suppress the error
			return true;
		}
		
		// For non-permission errors, let them through
		return false;
	}
	
	/**
	 * Check if file is part of WUDT plugin
	 */
	private function is_wudt_file(string $file): bool {
		$wudt_marker = 'wp-ultimate-diagnostics-toolkit';
		return str_contains($file, $wudt_marker);
	}
	
	/**
	 * Check if error message is permission-related
	 */
	private function is_permission_error(string $message): bool {
		$message_lower = strtolower($message);
		
		foreach (self::PERMISSION_PATTERNS as $pattern) {
			if (str_contains($message_lower, $pattern)) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Log permission error to internal WUDT log (not debug.log)
	 */
	private function log_permission_error(int $errno, string $errstr, string $errfile, int $errline): void {
		// Only log significant errors, skip routine warnings
		$log_entry = array(
			'time' => current_time('mysql'),
			'code' => $errno,
			'message' => $errstr,
			'file' => basename($errfile),
			'line' => $errline,
		);
		
		$existing = (array) get_option('wudt_permission_errors', array());
		$existing[] = $log_entry;
		
		// Keep only last 50 errors
		if (count($existing) > 50) {
			$existing = array_slice($existing, -50);
		}
		
		update_option('wudt_permission_errors', $existing, false);
	}
	
	/**
	 * Safely read file contents - handles permission errors gracefully
	 *
	 * @param string $file_path Full path to file
	 * @param string|null $default_content Content to return if file cannot be read
	 * @return string|null File contents or default content/null
	 */
	public static function safe_read_file(string $file_path, ?string $default_content = null): ?string {
		try {
			// Check if file exists and is readable
			if (! file_exists($file_path)) {
				self::log_silent_error('file_not_found', $file_path);
				return $default_content;
			}
			
			if (! is_readable($file_path)) {
				self::log_silent_error('file_not_readable', $file_path);
				return $default_content;
			}
			
			// Suppress warnings during read operation
			$content = @file_get_contents($file_path);
			
			if (false === $content) {
				self::log_silent_error('file_read_failed', $file_path);
				return $default_content;
			}
			
			return $content;
			
		} catch (\Throwable $e) {
			self::log_silent_error('file_read_exception', $file_path, $e->getMessage());
			return $default_content;
		}
	}
	
	/**
	 * Safely write file - handles permission errors gracefully
	 *
	 * @param string $file_path Full path to file
	 * @param string $content Content to write
	 * @param bool $create_dir Whether to create directory if it doesn't exist
	 * @return bool Success or failure
	 */
	public static function safe_write_file(string $file_path, string $content, bool $create_dir = true): bool {
		try {
			$dir = dirname($file_path);
			
			// Check/create directory
			if (! is_dir($dir)) {
				if (! $create_dir) {
					self::log_silent_error('directory_not_found', $dir);
					return false;
				}
				
				if (! @wp_mkdir_p($dir)) {
					self::log_silent_error('directory_creation_failed', $dir);
					return false;
				}
			}
			
			// Check if directory is writable
			if (! is_writable($dir)) {
				self::log_silent_error('directory_not_writable', $dir);
				return false;
			}
			
			// Suppress warnings during write operation
			$result = @file_put_contents($file_path, $content, LOCK_EX);
			
			if (false === $result) {
				self::log_silent_error('file_write_failed', $file_path);
				return false;
			}
			
			return true;
			
		} catch (\Throwable $e) {
			self::log_silent_error('file_write_exception', $file_path, $e->getMessage());
			return false;
		}
	}
	
	/**
	 * Safely delete file - handles permission errors gracefully
	 *
	 * @param string $file_path Full path to file
	 * @return bool Success or failure
	 */
	public static function safe_delete_file(string $file_path): bool {
		try {
			if (! file_exists($file_path)) {
				return true; // Already gone, consider it success
			}
			
			if (! is_writable($file_path)) {
				self::log_silent_error('file_not_deletable', $file_path);
				return false;
			}
			
			$result = @unlink($file_path);
			
			if (! $result) {
				self::log_silent_error('file_delete_failed', $file_path);
				return false;
			}
			
			return true;
			
		} catch (\Throwable $e) {
			self::log_silent_error('file_delete_exception', $file_path, $e->getMessage());
			return false;
		}
	}
	
	/**
	 * Safely create directory - handles permission errors gracefully
	 *
	 * @param string $dir_path Full path to directory
	 * @param int $permissions Directory permissions (octal)
	 * @return bool Success or failure
	 */
	public static function safe_mkdir(string $dir_path, int $permissions = 0755): bool {
		try {
			if (is_dir($dir_path)) {
				return true; // Already exists
			}
			
			$result = @wp_mkdir_p($dir_path);
			
			if (! $result) {
				self::log_silent_error('mkdir_failed', $dir_path);
				return false;
			}
			
			// Set permissions
			@chmod($dir_path, $permissions);
			
			return true;
			
		} catch (\Throwable $e) {
			self::log_silent_error('mkdir_exception', $dir_path, $e->getMessage());
			return false;
		}
	}
	
	/**
	 * Safely scan directory - handles permission errors gracefully
	 *
	 * @param string $dir_path Full path to directory
	 * @param array<string>|null $default Return value if scan fails
	 * @return array<string>|null Array of files or default/null
	 */
	public static function safe_scandir(string $dir_path, ?array $default = array()): ?array {
		try {
			if (! is_dir($dir_path)) {
				self::log_silent_error('scandir_not_found', $dir_path);
				return $default;
			}
			
			if (! is_readable($dir_path)) {
				self::log_silent_error('scandir_not_readable', $dir_path);
				return $default;
			}
			
			$result = @scandir($dir_path);
			
			if (false === $result) {
				self::log_silent_error('scandir_failed', $dir_path);
				return $default;
			}
			
			return $result;
			
		} catch (\Throwable $e) {
			self::log_silent_error('scandir_exception', $dir_path, $e->getMessage());
			return $default;
		}
	}
	
	/**
	 * Execute callback with suppressed permission errors
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @param T|null $default
	 * @return T|null
	 */
	public static function with_suppressed_errors(callable $callback, $default = null) {
		$previous_handler = set_error_handler(function ($errno, $errstr) {
			$handler = self::get_instance();
			if ($handler->is_permission_error($errstr)) {
				return true; // Suppress
			}
			return false; // Let through
		});
		
		try {
			return $callback();
		} catch (\Throwable $e) {
			self::log_silent_error('callback_exception', '', $e->getMessage());
			return $default;
		} finally {
			restore_error_handler();
		}
	}
	
	/**
	 * Log error silently to WUDT internal log only
	 */
	private static function log_silent_error(string $type, string $target, string $message = ''): void {
		$errors = (array) get_option('wudt_silent_errors', array());
		
		$errors[] = array(
			'time' => current_time('mysql'),
			'type' => $type,
			'target' => $target,
			'message' => $message,
		);
		
		// Keep only last 100 silent errors
		if (count($errors) > 100) {
			$errors = array_slice($errors, -100);
		}
		
		update_option('wudt_silent_errors', $errors, false);
	}
	
	/**
	 * Get logged permission errors for admin display
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_permission_errors(): array {
		return (array) get_option('wudt_permission_errors', array());
	}
	
	/**
	 * Get logged silent errors for admin display
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_silent_errors(): array {
		return (array) get_option('wudt_silent_errors', array());
	}
	
	/**
	 * Clear permission error logs
	 */
	public static function clear_error_logs(): void {
		delete_option('wudt_permission_errors');
		delete_option('wudt_silent_errors');
	}
}

/**
 * Global helper function for safe file operations
 */
if (! function_exists('wudt_safe_read_file')) {
	function wudt_safe_read_file(string $file_path, ?string $default = null): ?string {
		return Permission_Handler::safe_read_file($file_path, $default);
	}
}

if (! function_exists('wudt_safe_write_file')) {
	function wudt_safe_write_file(string $file_path, string $content, bool $create_dir = true): bool {
		return Permission_Handler::safe_write_file($file_path, $content, $create_dir);
	}
}

if (! function_exists('wudt_safe_delete_file')) {
	function wudt_safe_delete_file(string $file_path): bool {
		return Permission_Handler::safe_delete_file($file_path);
	}
}

if (! function_exists('wudt_safe_mkdir')) {
	function wudt_safe_mkdir(string $dir_path, int $permissions = 0755): bool {
		return Permission_Handler::safe_mkdir($dir_path, $permissions);
	}
}

if (! function_exists('wudt_safe_scandir')) {
	function wudt_safe_scandir(string $dir_path, ?array $default = array()): ?array {
		return Permission_Handler::safe_scandir($dir_path, $default);
	}
}
