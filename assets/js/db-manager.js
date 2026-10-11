/**
 * Diagnostics Toolkit — Database manager with a phpMyAdmin-style interface.
 *
 * Mounted by pro-admin.js into #wudt-dbm-root. Keeps its own state so it
 * survives re-renders of the surrounding admin app.
 */
(function ($) {
	'use strict';

	function cfg() {
		return window.wudtProAdmin || {};
	}

	var ROWS_OPTIONS = [25, 50, 100, 250, 500];
	var TEXT_LIMIT = 50;
	var NUMERIC = /^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit|year)\b/i;
	var TABLE_TABS = [
		['browse', 'Browse', 'browse'],
		['structure', 'Structure', 'props'],
		['sql', 'SQL', 'sql'],
		['search', 'Search', 'search'],
		['insert', 'Insert', 'insrow'],
		['export', 'Export', 'export'],
		['operations', 'Operations', 'tblops']
	];
	var DB_TABS = [
		['structure', 'Structure', 'props'],
		['sql', 'SQL', 'sql']
	];
	var OPS_NUMBER = ['=', '>', '>=', '<', '<=', '!=', 'LIKE', 'LIKE %...%', 'NOT LIKE', 'IN (...)', 'NOT IN (...)', 'BETWEEN', 'IS NULL', 'IS NOT NULL'];
	var OPS_TEXT = ['LIKE', 'LIKE %...%', 'NOT LIKE', 'NOT LIKE %...%', '=', '!=', 'REGEXP', 'NOT REGEXP', "= ''", "!= ''", 'IN (...)', 'NOT IN (...)', 'BETWEEN', 'IS NULL', 'IS NOT NULL'];
	var SQL_KEYWORDS = 'SELECT|FROM|WHERE|AND|OR|NOT|NULL|IS|IN|LIKE|REGEXP|BETWEEN|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|ASC|DESC|INSERT|INTO|VALUES|UPDATE|SET|DELETE|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AS|DISTINCT|COUNT|SHOW|TABLES|COLUMNS|FULL|DESCRIBE|EXPLAIN|CREATE|ALTER|TABLE|ADD|CHANGE|MODIFY|DROP|COLUMN|TRUNCATE|DEFAULT|AFTER|FIRST|PRIMARY|KEY|INDEX|UNIQUE|CHECK|ANALYZE|OPTIMIZE|REPAIR|UNION|ALL|CASE|WHEN|THEN|ELSE|END|COMMENT|UNSIGNED|AUTO_INCREMENT';
	var KEYWORD_RE = new RegExp('^(' + SQL_KEYWORDS + ')$', 'i');

	var D = {
		root: null,
		loaded: false,
		loading: '',
		tables: [],
		server: '',
		database: '',
		version: '',
		safeMode: true,
		allowDrop: false,
		history: [],
		table: '',
		tab: 'structure',
		message: null,
		navFilter: '',
		dbSelected: {},
		browse: null,
		structure: null,
		structureSelected: {},
		sql: { text: '', result: null, error: '', ranQuery: '' },
		search: { ops: {}, vals: {} },
		edit: null,
		colForm: null,
		opResult: null,
		exportFormat: 'sql',
		exportMethod: 'quick',
		exportCompression: ''
	};

	function freshBrowse() {
		return { page: 1, perPage: 25, showAll: false, sort: '', dir: 'ASC', filters: [], rows: [], total: 0, columns: [], keys: [], sql: '', time: 0, fullText: false, selected: {}, filterText: '', loaded: false, editing: null };
	}
	D.browse = freshBrowse();

	/* ----------------------------------------------------------------- */

	function esc(v) {
		return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function num(n) {
		return (Number(n) || 0).toLocaleString();
	}

	function bytes(n) {
		n = Number(n) || 0;
		var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		var i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (i ? n.toFixed(1) : n) + ' ' + u[i];
	}

	function q(name) {
		return '`' + String(name).replace(/`/g, '``') + '`';
	}

	function sqlValue(v) {
		if (v === null || v === undefined) { return 'NULL'; }
		if (typeof v === 'number') { return String(v); }
		return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
	}

	function post(action, data, timeout) {
		return $.ajax({
			url: cfg().ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: timeout || 120000,
			data: $.extend({ action: action, nonce: cfg().nonce }, data || {})
		});
	}

	function errText(r, xhr) {
		if (r && r.data && r.data.message) { return r.data.message; }
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { return xhr.responseJSON.data.message; }
		if (xhr && xhr.status) { return 'Server returned HTTP ' + xhr.status + '.'; }
		return 'The request failed.';
	}

	// A request with the "Loading…" notice; resolves with r.data or rejects with a message.
	function call(action, data, label) {
		var d = $.Deferred();
		D.loading = label || 'Loading…';
		renderNotice();
		post(action, data).done(function (r) {
			D.loading = '';
			renderNotice();
			if (r && r.success) { d.resolve(r.data || {}); } else { d.reject(errText(r), r && r.data); }
		}).fail(function (xhr) {
			D.loading = '';
			renderNotice();
			d.reject(errText(null, xhr), xhr && xhr.responseJSON && xhr.responseJSON.data);
		});
		return d.promise();
	}

	function setMessage(type, html) {
		D.message = html ? { type: type, html: html } : null;
	}

	function isNumericType(type) {
		return NUMERIC.test(String(type || ''));
	}

	function isTextType(type) {
		return /(text|blob|json)/i.test(String(type || ''));
	}

	function isBinaryType(type) {
		return /(blob|binary)/i.test(String(type || ''));
	}

	function tableInfo(name) {
		for (var i = 0; i < D.tables.length; i++) { if (D.tables[i].name === name) { return D.tables[i]; } }
		return null;
	}

	/* ----------------------------------------------------------------- icons */

	var ICONS = {
		db: '<svg viewBox="0 0 16 16"><ellipse cx="8" cy="3.5" rx="6" ry="2.5" fill="#d3e4f5" stroke="#3a6ea5"/><path d="M2 3.5v9c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-9" fill="#d3e4f5" stroke="#3a6ea5"/><path d="M2 8c0 1.4 2.7 2.5 6 2.5S14 9.4 14 8" fill="none" stroke="#3a6ea5"/></svg>',
		table: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" fill="#fff" stroke="#6b7f99"/><rect x="1.5" y="2.5" width="13" height="3" fill="#9bb3d1" stroke="#6b7f99"/><path d="M1.5 9h13M6 5.5v8M10.5 5.5v8" stroke="#6b7f99"/></svg>',
		server: '<svg viewBox="0 0 16 16"><rect x="2" y="1.5" width="12" height="5.5" rx="1" fill="#e7e7e7" stroke="#555"/><rect x="2" y="9" width="12" height="5.5" rx="1" fill="#e7e7e7" stroke="#555"/><circle cx="4.5" cy="4.2" r=".9" fill="#2e9e2e"/><circle cx="4.5" cy="11.7" r=".9" fill="#2e9e2e"/></svg>',
		browse: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" fill="#fff" stroke="#6b7f99"/><path d="M1.5 6h13M1.5 9.5h13" stroke="#6b7f99"/><path d="M4 4.2h3" stroke="#3a6ea5"/></svg>',
		props: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" fill="#fff" stroke="#6b7f99"/><path d="M4 5.5h8M4 8h8M4 10.5h5" stroke="#3a6ea5"/></svg>',
		sql: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2" width="13" height="12" rx="1" fill="#fffbe6" stroke="#b08900"/><text x="8" y="11" font-size="6" font-family="Arial" font-weight="bold" text-anchor="middle" fill="#8a6d00">SQL</text></svg>',
		search: '<svg viewBox="0 0 16 16"><circle cx="6.5" cy="6.5" r="4" fill="#e8f1fb" stroke="#3a6ea5" stroke-width="1.5"/><path d="M9.5 9.5l4.5 4.5" stroke="#555" stroke-width="2" stroke-linecap="round"/></svg>',
		insrow: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" fill="#fff" stroke="#6b7f99"/><path d="M1.5 6h13" stroke="#6b7f99"/><circle cx="11.5" cy="11" r="3.5" fill="#2e9e2e"/><path d="M11.5 9v4M9.5 11h4" stroke="#fff" stroke-width="1.4"/></svg>',
		export: '<svg viewBox="0 0 16 16"><rect x="2" y="1.5" width="9" height="12" fill="#fff" stroke="#6b7f99"/><path d="M9 9h6M12.5 6.5L15 9l-2.5 2.5" fill="none" stroke="#2e9e2e" stroke-width="1.6"/></svg>',
		tblops: '<svg viewBox="0 0 16 16"><path d="M10.5 1.5a3.5 3.5 0 0 0-3.3 4.6L2 11.3 4.7 14l5.2-5.2a3.5 3.5 0 0 0 4.6-3.3l-2 2-2.3-.7-.7-2.3z" fill="#c9c9c9" stroke="#555"/></svg>',
		edit: '<svg viewBox="0 0 16 16"><path d="M2 14l1-4 8-8 3 3-8 8z" fill="#f5c542" stroke="#8a6d00"/><path d="M2 14l1-4 3 3z" fill="#f7e2b0"/></svg>',
		copy: '<svg viewBox="0 0 16 16"><rect x="4.5" y="4.5" width="9" height="10" fill="#fff" stroke="#6b7f99"/><rect x="2.5" y="1.5" width="9" height="10" fill="#eef3f9" stroke="#6b7f99"/></svg>',
		drop: '<svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5" fill="#d9302c"/><path d="M5.3 5.3l5.4 5.4M10.7 5.3l-5.4 5.4" stroke="#fff" stroke-width="1.8"/></svg>',
		empty: '<svg viewBox="0 0 16 16"><rect x="1.5" y="2.5" width="13" height="11" fill="#fff" stroke="#6b7f99"/><path d="M5 6l6 6M11 6l-6 6" stroke="#d9302c" stroke-width="1.5"/></svg>',
		home: '<svg viewBox="0 0 16 16"><path d="M8 2L1.5 8H4v6h3v-4h2v4h3V8h2.5z" fill="#e8b33b" stroke="#8a6d00"/></svg>',
		reload: '<svg viewBox="0 0 16 16"><path d="M13 8a5 5 0 1 1-1.5-3.6" fill="none" stroke="#2e7d32" stroke-width="1.8"/><path d="M13.5 1.5v4h-4z" fill="#2e7d32"/></svg>',
		key: '<svg viewBox="0 0 16 16"><circle cx="5" cy="8" r="3" fill="#f5c542" stroke="#8a6d00"/><path d="M8 8h6.5M12 8v2.5M14 8v2" stroke="#8a6d00" stroke-width="1.5"/></svg>',
		success: '<svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#58a22d"/><path d="M4.5 8.2l2.3 2.3 4.7-4.8" fill="none" stroke="#fff" stroke-width="1.8"/></svg>',
		error: '<svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#d9302c"/><path d="M8 4v5" stroke="#fff" stroke-width="2"/><circle cx="8" cy="11.7" r="1.1" fill="#fff"/></svg>',
		notice: '<svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="#3a6ea5"/><circle cx="8" cy="4.6" r="1.1" fill="#fff"/><path d="M8 7v5" stroke="#fff" stroke-width="2"/></svg>',
		print: '<svg viewBox="0 0 16 16"><rect x="4" y="1.5" width="8" height="5" fill="#fff" stroke="#555"/><rect x="1.5" y="6" width="13" height="6" rx="1" fill="#c9c9c9" stroke="#555"/><rect x="4" y="10" width="8" height="4.5" fill="#fff" stroke="#555"/></svg>',
		clipboard: '<svg viewBox="0 0 16 16"><rect x="2.5" y="2.5" width="11" height="12" rx="1" fill="#e8b33b" stroke="#8a6d00"/><rect x="4.5" y="4.5" width="7" height="8" fill="#fff"/><rect x="5.5" y="1.5" width="5" height="2.5" rx=".8" fill="#ddd" stroke="#555"/></svg>',
		first: '<svg viewBox="0 0 16 16"><path d="M4 3v10M13 3L7 8l6 5z" fill="#555" stroke="#555"/></svg>',
		prev: '<svg viewBox="0 0 16 16"><path d="M11 3L5 8l6 5z" fill="#555"/></svg>',
		next: '<svg viewBox="0 0 16 16"><path d="M5 3l6 5-6 5z" fill="#555"/></svg>',
		last: '<svg viewBox="0 0 16 16"><path d="M12 3v10M3 3l6 5-6 5z" fill="#555" stroke="#555"/></svg>',
		arrowUp: '<svg viewBox="0 0 16 16"><path d="M3 13V5h8V2l4 4.5L11 11V8H6v5z" fill="#555"/></svg>',
		shield: '<svg viewBox="0 0 16 16"><path d="M8 1.5l5.5 2v4.2c0 3.3-2.3 5.8-5.5 6.8-3.2-1-5.5-3.5-5.5-6.8V3.5z" fill="#58a22d" stroke="#2e6b14"/></svg>'
	};

	function icon(name, cls) {
		return '<span class="pma-icon' + (cls ? ' ' + cls : '') + '" aria-hidden="true">' + (ICONS[name] || '') + '</span>';
	}

	/* ----------------------------------------------------------------- SQL display */

	function highlightSql(sql) {
		var out = '';
		var re = /(`(?:[^`]|``)*`)|('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")|(\b\d+(?:\.\d+)?\b)|([A-Za-z_][A-Za-z_0-9]*)|(\s+)|([\s\S])/g;
		var m;
		while ((m = re.exec(String(sql || ''))) !== null) {
			if (m[1]) { out += '<span class="pma-sql-ident">' + esc(m[1]) + '</span>'; }
			else if (m[2]) { out += '<span class="pma-sql-string">' + esc(m[2]) + '</span>'; }
			else if (m[3]) { out += '<span class="pma-sql-number">' + esc(m[3]) + '</span>'; }
			else if (m[4]) { out += KEYWORD_RE.test(m[4]) ? '<span class="pma-sql-keyword">' + esc(m[4].toUpperCase()) + '</span>' : esc(m[4]); }
			else { out += esc(m[0]); }
		}
		return out;
	}

	function formatSql(sql) {
		return String(sql || '').replace(/\s+/g, ' ').trim()
			.replace(/\s+(FROM|WHERE|ORDER BY|GROUP BY|HAVING|LIMIT|LEFT JOIN|RIGHT JOIN|INNER JOIN|JOIN|VALUES|SET|UNION)\b/gi, '\n$1')
			.replace(/\s+(AND|OR)\s+/gi, '\n    $1 ');
	}

	function sqlBox(sql, tools) {
		return '<div class="pma-sqlquery"><code class="pma-sql">' + highlightSql(sql) + '</code>'
			+ (tools === false ? '' : '<div class="pma-sqlquery-tools">[ <a href="#" data-pma="sql-edit" data-sql="' + esc(sql) + '">Edit</a> ] [ <a href="#" data-pma="sql-explain" data-sql="' + esc(sql) + '">Explain SQL</a> ] [ <a href="#" data-pma="refresh">Refresh</a> ]</div>')
			+ '</div>';
	}

	function messageBox(type, html) {
		return '<div class="pma-message pma-' + type + '">' + icon(type === 'success' ? 'success' : (type === 'error' ? 'error' : 'notice')) + '<div>' + html + '</div></div>';
	}

	/* ----------------------------------------------------------------- layout */

	function render() {
		if (!D.root || !document.body.contains(D.root)) { return; }
		var scrollTop = $(D.root).find('.pma-content').scrollTop();
		var html = '<div class="wudt-pma">' + renderNav() + '<div class="pma-main">' + renderServerInfo() + renderTabs()
			+ '<div class="pma-content">';
		if (D.message) { html += messageBox(D.message.type, D.message.html); }
		html += renderPage() + '</div></div><div class="pma-ajax" ' + (D.loading ? '' : 'hidden') + '>' + esc(D.loading) + '</div></div>';
		D.root.innerHTML = html;
		if (scrollTop) { $(D.root).find('.pma-content').scrollTop(scrollTop); }
	}

	function renderNotice() {
		var $n = $(D.root).find('.pma-ajax');
		if (!$n.length) { return; }
		$n.text(D.loading || '');
		if (D.loading) { $n.removeAttr('hidden'); } else { $n.attr('hidden', 'hidden'); }
	}

	function renderNav() {
		var f = String(D.navFilter || '').toLowerCase();
		var h = '<aside class="pma-nav"><div class="pma-nav-brand">' + icon('db') + '<span>Database&nbsp;manager</span></div>'
			+ '<div class="pma-nav-icons"><a href="#" data-pma="go-db" title="Database overview">' + icon('home') + '</a>'
			+ '<a href="#" data-pma="reload" title="Reload navigation panel">' + icon('reload') + '</a></div>'
			+ '<div class="pma-nav-tree"><div class="pma-tree-db' + (D.table ? '' : ' is-active') + '"><span class="pma-tree-toggle">−</span><a href="#" data-pma="go-db">' + icon('db') + esc(D.database || 'database') + '</a></div>'
			+ '<div class="pma-tree-filter"><input type="search" id="pma-nav-filter" placeholder="Type to filter these, Enter to search all" value="' + esc(D.navFilter) + '"></div><ul class="pma-tree-tables">';
		D.tables.forEach(function (t) {
			var hidden = f && t.name.toLowerCase().indexOf(f) === -1;
			h += '<li class="' + (D.table === t.name ? 'is-active' : '') + '" data-pma-name="' + esc(t.name.toLowerCase()) + '"' + (hidden ? ' hidden' : '') + '>'
				+ '<a href="#" data-pma="open-structure" data-table="' + esc(t.name) + '" title="Structure">' + icon('props', 'pma-icon-sm') + '</a>'
				+ '<a href="#" data-pma="open-table" data-table="' + esc(t.name) + '" title="' + esc(t.name + ' — ' + num(t.rows) + ' rows') + '">' + esc(t.name) + '</a></li>';
		});
		return h + '</ul></div></aside>';
	}

	function renderServerInfo() {
		var h = '<div class="pma-serverinfo">' + icon('server') + '<a href="#" data-pma="go-db">Server: ' + esc(D.server || 'localhost') + '</a>'
			+ '<span class="pma-sep">»</span>' + icon('db') + '<a href="#" data-pma="go-db">Database: ' + esc(D.database) + '</a>';
		if (D.table) {
			h += '<span class="pma-sep">»</span>' + icon('table') + '<a href="#" data-pma="tab" data-tab="browse">Table: ' + esc(D.table) + '</a>';
		}
		h += '<label class="pma-safe" title="Blocks deleting rows, emptying tables and DROP/TRUNCATE/ALTER statements">'
			+ '<input type="checkbox" id="pma-safe-mode"' + (D.safeMode ? ' checked' : '') + '> ' + icon('shield') + 'Safe mode</label></div>';
		return h;
	}

	function renderTabs() {
		var tabs = D.table ? TABLE_TABS : DB_TABS;
		var h = '<ul class="pma-topmenu">';
		tabs.forEach(function (t) {
			var active = D.tab === t[0] || (t[0] === 'insert' && D.tab === 'edit' && D.edit && D.edit.mode !== 'edit');
			h += '<li><a href="#" class="' + (active ? 'is-active' : '') + '" data-pma="tab" data-tab="' + t[0] + '">' + icon(t[2]) + esc(t[1]) + '</a></li>';
		});
		return h + '</ul>';
	}

	function renderPage() {
		if (!D.loaded) { return '<p class="pma-muted">Loading…</p>'; }
		if (!D.table) {
			return D.tab === 'sql' ? renderSql() : renderDbStructure();
		}
		switch (D.tab) {
			case 'browse': return renderBrowse();
			case 'structure': return renderStructure();
			case 'sql': return renderSql();
			case 'search': return renderSearch();
			case 'insert': return renderEditForm({ mode: 'insert', rows: [null] });
			case 'edit': return renderEditForm(D.edit);
			case 'export': return renderExport();
			case 'operations': return renderOperations();
			case 'column': return renderColumnForm();
		}
		return '';
	}

	/* ----------------------------------------------------------------- database overview */

	function renderDbStructure() {
		var sum = { rows: 0, size: 0, overhead: 0 };
		var h = '<form class="pma-form" onsubmit="return false"><table class="pma-table pma-data pma-db-tables"><thead><tr><th></th><th>Table</th><th colspan="6">Action</th><th>Rows</th><th>Type</th><th>Collation</th><th>Size</th><th>Overhead</th></tr></thead><tbody>';
		D.tables.forEach(function (t) {
			sum.rows += Number(t.rows) || 0;
			sum.size += Number(t.size) || 0;
			sum.overhead += Number(t.overhead) || 0;
			var checked = !!D.dbSelected[t.name];
			h += '<tr class="' + (checked ? 'is-marked' : '') + '"><td class="pma-cb"><input type="checkbox" data-pma-dbsel="' + esc(t.name) + '"' + (checked ? ' checked' : '') + '></td>'
				+ '<th class="pma-row-head"><a href="#" data-pma="open-table" data-table="' + esc(t.name) + '">' + esc(t.name) + '</a></th>'
				+ '<td class="pma-act"><a href="#" data-pma="open-table" data-table="' + esc(t.name) + '">' + icon('browse') + 'Browse</a></td>'
				+ '<td class="pma-act"><a href="#" data-pma="open-structure" data-table="' + esc(t.name) + '">' + icon('props') + 'Structure</a></td>'
				+ '<td class="pma-act"><a href="#" data-pma="open-tab" data-tab="search" data-table="' + esc(t.name) + '">' + icon('search') + 'Search</a></td>'
				+ '<td class="pma-act"><a href="#" data-pma="open-tab" data-tab="insert" data-table="' + esc(t.name) + '">' + icon('insrow') + 'Insert</a></td>'
				+ '<td class="pma-act"><a href="#" class="pma-danger" data-pma="table-op" data-op="empty" data-table="' + esc(t.name) + '">' + icon('empty') + 'Empty</a></td>'
				+ '<td class="pma-act">' + (D.allowDrop ? '<a href="#" class="pma-danger" data-pma="table-op" data-op="drop" data-table="' + esc(t.name) + '">' + icon('drop') + 'Drop</a>' : '<span class="pma-disabled" title="DROP TABLE is disabled in the plugin settings">' + icon('drop') + 'Drop</span>') + '</td>'
				+ '<td class="pma-num">' + esc(num(t.rows)) + '</td><td>' + esc(t.engine) + '</td><td>' + esc(t.collation) + '</td>'
				+ '<td class="pma-num">' + esc(bytes(t.size)) + '</td><td class="pma-num">' + (t.overhead ? esc(bytes(t.overhead)) : '-') + '</td></tr>';
		});
		h += '</tbody><tfoot><tr><th></th><th>' + esc(D.tables.length) + ' tables</th><th colspan="6">Sum</th><th class="pma-num">' + esc(num(sum.rows)) + '</th><th></th><th></th>'
			+ '<th class="pma-num">' + esc(bytes(sum.size)) + '</th><th class="pma-num">' + (sum.overhead ? esc(bytes(sum.overhead)) : '0 B') + '</th></tr></tfoot></table>';
		h += '<div class="pma-with-selected">' + icon('arrowUp') + '<label><input type="checkbox" id="pma-db-checkall"' + (D.tables.length && Object.keys(D.dbSelected).length === D.tables.length ? ' checked' : '') + '> Check all</label>'
			+ '<a href="#" data-pma="db-check-overhead">Check tables having overhead</a>'
			+ '<select id="pma-db-with"><option value="">With selected:</option><option value="check">Check table</option><option value="optimize">Optimize table</option><option value="repair">Repair table</option><option value="analyze">Analyze table</option><option value="empty">Empty</option>' + (D.allowDrop ? '<option value="drop">Drop</option>' : '') + '</select></div></form>';
		if (D.opResult) { h += renderOpResult(); }
		return h;
	}

	/* ----------------------------------------------------------------- browse */

	function pageCount() {
		var b = D.browse;
		return b.showAll ? 1 : Math.max(1, Math.ceil(b.total / b.perPage));
	}

	function pageOptions(cur, last) {
		var pages = {};
		var add = function (p) { if (p >= 1 && p <= last) { pages[p] = true; } };
		if (last <= 200) {
			for (var i = 1; i <= last; i++) { add(i); }
		} else {
			for (var a = 1; a <= 5; a++) { add(a); add(last - a + 1); }
			for (var c = cur - 10; c <= cur + 10; c++) { add(c); }
			var step = Math.ceil(last / 50);
			for (var s = step; s < last; s += step) { add(s); }
		}
		return Object.keys(pages).map(Number).sort(function (x, y) { return x - y; });
	}

	function renderNavBar(position) {
		var b = D.browse;
		var last = pageCount();
		var h = '<div class="pma-navbar">';
		if (!b.showAll && last > 1) {
			h += '<button type="button" class="pma-btn pma-btn-icon" data-pma="page" data-page="1" title="Begin"' + (b.page <= 1 ? ' disabled' : '') + '>' + icon('first') + '</button>'
				+ '<button type="button" class="pma-btn pma-btn-icon" data-pma="page" data-page="' + (b.page - 1) + '" title="Previous"' + (b.page <= 1 ? ' disabled' : '') + '>' + icon('prev') + '</button>'
				+ '<select class="pma-page-select" data-pma-page>' + pageOptions(b.page, last).map(function (p) {
					return '<option value="' + p + '"' + (p === b.page ? ' selected' : '') + '>' + p + '</option>';
				}).join('') + '</select>'
				+ '<button type="button" class="pma-btn pma-btn-icon" data-pma="page" data-page="' + (b.page + 1) + '" title="Next"' + (b.page >= last ? ' disabled' : '') + '>' + icon('next') + '</button>'
				+ '<button type="button" class="pma-btn pma-btn-icon" data-pma="page" data-page="' + last + '" title="End"' + (b.page >= last ? ' disabled' : '') + '>' + icon('last') + '</button><span class="pma-navbar-sep"></span>';
		}
		h += '<label><input type="checkbox" data-pma-showall' + (b.showAll ? ' checked' : '') + '> Show all</label><span class="pma-navbar-sep"></span>'
			+ '<label>Number of rows: <select data-pma-perpage' + (b.showAll ? ' disabled' : '') + '>' + ROWS_OPTIONS.map(function (n) {
				return '<option value="' + n + '"' + (n === b.perPage ? ' selected' : '') + '>' + n + '</option>';
			}).join('') + '</select></label>';
		if (position === 'top') {
			h += '<span class="pma-navbar-sep"></span><label>Filter rows: <input type="search" id="pma-filter-rows" placeholder="Search this table" value="' + esc(b.filterText) + '"></label>';
			if (b.keys.length) {
				var cur = b.sort === b.keys[0] ? b.dir : '';
				h += '<span class="pma-navbar-sep"></span><label>Sort by key: <select data-pma-sortkey><option value="">None</option>'
					+ '<option value="ASC"' + (cur === 'ASC' ? ' selected' : '') + '>PRIMARY (ASC)</option><option value="DESC"' + (cur === 'DESC' ? ' selected' : '') + '>PRIMARY (DESC)</option></select></label>';
			}
		}
		return h + '</div>';
	}

	function cellHtml(value, col) {
		if (value === null || value === undefined) { return '<i class="pma-null">NULL</i>'; }
		var type = col ? col.Type : '';
		var text = String(value);
		if (isBinaryType(type) && /[\x00-\x08\x0e-\x1f]/.test(text)) {
			return '<span class="pma-blob">[BLOB - ' + esc(bytes(text.length)) + ']</span>';
		}
		if (!D.browse.fullText && text.length > TEXT_LIMIT) {
			return esc(text.substr(0, TEXT_LIMIT)) + '...';
		}
		return esc(text);
	}

	function rowWhere(row) {
		var keys = D.browse.keys;
		if (!keys.length) { return null; }
		var where = {};
		for (var i = 0; i < keys.length; i++) {
			if (row[keys[i]] === undefined) { return null; }
			where[keys[i]] = row[keys[i]];
		}
		return where;
	}

	function whereSql(where) {
		return Object.keys(where).map(function (k) { return q(D.table) + '.' + q(k) + ' = ' + sqlValue(where[k]); }).join(' AND ');
	}

	function renderBrowse() {
		var b = D.browse;
		if (!b.loaded) { return '<p class="pma-muted">Loading…</p>'; }
		var h = '';
		var first = b.rows.length ? (b.showAll ? 0 : (b.page - 1) * b.perPage) : 0;
		if (b.rows.length) {
			h += messageBox('success', 'Showing rows ' + esc(num(first)) + ' - ' + esc(num(first + b.rows.length - 1)) + ' (' + esc(num(b.total)) + ' total' + (b.showAll && b.total > b.rows.length ? ', only the first ' + esc(num(b.rows.length)) + ' are shown' : '') + ', Query took ' + Number(b.time).toFixed(4) + ' seconds.)'
				+ (b.filters.length ? ' <a href="#" data-pma="tab" data-tab="search">Modify search criteria</a> · <a href="#" data-pma="clear-filters">Show all rows</a>' : ''));
		} else {
			h += messageBox('success', 'MySQL returned an empty result set (i.e. zero rows). (Query took ' + Number(b.time).toFixed(4) + ' seconds.)'
				+ (b.filters.length ? ' <a href="#" data-pma="tab" data-tab="search">Modify search criteria</a> · <a href="#" data-pma="clear-filters">Show all rows</a>' : ''));
		}
		h += sqlBox(b.sql);
		if (!b.rows.length) { return h; }
		if (!b.keys.length) {
			h += messageBox('notice', 'This table does not contain a unique column. Grid edit, checkbox, Edit, Copy and Delete features are not available.');
		}
		var canEdit = b.keys.length > 0;
		h += renderNavBar('top');
		h += '<div class="pma-table-wrap"><table class="pma-table pma-data pma-results"><thead><tr>';
		h += '<th colspan="' + (canEdit ? 4 : 1) + '" class="pma-th-full"><a href="#" data-pma="fulltext" title="' + (b.fullText ? 'Partial texts' : 'Full texts') + '">' + (b.fullText ? '→T←' : '←T→') + '</a></th>';
		b.columns.forEach(function (col) {
			var name = col.Field;
			var arrow = b.sort === name ? (b.dir === 'ASC' ? ' <span class="pma-sort">▲</span>' : ' <span class="pma-sort">▼</span>') : '';
			h += '<th class="pma-col-head' + (isNumericType(col.Type) ? ' pma-num' : '') + '" title="' + esc(col.Type + (col.Comment ? ' — ' + col.Comment : '')) + '"><a href="#" data-pma="sort" data-col="' + esc(name) + '">' + esc(name) + '</a>' + arrow + '</th>';
		});
		h += '</tr></thead><tbody>';
		var ft = String(b.filterText || '').toLowerCase();
		b.rows.forEach(function (row, i) {
			var marked = !!b.selected[i];
			var hide = ft && !b.columns.some(function (col) { return String(row[col.Field] == null ? '' : row[col.Field]).toLowerCase().indexOf(ft) !== -1; });
			h += '<tr class="' + (marked ? 'is-marked' : '') + '" data-row="' + i + '"' + (hide ? ' hidden' : '') + '>';
			if (canEdit) {
				h += '<td class="pma-cb"><input type="checkbox" data-pma-row="' + i + '"' + (marked ? ' checked' : '') + '></td>'
					+ '<td class="pma-act"><a href="#" data-pma="row-edit" data-row="' + i + '">' + icon('edit') + 'Edit</a></td>'
					+ '<td class="pma-act"><a href="#" data-pma="row-copy" data-row="' + i + '">' + icon('copy') + 'Copy</a></td>'
					+ '<td class="pma-act"><a href="#" class="pma-danger" data-pma="row-delete" data-row="' + i + '">' + icon('drop') + 'Delete</a></td>';
			} else {
				h += '<td></td>';
			}
			b.columns.forEach(function (col) {
				var editing = b.editing && b.editing.row === i && b.editing.col === col.Field;
				var value = row[col.Field];
				h += '<td class="pma-cell' + (isNumericType(col.Type) ? ' pma-num' : '') + (value === null ? ' pma-cell-null' : '') + (canEdit ? ' pma-grid-edit' : '') + '" data-col="' + esc(col.Field) + '">'
					+ (editing ? renderCellEditor(value, col) : cellHtml(value, col)) + '</td>';
			});
			h += '</tr>';
		});
		h += '</tbody></table></div>';
		if (canEdit) {
			var count = Object.keys(b.selected).length;
			h += '<div class="pma-with-selected">' + icon('arrowUp') + '<label><input type="checkbox" id="pma-checkall"' + (count && count === b.rows.length ? ' checked' : '') + '> Check all</label>'
				+ '<span class="pma-with-label">With selected:</span>'
				+ '<button type="button" class="pma-btn" data-pma="sel-edit"' + (count ? '' : ' disabled') + '>' + icon('edit') + 'Edit</button>'
				+ '<button type="button" class="pma-btn" data-pma="sel-copy"' + (count ? '' : ' disabled') + '>' + icon('copy') + 'Copy</button>'
				+ '<button type="button" class="pma-btn" data-pma="sel-delete"' + (count ? '' : ' disabled') + '>' + icon('drop') + 'Delete</button>'
				+ '<button type="button" class="pma-btn" data-pma="sel-export"' + (count ? '' : ' disabled') + '>' + icon('export') + 'Export</button></div>';
		}
		h += renderNavBar('bottom');
		h += '<fieldset class="pma-fieldset"><legend>Query results operations</legend><div class="pma-ops-links">'
			+ '<a href="#" data-pma="print">' + icon('print') + 'Print</a>'
			+ '<a href="#" data-pma="copy-results">' + icon('clipboard') + 'Copy to clipboard</a>'
			+ '<a href="#" data-pma="tab" data-tab="export">' + icon('export') + 'Export</a></div></fieldset>';
		return h;
	}

	function renderCellEditor(value, col) {
		var v = value === null || value === undefined ? '' : String(value);
		var big = isTextType(col.Type) || v.length > 60 || v.indexOf('\n') !== -1;
		return '<div class="pma-cell-editor">'
			+ (big ? '<textarea id="pma-cell-input" rows="4">' + esc(v) + '</textarea>' : '<input type="text" id="pma-cell-input" value="' + esc(v) + '">')
			+ '<div class="pma-cell-editor-tools">' + (/YES/i.test(col.Null) ? '<label><input type="checkbox" id="pma-cell-null"' + (value === null ? ' checked' : '') + '> Null</label>' : '')
			+ '<button type="button" class="pma-btn" data-pma="cell-save">Save</button><a href="#" data-pma="cell-cancel">Cancel</a></div></div>';
	}

	/* ----------------------------------------------------------------- insert / edit */

	function inputFor(col, value, idx) {
		var name = 'pma-f-' + idx + '-' + col.Field;
		var v = value === null || value === undefined ? '' : String(value);
		var enumMatch = /^(enum|set)\((.*)\)$/i.exec(String(col.Type));
		if (enumMatch && enumMatch[1].toLowerCase() === 'enum') {
			var opts = [];
			enumMatch[2].replace(/'((?:[^']|'')*)'/g, function (_, o) { opts.push(o.replace(/''/g, "'")); });
			return '<select data-pma-field="' + esc(col.Field) + '" data-pma-form="' + idx + '"><option value=""></option>' + opts.map(function (o) {
				return '<option value="' + esc(o) + '"' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>';
			}).join('') + '</select>';
		}
		if (isTextType(col.Type) || v.length > 80 || v.indexOf('\n') !== -1) {
			return '<textarea data-pma-field="' + esc(col.Field) + '" data-pma-form="' + idx + '" id="' + esc(name) + '" rows="' + (v.length > 300 ? 10 : 5) + '">' + esc(v) + '</textarea>';
		}
		return '<input type="text" data-pma-field="' + esc(col.Field) + '" data-pma-form="' + idx + '" id="' + esc(name) + '" value="' + esc(v) + '">';
	}

	function renderEditForm(edit) {
		var cols = D.browse.columns.length ? D.browse.columns : ((D.structure && D.structure.cols) || []);
		if (!cols.length) { return '<p class="pma-muted">Loading…</p>'; }
		var h = '<form class="pma-form" id="pma-edit-form" onsubmit="return false">';
		edit.rows.forEach(function (row, idx) {
			var isNew = edit.mode !== 'edit';
			h += '<table class="pma-table pma-insert-table" data-pma-form-index="' + idx + '"><thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Value</th></tr></thead><tbody>';
			cols.forEach(function (col) {
				var auto = /auto_increment/i.test(col.Extra || '');
				var value = row ? row[col.Field] : (col.Default !== undefined ? col.Default : null);
				if (isNew && auto) { value = ''; }
				var nullable = /YES/i.test(col.Null);
				var isNull = nullable && (value === null || value === undefined) && !(isNew && auto);
				h += '<tr><td class="pma-field-name"><span title="' + esc(col.Comment || '') + '">' + esc(col.Field) + '</span>' + (auto ? ' <small>(auto)</small>' : '') + '</td>'
					+ '<td class="pma-field-type">' + esc(col.Type) + '</td>'
					+ '<td class="pma-cb">' + (nullable ? '<input type="checkbox" data-pma-null="' + esc(col.Field) + '" data-pma-form="' + idx + '"' + (isNull ? ' checked' : '') + '>' : '') + '</td>'
					+ '<td class="pma-field-value">' + inputFor(col, isNull ? '' : value, idx) + '</td></tr>';
			});
			h += '</tbody></table>';
			if (idx < edit.rows.length - 1) { h += '<div class="pma-form-gap"></div>'; }
		});
		h += '<fieldset class="pma-fieldset pma-tbl-footer"><div class="pma-footer-row">'
			+ (edit.mode === 'edit'
				? '<label>Save <select id="pma-save-as"><option value="save">Save</option><option value="new">Insert as new row</option></select></label>'
				: '<span class="pma-muted">' + (edit.rows.length > 1 ? edit.rows.length + ' new rows' : 'Insert as new row') + '</span>')
			+ '<label>and then <select id="pma-after"><option value="back">Go back to previous page</option><option value="another">Insert another new row</option></select></label>'
			+ '<button type="button" class="pma-btn pma-btn-go" data-pma="form-go">Go</button>'
			+ '<button type="button" class="pma-btn" data-pma="form-reset">Reset</button></div></fieldset></form>';
		return h;
	}

	function collectForm(idx) {
		var data = {};
		$(D.root).find('[data-pma-field][data-pma-form="' + idx + '"]').each(function () {
			data[$(this).attr('data-pma-field')] = $(this).val();
		});
		$(D.root).find('[data-pma-null][data-pma-form="' + idx + '"]').each(function () {
			if (this.checked) { data[$(this).attr('data-pma-null')] = null; }
		});
		return data;
	}

	function submitForm() {
		var edit = D.tab === 'edit' ? D.edit : { mode: 'insert', rows: [null] };
		var cols = D.browse.columns.length ? D.browse.columns : ((D.structure && D.structure.cols) || []);
		var saveAs = $('#pma-save-as').val() || 'save';
		var after = $('#pma-after').val() || 'back';
		var jobs = [];
		edit.rows.forEach(function (row, idx) {
			var data = collectForm(idx);
			cols.forEach(function (col) {
				// An empty auto-increment column lets MySQL pick the next value.
				if (/auto_increment/i.test(col.Extra || '') && data[col.Field] === '' && (edit.mode !== 'edit' || saveAs === 'new')) { delete data[col.Field]; }
			});
			if (edit.mode === 'edit' && saveAs === 'save') {
				var where = rowWhere(row);
				var changed = {};
				Object.keys(data).forEach(function (k) {
					var before = row[k] === undefined ? null : row[k];
					if (data[k] !== before && !(data[k] === '' && before === '')) { changed[k] = data[k]; }
				});
				if (Object.keys(changed).length) {
					jobs.push({ action: 'diagnostics_db_update', data: { table: D.table, data: JSON.stringify(changed), where: JSON.stringify(where) }, sql: 'UPDATE ' + q(D.table) + ' SET ' + Object.keys(changed).map(function (k) { return q(k) + ' = ' + sqlValue(changed[k]); }).join(', ') + ' WHERE ' + whereSql(where) });
				}
			} else {
				jobs.push({ action: 'diagnostics_db_insert', data: { table: D.table, data: JSON.stringify(data) }, sql: 'INSERT INTO ' + q(D.table) + ' (' + Object.keys(data).map(q).join(', ') + ') VALUES (' + Object.keys(data).map(function (k) { return sqlValue(data[k]); }).join(', ') + ')' });
			}
		});
		if (!jobs.length) {
			setMessage('notice', 'No change: nothing was edited.');
			goBrowse();
			return;
		}
		var done = 0;
		var affected = 0;
		var lastId = 0;
		var sqls = [];
		var next = function () {
			if (done >= jobs.length) {
				var inserted = jobs[0].action === 'diagnostics_db_insert';
				var msg = inserted
					? esc(jobs.length) + ' row' + (jobs.length === 1 ? '' : 's') + ' inserted.' + (lastId ? ' Inserted row id: ' + esc(lastId) : '')
					: esc(affected) + ' row' + (affected === 1 ? '' : 's') + ' affected.';
				setMessage('success', msg + sqlBox(sqls.join(';\n'), false));
				if (after === 'another') {
					D.edit = { mode: 'insert', rows: [null] };
					D.tab = 'insert';
					render();
				} else {
					goBrowse(true);
				}
				loadTables(false);
				return;
			}
			var job = jobs[done];
			call(job.action, job.data, 'Saving…').done(function (r) {
				affected += Number(r.affected || 0);
				lastId = r.insert_id || lastId;
				sqls.push(job.sql);
				done++;
				next();
			}).fail(function (msg) {
				setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + sqlBox(job.sql, false));
				render();
			});
		};
		next();
	}

	/* ----------------------------------------------------------------- structure */

	function splitType(type) {
		var t = String(type || '');
		var attrs = [];
		['unsigned zerofill', 'unsigned', 'zerofill'].forEach(function (a) {
			var re = new RegExp('\\s' + a + '\\b', 'i');
			if (re.test(t) && !attrs.length) { attrs.push(a.toUpperCase()); t = t.replace(re, ''); }
		});
		return { base: t.trim(), attrs: attrs.join(' ') };
	}

	function renderStructure() {
		var s = D.structure;
		if (!s) { return '<p class="pma-muted">Loading…</p>'; }
		var h = '<form class="pma-form" onsubmit="return false"><table class="pma-table pma-data pma-structure"><thead><tr><th></th><th>#</th><th>Name</th><th>Type</th><th>Collation</th><th>Attributes</th><th>Null</th><th>Default</th><th>Comments</th><th>Extra</th><th colspan="3">Action</th></tr></thead><tbody>';
		s.cols.forEach(function (c, i) {
			var t = splitType(c.Type);
			var marked = !!D.structureSelected[c.Field];
			var isPk = c.Key === 'PRI';
			var def = c.Default === null || c.Default === undefined ? (/YES/i.test(c.Null) ? '<i>NULL</i>' : '<i>None</i>') : esc(c.Default);
			h += '<tr class="' + (marked ? 'is-marked' : '') + '"><td class="pma-cb"><input type="checkbox" data-pma-colsel="' + esc(c.Field) + '"' + (marked ? ' checked' : '') + '></td>'
				+ '<td class="pma-num">' + (i + 1) + '</td>'
				+ '<th class="pma-row-head">' + (isPk ? '<u>' + esc(c.Field) + '</u> ' + icon('key', 'pma-icon-sm') : esc(c.Field)) + (c.Key === 'UNI' ? ' <small class="pma-muted">(unique)</small>' : (c.Key === 'MUL' ? ' <small class="pma-muted">(index)</small>' : '')) + '</th>'
				+ '<td><bdo dir="ltr">' + esc(t.base) + '</bdo></td><td>' + (c.Collation ? '<dfn title="' + esc(c.Collation) + '">' + esc(c.Collation) + '</dfn>' : '') + '</td>'
				+ '<td>' + esc(t.attrs) + '</td><td>' + (/YES/i.test(c.Null) ? 'Yes' : 'No') + '</td><td>' + def + '</td><td>' + esc(c.Comment || '') + '</td><td>' + esc(String(c.Extra || '').toUpperCase()) + '</td>'
				+ '<td class="pma-act"><a href="#" data-pma="col-change" data-col="' + esc(c.Field) + '">' + icon('edit') + 'Change</a></td>'
				+ '<td class="pma-act"><a href="#" class="pma-danger" data-pma="col-drop" data-col="' + esc(c.Field) + '">' + icon('drop') + 'Drop</a></td>'
				+ '<td class="pma-act"><a href="#" data-pma="col-browse" data-col="' + esc(c.Field) + '">' + icon('browse') + 'Browse distinct values</a></td></tr>';
		});
		h += '</tbody></table>';
		var selCount = Object.keys(D.structureSelected).length;
		h += '<div class="pma-with-selected">' + icon('arrowUp') + '<label><input type="checkbox" id="pma-col-checkall"' + (selCount && selCount === s.cols.length ? ' checked' : '') + '> Check all</label>'
			+ '<span class="pma-with-label">With selected:</span>'
			+ '<button type="button" class="pma-btn" data-pma="cols-browse"' + (selCount ? '' : ' disabled') + '>' + icon('browse') + 'Browse</button>'
			+ '<button type="button" class="pma-btn" data-pma="cols-drop"' + (selCount ? '' : ' disabled') + '>' + icon('drop') + 'Drop</button></div>';
		h += '<div class="pma-structure-add">' + icon('insrow') + 'Add <input type="number" id="pma-add-count" value="1" min="1" max="1" readonly> column '
			+ '<select id="pma-add-pos"><option value="end">at end of table</option><option value="first">at beginning of table</option>'
			+ s.cols.map(function (c) { return '<option value="after:' + esc(c.Field) + '">after ' + esc(c.Field) + '</option>'; }).join('')
			+ '</select> <button type="button" class="pma-btn pma-btn-go" data-pma="col-add">Go</button></div></form>';

		// Indexes.
		h += '<fieldset class="pma-fieldset"><legend>Indexes</legend>';
		var groups = {};
		var order = [];
		(s.indexes || []).forEach(function (ix) {
			if (!groups[ix.Key_name]) { groups[ix.Key_name] = []; order.push(ix.Key_name); }
			groups[ix.Key_name].push(ix);
		});
		if (!order.length) {
			h += messageBox('notice', 'No index defined!');
		} else {
			h += '<table class="pma-table pma-data pma-indexes"><thead><tr><th>Keyname</th><th>Type</th><th>Unique</th><th>Packed</th><th>Column</th><th>Cardinality</th><th>Collation</th><th>Null</th><th>Comment</th></tr></thead><tbody>';
			order.forEach(function (name) {
				groups[name].forEach(function (ix, k) {
					h += '<tr>' + (k === 0
						? '<th class="pma-row-head" rowspan="' + groups[name].length + '">' + esc(name) + '</th><td rowspan="' + groups[name].length + '">' + esc(ix.Index_type) + '</td>'
							+ '<td rowspan="' + groups[name].length + '">' + (String(ix.Non_unique) === '0' ? 'Yes' : 'No') + '</td><td rowspan="' + groups[name].length + '">' + (ix.Packed ? esc(ix.Packed) : 'No') + '</td>'
						: '')
						+ '<td>' + esc(ix.Column_name) + (ix.Sub_part ? ' (' + esc(ix.Sub_part) + ')' : '') + '</td><td class="pma-num">' + esc(ix.Cardinality == null ? '' : num(ix.Cardinality)) + '</td>'
						+ '<td>' + esc(ix.Collation || '') + '</td><td>' + (ix.Null === 'YES' ? 'Yes' : 'No') + '</td>' + (k === 0 ? '<td rowspan="' + groups[name].length + '">' + esc(ix.Index_comment || ix.Comment || '') + '</td>' : '') + '</tr>';
				});
			});
			h += '</tbody></table>';
		}
		h += '</fieldset>';

		// Information.
		var st = s.status || {};
		var data = Number(st.Data_length) || 0;
		var index = Number(st.Index_length) || 0;
		// Only MyISAM/Aria report real per-table overhead; InnoDB's Data_free is tablespace free space.
		var free = /^(myisam|aria)$/i.test(String(st.Engine || '')) ? (Number(st.Data_free) || 0) : 0;
		h += '<div class="pma-info-row"><fieldset class="pma-fieldset"><legend>Space usage</legend><table class="pma-table pma-data pma-kv">'
			+ '<tr><th>Data</th><td class="pma-num">' + esc(bytes(data)) + '</td></tr><tr><th>Index</th><td class="pma-num">' + esc(bytes(index)) + '</td></tr>'
			+ (free ? '<tr><th>Overhead</th><td class="pma-num">' + esc(bytes(free)) + '</td></tr>' : '')
			+ '<tr><th>Effective</th><td class="pma-num">' + esc(bytes(data + index - free)) + '</td></tr><tr><th>Total</th><td class="pma-num">' + esc(bytes(data + index)) + '</td></tr></table></fieldset>'
			+ '<fieldset class="pma-fieldset"><legend>Row statistics</legend><table class="pma-table pma-data pma-kv">'
			+ '<tr><th>Format</th><td>' + esc(String(st.Row_format || '').toLowerCase()) + '</td></tr><tr><th>Collation</th><td>' + esc(st.Collation || '') + '</td></tr>'
			+ '<tr><th>Rows</th><td class="pma-num">' + esc(num(st.Rows)) + '</td></tr><tr><th>Row length ø</th><td class="pma-num">' + esc(num(st.Avg_row_length)) + '</td></tr>'
			+ '<tr><th>Row size ø</th><td class="pma-num">' + esc(st.Rows ? bytes((data + index) / Number(st.Rows)) : '0 B') + '</td></tr>'
			+ (st.Auto_increment ? '<tr><th>Next autoindex</th><td class="pma-num">' + esc(num(st.Auto_increment)) + '</td></tr>' : '')
			+ (st.Create_time ? '<tr><th>Creation</th><td>' + esc(st.Create_time) + '</td></tr>' : '')
			+ (st.Update_time ? '<tr><th>Last update</th><td>' + esc(st.Update_time) + '</td></tr>' : '')
			+ '</table></fieldset></div>';
		return h;
	}

	function renderColumnForm() {
		var f = D.colForm;
		if (!f) { return ''; }
		var c = f.col || { Field: '', Type: 'varchar(255)', Null: 'NO', Default: null, Comment: '', Extra: '' };
		var h = '<form class="pma-form" onsubmit="return false"><table class="pma-table pma-insert-table pma-colform"><thead><tr><th>Name</th><th>Type</th><th>Default</th><th>Null</th><th>A_I</th><th>Comments</th></tr></thead><tbody><tr>'
			+ '<td><input type="text" id="pma-col-name" value="' + esc(c.Field) + '"></td>'
			+ '<td><input type="text" id="pma-col-type" value="' + esc(c.Type) + '" list="pma-types"><datalist id="pma-types">'
			+ ['int(11)', 'bigint(20) unsigned', 'tinyint(1)', 'varchar(255)', 'text', 'longtext', 'datetime', 'date', 'decimal(10,2)', 'float', 'json'].map(function (t) { return '<option value="' + t + '">'; }).join('')
			+ '</datalist></td>'
			+ '<td><select id="pma-col-default-kind"><option value="none"' + (c.Default === null && !/YES/i.test(c.Null) ? ' selected' : '') + '>None</option><option value="value"' + (c.Default !== null && c.Default !== undefined && c.Default !== 'CURRENT_TIMESTAMP' ? ' selected' : '') + '>As defined:</option>'
			+ '<option value="null"' + (c.Default === null && /YES/i.test(c.Null) ? ' selected' : '') + '>NULL</option><option value="ts"' + (c.Default === 'CURRENT_TIMESTAMP' ? ' selected' : '') + '>CURRENT_TIMESTAMP</option></select>'
			+ '<input type="text" id="pma-col-default" value="' + esc(c.Default === null || c.Default === 'CURRENT_TIMESTAMP' ? '' : c.Default) + '"></td>'
			+ '<td class="pma-cb"><input type="checkbox" id="pma-col-null"' + (/YES/i.test(c.Null) ? ' checked' : '') + '></td>'
			+ '<td class="pma-cb"><input type="checkbox" id="pma-col-ai"' + (/auto_increment/i.test(c.Extra || '') ? ' checked' : '') + '></td>'
			+ '<td><input type="text" id="pma-col-comment" value="' + esc(c.Comment || '') + '"></td></tr></tbody></table>'
			+ '<fieldset class="pma-fieldset pma-tbl-footer"><div class="pma-footer-row"><button type="button" class="pma-btn" data-pma="col-preview">Preview SQL</button>'
			+ '<button type="button" class="pma-btn pma-btn-go" data-pma="col-save">Save</button><a href="#" data-pma="tab" data-tab="structure">Cancel</a></div></fieldset></form>';
		return '<h2 class="pma-h2">' + (f.col ? 'Change column ' + esc(f.col.Field) : 'Add column') + '</h2>' + h;
	}

	function columnSql() {
		var f = D.colForm;
		var name = $.trim($('#pma-col-name').val());
		var type = $.trim($('#pma-col-type').val());
		if (!name || !type) { return ''; }
		var nullable = $('#pma-col-null').is(':checked');
		var kind = $('#pma-col-default-kind').val();
		var def = '';
		if (kind === 'value') { def = ' DEFAULT ' + sqlValue($('#pma-col-default').val()); }
		if (kind === 'null') { def = ' DEFAULT NULL'; }
		if (kind === 'ts') { def = ' DEFAULT CURRENT_TIMESTAMP'; }
		var comment = $.trim($('#pma-col-comment').val());
		var spec = q(name) + ' ' + type + (nullable ? ' NULL' : ' NOT NULL') + def + ($('#pma-col-ai').is(':checked') ? ' AUTO_INCREMENT' : '') + (comment ? ' COMMENT ' + sqlValue(comment) : '');
		if (f.col) {
			return 'ALTER TABLE ' + q(D.table) + ' CHANGE ' + q(f.col.Field) + ' ' + spec;
		}
		var pos = f.position || 'end';
		return 'ALTER TABLE ' + q(D.table) + ' ADD ' + spec + (pos === 'first' ? ' FIRST' : (pos.indexOf('after:') === 0 ? ' AFTER ' + q(pos.substr(6)) : ''));
	}

	/* ----------------------------------------------------------------- SQL */

	function renderSql() {
		var cols = D.table && D.browse.columns.length ? D.browse.columns : ((D.table && D.structure && D.structure.cols) || []);
		var target = D.table ? esc(D.database) + '.' + esc(D.table) : esc(D.database);
		var text = D.sql.text || (D.table ? 'SELECT * FROM ' + q(D.table) + ' WHERE 1' : '');
		var h = '<form class="pma-form" onsubmit="return false"><fieldset class="pma-fieldset pma-sql-fieldset"><legend>Run SQL query/queries on ' + (D.table ? 'table' : 'database') + ' ' + target + ':</legend>'
			+ '<div class="pma-sql-area"><textarea id="pma-sql-text" rows="12" spellcheck="false">' + esc(text) + '</textarea>';
		if (cols.length) {
			h += '<div class="pma-sql-columns"><label for="pma-sql-cols">Columns</label><select id="pma-sql-cols" multiple size="10">'
				+ cols.map(function (c) { return '<option value="' + esc(c.Field) + '">' + esc(c.Field) + '</option>'; }).join('')
				+ '</select><button type="button" class="pma-btn" data-pma="sql-insert-cols">&lt;&lt;</button></div>';
		}
		h += '</div><div class="pma-sql-buttons">';
		if (D.table) {
			['SELECT *', 'SELECT', 'INSERT', 'UPDATE', 'DELETE'].forEach(function (b) {
				h += '<button type="button" class="pma-btn" data-pma="sql-template" data-tpl="' + b + '">' + b + '</button>';
			});
		}
		h += '<button type="button" class="pma-btn" data-pma="sql-clear">Clear</button><button type="button" class="pma-btn" data-pma="sql-format">Format</button></div></fieldset>'
			+ '<fieldset class="pma-fieldset pma-tbl-footer"><div class="pma-footer-row"><span class="pma-muted">Ctrl+Enter runs the query</span><button type="button" class="pma-btn pma-btn-go" data-pma="sql-go">Go</button></div></fieldset></form>';
		h += renderSqlResult();
		if (D.history.length) {
			h += '<details class="pma-history"><summary>Query history (' + esc(D.history.length) + ')</summary><ul>'
				+ D.history.slice().reverse().map(function (x) {
					return '<li><a href="#" data-pma="history" data-sql="' + esc(x.query) + '"><code>' + esc(String(x.query).substr(0, 200)) + '</code></a><small>' + esc(x.time) + ' · ' + esc(x.time_ms) + ' ms' + (x.success === false ? ' · failed' : '') + '</small></li>';
				}).join('') + '</ul></details>';
		}
		return h;
	}

	function renderSqlResult() {
		var r = D.sql.result;
		if (D.sql.error) {
			return '<div class="pma-message pma-error">' + icon('error') + '<div><strong>Error</strong>' + sqlBox(D.sql.ranQuery, false) + '<strong>MySQL said:</strong> <code>' + esc(D.sql.error) + '</code></div></div>';
		}
		if (!r) { return ''; }
		var secs = (Number(r.time_ms) / 1000).toFixed(4);
		if (r.type !== 'select') {
			return messageBox('success', esc(num(r.affected)) + ' row' + (r.affected === 1 ? '' : 's') + ' affected.' + (r.insert_id ? ' Inserted row id: ' + esc(r.insert_id) : '') + ' (Query took ' + secs + ' seconds.)') + sqlBox(D.sql.ranQuery, false);
		}
		var rows = r.result || [];
		var h = messageBox('success', rows.length
			? 'Showing rows 0 - ' + esc(num(rows.length - 1)) + ' (' + esc(num(r.count)) + ' total' + (r.truncated ? ', only the first ' + esc(num(rows.length)) + ' are shown' : '') + ', Query took ' + secs + ' seconds.)'
			: 'MySQL returned an empty result set (i.e. zero rows). (Query took ' + secs + ' seconds.)') + sqlBox(D.sql.ranQuery, false);
		if (!rows.length) { return h; }
		var cols = r.columns && r.columns.length ? r.columns : Object.keys(rows[0]);
		h += '<div class="pma-table-wrap"><table class="pma-table pma-data pma-results"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>';
		rows.forEach(function (row) {
			h += '<tr>' + cols.map(function (c) {
				var v = row[c];
				return '<td' + (typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v) ? ' class="pma-num"' : '') + '>' + (v === null || v === undefined ? '<i class="pma-null">NULL</i>' : esc(String(v).length > 200 ? String(v).substr(0, 200) + '...' : v)) + '</td>';
			}).join('') + '</tr>';
		});
		return h + '</tbody></table></div>';
	}

	function runSql(sql) {
		sql = $.trim(sql || '');
		if (!sql) { return; }
		D.sql.text = sql;
		D.sql.ranQuery = sql;
		D.sql.error = '';
		D.sql.result = null;
		call('diagnostics_db_query', { query: sql }, 'Running query…').done(function (r) {
			D.sql.result = r;
			D.history = r.history || D.history;
			render();
			if (r.type !== 'select') { loadTables(false); }
		}).fail(function (msg, data) {
			D.sql.error = msg;
			if (data && data.history) { D.history = data.history; }
			render();
		});
	}

	/* ----------------------------------------------------------------- search */

	function renderSearch() {
		var cols = D.browse.columns.length ? D.browse.columns : ((D.structure && D.structure.cols) || []);
		if (!cols.length) { return '<p class="pma-muted">Loading…</p>'; }
		var h = '<form class="pma-form" onsubmit="return false"><fieldset class="pma-fieldset"><legend>Do a "query by example" (wildcard: "%")</legend>'
			+ '<table class="pma-table pma-data pma-search"><thead><tr><th>Column</th><th>Type</th><th>Collation</th><th>Operator</th><th>Value</th></tr></thead><tbody>';
		cols.forEach(function (c) {
			var ops = isNumericType(c.Type) ? OPS_NUMBER : OPS_TEXT;
			var cur = D.search.ops[c.Field] || ops[0];
			h += '<tr><th class="pma-row-head">' + esc(c.Field) + '</th><td>' + esc(c.Type) + '</td><td>' + esc(c.Collation || '') + '</td>'
				+ '<td><select data-pma-op="' + esc(c.Field) + '">' + ops.map(function (o) { return '<option' + (o === cur ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select></td>'
				+ '<td><input type="text" data-pma-val="' + esc(c.Field) + '" value="' + esc(D.search.vals[c.Field] || '') + '"></td></tr>';
		});
		h += '</tbody></table><p class="pma-muted">For IN (...) list values separated by commas; for BETWEEN enter two values separated by a comma.</p></fieldset>'
			+ '<fieldset class="pma-fieldset pma-tbl-footer"><div class="pma-footer-row"><button type="button" class="pma-btn" data-pma="search-reset">Reset</button><button type="button" class="pma-btn pma-btn-go" data-pma="search-go">Go</button></div></fieldset></form>';
		return h;
	}

	/* ----------------------------------------------------------------- export / operations */

	function renderExport() {
		return '<h2 class="pma-h2">Exporting rows from "' + esc(D.table) + '" table</h2><form class="pma-form" onsubmit="return false">'
			+ '<fieldset class="pma-fieldset"><legend>Export method:</legend>'
			+ '<label class="pma-radio"><input type="radio" name="pma-export-method" value="quick"' + (D.exportMethod === 'quick' ? ' checked' : '') + '> Quick - display only the minimal options</label>'
			+ '<label class="pma-radio"><input type="radio" name="pma-export-method" value="custom"' + (D.exportMethod === 'custom' ? ' checked' : '') + '> Custom - display all possible options</label></fieldset>'
			+ '<fieldset class="pma-fieldset"><legend>Format:</legend><select id="pma-export-format">'
			+ [['sql', 'SQL'], ['csv', 'CSV'], ['json', 'JSON']].map(function (f) { return '<option value="' + f[0] + '"' + (D.exportFormat === f[0] ? ' selected' : '') + '>' + f[1] + '</option>'; }).join('')
			+ '</select></fieldset>'
			+ (D.exportMethod === 'custom'
				? '<fieldset class="pma-fieldset"><legend>Output:</legend><label>Compression: <select id="pma-export-compression"><option value="">None</option><option value="zip"' + (D.exportCompression === 'zip' ? ' selected' : '') + '>zipped</option></select></label></fieldset>'
				: '')
			+ '<fieldset class="pma-fieldset pma-tbl-footer"><div class="pma-footer-row"><button type="button" class="pma-btn pma-btn-go" data-pma="export-go">Go</button></div></fieldset></form>';
	}

	function renderOperations() {
		var t = esc(D.table);
		var h = '<div class="pma-ops-grid"><fieldset class="pma-fieldset"><legend>Table maintenance</legend><ul class="pma-ops-list">'
			+ '<li><a href="#" data-pma="table-op" data-op="check" data-table="' + t + '">Check table</a></li>'
			+ '<li><a href="#" data-pma="table-op" data-op="analyze" data-table="' + t + '">Analyze table</a></li>'
			+ '<li><a href="#" data-pma="table-op" data-op="optimize" data-table="' + t + '">Optimize table</a></li>'
			+ '<li><a href="#" data-pma="table-op" data-op="repair" data-table="' + t + '">Repair table</a></li></ul></fieldset>'
			+ '<fieldset class="pma-fieldset pma-fieldset-danger"><legend>Delete data or table</legend><ul class="pma-ops-list">'
			+ '<li><a href="#" class="pma-danger" data-pma="table-op" data-op="empty" data-table="' + t + '">Empty the table (TRUNCATE)</a></li>'
			+ '<li>' + (D.allowDrop ? '<a href="#" class="pma-danger" data-pma="table-op" data-op="drop" data-table="' + t + '">Delete the table (DROP)</a>' : '<span class="pma-disabled" title="DROP TABLE is disabled in the plugin settings">Delete the table (DROP)</span>') + '</li></ul></fieldset></div>';
		if (D.opResult) { h += renderOpResult(); }
		return h;
	}

	function renderOpResult() {
		var res = D.opResult;
		var h = '';
		if (res.rows && res.rows.length) {
			var cols = Object.keys(res.rows[0]);
			h += '<table class="pma-table pma-data pma-results"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>'
				+ res.rows.map(function (row) { return '<tr>' + cols.map(function (c) { return '<td>' + esc(row[c]) + '</td>'; }).join('') + '</tr>'; }).join('')
				+ '</tbody></table>';
		}
		return h;
	}

	/* ----------------------------------------------------------------- data loading */

	function loadTables(alsoCurrent) {
		return call('diagnostics_db_tables', {}, 'Loading…').done(function (r) {
			D.tables = r.tables || [];
			D.server = r.server || 'localhost';
			D.database = r.database || '';
			D.version = r.version || '';
			D.safeMode = !!r.safe_mode;
			D.allowDrop = !!r.allow_drop;
			D.history = r.query_history || [];
			D.loaded = true;
			if (D.table && !tableInfo(D.table)) { D.table = ''; D.tab = 'structure'; }
			render();
			if (alsoCurrent && D.table) { loadCurrent(); }
		}).fail(function (msg) {
			D.loaded = true;
			setMessage('error', esc(msg));
			render();
		});
	}

	function loadBrowse() {
		var b = D.browse;
		return call('diagnostics_db_browse', {
			table: D.table,
			page: b.page,
			per_page: b.showAll ? 0 : b.perPage,
			sort_by: b.sort,
			sort_dir: b.dir,
			filters: JSON.stringify(b.filters)
		}, 'Loading…').done(function (r) {
			if (D.browse !== b) { return; }
			b.rows = r.rows || [];
			b.total = r.total || 0;
			b.columns = r.columns || [];
			b.keys = r.unique_keys || (r.primary_key ? [r.primary_key] : []);
			b.sql = r.sql || '';
			b.time = r.time || 0;
			b.selected = {};
			b.editing = null;
			b.loaded = true;
			var last = pageCount();
			if (b.page > last) { b.page = last; loadBrowse(); return; }
			render();
		}).fail(function (msg, data) {
			b.loaded = true;
			setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + (data && data.sql ? sqlBox(data.sql, false) : ''));
			render();
		});
	}

	function loadStructure() {
		var table = D.table;
		return call('diagnostics_db_structure', { table: table }, 'Loading…').done(function (r) {
			if (D.table !== table) { return; }
			D.structure = { cols: r.structure || [], indexes: r.indexes || [], status: r.status || {} };
			render();
		}).fail(function (msg) {
			setMessage('error', esc(msg));
			render();
		});
	}

	function loadCurrent() {
		if (D.tab === 'browse') { loadBrowse(); } else { loadStructure(); }
		if (D.tab !== 'browse' && !D.browse.columns.length) { /* structure gives the columns */ }
	}

	function openTable(name, tab) {
		var changed = D.table !== name;
		D.table = name;
		D.tab = tab || 'browse';
		D.message = null;
		D.opResult = null;
		if (changed) {
			D.browse = freshBrowse();
			D.structure = null;
			D.structureSelected = {};
			D.search = { ops: {}, vals: {} };
			D.sql = { text: '', result: null, error: '', ranQuery: '' };
		}
		render();
		if (D.tab === 'browse') {
			loadBrowse();
		} else {
			loadStructure();
		}
	}

	function goBrowse(reload) {
		D.tab = 'browse';
		D.edit = null;
		render();
		if (reload || !D.browse.loaded) { loadBrowse(); }
	}

	function goDatabase() {
		D.table = '';
		D.tab = 'structure';
		D.message = null;
		D.opResult = null;
		render();
	}

	/* ----------------------------------------------------------------- actions */

	function tableOp(op, tables, done) {
		var names = tables.slice();
		var labels = { check: 'Check table', analyze: 'Analyze table', optimize: 'Optimize table', repair: 'Repair table', empty: 'TRUNCATE', drop: 'DROP TABLE' };
		if (op === 'empty' || op === 'drop') {
			var sql = names.map(function (n) { return (op === 'empty' ? 'TRUNCATE ' : 'DROP TABLE ') + q(n); }).join(';\n');
			if (!window.confirm('Do you really want to execute "' + sql + '"?\n\nYou are about to DESTROY ' + (op === 'empty' ? 'all data in' : '') + ' ' + names.length + ' table' + (names.length === 1 ? '' : 's') + '!')) { return; }
		}
		var rows = [];
		var errors = [];
		var i = 0;
		var next = function () {
			if (i >= names.length) {
				D.opResult = rows.length ? { rows: rows } : null;
				if (errors.length) {
					setMessage('error', errors.map(esc).join('<br>'));
				} else {
					setMessage('success', esc(labels[op]) + ': your SQL query has been executed successfully.' + (op === 'empty' || op === 'drop' ? '' : ''));
				}
				if (done) { done(); }
				loadTables(false);
				render();
				return;
			}
			var name = names[i++];
			call('diagnostics_db_operations', { table: name, operation: op, confirm: '1' }, labels[op] + ' ' + name + '…').done(function (r) {
				(r.result || []).forEach(function (row) { rows.push(row); });
				next();
			}).fail(function (msg) {
				errors.push(name + ': ' + msg);
				next();
			});
		};
		next();
	}

	function deleteRows(indexes) {
		var b = D.browse;
		var wheres = indexes.map(function (i) { return rowWhere(b.rows[i]); }).filter(Boolean);
		if (!wheres.length) { return; }
		var sql = 'DELETE FROM ' + q(D.table) + ' WHERE ' + wheres.map(function (w) { return whereSql(w); }).join(wheres.length > 1 ? ' OR ' : '');
		if (!window.confirm('Do you really want to execute "' + (sql.length > 400 ? sql.substr(0, 400) + '…' : sql) + '"?')) { return; }
		call('diagnostics_db_delete', { table: D.table, wheres: JSON.stringify(wheres) }, 'Deleting…').done(function (r) {
			setMessage('success', esc(num(r.affected)) + ' row' + (r.affected === 1 ? '' : 's') + ' deleted.' + sqlBox(sql, false));
			loadBrowse();
			loadTables(false);
		}).fail(function (msg) {
			setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + (D.safeMode ? ' <em>Turn off Safe mode (top right) to delete rows.</em>' : ''));
			render();
		});
	}

	function saveCell() {
		var b = D.browse;
		var ed = b.editing;
		if (!ed) { return; }
		var row = b.rows[ed.row];
		var value = $('#pma-cell-null').is(':checked') ? null : $('#pma-cell-input').val();
		var before = row[ed.col] === undefined ? null : row[ed.col];
		if (value === before) { b.editing = null; render(); return; }
		var where = rowWhere(row);
		var data = {};
		data[ed.col] = value;
		var sql = 'UPDATE ' + q(D.table) + ' SET ' + q(ed.col) + ' = ' + sqlValue(value) + ' WHERE ' + whereSql(where);
		call('diagnostics_db_update', { table: D.table, data: JSON.stringify(data), where: JSON.stringify(where) }, 'Saving…').done(function (r) {
			row[ed.col] = value;
			b.editing = null;
			setMessage('success', esc(num(r.affected)) + ' row affected.' + sqlBox(sql, false));
			render();
		}).fail(function (msg) {
			setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + sqlBox(sql, false));
			render();
		});
	}

	function resultsTsv() {
		var b = D.browse;
		var lines = [b.columns.map(function (c) { return c.Field; }).join('\t')];
		b.rows.forEach(function (row) {
			lines.push(b.columns.map(function (c) { return row[c.Field] === null ? 'NULL' : String(row[c.Field]).replace(/[\t\r\n]+/g, ' '); }).join('\t'));
		});
		return lines.join('\n');
	}

	function downloadCsv(rows, name) {
		var cols = D.browse.columns.map(function (c) { return c.Field; });
		var cell = function (v) {
			if (v === null || v === undefined) { return 'NULL'; }
			v = String(v);
			// Keep spreadsheet apps from running cell text as a formula.
			if (/^[=+\-@]/.test(v)) { v = "'" + v; }
			return '"' + v.replace(/"/g, '""') + '"';
		};
		var csv = [cols.map(cell).join(',')].concat(rows.map(function (row) { return cols.map(function (c) { return cell(row[c]); }).join(','); })).join('\r\n');
		var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		document.body.appendChild(a);
		a.click();
		setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		var $t = $('<textarea style="position:fixed;left:-9999px">').val(text).appendTo('body');
		$t[0].select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		$t.remove();
		return $.Deferred().resolve().promise();
	}

	function printResults() {
		var $table = $(D.root).find('.pma-results').first().clone();
		$table.find('.pma-cb, .pma-act, .pma-th-full').remove();
		var w = window.open('', '_blank');
		if (!w) { return; }
		w.document.write('<!doctype html><title>' + esc(D.table) + '</title><style>body{font:12px sans-serif}table{border-collapse:collapse}th,td{border:1px solid #999;padding:2px 5px;text-align:left}th{background:#ddd}</style>'
			+ '<p>' + esc(D.browse.sql) + '</p>' + $table.prop('outerHTML'));
		w.document.close();
		w.focus();
		w.print();
	}

	/* ----------------------------------------------------------------- events */

	function bind() {
		var $doc = $(document);
		$doc.off('.wudtpma');
		var root = '#wudt-dbm-root ';

		$doc.on('click.wudtpma', root + '[data-pma]', function (e) {
			e.preventDefault();
			var $a = $(this);
			if ($a.is('[disabled]')) { return; }
			var act = String($a.data('pma'));
			var b = D.browse;
			var idx;
			switch (act) {
				case 'reload': loadTables(true); break;
				case 'go-db': goDatabase(); break;
				case 'open-table': openTable(String($a.data('table')), 'browse'); break;
				case 'open-structure': openTable(String($a.data('table')), 'structure'); break;
				case 'open-tab':
					openTable(String($a.data('table')), 'structure');
					D.tab = String($a.data('tab'));
					render();
					break;
				case 'tab':
					D.tab = String($a.data('tab'));
					D.message = null;
					D.opResult = null;
					if (D.tab === 'browse') { goBrowse(!b.loaded); return; }
					if (D.tab === 'insert') { D.edit = { mode: 'insert', rows: [null] }; }
					render();
					if ((D.tab === 'structure' || D.tab === 'search' || D.tab === 'insert' || D.tab === 'sql') && D.table && !D.structure) { loadStructure(); }
					break;
				case 'refresh': D.message = null; loadBrowse(); break;
				case 'page':
					b.page = Math.max(1, Math.min(pageCount(), Number($a.data('page')) || 1));
					loadBrowse();
					break;
				case 'sort':
					var col = String($a.data('col'));
					if (b.sort === col) { b.dir = b.dir === 'ASC' ? 'DESC' : 'ASC'; } else { b.sort = col; b.dir = 'ASC'; }
					loadBrowse();
					break;
				case 'fulltext': b.fullText = !b.fullText; render(); break;
				case 'clear-filters': b.filters = []; b.page = 1; D.message = null; loadBrowse(); break;
				case 'row-edit':
					idx = Number($a.data('row'));
					D.edit = { mode: 'edit', rows: [b.rows[idx]] };
					D.tab = 'edit';
					D.message = null;
					render();
					break;
				case 'row-copy':
					idx = Number($a.data('row'));
					D.edit = { mode: 'copy', rows: [b.rows[idx]] };
					D.tab = 'edit';
					D.message = null;
					render();
					break;
				case 'row-delete': deleteRows([Number($a.data('row'))]); break;
				case 'sel-edit':
				case 'sel-copy':
					var picked = Object.keys(b.selected).map(Number).sort(function (x, y) { return x - y; }).map(function (i) { return b.rows[i]; });
					D.edit = { mode: act === 'sel-edit' ? 'edit' : 'copy', rows: picked };
					D.tab = 'edit';
					D.message = null;
					render();
					break;
				case 'sel-delete': deleteRows(Object.keys(b.selected).map(Number)); break;
				case 'sel-export':
					downloadCsv(Object.keys(b.selected).map(Number).sort(function (x, y) { return x - y; }).map(function (i) { return b.rows[i]; }), D.table + '-selected.csv');
					break;
				case 'print': printResults(); break;
				case 'copy-results':
					copyText(resultsTsv()).then(function () { setMessage('success', 'The results of this page were copied to the clipboard.'); render(); });
					break;
				case 'cell-save': saveCell(); break;
				case 'cell-cancel': b.editing = null; render(); break;
				case 'form-go': submitForm(); break;
				case 'form-reset': render(); break;
				case 'sql-edit':
					D.sql.text = String($a.data('sql'));
					D.tab = 'sql';
					D.message = null;
					render();
					break;
				case 'sql-explain': D.tab = 'sql'; D.message = null; runSql('EXPLAIN ' + String($a.data('sql')).replace(/\s+LIMIT\s+\d+\s*,\s*\d+\s*$/i, '')); break;
				case 'sql-go': runSql($('#pma-sql-text').val()); break;
				case 'sql-clear': D.sql.text = ' '; $('#pma-sql-text').val('').trigger('focus'); break;
				case 'sql-format': $('#pma-sql-text').val(formatSql($('#pma-sql-text').val())); D.sql.text = $('#pma-sql-text').val(); break;
				case 'sql-template':
					var cols = (b.columns.length ? b.columns : ((D.structure && D.structure.cols) || [])).map(function (c) { return q(c.Field); });
					var tpl = {
						'SELECT *': 'SELECT * FROM ' + q(D.table) + ' WHERE 1',
						'SELECT': 'SELECT ' + cols.join(', ') + ' FROM ' + q(D.table) + ' WHERE 1',
						'INSERT': 'INSERT INTO ' + q(D.table) + ' (' + cols.join(', ') + ') VALUES (' + cols.map(function (c) { return '[value-' + c.replace(/`/g, '') + ']'; }).join(', ') + ')',
						'UPDATE': 'UPDATE ' + q(D.table) + ' SET ' + cols.map(function (c) { return c + '=[value-' + c.replace(/`/g, '') + ']'; }).join(', ') + ' WHERE 1',
						'DELETE': 'DELETE FROM ' + q(D.table) + ' WHERE 0'
					}[String($a.data('tpl'))];
					$('#pma-sql-text').val(tpl);
					D.sql.text = tpl;
					break;
				case 'sql-insert-cols':
					var picks = ($('#pma-sql-cols').val() || []).map(q).join(', ');
					var ta = document.getElementById('pma-sql-text');
					if (ta && picks) {
						var start = ta.selectionStart;
						ta.value = ta.value.substr(0, start) + picks + ta.value.substr(ta.selectionEnd);
						D.sql.text = ta.value;
						ta.focus();
					}
					break;
				case 'history':
					D.sql.text = String($a.data('sql'));
					render();
					break;
				case 'search-go':
					var filters = [];
					$(D.root).find('[data-pma-op]').each(function () {
						var field = $(this).attr('data-pma-op');
						var op = $(this).val();
						var val = $(D.root).find('[data-pma-val="' + field.replace(/"/g, '\\"') + '"]').val();
						D.search.ops[field] = op;
						D.search.vals[field] = val;
						if (val !== '' || /NULL|''/.test(op)) { filters.push({ col: field, op: op, value: val }); }
					});
					b.filters = filters;
					b.page = 1;
					D.message = null;
					goBrowse(true);
					break;
				case 'search-reset': D.search = { ops: {}, vals: {} }; render(); break;
				case 'export-go':
					call('diagnostics_db_export', { table: D.table, format: $('#pma-export-format').val(), compression: D.exportMethod === 'custom' ? ($('#pma-export-compression').val() || '') : '' }, 'Exporting…').done(function (r) {
						if (r.url) { window.location.href = r.url; }
					}).fail(function (msg) { setMessage('error', esc(msg)); render(); });
					break;
				case 'table-op': tableOp(String($a.data('op')), [String($a.data('table'))], function () {
					if (String($a.data('op')) === 'drop') { goDatabase(); }
					if (String($a.data('op')) === 'empty' && D.table) { D.browse.loaded = false; }
				}); break;
				case 'db-check-overhead':
					D.dbSelected = {};
					D.tables.forEach(function (t) { if (t.overhead > 0) { D.dbSelected[t.name] = true; } });
					render();
					break;
				case 'col-change':
				case 'col-add':
					var colName = String($a.data('col') || '');
					var found = null;
					((D.structure && D.structure.cols) || []).forEach(function (c) { if (c.Field === colName) { found = c; } });
					D.colForm = { col: act === 'col-change' ? found : null, position: $('#pma-add-pos').val() || 'end' };
					D.tab = 'column';
					D.message = null;
					render();
					break;
				case 'col-preview':
					var preview = columnSql();
					setMessage(preview ? 'notice' : 'error', preview ? 'Preview SQL:' + sqlBox(preview, false) : 'Enter a column name and type.');
					render();
					break;
				case 'col-save':
					var alter = columnSql();
					if (!alter) { setMessage('error', 'Enter a column name and type.'); render(); return; }
					call('diagnostics_db_query', { query: alter }, 'Saving…').done(function () {
						setMessage('success', 'Table ' + esc(D.table) + ' has been altered successfully.' + sqlBox(alter, false));
						D.tab = 'structure';
						D.browse = freshBrowse();
						loadStructure();
					}).fail(function (msg) {
						setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + sqlBox(alter, false) + (D.safeMode ? ' <em>Safe mode blocks ALTER statements; turn it off (top right) to change columns.</em>' : ''));
						render();
					});
					break;
				case 'col-drop':
				case 'cols-drop':
					var dropCols = act === 'col-drop' ? [String($a.data('col'))] : Object.keys(D.structureSelected);
					var dropSql = 'ALTER TABLE ' + q(D.table) + ' ' + dropCols.map(function (c) { return 'DROP ' + q(c); }).join(', ');
					if (!window.confirm('Do you really want to execute "' + dropSql + '"?')) { return; }
					call('diagnostics_db_query', { query: dropSql }, 'Dropping…').done(function () {
						setMessage('success', 'Column' + (dropCols.length > 1 ? 's' : '') + ' ' + esc(dropCols.join(', ')) + ' dropped.' + sqlBox(dropSql, false));
						D.structureSelected = {};
						D.browse = freshBrowse();
						loadStructure();
					}).fail(function (msg) {
						setMessage('error', '<strong>MySQL said:</strong> ' + esc(msg) + (D.safeMode ? ' <em>Safe mode blocks ALTER statements; turn it off (top right) to drop columns.</em>' : ''));
						render();
					});
					break;
				case 'col-browse':
				case 'cols-browse':
					var bcols = act === 'col-browse' ? [String($a.data('col'))] : Object.keys(D.structureSelected);
					D.tab = 'sql';
					runSql(act === 'col-browse'
						? 'SELECT COUNT(*) AS `Rows`, ' + q(bcols[0]) + ' FROM ' + q(D.table) + ' GROUP BY ' + q(bcols[0]) + ' ORDER BY ' + q(bcols[0])
						: 'SELECT ' + bcols.map(q).join(', ') + ' FROM ' + q(D.table) + ' LIMIT 25');
					break;
			}
		});

		// Grid editing: double-click a cell, like phpMyAdmin.
		$doc.on('dblclick.wudtpma', root + 'td.pma-grid-edit', function () {
			clearTimeout(D.markTimer);
			var b = D.browse;
			var rowIndex = Number($(this).closest('tr').attr('data-row'));
			var col = String($(this).attr('data-col'));
			if (b.editing && b.editing.row === rowIndex && b.editing.col === col) { return; }
			b.editing = { row: rowIndex, col: col };
			render();
			var input = document.getElementById('pma-cell-input');
			if (input) { input.focus(); input.select(); }
		});
		$doc.on('keydown.wudtpma', root + '#pma-cell-input', function (e) {
			if (e.key === 'Escape') { D.browse.editing = null; render(); }
			if (e.key === 'Enter' && (this.tagName === 'INPUT' || e.ctrlKey)) { e.preventDefault(); saveCell(); }
		});

		// Checkboxes and selects.
		$doc.on('change.wudtpma', root + '[data-pma-row]', function () {
			var i = Number($(this).attr('data-pma-row'));
			if (this.checked) { D.browse.selected[i] = true; } else { delete D.browse.selected[i]; }
			render();
		});
		$doc.on('click.wudtpma', root + '.pma-results tbody td.pma-cell', function (e) {
			// A single click on a row marks it (phpMyAdmin's row marker).
			if (e.detail > 1 || D.browse.editing || !D.browse.keys.length) { return; }
			var $tr = $(this).closest('tr');
			var i = Number($tr.attr('data-row'));
			if (isNaN(i)) { return; }
			// Wait: the click may be the first half of a double-click (grid edit).
			clearTimeout(D.markTimer);
			D.markTimer = setTimeout(function () {
				if (D.browse.selected[i]) { delete D.browse.selected[i]; } else { D.browse.selected[i] = true; }
				$tr.toggleClass('is-marked', !!D.browse.selected[i]).find('[data-pma-row]').prop('checked', !!D.browse.selected[i]);
				var count = Object.keys(D.browse.selected).length;
				$(D.root).find('[data-pma^="sel-"]').prop('disabled', !count);
				$('#pma-checkall').prop('checked', count === D.browse.rows.length);
			}, 250);
		});
		$doc.on('change.wudtpma', root + '#pma-checkall', function () {
			var b = D.browse;
			b.selected = {};
			if (this.checked) { b.rows.forEach(function (_, i) { b.selected[i] = true; }); }
			render();
		});
		$doc.on('change.wudtpma', root + '[data-pma-dbsel]', function () {
			var n = String($(this).attr('data-pma-dbsel'));
			if (this.checked) { D.dbSelected[n] = true; } else { delete D.dbSelected[n]; }
			render();
		});
		$doc.on('change.wudtpma', root + '#pma-db-checkall', function () {
			D.dbSelected = {};
			if (this.checked) { D.tables.forEach(function (t) { D.dbSelected[t.name] = true; }); }
			render();
		});
		$doc.on('change.wudtpma', root + '#pma-db-with', function () {
			var op = $(this).val();
			var names = Object.keys(D.dbSelected);
			$(this).val('');
			if (!op || !names.length) { return; }
			tableOp(op, names, function () { D.dbSelected = {}; });
		});
		$doc.on('change.wudtpma', root + '[data-pma-colsel]', function () {
			var n = String($(this).attr('data-pma-colsel'));
			if (this.checked) { D.structureSelected[n] = true; } else { delete D.structureSelected[n]; }
			render();
		});
		$doc.on('change.wudtpma', root + '#pma-col-checkall', function () {
			D.structureSelected = {};
			if (this.checked) { (D.structure.cols || []).forEach(function (c) { D.structureSelected[c.Field] = true; }); }
			render();
		});
		$doc.on('change.wudtpma', root + '[data-pma-page]', function () {
			D.browse.page = Number($(this).val()) || 1;
			loadBrowse();
		});
		$doc.on('change.wudtpma', root + '[data-pma-perpage]', function () {
			D.browse.perPage = Number($(this).val()) || 25;
			D.browse.page = 1;
			loadBrowse();
		});
		$doc.on('change.wudtpma', root + '[data-pma-showall]', function () {
			if (this.checked && D.browse.total > 2000 && !window.confirm('This table has ' + num(D.browse.total) + ' rows; only the first 2,000 will be shown. Continue?')) {
				this.checked = false;
				return;
			}
			D.browse.showAll = this.checked;
			D.browse.page = 1;
			loadBrowse();
		});
		$doc.on('change.wudtpma', root + '[data-pma-sortkey]', function () {
			var dir = $(this).val();
			D.browse.sort = dir ? D.browse.keys[0] : '';
			D.browse.dir = dir || 'ASC';
			loadBrowse();
		});
		$doc.on('change.wudtpma', root + '#pma-safe-mode', function () {
			var on = this.checked;
			if (!on && !window.confirm('Turn off Safe mode? Deleting rows, emptying tables and DROP / TRUNCATE / ALTER statements will be allowed.')) {
				this.checked = true;
				return;
			}
			post('wudt_dbm_toggle_safe_mode', { safe_mode: on ? '1' : '0' }).done(function (r) {
				D.safeMode = !!(r && r.data && r.data.safe_mode);
				render();
			});
		});
		$doc.on('change.wudtpma', root + 'input[name="pma-export-method"]', function () {
			D.exportFormat = $('#pma-export-format').val() || D.exportFormat;
			D.exportMethod = $(this).val();
			render();
		});
		$doc.on('change.wudtpma', root + '#pma-export-format', function () { D.exportFormat = $(this).val(); });
		$doc.on('change.wudtpma', root + '#pma-export-compression', function () { D.exportCompression = $(this).val(); });
		$doc.on('change.wudtpma', root + '[data-pma-null]', function () {
			if (this.checked) {
				$(D.root).find('[data-pma-field="' + $(this).attr('data-pma-null').replace(/"/g, '\\"') + '"][data-pma-form="' + $(this).attr('data-pma-form') + '"]').val('');
			}
		});
		$doc.on('input.wudtpma', root + '[data-pma-field]', function () {
			if (this.value !== '') {
				$(D.root).find('[data-pma-null="' + $(this).attr('data-pma-field').replace(/"/g, '\\"') + '"][data-pma-form="' + $(this).attr('data-pma-form') + '"]').prop('checked', false);
			}
		});

		// Text inputs that filter in place keep focus while typing.
		$doc.on('input.wudtpma', root + '#pma-nav-filter', function () {
			var f = (D.navFilter = $(this).val()).toLowerCase();
			$(D.root).find('.pma-tree-tables li').each(function () {
				this.hidden = !!f && this.getAttribute('data-pma-name').indexOf(f) === -1;
			});
		});
		$doc.on('input.wudtpma', root + '#pma-filter-rows', function () {
			var f = (D.browse.filterText = $(this).val()).toLowerCase();
			$(D.root).find('.pma-results tbody tr').each(function () {
				this.hidden = !!f && $(this).find('td.pma-cell').text().toLowerCase().indexOf(f) === -1;
			});
		});
		$doc.on('input.wudtpma', root + '#pma-sql-text', function () { D.sql.text = $(this).val(); });
		$doc.on('keydown.wudtpma', root + '#pma-sql-text', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); runSql($(this).val()); }
		});
		$doc.on('keydown.wudtpma', root + '[data-pma-val]', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); $(D.root).find('[data-pma="search-go"]').trigger('click'); }
		});
	}

	window.WUDTDbManager = {
		mount: function (el) {
			D.root = el;
			bind();
			render();
			if (!D.loaded) { loadTables(false); }
		}
	};
})(jQuery);
