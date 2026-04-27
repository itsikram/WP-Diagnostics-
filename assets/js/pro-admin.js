(function ($) {
	'use strict';

	var bootData = (window.wudtProAdmin && window.wudtProAdmin.data) ? window.wudtProAdmin.data : { tabs: [] };
	var state = {
		data: bootData,
		tab: 'dashboard',
		currentPath: '',
		history: [],
		historyIndex: -1,
		dirCache: {},
		selected: [],
		lastSelected: -1,
		clipboardPath: '',
		fileViewMode: 'details',
		fileSearch: '',
		contextMenu: null,
		fileClickTimer: null,
		sidebarExpanded: {
			ABSPATH: true,
			'wp-content': true,
			plugins: false,
			themes: false,
			uploads: false
		},
		preview: null,
		darkExplorer: false,
		db: {
			tables: [],
			selectedTable: '',
			tab: 'browse',
			browseRows: [],
			columns: [],
			primaryKey: '',
			page: 1,
			perPage: 0,
			total: 0,
			search: '',
			sortBy: '',
			sortDir: 'ASC',
			structure: [],
			queryHistory: [],
			lastQueryResult: null,
			safeMode: true
		},
		editor: {
			visible: false,
			minimized: false,
			maximized: false,
			path: '',
			title: '',
			content: '',
			original: '',
			language: 'text',
			wordWrap: false,
			x: 120,
			y: 90,
			width: 860,
			height: 560,
			dragging: false,
			dragOffsetX: 0,
			dragOffsetY: 0
		}
	};

	function esc(text) {
		var m = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
		return String(text || '').replace(/[&<>"']/g, function (ch) { return m[ch]; });
	}

	function titleCase(input) {
		return String(input || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function tabData(key) {
		var tabs = (state.data && state.data.tabs) ? state.data.tabs : [];
		for (var i = 0; i < tabs.length; i++) {
			if (tabs[i].key === key) {
				return tabs[i].data || {};
			}
		}
		return {};
	}

	function status(text) {
		$('#wudt-pro-status').text(text || '');
	}

	function post(action, data) {
		data = data || {};
		if (!window.wudtProAdmin) {
			return $.Deferred().reject({ success: false, data: { message: 'wudtProAdmin missing' } });
		}
		return $.post(window.wudtProAdmin.ajaxUrl, $.extend({ action: action, nonce: window.wudtProAdmin.nonce }, data));
	}

	function render() {
		var tabs = ['dashboard', 'file_manager', 'database_manager', 'malware_scanner', 'logs', 'performance', 'security'];
		var tabsHtml = '';
		for (var i = 0; i < tabs.length; i++) {
			var t = tabs[i];
			tabsHtml += '<button class="wudt-tab ' + (state.tab === t ? 'is-active' : '') + '" data-tab="' + esc(t) + '">' + esc(titleCase(t)) + '</button>';
		}

		var html = ''
			+ '<div class="wudt-app-shell">'
			+ '<div class="wudt-toolbar">'
			+ '<span class="wudt-badge">Generated: ' + esc((state.data && state.data.generated_at) ? state.data.generated_at : 'N/A') + '</span>'
			+ '<button class="button button-primary" id="wudt-pro-refresh">Refresh</button>'
			+ '<span class="wudt-status" id="wudt-pro-status"></span>'
			+ '</div>'
			+ '<div class="wudt-tabs">' + tabsHtml + '</div>'
			+ '<div class="wudt-panel">' + renderPanel() + '</div>'
			+ '</div>';
		$('#wudt-pro-admin-app').html(html);
		bind();
	}

	function renderPanel() {
		if (state.tab === 'dashboard') {
			return '<div class="wudt-grid">'
				+ '<div class="wudt-col-6"><div class="wudt-card"><h3>System Overview</h3><pre class="wudt-pre">' + esc(JSON.stringify(tabData('system_info'), null, 2)) + '</pre></div></div>'
				+ '<div class="wudt-col-6"><div class="wudt-card"><h3>Quick Status</h3><pre class="wudt-pre">' + esc(JSON.stringify({ security: tabData('security'), performance: tabData('performance') }, null, 2)) + '</pre></div></div>'
				+ '</div>';
		}
		if (state.tab === 'logs') {
			return '<div class="wudt-card"><h3>Operations Logs</h3><pre class="wudt-pre">' + esc(JSON.stringify(tabData('logs'), null, 2)) + '</pre></div>';
		}
		if (state.tab === 'performance') {
			return '<div class="wudt-card"><h3>Performance Insights</h3><pre class="wudt-pre">' + esc(JSON.stringify({ performance: tabData('performance'), advanced: tabData('advanced_tools'), requests: tabData('external_requests') }, null, 2)) + '</pre></div>';
		}
		if (state.tab === 'security') {
			return '<div class="wudt-card"><h3>Security Checks</h3><pre class="wudt-pre">' + esc(JSON.stringify(tabData('security'), null, 2)) + '</pre></div>';
		}
		if (state.tab === 'file_manager') { return renderFileManager(); }
		if (state.tab === 'database_manager') { return renderDbManager(); }
		if (state.tab === 'malware_scanner') { return renderMalware(); }
		return '<p>No data.</p>';
	}

	function normalizePath(path) {
		return String(path || '').replace(/\\/g, '/');
	}

	function pathBasename(path) {
		var normalized = normalizePath(path);
		var parts = normalized.split('/');
		return parts[parts.length - 1] || normalized;
	}

	function parseListing(entries) {
		var out = [];
		for (var i = 0; i < (entries || []).length; i++) {
			var e = entries[i] || {};
			out.push({
				name: e.name || pathBasename(e.path || ''),
				path: normalizePath(e.path || ''),
				type: e.type || 'file',
				size: e.size || 0,
				permissions: e.permissions || '',
				modified: e.modified || '',
				extension: (e.name && e.name.indexOf('.') !== -1) ? e.name.split('.').pop().toLowerCase() : ''
			});
		}
		out.sort(function (a, b) {
			if (a.type !== b.type) {
				return a.type === 'dir' ? -1 : 1;
			}
			return a.name.localeCompare(b.name);
		});
		return out;
	}

	function formatSize(bytes) {
		var value = parseFloat(bytes || 0);
		if (value <= 0) { return ''; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var idx = 0;
		while (value >= 1024 && idx < units.length - 1) {
			value = value / 1024;
			idx++;
		}
		return value.toFixed(value < 10 && idx > 0 ? 1 : 0) + ' ' + units[idx];
	}

	function iconFor(entry) {
		if (entry.type === 'dir') { return '📁'; }
		if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].indexOf(entry.extension) > -1) { return '🖼️'; }
		if (['php'].indexOf(entry.extension) > -1) { return '🐘'; }
		if (['js', 'ts'].indexOf(entry.extension) > -1) { return '🟨'; }
		if (['css', 'scss'].indexOf(entry.extension) > -1) { return '🎨'; }
		if (['html', 'htm'].indexOf(entry.extension) > -1) { return '🌐'; }
		if (['json', 'xml', 'yml', 'yaml'].indexOf(entry.extension) > -1) { return '🧾'; }
		if (['zip', 'rar', '7z', 'tar', 'gz'].indexOf(entry.extension) > -1) { return '🗜️'; }
		return '📄';
	}

	function getCurrentEntries() {
		var cache = state.dirCache[state.currentPath];
		return cache ? cache : [];
	}

	function filteredEntries() {
		var q = String(state.fileSearch || '').toLowerCase();
		var entries = getCurrentEntries();
		if (!q) { return entries; }
		var filtered = [];
		for (var i = 0; i < entries.length; i++) {
			if (String(entries[i].name || '').toLowerCase().indexOf(q) !== -1) {
				filtered.push(entries[i]);
			}
		}
		return filtered;
	}

	function updateHistory(path) {
		if (state.historyIndex >= 0 && state.history[state.historyIndex] === path) {
			return;
		}
		if (state.historyIndex < state.history.length - 1) {
			state.history = state.history.slice(0, state.historyIndex + 1);
		}
		state.history.push(path);
		state.historyIndex = state.history.length - 1;
	}

	function loadDirectory(path, pushHistory, done) {
		path = normalizePath(path || '');
		status('Loading folder...');
		post('wudt_fm_list', { path: path }).done(function (r) {
			if (!r || !r.success) {
				status('Failed to load folder');
				return;
			}
			var resolved = normalizePath(r.data.path || path);
			var parsed = parseListing(r.data.entries || []);
			state.currentPath = resolved;
			state.dirCache[resolved] = parsed;
			state.selected = [];
			state.lastSelected = -1;
			if (pushHistory !== false) {
				updateHistory(resolved);
			}
			status('Folder ready');
			render();
			if (typeof done === 'function') { done(parsed); }
		}).fail(function () {
			status('Folder load failed');
		});
	}

	function buildBreadcrumb(path) {
		var normalized = normalizePath(path || '');
		var parts = normalized.split('/').filter(function (x) { return !!x; });
		var html = '<span class="wudt-fm-crumb" data-path="/">Computer</span>';
		var acc = '';
		for (var i = 0; i < parts.length; i++) {
			acc += '/' + parts[i];
			html += '<span class="wudt-fm-sep">›</span><span class="wudt-fm-crumb" data-path="' + esc(acc) + '">' + esc(parts[i]) + '</span>';
		}
		return html;
	}

	function treeNode(label, path, key) {
		var expanded = !!state.sidebarExpanded[key];
		var children = '';
		var entries = state.dirCache[path] || [];
		if (expanded && entries.length) {
			children += '<div class="wudt-fm-tree-children">';
			for (var i = 0; i < entries.length; i++) {
				if (entries[i].type !== 'dir') { continue; }
				children += '<div class="wudt-fm-tree-leaf" data-open-path="' + esc(entries[i].path) + '">📁 ' + esc(entries[i].name) + '</div>';
			}
			children += '</div>';
		}
		return ''
			+ '<div class="wudt-fm-tree-group">'
			+ '<div class="wudt-fm-tree-item ' + (normalizePath(state.currentPath) === normalizePath(path) ? 'is-active' : '') + '" data-open-path="' + esc(path) + '">'
			+ '<button class="wudt-fm-expander" data-expand-key="' + esc(key) + '">' + (expanded ? '▾' : '▸') + '</button>'
			+ '<span class="wudt-fm-tree-label">' + esc(label) + '</span>'
			+ '</div>'
			+ children
			+ '</div>';
	}

	function renderFileRows(entries) {
		var rows = '';
		for (var i = 0; i < entries.length; i++) {
			var e = entries[i];
			var selected = state.selected.indexOf(e.path) !== -1;
			rows += ''
				+ '<tr class="wudt-fm-row ' + (selected ? 'is-selected' : '') + '" '
				+ 'data-index="' + i + '" data-path="' + esc(e.path) + '" data-type="' + esc(e.type) + '" draggable="true">'
				+ '<td><span class="wudt-fm-icon">' + iconFor(e) + '</span>' + esc(e.name) + '</td>'
				+ '<td>' + esc(e.type === 'dir' ? 'File Folder' : (e.extension ? e.extension.toUpperCase() + ' File' : 'File')) + '</td>'
				+ '<td>' + esc(formatSize(e.size)) + '</td>'
				+ '<td>' + esc(e.modified || '') + '</td>'
				+ '</tr>';
		}
		return rows || '<tr><td colspan="4" class="wudt-fm-empty">This folder is empty</td></tr>';
	}

	function renderFileCards(entries) {
		var cards = '';
		for (var i = 0; i < entries.length; i++) {
			var e = entries[i];
			var selected = state.selected.indexOf(e.path) !== -1;
			cards += ''
				+ '<div class="wudt-fm-card ' + (selected ? 'is-selected' : '') + '" data-index="' + i + '" data-path="' + esc(e.path) + '" data-type="' + esc(e.type) + '" draggable="true">'
				+ '<div class="wudt-fm-card-icon">' + iconFor(e) + '</div>'
				+ '<div class="wudt-fm-card-name">' + esc(e.name) + '</div>'
				+ '<div class="wudt-fm-card-meta">' + esc(e.type === 'dir' ? 'Folder' : formatSize(e.size)) + '</div>'
				+ '</div>';
		}
		return cards || '<div class="wudt-fm-empty">This folder is empty</div>';
	}

	function renderFileManager() {
		var d = tabData('file_manager');
		if (!state.currentPath) {
			state.currentPath = normalizePath(d.root || '/');
			state.dirCache[state.currentPath] = parseListing(d.entries || []);
			updateHistory(state.currentPath);
		}
		var rows = filteredEntries();
		var sidebar = ''
			+ treeNode('WordPress Root', normalizePath(d.root || '/'), 'ABSPATH')
			+ treeNode('wp-content', normalizePath((d.root || '/') + '/wp-content'), 'wp-content')
			+ treeNode('plugins', normalizePath((d.root || '/') + '/wp-content/plugins'), 'plugins')
			+ treeNode('themes', normalizePath((d.root || '/') + '/wp-content/themes'), 'themes')
			+ treeNode('uploads', normalizePath((d.root || '/') + '/wp-content/uploads'), 'uploads');

		var listHtml = state.fileViewMode === 'grid'
			? '<div class="wudt-fm-grid" id="wudt-fm-items">' + renderFileCards(rows) + '</div>'
			: '<table class="wudt-fm-table"><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead><tbody id="wudt-fm-items">' + renderFileRows(rows) + '</tbody></table>';

		return ''
			+ '<div class="wudt-fm-shell ' + (state.darkExplorer ? 'is-dark' : '') + '">'
			+ '<div class="wudt-fm-toolbar">'
			+ '<button class="button" id="wudt-fm-back" title="Back">←</button>'
			+ '<button class="button" id="wudt-fm-forward" title="Forward">→</button>'
			+ '<button class="button" id="wudt-fm-up" title="Up">↑</button>'
			+ '<button class="button" id="wudt-fm-refresh">Refresh</button>'
			+ '<button class="button" id="wudt-fm-new-folder">New Folder</button>'
			+ '<button class="button" id="wudt-fm-upload-trigger">Upload File</button>'
			+ '<button class="button" id="wudt-fm-download">Download</button>'
			+ '<button class="button" id="wudt-fm-toggle-view">' + (state.fileViewMode === 'grid' ? 'Details View' : 'Grid View') + '</button>'
			+ '<button class="button" id="wudt-fm-dark">' + (state.darkExplorer ? 'Light' : 'Dark') + '</button>'
			+ '<input type="file" id="wudt-fm-upload-file" style="display:none" />'
			+ '<input id="wudt-fm-search-text" class="wudt-input wudt-fm-search" value="' + esc(state.fileSearch) + '" placeholder="Search this folder" />'
			+ '</div>'
			+ '<div class="wudt-fm-breadcrumb">' + buildBreadcrumb(state.currentPath) + '</div>'
			+ '<div class="wudt-fm-main">'
			+ '<aside class="wudt-fm-sidebar">' + sidebar + '</aside>'
			+ '<section class="wudt-fm-content" id="wudt-fm-content-drop">'
			+ listHtml
			+ '</section>'
			+ '</div>'
			+ '<div class="wudt-fm-context" id="wudt-fm-context"></div>'
			+ renderEditorTaskbar()
			+ renderEditorWindow()
			+ '</div>';
	}

	function renderEditorTaskbar() {
		if (!state.editor.minimized) { return ''; }
		return '<div class="wudt-ed-taskbar"><button class="button" id="wudt-ed-restore">🗔 ' + esc(state.editor.title || 'Editor') + '</button></div>';
	}

	function renderEditorWindow() {
		if (!state.editor.visible) { return ''; }
		var e = state.editor;
		var dirty = isEditorDirty();
		var style = '';
		var cls = 'wudt-ed-window';
		if (e.maximized) {
			cls += ' is-maximized';
		} else {
			style = 'style="left:' + e.x + 'px;top:' + e.y + 'px;width:' + e.width + 'px;height:' + e.height + 'px;"';
		}
		if (e.minimized) {
			cls += ' is-hidden';
		}
		return ''
			+ '<div class="' + cls + '" id="wudt-editor-window" ' + style + '>'
			+ '<div class="wudt-ed-header" id="wudt-ed-header">'
			+ '<div class="wudt-ed-title">' + esc(e.title || 'Untitled') + (dirty ? ' *' : '') + '</div>'
			+ '<div class="wudt-ed-controls">'
			+ '<button class="wudt-ed-btn" id="wudt-ed-min">➖</button>'
			+ '<button class="wudt-ed-btn" id="wudt-ed-max">🗖</button>'
			+ '<button class="wudt-ed-btn is-close" id="wudt-ed-close">✕</button>'
			+ '</div>'
			+ '</div>'
			+ '<div class="wudt-ed-meta">'
			+ '<span class="wudt-badge">' + esc(e.language.toUpperCase()) + '</span>'
			+ '<span class="wudt-status">' + esc(e.path) + '</span>'
			+ '</div>'
			+ '<div class="wudt-ed-body">'
			+ '<textarea id="wudt-ed-textarea" class="wudt-ed-textarea ' + (e.wordWrap ? 'is-wrap' : '') + '">' + esc(e.content) + '</textarea>'
			+ '</div>'
			+ '<div class="wudt-ed-footer">'
			+ '<button class="button button-primary" id="wudt-ed-save">💾 Save</button>'
			+ '<button class="button" id="wudt-ed-save-as">💾 Save As</button>'
			+ '<button class="button" id="wudt-ed-format">🔄 Format</button>'
			+ '<button class="button" id="wudt-ed-validate">🧪 Validate</button>'
			+ '<button class="button" id="wudt-ed-wrap">' + (e.wordWrap ? 'Disable Wrap' : 'Enable Wrap') + '</button>'
			+ '<button class="button" id="wudt-ed-close-footer">❌ Close</button>'
			+ '</div>'
			+ '</div>';
	}

	function renderDbManager() {
		var d = tabData('database_manager');
		if (!state.db.tables.length) {
			var stats = d.table_stats || [];
			for (var i = 0; i < stats.length; i++) {
				state.db.tables.push({
					name: stats[i].Name || '',
					rows: stats[i].Rows || 0,
					size: (parseInt(stats[i].Data_length || 0, 10) + parseInt(stats[i].Index_length || 0, 10)),
					updated: stats[i].Update_time || ''
				});
			}
			state.db.safeMode = !!d.safe_mode;
			if (!state.db.selectedTable && state.db.tables.length) {
				state.db.selectedTable = state.db.tables[0].name;
			}
		}
		var tabs = ['browse', 'structure', 'sql', 'search', 'insert', 'export', 'operations'];
		var tabsHtml = '';
		for (var t = 0; t < tabs.length; t++) {
			var key = tabs[t];
			tabsHtml += '<button class="wudt-db-tab ' + (state.db.tab === key ? 'is-active' : '') + '" data-db-tab="' + key + '">' + titleCase(key) + '</button>';
		}
		var tree = '';
		for (var j = 0; j < state.db.tables.length; j++) {
			var table = state.db.tables[j];
			tree += '<div class="wudt-db-tree-item ' + (state.db.selectedTable === table.name ? 'is-active' : '') + '" data-db-table="' + esc(table.name) + '">'
				+ '<strong>' + esc(table.name) + '</strong>'
				+ '<span>' + esc(String(table.rows)) + ' rows</span>'
				+ '<em>' + esc(formatSize(table.size)) + '</em>'
				+ '</div>';
		}
		return ''
			+ '<div class="wudt-db-shell">'
			+ '<aside class="wudt-db-sidebar">'
			+ '<div class="wudt-db-sidebar-head"><strong>Database Tables</strong><button class="button button-small" id="wudt-db-refresh">Refresh</button></div>'
			+ '<div class="wudt-db-tree">' + tree + '</div>'
			+ '</aside>'
			+ '<section class="wudt-db-main">'
			+ '<div class="wudt-db-topbar">'
			+ '<div><strong>' + esc(state.db.selectedTable || 'No table selected') + '</strong></div>'
			+ '<label class="wudt-badge"><input type="checkbox" id="wudt-db-safe-mode" ' + (state.db.safeMode ? 'checked' : '') + '> Safe Mode</label>'
			+ '</div>'
			+ '<div class="wudt-db-tabs">' + tabsHtml + '</div>'
			+ '<div class="wudt-db-body">' + renderDbTabBody() + '</div>'
			+ '</section>'
			+ '</div>';
	}

	function renderDbTabBody() {
		if (!state.db.selectedTable) {
			return '<div class="wudt-fm-empty">Select a table from left sidebar.</div>';
		}
		if (state.db.tab === 'browse') {
			return renderDbBrowse();
		}
		if (state.db.tab === 'structure') {
			return renderDbStructure();
		}
		if (state.db.tab === 'sql') {
			return renderDbSql();
		}
		if (state.db.tab === 'search') {
			return renderDbSearch();
		}
		if (state.db.tab === 'insert') {
			return renderDbInsert();
		}
		if (state.db.tab === 'export') {
			return renderDbExport();
		}
		return renderDbOperations();
	}

	function renderDbBrowse() {
		var rows = state.db.browseRows || [];
		var cols = state.db.columns || [];
		var head = '';
		for (var i = 0; i < cols.length; i++) {
			var colName = cols[i].Field || cols[i];
			head += '<th data-db-sort="' + esc(colName) + '">' + esc(colName) + '</th>';
		}
		head += '<th>Actions</th>';
		var body = '';
		for (var r = 0; r < rows.length; r++) {
			var row = rows[r];
			body += '<tr>';
			for (var c = 0; c < cols.length; c++) {
				var key = cols[c].Field || cols[c];
				body += '<td>' + esc(row[key]) + '</td>';
			}
			body += '<td>'
				+ '<button class="button-link wudt-db-row-edit" data-row-index="' + r + '">Edit</button> | '
				+ '<button class="button-link wudt-db-row-copy" data-row-index="' + r + '">Copy</button> | '
				+ '<button class="button-link wudt-db-row-delete" data-row-index="' + r + '">Delete</button>'
				+ '</td>';
			body += '</tr>';
		}
		if (!body) { body = '<tr><td colspan="' + (cols.length + 1) + '" class="wudt-fm-empty">No rows</td></tr>'; }
		return ''
			+ '<div class="wudt-toolbar">'
			+ '<input id="wudt-db-browse-search" class="wudt-input" placeholder="Filter rows..." value="' + esc(state.db.search) + '">'
			+ '<select id="wudt-db-browse-per-page" class="wudt-select"><option value="0">All</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select>'
			+ '<button class="button" id="wudt-db-browse-run">Run</button>'
			+ '<button class="button" id="wudt-db-page-prev">Prev</button>'
			+ '<span class="wudt-status">Page ' + state.db.page + ' / ' + Math.max(1, Math.ceil((state.db.total || 0) / (state.db.perPage > 0 ? state.db.perPage : (state.db.total || 1)))) + '</span>'
			+ '<button class="button" id="wudt-db-page-next">Next</button>'
			+ '</div>'
			+ '<table class="wudt-fm-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
	}

	function renderDbStructure() {
		var s = state.db.structure || [];
		var rows = '';
		for (var i = 0; i < s.length; i++) {
			rows += '<tr>'
				+ '<td>' + esc(s[i].Field) + '</td>'
				+ '<td>' + esc(s[i].Type) + '</td>'
				+ '<td>' + esc(s[i].Null) + '</td>'
				+ '<td>' + esc(s[i].Key) + '</td>'
				+ '<td>' + esc(s[i].Default) + '</td>'
				+ '<td><button class="button-link wudt-db-drop-col" data-col="' + esc(s[i].Field) + '">Drop</button></td>'
				+ '</tr>';
		}
		return '<div class="wudt-toolbar"><button class="button" id="wudt-db-structure-refresh">Refresh Structure</button><button class="button" id="wudt-db-add-col">Add Column</button></div>'
			+ '<table class="wudt-fm-table"><thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Action</th></tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderDbSql() {
		return '<textarea id="wudt-db-sql-editor" class="wudt-textarea" rows="9" placeholder="SELECT * FROM ' + esc(state.db.selectedTable) + ' LIMIT 50;"></textarea>'
			+ '<div class="wudt-toolbar"><button class="button button-primary" id="wudt-db-sql-run">Run Query</button></div>'
			+ '<pre class="wudt-pre" id="wudt-db-sql-result">' + esc(JSON.stringify(state.db.lastQueryResult || {}, null, 2)) + '</pre>'
			+ '<div class="wudt-card"><h3>Query History</h3><pre class="wudt-pre">' + esc(JSON.stringify(state.db.queryHistory || [], null, 2)) + '</pre></div>';
	}

	function renderDbSearch() {
		var cols = state.db.columns || [];
		var options = '<option value="">All columns</option>';
		for (var i = 0; i < cols.length; i++) {
			var name = cols[i].Field || cols[i];
			options += '<option value="' + esc(name) + '">' + esc(name) + '</option>';
		}
		return '<div class="wudt-toolbar">'
			+ '<select id="wudt-db-search-column" class="wudt-select">' + options + '</select>'
			+ '<input id="wudt-db-search-text" class="wudt-input" placeholder="LIKE value">'
			+ '<button class="button" id="wudt-db-search-run">Search</button>'
			+ '</div>'
			+ '<pre class="wudt-pre" id="wudt-db-search-result"></pre>';
	}

	function renderDbInsert() {
		var cols = state.db.columns || [];
		var fields = '';
		for (var i = 0; i < cols.length; i++) {
			var name = cols[i].Field || cols[i];
			fields += '<label class="wudt-db-field"><span>' + esc(name) + '</span><input class="wudt-input wudt-db-insert-input" data-col="' + esc(name) + '"></label>';
		}
		return '<div class="wudt-db-form">' + fields + '</div><div class="wudt-toolbar"><button class="button button-primary" id="wudt-db-insert-run">Insert Row</button></div>';
	}

	function renderDbExport() {
		return '<div class="wudt-toolbar">'
			+ '<select id="wudt-db-export-format" class="wudt-select"><option value="sql">SQL</option><option value="csv">CSV</option><option value="json">JSON</option></select>'
			+ '<select id="wudt-db-export-compress" class="wudt-select"><option value="">No Compression</option><option value="zip">ZIP</option></select>'
			+ '<button class="button" id="wudt-db-export-run">Download Export</button>'
			+ '</div>';
	}

	function renderDbOperations() {
		return '<div class="wudt-toolbar">'
			+ '<button class="button" data-db-op="optimize">Optimize</button>'
			+ '<button class="button" data-db-op="repair">Repair</button>'
			+ '<button class="button" data-db-op="empty">Empty (TRUNCATE)</button>'
			+ '<button class="button button-link-delete" data-db-op="drop">Drop Table</button>'
			+ '</div><pre class="wudt-pre" id="wudt-db-op-result"></pre>';
	}

	function renderMalware() {
		var d = tabData('malware_scanner');
		return '<div class="wudt-card"><div class="wudt-toolbar"><button class="button button-primary" id="wudt-malware-scan">Run Scan</button></div><pre class="wudt-pre" id="wudt-malware-result">' + esc(JSON.stringify(d, null, 2)) + '</pre></div>';
	}

	function bind() {
		$('.wudt-tab').on('click', function () { state.tab = $(this).data('tab'); render(); });
		$('#wudt-pro-refresh').on('click', function () {
			status('Refreshing...');
			post('wudt_pro_refresh_dashboard').done(function (r) {
				if (r && r.success) {
					state.data = r.data;
					render();
					status('Refreshed');
				}
			});
		});

		$('#wudt-fm-open').on('click', function () {
			status('Loading directory...');
			post('wudt_fm_list', { path: $('#wudt-fm-path').val() }).done(function (r) {
				if (r && r.success) {
					$('#wudt-fm-list').text(JSON.stringify(r.data.entries, null, 2));
					state.currentPath = r.data.path;
					status('Directory loaded');
				}
			});
		});
		$('#wudt-fm-read').on('click', function () {
			post('wudt_fm_read', { path: $('#wudt-fm-file-path').val() }).done(function (r) {
				if (r && r.success) { $('#wudt-fm-editor').val(r.data.content); }
			});
		});
		$('#wudt-fm-save').on('click', function () {
			post('wudt_fm_write', { path: $('#wudt-fm-file-path').val(), content: $('#wudt-fm-editor').val() }).done(function (r) {
				alert((r && r.data && r.data.message) ? r.data.message : 'Saved');
			});
		});
		$('#wudt-fm-search').on('click', function () {
			post('wudt_fm_search', { path: $('#wudt-fm-path').val(), query: $('#wudt-fm-search-text').val() }).done(function (r) {
				if (r && r.success) { $('#wudt-fm-list').text(JSON.stringify(r.data.results, null, 2)); }
			});
		});
		$('#wudt-fm-zip').on('click', function () {
			post('wudt_fm_download_zip', { path: $('#wudt-fm-path').val() }).done(function (r) {
				if (r && r.success) { window.open(r.data.url, '_blank'); }
			});
		});

		$(document).off('click.wudtdb').on('click.wudtdb', '.wudt-db-tab', function () {
			state.db.tab = String($(this).data('db-tab'));
			if (state.db.tab === 'browse') { loadDbBrowse(); }
			if (state.db.tab === 'structure') { loadDbStructure(); }
			render();
		});
		$(document).off('click.wudtdbtree').on('click.wudtdbtree', '.wudt-db-tree-item', function () {
			state.db.selectedTable = String($(this).data('db-table'));
			state.db.page = 1;
			state.db.search = '';
			state.db.sortBy = '';
			state.db.sortDir = 'ASC';
			state.db.perPage = 0;
			loadDbStructure();
			loadDbBrowse();
		});
		$('#wudt-db-refresh').on('click', function () { loadDbTables(true); });
		$('#wudt-db-safe-mode').on('change', function () {
			var enabled = this.checked ? '1' : '0';
			post('wudt_dbm_toggle_safe_mode', { safe_mode: enabled }).done(function () {
				state.db.safeMode = enabled === '1';
			});
		});
		$('#wudt-db-browse-run').on('click', function () {
			state.db.search = $('#wudt-db-browse-search').val();
			state.db.perPage = parseInt($('#wudt-db-browse-per-page').val(), 10);
			if (isNaN(state.db.perPage) || state.db.perPage < 0) { state.db.perPage = 0; }
			state.db.page = 1;
			loadDbBrowse();
		});
		$('#wudt-db-page-prev').on('click', function () {
			if (state.db.perPage === 0) { return; }
			state.db.page = Math.max(1, state.db.page - 1);
			loadDbBrowse();
		});
		$('#wudt-db-page-next').on('click', function () {
			if (state.db.perPage === 0) { return; }
			var maxPage = Math.max(1, Math.ceil((state.db.total || 0) / (state.db.perPage || 20)));
			state.db.page = Math.min(maxPage, state.db.page + 1);
			loadDbBrowse();
		});
		$(document).on('click', '[data-db-sort]', function () {
			var col = String($(this).data('db-sort'));
			if (state.db.sortBy === col) {
				state.db.sortDir = state.db.sortDir === 'ASC' ? 'DESC' : 'ASC';
			} else {
				state.db.sortBy = col;
				state.db.sortDir = 'ASC';
			}
			loadDbBrowse();
		});
		$('#wudt-db-structure-refresh').on('click', function () { loadDbStructure(); });
		$('#wudt-db-add-col').on('click', function () {
			var name = prompt('Column name');
			var type = prompt('Column type (e.g. VARCHAR(255))', 'VARCHAR(255)');
			if (!name || !type) { return; }
			post('diagnostics_db_query', { query: 'ALTER TABLE `' + state.db.selectedTable + '` ADD `' + name + '` ' + type }).done(function (r) {
				$('#wudt-db-op-result').text(JSON.stringify(r, null, 2));
				loadDbStructure();
			});
		});
		$(document).on('click', '.wudt-db-drop-col', function () {
			var col = String($(this).data('col'));
			if (!confirm('Drop column ' + col + '?')) { return; }
			post('diagnostics_db_query', { query: 'ALTER TABLE `' + state.db.selectedTable + '` DROP COLUMN `' + col + '`' }).done(function (r) {
				$('#wudt-db-op-result').text(JSON.stringify(r, null, 2));
				loadDbStructure();
			});
		});
		$('#wudt-db-sql-run').on('click', function () {
			var query = $('#wudt-db-sql-editor').val();
			post('diagnostics_db_query', { query: query }).done(function (r) {
				state.db.lastQueryResult = r;
				if (r && r.success && r.data && r.data.history) { state.db.queryHistory = r.data.history; }
				render();
			});
		});
		$('#wudt-db-search-run').on('click', function () {
			var text = $('#wudt-db-search-text').val();
			state.db.search = text;
			state.db.page = 1;
			loadDbBrowse(function () {
				$('#wudt-db-search-result').text(JSON.stringify({ rows: state.db.browseRows, total: state.db.total }, null, 2));
			});
		});
		$('#wudt-db-insert-run').on('click', function () {
			var payload = {};
			$('.wudt-db-insert-input').each(function () {
				payload[String($(this).data('col'))] = $(this).val();
			});
			post('diagnostics_db_insert', { table: state.db.selectedTable, data: JSON.stringify(payload) }).done(function (r) {
				state.db.lastQueryResult = r;
				alert(r && r.success ? 'Row inserted' : 'Insert failed');
				loadDbBrowse();
			});
		});
		$('#wudt-db-export-run').on('click', function () {
			post('diagnostics_db_export', {
				table: state.db.selectedTable,
				format: $('#wudt-db-export-format').val(),
				compression: $('#wudt-db-export-compress').val()
			}).done(function (r) {
				if (r && r.success) { window.open(r.data.url, '_blank'); }
			});
		});
		$(document).on('click', '[data-db-op]', function () {
			var op = String($(this).data('db-op'));
			var confirmRequired = (op === 'empty' || op === 'drop') ? confirm('Confirm ' + op + ' operation?') : true;
			if (!confirmRequired) { return; }
			post('diagnostics_db_operations', { table: state.db.selectedTable, operation: op, confirm: '1' }).done(function (r) {
				$('#wudt-db-op-result').text(JSON.stringify(r, null, 2));
				loadDbTables();
			});
		});
		$(document).on('click', '.wudt-db-row-delete', function () {
			var idx = parseInt($(this).attr('data-row-index'), 10);
			var row = state.db.browseRows[idx];
			if (!row) { alert('Row not found.'); return; }
			var pk = state.db.primaryKey;
			if (!pk || typeof row[pk] === 'undefined') { alert('Primary key required to delete row.'); return; }
			if (!confirm('Delete selected row?')) { return; }
			var where = {}; where[pk] = row[pk];
			post('diagnostics_db_delete', { table: state.db.selectedTable, where: JSON.stringify(where) }).done(function (r) {
				state.db.lastQueryResult = r;
				loadDbBrowse();
			});
		});
		$(document).on('click', '.wudt-db-row-copy', function () {
			var idx = parseInt($(this).attr('data-row-index'), 10);
			var row = $.extend({}, state.db.browseRows[idx] || {});
			if (!row || Object.keys(row).length === 0) { alert('Row not found.'); return; }
			var pk = state.db.primaryKey;
			if (pk && typeof row[pk] !== 'undefined') { delete row[pk]; }
			post('diagnostics_db_insert', { table: state.db.selectedTable, data: JSON.stringify(row) }).done(function () { loadDbBrowse(); });
		});
		$(document).on('click', '.wudt-db-row-edit', function () {
			var idx = parseInt($(this).attr('data-row-index'), 10);
			var row = state.db.browseRows[idx];
			if (!row) { alert('Row not found.'); return; }
			var updated = {};
			for (var k in row) {
				if (!Object.prototype.hasOwnProperty.call(row, k)) { continue; }
				updated[k] = prompt('Edit ' + k, row[k]);
			}
			var pk = state.db.primaryKey;
			if (!pk || typeof row[pk] === 'undefined') { alert('Primary key required to update row.'); return; }
			var where = {}; where[pk] = row[pk];
			post('diagnostics_db_update', { table: state.db.selectedTable, data: JSON.stringify(updated), where: JSON.stringify(where) }).done(function () { loadDbBrowse(); });
		});
		$('#wudt-malware-scan').on('click', function () {
			post('wudt_malware_scan').done(function (r) {
				$('#wudt-malware-result').text(JSON.stringify(r, null, 2));
			});
		});

		$('.wudt-fm-crumb').on('click', function () {
			loadDirectory($(this).data('path'), true);
		});
		$('[data-open-path]').on('click', function (e) {
			e.stopPropagation();
			loadDirectory($(this).data('open-path'), true);
		});
		$('[data-expand-key]').on('click', function (e) {
			e.stopPropagation();
			var key = String($(this).data('expand-key'));
			state.sidebarExpanded[key] = !state.sidebarExpanded[key];
			render();
		});
		$('#wudt-fm-refresh').on('click', function () { loadDirectory(state.currentPath, false); });
		$('#wudt-fm-back').on('click', function () {
			if (state.historyIndex > 0) {
				state.historyIndex--;
				loadDirectory(state.history[state.historyIndex], false);
			}
		});
		$('#wudt-fm-forward').on('click', function () {
			if (state.historyIndex < state.history.length - 1) {
				state.historyIndex++;
				loadDirectory(state.history[state.historyIndex], false);
			}
		});
		$('#wudt-fm-up').on('click', function () {
			var current = normalizePath(state.currentPath);
			var parent = current.substring(0, current.lastIndexOf('/')) || '/';
			loadDirectory(parent, true);
		});
		$('#wudt-fm-toggle-view').on('click', function () {
			state.fileViewMode = state.fileViewMode === 'grid' ? 'details' : 'grid';
			render();
		});
		$('#wudt-fm-dark').on('click', function () {
			state.darkExplorer = !state.darkExplorer;
			render();
		});
		$('#wudt-fm-search-text').on('input', function () {
			state.fileSearch = $(this).val();
			render();
		});
		$('#wudt-fm-new-folder').on('click', function () {
			var name = prompt('Folder name');
			if (!name) { return; }
			var target = normalizePath(state.currentPath + '/' + name);
			post('wudt_fm_create', { path: target, entry_type: 'dir' }).done(function () {
				loadDirectory(state.currentPath, false);
			});
		});
		$('#wudt-fm-upload-trigger').on('click', function () { $('#wudt-fm-upload-file').trigger('click'); });
		$('#wudt-fm-upload-file').on('change', function () {
			var file = this.files && this.files[0];
			if (!file) { return; }
			var fd = new FormData();
			fd.append('action', 'wudt_fm_upload');
			fd.append('nonce', window.wudtProAdmin.nonce);
			fd.append('path', state.currentPath);
			fd.append('file', file);
			status('Uploading...');
			$.ajax({
				url: window.wudtProAdmin.ajaxUrl,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false
			}).done(function () {
				loadDirectory(state.currentPath, false);
				status('Upload complete');
			});
		});
		$('#wudt-fm-download').on('click', function () {
			var path = state.selected.length ? state.selected[0] : state.currentPath;
			post('wudt_fm_download_zip', { path: path }).done(function (r) {
				if (r && r.success) { window.open(r.data.url, '_blank'); }
			});
		});
		$('#wudt-ed-restore').on('click', function () {
			state.editor.minimized = false;
			render();
		});
		$('#wudt-ed-min').on('click', function () {
			state.editor.minimized = true;
			render();
		});
		$('#wudt-ed-max').on('click', function () {
			state.editor.maximized = !state.editor.maximized;
			render();
		});
		$('#wudt-ed-close,#wudt-ed-close-footer').on('click', function () {
			closeEditorWindow();
		});
		$('#wudt-ed-save').on('click', function () {
			saveEditor(false);
		});
		$('#wudt-ed-save-as').on('click', function () {
			saveEditor(true);
		});
		$('#wudt-ed-format').on('click', function () {
			state.editor.content = formatEditorContent(state.editor.content, state.editor.language);
			render();
		});
		$('#wudt-ed-validate').on('click', function () {
			var out = validateEditorContent(state.editor.content, state.editor.language);
			alert(out.valid ? 'Validation passed' : ('Validation errors:\n' + out.errors.join('\n')));
		});
		$('#wudt-ed-wrap').on('click', function () {
			state.editor.wordWrap = !state.editor.wordWrap;
			render();
		});
		$('#wudt-ed-textarea').on('input', function () {
			state.editor.content = $(this).val();
		});

		$('#wudt-ed-header').off('mousedown.wudted').on('mousedown.wudted', function (e) {
			if (state.editor.maximized) { return; }
			state.editor.dragging = true;
			state.editor.dragOffsetX = e.pageX - state.editor.x;
			state.editor.dragOffsetY = e.pageY - state.editor.y;
		});
		$(document).off('mousemove.wudted').on('mousemove.wudted', function (e) {
			if (!state.editor.dragging || state.editor.maximized) { return; }
			state.editor.x = Math.max(0, e.pageX - state.editor.dragOffsetX);
			state.editor.y = Math.max(32, e.pageY - state.editor.dragOffsetY);
			$('#wudt-editor-window').css({ left: state.editor.x + 'px', top: state.editor.y + 'px' });
		});
		$(document).off('mouseup.wudted').on('mouseup.wudted', function () {
			state.editor.dragging = false;
		});

		$(document).off('click.wudtfm').on('click.wudtfm', function () {
			$('#wudt-fm-context').hide().empty();
		});
		$('#wudt-fm-content-drop').on('contextmenu', function (e) {
			e.preventDefault();
			if ($(e.target).closest('.wudt-fm-row,.wudt-fm-card').length) {
				return;
			}
			showContextMenu(e.clientX, e.clientY, null);
		});
		$('.wudt-fm-row,.wudt-fm-card').on('contextmenu', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var itemPath = String($(this).data('path'));
			if (state.selected.indexOf(itemPath) === -1) {
				state.selected = [itemPath];
				render();
				return;
			}
			showContextMenu(e.clientX, e.clientY, itemPath);
		});
		$('.wudt-fm-row,.wudt-fm-card').on('click', function (e) {
			var path = String($(this).data('path'));
			var idx = parseInt($(this).data('index'), 10);
			if (e.shiftKey && state.lastSelected > -1) {
				var entries = filteredEntries();
				var start = Math.min(state.lastSelected, idx);
				var end = Math.max(state.lastSelected, idx);
				state.selected = [];
				for (var i = start; i <= end; i++) {
					state.selected.push(entries[i].path);
				}
			} else if (e.ctrlKey || e.metaKey) {
				if (state.selected.indexOf(path) > -1) {
					state.selected = state.selected.filter(function (p) { return p !== path; });
				} else {
					state.selected.push(path);
				}
				state.lastSelected = idx;
			} else {
				state.selected = [path];
				state.lastSelected = idx;
			}
			if (state.fileClickTimer) {
				clearTimeout(state.fileClickTimer);
			}
			state.fileClickTimer = setTimeout(function () {
				render();
				state.fileClickTimer = null;
			}, 250);
		});
		$('.wudt-fm-row,.wudt-fm-card').on('dblclick', function () {
			if (state.fileClickTimer) {
				clearTimeout(state.fileClickTimer);
				state.fileClickTimer = null;
			}
			var p = String($(this).data('path'));
			var t = String($(this).data('type'));
			if (t === 'dir') {
				loadDirectory(p, true);
				return;
			}
			editFile(p);
		});

		$('.wudt-fm-row,.wudt-fm-card').on('dragstart', function (e) {
			e.originalEvent.dataTransfer.setData('text/plain', String($(this).data('path')));
		});
		$('.wudt-fm-row[data-type="dir"],.wudt-fm-card[data-type="dir"],#wudt-fm-content-drop').on('dragover', function (e) {
			e.preventDefault();
			$(this).addClass('is-drop-target');
		});
		$('.wudt-fm-row[data-type="dir"],.wudt-fm-card[data-type="dir"],#wudt-fm-content-drop').on('dragleave', function () {
			$(this).removeClass('is-drop-target');
		});
		$('.wudt-fm-row[data-type="dir"],.wudt-fm-card[data-type="dir"],#wudt-fm-content-drop').on('drop', function (e) {
			e.preventDefault();
			$(this).removeClass('is-drop-target');
			var sourcePath = e.originalEvent.dataTransfer.getData('text/plain');
			var targetDir = String($(this).data('path') || state.currentPath);
			if (!sourcePath) { return; }
			var base = pathBasename(sourcePath);
			var destination = normalizePath(targetDir + '/' + base);
			post('wudt_fm_rename', { old_path: sourcePath, new_path: destination }).done(function () {
				loadDirectory(state.currentPath, false);
			});
		});

		if (state.tab === 'database_manager' && !state.db._loadedOnce) {
			state.db._loadedOnce = true;
			loadDbTables(true);
		}
	}

	function loadDbTables(alsoLoadCurrent) {
		post('diagnostics_db_tables').done(function (r) {
			if (!r || !r.success) { return; }
			state.db.tables = r.data.tables || [];
			state.db.queryHistory = r.data.query_history || [];
			state.db.safeMode = !!r.data.safe_mode;
			if (!state.db.selectedTable && state.db.tables.length) {
				state.db.selectedTable = state.db.tables[0].name;
			}
			render();
			if (alsoLoadCurrent && state.db.selectedTable) {
				loadDbStructure();
				loadDbBrowse();
			}
		});
	}

	function loadDbBrowse(done) {
		if (!state.db.selectedTable) { return; }
		post('diagnostics_db_browse', {
			table: state.db.selectedTable,
			page: state.db.page,
			per_page: state.db.perPage,
			search: state.db.search,
			sort_by: state.db.sortBy,
			sort_dir: state.db.sortDir
		}).done(function (r) {
			if (!r || !r.success) { return; }
			state.db.browseRows = r.data.rows || [];
			state.db.total = r.data.total || 0;
			state.db.columns = r.data.columns || [];
			state.db.primaryKey = r.data.primary_key || '';
			if (state.db.perPage > 0) {
				$('#wudt-db-browse-per-page').val(String(state.db.perPage));
			}
			render();
			if (typeof done === 'function') { done(); }
		});
	}

	function loadDbStructure() {
		if (!state.db.selectedTable) { return; }
		post('diagnostics_db_structure', { table: state.db.selectedTable }).done(function (r) {
			if (!r || !r.success) { return; }
			state.db.structure = r.data.structure || [];
			render();
		});
	}

	function editFile(path) {
		post('wudt_fm_read', { path: path }).done(function (r) {
			if (!r || !r.success) { return; }
			openEditorWindow(path, String(r.data.content || ''));
		});
	}

	function openEditorWindow(path, content) {
		if (state.editor.visible && state.editor.path === path) {
			state.editor.minimized = false;
			render();
			return;
		}
		if (state.editor.visible && isEditorDirty()) {
			var proceed = confirm('Another file is open with unsaved changes. Continue and discard?');
			if (!proceed) { return; }
		}
		state.editor.visible = true;
		state.editor.minimized = false;
		state.editor.maximized = false;
		state.editor.path = path;
		state.editor.title = pathBasename(path);
		state.editor.content = content;
		state.editor.original = content;
		state.editor.language = detectLanguageFromPath(path);
		render();
	}

	function closeEditorWindow() {
		if (isEditorDirty()) {
			var saveFirst = confirm('You have unsaved changes. Press OK to save before close, Cancel to close without saving.');
			if (saveFirst) {
				saveEditor(false, function () {
					resetEditorWindow();
				});
				return;
			}
		}
		resetEditorWindow();
	}

	function resetEditorWindow() {
		state.editor.visible = false;
		state.editor.minimized = false;
		state.editor.maximized = false;
		state.editor.path = '';
		state.editor.title = '';
		state.editor.content = '';
		state.editor.original = '';
		render();
	}

	function isEditorDirty() {
		return state.editor.visible && String(state.editor.content) !== String(state.editor.original);
	}

	function saveEditor(saveAs, onDone) {
		var targetPath = state.editor.path;
		if (saveAs) {
			var ask = prompt('Save as path', targetPath);
			if (!ask) { return; }
			targetPath = normalizePath(ask);
		}
		var validation = validateEditorContent(state.editor.content, state.editor.language);
		if (!validation.valid) {
			var proceed = confirm('Validation reported issues:\n' + validation.errors.join('\n') + '\n\nSave anyway?');
			if (!proceed) { return; }
		}
		post('wudt_fm_write', { path: targetPath, content: state.editor.content }).done(function (r) {
			if (!r || !r.success) {
				alert((r && r.data && r.data.message) ? r.data.message : 'Save failed');
				return;
			}
			state.editor.path = targetPath;
			state.editor.title = pathBasename(targetPath);
			state.editor.original = state.editor.content;
			status('Saved: ' + state.editor.title);
			loadDirectory(state.currentPath, false);
			render();
			if (typeof onDone === 'function') { onDone(); }
		});
	}

	function detectLanguageFromPath(path) {
		var ext = String(path || '').split('.').pop().toLowerCase();
		if (ext === 'php') { return 'php'; }
		if (ext === 'js' || ext === 'ts') { return 'javascript'; }
		if (ext === 'css' || ext === 'scss' || ext === 'less') { return 'css'; }
		if (ext === 'html' || ext === 'htm') { return 'html'; }
		return 'text';
	}

	function validateEditorContent(content, language) {
		var out = { valid: true, errors: [] };
		var txt = String(content || '');
		if (language === 'php') {
			var open = (txt.match(/<\?php/g) || []).length;
			var close = (txt.match(/\?>/g) || []).length;
			if (open > 0 && close > open) {
				out.valid = false;
				out.errors.push('Unexpected PHP close tag count.');
			}
		}
		if (language === 'javascript') {
			var stackJs = 0;
			for (var i = 0; i < txt.length; i++) {
				if (txt[i] === '{') { stackJs++; }
				if (txt[i] === '}') { stackJs--; }
			}
			if (stackJs !== 0) {
				out.valid = false;
				out.errors.push('Unbalanced curly braces in JavaScript.');
			}
		}
		if (language === 'css') {
			var openCss = (txt.match(/\{/g) || []).length;
			var closeCss = (txt.match(/\}/g) || []).length;
			if (openCss !== closeCss) {
				out.valid = false;
				out.errors.push('Unbalanced CSS braces.');
			}
		}
		if (language === 'html') {
			var opens = (txt.match(/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/g) || []).length;
			var closes = (txt.match(/<\/([a-zA-Z][a-zA-Z0-9]*)>/g) || []).length;
			if (closes > opens) {
				out.valid = false;
				out.errors.push('More closing tags than opening tags in HTML.');
			}
		}
		return out;
	}

	function formatEditorContent(content, language) {
		var txt = String(content || '').replace(/\r\n/g, '\n');
		if (language === 'javascript' || language === 'css' || language === 'php') {
			var lines = txt.split('\n');
			var depth = 0;
			for (var i = 0; i < lines.length; i++) {
				var line = lines[i].trim();
				if (line.indexOf('}') === 0) { depth = Math.max(0, depth - 1); }
				lines[i] = new Array(depth + 1).join('\t') + line;
				if (line.indexOf('{') !== -1 && line.lastIndexOf('{') > line.lastIndexOf('}')) { depth++; }
			}
			return lines.join('\n');
		}
		if (language === 'html') {
			return txt.replace(/>\s*</g, '>\n<');
		}
		return txt;
	}

	function showContextMenu(x, y, itemPath) {
		var menu = $('#wudt-fm-context');
		var item = null;
		var entries = getCurrentEntries();
		for (var i = 0; i < entries.length; i++) {
			if (entries[i].path === itemPath) {
				item = entries[i];
				break;
			}
		}
		var html = '<div class="wudt-fm-menu">';
		if (item) {
			html += '<button data-act="open">Open</button>';
			if (item.type !== 'dir') { html += '<button data-act="edit">Edit</button>'; }
			html += '<button data-act="rename">Rename</button>';
			html += '<button data-act="delete">Delete</button>';
			html += '<button data-act="download">Download</button>';
			html += '<button data-act="copy_path">Copy Path</button>';
			html += '<button data-act="properties">Properties</button>';
		} else {
			html += '<button data-act="new_folder">New Folder</button>';
			html += '<button data-act="upload">Upload File</button>';
			html += '<button data-act="refresh">Refresh</button>';
			html += '<button data-act="paste">Paste</button>';
		}
		html += '</div>';
		menu.html(html).show();
		var menuBox = menu.find('.wudt-fm-menu');
		var maxLeft = Math.max(8, window.innerWidth - menuBox.outerWidth() - 8);
		var maxTop = Math.max(8, window.innerHeight - menuBox.outerHeight() - 8);
		var left = Math.min(Math.max(8, x), maxLeft);
		var top = Math.min(Math.max(8, y), maxTop);
		menu.css({ left: left + 'px', top: top + 'px' });
		menu.find('button').on('click', function () {
			var act = String($(this).data('act'));
			menu.hide().empty();
			handleContextAction(act, item);
		});
	}

	function handleContextAction(action, item) {
		if (action === 'open' && item) {
			if (item.type === 'dir') { loadDirectory(item.path, true); } else { editFile(item.path); }
			return;
		}
		if (action === 'edit' && item) { editFile(item.path); return; }
		if (action === 'rename' && item) {
			var name = prompt('New name', item.name);
			if (!name) { return; }
			var parent = item.path.substring(0, item.path.lastIndexOf('/'));
			post('wudt_fm_rename', { old_path: item.path, new_path: normalizePath(parent + '/' + name) }).done(function () {
				loadDirectory(state.currentPath, false);
			});
			return;
		}
		if (action === 'delete' && item) {
			if (!confirm('Delete ' + item.name + '?')) { return; }
			post('wudt_fm_delete', { path: item.path }).done(function () { loadDirectory(state.currentPath, false); });
			return;
		}
		if (action === 'download' && item) {
			post('wudt_fm_download_zip', { path: item.path }).done(function (r) { if (r && r.success) { window.open(r.data.url, '_blank'); } });
			return;
		}
		if (action === 'copy_path' && item) {
			navigator.clipboard.writeText(item.path);
			return;
		}
		if (action === 'properties' && item) {
			alert('Name: ' + item.name + '\nType: ' + item.type + '\nSize: ' + formatSize(item.size) + '\nPath: ' + item.path + '\nPermissions: ' + item.permissions);
			return;
		}
		if (action === 'new_folder') { $('#wudt-fm-new-folder').trigger('click'); return; }
		if (action === 'upload') { $('#wudt-fm-upload-trigger').trigger('click'); return; }
		if (action === 'refresh') { $('#wudt-fm-refresh').trigger('click'); return; }
		if (action === 'paste') {
			if (!state.clipboardPath) { return; }
			var base = pathBasename(state.clipboardPath);
			post('wudt_fm_rename', { old_path: state.clipboardPath, new_path: normalizePath(state.currentPath + '/' + base) }).done(function () {
				loadDirectory(state.currentPath, false);
			});
		}
	}

	$(function () { render(); });
})(jQuery);
