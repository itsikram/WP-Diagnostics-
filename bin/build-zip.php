<?php
/**
 * Builds the WordPress.org zip: dist/diagnostics-toolkit-<version>.zip with every
 * file tracked by git under a "diagnostics-toolkit/" folder, minus development files.
 *
 * Usage (from the plugin folder): php bin/build-zip.php
 */

// phpcs:disable -- command-line build script, not loaded by WordPress.
if ('cli' !== PHP_SAPI) {
	exit;
}

$root = dirname(__DIR__);
$slug = 'diagnostics-toolkit';
$exclude = array('.gitignore', '.gitattributes', '.distignore', 'bin/', 'dist/', '.claude/', 'cli/README.md');

$header = (string) file_get_contents($root . '/' . $slug . '.php', false, null, 0, 2000);
if (! preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $header, $m)) {
	fwrite(STDERR, "Version header not found.\n");
	exit(1);
}
$version = $m[1];
$readme = (string) file_get_contents($root . '/readme.txt');
if (! preg_match('/^Stable tag:\s*' . preg_quote($version, '/') . '\s*$/m', $readme)) {
	fwrite(STDERR, "readme.txt Stable tag does not match plugin version {$version}.\n");
	exit(1);
}

$files = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files')));
if (empty($files)) {
	fwrite(STDERR, "No files from git ls-files.\n");
	exit(1);
}

@mkdir($root . '/dist');
$zip_path = $root . '/dist/' . $slug . '-' . $version . '.zip';
@unlink($zip_path);
$zip = new ZipArchive();
if (true !== $zip->open($zip_path, ZipArchive::CREATE)) {
	fwrite(STDERR, "Cannot create {$zip_path}\n");
	exit(1);
}
$count = 0;
foreach ($files as $file) {
	foreach ($exclude as $pattern) {
		if ($file === $pattern || ('/' === substr($pattern, -1) && 0 === strpos($file, $pattern))) {
			continue 2;
		}
	}
	if (is_file($root . '/' . $file)) {
		$zip->addFile($root . '/' . $file, $slug . '/' . $file);
		$count++;
	}
}
$zip->close();
echo "Built {$zip_path} ({$count} files, " . round(filesize($zip_path) / 1024) . " KB)\n";
