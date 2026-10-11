/**
 * Diagnostics Toolkit — Site Migration UI.
 *
 * Mounted by pro-admin.js into #wudt-migration-root. Keeps its own state so a
 * running migration survives re-renders of the surrounding admin app.
 */
(function ($) {
	'use strict';

	// Read lazily: this script loads before the localized wudtProAdmin object is printed.
	function cfg() {
		return window.wudtProAdmin || {};
	}
	var TOKEN_KEY = 'wudtMigrationTokens';
	var COMPONENT_LABELS = {
		plugins: 'Plugins',
		themes: 'Themes',
		uploads: 'Media uploads',
		'mu-plugins': 'Must-use plugins',
		languages: 'Languages',
		content: 'Other wp-content files',
		core: 'WordPress core & root files'
	};
	var COMPONENT_HELP = {
		content: 'everything else in wp-content (fonts, custom folders…); caches and backups are skipped',
		core: 'wp-admin, wp-includes and root files; wp-config.php and .htaccess are kept'
	};
	var DEFAULT_COMPONENTS = ['plugins', 'themes', 'uploads'];
	var PHASES = [
		{ key: 'database', label: 'Database' },
		{ key: 'scan', label: 'Prepare files' },
		{ key: 'transfer', label: 'Transfer' },
		{ key: 'packages', label: 'WordPress.org' },
		{ key: 'finalize_files', label: 'Apply' },
		{ key: 'done', label: 'Done' }
	];

	var S = {
		root: null,
		data: null,
		view: 'home',
		busy: false,
		notice: null,
		wizard: null,
		job: null,
		token: '',
		running: false,
		netFailures: 0,
		showManual: false,
		showKey: false
	};

	/* ----------------------------------------------------------------- */

	function esc(v) {
		return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function bytes(n) {
		n = Number(n) || 0;
		var u = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (i ? n.toFixed(1) : n) + ' ' + u[i];
	}

	function num(n) {
		return (Number(n) || 0).toLocaleString();
	}

	// "1:05" or "1:02:05" for a number of seconds.
	function duration(sec) {
		sec = Math.max(0, Math.floor(Number(sec) || 0));
		var h = Math.floor(sec / 3600);
		var m = Math.floor((sec % 3600) / 60);
		var s2 = sec % 60;
		var mm = h ? (m < 10 ? '0' + m : m) : m;
		return (h ? h + ':' : '') + mm + ':' + (s2 < 10 ? '0' + s2 : s2);
	}

	/* -----------------------------------------------------------------
	 * Live progress. Each job publishes a small JSON file after every
	 * sub-step; reading it needs no WordPress request, so the browser can
	 * poll it often while the (longer) step request is still running.
	 * --------------------------------------------------------------- */

	// Server clock minus browser clock, in seconds.
	var clockSkew = 0;

	function serverNow() {
		return Date.now() / 1000 + clockSkew;
	}

	// Accept a newer job snapshot (from a step response or the progress file).
	function mergeJob(owner, job) {
		if (!job || (owner.job && owner.job.id && job.id !== owner.job.id)) { return false; }
		if (owner.job && Number(job.seq || 0) < Number(owner.job.seq || 0)) { return false; }
		if (job.now) { clockSkew = Number(job.now) - Date.now() / 1000; }
		owner.job = job;
		trackSpeed(owner);
		return true;
	}

	// Transfer speed from recent samples of bytes sent.
	function trackSpeed(owner) {
		var j = owner.job;
		var st = (j && j.stats) || {};
		var now = Date.now() / 1000;
		owner.samples = owner.samples || [];
		if (j.phase !== 'transfer') { owner.samples = []; return; }
		var last = owner.samples[owner.samples.length - 1];
		if (!last || last.b !== st.bytes_done) { owner.samples.push({ t: now, b: Number(st.bytes_done) || 0 }); }
		owner.samples = owner.samples.filter(function (x) { return now - x.t <= 30; });
	}

	function speedOf(owner) {
		var sm = owner.samples || [];
		if (sm.length < 2) { return 0; }
		var a = sm[0];
		var b = sm[sm.length - 1];
		return b.t > a.t ? (b.b - a.b) / (b.t - a.t) : 0;
	}

	function startPolling(owner, onUpdate) {
		stopPolling(owner);
		var tick = function () {
			if (!owner.running || !owner.job || !owner.job.progress_url) { return; }
			$.ajax({ url: owner.job.progress_url, dataType: 'json', cache: false, timeout: 5000 }).done(function (p) {
				if (owner.running && mergeJob(owner, p)) { onUpdate(); }
			}).always(function () {
				if (owner.running) { owner.pollTimer = setTimeout(tick, 800); }
			});
		};
		owner.pollTimer = setTimeout(tick, 600);
		// Count the timers up every second without a full re-render.
		owner.clockTimer = setInterval(function () {
			$('[data-mig-since]').each(function () {
				this.textContent = duration(serverNow() - Number(this.getAttribute('data-mig-since')));
			});
		}, 1000);
	}

	function stopPolling(owner) {
		clearTimeout(owner.pollTimer);
		clearInterval(owner.clockTimer);
		owner.pollTimer = null;
		owner.clockTimer = null;
	}

	// Elapsed time, time in the current stage, speed and remaining time.
	function liveMeta(owner) {
		var j = owner.job;
		var st = j.stats || {};
		var live = j.status === 'running' || j.status === 'starting';
		var h = '<div class="wudt-mig-live">';
		if (live) {
			h += '<span class="wudt-mig-live-dot' + (owner.running ? '' : ' is-paused') + '"></span><strong>' + (owner.running ? 'Live' : 'Paused') + '</strong>';
		}
		if (j.created) {
			h += '<span>Elapsed <b' + (live ? ' data-mig-since="' + esc(j.created) + '"' : '') + '>' + esc(duration((live ? serverNow() : j.updated) - j.created)) + '</b></span>';
		}
		if (live && j.phase_started && j.status === 'running') {
			h += '<span>This stage <b data-mig-since="' + esc(j.phase_started) + '">' + esc(duration(serverNow() - j.phase_started)) + '</b></span>';
		}
		if (live && j.phase === 'transfer') {
			var speed = speedOf(owner);
			if (speed > 0) {
				var left = Math.max(0, (Number(st.bytes_total) || 0) - (Number(st.bytes_done) || 0));
				h += '<span>Speed <b>' + esc(bytes(speed)) + '/s</b></span><span>About <b>' + esc(duration(left / speed)) + '</b> left</span>';
			}
		}
		return h + '</div>';
	}

	function when(ts) {
		if (!ts) { return ''; }
		var d = new Date(ts * 1000);
		return d.toLocaleString();
	}

	function ajax(action, data, timeout) {
		return $.ajax({
			url: cfg().ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: timeout || 180000,
			data: $.extend({ action: action, nonce: cfg().nonce }, data || {})
		});
	}

	function errMsg(r, xhr) {
		if (r && r.data && r.data.message) { return r.data.message; }
		if (xhr && xhr.status === 0) { return 'Network error — the request did not reach the server.'; }
		if (xhr && xhr.status) { return 'Server returned HTTP ' + xhr.status + '.'; }
		return 'Unexpected error.';
	}

	function tokens() {
		try { return JSON.parse(window.localStorage.getItem(TOKEN_KEY) || '{}') || {}; } catch (e) { return {}; }
	}

	function saveToken(id, token) {
		try {
			var t = tokens();
			t[id] = token;
			window.localStorage.setItem(TOKEN_KEY, JSON.stringify(t));
		} catch (e) { /* storage unavailable */ }
	}

	function copy(text, $btn) {
		var done = function () {
			var old = $btn.text();
			$btn.text('Copied ✓');
			setTimeout(function () { $btn.text(old); }, 1800);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
		} else {
			legacyCopy(text);
			done();
		}
	}

	function legacyCopy(text) {
		var $t = $('<textarea style="position:fixed;left:-9999px">').val(text).appendTo('body');
		$t[0].select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		$t.remove();
	}

	function setNotice(type, text) {
		S.notice = text ? { type: type, text: text } : null;
	}

	function site(id) {
		var list = (S.data && S.data.sites) || [];
		for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } }
		return null;
	}

	/* ----------------------------------------------------------------- */

	function render() {
		if (!S.root || !document.body.contains(S.root)) { return; }
		var html = '<div class="wudt-mig">';
		html += '<div class="wudt-mig-head"><div><h2>Site Migration</h2>'
			+ '<p>Push this site to a live server or pull a live site here. Only changed files are transferred, the database is switched over in one step, and every migration can be rolled back.</p></div></div>';
		if (S.notice) {
			html += '<div class="wudt-mig-notice is-' + esc(S.notice.type) + '">' + esc(S.notice.text) + '</div>';
		}
		if (!S.data) {
			html += '<div class="wudt-mig-card"><p>Loading…</p></div>';
		} else if (S.view === 'wizard') {
			html += renderWizard();
		} else if (S.view === 'progress') {
			html += renderProgress();
		} else {
			html += renderHome();
		}
		html += '</div>';
		S.root.innerHTML = html;
	}

	function renderHome() {
		var d = S.data;
		var h = '<div id="wudt-mig-peers">' + renderPeers() + '</div>';

		var active = d.active_job;
		if (!active) {
			for (var i = 0; i < (d.history || []).length; i++) {
				var j = d.history[i];
				if (j.status === 'running' || j.status === 'failed') { active = j; break; }
			}
		}
		if (active && (active.status === 'running' || active.status === 'failed')) {
			h += '<div class="wudt-mig-card wudt-mig-resume">'
				+ '<div><strong>' + (active.status === 'failed' ? 'A migration stopped with an error' : (active.stale ? 'A migration was interrupted' : 'A migration is in progress')) + '</strong>'
				+ '<p>' + esc(active.direction === 'pull' ? 'Pull from ' : 'Push to ') + esc(active.site.url) + ' · ' + esc(active.percent) + '% · ' + esc(active.message) + '</p></div>'
				+ '<div class="wudt-mig-actions"><button class="button button-primary" data-mig="resume" data-job="' + esc(active.id) + '">Open</button></div>'
				+ '</div>';
		}

		// This site.
		h += '<div class="wudt-mig-card">'
			+ '<h3>This site’s connection key</h3>'
			+ '<p class="wudt-mig-muted">Copy this key into Diagnostics Toolkit on the other site (Site Migration → Add a site). It contains this site’s address and secret key.</p>'
			+ '<div class="wudt-mig-keyrow"><input type="text" readonly class="wudt-mig-input wudt-mig-mono" id="wudt-mig-conn" value="' + esc(d.connection_string) + '">'
			+ '<button class="button button-primary" data-mig="copy-conn">Copy</button></div>'
			+ '<button type="button" class="button-link" data-mig="toggle-key">' + (S.showKey ? 'Hide details' : 'Show URL and API key separately') + '</button>';
		if (S.showKey) {
			h += '<table class="wudt-mig-kv"><tr><th>Site URL</th><td class="wudt-mig-mono">' + esc(d.local_site_url) + '</td></tr>'
				+ '<tr><th>API key</th><td class="wudt-mig-mono">' + esc(d.local_api_key) + ' <button class="button button-small" data-mig="copy-key">Copy</button></td></tr></table>'
				+ '<p><button class="button" data-mig="regen">Generate a new key</button> <span class="wudt-mig-muted">Sites using the old key will need the new one.</span></p>';
		}
		h += '</div>';

		h += renderLiveMediaCard(d);

		// Local rollback.
		if (d.rollback && d.rollback.available) {
			h += '<div class="wudt-mig-card wudt-mig-rollback">'
				+ '<div><strong>Undo the last migration received by this site</strong>'
				+ '<p>' + esc(when(d.rollback.created)) + ' · ' + esc(d.rollback.tables) + ' tables, ' + esc(d.rollback.files) + ' files can be restored.</p></div>'
				+ '<div class="wudt-mig-actions"><button class="button" data-mig="rollback-local">Roll back</button>'
				+ '<button class="button-link wudt-mig-danger" data-mig="discard-rollback">Delete rollback data</button></div></div>';
		}

		// Sites.
		h += '<div class="wudt-mig-card"><h3>Connected sites</h3>';
		if (!d.sites.length) {
			h += '<p class="wudt-mig-muted">No sites yet. Paste the connection key of your live (or local) site below.</p>';
		} else {
			h += '<div class="wudt-mig-sites">';
			d.sites.forEach(function (s) {
				var chk = s.last_check || null;
				var dot = chk ? (chk.ok ? 'ok' : 'bad') : 'unknown';
				var title = chk ? (chk.ok ? 'Connected' : (chk.error || 'Connection failed')) : 'Not tested yet';
				h += '<div class="wudt-mig-site">'
					+ '<div class="wudt-mig-site-info"><span class="wudt-mig-dot is-' + (s.local_only ? 'unknown' : dot) + '" title="' + esc(s.local_only ? 'On your computer' : title) + '"></span>'
					+ '<div><strong>' + esc(s.label) + '</strong><a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.url) + '</a>'
					+ (chk && !chk.ok && !s.local_only ? '<div class="wudt-mig-error-inline">' + esc(chk.error) + '</div>' : '')
					+ (s.local_only
						? '<div class="wudt-mig-muted">This site is on your computer, which this server cannot connect to. Pull and Push open it in a new tab and run the migration from there; its progress is also shown here.</div>'
						: (chk && chk.auto ? '<div class="wudt-mig-muted">Connected automatically when that site added this one.</div>' : ''))
					+ '</div></div>'
					+ '<div class="wudt-mig-actions">';
				if (s.local_only) {
					// Pulling here = that site pushing to this one, and the other way round.
					h += '<a class="button button-primary" href="' + esc(handoffUrl(s, 'push')) + '" target="_blank" rel="noopener" title="Opens ' + esc(s.label) + ' to push it to this site">⬇ Pull to this site ↗</a>'
						+ '<a class="button button-primary" href="' + esc(handoffUrl(s, 'pull')) + '" target="_blank" rel="noopener" title="Opens ' + esc(s.label) + ' to pull this site there">⬆ Push to this site ↗</a>';
				} else {
					h += '<button class="button button-primary" data-mig="pull" data-site="' + esc(s.id) + '">⬇ Pull to this site</button>'
						+ '<button class="button button-primary" data-mig="push" data-site="' + esc(s.id) + '">⬆ Push to this site</button>'
						+ '<button class="button" data-mig="test" data-site="' + esc(s.id) + '">Test</button>';
				}
				h += '<button class="button-link wudt-mig-danger" data-mig="delete-site" data-site="' + esc(s.id) + '">Remove</button>'
					+ '</div></div>';
			});
			h += '</div>';
		}
		h += '<div class="wudt-mig-add"><h4>Add a site</h4>'
			+ '<div class="wudt-mig-keyrow"><input type="text" class="wudt-mig-input wudt-mig-mono" id="wudt-mig-new-conn" placeholder="Paste the other site’s connection key (wudt:…)">'
			+ '<button class="button button-primary" data-mig="add-conn"' + (S.busy ? ' disabled' : '') + '>Connect</button></div>'
			+ '<button type="button" class="button-link" data-mig="toggle-manual">' + (S.showManual ? 'Hide manual entry' : 'Enter URL and API key manually') + '</button>';
		if (S.showManual) {
			h += '<div class="wudt-mig-manual">'
				+ '<label>Label<input type="text" class="wudt-mig-input" id="wudt-mig-label" placeholder="Live site"></label>'
				+ '<label>Site URL<input type="url" class="wudt-mig-input" id="wudt-mig-url" placeholder="https://example.com"></label>'
				+ '<label>API key<input type="text" class="wudt-mig-input wudt-mig-mono" id="wudt-mig-key" placeholder="48-character key"></label>'
				+ '<button class="button button-primary" data-mig="add-manual"' + (S.busy ? ' disabled' : '') + '>Save and test</button></div>';
		}
		h += '<p class="wudt-mig-muted wudt-mig-help">Both sites need Diagnostics Toolkit active. A live server cannot reach your localhost, so always start migrations from the <strong>local</strong> site: use Pull to bring live here, Push to publish local to live.</p>';
		h += '</div></div>';

		// History.
		var hist = d.history || [];
		if (hist.length) {
			h += '<div class="wudt-mig-card"><h3>History</h3><table class="widefat striped wudt-mig-table"><thead><tr><th>When</th><th>Type</th><th>Site</th><th>Status</th><th>Details</th><th></th></tr></thead><tbody>';
			hist.forEach(function (j) {
				h += '<tr><td>' + esc(when(j.created)) + '</td>'
					+ '<td>' + (j.direction === 'pull' ? '⬇ Pull' : '⬆ Push') + '</td>'
					+ '<td>' + esc(j.site.label || j.site.url) + '</td>'
					+ '<td><span class="wudt-mig-status is-' + esc(j.status) + '">' + esc(j.stale ? 'interrupted' : j.status) + '</span></td>'
					+ '<td class="wudt-mig-muted">' + jobScope(j) + (j.error ? ' — ' + esc(j.error) : '') + '</td>'
					+ '<td class="wudt-mig-actions">'
					+ ((j.status === 'running' || j.status === 'failed') ? '<button class="button button-small" data-mig="resume" data-job="' + esc(j.id) + '">Open</button>' : '')
					+ (j.status === 'done' && j.direction === 'push' && site(j.site.id) ? '<button class="button button-small" data-mig="rollback-remote" data-site="' + esc(j.site.id) + '">Roll back remote</button>' : '')
					+ '<button class="button-link wudt-mig-danger" data-mig="delete-job" data-job="' + esc(j.id) + '">Delete</button></td></tr>';
			});
			h += '</tbody></table></div>';
		}
		return h;
	}

	// What a job included, e.g. "DB: 55 tables · Files: Plugins, Themes (120 files)".
	function jobScope(j) {
		var parts = [];
		if (j.files_count > 0) {
			var names = (j.files || []).slice(0, 3).map(function (f) { return f.p ? f.p.split('/').pop() : (COMPONENT_LABELS[f.c] || f.c); });
			return 'Specific files: ' + esc(names.join(', ')) + (j.files_count > 3 ? ' +' + esc(j.files_count - 3) + ' more' : '')
				+ ' (' + esc(num(j.stats.files_done)) + ' files sent)';
		}
		if (j.tables > 0) {
			parts.push(j.db_mode === 'merge'
				? 'DB: added as new content'
				: (j.db_mode === 'replace_content' ? 'DB: replaced WordPress content' : 'DB: ' + esc(j.stats.tables_done) + ' tables'));
		}
		if (j.live_media) { parts.push('Media: live URLs'); }
		if (j.repo && j.repo.enabled && j.repo.installed) { parts.push(esc(j.repo.installed) + ' from WordPress.org'); }
		if ((j.components || []).length) {
			var sel = j.pt_filter === 'selected' ? (j.pt_selected || {}) : null;
			parts.push('Files: ' + j.components.map(function (c) {
				var n = sel && sel[c] ? ' (' + sel[c].length + ' selected)' : '';
				return esc((COMPONENT_LABELS[c] || c) + n);
			}).join(', ') + ' (' + esc(num(j.stats.files_done)) + ' files)');
		}
		return parts.join(' · ');
	}

	// File components both ends support (older remote versions lack the whole-folder ones).
	// Uploads are left out while media is loaded from the live site.
	function availableComponents(w) {
		var pf = w.preflight || {};
		var feats = ((w.direction === 'pull' ? pf.source : pf.dest) || {}).features || [];
		return (S.data.components || []).filter(function (c) {
			if (c === 'uploads' && w.liveMedia) { return false; }
			return (c !== 'content' && c !== 'core') || feats.indexOf(c) !== -1;
		});
	}

	function remoteSupports(w, feature) {
		var pf = w.preflight || {};
		return (((w.direction === 'pull' ? pf.source : pf.dest) || {}).features || []).indexOf(feature) !== -1;
	}

	// "Live media" card: this site loads its media from another site.
	function renderLiveMediaCard(d) {
		var lm = d.live_media || {};
		if (lm.enabled) {
			return '<div class="wudt-mig-card wudt-mig-lm is-on"><div class="wudt-mig-lm-head"><span class="wudt-mig-lm-icon" aria-hidden="true">🖼</span><div>'
				+ '<strong>Media is loaded from ' + esc(lm.site || 'another site') + '</strong>'
				+ '<p class="wudt-mig-muted">Image and file URLs of this site point to <code>' + esc(lm.url) + '</code>' + (lm.since ? ' (since ' + esc(when(lm.since)) + ')' : '') + '. Nothing is copied or changed in the database; turn this off any time.</p></div></div>'
				+ '<div class="wudt-mig-lm-row"><label for="wudt-mig-lm-mode">Use the live copy for</label>'
				+ '<select id="wudt-mig-lm-mode" class="wudt-mig-select">'
				+ '<option value="missing"' + (lm.mode !== 'all' ? ' selected' : '') + '>Files missing on this site (recommended)</option>'
				+ '<option value="all"' + (lm.mode === 'all' ? ' selected' : '') + '>All media</option></select>'
				+ '<button class="button" data-mig="lm-disable">Turn off</button></div>'
				+ '<p class="wudt-mig-muted wudt-mig-lm-note">“Files missing on this site” keeps working uploads you add here and files WordPress generates (like page-builder CSS).</p></div>';
		}
		if (!(d.sites || []).length) { return ''; }
		return '<details class="wudt-mig-card wudt-mig-lm"' + (S.showLiveMedia ? ' open' : '') + ' data-mig-lm><summary><strong>Live media</strong> <span class="wudt-mig-muted">— use another site’s images instead of copying the uploads folder</span></summary>'
			+ '<p class="wudt-mig-muted">Useful on a local copy of a live site: media URLs point to the live site, so you can skip downloading gigabytes of uploads.</p>'
			+ '<div class="wudt-mig-lm-row"><label for="wudt-mig-lm-site">Load media from</label><select id="wudt-mig-lm-site" class="wudt-mig-select">'
			+ d.sites.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.label + ' — ' + x.url) + '</option>'; }).join('')
			+ '</select><button class="button button-primary" data-mig="lm-enable">Turn on</button></div></details>';
	}

	function restoreSavedOptions(w) {
		var history = (S.data && S.data.history) || [];
		var saved = null;
		for (var i = 0; i < history.length; i++) {
			var job = history[i];
			if (job.site && job.site.id === w.siteId && job.direction === w.direction && Array.isArray(job.selected_tables)) {
				saved = job;
				break;
			}
		}
		if (!saved) { return false; }

		var allTables = (w.preflight.tables || []).map(function (table) { return table.name; });
		w.tables = saved.selected_tables.filter(function (table) { return allTables.indexOf(table) !== -1; });
		w.db = w.tables.length > 0;
		w.tableMode = w.tables.length === allTables.length ? 'all' : 'custom';
		w.components = (saved.components || []).filter(function (component) {
			return availableComponents(w).indexOf(component) !== -1;
		});
		w.skipUnchanged = !!saved.skip_unchanged;
		w.override = !!saved.override;
		w.keepBackup = saved.keep_backup !== false;
		w.repo = !!(saved.repo && saved.repo.enabled);
		w.liveMedia = !!saved.live_media;
		w.liveMediaMode = saved.live_media && saved.live_media.mode === 'all' ? 'all' : 'missing';
		if (saved.files_count > 0 && pickerSupported(w)) {
			w.mode = 'pick';
			w.db = false;
			w.components = [];
			w.files = (saved.files || []).map(function (f) { return { c: f.c, p: f.p, type: '', s: 0 }; });
			return true;
		}
		w.dbMode = saved.db_mode === 'merge' ? 'merge' : (saved.db_mode === 'replace_content' ? 'replace_content' : 'replace');
		w.mergeGroups = (saved.merge_groups || []).filter(function (group) {
			return ['posts', 'terms', 'comments', 'users'].indexOf(group) !== -1;
		});
		w.excludes = Array.isArray(saved.excludes) ? saved.excludes.join('\n') : '';
		w.excludeDev = !!saved.exclude_dev;
		w.ptFilter = ['all', 'active', 'inactive', 'selected'].indexOf(saved.pt_filter) !== -1 ? saved.pt_filter : 'all';
		w.ptSelected = { plugins: [], themes: [] };
		['plugins', 'themes'].forEach(function (component) {
			var items = ((w.preflight.items || {})[component] || []).map(function (item) { return item.id; });
			w.ptSelected[component] = ((saved.pt_selected || {})[component] || []).filter(function (item) {
				return items.indexOf(item) !== -1;
			});
		});
		return true;
	}

	function renderWizard() {
		var w = S.wizard;
		var s = site(w.siteId) || { label: '', url: '' };
		var isPull = w.direction === 'pull';
		var h = '<div class="wudt-mig-card">';
		h += '<div class="wudt-mig-flow">'
			+ '<div class="wudt-mig-flow-box"><span>From</span><strong>' + esc(isPull ? s.label : 'This site') + '</strong><small>' + esc(isPull ? s.url : S.data.local_site_url) + '</small></div>'
			+ '<div class="wudt-mig-flow-arrow">→</div>'
			+ '<div class="wudt-mig-flow-box is-dest"><span>' + (w.dbMode === 'replace_content' ? 'To (selected content replaced)' : 'To (will be overwritten)') + '</span><strong>' + esc(isPull ? 'This site' : s.label) + '</strong><small>' + esc(isPull ? S.data.local_site_url : s.url) + '</small></div>'
			+ '</div>';

		if (w.loading) {
			return h + '<p class="wudt-mig-muted"><span class="spinner is-active wudt-mig-spinner"></span> Connecting and checking both sites…</p></div>';
		}
		if (w.error) {
			return h + '<div class="wudt-mig-notice is-error">' + esc(w.error) + '</div><p><button class="button" data-mig="back">Back</button> <button class="button button-primary" data-mig="retry-preflight">Try again</button></p></div>';
		}
		var pf = w.preflight;
		if (pf.errors && pf.errors.length) {
			h += '<div class="wudt-mig-notice is-error">' + pf.errors.map(esc).join('<br>') + '</div>';
		}
		if (pf.warnings && pf.warnings.length) {
			h += '<ul class="wudt-mig-warnings">' + pf.warnings.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
		}
		if (pf.dest && pf.dest.rollback && pf.dest.rollback.available && !isPull) {
			h += '<p class="wudt-mig-muted">The remote site still has rollback data from ' + esc(when(pf.dest.rollback.created)) + '; it will be replaced by this migration.</p>';
		}

		// The other end must support newer options (the local plugin always does).
		var remoteFeatures = ((isPull ? pf.source : pf.dest) || {}).features || [];
		var supports = function (f) { return remoteFeatures.indexOf(f) !== -1; };
		var allComps = availableComponents(w);
		var isAll = w.db && allComps.length && allComps.every(function (c) { return w.components.indexOf(c) !== -1; });
		var scope = w.mode === 'pick' ? 'pick' : (isAll ? 'all' : (w.db && w.components.length ? 'both' : (w.db ? 'db' : (w.components.length ? 'files' : ''))));
		var canPick = pickerSupported(w);
		h += '<h3>What to migrate</h3><div class="wudt-mig-scope">';
		[['all', 'Entire site'], ['both', 'Database + files'], ['db', 'Database only'], ['files', 'Files only'], ['pick', 'Specific files & folders']].forEach(function (o) {
			var off = o[0] === 'pick' && !canPick;
			h += '<label class="' + (scope === o[0] ? 'is-on' : '') + (off ? ' is-disabled' : '') + '"' + (off ? ' title="Update Diagnostics Toolkit on the remote site to use this"' : '') + '><input type="radio" name="wudt-mig-scope" value="' + o[0] + '"' + (scope === o[0] ? ' checked' : '') + (off ? ' disabled' : '') + '> ' + esc(o[1]) + '</label>';
		});
		h += '</div>';
		if (w.mode === 'pick') {
			h += renderFilePicker(w);
			h += renderOverride(w);
			return h + renderWizardFooter(w, w.files.length > 0 && !(pf.errors && pf.errors.length)) + '</div>';
		}
		h += '<p class="wudt-mig-muted">Only the items ticked below are copied; everything else on the destination is left untouched.</p><div class="wudt-mig-grid">';
		h += '<label class="wudt-mig-check"><input type="checkbox" data-mig-field="db"' + (w.db ? ' checked' : '') + '> <strong>Database</strong><small>' + esc((pf.tables || []).length) + ' tables · ' + esc(bytes(w.dbSize)) + '</small></label>';
		(S.data.components || []).forEach(function (c) {
			var ok = allComps.indexOf(c) !== -1;
			var live = c === 'uploads' && w.liveMedia;
			h += '<label class="wudt-mig-check' + (ok ? '' : ' is-disabled') + '"><input type="checkbox" data-mig-comp="' + esc(c) + '"' + (w.components.indexOf(c) !== -1 ? ' checked' : '') + (ok ? '' : ' disabled') + '> <strong>' + esc(COMPONENT_LABELS[c] || c) + '</strong><small>'
				+ esc(live ? 'not copied — loaded from the live site (see below)' : (ok ? (COMPONENT_HELP[c] || 'only changed files are sent') : 'update Diagnostics Toolkit on the remote site to use this')) + '</small></label>';
		});
		h += '</div>';
		if (isPull) {
			h += '<div class="wudt-mig-sub wudt-mig-lm-opt"><strong class="wudt-mig-sub-title">Media</strong>'
				+ '<label class="wudt-mig-mode"><input type="radio" name="wudt-mig-media" value="copy"' + (w.liveMedia ? '' : ' checked') + '> <strong>Copy media files</strong> — download the uploads folder (tick <em>Media uploads</em> above).</label>'
				+ '<label class="wudt-mig-mode"><input type="radio" name="wudt-mig-media" value="live"' + (w.liveMedia ? ' checked' : '') + '> <strong>Use the live site’s media URLs</strong> — don’t download uploads; this site shows images and files straight from ' + esc(s.label || 'the live site') + '.</label>';
			if (w.liveMedia) {
				h += '<div class="wudt-mig-merge"><label><input type="radio" name="wudt-mig-lmmode" value="missing"' + (w.liveMediaMode !== 'all' ? ' checked' : '') + '> Only for files missing on this site <small>(recommended — uploads you add here still work)</small></label>'
					+ '<label><input type="radio" name="wudt-mig-lmmode" value="all"' + (w.liveMediaMode === 'all' ? ' checked' : '') + '> For all media</label>'
					+ '<p class="wudt-mig-muted">Nothing in the database is changed for this; you can switch it off later under Site Migration → Live media.</p></div>';
			}
			h += '</div>';
		}
		if (allComps.length) {
			h += '<p><button class="button button-small" data-mig="files-all">Select the entire WordPress folder (all files)</button></p>';
		}

		h += renderOverride(w);

		if (w.db) {
			var canMerge = supports('merge');
			if (!canMerge && w.dbMode === 'merge') { w.dbMode = 'replace'; }
			h += '<div class="wudt-mig-sub"><strong class="wudt-mig-sub-title">Database</strong>'
				+ '<label class="wudt-mig-mode"><input type="radio" name="wudt-mig-dbmode" value="replace"' + (w.dbMode === 'replace' ? ' checked' : '') + '> <strong>Replace</strong> — the destination’s tables are overwritten with the source’s (an exact copy).</label>'
				+ '<label class="wudt-mig-mode"><input type="radio" name="wudt-mig-dbmode" value="replace_content"' + (w.dbMode === 'replace_content' ? ' checked' : '') + '> <strong>Replace content only</strong> — replace the destination’s users, posts, pages, custom post types, taxonomies and comments with the source’s. Site settings and plugin-specific tables are preserved. User accounts and passwords will become the source site’s.</label>'
				+ '<label class="wudt-mig-mode' + (canMerge ? '' : ' is-disabled') + '"><input type="radio" name="wudt-mig-dbmode" value="merge"' + (w.dbMode === 'merge' ? ' checked' : '') + (canMerge ? '' : ' disabled') + '> <strong>Add as new content</strong> — keep every existing post, page, user and term on the destination and add the source’s content as new items.'
				+ (canMerge ? '' : ' <em>(update Diagnostics Toolkit on the remote site to use this)</em>') + '</label>';
			if (w.dbMode === 'merge') {
				h += '<div class="wudt-mig-merge">';
				[['posts', 'Posts, pages & all custom post types', 'includes media library entries, menus and products, with their custom fields'],
					['terms', 'Categories, tags & other terms', 'existing terms with the same slug are reused'],
					['comments', 'Comments', 'on the added posts'],
					['users', 'Users', 'users whose email or username already exists are reused; others are added']].forEach(function (g) {
					h += '<label><input type="checkbox" data-mig-group="' + g[0] + '"' + (w.mergeGroups.indexOf(g[0]) !== -1 ? ' checked' : '') + '> ' + esc(g[1]) + ' <small>' + esc(g[2]) + '</small></label>';
				});
				h += '<p class="wudt-mig-muted">Everything gets new IDs, so nothing on the destination is overwritten. Content added by an earlier merge from the same site is skipped. Settings and plugin-specific tables (for example WooCommerce orders) are not merged. Tick <strong>Media uploads</strong> too, so the image files come along. Rollback removes everything that was added.</p></div>';
			} else if (w.dbMode === 'replace_content') {
				h += '<p class="wudt-mig-muted">The WordPress content tables are backed up for rollback before replacement. Plugin-specific tables and site settings are preserved, though plugin data linked to replaced users or posts may need reconciliation. Media files still follow your Files selections.</p>';
			} else {
				h += '<div class="wudt-mig-tmodes"><label><input type="radio" name="wudt-mig-tmode" value="all"' + (w.tableMode === 'all' ? ' checked' : '') + '> All tables</label> '
					+ '<label><input type="radio" name="wudt-mig-tmode" value="custom"' + (w.tableMode === 'custom' ? ' checked' : '') + '> Choose tables</label>'
					+ (w.override ? '' : '<label class="wudt-mig-inline"><input type="checkbox" data-mig-field="skip"' + (w.skipUnchanged ? ' checked' : '') + '> Skip tables that are already identical</label>') + '</div>';
				if (w.tableMode === 'custom') {
					var prefix = (pf.source && pf.source.prefix) || '';
					h += '<div class="wudt-mig-table-tools"><button class="button button-small" data-mig="tables-all">All</button> '
						+ '<button class="button button-small" data-mig="tables-none">None</button> '
						+ '<button class="button button-small" data-mig="tables-content">Content only (no users/options)</button></div>'
						+ '<div class="wudt-mig-tables">';
					(pf.tables || []).forEach(function (t) {
						var checked = w.tables.indexOf(t.name) !== -1;
						h += '<label><input type="checkbox" data-mig-table="' + esc(t.name) + '"' + (checked ? ' checked' : '') + '> <span class="wudt-mig-mono">' + esc(t.name.indexOf(prefix) === 0 ? t.name.substr(prefix.length) : t.name) + '</span> <small>' + esc(num(t.rows)) + ' rows · ' + esc(bytes(t.size)) + '</small></label>';
					});
					h += '</div>';
				}
			}
			h += '</div>';
		}

		if (w.components.indexOf('plugins') !== -1 || w.components.indexOf('themes') !== -1) {
			h += '<div class="wudt-mig-sub"><label class="wudt-mig-block">Plugins &amp; themes to include '
				+ '<select id="wudt-mig-ptfilter" class="wudt-mig-select">'
				+ [['all', 'All (active and deactivated)'], ['active', 'Active only'], ['inactive', 'Deactivated only'], ['selected', 'Choose specific plugins / themes…']].map(function (o) {
					return '<option value="' + o[0] + '"' + (w.ptFilter === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
				}).join('')
				+ '</select></label>';
			if (w.ptFilter === 'selected') {
				h += renderPtPicker(w);
			}
			var canRepo = remoteSupports(w, 'repo');
			h += '<label class="wudt-mig-repo' + (w.repo && canRepo ? ' is-on' : '') + (canRepo ? '' : ' is-disabled') + '"><input type="checkbox" data-mig-field="repo"' + (w.repo && canRepo ? ' checked' : '') + (canRepo ? '' : ' disabled') + '> '
				+ '<strong>Install from WordPress.org when possible</strong> — ' + (isPull ? 'this site' : 'the destination') + ' downloads free plugins and themes straight from WordPress.org instead of '
				+ (isPull ? 'from ' + esc(s.label || 'the remote site') : 'uploading them from this site') + '.'
				+ '<span>' + (canRepo
					? 'Each one is first checked file-by-file against the official release; modified, premium or custom ones are copied as usual.'
					: 'Update Diagnostics Toolkit on the remote site to use this.') + '</span></label>';
			h += '</div>';
		}

		if (w.components.length) {
			h += '<div class="wudt-mig-sub"><label class="wudt-mig-block">Exclude files or folders (optional, one per line, relative to the component folder, wildcards allowed)'
				+ '<textarea class="wudt-mig-input wudt-mig-mono" rows="3" id="wudt-mig-excludes" placeholder="2019/*&#10;my-plugin/cache">' + esc(w.excludes) + '</textarea></label>'
				+ '<label class="wudt-mig-inline"><input type="checkbox" data-mig-field="excludedev"' + (w.excludeDev ? ' checked' : '') + '> Exclude development files '
				+ '<small>(.git, .gitignore, .github, .vscode, .idea, .claude, .cursor, CLAUDE.md, lint / test config, editor swap files…)</small></label></div>';
		}

		var dbOk = w.db && (w.dbMode === 'merge'
			? w.mergeGroups.length > 0 && (pf.tables || []).length > 0
			: ((w.dbMode === 'replace_content' || w.tableMode === 'all') ? (pf.tables || []).length > 0 : w.tables.length > 0));
		var canStart = !(pf.errors && pf.errors.length) && (dbOk || w.components.length > 0) && !ptMissing(w).length;
		h += renderWizardFooter(w, canStart);
		h += '</div>';
		return h;
	}

	function renderBackup(w) {
		var isPull = w.direction === 'pull';
		return '<label class="wudt-mig-backup' + (w.keepBackup ? ' is-on' : '') + '"><input type="checkbox" data-mig-field="backup"' + (w.keepBackup ? ' checked' : '') + '> '
			+ '<strong>Keep a backup so I can roll back</strong> (recommended) — before anything is replaced on ' + esc(isPull ? 'this site' : 'the destination') + ', its current files and tables are kept aside. '
			+ '<span>It takes almost no extra time (they are moved, not copied). Turn it off only to save disk space; the migration then cannot be undone.</span></label>';
	}

	function renderOverride(w) {
		return renderBackup(w) + '<label class="wudt-mig-override' + (w.override ? ' is-on' : '') + '"><input type="checkbox" data-mig-field="override"' + (w.override ? ' checked' : '') + '> '
			+ (w.mode === 'pick'
				? '<strong>Always overwrite</strong> — send every selected file even if the destination already has an identical copy. '
					+ '<span>Normally only files that differ are sent.</span>'
				: '<strong>Override everything</strong> — don’t compare; send every selected file and table and replace them on the destination. '
					+ '<span>Slower, but guarantees an exact copy (use it if a previous migration missed something).</span>')
			+ '</label>';
	}

	function renderWizardFooter(w, canStart) {
		var isPull = w.direction === 'pull';
		var label = w.mode === 'pick'
			? (isPull ? '⬇ Pull ' : '⬆ Push ') + w.files.length + ' selected item' + (w.files.length === 1 ? '' : 's')
			: (isPull ? '⬇ Start pull' : '⬆ Start push');
		return '<div class="wudt-mig-footer"><button class="button" data-mig="back">Cancel</button>'
			+ '<button class="button button-primary button-hero" data-mig="start"' + (canStart && !S.busy ? '' : ' disabled') + '>' + esc(label) + '</button></div>';
	}

	/* ------------------------- Specific files picker ------------------------- */

	// The remote end must support browsing and path-limited jobs.
	function pickerSupported(w) {
		var pf = w.preflight || {};
		var feats = ((w.direction === 'pull' ? pf.source : pf.dest) || {}).features || [];
		return feats.indexOf('paths') !== -1;
	}

	// Path of a migration location relative to the WordPress folder, e.g. "wp-content/themes/x".
	function pathLabel(roots, c, p) {
		var root = roots && roots[c] !== undefined ? roots[c] : (c === 'core' ? '' : c);
		return [root, p].filter(function (x) { return x; }).join('/');
	}

	// Inverse of pathLabel(): the most specific component folder containing a path.
	function locateRel(roots, rel) {
		rel = String(rel || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
		var best = null;
		Object.keys(roots || {}).forEach(function (c) {
			var root = roots[c];
			if (root !== '' && rel !== root && rel.indexOf(root + '/') !== 0) { return; }
			if (!best || root.length > best.root.length) { best = { c: c, root: root }; }
		});
		if (!best) { return null; }
		return { c: best.c, p: best.root === '' ? rel : rel.substr(best.root.length).replace(/^\//, '') };
	}

	function fileKey(f) { return f.c + '|' + f.p; }

	// Selected item that already includes this path (the path itself or a parent folder).
	function coveringItem(w, c, p) {
		var roots = w.pick.roots;
		var label = pathLabel(roots, c, p);
		for (var i = 0; i < w.files.length; i++) {
			var sel = w.files[i];
			var sl = pathLabel(roots, sel.c, sel.p);
			if ((sel.c === c && sel.p === p) || sl === '' || label.indexOf(sl + '/') === 0) { return sel; }
		}
		return null;
	}

	function addPicked(w, item) {
		var roots = w.pick.roots;
		var label = pathLabel(roots, item.c, item.p);
		if (coveringItem(w, item.c, item.p)) { return; }
		// A folder replaces anything already picked inside it.
		w.files = w.files.filter(function (f) {
			var fl = pathLabel(roots, f.c, f.p);
			return !(label === '' || fl.indexOf(label + '/') === 0);
		});
		w.files.push(item);
	}

	function loadPicker(c, p) {
		var w = S.wizard;
		if (!w) { return; }
		w.pick.loading = true;
		w.pick.error = '';
		render();
		ajax('wudt_migration_browse', {
			site_id: w.siteId,
			side: w.direction === 'pull' ? 'remote' : 'local',
			component: c,
			path: p
		}, 90000).done(function (r) {
			if (S.wizard !== w) { return; }
			w.pick.loading = false;
			if (!r || !r.success) { w.pick.error = errMsg(r); render(); return; }
			w.pick.c = r.data.component;
			w.pick.p = r.data.path;
			w.pick.entries = r.data.entries || [];
			w.pick.truncated = !!r.data.truncated;
			w.pick.roots = r.data.roots || {};
			w.pick.filter = '';
			render();
		}).fail(function (xhr) {
			if (S.wizard !== w) { return; }
			w.pick.loading = false;
			w.pick.error = errMsg(null, xhr);
			render();
		});
	}

	function fileIcon(type) {
		return type === 'dir' ? '📁' : (type === 'file' ? '📄' : '📌');
	}

	function renderFilePicker(w) {
		var pk = w.pick;
		var isPull = w.direction === 'pull';
		var s = site(w.siteId) || { label: '' };
		var here = pathLabel(pk.roots, pk.c, pk.p);
		var h = '<p class="wudt-mig-muted">Browse <strong>' + esc(isPull ? s.label : 'this site') + '</strong> (the source) and tick the files or folders to '
			+ (isPull ? 'pull here' : 'push to ' + esc(s.label)) + '. Only these are copied — the database and every other file stay as they are, and replaced files can be rolled back.</p>';
		h += '<div class="wudt-mig-picker">';

		// Browser.
		h += '<div class="wudt-mig-pk-browser"><div class="wudt-mig-pk-bar">';
		[['core', 'WordPress folder'], ['content', 'wp-content'], ['plugins', 'Plugins'], ['themes', 'Themes'], ['uploads', 'Uploads']].forEach(function (j) {
			var on = pk.c === j[0] && pk.p === '';
			h += '<button type="button" class="wudt-mig-chip' + (on ? ' is-on' : '') + '" data-mig="pk-open" data-c="' + j[0] + '" data-p="">' + esc(j[1]) + '</button>';
		});
		h += '<button type="button" class="wudt-mig-chip" data-mig="pk-refresh" title="Reload this folder">↻</button></div>';

		var parts = here ? here.split('/') : [];
		h += '<div class="wudt-mig-pk-crumbs"><button type="button" class="button-link" data-mig="pk-crumb" data-rel="">' + esc(isPull ? (s.label || 'Remote site') : 'This site') + '</button>';
		var acc = '';
		parts.forEach(function (part) {
			acc = acc ? acc + '/' + part : part;
			h += '<span>/</span><button type="button" class="button-link" data-mig="pk-crumb" data-rel="' + esc(acc) + '">' + esc(part) + '</button>';
		});
		h += '</div>';

		h += '<div class="wudt-mig-pk-tools"><input type="search" class="wudt-mig-input" id="wudt-mig-pkfilter" placeholder="Filter this folder…" value="' + esc(pk.filter) + '">'
			+ (here ? '<button type="button" class="button button-small" data-mig="pk-up">↑ Up</button>' : '')
			+ '<button type="button" class="button button-small" data-mig="pk-this">+ Select this folder</button></div>';

		h += '<div class="wudt-mig-pk-list">';
		if (pk.loading) {
			h += '<div class="wudt-mig-pk-empty"><span class="spinner is-active wudt-mig-spinner"></span> Loading…</div>';
		} else if (pk.error) {
			h += '<div class="wudt-mig-pk-empty wudt-mig-error-inline">' + esc(pk.error) + ' <button type="button" class="button button-small" data-mig="pk-refresh">Try again</button></div>';
		} else if (!pk.entries || !pk.entries.length) {
			h += '<div class="wudt-mig-pk-empty">This folder is empty (or holds only files that are never migrated).</div>';
		} else {
			var q = String(pk.filter || '').toLowerCase();
			pk.entries.forEach(function (e) {
				var cover = coveringItem(w, e.c, e.p);
				var inherited = cover && !(cover.c === e.c && cover.p === e.p);
				var name = String(e.name);
				h += '<div class="wudt-mig-pk-row' + (cover ? ' is-picked' : '') + '" data-pk-name="' + esc(name.toLowerCase()) + '"' + (q && name.toLowerCase().indexOf(q) === -1 ? ' style="display:none"' : '') + '>'
					+ '<label class="wudt-mig-pk-check" title="' + (inherited ? 'Included with its folder' : 'Select') + '"><input type="checkbox" data-mig-pk data-c="' + esc(e.c) + '" data-p="' + esc(e.p) + '" data-type="' + esc(e.type) + '" data-s="' + esc(e.s || 0) + '"'
					+ (cover ? ' checked' : '') + (inherited ? ' disabled' : '') + '></label>'
					+ '<span class="wudt-mig-pk-icon">' + fileIcon(e.type) + '</span>'
					+ (e.type === 'dir'
						? '<button type="button" class="button-link wudt-mig-pk-name" data-mig="pk-open" data-c="' + esc(e.c) + '" data-p="' + esc(e.p) + '">' + esc(name) + '</button>'
						: '<span class="wudt-mig-pk-name">' + esc(name) + '</span>')
					+ '<span class="wudt-mig-pk-meta">' + (e.type === 'dir' ? '' : esc(bytes(e.s))) + '</span>'
					+ '<span class="wudt-mig-pk-meta">' + (e.m ? esc(new Date(e.m * 1000).toLocaleDateString()) : '') + '</span>'
					+ '</div>';
			});
			if (pk.truncated) {
				h += '<div class="wudt-mig-pk-empty">Only the first 3,000 entries are shown. Select the whole folder, or type a path on the right.</div>';
			}
		}
		h += '</div></div>';

		// Selection.
		h += '<div class="wudt-mig-pk-basket"><div class="wudt-mig-pk-basket-head"><strong>Selected <span class="wudt-mig-count">' + esc(w.files.length) + '</span></strong>'
			+ (w.files.length ? '<button type="button" class="button-link wudt-mig-danger" data-mig="pk-clear">Clear all</button>' : '') + '</div>';
		if (!w.files.length) {
			h += '<div class="wudt-mig-pk-hint">Tick files or folders on the left.<br><small>A ticked folder includes everything inside it.</small></div>';
		} else {
			h += '<ul class="wudt-mig-pk-picked">';
			w.files.forEach(function (f) {
				var label = pathLabel(pk.roots, f.c, f.p) || 'Entire WordPress folder';
				h += '<li title="' + esc(label) + '"><span class="wudt-mig-pk-icon">' + fileIcon(f.type) + '</span><span class="wudt-mig-pk-path">' + esc(label) + '</span>'
					+ (f.type === 'file' && f.s ? '<small>' + esc(bytes(f.s)) + '</small>' : '')
					+ '<button type="button" class="wudt-mig-pk-x" data-mig="pk-remove" data-key="' + esc(fileKey(f)) + '" aria-label="Remove">×</button></li>';
			});
			h += '</ul>';
		}
		h += '<div class="wudt-mig-pk-add"><input type="text" class="wudt-mig-input wudt-mig-mono" id="wudt-mig-pkpath" placeholder="or type a path, e.g. wp-content/themes/x/style.css">'
			+ '<button type="button" class="button" data-mig="pk-add">Add</button></div>';
		h += '</div></div>';
		return h;
	}

	// Ticked Plugins/Themes components that have nothing chosen in "Choose specific" mode.
	function ptMissing(w) {
		if (w.ptFilter !== 'selected') { return []; }
		return ['plugins', 'themes'].filter(function (c) {
			return w.components.indexOf(c) !== -1 && !w.ptSelected[c].length;
		});
	}

	function ptMatches(item, q) {
		q = (q || '').toLowerCase();
		return !q || (item.name + ' ' + item.id).toLowerCase().indexOf(q) !== -1;
	}

	// Checklist of the source site's plugins and themes for "Choose specific".
	function renderPtPicker(w) {
		var items = (w.preflight && w.preflight.items) || { plugins: [], themes: [] };
		var h = '<div class="wudt-mig-ptpick">'
			+ '<input type="search" class="wudt-mig-input" id="wudt-mig-ptsearch" placeholder="Filter by name…" value="' + esc(w.ptSearch) + '">';
		[['plugins', 'Plugins'], ['themes', 'Themes']].forEach(function (g) {
			var c = g[0];
			if (w.components.indexOf(c) === -1) { return; }
			var list = items[c] || [];
			h += '<div class="wudt-mig-ptgroup"><div class="wudt-mig-table-tools"><strong>' + esc(g[1]) + '</strong> '
				+ '<small class="wudt-mig-muted">' + esc(w.ptSelected[c].length) + ' of ' + esc(list.length) + ' selected</small> '
				+ '<button class="button button-small" data-mig="pt-all" data-pt="' + c + '">All</button> '
				+ '<button class="button button-small" data-mig="pt-none" data-pt="' + c + '">None</button> '
				+ '<button class="button button-small" data-mig="pt-active" data-pt="' + c + '">Active</button></div>'
				+ '<div class="wudt-mig-tables">';
			if (!list.length) {
				h += '<p class="wudt-mig-muted">None found on the source site.</p>';
			}
			list.forEach(function (it) {
				var dst = it.dst_version === null || it.dst_version === undefined
					? 'not on destination'
					: (it.dst_version === it.version ? 'same version on destination' : 'destination has ' + (it.dst_version || '?'));
				h += '<label data-pt-item="' + esc((it.name + ' ' + it.id).toLowerCase()) + '"' + (ptMatches(it, w.ptSearch) ? '' : ' style="display:none"') + '>'
					+ '<input type="checkbox" data-mig-pt="' + c + '" value="' + esc(it.id) + '"' + (w.ptSelected[c].indexOf(it.id) !== -1 ? ' checked' : '') + '> '
					+ esc(it.name) + (it.active ? ' <span class="wudt-mig-status is-done">active</span>' : '')
					+ ' <small>' + (it.version ? 'v' + esc(it.version) + ' · ' : '') + esc(dst) + '</small></label>';
			});
			h += '</div></div>';
		});
		var missing = ptMissing(w);
		if (missing.length) {
			h += '<p class="wudt-mig-error-inline">Tick at least one item under ' + missing.map(function (c) { return COMPONENT_LABELS[c]; }).join(' and ') + ', or untick that component above.</p>';
		}
		h += '<p class="wudt-mig-muted">Only the ticked plugin and theme folders are copied; all others on the destination are left as they are. Tip: choose <strong>Files only</strong> above to migrate just these without touching the database.</p></div>';
		return h;
	}

	function renderProgress() {
		var j = S.job;
		if (!j) { return '<div class="wudt-mig-card"><p>Loading…</p></div>'; }
		var isPull = j.direction === 'pull';
		var starting = j.status === 'starting';
		// Only show the phases this job actually runs.
		var hasFiles = (j.components || []).length > 0;
		var phases = starting ? [{ key: 'prepare', label: 'Prepare' }] : PHASES.filter(function (p) {
			if (p.key === 'database') { return j.tables > 0; }
			if (p.key === 'scan' || p.key === 'transfer') { return hasFiles; }
			if (p.key === 'packages') { return !!(j.repo && j.repo.enabled && (!j.repo.checked || j.repo.eligible > 0)); }
			return true;
		});
		var order = ['database', 'scan', 'transfer', 'packages', 'finalize_files', 'finalize_db', 'cleanup', 'done'];
		var cur = order.indexOf(j.phase);
		var phaseIdx = 0;
		phases.forEach(function (p, idx) {
			var k = order.indexOf(p.key);
			if (k !== -1 && k <= cur) { phaseIdx = idx; }
		});
		if (j.status === 'done') { phaseIdx = phases.length - 1; }

		var h = '<div class="wudt-mig-card">';
		h += '<div class="wudt-mig-progress-head"><h3>' + (isPull ? '⬇ Pulling from ' : '⬆ Pushing to ') + esc(j.site.label || j.site.url) + '</h3>'
			+ '<span>' + (j.override ? '<span class="wudt-mig-status is-override">override</span> ' : '') + '<span class="wudt-mig-status is-' + esc(j.status) + '">' + esc(j.status) + '</span></span></div>';
		h += '<ol class="wudt-mig-phases">';
		phases.forEach(function (p, idx) {
			var cls = idx < phaseIdx ? 'is-done' : (idx === phaseIdx ? 'is-current' : '');
			if (j.status === 'done') { cls = 'is-done'; }
			h += '<li class="' + cls + '">' + esc(p.label) + '</li>';
		});
		h += '</ol>';
		h += progressBar(S, j);
		h += liveMeta(S);

		var st = j.stats || {};
		if (starting) {
			return h + '</div>';
		}
		h += '<div class="wudt-mig-stats">'
			+ (j.tables > 0
				? '<div><span>Tables copied</span><strong>' + esc(st.tables_done) + (st.tables_skipped ? ' <small>(' + esc(st.tables_skipped) + ' unchanged)</small>' : '') + '</strong></div>'
					+ '<div><span>Rows</span><strong>' + esc(num(st.rows)) + '</strong></div>'
				: '<div><span>Database</span><strong>not included</strong></div>')
			+ (hasFiles
				? '<div><span>Files changed</span><strong>' + esc(num(st.files_changed)) + ' <small>of ' + esc(num(st.files_scanned)) + '</small></strong></div>'
					+ '<div><span>Transferred</span><strong>' + esc(bytes(st.bytes_done)) + ' <small>of ' + esc(bytes(st.bytes_total)) + '</small></strong></div>'
				: '<div><span>Files</span><strong>not included</strong></div>')
			+ (j.repo && j.repo.enabled
				? '<div title="' + esc((j.repo.names || []).join(', ')) + '"><span>From WordPress.org</span><strong>'
					+ (j.repo.checked ? esc(j.repo.installed) + ' <small>of ' + esc(j.repo.eligible) + (j.repo.bytes ? ' · ' + esc(bytes(j.repo.bytes)) + ' not copied' : '') + '</small>' : '<small>checking…</small>')
					+ '</strong></div>'
				: '')
			+ (j.live_media ? '<div><span>Media</span><strong><small>loaded from the live site</small></strong></div>' : '')
			+ '</div>';

		if (j.status === 'failed') {
			h += '<div class="wudt-mig-notice is-error"><strong>The migration stopped.</strong> ' + esc(j.error) + '<br>The destination site has not been switched over. You can retry from where it stopped, or cancel to clean up.</div>';
		}
		if (S.connectionLost) {
			h += '<div class="wudt-mig-notice is-warning">Lost contact with this site’s server: ' + esc(S.connectionLost) + '</div>';
		}
		if (j.status === 'done') {
			h += '<div class="wudt-mig-notice is-success"><strong>Migration complete' + (j.created ? ' in ' + esc(duration(j.updated - j.created)) : '') + '.</strong> ';
			if (isPull) {
				h += 'This site now contains the remote site’s content. If the users table was included, <a href="' + esc(S.data.local_site_url) + '/wp-login.php">log in again</a> with the remote site’s credentials.';
				if (j.live_media) { h += ' Media is now loaded from <code>' + esc(j.live_media.url) + '</code>.'; }
			} else {
				h += 'The remote site has been updated. <a href="' + esc(j.site.url) + '" target="_blank" rel="noopener">Open it</a> and check it. If anything is wrong you can roll it back.';
			}
			h += '</div>';
		}
		(j.warnings || []).forEach(function (wmsg) {
			h += '<div class="wudt-mig-notice is-warning">' + esc(wmsg) + '</div>';
		});

		h += '<div class="wudt-mig-footer">';
		if (j.status === 'running') {
			h += '<button class="button" data-mig="cancel-job">Cancel migration</button>';
			if (!S.running) { h += '<button class="button button-primary" data-mig="continue">Continue</button>'; }
		} else if (j.status === 'failed') {
			h += '<button class="button" data-mig="cancel-job">Cancel and clean up</button><button class="button button-primary" data-mig="continue">Retry</button>';
		} else {
			if (j.status === 'done' && j.keep_backup === false) {
				h += '<span class="wudt-mig-muted wudt-mig-nobackup">No backup was kept, so this migration cannot be rolled back.</span>';
			} else if (j.status === 'done' && !isPull && site(j.site.id)) {
				h += '<button class="button" data-mig="rollback-remote" data-site="' + esc(j.site.id) + '">Roll back remote site</button>';
			}
			if (j.status === 'done' && isPull && j.keep_backup !== false) {
				h += '<button class="button" data-mig="rollback-local">Roll back this site</button>';
			}
			h += '<button class="button button-primary" data-mig="home">Back to Site Migration</button>';
		}
		h += '</div>';

		if ((j.log || []).length) {
			h += renderLog(S, j);
		}
		h += '</div>';
		return h;
	}

	function progressBar(owner, j) {
		var live = owner.running && (j.status === 'running' || j.status === 'starting');
		return '<div class="wudt-mig-bar' + (j.status === 'failed' ? ' is-failed' : '') + (live ? ' is-live' : '') + (j.status === 'starting' ? ' is-indeterminate' : '') + '">'
			+ '<div style="width:' + esc(j.status === 'starting' ? 100 : j.percent) + '%"></div></div>'
			+ '<div class="wudt-mig-bar-meta"><span class="wudt-mig-msg">' + esc(j.message) + '</span><strong>' + (j.status === 'starting' ? '' : esc(j.percent) + '%') + '</strong></div>';
	}

	// The open/closed state of the log survives the frequent re-renders.
	function renderLog(owner, j) {
		var log = j.log || [];
		var open = owner.logOpen === undefined ? j.status === 'failed' : owner.logOpen;
		var h = '<details class="wudt-mig-log" data-mig-log' + (open ? ' open' : '') + '><summary>Activity log <small>(' + esc(log.length) + ')</small></summary><ul>';
		log.slice().reverse().forEach(function (l) {
			h += '<li><time>' + esc(new Date(l.t * 1000).toLocaleTimeString()) + '</time> ' + esc(l.m) + '</li>';
		});
		return h + '</ul></details>';
	}

	/* -----------------------------------------------------------------
	 * Migrations another site runs with this one. That site drives the
	 * migration and reports its progress here; running ones are followed
	 * through their progress file, new ones are found by a slower poll.
	 * --------------------------------------------------------------- */

	var P = { pollTimer: null, findTimer: null, clockTimer: null, logOpen: {} };

	function peerJobs() {
		return (S.data && S.data.peer_jobs) || [];
	}

	// No report for 5 minutes: the tab running it on the other site was probably closed.
	function peerStale(j) {
		return j.status === 'running' && serverNow() - Number(j.updated) > 300;
	}

	function peerLive(j) {
		return j.status === 'running' && !peerStale(j);
	}

	function renderPeers() {
		var h = '';
		peerJobs().forEach(function (j) {
			if (j.status !== 'running' && serverNow() - j.updated > 6 * 3600) { return; }
			h += renderPeerJob(j);
		});
		return h;
	}

	function renderPeerJob(j) {
		var receiving = j.role === 'receiving';
		var peer = j.peer || {};
		var live = peerLive(j);
		var stale = peerStale(j);
		var st = j.stats || {};
		var phase = '';
		PHASES.forEach(function (p) { if (p.key === j.phase) { phase = p.label; } });
		if (j.phase === 'finalize_db' || j.phase === 'cleanup') { phase = 'Apply'; }
		var h = '<div class="wudt-mig-card wudt-mig-peer">'
			+ '<div class="wudt-mig-progress-head"><h3>' + (receiving ? '⬇ Receiving from ' : '⬆ Sending to ') + esc(peer.label || peer.url) + '</h3>'
			+ '<span>' + (phase && j.status === 'running' ? '<span class="wudt-mig-muted">' + esc(phase) + '</span> ' : '')
			+ '<span class="wudt-mig-status is-' + esc(stale ? 'failed' : j.status) + '">' + esc(stale ? 'paused' : j.status) + '</span>'
			+ (!live ? ' <button type="button" class="button-link" data-mig="peer-dismiss" data-job="' + esc(j.id) + '">Dismiss</button>' : '')
			+ '</span></div>'
			+ '<p class="wudt-mig-muted">' + esc(receiving
				? (peer.label || 'The other site') + ' is migrating to this site'
				: (peer.label || 'The other site') + ' is copying this site')
			+ ' (<a href="' + esc(peer.url) + '" target="_blank" rel="noopener">' + esc(peer.url) + '</a>). It runs in that site’s browser tab — keep it open until it finishes.</p>';
		h += progressBar({ running: live }, j);
		h += '<div class="wudt-mig-live">'
			+ (live ? '<span class="wudt-mig-live-dot"></span><strong>Live</strong>' : '')
			+ '<span>Elapsed <b' + (live ? ' data-mig-peer-since="' + esc(j.created) + '"' : '') + '>' + esc(duration((live ? serverNow() : j.updated) - j.created)) + '</b></span>'
			+ '<span>Last update <b data-mig-peer-ago="' + esc(j.updated) + '">' + esc(duration(serverNow() - j.updated)) + '</b> ago</span>'
			+ '</div>';
		h += '<div class="wudt-mig-stats">'
			+ (j.tables > 0
				? '<div><span>Tables copied</span><strong>' + esc(st.tables_done || 0) + ' <small>of ' + esc(j.tables) + '</small></strong></div>'
					+ '<div><span>Rows</span><strong>' + esc(num(st.rows)) + '</strong></div>'
				: '<div><span>Database</span><strong>not included</strong></div>')
			+ ((j.components || []).length
				? '<div><span>Files changed</span><strong>' + esc(num(st.files_changed)) + ' <small>of ' + esc(num(st.files_scanned)) + '</small></strong></div>'
					+ '<div><span>Transferred</span><strong>' + esc(bytes(st.bytes_done)) + ' <small>of ' + esc(bytes(st.bytes_total)) + '</small></strong></div>'
				: '<div><span>Files</span><strong>not included</strong></div>')
			+ '</div>';
		if (stale) {
			h += '<div class="wudt-mig-notice is-warning">No progress for ' + esc(duration(serverNow() - j.updated)) + '. The migration is probably paused because its tab on ' + esc(peer.label || peer.url) + ' was closed — open Site Migration there and click Continue.</div>';
		} else if (j.status === 'failed') {
			h += '<div class="wudt-mig-notice is-error"><strong>The migration stopped.</strong> ' + esc(j.error) + '<br>It can be retried or cancelled on ' + esc(peer.label || peer.url) + '.</div>';
		} else if (j.status === 'done') {
			h += '<div class="wudt-mig-notice is-success"><strong>Migration complete' + (j.created ? ' in ' + esc(duration(j.updated - j.created)) : '') + '.</strong> '
				+ (receiving ? 'This site was updated from ' + esc(peer.label || peer.url) + '. If the users table was included, log in again with that site’s username and password.' : 'This site was copied to ' + esc(peer.label || peer.url) + '.')
				+ '</div>';
		} else if (j.status === 'cancelled') {
			h += '<div class="wudt-mig-notice is-warning">' + esc(j.message) + '</div>';
		}
		if ((j.log || []).length) {
			var open = !!P.logOpen[j.id];
			h += '<details class="wudt-mig-log" data-mig-peer-log="' + esc(j.id) + '"' + (open ? ' open' : '') + '><summary>Activity log <small>(' + esc(j.log.length) + ')</small></summary><ul>';
			j.log.slice().reverse().forEach(function (l) {
				h += '<li><time>' + esc(new Date(l.t * 1000).toLocaleTimeString()) + '</time> ' + esc(l.m) + '</li>';
			});
			h += '</ul></details>';
		}
		return h + '</div>';
	}

	// Update only the peer panel, so typing elsewhere on the page is not interrupted.
	function renderPeersOnly() {
		var el = document.getElementById('wudt-mig-peers');
		if (el && S.view === 'home') { el.innerHTML = renderPeers(); }
	}

	function setPeerJobs(list) {
		if (!S.data) { return; }
		var had = peerJobs().map(function (j) { return j.id + ':' + j.seq + ':' + (peerStale(j) ? 1 : 0); }).join();
		S.data.peer_jobs = list || [];
		if (S.data.peer_jobs[0] && S.data.peer_jobs[0].now) { clockSkew = Number(S.data.peer_jobs[0].now) - Date.now() / 1000; }
		var now = peerJobs().map(function (j) { return j.id + ':' + j.seq + ':' + (peerStale(j) ? 1 : 0); }).join();
		if (had !== now) { renderPeersOnly(); }
	}

	function startPeerPolling() {
		if (P.findTimer) { return; }
		// Follow running migrations through their progress files (no WordPress request).
		var follow = function () {
			var running = peerJobs().filter(peerLive);
			if (!running.length || !S.root || !document.body.contains(S.root)) {
				P.pollTimer = setTimeout(follow, 2000);
				return;
			}
			$.when.apply($, running.map(function (j) {
				return $.ajax({ url: j.progress_url, dataType: 'json', cache: false, timeout: 5000 }).done(function (p) {
					if (!p || p.id !== j.id) { return; }
					setPeerJobs(peerJobs().map(function (x) { return x.id === p.id && Number(p.seq) >= Number(x.seq) ? p : x; }));
				});
			})).always(function () { P.pollTimer = setTimeout(follow, 1500); });
		};
		// Look for new or finished migrations now and then.
		var find = function () {
			var delay = peerJobs().some(peerLive) ? 10000 : 15000;
			if (!S.root || !document.body.contains(S.root) || document.hidden) {
				P.findTimer = setTimeout(find, delay);
				return;
			}
			ajax('wudt_migration_peer_jobs', {}, 30000).done(function (r) {
				if (r && r.success) { setPeerJobs(r.data.peer_jobs); }
			}).always(function () { P.findTimer = setTimeout(find, delay); });
		};
		P.pollTimer = setTimeout(follow, 1000);
		P.findTimer = setTimeout(find, 15000);
		P.clockTimer = setInterval(function () {
			$('[data-mig-peer-since]').each(function () {
				this.textContent = duration(serverNow() - Number(this.getAttribute('data-mig-peer-since')));
			});
			$('[data-mig-peer-ago]').each(function () {
				this.textContent = duration(serverNow() - Number(this.getAttribute('data-mig-peer-ago')));
			});
		}, 1000);
	}

	/* -----------------------------------------------------------------
	 * Hand-off: a site on the internet cannot connect to a site on the
	 * user's computer, so it opens that site's Site Migration page with
	 * the matching migration (?wudt_mig_site=<its url>&wudt_mig_dir=…).
	 * --------------------------------------------------------------- */

	function handoffUrl(s, direction) {
		return String(s.admin_url || '').replace(/\/?$/, '/') + 'admin.php?page=wudt-site-migration'
			+ '&wudt_mig_site=' + encodeURIComponent(S.data.local_site_url) + '&wudt_mig_dir=' + direction;
	}

	function sameSite(a, b) {
		var norm = function (u) { return String(u || '').toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, ''); };
		return norm(a) !== '' && norm(a) === norm(b);
	}

	function maybeHandoff() {
		if (S.handoffDone || !S.data || S.running) { return; }
		S.handoffDone = true;
		var params;
		try { params = new URLSearchParams(window.location.search); } catch (e) { return; }
		var url = params.get('wudt_mig_site');
		var dir = params.get('wudt_mig_dir') === 'pull' ? 'pull' : 'push';
		if (!url) { return; }
		params.delete('wudt_mig_site');
		params.delete('wudt_mig_dir');
		try { window.history.replaceState(null, '', window.location.pathname + '?' + params.toString() + window.location.hash); } catch (e) { /* ignore */ }
		var match = null;
		(S.data.sites || []).forEach(function (s) { if (!match && sameSite(s.url, url)) { match = s; } });
		if (!match) {
			setNotice('warning', url + ' asked to ' + (dir === 'push' ? 'receive this site' : 'be copied here') + ', but it is not connected to this site yet. Paste its connection key under “Add a site” first, then use ' + (dir === 'push' ? 'Push' : 'Pull') + '.');
			render();
			return;
		}
		openWizard(match.id, dir);
		setNotice('info', 'Opened from ' + match.url + ': ' + (dir === 'push' ? 'push this site to it' : 'pull it to this site') + '. Its progress is also shown on that site’s Site Migration page.');
		render();
	}

	/* ----------------------------------------------------------------- */

	function refresh() {
		return ajax('wudt_migration_get_state').done(function (r) {
			if (r && r.success) { S.data = r.data; render(); maybeHandoff(); }
		});
	}

	function openWizard(siteId, direction) {
		S.view = 'wizard';
		S.notice = null;
		S.wizard = {
			siteId: siteId, direction: direction, loading: true, error: '', preflight: null,
			db: true, components: DEFAULT_COMPONENTS.slice(), tableMode: 'all', tables: [],
			skipUnchanged: true, override: false, excludes: '', excludeDev: true, dbSize: 0,
			dbMode: 'replace', mergeGroups: ['posts', 'terms', 'comments', 'users'], ptFilter: 'all',
			ptSelected: { plugins: [], themes: [] }, ptSearch: '',
			mode: '', files: [], keepBackup: true,
			repo: direction === 'push', liveMedia: false, liveMediaMode: 'missing',
			pick: { c: 'core', p: '', entries: null, loading: false, error: '', roots: {}, filter: '', truncated: false }
		};
		render();
		ajax('wudt_migration_preflight', { site_id: siteId, direction: direction }, 120000).done(function (r) {
			S.wizard.loading = false;
			if (!r || !r.success) { S.wizard.error = errMsg(r); render(); return; }
			S.wizard.preflight = r.data;
			S.wizard.tables = (r.data.tables || []).map(function (t) { return t.name; });
			S.wizard.dbSize = (r.data.tables || []).reduce(function (a, t) { return a + (Number(t.size) || 0); }, 0);
			if (restoreSavedOptions(S.wizard)) {
				setNotice('info', 'Your previous migration choices for this site and direction have been restored.');
			}
			render();
			if (S.wizard.mode === 'pick') { loadPicker('core', ''); }
		}).fail(function (xhr) {
			S.wizard.loading = false;
			S.wizard.error = errMsg(null, xhr);
			render();
		});
	}

	function startPickJob(w) {
		if (!w.files.length) { return; }
		var s = site(w.siteId) || {};
		var isPull = w.direction === 'pull';
		var labels = w.files.map(function (f) { return pathLabel(w.pick.roots, f.c, f.p) || 'Entire WordPress folder'; });
		var msg = (isPull ? 'Pull these from ' + s.url + ' and replace them on THIS site' : 'Push these to ' + s.url + ' and replace them there') + ':\n\n• '
			+ labels.slice(0, 15).join('\n• ') + (labels.length > 15 ? '\n• …and ' + (labels.length - 15) + ' more' : '')
			+ '\n\nThe database and all other files are not touched. ' + (w.keepBackup ? 'Replaced files are backed up so you can roll back.' : 'NO BACKUP: replaced files cannot be restored.') + ' Continue?';
		if (!window.confirm(msg)) { return; }
		launchJob({
			site_id: w.siteId,
			direction: w.direction,
			components: '[]',
			tables: '[]',
			files: JSON.stringify(w.files.map(function (f) { return { c: f.c, p: f.p }; })),
			override: w.override ? 1 : '',
			keep_backup: w.keepBackup ? 1 : 0,
			db_mode: 'replace'
		});
	}

	// Show the progress screen at once while the job is being created.
	function launchJob(params) {
		var w = S.wizard;
		var s = site(w.siteId) || {};
		S.busy = true;
		S.notice = null;
		S.view = 'progress';
		S.job = {
			id: '', status: 'starting', phase: 'prepare', percent: 0, direction: w.direction,
			site: { id: w.siteId, label: s.label, url: s.url }, components: [], tables: 0, stats: {},
			created: serverNow(), log: [], warnings: [],
			message: 'Contacting ' + (s.label || 'the other site') + ' and preparing the migration…'
		};
		render();
		S.startClock = setInterval(function () {
			$('[data-mig-since]').each(function () {
				this.textContent = duration(serverNow() - Number(this.getAttribute('data-mig-since')));
			});
		}, 1000);
		ajax('wudt_migration_start', params, 120000).done(function (r) {
			clearInterval(S.startClock);
			S.busy = false;
			if (!r || !r.success) {
				S.view = 'wizard';
				S.job = null;
				setNotice('error', errMsg(r));
				render();
				return;
			}
			S.job = null;
			mergeJob(S, r.data.job);
			S.token = r.data.token;
			saveToken(S.job.id, S.token);
			run();
		}).fail(function (xhr) {
			clearInterval(S.startClock);
			S.busy = false;
			S.view = 'wizard';
			S.job = null;
			setNotice('error', errMsg(null, xhr));
			render();
		});
	}

	function startJob() {
		var w = S.wizard;
		if (w.mode === 'pick') { startPickJob(w); return; }
		w.excludes = $('#wudt-mig-excludes').val() || w.excludes;
		var merge = w.dbMode === 'merge';
		var replaceContent = w.dbMode === 'replace_content';
		var allTables = (w.preflight.tables || []).map(function (t) { return t.name; });
		var tables = w.db
			? (merge ? (w.mergeGroups.length ? allTables : []) : (replaceContent || w.tableMode === 'all' ? allTables : w.tables))
			: [];
		if (!tables.length && !w.components.length) { return; }
		if (ptMissing(w).length) { return; }
		var s = site(w.siteId);
		var parts = [];
		if (tables.length) {
			parts.push(merge
				? 'Database: ADD as new content (' + w.mergeGroups.join(', ') + ') — existing content is kept'
				: (replaceContent
					? 'Database: replace target users, posts/pages/custom post types, taxonomies and comments; preserve site settings and plugin-specific tables'
					: tables.length + ' database tables (replaced)'));
		}
		w.components.forEach(function (c) {
			var pt = '';
			if ((c === 'plugins' || c === 'themes') && w.ptFilter !== 'all') {
				pt = w.ptFilter === 'selected' ? ': ' + ptNames(w, c).join(', ') : (w.ptFilter === 'active' ? ' (active only)' : ' (deactivated only)');
			}
			parts.push((COMPONENT_LABELS[c] || c) + pt);
		});
		var skipped = [];
		if (!tables.length) { skipped.push('Database'); }
		(S.data.components || []).forEach(function (c) { if (w.components.indexOf(c) === -1) { skipped.push(COMPONENT_LABELS[c] || c); } });
		var target = w.direction === 'pull' ? 'THIS site (' + S.data.local_site_url + ')' : s.url;
		var useRepo = w.repo && remoteSupports(w, 'repo') && (w.components.indexOf('plugins') !== -1 || w.components.indexOf('themes') !== -1);
		var liveMedia = w.direction === 'pull' && w.liveMedia;
		if (useRepo) { parts.push('Unmodified WordPress.org plugins/themes are installed from WordPress.org'); }
		if (liveMedia) {
			skipped = skipped.filter(function (x) { return x !== COMPONENT_LABELS.uploads; });
			parts.push('Media: loaded from ' + s.url + ' (' + (w.liveMediaMode === 'all' ? 'all media' : 'files missing here') + '), not downloaded');
		}
		if (!window.confirm((replaceContent ? 'Replace selected content on ' : 'Overwrite ') + target + ' with:\n\n• ' + parts.join('\n• ') + (skipped.length ? '\n\nNot touched: ' + skipped.join(', ') : '') + (w.excludeDev && w.components.length ? '\n\nDevelopment files (.git, .vscode, .claude…) are skipped.' : '') + (w.override ? '\n\nOVERRIDE: every file and table is sent and replaced without comparing.' : '') + (w.keepBackup ? '\n\nThe previous version is kept so you can roll back.' : '\n\nNO BACKUP: the previous version is not kept and this cannot be undone.') + ' Continue?')) {
			return;
		}
		launchJob({
			site_id: w.siteId,
			direction: w.direction,
			components: JSON.stringify(w.components),
			tables: JSON.stringify(tables),
			skip_unchanged: w.skipUnchanged && !w.override ? 1 : '',
			override: w.override ? 1 : '',
			excludes: w.excludes,
			exclude_dev: w.excludeDev && w.components.length ? 1 : '',
			db_mode: merge ? 'merge' : (replaceContent ? 'replace_content' : 'replace'),
			merge_groups: JSON.stringify(w.mergeGroups),
			pt_filter: w.ptFilter,
			pt_plugins: JSON.stringify(w.ptFilter === 'selected' ? w.ptSelected.plugins : []),
			pt_themes: JSON.stringify(w.ptFilter === 'selected' ? w.ptSelected.themes : []),
			keep_backup: w.keepBackup ? 1 : 0,
			repo: useRepo ? 1 : '',
			live_media: liveMedia ? 1 : '',
			live_media_mode: w.liveMediaMode
		});
	}

	function ptNames(w, c) {
		var list = ((w.preflight && w.preflight.items) || {})[c] || [];
		return list.filter(function (it) { return w.ptSelected[c].indexOf(it.id) !== -1; }).map(function (it) { return it.name; });
	}

	function run() {
		if (S.running) { return; }
		S.running = true;
		S.netFailures = 0;
		S.connectionLost = '';
		window.addEventListener('beforeunload', warnLeave);
		startPolling(S, render);
		render();
		loop();
	}

	function stop() {
		S.running = false;
		stopPolling(S);
		if (!(Q && Q.running)) { window.removeEventListener('beforeunload', warnLeave); }
	}

	function warnLeave(e) {
		e.preventDefault();
		e.returnValue = 'A migration is running. It will pause if you leave this page.';
		return e.returnValue;
	}

	function loop() {
		if (!S.running || !S.job) { return; }
		ajax('wudt_migration_step', { job_id: S.job.id, token: S.token }, 240000).done(function (r) {
			if (!r || !r.success) {
				stop();
				S.connectionLost = errMsg(r);
				render();
				return;
			}
			S.netFailures = 0;
			S.connectionLost = '';
			mergeJob(S, r.data.job);
			S.job.busy = r.data.job.busy;
			render();
			if (S.job.status === 'running') {
				setTimeout(loop, r.data.job.busy ? 3000 : 250);
			} else {
				stop();
				render();
				refresh();
			}
		}).fail(function (xhr) {
			S.netFailures++;
			if (S.netFailures <= 6) {
				S.connectionLost = errMsg(null, xhr) + ' Retrying (' + S.netFailures + '/6)…';
				render();
				setTimeout(loop, 3000 * S.netFailures);
				return;
			}
			stop();
			S.connectionLost = errMsg(null, xhr) + ' Click Continue to retry.';
			render();
		});
	}

	function resumeJob(jobId) {
		var hist = (S.data.history || []).concat(S.data.active_job ? [S.data.active_job] : []);
		var job = null;
		hist.forEach(function (j) { if (j.id === jobId) { job = j; } });
		if (!job) { return; }
		S.job = null;
		S.logOpen = undefined;
		mergeJob(S, job);
		S.token = tokens()[jobId] || '';
		S.view = 'progress';
		render();
	}

	/* ----------------------------------------------------------------- */

	function onLogToggle(e) {
		var el = e.target;
		if (el && el.hasAttribute && el.hasAttribute('data-mig-lm')) {
			S.showLiveMedia = el.open;
			return;
		}
		if (el && el.hasAttribute && el.hasAttribute('data-mig-peer-log')) {
			P.logOpen[el.getAttribute('data-mig-peer-log')] = el.open;
			return;
		}
		if (!el || !el.hasAttribute || !el.hasAttribute('data-mig-log')) { return; }
		if ($(el).closest('#wudt-qt').length) {
			if (Q) { Q.logOpen = el.open; }
		} else {
			S.logOpen = el.open;
		}
	}

	function bindEvents() {
		$(document).off('.wudtmig');

		$(document).on('click.wudtmig', '#wudt-migration-root [data-mig]', function (e) {
			e.preventDefault();
			var $b = $(this);
			var act = $b.data('mig');
			var siteId = $b.data('site');
			var jobId = $b.data('job');

			switch (act) {
				case 'copy-conn': copy(S.data.connection_string, $b); break;
				case 'copy-key': copy(S.data.local_api_key, $b); break;
				case 'toggle-key': S.showKey = !S.showKey; render(); break;
				case 'toggle-manual': S.showManual = !S.showManual; render(); break;
				case 'regen':
					if (!window.confirm('Generate a new key? Sites that use the current key must be updated.')) { return; }
					ajax('wudt_migration_regenerate_key').done(function (r) { if (r && r.success) { S.data = r.data.state; setNotice('success', 'New key generated.'); render(); } });
					break;
				case 'add-conn':
				case 'add-manual':
					var payload = act === 'add-conn'
						? { connection: $.trim($('#wudt-mig-new-conn').val()) }
						: { label: $('#wudt-mig-label').val(), url: $.trim($('#wudt-mig-url').val()), api_key: $.trim($('#wudt-mig-key').val()) };
					if (act === 'add-conn' && !payload.connection) { setNotice('error', 'Paste the connection key first.'); render(); return; }
					S.busy = true; setNotice('info', 'Connecting…'); render();
					ajax('wudt_migration_save_site', payload, 90000).done(function (r) {
						S.busy = false;
						if (!r || !r.success) { setNotice('error', errMsg(r)); render(); return; }
						S.data = r.data.state;
						S.showManual = false;
						var peer = r.data.peer || '';
						var peerMsg = peer === 'added' ? ' This site was also added to the other site’s Connected sites automatically.'
							: (peer === 'unsupported' ? ' Update Diagnostics Toolkit on the other site so it connects back automatically.'
								: (peer.indexOf('failed') === 0 ? ' Could not add this site on the other site automatically (' + peer.substr(8) + ').' : ''));
						setNotice(r.data.warning ? 'warning' : 'success', r.data.warning ? 'Site saved, but the connection test failed: ' + r.data.warning : 'Site connected.' + peerMsg);
						render();
					}).fail(function (xhr) { S.busy = false; setNotice('error', errMsg(null, xhr)); render(); });
					break;
				case 'test':
					$b.prop('disabled', true).text('Testing…');
					ajax('wudt_migration_test_connection', { site_id: siteId }, 90000).done(function (r) {
						setNotice(r && r.success ? 'success' : 'error', r && r.success ? r.data.message : errMsg(r));
						refresh();
					}).fail(function (xhr) { setNotice('error', errMsg(null, xhr)); render(); });
					break;
				case 'delete-site':
					if (!window.confirm('Remove this site connection? No data is deleted.')) { return; }
					ajax('wudt_migration_delete_site', { site_id: siteId }).done(function (r) { if (r && r.success) { S.data = r.data.state; render(); } });
					break;
				case 'pull':
				case 'push':
					openWizard(siteId, act);
					break;
				case 'retry-preflight':
					openWizard(S.wizard.siteId, S.wizard.direction);
					break;
				case 'back':
				case 'home':
					S.view = 'home'; S.wizard = null; S.job = null; S.notice = null; render(); refresh();
					break;
				case 'tables-all':
					S.wizard.tables = (S.wizard.preflight.tables || []).map(function (t) { return t.name; }); render();
					break;
				case 'files-all':
					S.wizard.components = availableComponents(S.wizard); render();
					break;
				case 'pt-all':
				case 'pt-none':
				case 'pt-active':
					var ptc = $b.data('pt');
					var ptList = ((S.wizard.preflight && S.wizard.preflight.items) || {})[ptc] || [];
					S.wizard.ptSelected[ptc] = act === 'pt-none' ? [] : ptList.filter(function (it) {
						return (act === 'pt-all' || it.active) && ptMatches(it, S.wizard.ptSearch);
					}).map(function (it) { return it.id; });
					render();
					break;
				case 'tables-none':
					S.wizard.tables = []; render();
					break;
				case 'tables-content':
					var pfx = (S.wizard.preflight.source && S.wizard.preflight.source.prefix) || '';
					var skip = ['users', 'usermeta', 'options'];
					S.wizard.tables = (S.wizard.preflight.tables || []).map(function (t) { return t.name; }).filter(function (n) {
						return skip.indexOf(n.substr(pfx.length)) === -1;
					});
					render();
					break;
				case 'start':
					startJob();
					break;
				case 'lm-enable':
					var lmSite = String($('#wudt-mig-lm-site').val() || '');
					var lmLabel = (site(lmSite) || {}).url || 'that site';
					if (!window.confirm('Load this site’s media from ' + lmLabel + '?\n\nImages and files that are missing here will be shown from that site. Nothing is copied or changed in the database.')) { return; }
					$b.prop('disabled', true).text('Connecting…');
					ajax('wudt_migration_live_media', { op: 'enable', site_id: lmSite, mode: 'missing' }, 90000).done(function (r) {
						if (r && r.success) { S.data = r.data.state; setNotice('success', 'Live media is on.'); } else { setNotice('error', errMsg(r)); }
						render();
					}).fail(function (xhr) { setNotice('error', errMsg(null, xhr)); render(); });
					break;
				case 'lm-disable':
					if (!window.confirm('Stop loading media from the live site? Images that are not on this site will be broken until you copy the uploads folder.')) { return; }
					ajax('wudt_migration_live_media', { op: 'disable' }).done(function (r) {
						if (r && r.success) { S.data = r.data.state; setNotice('success', 'Live media is off. This site uses its own uploads folder again.'); } else { setNotice('error', errMsg(r)); }
						render();
					});
					break;
				case 'pk-open':
					loadPicker(String($b.data('c')), String($b.data('p') || ''));
					break;
				case 'pk-refresh':
					loadPicker(S.wizard.pick.c, S.wizard.pick.p);
					break;
				case 'pk-crumb':
				case 'pk-up':
					var pk = S.wizard.pick;
					var rel = act === 'pk-crumb' ? String($b.data('rel') || '') : pathLabel(pk.roots, pk.c, pk.p).split('/').slice(0, -1).join('/');
					var loc = locateRel(pk.roots, rel) || { c: 'core', p: '' };
					loadPicker(loc.c, loc.p);
					break;
				case 'pk-this':
					addPicked(S.wizard, { c: S.wizard.pick.c, p: S.wizard.pick.p, type: 'dir', s: 0 });
					render();
					break;
				case 'pk-remove':
					var key = String($b.data('key'));
					S.wizard.files = S.wizard.files.filter(function (f) { return fileKey(f) !== key; });
					render();
					break;
				case 'pk-clear':
					S.wizard.files = [];
					render();
					break;
				case 'pk-add':
					var typed = $.trim($('#wudt-mig-pkpath').val() || '');
					var found = typed && typed.indexOf('..') === -1 ? locateRel(S.wizard.pick.roots, typed) : null;
					if (!found) {
						setNotice('error', 'Enter a path inside the WordPress folder, e.g. wp-content/themes/my-theme/style.css');
						render();
						return;
					}
					addPicked(S.wizard, { c: found.c, p: found.p, type: '', s: 0 });
					setNotice(null);
					render();
					break;
				case 'resume':
					resumeJob(jobId);
					break;
				case 'peer-dismiss':
					S.data.peer_jobs = peerJobs().filter(function (x) { return x.id !== jobId; });
					renderPeersOnly();
					ajax('wudt_migration_peer_jobs', { dismiss: jobId }).done(function (r) {
						if (r && r.success) { setPeerJobs(r.data.peer_jobs); }
					});
					break;
				case 'continue':
					run();
					break;
				case 'cancel-job':
					if (!window.confirm('Cancel this migration? Temporary data will be removed; the destination site stays as it was.')) { return; }
					stop();
					ajax('wudt_migration_cancel', { job_id: S.job.id, token: S.token }, 120000).done(function (r) {
						if (r && r.success) { S.job = r.data.job; } else { setNotice('error', errMsg(r)); }
						render(); refresh();
					}).fail(function (xhr) { setNotice('error', errMsg(null, xhr)); render(); });
					break;
				case 'delete-job':
					if (!window.confirm('Delete this entry from the history?')) { return; }
					ajax('wudt_migration_delete_job', { job_id: jobId }).done(function (r) {
						if (r && r.success) { S.data = r.data.state; } else { setNotice('error', errMsg(r)); }
						render();
					});
					break;
				case 'rollback-local':
				case 'rollback-remote':
					var label = act === 'rollback-local' ? 'this site' : ((site(siteId) || {}).url || 'the remote site');
					if (!window.confirm('Roll back ' + label + ' to how it was before the last migration?')) { return; }
					$b.prop('disabled', true).text('Rolling back…');
					ajax('wudt_migration_rollback', { site_id: act === 'rollback-local' ? 'local' : siteId }, 300000).done(function (r) {
						setNotice(r && r.success ? 'success' : 'error', r && r.success ? r.data.message : errMsg(r));
						S.view = 'home'; render(); refresh();
					}).fail(function (xhr) { setNotice('error', errMsg(null, xhr)); render(); });
					break;
				case 'discard-rollback':
					if (!window.confirm('Delete the rollback copy? You will no longer be able to undo the last migration.')) { return; }
					ajax('wudt_migration_discard_rollback').done(function (r) { if (r && r.success) { S.data = r.data.state; render(); } });
					break;
			}
		});

		$(document).on('change.wudtmig', '#wudt-migration-root [data-mig-field], #wudt-migration-root [data-mig-comp], #wudt-migration-root [data-mig-table], #wudt-migration-root input[name="wudt-mig-tmode"], #wudt-migration-root input[name="wudt-mig-scope"], #wudt-migration-root input[name="wudt-mig-dbmode"], #wudt-migration-root input[name="wudt-mig-media"], #wudt-migration-root input[name="wudt-mig-lmmode"], #wudt-migration-root [data-mig-group], #wudt-migration-root [data-mig-pt], #wudt-mig-ptfilter', function () {
			var w = S.wizard;
			if (!w) { return; }
			w.excludes = $('#wudt-mig-excludes').val() || w.excludes;
			var $i = $(this);
			if ($i.is('[data-mig-field="db"]')) { w.db = $i.is(':checked'); }
			if ($i.is('[data-mig-field="skip"]')) { w.skipUnchanged = $i.is(':checked'); }
			if ($i.is('[data-mig-field="override"]')) { w.override = $i.is(':checked'); }
			if ($i.is('[data-mig-field="backup"]')) { w.keepBackup = $i.is(':checked'); }
			if ($i.is('[data-mig-field="repo"]')) { w.repo = $i.is(':checked'); }
			if ($i.is('[name="wudt-mig-media"]')) {
				w.liveMedia = $i.val() === 'live';
				if (w.liveMedia) { w.components = w.components.filter(function (c) { return c !== 'uploads'; }); }
			}
			if ($i.is('[name="wudt-mig-lmmode"]')) { w.liveMediaMode = $i.val() === 'all' ? 'all' : 'missing'; }
			if ($i.is('[data-mig-field="excludedev"]')) { w.excludeDev = $i.is(':checked'); }
			if ($i.is('[name="wudt-mig-tmode"]')) { w.tableMode = $i.val(); }
			if ($i.is('[name="wudt-mig-scope"]')) {
				var sc = $i.val();
				var all = availableComponents(w);
				if (sc === 'pick') {
					w.mode = 'pick';
					w.db = false;
					w.components = [];
					render();
					if (!w.pick.entries) { loadPicker('core', ''); }
					return;
				}
				if (w.mode === 'pick') {
					w.mode = '';
					w.components = [];
				}
				w.db = sc !== 'files';
				if (sc === 'db') {
					w.components = [];
				} else if (sc === 'all') {
					w.components = all;
				} else if (!w.components.length || (sc === 'both' && w.components.length === all.length)) {
					w.components = DEFAULT_COMPONENTS.slice();
				}
			}
			if ($i.is('[name="wudt-mig-dbmode"]')) { w.dbMode = $i.val(); }
			if ($i.is('#wudt-mig-ptfilter')) { w.ptFilter = $i.val(); }
			var group = $i.data('mig-group');
			if (group) {
				w.mergeGroups = w.mergeGroups.filter(function (g) { return g !== group; });
				if ($i.is(':checked')) { w.mergeGroups.push(group); }
			}
			var ptGroup = $i.data('mig-pt');
			if (ptGroup) {
				var ptId = String($i.val());
				w.ptSelected[ptGroup] = w.ptSelected[ptGroup].filter(function (x) { return x !== ptId; });
				if ($i.is(':checked')) { w.ptSelected[ptGroup].push(ptId); }
			}
			var comp = $i.data('mig-comp');
			if (comp) {
				w.components = w.components.filter(function (c) { return c !== comp; });
				if ($i.is(':checked')) { w.components.push(comp); }
			}
			var table = $i.data('mig-table');
			if (table) {
				w.tables = w.tables.filter(function (t) { return t !== table; });
				if ($i.is(':checked')) { w.tables.push(table); }
			}
			render();
		});

		document.removeEventListener('toggle', onLogToggle, true);
		document.addEventListener('toggle', onLogToggle, true);

		$(document).on('change.wudtmig', '#wudt-mig-lm-mode', function () {
			ajax('wudt_migration_live_media', { op: 'mode', mode: $(this).val() }).done(function (r) {
				if (r && r.success) { S.data = r.data.state; setNotice('success', 'Saved.'); } else { setNotice('error', errMsg(r)); }
				render();
			});
		});

		$(document).on('change.wudtmig', '#wudt-migration-root [data-mig-pk]', function () {
			var w = S.wizard;
			if (!w) { return; }
			var $i = $(this);
			var item = { c: String($i.data('c')), p: String($i.data('p') || ''), type: String($i.data('type')), s: Number($i.data('s')) || 0 };
			if ($i.is(':checked')) {
				addPicked(w, item);
			} else {
				w.files = w.files.filter(function (f) { return fileKey(f) !== fileKey(item); });
			}
			render();
		});

		$(document).on('keydown.wudtmig', '#wudt-mig-pkpath', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); $('#wudt-migration-root [data-mig="pk-add"]').trigger('click'); }
		});

		$(document).on('input.wudtmig', '#wudt-mig-pkfilter', function () {
			if (!S.wizard) { return; }
			var q = (S.wizard.pick.filter = $(this).val()).toLowerCase();
			$('#wudt-migration-root [data-pk-name]').each(function () {
				this.style.display = !q || this.getAttribute('data-pk-name').indexOf(q) !== -1 ? '' : 'none';
			});
		});

		$(document).on('input.wudtmig', '#wudt-mig-excludes', function () {
			if (S.wizard) { S.wizard.excludes = $(this).val(); }
		});

		// Filter in place so the search box keeps focus while typing.
		$(document).on('input.wudtmig', '#wudt-mig-ptsearch', function () {
			if (!S.wizard) { return; }
			var q = (S.wizard.ptSearch = $(this).val()).toLowerCase();
			$('#wudt-migration-root [data-pt-item]').each(function () {
				this.style.display = !q || this.getAttribute('data-pt-item').indexOf(q) !== -1 ? '' : 'none';
			});
		});
	}

	/* -----------------------------------------------------------------
	 * Quick transfer dialog: push or pull selected files from anywhere
	 * (used by the file manager).
	 * --------------------------------------------------------------- */

	var Q = null;

	function migrationPageUrl() {
		return String(cfg().ajaxUrl || 'admin-ajax.php').replace(/admin-ajax\.php.*$/, '') + 'admin.php?page=wudt-site-migration';
	}

	function openTransfer(paths, opts) {
		opts = opts || {};
		if (Q && Q.running) {
			return; // A transfer is already on screen.
		}
		closeTransfer(true);
		Q = {
			paths: (paths || []).slice(0, 500),
			direction: opts.direction === 'pull' ? 'pull' : 'push',
			onDone: typeof opts.onDone === 'function' ? opts.onDone : null,
			view: 'setup',
			loading: true,
			error: '',
			sites: [],
			siteId: '',
			items: [],
			override: false,
			keepBackup: true,
			busy: false,
			job: null,
			token: '',
			running: false,
			failures: 0,
			lost: ''
		};
		$('body').append('<div class="wudt-qt-backdrop" id="wudt-qt"><div class="wudt-mig wudt-qt" role="dialog" aria-modal="true" aria-labelledby="wudt-qt-title"></div></div>');
		bindTransferEvents();
		qRender();
		$.when(
			ajax('wudt_migration_get_state'),
			ajax('wudt_migration_locate', { paths: Q.paths })
		).done(function (a, b) {
			if (!Q) { return; }
			var st = a[0];
			var loc = b[0];
			Q.loading = false;
			if (!st || !st.success) { Q.error = errMsg(st); qRender(); return; }
			if (!loc || !loc.success) { Q.error = errMsg(loc); qRender(); return; }
			S.data = S.data || st.data;
			Q.sites = st.data.sites || [];
			Q.active = st.data.active_job || null;
			Q.items = loc.data.items || [];
			var remembered = '';
			try { remembered = window.localStorage.getItem('wudtQtSite') || ''; } catch (e) { /* storage unavailable */ }
			Q.siteId = Q.sites.some(function (x) { return x.id === remembered; }) ? remembered : (Q.sites[0] ? Q.sites[0].id : '');
			qRender();
		}).fail(function (xhr) {
			if (!Q) { return; }
			Q.loading = false;
			Q.error = errMsg(null, xhr);
			qRender();
		});
	}

	function closeTransfer(force) {
		if (!Q) { return; }
		if (!force && Q.running && !window.confirm('The transfer pauses while this window is closed. You can resume it later from Site Migration. Close anyway?')) {
			return;
		}
		qStop();
		$(document).off('.wudtqt');
		$('#wudt-qt').remove();
		Q = null;
	}

	function qSite() {
		for (var i = 0; i < Q.sites.length; i++) { if (Q.sites[i].id === Q.siteId) { return Q.sites[i]; } }
		return null;
	}

	function qValidItems() {
		return Q.items.filter(function (it) { return !it.error; });
	}

	function qRender() {
		var $box = $('#wudt-qt .wudt-qt');
		if (!Q || !$box.length) { return; }
		var h = '<div class="wudt-qt-head"><h2 id="wudt-qt-title">⇅ Push or pull files</h2>'
			+ '<button type="button" class="wudt-qt-close" data-qt="close" aria-label="Close">×</button></div>';
		h += '<div class="wudt-qt-body">' + (Q.view === 'progress' ? qProgress() : qSetup()) + '</div>';
		$box.html(h);
	}

	function qSetup() {
		if (Q.loading) {
			return '<p class="wudt-mig-muted"><span class="spinner is-active wudt-mig-spinner"></span> Loading connected sites…</p>';
		}
		if (Q.error) {
			return '<div class="wudt-mig-notice is-error">' + esc(Q.error) + '</div><div class="wudt-mig-footer"><button class="button" data-qt="close">Close</button></div>';
		}
		if (!Q.sites.length) {
			return '<div class="wudt-qt-empty"><div class="wudt-qt-empty-icon">🔗</div><strong>No connected sites yet</strong>'
				+ '<p class="wudt-mig-muted">Connect your live or local site once in Site Migration (paste its connection key), then push or pull files from here.</p>'
				+ '<a class="button button-primary" href="' + esc(migrationPageUrl()) + '">Open Site Migration</a></div>';
		}
		var isPull = Q.direction === 'pull';
		var s = qSite() || {};
		var valid = qValidItems();
		var h = '';
		if (Q.notice) {
			h += '<div class="wudt-mig-notice is-' + esc(Q.notice.type) + '">' + esc(Q.notice.text) + '</div>';
		}
		if (Q.active && Q.active.status === 'running' && !Q.active.stale) {
			h += '<div class="wudt-mig-notice is-warning">Another migration is running. Finish or cancel it in <a href="' + esc(migrationPageUrl()) + '">Site Migration</a> first.</div>';
		}

		h += '<div class="wudt-qt-dir" role="radiogroup" aria-label="Direction">'
			+ '<button type="button" class="wudt-qt-dir-btn' + (!isPull ? ' is-on' : '') + '" data-qt="dir" data-dir="push" role="radio" aria-checked="' + (!isPull) + '"><span>⬆</span><strong>Push</strong><small>Send this site’s copy to the other site</small></button>'
			+ '<button type="button" class="wudt-qt-dir-btn' + (isPull ? ' is-on' : '') + '" data-qt="dir" data-dir="pull" role="radio" aria-checked="' + isPull + '"><span>⬇</span><strong>Pull</strong><small>Replace them here with the other site’s copy</small></button>'
			+ '</div>';

		h += '<label class="wudt-qt-label" for="wudt-qt-site">' + (isPull ? 'Pull from' : 'Push to') + '</label>'
			+ '<select id="wudt-qt-site" class="wudt-mig-input">' + Q.sites.map(function (x) {
				return '<option value="' + esc(x.id) + '"' + (x.id === Q.siteId ? ' selected' : '') + '>' + esc(x.label + ' — ' + x.url) + '</option>';
			}).join('') + '</select>';

		h += '<div class="wudt-mig-flow wudt-qt-flow">'
			+ '<div class="wudt-mig-flow-box"><span>From</span><strong>' + esc(isPull ? s.label : 'This site') + '</strong><small>' + esc(isPull ? s.url : (S.data && S.data.local_site_url) || '') + '</small></div>'
			+ '<div class="wudt-mig-flow-arrow">→</div>'
			+ '<div class="wudt-mig-flow-box is-dest"><span>To (files replaced)</span><strong>' + esc(isPull ? 'This site' : s.label) + '</strong><small>' + esc(isPull ? (S.data && S.data.local_site_url) || '' : s.url) + '</small></div>'
			+ '</div>';

		h += '<div class="wudt-qt-label">' + esc(valid.length) + ' item' + (valid.length === 1 ? '' : 's') + ' to ' + (isPull ? 'pull' : 'push') + '</div><ul class="wudt-qt-items">';
		Q.items.forEach(function (it, idx) {
			h += '<li class="' + (it.error ? 'is-error' : '') + '"><span class="wudt-mig-pk-icon">' + (it.type === 'dir' ? '📁' : '📄') + '</span>'
				+ '<span class="wudt-mig-pk-path" title="' + esc(it.label) + '">' + esc(it.label) + '</span>'
				+ (it.error ? '<small class="wudt-mig-error-inline">' + esc(it.error) + '</small>' : (it.type === 'file' ? '<small>' + esc(bytes(it.s)) + '</small>' : '<small>folder</small>'))
				+ '<button type="button" class="wudt-mig-pk-x" data-qt="remove" data-idx="' + idx + '" aria-label="Remove">×</button></li>';
		});
		h += '</ul>';
		if (isPull) {
			h += '<p class="wudt-mig-muted wudt-qt-note">The same paths are read from ' + esc(s.label || 'the other site') + '. Anything missing there is skipped and reported.</p>';
		}

		h += '<label class="wudt-mig-backup' + (Q.keepBackup ? ' is-on' : '') + '"><input type="checkbox" id="wudt-qt-backup"' + (Q.keepBackup ? ' checked' : '') + '> '
			+ '<strong>Keep a backup so I can roll back</strong> (recommended)<span>The replaced files are kept aside on ' + esc(isPull ? 'this site' : (s.label || 'the other site')) + '; this adds almost no time.</span></label>';
		h += '<label class="wudt-mig-override' + (Q.override ? ' is-on' : '') + '"><input type="checkbox" id="wudt-qt-override"' + (Q.override ? ' checked' : '') + '> '
			+ '<strong>Always overwrite</strong> — send files even when the other copy is identical.<span>Normally only files that differ are sent.</span></label>';

		var blocked = Q.active && Q.active.status === 'running' && !Q.active.stale;
		h += '<div class="wudt-mig-footer"><button class="button" data-qt="close">Cancel</button>'
			+ '<button class="button button-primary button-hero" data-qt="start"' + (valid.length && Q.siteId && !Q.busy && !blocked ? '' : ' disabled') + '>'
			+ (Q.busy ? 'Starting…' : (isPull ? '⬇ Pull ' : '⬆ Push ') + valid.length + ' item' + (valid.length === 1 ? '' : 's')) + '</button></div>';
		return h;
	}

	function qProgress() {
		var j = Q.job;
		var isPull = j.direction === 'pull';
		var st = j.stats || {};
		var h = '<div class="wudt-mig-progress-head"><h3>' + (isPull ? '⬇ Pulling from ' : '⬆ Pushing to ') + esc(j.site.label || j.site.url) + '</h3>'
			+ '<span class="wudt-mig-status is-' + esc(j.status) + '">' + esc(j.status) + '</span></div>';
		h += progressBar(Q, j);
		h += liveMeta(Q);
		if (j.status === 'starting') {
			return h;
		}
		h += '<div class="wudt-mig-stats">'
			+ '<div><span>Files checked</span><strong>' + esc(num(st.files_scanned)) + '</strong></div>'
			+ '<div><span>Changed</span><strong>' + esc(num(st.files_changed)) + '</strong></div>'
			+ '<div><span>Transferred</span><strong>' + esc(bytes(st.bytes_done)) + ' <small>of ' + esc(bytes(st.bytes_total)) + '</small></strong></div>'
			+ '</div>';
		if (Q.lost) {
			h += '<div class="wudt-mig-notice is-warning">' + esc(Q.lost) + '</div>';
		}
		if (j.status === 'failed') {
			h += '<div class="wudt-mig-notice is-error"><strong>The transfer stopped.</strong> ' + esc(j.error) + '</div>';
		}
		if (j.status === 'done') {
			h += '<div class="wudt-mig-notice is-success"><strong>Done' + (j.created ? ' in ' + esc(duration(j.updated - j.created)) : '') + '.</strong> '
				+ (st.files_changed ? esc(num(st.files_done)) + ' file' + (st.files_done === 1 ? '' : 's') + ' updated on ' + esc(isPull ? 'this site' : (j.site.label || j.site.url)) + '.' : 'Everything was already identical — nothing needed to change.')
				+ '</div>';
		}
		(j.warnings || []).forEach(function (wmsg) {
			h += '<div class="wudt-mig-notice is-warning">' + esc(wmsg) + '</div>';
		});
		h += '<div class="wudt-mig-footer">';
		if (j.status === 'running') {
			h += '<button class="button" data-qt="cancel">Cancel transfer</button>';
			if (!Q.running) { h += '<button class="button button-primary" data-qt="continue">Continue</button>'; }
		} else if (j.status === 'failed') {
			h += '<button class="button" data-qt="cancel">Cancel and clean up</button><button class="button button-primary" data-qt="continue">Retry</button>';
		} else {
			if (j.status === 'done' && st.files_done > 0 && j.keep_backup !== false) {
				h += '<button class="button" data-qt="rollback">Undo (roll back)</button>';
			}
			h += '<button class="button button-primary" data-qt="close">Close</button>';
		}
		h += '</div>';
		if ((j.log || []).length) {
			h += renderLog(Q, j);
		}
		return h;
	}

	function qStart() {
		var valid = qValidItems();
		var s = qSite();
		if (!valid.length || !s || Q.busy) { return; }
		var isPull = Q.direction === 'pull';
		if (!window.confirm((isPull ? 'Replace ' + valid.length + ' item(s) on THIS site with the copies from ' + s.url : 'Replace ' + valid.length + ' item(s) on ' + s.url + ' with this site’s copies') + '?\n\nOnly these files are changed. ' + (Q.keepBackup ? 'You can roll back afterwards.' : 'NO BACKUP: this cannot be undone.'))) {
			return;
		}
		try { window.localStorage.setItem('wudtQtSite', Q.siteId); } catch (e) { /* storage unavailable */ }
		Q.busy = true;
		Q.notice = null;
		Q.view = 'progress';
		Q.job = {
			id: '', status: 'starting', phase: 'prepare', percent: 0, direction: Q.direction,
			site: { id: s.id, label: s.label, url: s.url }, stats: {}, created: serverNow(), log: [], warnings: [],
			message: 'Contacting ' + (s.label || 'the other site') + ' and preparing the transfer…'
		};
		qRender();
		ajax('wudt_migration_start', {
			site_id: Q.siteId,
			direction: Q.direction,
			components: '[]',
			tables: '[]',
			files: JSON.stringify(valid.map(function (it) { return { c: it.c, p: it.p }; })),
			override: Q.override ? 1 : '',
			keep_backup: Q.keepBackup ? 1 : 0,
			db_mode: 'replace'
		}, 120000).done(function (r) {
			if (!Q) { return; }
			Q.busy = false;
			if (!r || !r.success) { Q.view = 'setup'; Q.job = null; Q.notice = { type: 'error', text: errMsg(r) }; qRender(); return; }
			Q.job = null;
			mergeJob(Q, r.data.job);
			Q.token = r.data.token;
			saveToken(Q.job.id, Q.token);
			qRun();
		}).fail(function (xhr) {
			if (!Q) { return; }
			Q.busy = false;
			Q.view = 'setup';
			Q.job = null;
			Q.notice = { type: 'error', text: errMsg(null, xhr) };
			qRender();
		});
	}

	function qRun() {
		if (!Q || Q.running) { return; }
		Q.running = true;
		Q.failures = 0;
		Q.lost = '';
		window.addEventListener('beforeunload', warnLeave);
		startPolling(Q, qRender);
		qRender();
		qLoop();
	}

	function qStop() {
		if (Q) {
			Q.running = false;
			stopPolling(Q);
		}
		if (!S.running) { window.removeEventListener('beforeunload', warnLeave); }
	}

	function qLoop() {
		if (!Q || !Q.running || !Q.job) { return; }
		var q = Q;
		ajax('wudt_migration_step', { job_id: q.job.id, token: q.token }, 240000).done(function (r) {
			if (Q !== q) { return; }
			if (!r || !r.success) { qStop(); q.lost = errMsg(r); qRender(); return; }
			q.failures = 0;
			q.lost = '';
			mergeJob(q, r.data.job);
			q.job.busy = r.data.job.busy;
			if (q.job.status === 'running') {
				qRender();
				setTimeout(qLoop, q.job.busy ? 3000 : 250);
				return;
			}
			qStop();
			qRender();
			if (q.job.status === 'done' && q.job.direction === 'pull' && q.onDone) { q.onDone(q.job); }
		}).fail(function (xhr) {
			if (Q !== q) { return; }
			q.failures++;
			if (q.failures <= 6) {
				q.lost = errMsg(null, xhr) + ' Retrying (' + q.failures + '/6)…';
				qRender();
				setTimeout(qLoop, 3000 * q.failures);
				return;
			}
			qStop();
			q.lost = errMsg(null, xhr) + ' Click Continue to retry.';
			qRender();
		});
	}

	function bindTransferEvents() {
		$(document).off('.wudtqt');
		$(document).on('click.wudtqt', '#wudt-qt [data-qt]', function (e) {
			e.preventDefault();
			var $b = $(this);
			switch ($b.data('qt')) {
				case 'close':
					closeTransfer(false);
					break;
				case 'dir':
					Q.direction = $b.data('dir') === 'pull' ? 'pull' : 'push';
					qRender();
					break;
				case 'remove':
					Q.items.splice(Number($b.data('idx')), 1);
					qRender();
					break;
				case 'start':
					qStart();
					break;
				case 'continue':
					qRun();
					break;
				case 'cancel':
					if (!window.confirm('Cancel this transfer? Files not yet moved into place stay as they were.')) { return; }
					qStop();
					ajax('wudt_migration_cancel', { job_id: Q.job.id, token: Q.token }, 120000).done(function (r) {
						if (!Q) { return; }
						if (r && r.success) { Q.job = r.data.job; } else { Q.lost = errMsg(r); }
						qRender();
					}).fail(function (xhr) { if (Q) { Q.lost = errMsg(null, xhr); qRender(); } });
					break;
				case 'rollback':
					var j = Q.job;
					var target = j.direction === 'pull' ? 'this site' : (j.site.url || 'the other site');
					if (!window.confirm('Undo this transfer on ' + target + '? The files are restored to how they were before.')) { return; }
					$b.prop('disabled', true).text('Rolling back…');
					ajax('wudt_migration_rollback', { site_id: j.direction === 'pull' ? 'local' : j.site.id }, 300000).done(function (r) {
						if (!Q) { return; }
						Q.lost = '';
						Q.job.warnings = [];
						Q.job.message = r && r.success ? r.data.message : errMsg(r);
						Q.job.status = r && r.success ? 'rolled back' : Q.job.status;
						qRender();
						if (r && r.success && j.direction === 'pull' && Q.onDone) { Q.onDone(j); }
					}).fail(function (xhr) { if (Q) { Q.lost = errMsg(null, xhr); qRender(); } });
					break;
			}
		});
		$(document).on('change.wudtqt', '#wudt-qt-site', function () {
			Q.siteId = String($(this).val());
			qRender();
		});
		$(document).on('change.wudtqt', '#wudt-qt-backup', function () {
			Q.keepBackup = $(this).is(':checked');
			qRender();
		});
		$(document).on('change.wudtqt', '#wudt-qt-override', function () {
			Q.override = $(this).is(':checked');
			qRender();
		});
		$(document).on('mousedown.wudtqt', '#wudt-qt', function (e) {
			if (e.target === this && !(Q && Q.running)) { closeTransfer(false); }
		});
		$(document).on('keydown.wudtqt', function (e) {
			if (e.key === 'Escape' && Q && !Q.running) { closeTransfer(false); }
		});
	}

	window.WUDTMigration = {
		mount: function (el, data) {
			S.root = el;
			if (!S.data && data && data.connection_string) { S.data = data; }
			bindEvents();
			render();
			startPeerPolling();
			if (!S.running) { refresh(); }
		},
		/**
		 * Push or pull files of this site (absolute paths) to or from a connected site.
		 *
		 * @param {string[]} paths
		 * @param {{direction?: string, onDone?: Function}} opts
		 */
		openTransfer: openTransfer
	};
})(jQuery);
