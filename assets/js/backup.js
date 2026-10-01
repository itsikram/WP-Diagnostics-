/**
 * WP Diagnostics — Backup & Restore UI.
 */
(function ($) {
	'use strict';

	function cfg() { return window.wudtProAdmin || {}; }
	var TOKENS = 'wudtBackupTokens';
	var LABELS = { database: 'Database', plugins: 'Plugins', themes: 'Themes', uploads: 'Media uploads', 'mu-plugins': 'Must-use plugins', languages: 'Languages' };
	var S = { root: null, data: null, job: null, token: '', running: false, notice: null, restore: null, upload: null, focus: 'backup', create: ['database', 'plugins', 'themes', 'uploads'], note: '' };

	function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function bytes(n) { n = Number(n) || 0; var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return (i ? n.toFixed(1) : n) + ' ' + u[i]; }
	function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : ''; }
	function ajax(action, data, timeout) {
		return $.ajax({ url: cfg().ajaxUrl, type: 'POST', dataType: 'json', timeout: timeout || 240000, data: $.extend({ action: action, nonce: cfg().nonce }, data || {}) });
	}
	function err(r, xhr) {
		if (r && r.data && r.data.message) { return r.data.message; }
		if (xhr && xhr.status === 0) { return 'Network error — the request did not reach the server.'; }
		return 'Server error' + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '') + '.';
	}
	function note(type, text) { S.notice = text ? { type: type, text: text } : null; render(); }
	function saveToken(id, t) { try { var o = JSON.parse(localStorage.getItem(TOKENS) || '{}'); o[id] = t; localStorage.setItem(TOKENS, JSON.stringify(o)); } catch (e) { /* ignore */ } }
	function getToken(id) { try { return (JSON.parse(localStorage.getItem(TOKENS) || '{}'))[id] || ''; } catch (e) { return ''; } }

	/* --------------------------------------------------------------- */

	function render() {
		if (!S.root || !document.body.contains(S.root)) { return; }
		var d = S.data;
		var h = '<div class="wudt-mig wudt-bk">';
		h += '<div class="wudt-mig-head"><h2>Backup &amp; Restore</h2><p>Complete, verified backups of your database and files. Backups are private (downloadable only from wp-admin), work on large sites, and every restore can be rolled back.</p></div>';
		if (S.notice) { h += '<div class="wudt-mig-notice is-' + esc(S.notice.type) + '">' + esc(S.notice.text) + '</div>'; }
		if (!d) { S.root.innerHTML = h + '<div class="wudt-mig-card">Loading…</div></div>'; return; }
		if (!d.limits.zip) { h += '<div class="wudt-mig-notice is-error">The PHP Zip extension is missing on this server. Ask your host to enable it.</div>'; }

		if (S.job) { h += renderJob(); }

		if (d.rollback && d.rollback.available && (!S.job || S.job.status !== 'running')) {
			h += '<div class="wudt-mig-card wudt-mig-rollback"><div><strong>Undo the last restore or migration</strong><p>' + esc(when(d.rollback.created)) + ' · ' + esc(d.rollback.tables) + ' tables, ' + esc(d.rollback.files) + ' files can be put back.</p></div>'
				+ '<div class="wudt-mig-actions"><button class="button" data-bk="rollback">Roll back</button><button class="button-link wudt-mig-danger" data-bk="discard">Delete rollback data</button></div></div>';
		}

		// Create.
		var busy = S.job && S.job.status === 'running';
		h += '<div class="wudt-mig-card"><h3>Create a backup</h3><div class="wudt-mig-grid">';
		d.components.forEach(function (c) {
			h += '<label class="wudt-mig-check"><input type="checkbox" data-bk-comp="' + esc(c) + '"' + (S.create.indexOf(c) !== -1 ? ' checked' : '') + '> <strong>' + esc(LABELS[c] || c) + '</strong></label>';
		});
		h += '</div><div class="wudt-mig-keyrow" style="margin-top:12px"><input class="wudt-mig-input" id="wudt-bk-note" placeholder="Note (optional), e.g. Before theme update" value="' + esc(S.note) + '">'
			+ '<button class="button button-primary" data-bk="create"' + (busy || !S.create.length ? ' disabled' : '') + '>Create backup now</button></div>'
			+ '<p class="wudt-mig-muted">Free disk space: ' + esc(bytes(d.limits.free_space)) + '. WordPress core files are not included (reinstall them any time from Dashboard → Updates).</p></div>';

		// List.
		h += '<div class="wudt-mig-card' + (S.focus === 'restore' ? ' wudt-bk-focus' : '') + '"><h3>Backups</h3>';
		if (!d.backups.length) {
			h += '<p class="wudt-mig-muted">No backups yet.</p>';
		} else {
			h += '<table class="widefat striped wudt-mig-table"><thead><tr><th>Backup</th><th>Contains</th><th>Size</th><th></th></tr></thead><tbody>';
			d.backups.forEach(function (b) {
				var dl = cfg().ajaxUrl + '?action=wudt_backup_download&nonce=' + encodeURIComponent(cfg().nonce) + '&name=' + encodeURIComponent(b.name);
				h += '<tr><td><strong>' + esc(when(b.created)) + '</strong><br><small class="wudt-mig-muted">' + esc(b.name) + (b.note ? ' · ' + esc(b.note) : '') + (b.trigger !== 'manual' ? ' · ' + esc(b.trigger) : '') + '</small></td>'
					+ '<td>' + esc((b.components || []).map(function (c) { return LABELS[c] || c; }).join(', ') || '—') + '</td>'
					+ '<td>' + esc(bytes(b.size)) + '</td>'
					+ '<td class="wudt-mig-actions"><a class="button button-small" href="' + esc(dl) + '">Download</a>'
					+ '<button class="button button-small button-primary" data-bk="restore" data-name="' + esc(b.name) + '"' + (busy ? ' disabled' : '') + '>Restore</button>'
					+ '<button class="button-link wudt-mig-danger" data-bk="delete" data-name="' + esc(b.name) + '">Delete</button></td></tr>';
			});
			h += '</tbody></table>';
		}
		h += '<div class="wudt-bk-upload"><strong>Restore from a file</strong> <span class="wudt-mig-muted">— upload a backup .zip made by WP Diagnostics (any size; it is sent in parts).</span><br>'
			+ '<input type="file" accept=".zip" id="wudt-bk-file"' + (S.upload ? ' disabled' : '') + '>';
		if (S.upload) { h += ' <span class="wudt-bk-upbar"><span style="width:' + S.upload.pct + '%"></span></span> ' + esc(S.upload.pct) + '%'; }
		h += '</div></div>';

		if (S.restore) { h += renderRestoreDialog(); }

		// Schedule.
		var sc = d.schedule;
		h += '<div class="wudt-mig-card"><h3>Automatic backups</h3>'
			+ '<label><input type="checkbox" id="wudt-bk-s-on"' + (sc.enabled ? ' checked' : '') + '> Enable automatic backups</label> '
			+ '<select id="wudt-bk-s-freq"><option value="twicedaily"' + (sc.frequency === 'twicedaily' ? ' selected' : '') + '>Twice a day</option><option value="daily"' + (sc.frequency === 'daily' ? ' selected' : '') + '>Daily</option><option value="weekly"' + (sc.frequency === 'weekly' ? ' selected' : '') + '>Weekly</option></select> '
			+ 'keep the last <input type="number" min="1" max="30" class="small-text" id="wudt-bk-s-keep" value="' + esc(sc.keep) + '"> backups<div class="wudt-mig-grid" style="margin-top:8px">';
		d.components.forEach(function (c) {
			h += '<label class="wudt-mig-check"><input type="checkbox" data-bk-scomp="' + esc(c) + '"' + (sc.components.indexOf(c) !== -1 ? ' checked' : '') + '> ' + esc(LABELS[c] || c) + '</label>';
		});
		h += '</div><p><button class="button" data-bk="schedule">Save schedule</button> <span class="wudt-mig-muted">' + (d.next_run ? 'Next backup: ' + esc(when(d.next_run)) : 'Not scheduled.') + ' Automatic backups run through WP-Cron, which needs site visits (or a real cron job) to trigger.</span></p></div>';

		h += '</div>';
		S.root.innerHTML = h;
	}

	function renderJob() {
		var j = S.job;
		var isRestore = j.type === 'restore';
		var h = '<div class="wudt-mig-card"><div class="wudt-mig-progress-head"><h3>' + (isRestore ? 'Restoring ' : 'Backing up ') + esc(j.name) + '</h3><span class="wudt-mig-status is-' + esc(j.status) + '">' + esc(j.status) + '</span></div>'
			+ '<div class="wudt-mig-bar' + (j.status === 'failed' ? ' is-failed' : '') + '"><div style="width:' + esc(j.percent) + '%"></div></div>'
			+ '<div class="wudt-mig-bar-meta"><span>' + esc(j.message) + '</span><strong>' + esc(j.percent) + '%</strong></div>';
		if (S.lost) { h += '<div class="wudt-mig-notice is-warning">' + esc(S.lost) + '</div>'; }
		if (j.status === 'failed') { h += '<div class="wudt-mig-notice is-error"><strong>Stopped:</strong> ' + esc(j.error) + (isRestore ? '<br>Nothing on your site was replaced yet.' : '') + '</div>'; }
		if (j.status === 'done') {
			h += '<div class="wudt-mig-notice is-success">' + (isRestore
				? '<strong>Restore complete.</strong> If the users table was restored you may need to <a href="' + esc((window.location.href.split('/wp-admin')[0])) + '/wp-login.php">log in again</a>. Use “Roll back” above to undo.'
				: '<strong>Backup complete</strong> (' + esc(bytes(j.size)) + ').') + '</div>';
		}
		h += '<div class="wudt-mig-footer">';
		if (j.status === 'running') {
			h += '<button class="button" data-bk="cancel">Cancel</button>' + (S.running ? '' : '<button class="button button-primary" data-bk="continue">Continue</button>');
		} else if (j.status === 'failed') {
			h += '<button class="button" data-bk="cancel">Cancel</button><button class="button button-primary" data-bk="continue">Retry</button>';
		} else {
			h += '<button class="button" data-bk="close-job">Close</button>';
		}
		h += '</div>';
		var log = j.log || [];
		if (log.length) {
			h += '<details class="wudt-mig-log"' + (j.status === 'failed' ? ' open' : '') + '><summary>Activity log</summary><ul>';
			log.slice().reverse().forEach(function (l) { h += '<li><time>' + esc(new Date(l.t * 1000).toLocaleTimeString()) + '</time> ' + esc(l.m) + '</li>'; });
			h += '</ul></details>';
		}
		return h + '</div>';
	}

	function renderRestoreDialog() {
		var r = S.restore;
		var h = '<div class="wudt-mig-card wudt-bk-focus"><h3>Restore ' + esc(r.name) + '</h3>';
		if (!r.info) { return h + '<p>Reading backup…</p></div>'; }
		var i = r.info;
		h += '<p class="wudt-mig-muted">Made ' + esc(i.created) + (i.site.home ? ' on ' + esc(i.site.home) : '') + (i.format < 2 ? ' (older backup format — fully supported)' : '') + '.</p>';
		if (!i.same_site && i.site.home) { h += '<div class="wudt-mig-notice is-warning">This backup comes from another address; links will be updated to this site automatically. This site’s own URL and WP Diagnostics settings are kept.</div>'; }
		h += '<div class="wudt-mig-grid">';
		i.components.forEach(function (c) {
			h += '<label class="wudt-mig-check"><input type="checkbox" data-bk-rcomp="' + esc(c) + '"' + (r.components.indexOf(c) !== -1 ? ' checked' : '') + '> <strong>' + esc(LABELS[c] || c) + '</strong></label>';
		});
		h += '</div><p class="wudt-mig-muted">The current database and files are kept aside until the restore finishes, so you can roll back. Files added after the backup are left in place.</p>'
			+ '<div class="wudt-mig-footer"><button class="button" data-bk="restore-cancel">Cancel</button><button class="button button-primary button-hero" data-bk="restore-go"' + (r.components.length ? '' : ' disabled') + '>Restore now</button></div></div>';
		return h;
	}

	/* --------------------------------------------------------------- */

	function refresh() { return ajax('wudt_backup_state').done(function (r) { if (r && r.success) { S.data = r.data; if (!S.job && r.data.active_job) { S.job = r.data.active_job; S.token = getToken(S.job.id); } render(); } }); }

	function startJob(r) {
		S.job = r.data.job; S.token = r.data.token; saveToken(S.job.id, S.token); S.restore = null; S.notice = null; run();
	}

	function run() {
		if (S.running) { return; }
		S.running = true; S.fails = 0; S.lost = '';
		window.addEventListener('beforeunload', warn);
		render(); loop();
	}
	function stop() { S.running = false; window.removeEventListener('beforeunload', warn); }
	function warn(e) { e.preventDefault(); e.returnValue = 'A backup job is running.'; return e.returnValue; }

	function loop() {
		if (!S.running || !S.job) { return; }
		ajax('wudt_backup_step', { job_id: S.job.id, token: S.token }, 300000).done(function (r) {
			if (!r || !r.success) { stop(); S.lost = err(r); render(); return; }
			S.fails = 0; S.lost = ''; S.job = r.data.job; render();
			if (S.job.status === 'running') { setTimeout(loop, r.data.job.busy ? 3000 : 200); } else { stop(); refresh(); }
		}).fail(function (xhr) {
			S.fails++;
			if (S.fails <= 6) { S.lost = err(null, xhr) + ' Retrying (' + S.fails + '/6)…'; render(); setTimeout(loop, 3000 * S.fails); return; }
			stop(); S.lost = err(null, xhr) + ' Click Continue to retry.'; render();
		});
	}

	function upload(file) {
		if (!/\.zip$/i.test(file.name)) { note('error', 'Choose a .zip backup file.'); return; }
		var id = 'u' + Date.now() + Math.random().toString(36).slice(2, 10);
		var size = 2 * 1024 * 1024;
		S.upload = { pct: 0 }; render();
		function send(offset) {
			var fd = new FormData();
			fd.append('action', 'wudt_backup_upload_chunk'); fd.append('nonce', cfg().nonce); fd.append('upload_id', id);
			fd.append('name', file.name); fd.append('offset', offset); fd.append('total', file.size);
			fd.append('chunk', file.slice(offset, offset + size), 'chunk');
			$.ajax({ url: cfg().ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', timeout: 300000 }).done(function (r) {
				if (!r || !r.success) { S.upload = null; note('error', err(r)); return; }
				if (r.data.done) { S.upload = null; S.data = r.data.state; note('success', 'Uploaded ' + r.data.name + '. You can restore it from the list.'); return; }
				S.upload.pct = Math.min(99, Math.round(100 * r.data.received / file.size)); render();
				send(r.data.received);
			}).fail(function (xhr) { S.upload = null; note('error', 'Upload failed: ' + err(null, xhr)); });
		}
		send(0);
	}

	function bind() {
		$(document).off('.wudtbk');
		$(document).on('click.wudtbk', '#wudt-backup-root [data-bk]', function (e) {
			e.preventDefault();
			var $b = $(this), act = $b.data('bk'), name = $b.data('name');
			switch (act) {
				case 'create':
					S.note = $('#wudt-bk-note').val() || '';
					$b.prop('disabled', true);
					ajax('wudt_backup_start', { components: JSON.stringify(S.create), note: S.note }).done(function (r) {
						if (r && r.success) { startJob(r); } else { note('error', err(r)); }
					}).fail(function (xhr) { note('error', err(null, xhr)); });
					break;
				case 'continue': run(); break;
				case 'cancel':
					if (!window.confirm('Cancel this job?')) { return; }
					stop();
					ajax('wudt_backup_cancel', { job_id: S.job.id, token: S.token }).done(function (r) {
						if (r && r.success) { S.job = r.data.job; } else { note('error', err(r)); }
						refresh();
					});
					break;
				case 'close-job': S.job = null; render(); break;
				case 'delete':
					if (!window.confirm('Delete this backup permanently?')) { return; }
					ajax('wudt_backup_delete', { name: name }).done(function (r) { if (r && r.success) { S.data = r.data; render(); } else { note('error', err(r)); } });
					break;
				case 'restore':
					S.restore = { name: name, info: null, components: [] }; render();
					ajax('wudt_backup_inspect', { name: name }).done(function (r) {
						if (!r || !r.success) { S.restore = null; note('error', err(r)); return; }
						S.restore.info = r.data; S.restore.components = r.data.components.slice(); render();
					});
					break;
				case 'restore-cancel': S.restore = null; render(); break;
				case 'restore-go':
					var r0 = S.restore;
					if (!window.confirm('Restore ' + r0.components.map(function (c) { return LABELS[c] || c; }).join(', ') + ' from this backup?\n\nYour current data is replaced (and kept for rollback).')) { return; }
					$b.prop('disabled', true).text('Starting…');
					ajax('wudt_backup_restore_start', { name: r0.name, components: JSON.stringify(r0.components) }).done(function (r) {
						if (r && r.success) { startJob(r); } else { note('error', err(r)); }
					}).fail(function (xhr) { note('error', err(null, xhr)); });
					break;
				case 'rollback':
					if (!window.confirm('Put the database and files back the way they were before the last restore/migration?')) { return; }
					$b.prop('disabled', true).text('Rolling back…');
					ajax('wudt_backup_rollback', {}, 300000).done(function (r) { note(r && r.success ? 'success' : 'error', r && r.success ? r.data.message : err(r)); refresh(); });
					break;
				case 'discard':
					if (!window.confirm('Delete the rollback copy? You will not be able to undo the last restore/migration.')) { return; }
					ajax('wudt_backup_discard_rollback').done(function (r) { if (r && r.success) { S.data = r.data; render(); } });
					break;
				case 'schedule':
					var comps = [];
					$('#wudt-backup-root [data-bk-scomp]:checked').each(function () { comps.push($(this).data('bk-scomp')); });
					ajax('wudt_backup_schedule', { enabled: $('#wudt-bk-s-on').is(':checked') ? 1 : '', frequency: $('#wudt-bk-s-freq').val(), keep: $('#wudt-bk-s-keep').val(), components: JSON.stringify(comps) }).done(function (r) {
						if (r && r.success) { S.data = r.data; note('success', 'Schedule saved.'); } else { note('error', err(r)); }
					});
					break;
			}
		});
		$(document).on('change.wudtbk', '#wudt-backup-root [data-bk-comp], #wudt-backup-root [data-bk-rcomp]', function () {
			var c = $(this).data('bk-comp'), rc = $(this).data('bk-rcomp'), on = $(this).is(':checked');
			S.note = $('#wudt-bk-note').val() || S.note;
			if (c) { S.create = S.create.filter(function (x) { return x !== c; }); if (on) { S.create.push(c); } }
			if (rc && S.restore) { S.restore.components = S.restore.components.filter(function (x) { return x !== rc; }); if (on) { S.restore.components.push(rc); } }
			render();
		});
		$(document).on('change.wudtbk', '#wudt-bk-file', function () { if (this.files && this.files[0]) { upload(this.files[0]); } });
	}

	window.WUDTBackup = {
		mount: function (el, focus) {
			S.root = el; S.focus = focus || 'backup';
			bind(); render();
			if (!S.running) { refresh(); }
		}
	};
})(jQuery);
