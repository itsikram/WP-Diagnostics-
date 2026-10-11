=== Diagnostics Toolkit ===
Contributors: ikramulislam
Tags: diagnostics, debug, error log, malware scanner, migration
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix site problems from one screen: error log, debug mode, plugin conflicts, malware scan, backups and site migration.

== Description ==

**Diagnostics Toolkit** brings the tools you reach for when a site misbehaves into one admin screen. Read the error log, switch debug mode on safely, find the plugin that causes a conflict, scan for malware, back up and restore, and move a site between servers.

It is built for site owners, freelancers and agencies. Every tool is limited to administrators.

= Debugging and error logs =

* View `debug.log` and the PHP error log in wp-admin, filter by severity and clear them.
* Turn `WP_DEBUG`, `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` on or off; `wp-config.php` is backed up before every change.
* The last fatal error is captured with file, line and the plugin or theme responsible.
* Plugin conflict detector and a cron inspector (run or remove scheduled events).
* REST API tester and a log of outgoing HTTP requests.

= Site health and performance =

* System report: WordPress, PHP, database, server limits, active plugins and theme. Export it as JSON or email it.
* Largest autoloaded options, database table sizes, memory use and page load timing.
* Security checks: debug mode on a live site, `wp-config.php` permissions and sensitive files (.env, .git) left in the web root.
* File integrity check against the official WordPress.org checksums.

= Malware scanner =

* Scans core, plugins, themes and uploads for known malware patterns, backdoors and injected scripts.
* Checks the database for injected scripts, spam links, hidden administrators and suspicious scheduled tasks.
* Compares plugin files with the originals on WordPress.org and restores a modified file from the official copy.
* Checks WordPress, plugins and themes for known vulnerabilities.
* Quarantine a flagged file in a protected folder or delete it; optional daily scheduled scan with an email report.

= Search and replace in the database =

* Search text in files and in the database.
* Replace text in the database with a preview, a selection of what to change and an undo backup. Serialized data is handled safely.

= Backup, restore and site migration =

* Back up the database, plugins, themes and uploads in resumable steps that work on shared hosting. Restore any part, with rollback.
* Push a local site to live, or pull live to local, using the connection key of the other site. Only changed files are sent.
* Choose exactly what to migrate: the database, plugins, themes, uploads, or the entire WordPress folder.
* **Add as new content**: copy posts, pages, custom post types, terms, comments and users into another site as new items, without replacing what is already there.
* Scheduled push or pull migrations that run in the background.
* Optionally load media from the source site instead of copying the uploads folder.
* Every migration can be rolled back.

= Recovery =

* Safe mode: open wp-admin with every other plugin and the theme disabled, for your browser only. Visitors are not affected.
* Optional automatic deactivation of a plugin that keeps crashing the site (off by default).

= Debug mode =

The debug options change PHP error logging only after an administrator turns them on in Diagnostics Toolkit, and errors are written to `wp-content/debug.log` without being shown to visitors unless `WP_DEBUG_DISPLAY` is switched on.

= WP-CLI =

Run diagnostics, malware scans and database maintenance from the command line with `wp diagnostics`.

== External services ==

Diagnostics Toolkit does not contact any service on its own. It connects to the services below only when you use the related feature.

= WordPress.org =

Used by the malware scanner, the file integrity check and site migration:

* When you run a scan, the plugin downloads official file checksums for WordPress core and for installed plugins from WordPress.org (api.wordpress.org, downloads.wordpress.org). The plugin slug and version, or the WordPress version and locale, are sent.
* When you repair a file, the original copy of that file is downloaded from WordPress.org (core.svn.wordpress.org, plugins.svn.wordpress.org). The slug, version and file path are sent.
* When a migration includes unmodified plugins or themes from WordPress.org, the destination site downloads them from downloads.wordpress.org instead of receiving a copy. The slug and version are sent.

WordPress.org [Terms of Service](https://wordpress.org/about/domains/) and [Privacy Policy](https://wordpress.org/about/privacy/).

= WPVulnerability API =

When you run a malware scan, the plugin asks the WPVulnerability API (www.wpvulnerability.net) whether the installed versions of WordPress, your plugins and your themes have known vulnerabilities. For each item, only its type (core, plugin or theme) and its slug (for WordPress, the version number) are sent; no site address or personal data is sent. Answers are cached for 12 hours. The service is run by the WPVulnerability project: [website](https://www.wpvulnerability.net/), [about the service and its data use](https://wordpress.org/plugins/wpvulnerability/).

= Your other sites (site migration and media source) =

When you connect another site and run a push or pull, this site exchanges database rows and files only with that site, which you added yourself. Every request is signed with that site's connection key. If you turn on "Use media from source website", pages on this site load image and media URLs from the site address you entered. No third party is involved.

= Your email server (SMTP) =

If you enable SMTP, emails sent by WordPress are delivered through the mail server you enter. Nothing is sent until you configure it.

== Installation ==

1. Install the plugin from **Plugins → Add New** (search for "Diagnostics Toolkit") or upload the zip file.
2. Activate it.
3. Open **Diagnostics Toolkit** in the wp-admin menu.

== Frequently Asked Questions ==

= Is it safe to use on a live site? =

Yes. All tools are limited to administrators (`manage_options`), every admin action is protected with a nonce, and destructive actions ask for confirmation and keep a backup or rollback copy first.

= Does the plugin change my site on its own? =

No. Automatic deactivation of a crashing plugin is off until you switch it on in the Recovery tab. Scheduled scans and migrations run only after you create them.

= Can I edit files or run SQL queries with this plugin? =

No. Diagnostics Toolkit has no file editor and no SQL console. It reads logs and files to diagnose problems, and only changes things through specific actions such as restoring an official file from WordPress.org, quarantining a flagged file, or replacing text in the database with a preview and undo.

= My site shows "There has been a critical error". How do I get in? =

Use the link in WordPress's "Your Site is Experiencing a Technical Issue" email to log in. Then open Diagnostics Toolkit → Recovery and enter safe mode, read the fatal error, and deactivate the plugin or switch the theme that causes it.

= What does "Add as new content" in site migration do? =

Instead of replacing the destination database, it adds the source site's posts, pages, custom post types, media library entries, menus, terms, comments and users as new items with new IDs. Existing content is never changed, content added by an earlier merge is skipped, and a rollback removes everything that was added.

= Where are backups stored? =

In `wp-content/uploads/wudt-backups`, protected from direct web access. They are kept when you delete the plugin so you do not lose them; delete that folder yourself if you no longer need it.

= What is removed when I delete the plugin? =

Settings, its database tables, logs, migration and quarantine files, and the safe-mode helper file.

= Does it work on multisite? =

The diagnostics tools work on each site. Site migration does not support multisite networks.

== Changelog ==

= 1.11.0 =
* First release in the WordPress.org plugin directory.
