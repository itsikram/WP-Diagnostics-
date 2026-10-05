<?php
/**
 * Removes Diagnostics Toolkit's settings, tables and working files when the
 * plugin is deleted from the Plugins screen.
 *
 * Backup archives in wp-content/uploads/wudt-backups are kept on purpose: they
 * are the site owner's data. Delete that folder manually if you no longer need them.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Delete a directory created by this plugin.
 */
function wudt_uninstall_delete_tree(string $path): void {
	if (! is_dir($path)) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		if ($item->isDir() && ! $item->isLink()) {
			rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file($item->getPathname());
		}
	}
	rmdir($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Remove this plugin's data from the current site.
 */
function wudt_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('wudt_') . '%',
		$wpdb->esc_like('_transient_wudt_') . '%',
		$wpdb->esc_like('_transient_timeout_wudt_') . '%'
	));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('wudt_') . '%'));
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wudt_merge_source'));

	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wudt_ai_conversations");
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wudt_ai_chat_history");
	// Temporary and rollback tables left by site migrations.
	foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like('wudt_tmp_') . '%')) as $table) {
		$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql((string) $table) . '`');
	}
	foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like('wudt_bak_') . '%')) as $table) {
		$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql((string) $table) . '`');
	}
	// phpcs:enable

	foreach (array('wudt_scheduled_backup_event', 'wudt_backup_continue', 'wudt_mw_scheduled_scan') as $hook) {
		wp_unschedule_hook($hook);
	}
}

if (is_multisite()) {
	foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $wudt_site_id) {
		switch_to_blog((int) $wudt_site_id);
		wudt_uninstall_site();
		restore_current_blog();
	}
} else {
	wudt_uninstall_site();
}

foreach (array('wudt-migrations', 'wudt-ai-backups', 'wudt-quarantine', 'wudt-rescue', 'wudt-fm-tmp') as $wudt_dir) {
	wudt_uninstall_delete_tree(wp_normalize_path(WP_CONTENT_DIR) . '/' . $wudt_dir);
}

$wudt_loader = wp_normalize_path(WPMU_PLUGIN_DIR) . '/wudt-safe-loader.php';
if (is_file($wudt_loader)) {
	wp_delete_file($wudt_loader);
}
