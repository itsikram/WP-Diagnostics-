=== Diagnostics Toolkit – Debug, Error Log, Malware Scan, Backup & Migration ===
Contributors: ikramulislam
Tags: diagnostics, debug, error log, malware scanner, migration
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix site problems from one dashboard: error log, debug mode, plugin conflicts, malware scan, backups, site migration and an AI helper.

== Description ==

**Diagnostics Toolkit** brings the tools you reach for when a WordPress site misbehaves into one admin screen. Read the error log, switch debug mode on safely, find the plugin that causes a conflict, scan for malware, back up, move a site between servers, and ask an AI assistant to explain or fix an error — without FTP or phpMyAdmin.

It is built for site owners, freelancers and agencies who need complete WP diagnostics and repair in one place.

= Debugging and error logs =

* View `debug.log` and the PHP error log in wp-admin, filter by severity and clear them.
* Turn `WP_DEBUG`, `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` on or off; `wp-config.php` is backed up before every change.
* The last fatal error is captured with file, line and the plugin or theme responsible.
* Plugin conflict detector and a cron inspector (run or remove scheduled events).
* REST API tester and a log of outgoing HTTP requests.

= Site health and performance =

* System report: WordPress, PHP, database, server limits, active plugins and theme. Export it as JSON or email it.
* Largest autoloaded options, memory use and page load timing.
* Security quick scan: debug mode on a live site, wp-config.php permissions and sensitive files (.env, .git) left in the web root.
* File integrity check against the official WordPress.org checksums.

= Malware scanner =

* Scans core, plugins, themes and uploads for known malware patterns, backdoors and injected scripts.
* Compares plugin files with the originals on WordPress.org and restores a modified file from the official copy.
* Quarantine or delete infected files; optional daily scheduled scan with email report.

= File manager and database manager =

* Browse, edit, upload, rename, zip and change permissions of files, with a code editor.
* Browse and edit database tables, run SQL in a safe mode, optimize and repair tables, export SQL.
* Search text in files and the database and replace it, with a preview and an optional backup first.

= Backup, restore and site migration =

* Back up the database, plugins, themes and uploads in resumable steps that work on shared hosting. Restore any part, with rollback.
* Push a local site to live, or pull live to local, with the connection key of the other site. Only changed files are sent.
* Choose exactly what to migrate: the database, plugins, themes, uploads, or the entire WordPress folder.
* **Add as new content**: copy posts, pages, custom post types, terms, comments and users into another site as new items, without replacing what is already there.
* Every migration can be rolled back.

= Recovery =

* Safe mode: open wp-admin with every other plugin and the theme disabled, for your browser only — visitors are not affected.
* Optional automatic deactivation of a plugin that keeps crashing the site (off by default).

= AI troubleshooting assistant (optional) =

Connect your own API key for Google Gemini, OpenAI, Anthropic Claude or OpenRouter. The assistant can read error logs and files, explain what went wrong, and — only with your approval — edit files, run database queries or manage plugins. Every change is backed up and can be undone. The assistant is off until you add an API key.

= WP-CLI =

Run diagnostics, malware scans and database maintenance from the command line with `wp diagnostics`.

== External services ==

Diagnostics Toolkit works offline by default. It connects to the external services below only when you use the related feature.

= AI assistant (Google Gemini, OpenAI, Anthropic, OpenRouter) =

Used only after you add your own API key on the AI Assistant settings screen, and only when you send a message or the assistant runs a step you started. The plugin sends your message, the conversation so far and the site information needed to answer it — for example error log lines, the contents of files the assistant opens, database query results and your WordPress, PHP and plugin versions — to the provider you selected. It also asks the provider for its list of available models.

* Google Gemini API (generativelanguage.googleapis.com) — [Terms](https://ai.google.dev/gemini-api/terms), [Privacy Policy](https://policies.google.com/privacy)
* OpenAI API (api.openai.com) — [Terms](https://openai.com/policies/services-agreement/), [Privacy Policy](https://openai.com/policies/privacy-policy/)
* Anthropic API (api.anthropic.com) — [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy)
* OpenRouter (openrouter.ai) — [Terms](https://openrouter.ai/terms), [Privacy Policy](https://openrouter.ai/privacy)

If you ask the assistant to upload an image from a web address, the plugin downloads that image from the address you (or the assistant) provided.

= WordPress.org =

The malware scanner and file integrity check download official file checksums and original plugin and core files from WordPress.org (downloads.wordpress.org, plugins.svn.wordpress.org, core.svn.wordpress.org) when you run a scan or restore a file. Only the plugin slug, version and file path are sent. When you ask the AI assistant to install a plugin or theme, it is downloaded from WordPress.org through WordPress's own installer. [Privacy Policy](https://wordpress.org/about/privacy/)

= Your other sites (site migration) =

When you connect another site and run a push or pull, this site exchanges database rows and files with that site, which you added yourself. Every request is signed with that site's connection key. No third party is involved.

= Your email server (SMTP) =

If you enable SMTP, emails sent by WordPress are delivered through the mail server you enter. Nothing is sent until you configure it.

== Installation ==

1. Install the plugin from **Plugins → Add New** (search for "Diagnostics Toolkit") or upload the zip file.
2. Activate it.
3. Open **Diagnostics Toolkit** in the wp-admin menu (also under **Tools → Diagnostics Toolkit**).

To use the AI assistant, open **AI Assistant → Settings** and add an API key from the provider of your choice.

== Frequently Asked Questions ==

= Is it safe to use on a live site? =

Yes. All tools are limited to administrators (`manage_options`), every form is protected with a nonce, and destructive actions ask for confirmation and keep a backup or rollback copy first.

= Does the plugin change my site on its own? =

No. Automatic deactivation of a crashing plugin is off until you switch it on in the Recovery tab, and the AI assistant asks before each change unless you turn on auto-approve.

= My site shows "There has been a critical error". How do I get in? =

Use the link in WordPress's "Your Site is Experiencing a Technical Issue" email to log in. Then open Diagnostics Toolkit → Recovery and enter safe mode, read the fatal error, and deactivate the plugin or switch the theme that causes it.

= What does "Add as new content" in site migration do? =

Instead of replacing the destination database, it adds the source site's posts, pages, custom post types, media library entries, menus, terms, comments and users as new items with new IDs. Existing content is never changed, content added by an earlier merge is skipped, and a rollback removes everything that was added.

= What is removed when I delete the plugin? =

Settings, its database tables, logs, migration and quarantine files, and the safe-mode helper file. Backup archives in `wp-content/uploads/wudt-backups` are kept so you do not lose them; delete that folder yourself if you no longer need it.

= Does it work on multisite? =

The diagnostics tools work on each site. Site migration does not support multisite networks.

== Changelog ==

= 1.7.0 =
* New: site migration can add content as new items instead of replacing the database.
* New: migrate only active or only deactivated plugins and themes, or the entire WordPress folder.
* New: connecting a site now connects back automatically.
* New: safe mode is started from the Recovery tab.
* Changed: automatic deactivation of crashing plugins is now opt-in.
* Changed: secure HTTPS certificate checks for all remote requests.
* Fixed: migrations now copy only the parts you select.

= 1.6.0 =
* Site migration, backup rollback and the AI agent.

== Upgrade Notice ==

= 1.7.0 =
Adds "Add as new content" migrations and makes automatic plugin deactivation opt-in.
