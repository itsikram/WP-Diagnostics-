/**
 * Diagnostics Toolkit - Search & Replace
 * Search files and database, preview replacements, undo them.
 */

(function ($) {
	'use strict';

	const cfg = window.wudtSearchTool || {};
	const t = cfg.i18n || {};
	const PREFS_KEY = 'wudtSearchPrefs';
	const PAGE_SIZE = 50;
	const CONTENT_TABLES = ['posts', 'postmeta', 'options', 'terms', 'termmeta', 'comments', 'commentmeta'];

	const state = {
		opts: { case_sensitive: false, whole_word: false, regex: false },
		scope: 'files',
		tables: [],
		selectedTables: new Set(),
		snapshot: null,   // Parameters of the last completed search.
		files: null,      // Last file search response.
		db: null,         // Last database search response.
		pending: [],      // In-flight search requests (for cancel).
		preview: null,
		tab: 'files',
		filter: '',
		shown: { files: PAGE_SIZE, db: PAGE_SIZE },
		showContext: true,
		busy: false
	};

	// ============================================
	// Helpers
	// ============================================
	function esc(text) {
		return String(text === null || text === undefined ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function fmt(n) {
		return Number(n || 0).toLocaleString();
	}

	function plural(n, one, many) {
		return fmt(n) + ' ' + (Number(n) === 1 ? one : many);
	}

	function toast(type, message) {
		if (window.WUDTUI && WUDTUI.Toast) {
			WUDTUI.Toast[type](esc(message));
		}
	}

	function request(action, data) {
		const xhr = $.ajax({
			url: cfg.ajaxUrl || window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: $.extend({ action: action, nonce: cfg.nonce }, data || {})
		});

		const promise = new Promise(function (resolve, reject) {
			xhr.done(function (response) {
				if (response && response.success) {
					resolve(response.data || {});
				} else {
					reject(new Error((response && response.data && response.data.message) || t.requestFailed));
				}
			}).fail(function (jq, status) {
				const json = jq.responseJSON;
				const error = new Error(
					(json && json.data && json.data.message) ||
					(t.requestFailed + (jq.status ? ' (HTTP ' + jq.status + ')' : ''))
				);
				error.aborted = status === 'abort';
				reject(error);
			});
		});

		promise.xhr = xhr;
		return promise;
	}

	function loadPrefs() {
		try {
			return JSON.parse(window.localStorage.getItem(PREFS_KEY) || '{}') || {};
		} catch (e) {
			return {};
		}
	}

	function savePrefs() {
		try {
			window.localStorage.setItem(PREFS_KEY, JSON.stringify({
				opts: state.opts,
				scope: state.scope,
				directory: $('#wudt-directory').val(),
				extensions: getExtensions(),
				exclude: $('#wudt-sr-exclude').val(),
				skipMin: $('#wudt-sr-skip-min').is(':checked'),
				context: state.showContext,
				tables: Array.from(state.selectedTables)
			}));
		} catch (e) {
			// Storage unavailable; preferences just won't persist.
		}
	}

	function renderSegments(seg) {
		if (!seg || !seg.segments) {
			return '';
		}
		let html = seg.cut_start ? '<span class="wudt-sr-ellipsis">…</span>' : '';
		seg.segments.forEach(function (part) {
			html += part[1] ? '<mark>' + esc(part[0]) + '</mark>' : esc(part[0]);
		});
		if (seg.cut_end) {
			html += '<span class="wudt-sr-ellipsis">…</span>';
		}
		return html;
	}

	function segmentsText(seg) {
		return (seg && seg.segments ? seg.segments.map(function (p) { return p[0]; }).join('') : '');
	}

	function copyText(text) {
		const done = function () { toast('success', t.copied); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
			return;
		}
		const $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
		$tmp[0].select();
		try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
		$tmp.remove();
	}

	function setButtonBusy($btn, busy, label) {
		if (busy) {
			$btn.data('label', $btn.html()).prop('disabled', true)
				.html('<span class="wudt-sr-spinner" aria-hidden="true"></span>' + esc(label || ''));
		} else if ($btn.data('label')) {
			$btn.html($btn.data('label')).prop('disabled', false);
		}
	}

	// ============================================
	// Parameters
	// ============================================
	function getExtensions() {
		const list = [];
		$('#wudt-sr-ext-list input:checked').each(function () {
			list.push(this.value);
		});
		String($('#wudt-sr-ext-custom').val() || '').split(/[\s,]+/).forEach(function (ext) {
			ext = ext.replace(/^\.+/, '').toLowerCase().replace(/[^a-z0-9]/g, '');
			if (ext && list.indexOf(ext) === -1) {
				list.push(ext);
			}
		});
		return list;
	}

	function getExcludes() {
		return String($('#wudt-sr-exclude').val() || '').split(',').map(function (s) {
			return s.trim();
		}).filter(Boolean);
	}

	function currentParams() {
		return {
			search: $('#wudt-search-input').val(),
			scope: state.scope,
			regex: state.opts.regex ? '1' : '0',
			case_sensitive: state.opts.case_sensitive ? '1' : '0',
			whole_word: state.opts.whole_word ? '1' : '0',
			directory: $('#wudt-directory').val(),
			extensions: JSON.stringify(getExtensions()),
			exclude_dirs: JSON.stringify(getExcludes()),
			skip_minified: $('#wudt-sr-skip-min').is(':checked') ? '1' : '0',
			tables: JSON.stringify(Array.from(state.selectedTables).sort())
		};
	}

	function baseParams(p) {
		return { search: p.search, regex: p.regex, case_sensitive: p.case_sensitive, whole_word: p.whole_word };
	}

	function fileParams(p) {
		return $.extend(baseParams(p), {
			directory: p.directory,
			extensions: p.extensions,
			exclude_dirs: p.exclude_dirs,
			skip_minified: p.skip_minified
		});
	}

	function dbParams(p) {
		return $.extend(baseParams(p), { tables: p.tables });
	}

	function wantsFiles(p) { return p.scope !== 'db'; }
	function wantsDb(p) { return p.scope !== 'files'; }

	function isStale() {
		return !!state.snapshot && JSON.stringify(currentParams()) !== JSON.stringify(state.snapshot);
	}

	function hasResults() {
		return (state.files && state.files.files && state.files.files.length > 0) ||
			(state.db && state.db.results && state.db.results.length > 0);
	}

	function updateReplaceButton() {
		const $btn = $('#wudt-preview-replace-btn');
		const ok = hasResults() && !isStale() && !state.busy;
		$btn.prop('disabled', !ok).attr('title', ok ? '' : (hasResults() ? 'Search again with the current options first' : 'Run a search first'));
		$('#wudt-sr-stale').toggle(isStale() && hasResults());
	}

	// ============================================
	// Options UI
	// ============================================
	function setOption(name, value) {
		state.opts[name] = !!value;
		$('.wudt-sr-toggle[data-option="' + name + '"]').attr('aria-pressed', value ? 'true' : 'false');
		$('#wudt-sr-regex-hint').prop('hidden', !state.opts.regex);
		savePrefs();
		updateReplaceButton();
	}

	function setScope(scope) {
		state.scope = scope;
		$('.wudt-sr-segmented [data-scope]').each(function () {
			$(this).attr('aria-checked', $(this).data('scope') === scope ? 'true' : 'false');
		});
		$('[data-scope-section="files"]').prop('hidden', scope === 'db');
		$('[data-scope-section="db"]').prop('hidden', scope === 'files');
		if (scope !== 'files' && !state.tables.length) {
			loadTables();
		}
		savePrefs();
		updateReplaceButton();
	}

	function restorePrefs() {
		const prefs = loadPrefs();

		Object.keys(state.opts).forEach(function (key) {
			setOption(key, prefs.opts ? prefs.opts[key] : false);
		});

		if (prefs.directory && $('#wudt-directory option[value="' + CSS.escape(prefs.directory) + '"]').length) {
			$('#wudt-directory').val(prefs.directory);
		}

		if (Array.isArray(prefs.extensions) && prefs.extensions.length) {
			const custom = [];
			$('#wudt-sr-ext-list input').prop('checked', false);
			prefs.extensions.forEach(function (ext) {
				const $box = $('#wudt-sr-ext-list input[value="' + CSS.escape(ext) + '"]');
				if ($box.length) {
					$box.prop('checked', true);
				} else {
					custom.push(ext);
				}
			});
			$('#wudt-sr-ext-custom').val(custom.join(', '));
		}

		$('#wudt-sr-exclude').val(typeof prefs.exclude === 'string' ? prefs.exclude : ((cfg.defaults && cfg.defaults.excludeDirs) || []).join(', '));
		if (typeof prefs.skipMin === 'boolean') {
			$('#wudt-sr-skip-min').prop('checked', prefs.skipMin);
		}
		if (Array.isArray(prefs.tables)) {
			state.selectedTables = new Set(prefs.tables);
		}

		setContext(prefs.context !== false);
		setScope(prefs.scope || 'files');
	}

	function setContext(show) {
		state.showContext = !!show;
		$('#wudt-sr-toggle-context').attr('aria-pressed', show ? 'true' : 'false');
		$('#wudt-sr-results').toggleClass('wudt-sr-hide-context', !show);
	}

	// ============================================
	// Tables
	// ============================================
	function loadTables() {
		if (state.tablesLoading) {
			return;
		}
		state.tablesLoading = true;
		request('wudt_get_tables').then(function (data) {
			state.tables = data.tables || [];
			const names = new Set(state.tables.map(function (tbl) { return tbl.name; }));
			state.selectedTables.forEach(function (name) {
				if (!names.has(name)) {
					state.selectedTables.delete(name);
				}
			});
			renderTables();
		}).catch(function (err) {
			$('#wudt-sr-table-list').html('<div class="wudt-sr-alert wudt-sr-alert--error">' + esc(err.message) + '</div>');
		}).finally(function () {
			state.tablesLoading = false;
		});
	}

	function renderTables() {
		const filter = String($('#wudt-sr-table-filter').val() || '').toLowerCase();
		const prefix = cfg.dbPrefix || '';
		let html = '';

		state.tables.forEach(function (tbl) {
			if (filter && tbl.name.toLowerCase().indexOf(filter) === -1) {
				return;
			}
			const name = tbl.name;
			const short = prefix && name.indexOf(prefix) === 0 ? name.slice(prefix.length) : name;
			html += '<label class="wudt-sr-table">' +
				'<input type="checkbox" value="' + esc(name) + '"' + (state.selectedTables.has(name) ? ' checked' : '') + ' />' +
				'<span class="wudt-sr-table__name"><span class="wudt-sr-muted">' + esc(name.slice(0, name.length - short.length)) + '</span>' + esc(short) + '</span>' +
				'<span class="wudt-sr-table__rows">' + fmt(parseInt(tbl.rows_count, 10) || 0) + '</span>' +
				'</label>';
		});

		$('#wudt-sr-table-list').html(html || '<p class="wudt-sr-hint">No tables match.</p>');
		updateTableCount();
	}

	function updateTableCount() {
		const n = state.selectedTables.size;
		$('#wudt-sr-table-count').text(n ? n + ' of ' + state.tables.length + ' selected' : 'All ' + state.tables.length + ' tables');
	}

	// ============================================
	// Search
	// ============================================
	function showQueryError(message) {
		$('#wudt-sr-query-error').text(message || '').prop('hidden', !message);
		$('#wudt-search-input').closest('.wudt-sr-field').toggleClass('has-error', !!message);
	}

	function validate(p) {
		if (!p.search) {
			showQueryError(t.enterSearch);
			$('#wudt-search-input').trigger('focus');
			return false;
		}
		if (wantsFiles(p) && JSON.parse(p.extensions).length === 0) {
			showQueryError(t.pickExtension);
			return false;
		}
		showQueryError('');
		return true;
	}

	function setStep(key, status, text) {
		const $step = $('#wudt-sr-steps [data-step="' + key + '"]');
		$step.attr('data-status', status);
		if (text) {
			$step.find('.wudt-sr-step__text').text(text);
		}
	}

	function runSearch() {
		if (state.busy) {
			return;
		}
		const p = currentParams();
		if (!validate(p)) {
			return;
		}

		state.busy = true;
		state.pending = [];
		$('#wudt-sr-empty').prop('hidden', true);
		setButtonBusy($('#wudt-search-btn'), true, 'Searching…');
		updateReplaceButton();

		let steps = '';
		if (wantsFiles(p)) {
			steps += '<li class="wudt-sr-step" data-step="files" data-status="running"><span class="wudt-sr-step__icon"></span><span class="wudt-sr-step__text">' + esc(t.searchingFiles) + '</span></li>';
		}
		if (wantsDb(p)) {
			steps += '<li class="wudt-sr-step" data-step="db" data-status="running"><span class="wudt-sr-step__icon"></span><span class="wudt-sr-step__text">' + esc(t.searchingDb) + '</span></li>';
		}
		$('#wudt-sr-steps').html(steps);
		$('#wudt-sr-status').prop('hidden', false);

		const errors = [];
		const jobs = [];
		let files = null;
		let db = null;

		if (wantsFiles(p)) {
			const req = request('wudt_search_files', fileParams(p));
			state.pending.push(req.xhr);
			jobs.push(req.then(function (data) {
				files = data;
				setStep('files', 'done', plural(data.match_count, 'match', 'matches') + ' in ' + plural(data.files_matched, 'file', 'files') + ' · ' + plural(data.files_scanned, 'file', 'files') + ' scanned in ' + data.duration + 's');
			}, function (err) {
				setStep('files', 'failed', t.failed + ': ' + err.message);
				errors.push(err);
			}));
		}

		if (wantsDb(p)) {
			const req = request('wudt_search_db', dbParams(p));
			state.pending.push(req.xhr);
			jobs.push(req.then(function (data) {
				db = data;
				setStep('db', 'done', plural(data.rows_matched, 'row', 'rows') + ' in ' + plural(data.tables_matched, 'table', 'tables') + ' · ' + plural(data.tables_searched, 'table', 'tables') + ' searched in ' + data.duration + 's');
			}, function (err) {
				setStep('db', 'failed', t.failed + ': ' + err.message);
				errors.push(err);
			}));
		}

		Promise.all(jobs).then(function () {
			state.busy = false;
			state.pending = [];
			setButtonBusy($('#wudt-search-btn'), false);

			if (errors.some(function (e) { return e.aborted; })) {
				$('#wudt-sr-status').prop('hidden', true);
				toast('info', t.cancelled);
				updateReplaceButton();
				return;
			}

			// An invalid pattern fails both requests the same way: show it at the input.
			if (errors.length && !files && !db) {
				$('#wudt-sr-status').prop('hidden', true);
				showQueryError(errors[0].message);
				state.files = null;
				state.db = null;
				state.snapshot = null;
				$('#wudt-sr-results').prop('hidden', true);
				$('#wudt-sr-empty').prop('hidden', false);
				updateReplaceButton();
				return;
			}

			setTimeout(function () { $('#wudt-sr-status').prop('hidden', true); }, 600);

			state.snapshot = p;
			state.files = files;
			state.db = db;
			state.shown = { files: PAGE_SIZE, db: PAGE_SIZE };
			renderResults(errors);
			saveHistory(p);
			updateReplaceButton();
		});
	}

	function cancelSearch() {
		state.pending.forEach(function (xhr) {
			if (xhr && xhr.abort) {
				xhr.abort();
			}
		});
	}

	// ============================================
	// Results
	// ============================================
	function renderResults(errors) {
		const p = state.snapshot;
		const files = state.files;
		const db = state.db;
		const fileCount = files ? files.files.length : 0;
		const rowCount = db ? db.results.length : 0;
		const parts = [];

		if (files) {
			parts.push('<strong>' + fmt(files.match_count) + '</strong> ' + (files.match_count === 1 ? 'match' : 'matches') + ' in <strong>' + fmt(files.files_matched) + '</strong> ' + (files.files_matched === 1 ? 'file' : 'files'));
		}
		if (db) {
			parts.push('<strong>' + fmt(db.rows_matched) + '</strong> ' + (db.rows_matched === 1 ? 'row' : 'rows') + ' in <strong>' + fmt(db.tables_matched) + '</strong> ' + (db.tables_matched === 1 ? 'table' : 'tables'));
		}

		const meta = [];
		if (files) {
			meta.push(esc(files.directory) + ' · ' + plural(files.files_scanned, 'file', 'files') + ' scanned' + (files.files_skipped ? ' (' + fmt(files.files_skipped) + ' too large or unreadable)' : ''));
		}
		if (db) {
			meta.push(plural(db.tables_searched, 'table', 'tables') + ' searched');
		}

		$('#wudt-sr-summary').html(
			'<div class="wudt-sr-summary__main">' +
				'<span class="wudt-sr-summary__query"><code>' + esc(p.search) + '</code>' + optionBadges(p) + '</span>' +
				'<span class="wudt-sr-summary__counts">' + parts.join('<span class="wudt-sr-dot">·</span>') + '</span>' +
			'</div>' +
			'<div class="wudt-sr-summary__meta">' + meta.join('<span class="wudt-sr-dot">·</span>') + '</div>' +
			'<div class="wudt-sr-alert wudt-sr-alert--info" id="wudt-sr-stale" style="display:none">Options changed since this search. Press <kbd>Enter</kbd> in the search box to search again.</div>'
		);

		const notices = [];
		[files, db].forEach(function (res) {
			if (!res || !res.truncated) {
				return;
			}
			const msg = { limit: t.truncatedLimit, time: t.truncatedTime, rows: t.truncatedRows }[res.truncated];
			if (msg) {
				notices.push('<div class="wudt-sr-alert wudt-sr-alert--warning">' + esc(msg) + '</div>');
			}
		});
		(errors || []).forEach(function (err) {
			notices.push('<div class="wudt-sr-alert wudt-sr-alert--error">' + esc(err.message) + '</div>');
		});
		$('#wudt-sr-notices').html(notices.join(''));

		$('[data-count="files"]').text(fmt(fileCount));
		$('[data-count="db"]').text(fmt(rowCount));
		$('.wudt-sr-tab[data-tab="files"]').prop('hidden', !files);
		$('.wudt-sr-tab[data-tab="db"]').prop('hidden', !db);

		let tab = state.tab;
		if (tab === 'files' && (!files || (!fileCount && rowCount))) {
			tab = 'db';
		} else if (tab === 'db' && (!db || (!rowCount && fileCount))) {
			tab = 'files';
		}
		if (!files && !db) {
			tab = 'files';
		}
		selectTab(tab);

		$('#wudt-sr-results').prop('hidden', false);
		renderFileList();
		renderDbList();
	}

	function optionBadges(p) {
		let html = '';
		if (p.regex === '1') html += '<span class="wudt-sr-opt">.*</span>';
		if (p.case_sensitive === '1') html += '<span class="wudt-sr-opt">Aa</span>';
		if (p.whole_word === '1') html += '<span class="wudt-sr-opt"><span class="wudt-sr-ww">ab</span></span>';
		return html;
	}

	function selectTab(tab) {
		state.tab = tab;
		$('.wudt-sr-tab').each(function () {
			$(this).attr('aria-selected', $(this).data('tab') === tab ? 'true' : 'false');
		});
		$('[data-panel="files"]').prop('hidden', tab !== 'files');
		$('[data-panel="db"]').prop('hidden', tab !== 'db');
	}

	function emptyState(title, hint) {
		return '<div class="wudt-sr-empty wudt-sr-empty--inline">' +
			'<span class="dashicons dashicons-info-outline wudt-sr-empty__icon" aria-hidden="true"></span>' +
			'<h3 class="wudt-sr-empty__title">' + esc(title) + '</h3>' +
			(hint ? '<p class="wudt-sr-hint">' + esc(hint) + '</p>' : '') +
			'</div>';
	}

	function filterMatches(text) {
		return !state.filter || String(text).toLowerCase().indexOf(state.filter) !== -1;
	}

	function renderFileList() {
		const $list = $('#wudt-file-results-list');
		if (!state.files) {
			$list.empty();
			return;
		}

		const items = state.files.files.filter(function (f) { return filterMatches(f.path); });
		if (!items.length) {
			$list.html(state.files.files.length ? emptyState('No files match the filter.') : emptyState(t.noResults, t.noResultsHint));
			return;
		}

		const html = items.slice(0, state.shown.files).map(fileCard).join('');
		$list.html(html + moreButton('files', items.length));
	}

	function fileCard(file) {
		const slash = file.path.lastIndexOf('/');
		const dir = slash >= 0 ? file.path.slice(0, slash + 1) : '';
		const name = slash >= 0 ? file.path.slice(slash + 1) : file.path;
		const ext = (name.indexOf('.') >= 0 ? name.split('.').pop() : 'file').slice(0, 4);

		let code = '';
		let last = 0;
		file.matches.forEach(function (m) {
			const first = m.before.length ? m.before[0][0] : m.line;
			if (last && first > last + 1) {
				code += '<div class="wudt-sr-sep" aria-hidden="true">⋯</div>';
			}
			m.before.forEach(function (ctx) {
				if (ctx[0] > last) {
					code += lineRow(ctx[0], esc(ctx[1]), false);
					last = ctx[0];
				}
			});
			if (m.line > last) {
				code += lineRow(m.line, renderSegments(m), true);
			} else {
				// Already printed as context of the previous match: upgrade it to a match row.
				code = code.replace(new RegExp('<div class="wudt-sr-line is-ctx" data-line="' + m.line + '">[\\s\\S]*?</code></div>'), lineRow(m.line, renderSegments(m), true));
			}
			last = Math.max(last, m.line);
			m.after.forEach(function (ctx) {
				if (ctx[0] > last) {
					code += lineRow(ctx[0], esc(ctx[1]), false);
					last = ctx[0];
				}
			});
		});

		const more = file.lines_shown >= 100 ? '<div class="wudt-sr-more-note">Only the first ' + fmt(file.lines_shown) + ' matching lines are shown.</div>' : '';

		return '<article class="wudt-sr-card" data-path="' + esc(file.path) + '">' +
			'<header class="wudt-sr-card__head" tabindex="0" role="button" aria-expanded="true">' +
				'<span class="dashicons dashicons-arrow-down-alt2 wudt-sr-caret" aria-hidden="true"></span>' +
				'<span class="wudt-sr-ftype wudt-sr-ftype--' + esc(ext.toLowerCase()) + '">' + esc(ext) + '</span>' +
				'<span class="wudt-sr-path" title="' + esc(file.path) + '"><span class="wudt-sr-path__dir">' + esc(dir) + '</span><span class="wudt-sr-path__name">' + esc(name) + '</span></span>' +
				(file.writable ? '' : '<span class="wudt-sr-tag wudt-sr-tag--warn" title="Replace will skip this file">read-only</span>') +
				'<span class="wudt-sr-badge" title="Matches in this file">' + fmt(file.match_count) + '</span>' +
				'<button type="button" class="wudt-sr-icon-btn" data-copy="' + esc(file.path) + '" title="Copy path" aria-label="Copy path"><span class="dashicons dashicons-admin-page"></span></button>' +
			'</header>' +
			'<div class="wudt-sr-card__body"><div class="wudt-sr-code">' + code + '</div>' + more + '</div>' +
			'</article>';
	}

	function lineRow(num, html, isMatch) {
		return '<div class="wudt-sr-line ' + (isMatch ? 'is-match' : 'is-ctx') + '" data-line="' + num + '">' +
			'<span class="wudt-sr-ln">' + num + '</span><code>' + (html || ' ') + '</code></div>';
	}

	function renderDbList() {
		const $list = $('#wudt-db-results-list');
		if (!state.db) {
			$list.empty();
			return;
		}

		const items = state.db.results.filter(function (r) { return filterMatches(r.table + '.' + r.column); });
		if (!items.length) {
			$list.html(state.db.results.length ? emptyState('No rows match the filter.') : emptyState(t.noResults, t.noResultsHint));
			return;
		}

		const html = items.slice(0, state.shown.db).map(dbCard).join('');
		$list.html(html + moreButton('db', items.length));
	}

	function dbCard(row, index) {
		const id = row.row_id === null ? '' : String(row.row_id);
		return '<article class="wudt-sr-card" data-table="' + esc(row.table) + '" data-column="' + esc(row.column) + '" data-row="' + esc(id) + '">' +
			'<header class="wudt-sr-card__head" tabindex="0" role="button" aria-expanded="true">' +
				'<span class="dashicons dashicons-arrow-down-alt2 wudt-sr-caret" aria-hidden="true"></span>' +
				'<span class="wudt-sr-ftype wudt-sr-ftype--db">DB</span>' +
				'<span class="wudt-sr-path"><span class="wudt-sr-path__name">' + esc(row.table) + '</span><span class="wudt-sr-path__dir">.' + esc(row.column) + '</span>' +
				(id ? '<span class="wudt-sr-rowid">' + esc((row.primary_key || 'id') + ' = ' + id) + '</span>' : '<span class="wudt-sr-tag wudt-sr-tag--warn" title="Rows without a primary key cannot be replaced">no primary key</span>') +
				'</span>' +
				(row.serialized ? '<span class="wudt-sr-tag" title="Unpacked before replacing, so it stays valid">serialized</span>' : '') +
				'<span class="wudt-sr-badge" title="Matches in this value">' + fmt(row.match_count) + '</span>' +
				(id ? '<button type="button" class="wudt-sr-icon-btn" data-view-value title="View full value" aria-label="View full value"><span class="dashicons dashicons-visibility"></span></button>' : '') +
				(row.edit_url ? '<a class="wudt-sr-icon-btn" href="' + esc(row.edit_url) + '" target="_blank" rel="noopener" title="Open edit screen" aria-label="Open edit screen"><span class="dashicons dashicons-external"></span></a>' : '') +
			'</header>' +
			'<div class="wudt-sr-card__body"><div class="wudt-sr-snippet">' + renderSegments(row) + '</div>' +
			'<div class="wudt-sr-more-note">' + fmt(row.length) + ' characters</div></div>' +
			'</article>';
	}

	function moreButton(kind, total) {
		const shown = Math.min(state.shown[kind], total);
		if (shown >= total) {
			return '';
		}
		return '<div class="wudt-sr-more"><button type="button" class="wudt-btn wudt-btn--secondary" data-more="' + kind + '">' +
			'Show ' + fmt(Math.min(PAGE_SIZE, total - shown)) + ' more <span class="wudt-sr-muted">(' + fmt(shown) + ' of ' + fmt(total) + ')</span></button></div>';
	}

	function toggleCard($head, expand) {
		const open = expand === undefined ? $head.attr('aria-expanded') !== 'true' : expand;
		$head.attr('aria-expanded', open ? 'true' : 'false');
		$head.next('.wudt-sr-card__body').prop('hidden', !open);
	}

	function viewValue($card) {
		const p = state.snapshot;
		const table = $card.data('table');
		const column = $card.data('column');
		const rowId = String($card.data('row'));

		$('#wudt-sr-value-title').text(table + '.' + column + ' #' + rowId);
		$('#wudt-sr-value-meta').text('Loading…');
		$('#wudt-sr-value-body').empty();
		openModal('#wudt-sr-value-modal');

		request('wudt_search_db_value', $.extend(dbParams(p), { table: table, column: column, row_id: rowId })).then(function (data) {
			$('#wudt-sr-value-meta').text(fmt(data.length) + ' characters' + (data.truncated ? ' — showing the first 200 KB' : ''));
			$('#wudt-sr-value-body').html(renderSegments({ segments: data.segments }));
			const mark = $('#wudt-sr-value-body mark')[0];
			if (mark) {
				mark.scrollIntoView({ block: 'center' });
			}
		}).catch(function (err) {
			$('#wudt-sr-value-meta').html('<span class="wudt-sr-alert wudt-sr-alert--error">' + esc(err.message) + '</span>');
		});
	}

	// ============================================
	// Replace
	// ============================================
	function previewReplace() {
		if (!hasResults() || isStale() || state.busy) {
			return;
		}

		const p = state.snapshot;
		const replace = $('#wudt-replace-input').val();
		const jobs = [];
		const $btn = $('#wudt-preview-replace-btn');

		setButtonBusy($btn, true, t.previewing);
		state.busy = true;

		const result = { replace: replace, files: null, db: null, errors: [] };
		if (state.files && state.files.files.length) {
			jobs.push(request('wudt_preview_replace_files', $.extend(fileParams(p), { replace: replace }))
				.then(function (d) { result.files = d; }, function (e) { result.errors.push(e); }));
		}
		if (state.db && state.db.results.length) {
			jobs.push(request('wudt_preview_replace_db', $.extend(dbParams(p), { replace: replace }))
				.then(function (d) { result.db = d; }, function (e) { result.errors.push(e); }));
		}

		Promise.all(jobs).then(function () {
			state.busy = false;
			setButtonBusy($btn, false);
			updateReplaceButton();

			if (!result.files && !result.db) {
				toast('error', result.errors.length ? result.errors[0].message : t.requestFailed);
				return;
			}
			state.preview = result;
			renderPreview();
			openModal('#wudt-replace-preview-modal');
		});
	}

	function diffRows(sample) {
		const ln = sample.line ? '<span class="wudt-sr-ln">' + sample.line + '</span>' : '';
		const after = $.extend(true, {}, sample.after);
		const del = renderSegments(sample.before).replace(/<mark>/g, '<del>').replace(/<\/mark>/g, '</del>');
		const ins = renderSegments(after).replace(/<mark>/g, '<ins>').replace(/<\/mark>/g, '</ins>');
		return '<div class="wudt-sr-diff">' +
			'<div class="wudt-sr-diff__row is-del">' + ln + '<span class="wudt-sr-diff__sign">−</span><code>' + del + '</code></div>' +
			'<div class="wudt-sr-diff__row is-add">' + ln + '<span class="wudt-sr-diff__sign">+</span><code>' + ins + '</code></div>' +
			'</div>';
	}

	function previewGroup(kind, title, data) {
		if (!data) {
			return '';
		}
		const items = data.items || [];
		if (!items.length) {
			return '';
		}
		let html = '<section class="wudt-sr-pgroup" data-group="' + kind + '">' +
			'<label class="wudt-sr-pgroup__head"><input type="checkbox" data-select-group="' + kind + '" checked /> ' +
			'<strong>' + esc(title) + '</strong> <span class="wudt-sr-muted">' + plural(data.total_items, kind === 'files' ? 'file' : 'row', kind === 'files' ? 'files' : 'rows') + '</span></label>';

		items.forEach(function (item) {
			const ok = kind === 'files' ? item.writable : item.can_replace;
			const label = kind === 'files' ? item.path : item.table + '.' + item.column + ' #' + item.row_id;
			const reason = kind === 'files' ? (ok ? '' : 'File is not writable') : item.reason;
			const samples = kind === 'files' ? item.samples : [{ line: 0, before: item.before, after: item.after }];

			html += '<div class="wudt-sr-pitem' + (ok ? '' : ' is-disabled') + '">' +
				'<label class="wudt-sr-pitem__head">' +
					'<input type="checkbox" data-key="' + esc(item.key) + '" data-kind="' + kind + '"' + (ok ? ' checked' : ' disabled') + ' />' +
					'<span class="wudt-sr-pitem__title">' + esc(label) + '</span>' +
					(item.serialized ? '<span class="wudt-sr-tag">serialized</span>' : '') +
					'<span class="wudt-sr-badge">' + fmt(item.replace_count) + '</span>' +
				'</label>' +
				(reason ? '<div class="wudt-sr-pitem__reason">' + esc(reason) + '</div>' : '') +
				samples.map(diffRows).join('') +
				(kind === 'files' && item.lines_changed > samples.length ? '<div class="wudt-sr-more-note">+ ' + plural(item.lines_changed - samples.length, 'more line', 'more lines') + '</div>' : '') +
				'</div>';
		});

		if (data.total_items > items.length) {
			html += '<div class="wudt-sr-more-note">Showing the first ' + fmt(items.length) + '. With every item selected, all ' + fmt(data.total_items) + ' are replaced.</div>';
		}
		return html + '</section>';
	}

	function renderPreview() {
		const pv = state.preview;
		const total = (pv.files ? pv.files.total_replacements : 0) + (pv.db ? pv.db.total_replacements : 0);

		let summary = '<div class="wudt-sr-preview-summary__line">' +
			'<code class="wudt-sr-chip-code">' + esc(state.snapshot.search) + '</code>' +
			'<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>' +
			(pv.replace === '' ? '<em class="wudt-sr-muted">(delete)</em>' : '<code class="wudt-sr-chip-code wudt-sr-chip-code--new">' + esc(pv.replace) + '</code>') +
			'</div>' +
			'<p>About <strong>' + fmt(total) + '</strong> ' + (total === 1 ? 'replacement' : 'replacements') + '. Untick anything you want to leave alone.</p>';

		if (pv.errors.length) {
			summary += pv.errors.map(function (e) { return '<div class="wudt-sr-alert wudt-sr-alert--error">' + esc(e.message) + '</div>'; }).join('');
		}
		$('#wudt-preview-summary').html(summary);

		const body = previewGroup('files', 'Files', pv.files) + previewGroup('db', 'Database', pv.db);
		$('#wudt-preview-changes').html(body || emptyState(t.nothingToReplace));
		updateConfirmButton();
	}

	function selectionFor(kind) {
		const data = state.preview && state.preview[kind];
		if (!data || !data.items || !data.items.length) {
			return { run: false };
		}
		const $boxes = $('#wudt-preview-changes input[data-kind="' + kind + '"]:not(:disabled)');
		const $checked = $boxes.filter(':checked');
		if (!$checked.length) {
			return { run: false };
		}
		// Everything ticked and the preview was capped: let the server apply to all matches.
		if ($checked.length === $boxes.length && data.total_items > data.items.length) {
			return { run: true, selection: null, count: data.total_items };
		}
		return { run: true, selection: $checked.map(function () { return String($(this).data('key')); }).get(), count: $checked.length };
	}

	function updateConfirmButton() {
		['files', 'db'].forEach(function (kind) {
			const $boxes = $('#wudt-preview-changes input[data-kind="' + kind + '"]:not(:disabled)');
			const checked = $boxes.filter(':checked').length;
			$('[data-select-group="' + kind + '"]')
				.prop('checked', checked > 0 && checked === $boxes.length)
				.prop('indeterminate', checked > 0 && checked < $boxes.length);
		});
		const n = (selectionFor('files').count || 0) + (selectionFor('db').count || 0);
		$('#wudt-confirm-replace').prop('disabled', n === 0).text(n ? 'Replace in ' + plural(n, 'item', 'items') : 'Replace');
	}

	function executeReplace() {
		const pv = state.preview;
		const p = state.snapshot;
		const filesSel = selectionFor('files');
		const dbSel = selectionFor('db');

		if (!filesSel.run && !dbSel.run) {
			toast('warning', t.nothingSelected);
			return;
		}
		if (pv.replace === '' && !window.confirm(t.confirmDelete)) {
			return;
		}

		const common = { replace: pv.replace, confirmed: '1', backup: $('#wudt-create-backup').is(':checked') ? '1' : '0' };
		const $btn = $('#wudt-confirm-replace');
		setButtonBusy($btn, true, t.replacing);

		const outcome = { files: null, db: null, errors: [] };
		const jobs = [];
		const withSelection = function (data, sel) {
			if (sel.selection) {
				data.selection = JSON.stringify(sel.selection);
			}
			return data;
		};

		if (filesSel.run) {
			jobs.push(request('wudt_execute_replace_files', withSelection($.extend(fileParams(p), common), filesSel))
				.then(function (d) { outcome.files = d; }, function (e) { outcome.errors.push(e); }));
		}
		if (dbSel.run) {
			jobs.push(request('wudt_execute_replace_db', withSelection($.extend(dbParams(p), common), dbSel))
				.then(function (d) { outcome.db = d; }, function (e) { outcome.errors.push(e); }));
		}

		Promise.all(jobs).then(function () {
			setButtonBusy($btn, false);
			closeModals();
			showReplaceBanner(outcome);
			// Refresh results so they reflect the new content.
			runSearch();
		});
	}

	function showReplaceBanner(outcome) {
		const parts = [];
		const backups = [];
		let failures = [];

		if (outcome.files) {
			parts.push(plural(outcome.files.files_modified, 'file', 'files'));
			if (outcome.files.backup_id) backups.push(outcome.files.backup_id);
			failures = failures.concat(outcome.files.errors || []);
		}
		if (outcome.db) {
			parts.push(plural(outcome.db.rows_modified, 'row', 'rows'));
			if (outcome.db.backup_id) backups.push(outcome.db.backup_id);
			failures = failures.concat(outcome.db.errors || []);
		}

		const replacements = (outcome.files ? outcome.files.replacements : 0) + (outcome.db ? outcome.db.replacements : 0);
		const ok = parts.length > 0;
		let html = '<div class="wudt-sr-alert ' + (ok ? 'wudt-sr-alert--success' : 'wudt-sr-alert--error') + ' wudt-sr-banner">' +
			'<span class="dashicons ' + (ok ? 'dashicons-yes-alt' : 'dashicons-warning') + '" aria-hidden="true"></span>' +
			'<div class="wudt-sr-banner__text">';

		if (ok) {
			html += '<strong>Replaced ' + plural(replacements, 'occurrence', 'occurrences') + '</strong> in ' + esc(parts.join(' and ')) + '.';
		}
		outcome.errors.forEach(function (e) {
			html += '<div>' + esc(e.message) + '</div>';
		});
		if (failures.length) {
			html += '<details><summary>' + plural(failures.length, 'item', 'items') + ' skipped</summary><ul>' +
				failures.slice(0, 50).map(function (f) { return '<li><code>' + esc(f.item) + '</code> — ' + esc(f.error) + '</li>'; }).join('') +
				'</ul></details>';
		}
		html += '</div>';
		if (backups.length) {
			html += '<button type="button" class="wudt-btn wudt-btn--secondary wudt-btn--sm" data-undo="' + esc(backups.join(',')) + '"><span class="dashicons dashicons-undo" aria-hidden="true"></span>Undo</button>';
		}
		html += '<button type="button" class="wudt-sr-icon-btn" data-dismiss aria-label="Dismiss"><span class="dashicons dashicons-no-alt"></span></button></div>';

		$('#wudt-sr-banner').html(html);
	}

	// ============================================
	// Undo history
	// ============================================
	function restoreBackups(ids, $btn) {
		if (!window.confirm(t.confirmRestore)) {
			return;
		}
		setButtonBusy($btn, true, 'Restoring…');

		const results = { files: 0, rows: 0, skipped: [] };
		const errors = [];
		const jobs = ids.map(function (id) {
			return request('wudt_search_restore_backup', { id: id }).then(function (d) {
				results.files += d.files_restored;
				results.rows += d.rows_restored;
				results.skipped = results.skipped.concat(d.skipped || []);
			}, function (e) { errors.push(e); });
		});

		Promise.all(jobs).then(function () {
			setButtonBusy($btn, false);
			if (errors.length) {
				toast('error', errors[0].message);
			}
			let msg = t.restored + ': ' + plural(results.files, 'file', 'files') + ', ' + plural(results.rows, 'row', 'rows') + '.';
			if (results.skipped.length) {
				msg += ' ' + plural(results.skipped.length, 'item was', 'items were') + ' skipped because ' + (results.skipped.length === 1 ? 'it' : 'they') + ' changed again since.';
			}
			toast(results.skipped.length ? 'warning' : 'success', msg);
			$('#wudt-sr-banner').empty();
			if (!$('#wudt-sr-history-modal').prop('hidden')) {
				loadBackups();
			}
			if (state.snapshot) {
				runSearch();
			}
		});
	}

	function loadBackups() {
		const $list = $('#wudt-sr-history-list').html('<div class="wudt-sr-skeleton"></div><div class="wudt-sr-skeleton"></div>');
		request('wudt_search_backups').then(function (data) {
			const items = data.backups || [];
			if (!items.length) {
				$list.html(emptyState('No undo backups yet', 'They appear here after you run a replace with "Keep an undo backup" on.'));
				return;
			}
			$list.html(items.map(function (b) {
				const date = new Date(b.created * 1000).toLocaleString();
				const what = [];
				if (b.files) what.push(plural(b.files, 'file', 'files'));
				if (b.rows) what.push(plural(b.rows, 'row', 'rows'));
				return '<div class="wudt-sr-hitem">' +
					'<div class="wudt-sr-hitem__main">' +
						'<div class="wudt-sr-preview-summary__line"><code class="wudt-sr-chip-code">' + esc(b.search) + '</code>' +
						'<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>' +
						(b.replace === '' ? '<em class="wudt-sr-muted">(delete)</em>' : '<code class="wudt-sr-chip-code wudt-sr-chip-code--new">' + esc(b.replace) + '</code>') + '</div>' +
						'<div class="wudt-sr-hint">' + esc(date) + ' · ' + esc(what.join(', ')) +
						(b.restored ? ' · <span class="wudt-sr-tag">undone ' + esc(new Date(b.restored * 1000).toLocaleString()) + '</span>' : '') + '</div>' +
					'</div>' +
					'<div class="wudt-sr-hitem__actions">' +
						'<button type="button" class="wudt-btn wudt-btn--secondary wudt-btn--sm" data-restore="' + esc(b.id) + '"><span class="dashicons dashicons-undo" aria-hidden="true"></span>Undo</button>' +
						'<button type="button" class="wudt-sr-icon-btn" data-delete-backup="' + esc(b.id) + '" title="Delete backup" aria-label="Delete backup"><span class="dashicons dashicons-trash"></span></button>' +
					'</div></div>';
			}).join(''));
		}).catch(function (err) {
			$list.html('<div class="wudt-sr-alert wudt-sr-alert--error">' + esc(err.message) + '</div>');
		});
	}

	// ============================================
	// Recent searches
	// ============================================
	function renderHistory(history) {
		state.history = history || [];
		const html = state.history.slice(0, 8).map(function (h, i) {
			const label = h.search.length > 40 ? h.search.slice(0, 40) + '…' : h.search;
			return '<button type="button" class="wudt-sr-chip" data-history="' + i + '" title="' + esc(h.search) + '">' +
				(h.regex ? '<span class="wudt-sr-opt">.*</span>' : '') + '<code>' + esc(label) + '</code></button>';
		}).join('');
		$('#wudt-sr-recent-list').html(html);
		$('#wudt-sr-recent').prop('hidden', !state.history.length);
	}

	function saveHistory(p) {
		request('wudt_save_search_history', $.extend(baseParams(p), {
			files: wantsFiles(p) ? '1' : '0',
			db: wantsDb(p) ? '1' : '0'
		})).then(function (data) {
			renderHistory(data.history);
		}).catch(function () { /* not important */ });
	}

	function applyHistory(h) {
		$('#wudt-search-input').val(h.search);
		setOption('regex', h.regex);
		setOption('case_sensitive', h.case_sensitive);
		setOption('whole_word', h.whole_word);
		setScope(h.files && h.db ? 'both' : (h.db ? 'db' : 'files'));
		runSearch();
	}

	// ============================================
	// Modals
	// ============================================
	function openModal(selector) {
		const $modal = $(selector).prop('hidden', false);
		state.lastFocus = document.activeElement;
		setTimeout(function () {
			$modal.find('button, input, a').filter(':visible').first().trigger('focus');
		}, 50);
	}

	function closeModals() {
		$('.wudt-sr-modal').prop('hidden', true);
		if (state.lastFocus && state.lastFocus.focus) {
			state.lastFocus.focus();
		}
	}

	// ============================================
	// Export
	// ============================================
	function download(content, filename, type) {
		const blob = new Blob([content], { type: type });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	function exportResults(format) {
		if (!state.snapshot) {
			return;
		}
		const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
		const rows = [];

		if (state.files) {
			state.files.files.forEach(function (f) {
				f.matches.forEach(function (m) {
					rows.push({ type: 'file', location: f.path, line: m.line, matches: f.match_count, text: segmentsText(m) });
				});
			});
		}
		if (state.db) {
			state.db.results.forEach(function (r) {
				rows.push({ type: 'database', location: r.table + '.' + r.column + (r.row_id !== null ? '#' + r.row_id : ''), line: '', matches: r.match_count, text: segmentsText(r) });
			});
		}

		if (format === 'json') {
			download(JSON.stringify({ search: state.snapshot.search, options: baseParams(state.snapshot), exported_at: new Date().toISOString(), results: rows }, null, 2), 'search-results-' + stamp + '.json', 'application/json');
		} else {
			const cell = function (v) { return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"'; };
			const csv = ['type,location,line,matches,text'].concat(rows.map(function (r) {
				return [r.type, r.location, r.line, r.matches, r.text].map(cell).join(',');
			})).join('\r\n');
			download('﻿' + csv, 'search-results-' + stamp + '.csv', 'text/csv');
		}
	}

	// ============================================
	// Events
	// ============================================
	function bindEvents() {
		const $doc = $(document);

		$('#wudt-sr-form').on('submit', function (e) {
			e.preventDefault();
			runSearch();
		});

		$('#wudt-search-input').on('input', function () {
			showQueryError('');
			updateReplaceButton();
		});

		$('#wudt-replace-input').on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				previewReplace();
			}
		});

		$doc.on('click', '.wudt-sr-toggle', function () {
			const name = $(this).data('option');
			setOption(name, !state.opts[name]);
			$('#wudt-search-input').trigger('focus');
		});

		$doc.on('click', '.wudt-sr-segmented [data-scope]', function () {
			setScope($(this).data('scope'));
		});

		$doc.on('change input', '#wudt-directory, #wudt-sr-ext-list input, #wudt-sr-ext-custom, #wudt-sr-exclude, #wudt-sr-skip-min', function () {
			savePrefs();
			updateReplaceButton();
		});

		$doc.on('click', '.wudt-sr-chip--pattern', function () {
			$('#wudt-search-input').val($(this).data('pattern'));
			setOption('regex', true);
			setOption('whole_word', false);
			showQueryError('');
			toast('info', t.patternLoaded);
			$('#wudt-search-input').trigger('focus');
		});

		$doc.on('click', '[data-history]', function () {
			const h = state.history[parseInt($(this).data('history'), 10)];
			if (h) {
				applyHistory(h);
			}
		});

		$('#wudt-sr-clear-history').on('click', function () {
			request('wudt_clear_search_history').then(function () { renderHistory([]); });
		});

		// Tables
		$('#wudt-sr-table-filter').on('input', renderTables);
		$doc.on('change', '#wudt-sr-table-list input', function () {
			if (this.checked) {
				state.selectedTables.add(this.value);
			} else {
				state.selectedTables.delete(this.value);
			}
			updateTableCount();
			savePrefs();
			updateReplaceButton();
		});
		$doc.on('click', '[data-tables]', function () {
			const mode = $(this).data('tables');
			const prefix = cfg.dbPrefix || '';
			state.selectedTables = new Set();
			if (mode === 'all') {
				state.tables.forEach(function (tbl) { state.selectedTables.add(tbl.name); });
			} else if (mode === 'content') {
				state.tables.forEach(function (tbl) {
					if (CONTENT_TABLES.indexOf(tbl.name.slice(prefix.length)) !== -1 && tbl.name.indexOf(prefix) === 0) {
						state.selectedTables.add(tbl.name);
					}
				});
			}
			renderTables();
			savePrefs();
			updateReplaceButton();
		});

		// Status
		$('#wudt-sr-cancel').on('click', cancelSearch);

		// Results
		$doc.on('click', '.wudt-sr-tab', function () {
			selectTab($(this).data('tab'));
		});

		let filterTimer;
		$('#wudt-sr-filter').on('input', function () {
			const value = String(this.value || '').toLowerCase().trim();
			clearTimeout(filterTimer);
			filterTimer = setTimeout(function () {
				state.filter = value;
				state.shown = { files: PAGE_SIZE, db: PAGE_SIZE };
				renderFileList();
				renderDbList();
			}, 150);
		});

		$doc.on('click keydown', '.wudt-sr-card__head', function (e) {
			if ($(e.target).closest('button, a').length) {
				return;
			}
			if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
				return;
			}
			e.preventDefault();
			toggleCard($(this));
		});

		$('#wudt-sr-collapse-all').on('click', function () {
			const $btn = $(this);
			const collapse = !$btn.data('collapsed');
			$('[data-panel="' + state.tab + '"] .wudt-sr-card__head').each(function () {
				toggleCard($(this), !collapse);
			});
			$btn.data('collapsed', collapse);
			$btn.find('.dashicons').toggleClass('dashicons-arrow-up-alt2', !collapse).toggleClass('dashicons-arrow-down-alt2', collapse);
			$btn.find('span:last').text(collapse ? 'Expand all' : 'Collapse all');
		});

		$('#wudt-sr-toggle-context').on('click', function () {
			setContext(!state.showContext);
			savePrefs();
		});

		$doc.on('click', '[data-more]', function () {
			const kind = $(this).data('more');
			state.shown[kind] += PAGE_SIZE;
			if (kind === 'files') {
				renderFileList();
			} else {
				renderDbList();
			}
		});

		$doc.on('click', '[data-copy]', function () {
			copyText(String($(this).data('copy')));
		});

		$doc.on('click', '[data-view-value]', function () {
			viewValue($(this).closest('.wudt-sr-card'));
		});

		// Export menu
		$('#wudt-export-results').on('click', function (e) {
			e.stopPropagation();
			const $menu = $(this).next('.wudt-sr-menu__list');
			const open = $menu.prop('hidden');
			$menu.prop('hidden', !open);
			$(this).attr('aria-expanded', open ? 'true' : 'false');
		});
		$doc.on('click', '[data-export]', function () {
			exportResults($(this).data('export'));
		});
		$doc.on('click', function () {
			$('.wudt-sr-menu__list').prop('hidden', true);
			$('#wudt-export-results').attr('aria-expanded', 'false');
		});

		// Replace
		$('#wudt-preview-replace-btn').on('click', previewReplace);
		$doc.on('change', '#wudt-preview-changes input[data-kind]', updateConfirmButton);
		$doc.on('change', '[data-select-group]', function () {
			$('#wudt-preview-changes input[data-kind="' + $(this).data('select-group') + '"]:not(:disabled)').prop('checked', this.checked);
			updateConfirmButton();
		});
		$('#wudt-confirm-replace').on('click', executeReplace);

		$doc.on('click', '[data-undo]', function () {
			restoreBackups(String($(this).data('undo')).split(','), $(this));
		});
		$doc.on('click', '#wudt-sr-banner [data-dismiss]', function () {
			$('#wudt-sr-banner').empty();
		});

		// History modal
		$('#wudt-sr-history-btn').on('click', function () {
			openModal('#wudt-sr-history-modal');
			loadBackups();
		});
		$doc.on('click', '[data-restore]', function () {
			restoreBackups([String($(this).data('restore'))], $(this));
		});
		$doc.on('click', '[data-delete-backup]', function () {
			if (!window.confirm(t.confirmDeleteBak)) {
				return;
			}
			request('wudt_search_delete_backup', { id: $(this).data('delete-backup') }).then(loadBackups, function (e) {
				toast('error', e.message);
			});
		});

		// Modal close
		$doc.on('click', '.wudt-sr-modal [data-close]', closeModals);
		$doc.on('mousedown', '.wudt-sr-modal', function (e) {
			if (e.target === this) {
				closeModals();
			}
		});

		// Keyboard shortcuts
		$doc.on('keydown', function (e) {
			if (e.key === 'Escape' && $('.wudt-sr-modal:not([hidden])').length) {
				closeModals();
				return;
			}
			if (e.altKey && !e.ctrlKey && !e.metaKey) {
				// Use the physical key so Option+C on macOS (which types "ç") still works.
				const map = { KeyC: 'case_sensitive', KeyW: 'whole_word', KeyR: 'regex' };
				const key = e.code;
				if (map[key]) {
					e.preventDefault();
					setOption(map[key], !state.opts[map[key]]);
				}
			}
		});
	}

	// ============================================
	// Init
	// ============================================
	function init() {
		if (!$('#wudt-search-input').length) {
			return;
		}
		bindEvents();
		restorePrefs();
		renderHistory(cfg.history || []);
		$('#wudt-search-input').trigger('focus');
	}

	$(init);

})(jQuery);
