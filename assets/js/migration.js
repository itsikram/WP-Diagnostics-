/**
 * WP Diagnostics — Site Migration UI.
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
		languages: 'Languages'
	};
	var PHASES = [
		{ key: 'database', label: 'Database' },
		{ key: 'scan', label: 'Compare files' },
		{ key: 'transfer', label: 'Transfer' },
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
		var h = '';

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
			+ '<p class="wudt-mig-muted">Copy this key into WP Diagnostics on the other site (Site Migration → Add a site). It contains this site’s address and secret key.</p>'
			+ '<div class="wudt-mig-keyrow"><input type="text" readonly class="wudt-mig-input wudt-mig-mono" id="wudt-mig-conn" value="' + esc(d.connection_string) + '">'
			+ '<button class="button button-primary" data-mig="copy-conn">Copy</button></div>'
			+ '<button type="button" class="button-link" data-mig="toggle-key">' + (S.showKey ? 'Hide details' : 'Show URL and API key separately') + '</button>';
		if (S.showKey) {
			h += '<table class="wudt-mig-kv"><tr><th>Site URL</th><td class="wudt-mig-mono">' + esc(d.local_site_url) + '</td></tr>'
				+ '<tr><th>API key</th><td class="wudt-mig-mono">' + esc(d.local_api_key) + ' <button class="button button-small" data-mig="copy-key">Copy</button></td></tr></table>'
				+ '<p><button class="button" data-mig="regen">Generate a new key</button> <span class="wudt-mig-muted">Sites using the old key will need the new one.</span></p>';
		}
		h += '</div>';

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
					+ '<div class="wudt-mig-site-info"><span class="wudt-mig-dot is-' + dot + '" title="' + esc(title) + '"></span>'
					+ '<div><strong>' + esc(s.label) + '</strong><a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.url) + '</a>'
					+ (chk && !chk.ok ? '<div class="wudt-mig-error-inline">' + esc(chk.error) + '</div>' : '')
					+ '</div></div>'
					+ '<div class="wudt-mig-actions">'
					+ '<button class="button button-primary" data-mig="pull" data-site="' + esc(s.id) + '">⬇ Pull to this site</button>'
					+ '<button class="button button-primary" data-mig="push" data-site="' + esc(s.id) + '">⬆ Push to this site</button>'
					+ '<button class="button" data-mig="test" data-site="' + esc(s.id) + '">Test</button>'
					+ '<button class="button-link wudt-mig-danger" data-mig="delete-site" data-site="' + esc(s.id) + '">Remove</button>'
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
		h += '<p class="wudt-mig-muted wudt-mig-help">Both sites need WP Diagnostics active. A live server cannot reach your localhost, so always start migrations from the <strong>local</strong> site: use Pull to bring live here, Push to publish local to live.</p>';
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
					+ '<td class="wudt-mig-muted">' + esc(j.stats.tables_done) + ' tables, ' + esc(num(j.stats.files_done)) + ' files' + (j.error ? ' — ' + esc(j.error) : '') + '</td>'
					+ '<td class="wudt-mig-actions">'
					+ ((j.status === 'running' || j.status === 'failed') ? '<button class="button button-small" data-mig="resume" data-job="' + esc(j.id) + '">Open</button>' : '')
					+ (j.status === 'done' && j.direction === 'push' && site(j.site.id) ? '<button class="button button-small" data-mig="rollback-remote" data-site="' + esc(j.site.id) + '">Roll back remote</button>' : '')
					+ '<button class="button-link wudt-mig-danger" data-mig="delete-job" data-job="' + esc(j.id) + '">Delete</button></td></tr>';
			});
			h += '</tbody></table></div>';
		}
		return h;
	}

	function renderWizard() {
		var w = S.wizard;
		var s = site(w.siteId) || { label: '', url: '' };
		var isPull = w.direction === 'pull';
		var h = '<div class="wudt-mig-card">';
		h += '<div class="wudt-mig-flow">'
			+ '<div class="wudt-mig-flow-box"><span>From</span><strong>' + esc(isPull ? s.label : 'This site') + '</strong><small>' + esc(isPull ? s.url : S.data.local_site_url) + '</small></div>'
			+ '<div class="wudt-mig-flow-arrow">→</div>'
			+ '<div class="wudt-mig-flow-box is-dest"><span>To (will be overwritten)</span><strong>' + esc(isPull ? 'This site' : s.label) + '</strong><small>' + esc(isPull ? S.data.local_site_url : s.url) + '</small></div>'
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

		h += '<h3>What to migrate</h3><div class="wudt-mig-grid">';
		h += '<label class="wudt-mig-check"><input type="checkbox" data-mig-field="db"' + (w.db ? ' checked' : '') + '> <strong>Database</strong><small>' + esc((pf.tables || []).length) + ' tables · ' + esc(bytes(w.dbSize)) + '</small></label>';
		(S.data.components || []).forEach(function (c) {
			h += '<label class="wudt-mig-check"><input type="checkbox" data-mig-comp="' + esc(c) + '"' + (w.components.indexOf(c) !== -1 ? ' checked' : '') + '> <strong>' + esc(COMPONENT_LABELS[c] || c) + '</strong><small>only changed files are sent</small></label>';
		});
		h += '</div>';

		if (w.db) {
			h += '<div class="wudt-mig-sub">'
				+ '<label><input type="radio" name="wudt-mig-tmode" value="all"' + (w.tableMode === 'all' ? ' checked' : '') + '> All tables</label> '
				+ '<label><input type="radio" name="wudt-mig-tmode" value="custom"' + (w.tableMode === 'custom' ? ' checked' : '') + '> Choose tables</label>'
				+ '<label class="wudt-mig-inline"><input type="checkbox" data-mig-field="skip"' + (w.skipUnchanged ? ' checked' : '') + '> Skip tables that are already identical</label>';
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
			h += '</div>';
		}

		if (w.components.length) {
			h += '<div class="wudt-mig-sub"><label class="wudt-mig-block">Exclude files or folders (optional, one per line, relative to the component folder, wildcards allowed)'
				+ '<textarea class="wudt-mig-input wudt-mig-mono" rows="3" id="wudt-mig-excludes" placeholder="2019/*&#10;my-plugin/cache">' + esc(w.excludes) + '</textarea></label></div>';
		}

		var dbOk = w.db && (w.tableMode === 'all' ? (pf.tables || []).length > 0 : w.tables.length > 0);
		var canStart = !(pf.errors && pf.errors.length) && (dbOk || w.components.length > 0);
		h += '<div class="wudt-mig-footer"><button class="button" data-mig="back">Cancel</button>'
			+ '<button class="button button-primary button-hero" data-mig="start"' + (canStart && !S.busy ? '' : ' disabled') + '>'
			+ (isPull ? '⬇ Start pull' : '⬆ Start push') + '</button></div>';
		h += '</div>';
		return h;
	}

	function renderProgress() {
		var j = S.job;
		if (!j) { return '<div class="wudt-mig-card"><p>Loading…</p></div>'; }
		var isPull = j.direction === 'pull';
		var phaseIdx = 0;
		for (var i = 0; i < PHASES.length; i++) {
			if (PHASES[i].key === j.phase || (j.phase === 'finalize_db' && PHASES[i].key === 'finalize_files') || (j.phase === 'cleanup' && PHASES[i].key === 'finalize_files')) { phaseIdx = i; }
		}
		if (j.status === 'done') { phaseIdx = PHASES.length - 1; }

		var h = '<div class="wudt-mig-card">';
		h += '<div class="wudt-mig-progress-head"><h3>' + (isPull ? '⬇ Pulling from ' : '⬆ Pushing to ') + esc(j.site.label || j.site.url) + '</h3>'
			+ '<span class="wudt-mig-status is-' + esc(j.status) + '">' + esc(j.status) + '</span></div>';
		h += '<ol class="wudt-mig-phases">';
		PHASES.forEach(function (p, idx) {
			var cls = idx < phaseIdx ? 'is-done' : (idx === phaseIdx ? 'is-current' : '');
			if (j.status === 'done') { cls = 'is-done'; }
			h += '<li class="' + cls + '">' + esc(p.label) + '</li>';
		});
		h += '</ol>';
		h += '<div class="wudt-mig-bar' + (j.status === 'failed' ? ' is-failed' : '') + '"><div style="width:' + esc(j.percent) + '%"></div></div>';
		h += '<div class="wudt-mig-bar-meta"><span>' + esc(j.message) + '</span><strong>' + esc(j.percent) + '%</strong></div>';

		var st = j.stats || {};
		h += '<div class="wudt-mig-stats">'
			+ '<div><span>Tables copied</span><strong>' + esc(st.tables_done) + (st.tables_skipped ? ' <small>(' + esc(st.tables_skipped) + ' unchanged)</small>' : '') + '</strong></div>'
			+ '<div><span>Rows</span><strong>' + esc(num(st.rows)) + '</strong></div>'
			+ '<div><span>Files changed</span><strong>' + esc(num(st.files_changed)) + ' <small>of ' + esc(num(st.files_scanned)) + '</small></strong></div>'
			+ '<div><span>Transferred</span><strong>' + esc(bytes(st.bytes_done)) + ' <small>of ' + esc(bytes(st.bytes_total)) + '</small></strong></div>'
			+ '</div>';

		if (j.status === 'failed') {
			h += '<div class="wudt-mig-notice is-error"><strong>The migration stopped.</strong> ' + esc(j.error) + '<br>The destination site has not been switched over. You can retry from where it stopped, or cancel to clean up.</div>';
		}
		if (S.connectionLost) {
			h += '<div class="wudt-mig-notice is-warning">Lost contact with this site’s server: ' + esc(S.connectionLost) + '</div>';
		}
		if (j.status === 'done') {
			h += '<div class="wudt-mig-notice is-success"><strong>Migration complete.</strong> ';
			if (isPull) {
				h += 'This site now contains the remote site’s content. If the users table was included, <a href="' + esc(S.data.local_site_url) + '/wp-login.php">log in again</a> with the remote site’s credentials.';
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
			if (j.status === 'done' && !isPull && site(j.site.id)) {
				h += '<button class="button" data-mig="rollback-remote" data-site="' + esc(j.site.id) + '">Roll back remote site</button>';
			}
			if (j.status === 'done' && isPull) {
				h += '<button class="button" data-mig="rollback-local">Roll back this site</button>';
			}
			h += '<button class="button button-primary" data-mig="home">Back to Site Migration</button>';
		}
		h += '</div>';

		var log = j.log || [];
		if (log.length) {
			h += '<details class="wudt-mig-log"' + (j.status === 'failed' ? ' open' : '') + '><summary>Activity log</summary><ul>';
			log.slice().reverse().forEach(function (l) {
				h += '<li><time>' + esc(new Date(l.t * 1000).toLocaleTimeString()) + '</time> ' + esc(l.m) + '</li>';
			});
			h += '</ul></details>';
		}
		h += '</div>';
		return h;
	}

	/* ----------------------------------------------------------------- */

	function refresh() {
		return ajax('wudt_migration_get_state').done(function (r) {
			if (r && r.success) { S.data = r.data; render(); }
		});
	}

	function openWizard(siteId, direction) {
		S.view = 'wizard';
		S.notice = null;
		S.wizard = {
			siteId: siteId, direction: direction, loading: true, error: '', preflight: null,
			db: true, components: ['plugins', 'themes', 'uploads'], tableMode: 'all', tables: [],
			skipUnchanged: true, excludes: '', dbSize: 0
		};
		render();
		ajax('wudt_migration_preflight', { site_id: siteId, direction: direction }, 120000).done(function (r) {
			S.wizard.loading = false;
			if (!r || !r.success) { S.wizard.error = errMsg(r); render(); return; }
			S.wizard.preflight = r.data;
			S.wizard.tables = (r.data.tables || []).map(function (t) { return t.name; });
			S.wizard.dbSize = (r.data.tables || []).reduce(function (a, t) { return a + (Number(t.size) || 0); }, 0);
			render();
		}).fail(function (xhr) {
			S.wizard.loading = false;
			S.wizard.error = errMsg(null, xhr);
			render();
		});
	}

	function startJob() {
		var w = S.wizard;
		w.excludes = $('#wudt-mig-excludes').val() || w.excludes;
		var tables = w.db ? (w.tableMode === 'all' ? (w.preflight.tables || []).map(function (t) { return t.name; }) : w.tables) : [];
		if (!tables.length && !w.components.length) { return; }
		var s = site(w.siteId);
		var parts = [];
		if (tables.length) { parts.push(tables.length + ' database tables'); }
		w.components.forEach(function (c) { parts.push(COMPONENT_LABELS[c] || c); });
		var target = w.direction === 'pull' ? 'THIS site (' + S.data.local_site_url + ')' : s.url;
		if (!window.confirm('Overwrite ' + target + ' with:\n\n• ' + parts.join('\n• ') + '\n\nThe previous version is kept so you can roll back. Continue?')) {
			return;
		}
		S.busy = true;
		render();
		ajax('wudt_migration_start', {
			site_id: w.siteId,
			direction: w.direction,
			components: JSON.stringify(w.components),
			tables: JSON.stringify(tables),
			skip_unchanged: w.skipUnchanged ? 1 : '',
			excludes: w.excludes
		}, 120000).done(function (r) {
			S.busy = false;
			if (!r || !r.success) { setNotice('error', errMsg(r)); render(); return; }
			S.job = r.data.job;
			S.token = r.data.token;
			saveToken(S.job.id, S.token);
			S.view = 'progress';
			S.notice = null;
			run();
		}).fail(function (xhr) {
			S.busy = false;
			setNotice('error', errMsg(null, xhr));
			render();
		});
	}

	function run() {
		if (S.running) { return; }
		S.running = true;
		S.netFailures = 0;
		S.connectionLost = '';
		window.addEventListener('beforeunload', warnLeave);
		render();
		loop();
	}

	function stop() {
		S.running = false;
		window.removeEventListener('beforeunload', warnLeave);
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
			S.job = r.data.job;
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
		S.job = job;
		S.token = tokens()[jobId] || '';
		S.view = 'progress';
		render();
	}

	/* ----------------------------------------------------------------- */

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
						setNotice(r.data.warning ? 'warning' : 'success', r.data.warning ? 'Site saved, but the connection test failed: ' + r.data.warning : 'Site connected.');
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
				case 'resume':
					resumeJob(jobId);
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

		$(document).on('change.wudtmig', '#wudt-migration-root [data-mig-field], #wudt-migration-root [data-mig-comp], #wudt-migration-root [data-mig-table], #wudt-migration-root input[name="wudt-mig-tmode"]', function () {
			var w = S.wizard;
			if (!w) { return; }
			w.excludes = $('#wudt-mig-excludes').val() || w.excludes;
			var $i = $(this);
			if ($i.is('[data-mig-field="db"]')) { w.db = $i.is(':checked'); }
			if ($i.is('[data-mig-field="skip"]')) { w.skipUnchanged = $i.is(':checked'); }
			if ($i.is('[name="wudt-mig-tmode"]')) { w.tableMode = $i.val(); }
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

		$(document).on('input.wudtmig', '#wudt-mig-excludes', function () {
			if (S.wizard) { S.wizard.excludes = $(this).val(); }
		});
	}

	window.WUDTMigration = {
		mount: function (el, data) {
			S.root = el;
			if (!S.data && data && data.connection_string) { S.data = data; }
			bindEvents();
			render();
			if (!S.running) { refresh(); }
		}
	};
})(jQuery);
