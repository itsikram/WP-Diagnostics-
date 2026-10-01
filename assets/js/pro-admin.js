(function ($) {
	'use strict';

	var bootData = (window.wudtProAdmin && window.wudtProAdmin.data) ? window.wudtProAdmin.data : { tabs: [] };

	// Get initial tab from URL hash or default to dashboard
	function getInitialTab() {
		var hash = window.location.hash.replace('#', '');
		var validTabs = ['dashboard', 'ai_assistant', 'backup_suite', 'restore_suite', 'file_manager', 'database_manager', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp', 'site_migration'];
		if (hash && validTabs.indexOf(hash) !== -1) {
			return hash;
		}
		return (window.wudtProAdmin && window.wudtProAdmin.defaultTab) ? window.wudtProAdmin.defaultTab : 'dashboard';
	}

	var state = {
		data: bootData,
		tab: getInitialTab(),
		tabLoading: false,
		fmLoading: false,
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
		// Restore suite state to persist selections across re-renders
		restore: {
			selectedPath: '',
			safeMode: true,
			preservePlugins: true,
			components: ['plugins', 'themes', 'database'],
			mediaBase: '',
			downloadUrl: '',
			downloadedBackup: null
		},
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
			safeMode: true,
			loading: false,
			loadingMessage: ''
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
			dragOffsetY: 0,
			loading: false,
			loadingMessage: ''
		},
		ai: {
			history: [],
			typing: false,
			executing: false,
			executionStatus: '',
			executionStarted: false,
			buffer: '',
			lastAction: null,
			lastActions: [],
			mode: 'ask',
			model: '',
			models: [],
			chatTranscript: '',
			newChat: false,
			autoExecute: false
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

	function ensureToastContainer() {
		if (!$('#wudt-toast-container').length) {
			$('body').append('<div id="wudt-toast-container" class="wudt-toast-container"></div>');
		}
	}

	function showToast(title, message, type, duration) {
		type = type || 'info';
		duration = duration || 4000;
		ensureToastContainer();
		var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
		var toastId = 'wudt-toast-' + Date.now();
		var toastHtml = ''
			+ '<div id="' + toastId + '" class="wudt-toast wudt-toast--' + type + '">'
			+ '<span class="wudt-toast__icon">' + icons[type] + '</span>'
			+ '<div class="wudt-toast__content">'
			+ '<div class="wudt-toast__title">' + esc(title) + '</div>'
			+ (message ? '<div class="wudt-toast__message">' + esc(message) + '</div>' : '')
			+ '</div>'
			+ '<button class="wudt-toast__close" data-toast-dismiss>✕</button>'
			+ '</div>';
		$('#wudt-toast-container').append(toastHtml);
		var $toast = $('#' + toastId);
		$toast.find('[data-toast-dismiss]').on('click', function () {
			$toast.fadeOut(200, function () { $toast.remove(); });
		});
		setTimeout(function () {
			$toast.fadeOut(300, function () { $toast.remove(); });
		}, duration);
	}

	function post(action, data) {
		data = data || {};
		if (!window.wudtProAdmin) {
			return $.Deferred().reject({ success: false, data: { message: 'wudtProAdmin missing' } });
		}
		return $.post(window.wudtProAdmin.ajaxUrl, $.extend({ action: action, nonce: window.wudtProAdmin.nonce }, data));
	}

	/**
	 * File manager request: shows the server's error message on failure and
	 * resolves only on success.
	 */
	function fmPost(action, data, successTitle) {
		var d = $.Deferred();
		post(action, data).done(function (r) {
			if (r && r.success) {
				if (successTitle) {
					showToast(successTitle, (r.data && r.data.message) ? r.data.message : '', 'success', 3000);
				}
				d.resolve(r);
			} else {
				var msg = (r && r.data && r.data.message) ? r.data.message : 'The operation failed.';
				showToast('File manager', msg, 'error', 7000);
				status(msg);
				d.reject(r);
			}
		}).fail(function (xhr) {
			var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: 'Server error (HTTP ' + (xhr ? xhr.status : '?') + '). Check the PHP error log.';
			showToast('File manager', msg, 'error', 7000);
			status(msg);
			d.reject(xhr);
		});
		return d.promise();
	}

	function render() {
		var tabs = ['dashboard', 'ai_assistant', 'backup_suite', 'restore_suite', 'file_manager', 'database_manager', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp', 'site_migration'];
		var tabsHtml = '';
		for (var i = 0; i < tabs.length; i++) {
			var t = tabs[i];
			var isActive = state.tab === t;
			// Use anchor links for proper URL handling
			tabsHtml += '<a href="#' + esc(t) + '" class="wudt-tab ' + (isActive ? 'is-active' : '') + '" data-tab="' + esc(t) + '">' + esc(titleCase(t)) + '</a>';
		}

		var panelContent = state.tabLoading ? renderLoadingPanel() : renderPanel();

		var html = ''
			+ '<div class="wudt-app-shell">'
			+ '<div class="wudt-toolbar">'
			+ '<span class="wudt-badge">Generated: ' + esc((state.data && state.data.generated_at) ? state.data.generated_at : 'N/A') + '</span>'
			+ '<button class="button button-primary" id="wudt-pro-refresh">Refresh</button>'
			+ '<span class="wudt-status" id="wudt-pro-status"></span>'
			+ '</div>'
			+ '<div class="wudt-tabs">' + tabsHtml + '</div>'
			+ '<div class="wudt-panel">' + panelContent + '</div>'
			+ '</div>';
		$('#wudt-pro-admin-app').html(html);
		bind();
		if (state.tab === 'site_migration' && !state.tabLoading) {
			mountSiteMigration();
		}
		if ((state.tab === 'backup_suite' || state.tab === 'restore_suite') && !state.tabLoading) {
			var backupRoot = document.getElementById('wudt-backup-root');
			if (backupRoot && window.WUDTBackup) {
				window.WUDTBackup.mount(backupRoot, state.tab === 'restore_suite' ? 'restore' : 'backup');
			}
		}
		if (state.tab === 'malware_enterprise' && !state.tabLoading) {
			var malwareRoot = document.getElementById('wudt-malware-root');
			if (malwareRoot && window.WUDTMalware) {
				window.WUDTMalware.mount(malwareRoot);
			}
		}
		if (state.tab === 'ai_assistant' && !state.tabLoading) {
			var agentRoot = document.getElementById('wudt-ai-agent-root');
			if (agentRoot && window.WUDTAgent) {
				window.WUDTAgent.mount(agentRoot);
			}
		}
	}

	function renderLoadingPanel() {
		return '<div class="wudt-tab-loading">'
			+ '<div class="wudt-tab-loading-spinner"></div>'
			+ '<p>Loading ' + esc(titleCase(state.tab)) + '...</p>'
			+ '</div>';
	}

	function renderDashboard() {
		var sys = tabData('system_info') || {};
		var sec = tabData('security') || {};
		var perf = tabData('performance') || {};
		
		// Calculate active plugins count
		var plugins = sys.plugins || [];
		var activePlugins = plugins.filter(function(p) { return p.active; }).length;
		var totalPlugins = plugins.length;
		
		// Security status
		var issues = sec.issues || [];
		var hasIssues = issues.length > 0;
		
		// Performance samples
		var samples = perf.latest_samples || [];
		var avgLoadTime = samples.length > 0 
			? Math.round(samples.reduce(function(sum, s) { return sum + (s.load_time_ms || 0); }, 0) / samples.length)
			: 0;
		var avgMemory = samples.length > 0
			? Math.round(samples.reduce(function(sum, s) { return sum + (s.memory_mb || 0); }, 0) / samples.length * 100) / 100
			: 0;
		
		return '<div class="wudt-dashboard">'
			// System Overview Section
			+ '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">📊 System Overview</h3>'
			+ '<div class="wudt-dashboard-grid">'
			// WordPress Card
			+ '<div class="wudt-dashboard-card wudt-card-wp">'
			+ '<div class="wudt-dashboard-icon">📝</div>'
			+ '<div class="wudt-dashboard-label">WordPress</div>'
			+ '<div class="wudt-dashboard-value">' + esc(sys.wordpress_version || 'Unknown') + '</div>'
			+ '</div>'
			// PHP Card
			+ '<div class="wudt-dashboard-card wudt-card-php">'
			+ '<div class="wudt-dashboard-icon">🐘</div>'
			+ '<div class="wudt-dashboard-label">PHP Version</div>'
			+ '<div class="wudt-dashboard-value">' + esc(sys.php_version || 'Unknown') + '</div>'
			+ '</div>'
			// MySQL Card
			+ '<div class="wudt-dashboard-card wudt-card-db">'
			+ '<div class="wudt-dashboard-icon">🗄️</div>'
			+ '<div class="wudt-dashboard-label">MySQL</div>'
			+ '<div class="wudt-dashboard-value">' + esc(sys.mysql_version || 'Unknown') + '</div>'
			+ '</div>'
			// Theme Card
			+ '<div class="wudt-dashboard-card wudt-card-theme">'
			+ '<div class="wudt-dashboard-icon">🎨</div>'
			+ '<div class="wudt-dashboard-label">Theme</div>'
			+ '<div class="wudt-dashboard-value" title="' + esc((sys.active_theme || {}).name || 'Unknown') + '">' 
			+ esc(((sys.active_theme || {}).name || 'Unknown').substring(0, 15)) + '</div>'
			+ '</div>'
			// Plugins Card
			+ '<div class="wudt-dashboard-card wudt-card-plugins">'
			+ '<div class="wudt-dashboard-icon">🔌</div>'
			+ '<div class="wudt-dashboard-label">Plugins</div>'
			+ '<div class="wudt-dashboard-value">' + activePlugins + '/' + totalPlugins + '</div>'
			+ '</div>'
			// Server Card
			+ '<div class="wudt-dashboard-card wudt-card-server">'
			+ '<div class="wudt-dashboard-icon">🖥️</div>'
			+ '<div class="wudt-dashboard-label">Server</div>'
			+ '<div class="wudt-dashboard-value" title="' + esc(sys.server_software || 'Unknown') + '">' 
			+ esc((sys.server_software || 'Unknown').split('/')[0]) + '</div>'
			+ '</div>'
			+ '</div>'
			+ '</div>'
			// Quick Status Section
			+ '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">⚡ Quick Status</h3>'
			+ '<div class="wudt-dashboard-grid">'
			// Security Card
			+ '<div class="wudt-dashboard-card ' + (hasIssues ? 'wudt-card-warning' : 'wudt-card-ok') + '">'
			+ '<div class="wudt-dashboard-icon">' + (hasIssues ? '⚠️' : '🔒') + '</div>'
			+ '<div class="wudt-dashboard-label">Security</div>'
			+ '<div class="wudt-dashboard-value">' + (hasIssues ? issues.length + ' Issues' : 'Secure') + '</div>'
			+ (hasIssues ? '<div class="wudt-dashboard-sub">' + esc(issues[0]) + '</div>' : '')
			+ '</div>'
			// Performance Card
			+ '<div class="wudt-dashboard-card wudt-card-perf">'
			+ '<div class="wudt-dashboard-icon">⚡</div>'
			+ '<div class="wudt-dashboard-label">Avg Load Time</div>'
			+ '<div class="wudt-dashboard-value">' + avgLoadTime + 'ms</div>'
			+ '</div>'
			// Memory Card
			+ '<div class="wudt-dashboard-card wudt-card-mem">'
			+ '<div class="wudt-dashboard-icon">🧠</div>'
			+ '<div class="wudt-dashboard-label">Avg Memory</div>'
			+ '<div class="wudt-dashboard-value">' + avgMemory + ' MB</div>'
			+ '</div>'
			// Samples Card
			+ '<div class="wudt-dashboard-card wudt-card-samples">'
			+ '<div class="wudt-dashboard-icon">📈</div>'
			+ '<div class="wudt-dashboard-label">Samples</div>'
			+ '<div class="wudt-dashboard-value">' + samples.length + '</div>'
			+ '</div>'
			+ '</div>'
			+ '</div>'
			// Issues List (if any)
			+ (hasIssues ? '<div class="wudt-dashboard-section"><div class="wudt-dashboard-issues">' 
				+ '<h4>⚠️ Security Issues</h4><ul>' 
				+ issues.map(function(i) { return '<li>' + esc(i) + '</li>'; }).join('')
				+ '</ul></div></div>' 
				: '')
			+ '</div>';
	}

	function renderPanel() {
		if (state.tab === 'dashboard') {
			return renderDashboard();
		}
		if (state.tab === 'logs') {
			return '<div class="wudt-card"><h3>Operations Logs</h3><pre class="wudt-pre">' + esc(JSON.stringify(tabData('logs'), null, 2)) + '</pre></div>';
		}
		if (state.tab === 'performance') { return renderPerformance(); }
		if (state.tab === 'security') { return renderSecurity(); }
		if (state.tab === 'backup_suite') { return renderBackupSuite(); }
		if (state.tab === 'restore_suite') { return renderRestoreSuite(); }
		if (state.tab === 'ai_assistant') { return renderAIAssistant(); }
		if (state.tab === 'file_manager') { return renderFileManager(); }
		if (state.tab === 'database_manager') { return renderDbManager(); }
		if (state.tab === 'malware_enterprise') { return renderMalwareEnterprise(); }
		if (state.tab === 'recovery') { return renderRecovery(); }
		if (state.tab === 'smtp') { return renderSMTP(); }
		if (state.tab === 'site_migration') { return renderSiteMigration(); }
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
		if (value < 0) { value = 0; }
		if (value === 0) { return '0 B'; }
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
		state.fmLoading = true;
		status('Loading folder...');
		render();
		post('wudt_fm_list', { path: path }).done(function (r) {
			state.fmLoading = false;
			if (!r || !r.success) {
				status('Failed to load folder');
				render();
				return;
			}
			var resolved = normalizePath(r.data.path || path);
			var parsed = parseListing(r.data.entries || []);
			state.currentPath = resolved;
			state.dirCache[resolved] = parsed;
			state.selected = [];
			state.lastSelected = -1;
			persistFileManagerState();
			if (pushHistory !== false) {
				updateHistory(resolved);
			}
			status('Folder ready');
			render();
			if (typeof done === 'function') { done(parsed); }
		}).fail(function () {
			state.fmLoading = false;
			status('Folder load failed');
			render();
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

		var listHtml = state.fmLoading
			? '<div class="wudt-fm-loading"><div class="wudt-fm-loading-spinner"></div><p>Loading folder contents...</p></div>'
			: (state.fileViewMode === 'grid'
				? '<div class="wudt-fm-grid" id="wudt-fm-items">' + renderFileCards(rows) + '</div>'
				: '<table class="wudt-fm-table"><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead><tbody id="wudt-fm-items">' + renderFileRows(rows) + '</tbody></table>');

		var compressBtn = state.selected.length > 0
			? '<button class="button button-primary" id="wudt-fm-compress-selected" title="Compress ' + state.selected.length + ' selected items">Compress (' + state.selected.length + ')</button>'
			: '';

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
			+ compressBtn
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
		var loadingOverlay = e.loading
			? '<div class="wudt-ed-loading"><div class="wudt-ed-loading-spinner"></div><span>' + esc(e.loadingMessage || 'Loading...') + '</span></div>'
			: '';
		return ''
			+ '<div class="' + cls + '" id="wudt-editor-window" ' + style + '>'
			+ '<div class="wudt-ed-header" id="wudt-ed-header">'
			+ '<div class="wudt-ed-title">' + esc(e.title || 'Untitled') + (dirty ? ' *' : '') + '</div>'
			+ '<div class="wudt-ed-controls">'
			+ '<button class="wudt-ed-btn" id="wudt-ed-min" ' + (e.loading ? 'disabled' : '') + '>➖</button>'
			+ '<button class="wudt-ed-btn" id="wudt-ed-max" ' + (e.loading ? 'disabled' : '') + '>🗖</button>'
			+ '<button class="wudt-ed-btn is-close" id="wudt-ed-close" ' + (e.loading ? 'disabled' : '') + '>✕</button>'
			+ '</div>'
			+ '</div>'
			+ '<div class="wudt-ed-meta">'
			+ '<span class="wudt-badge">' + esc(e.language.toUpperCase()) + '</span>'
			+ '<span class="wudt-status">' + esc(e.path) + '</span>'
			+ '</div>'
			+ '<div class="wudt-ed-body">'
			+ '<textarea id="wudt-ed-textarea" class="wudt-ed-textarea ' + (e.wordWrap ? 'is-wrap' : '') + '" ' + (e.loading ? 'disabled' : '') + '>' + String(e.content || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</textarea>'
			+ loadingOverlay
			+ '</div>'
			+ '<div class="wudt-ed-footer">'
			+ '<button class="button button-primary" id="wudt-ed-save" ' + (e.loading ? 'disabled' : '') + '>' + (e.loading ? '⏳ Saving...' : '💾 Save') + '</button>'
			+ '<button class="button" id="wudt-ed-save-as" ' + (e.loading ? 'disabled' : '') + '>💾 Save As</button>'
			+ '<button class="button" id="wudt-ed-format" ' + (e.loading ? 'disabled' : '') + '>🔄 Format</button>'
			+ '<button class="button" id="wudt-ed-validate" ' + (e.loading ? 'disabled' : '') + '>🧪 Validate</button>'
			+ '<button class="button" id="wudt-ed-wrap" ' + (e.loading ? 'disabled' : '') + '>' + (e.wordWrap ? 'Disable Wrap' : 'Enable Wrap') + '</button>'
			+ '<button class="button" id="wudt-ed-close-footer" ' + (e.loading ? 'disabled' : '') + '>❌ Close</button>'
			+ '</div>'
			+ '</div>';
	}

	function renderDbManager() {
		var d = tabData('database_manager');
		if (!state.db.tables.length) {
			var stats = d.table_stats || [];
			for (var i = 0; i < stats.length; i++) {
				state.db.tables.push({
					name: stats[i].table_name,
					rows: stats[i].table_rows,
					size: stats[i].data_length + (stats[i].index_length || 0)
				});
			}
		}
		if (!state.db.selectedTable && state.db.tables.length) {
			state.db.selectedTable = state.db.tables[0].name;
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
		
		// Loading overlay HTML
		var loadingOverlay = state.db.loading 
			? '<div class="wudt-db-loading-overlay"><div class="wudt-db-loading-spinner"></div><span class="wudt-db-loading-text">' + esc(state.db.loadingMessage || 'Loading...') + '</span></div>' 
			: '';
		
		return ''
			+ '<div class="wudt-db-shell">'
			+ '<aside class="wudt-db-sidebar">'
			+ '<div class="wudt-db-sidebar-head"><strong>Database Tables</strong><button class="button button-small" id="wudt-db-refresh" ' + (state.db.loading ? 'disabled' : '') + '>Refresh</button></div>'
			+ '<div class="wudt-db-tree">' + tree + '</div>'
			+ '</aside>'
			+ '<section class="wudt-db-main">'
			+ '<div class="wudt-db-topbar">'
			+ '<div><strong>' + esc(state.db.selectedTable || 'No table selected') + '</strong></div>'
			+ '<label class="wudt-badge"><input type="checkbox" id="wudt-db-safe-mode" ' + (state.db.safeMode ? 'checked' : '') + ' ' + (state.db.loading ? 'disabled' : '') + '> Safe Mode</label>'
			+ '</div>'
			+ '<div class="wudt-db-tabs">' + tabsHtml + '</div>'
			+ '<div class="wudt-db-body wudt-db-body-' + state.db.tab + '">'
			+ renderDbTabBody()
			+ loadingOverlay
			+ '</div>'
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

	function renderBackupSuite() {
		return '<div id="wudt-backup-root"></div>';
	}

	function renderRestoreSuite() {
		return '<div id="wudt-backup-root"></div>';
	}

	function renderAIAssistant() {
		return '<div id="wudt-ai-agent-root"></div>';
	}

	function renderMarkdownLite(text) {
		if (!text) { return ''; }
		var out = esc(text);
		// Code blocks with language support
		out = out.replace(/```(\w+)?\n?([\s\S]*?)```/g, function(match, lang, code) {
			return '<pre class="wudt-pre"><code>' + code.trim() + '</code></pre>';
		});
		// Inline code
		out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
		// Bold
		out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		out = out.replace(/__([^_]+)__/g, '<strong>$1</strong>');
		// Italic
		out = out.replace(/\*([^*]+)\*/g, '<em>$1</em>');
		out = out.replace(/_([^_]+)_/g, '<em>$1</em>');
		// Links
		out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
		// Lists
		out = out.replace(/^\s*[-*]\s+(.+)$/gm, '<li>$1</li>');
		out = out.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
		// Numbered lists
		out = out.replace(/^\s*\d+\.\s+(.+)$/gm, '<li>$1</li>');
		// Headers
		out = out.replace(/^###\s+(.+)$/gm, '<h4>$1</h4>');
		out = out.replace(/^##\s+(.+)$/gm, '<h3>$1</h3>');
		out = out.replace(/^#\s+(.+)$/gm, '<h2>$1</h2>');
		// Paragraphs (convert double newlines to paragraphs)
		out = out.replace(/\n\n/g, '</p><p>');
		// Single newlines to breaks (within paragraphs)
		out = out.replace(/\n/g, '<br>');
		// Wrap in paragraph if not already wrapped
		if (!out.match(/^<[hp]/)) {
			out = '<p>' + out + '</p>';
		}
		return out;
	}

	function renderMalwareEnterprise() {
		return '<div id="wudt-malware-root"></div>';
	}

	function renderPerformance() {
		var d = tabData('performance') || {};
		var samples = d.latest_samples || [];
		var largeAutoloaded = d.large_autoloaded || [];
		var optimizations = d.optimizations || [];
		
		// Calculate averages
		var avgLoadTime = 0, avgMemory = 0, avgQueries = 0;
		if (samples.length > 0) {
			avgLoadTime = Math.round(samples.reduce(function(s, x) { return s + (x.load_time_ms || 0); }, 0) / samples.length);
			avgMemory = Math.round(samples.reduce(function(s, x) { return s + (x.memory_mb || 0); }, 0) / samples.length * 100) / 100;
			avgQueries = Math.round(samples.reduce(function(s, x) { return s + (x.queries || 0); }, 0) / samples.length);
		}
		
		// Get latest sample
		var latest = samples.length > 0 ? samples[samples.length - 1] : null;
		
		var html = '<div class="wudt-dashboard">';
		
		// Performance Metrics Section
		html += '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">⚡ Performance Metrics</h3>'
			+ '<div class="wudt-dashboard-grid">';
		
		// Average Load Time
		html += '<div class="wudt-dashboard-card wudt-card-perf">'
			+ '<div class="wudt-dashboard-icon">⏱️</div>'
			+ '<div class="wudt-dashboard-label">Avg Load Time</div>'
			+ '<div class="wudt-dashboard-value">' + avgLoadTime + 'ms</div>'
			+ '</div>';
		
		// Average Memory
		html += '<div class="wudt-dashboard-card wudt-card-mem">'
			+ '<div class="wudt-dashboard-icon">🧠</div>'
			+ '<div class="wudt-dashboard-label">Avg Memory</div>'
			+ '<div class="wudt-dashboard-value">' + avgMemory + ' MB</div>'
			+ '</div>';
		
		// Average Queries
		html += '<div class="wudt-dashboard-card wudt-card-db">'
			+ '<div class="wudt-dashboard-icon">🗄️</div>'
			+ '<div class="wudt-dashboard-label">Avg Queries</div>'
			+ '<div class="wudt-dashboard-value">' + avgQueries + '</div>'
			+ '</div>';
		
		// Total Samples
		html += '<div class="wudt-dashboard-card wudt-card-samples">'
			+ '<div class="wudt-dashboard-icon">📊</div>'
			+ '<div class="wudt-dashboard-label">Total Samples</div>'
			+ '<div class="wudt-dashboard-value">' + samples.length + '</div>'
			+ '</div>';
		
		html += '</div></div>';
		
		// Latest Request Section
		if (latest) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">🔄 Latest Request</h3>'
				+ '<div class="wudt-dashboard-grid">';
			
			html += '<div class="wudt-dashboard-card">'
				+ '<div class="wudt-dashboard-icon">🔗</div>'
				+ '<div class="wudt-dashboard-label">URL</div>'
				+ '<div class="wudt-dashboard-value" title="' + esc(latest.url || '') + '">' + esc((latest.url || '-').substring(0, 30)) + '</div>'
				+ '</div>';
			
			html += '<div class="wudt-dashboard-card wudt-card-perf">'
				+ '<div class="wudt-dashboard-icon">⚡</div>'
				+ '<div class="wudt-dashboard-label">Load Time</div>'
				+ '<div class="wudt-dashboard-value">' + Math.round(latest.load_time_ms || 0) + 'ms</div>'
				+ '</div>';
			
			html += '<div class="wudt-dashboard-card wudt-card-mem">'
				+ '<div class="wudt-dashboard-icon">💾</div>'
				+ '<div class="wudt-dashboard-label">Memory</div>'
				+ '<div class="wudt-dashboard-value">' + (latest.memory_mb || 0) + ' MB</div>'
				+ '</div>';
			
			html += '<div class="wudt-dashboard-card wudt-card-db">'
				+ '<div class="wudt-dashboard-icon">📋</div>'
				+ '<div class="wudt-dashboard-label">Queries</div>'
				+ '<div class="wudt-dashboard-value">' + (latest.queries || 0) + '</div>'
				+ '</div>';
			
			html += '</div></div>';
		}
		
		// Large Autoloaded Options Section
		if (largeAutoloaded.length > 0) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">⚠️ Large Autoloaded Options</h3>'
				+ '<div class="wudt-dashboard-issues">'
				+ '<table class="wudt-fm-table"><thead><tr><th>Option Name</th><th>Size</th></tr></thead><tbody>';
			
			for (var i = 0; i < largeAutoloaded.length && i < 10; i++) {
				var opt = largeAutoloaded[i];
				html += '<tr>'
					+ '<td>' + esc(opt.option_name || '') + '</td>'
					+ '<td>' + formatSize(opt.size || 0) + '</td>'
					+ '</tr>';
			}
			
			html += '</tbody></table></div></div>';
		}
		
		// Recommendations Section
		if (optimizations.length > 0) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">💡 Recommendations</h3>'
				+ '<div class="wudt-dashboard-issues"><ul>';
			
			for (var j = 0; j < optimizations.length; j++) {
				html += '<li>' + esc(optimizations[j]) + '</li>';
			}
			
			html += '</ul></div></div>';
		}
		
		// Recent Samples Table
		if (samples.length > 0) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">📈 Recent Samples (Last 10)</h3>'
				+ '<div class="wudt-dashboard-issues">'
				+ '<table class="wudt-fm-table"><thead><tr><th>Time</th><th>URL</th><th>Load</th><th>Memory</th><th>Queries</th></tr></thead><tbody>';
			
			var recentSamples = samples.slice(-10).reverse();
			for (var k = 0; k < recentSamples.length; k++) {
				var s = recentSamples[k];
				html += '<tr>'
					+ '<td>' + esc((s.time || '').substring(0, 16)) + '</td>'
					+ '<td title="' + esc(s.url || '') + '">' + esc((s.url || '-').substring(0, 40)) + '</td>'
					+ '<td>' + Math.round(s.load_time_ms || 0) + 'ms</td>'
					+ '<td>' + (s.memory_mb || 0) + ' MB</td>'
					+ '<td>' + (s.queries || 0) + '</td>'
					+ '</tr>';
			}
			
			html += '</tbody></table></div></div>';
		}
		
		html += '</div>';
		return html;
	}

	function renderSecurity() {
		var d = tabData('security') || {};
		var issues = d.issues || [];
		var wpDebug = d.wp_debug || false;
		var fileIntegrity = d.file_integrity || 'N/A';
		var hasIssues = issues.length > 0;
		
		var html = '<div class="wudt-dashboard">';
		
		// Security Status Section
		html += '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">🔒 Security Status</h3>'
			+ '<div class="wudt-dashboard-grid">';
		
		// Overall Status
		html += '<div class="wudt-dashboard-card ' + (hasIssues ? 'wudt-card-warning' : 'wudt-card-ok') + '">'
			+ '<div class="wudt-dashboard-icon">' + (hasIssues ? '⚠️' : '✅') + '</div>'
			+ '<div class="wudt-dashboard-label">Overall Status</div>'
			+ '<div class="wudt-dashboard-value">' + (hasIssues ? issues.length + ' Issues' : 'Secure') + '</div>'
			+ '</div>';
		
		// WP_DEBUG Status
		html += '<div class="wudt-dashboard-card ' + (wpDebug ? 'wudt-card-warning' : 'wudt-card-ok') + '">'
			+ '<div class="wudt-dashboard-icon">🐛</div>'
			+ '<div class="wudt-dashboard-label">WP_DEBUG</div>'
			+ '<div class="wudt-dashboard-value">' + (wpDebug ? 'Enabled' : 'Disabled') + '</div>'
			+ (wpDebug ? '<div class="wudt-dashboard-sub">Disable in production</div>' : '')
			+ '</div>';
		
		// wp-config.php Permissions
		html += '<div class="wudt-dashboard-card">'
			+ '<div class="wudt-dashboard-icon">🔐</div>'
			+ '<div class="wudt-dashboard-label">wp-config.php</div>'
			+ '<div class="wudt-dashboard-value">' + esc(String(fileIntegrity)) + '</div>'
			+ '</div>';
		
		html += '</div></div>';
		
		// Issues List Section
		if (hasIssues) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">⚠️ Security Issues</h3>'
				+ '<div class="wudt-dashboard-issues">'
				+ '<ul>';
			
			for (var i = 0; i < issues.length; i++) {
				html += '<li>' + esc(issues[i]) + '</li>';
			}
			
			html += '</ul></div></div>';
		}
		
		// Security Tips Section
		html += '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">💡 Security Tips</h3>'
			+ '<div class="wudt-dashboard-issues">'
			+ '<ul>'
			+ '<li>Keep WordPress core, themes, and plugins updated</li>'
			+ '<li>Use strong passwords and two-factor authentication</li>'
			+ '<li>Limit login attempts to prevent brute force attacks</li>'
			+ '<li>Regularly backup your website</li>'
			+ '<li>Remove unused themes and plugins</li>'
			+ '</ul></div></div>';
		
		html += '</div>';
		return html;
	}

	function renderRecovery() {
		var d = tabData('recovery') || {};
		var events = d.events || [];
		var recentEvents = events.slice(-10).reverse();
		
		var html = '<div class="wudt-dashboard">';

		// Rescue access (works even when the site has a fatal error).
		var rescue = d.rescue || null;
		if (rescue) {
			html += '<div class="wudt-dashboard-section"><div class="wudt-card wudt-rescue-card">'
				+ '<h3>🛟 Emergency access (bookmark this)</h3>'
				+ '<p>If a plugin or theme crashes your site (“There has been a critical error”) and wp-admin will not load, open this private link. '
				+ 'It works without loading any plugin or theme: you can read the error, deactivate the culprit, switch theme, let the AI propose a fix, '
				+ 'or open WP Diagnostics in <strong>safe mode</strong> (all other plugins and the theme disabled for your browser only).</p>'
				+ '<div class="wudt-rescue-row"><input type="text" readonly class="regular-text code" id="wudt-rescue-url" value="' + esc(rescue.rescue_url) + '">'
				+ '<button class="button button-primary" id="wudt-rescue-copy">Copy link</button>'
				+ '<a class="button" target="_blank" rel="noopener" href="' + esc(rescue.rescue_url) + '">Open</a>'
				+ '<button class="button" id="wudt-rescue-regen">New link</button></div>'
				+ '<p class="description">Keep it secret — anyone with this link can manage plugins on this site. The link is also added to WordPress’s recovery-mode email. '
				+ 'Safe-mode loader: ' + (rescue.loader_active ? '<strong style="color:#008a20">installed</strong>' : '<strong style="color:#b32d2e">not installed</strong> (make wp-content/mu-plugins writable)') + '.</p>'
				+ '</div></div>';
		}

		// Recovery Status Section
		html += '<div class="wudt-dashboard-section">'
			+ '<h3 class="wudt-dashboard-title">🛡️ Crash Recovery</h3>'
			+ '<div class="wudt-dashboard-grid">';
		
		// Total Events
		html += '<div class="wudt-dashboard-card">'
			+ '<div class="wudt-dashboard-icon">📊</div>'
			+ '<div class="wudt-dashboard-label">Total Events</div>'
			+ '<div class="wudt-dashboard-value">' + events.length + '</div>'
			+ '</div>';
		
		// Recent Crashes
		var recentCrashes = events.filter(function(e) { return e.type === 'disabled'; }).length;
		html += '<div class="wudt-dashboard-card ' + (recentCrashes > 0 ? 'wudt-card-warning' : 'wudt-card-ok') + '">'
			+ '<div class="wudt-dashboard-icon">⚠️</div>'
			+ '<div class="wudt-dashboard-label">Plugins Disabled</div>'
			+ '<div class="wudt-dashboard-value">' + recentCrashes + '</div>'
			+ '</div>';
		
		// Detection Count
		var detections = events.filter(function(e) { return e.type === 'detected'; }).length;
		html += '<div class="wudt-dashboard-card">'
			+ '<div class="wudt-dashboard-icon">🔍</div>'
			+ '<div class="wudt-dashboard-label">Potential Crashes</div>'
			+ '<div class="wudt-dashboard-value">' + detections + '</div>'
			+ '</div>';
		
		// Status
		html += '<div class="wudt-dashboard-card wudt-card-ok">'
			+ '<div class="wudt-dashboard-icon">✅</div>'
			+ '<div class="wudt-dashboard-label">Recovery Status</div>'
			+ '<div class="wudt-dashboard-value">Active</div>'
			+ '</div>';
		
		html += '</div></div>';
		
		// Recent Events Section
		if (recentEvents.length > 0) {
			html += '<div class="wudt-dashboard-section">'
				+ '<h3 class="wudt-dashboard-title">📝 Recent Events (Last 10)</h3>'
				+ '<div class="wudt-dashboard-issues">'
				+ '<table class="wudt-fm-table"><thead><tr><th>Time</th><th>Type</th><th>Plugin</th><th>Confidence</th><th>Error</th></tr></thead><tbody>';
			
			for (var i = 0; i < recentEvents.length; i++) {
				var e = recentEvents[i];
				var typeClass = e.type === 'disabled' ? 'style="color:#d63638;font-weight:600;"' : '';
				html += '<tr>'
					+ '<td>' + esc((e.time || '').substring(0, 16)) + '</td>'
					+ '<td ' + typeClass + '>' + esc((e.type || '').toUpperCase()) + '</td>'
					+ '<td>' + esc(e.plugin || '-') + '</td>'
					+ '<td>' + (e.confidence || 0) + '%</td>'
					+ '<td title="' + esc(e.error || '') + '">' + esc((e.error || '-').substring(0, 50)) + '</td>'
					+ '</tr>';
			}
			
			html += '</tbody></table></div></div>';
		} else {
			html += '<div class="wudt-dashboard-section">'
				+ '<div class="wudt-dashboard-issues" style="text-align:center;padding:40px;">'
				+ '<div style="font-size:48px;margin-bottom:16px;">✅</div>'
				+ '<h4>No Recovery Events</h4>'
				+ '<p>Your site has not experienced any crash events. The crash recovery system is monitoring for fatal errors.</p>'
				+ '</div></div>';
		}
		
		html += '</div>';
		return html;
	}

	function bind() {
		// Tab click handler with URL hash update and AJAX loading
		$('.wudt-tab').on('click', function (e) {
			e.preventDefault();
			var newTab = $(this).data('tab');
			if (newTab === state.tab) { return; }

			// Update URL hash
			window.location.hash = newTab;
			state.tab = newTab;
			state.tabLoading = true;
			render();

			// Load tab data via AJAX
			loadTabData(newTab);
		});

		// Listen for hash changes (browser back/forward buttons)
		$(window).off('hashchange.wudt').on('hashchange.wudt', function () {
			var newTab = window.location.hash.replace('#', '');
			var validTabs = ['dashboard', 'ai_assistant', 'backup_suite', 'restore_suite', 'file_manager', 'database_manager', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp'];
			if (newTab && validTabs.indexOf(newTab) !== -1 && newTab !== state.tab) {
				state.tab = newTab;
				state.tabLoading = true;
				render();
				loadTabData(newTab);
			}
		});
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
		$('#wudt-ai-send').on('click', function () {
			sendAIMessage();
		});
		$(document).off('change', '#wudt-ai-auto-execute').on('change', '#wudt-ai-auto-execute', function () {
			state.ai.autoExecute = $(this).is(':checked');
			render();
		});
		// Enter key to send message (Shift+Enter for new line)
		$(document).off('keydown', '#wudt-ai-input').on('keydown', '#wudt-ai-input', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendAIMessage();
			}
		});
		// Mode toggle buttons (new ChatGPT-style)
		$(document).off('click', '.wudt-ai-mode-btn').on('click', '.wudt-ai-mode-btn', function () {
			state.ai.mode = String($(this).data('mode') || 'ask');
			render();
		});
		// Suggested prompt clicks
		$(document).off('click', '.wudt-ai-suggestion').on('click', '.wudt-ai-suggestion', function () {
			var prompt = String($(this).data('prompt') || '');
			if (prompt) {
				$('#wudt-ai-input').val(prompt);
				sendAIMessage();
			}
		});
		$('#wudt-ai-mode').on('change', function () {
			state.ai.mode = String($(this).val() || 'ask');
		});
		$('#wudt-ai-model').on('change', function () {
			var val = String($(this).val() || '');
			state.ai.model = val;
			if (val) {
				$('#wudt-ai-model-custom').val(val);
			}
		});
		$('#wudt-ai-model-custom').on('input', function () {
			state.ai.model = String($(this).val() || '').trim();
		});
		$('#wudt-ai-refresh-models').on('click', function () {
			post('diagnostics_ai_models').done(function (r) {
				if (r && r.success) {
					state.ai.models = r.data.models || [];
					render();
				}
			});
		});
		$('#wudt-ai-test-api').on('click', function () {
			var $btn = $(this);
			var model = $('#wudt-ai-model').val() || $('#wudt-ai-model-custom').val() || '';
			$btn.prop('disabled', true).text('Testing...');
			post('diagnostics_ai_test_api', { model: model }).done(function (r) {
				$btn.prop('disabled', false).text('Test API');
				if (r && r.success) {
					alert('API Test SUCCESS!\n\nModel: ' + r.data.model_used + '\nResponse: ' + (r.data.response_text || 'OK'));
				} else {
					var err = r && r.data && r.data.error ? r.data.error : 'Unknown error';
					var full = r && r.data && r.data.raw_response ? JSON.stringify(r.data.raw_response, null, 2) : '';
					console.error('[WUDT AI Test] Error:', r);
					alert('API Test FAILED!\n\nError: ' + err + '\n\nSee browser console for full details.');
				}
			}).fail(function () {
				$btn.prop('disabled', false).text('Test API');
				alert('API Test FAILED! Network error.');
			});
		});
		$('#wudt-ai-chat-file').on('change', function () {
			var files = (this.files || []);
			if (!files.length) { return; }
			var parts = [];
			var pending = files.length;
			for (var i = 0; i < files.length; i++) {
				(function (file) {
					var reader = new FileReader();
					reader.onload = function (ev) {
						parts.push('### ' + file.name + '\n' + String(ev.target.result || ''));
						pending--;
						if (pending === 0) {
							state.ai.chatTranscript = parts.join('\n\n').slice(0, 120000);
							alert('Chat transcript attached: ' + files.length + ' file(s).');
						}
					};
					reader.onerror = function () { pending--; };
					reader.readAsText(file);
				})(files[i]);
			}
		});
		$('#wudt-ai-autodebug').on('click', function () {
			post('diagnostics_ai_autodebug').done(function (r) {
				if (r && r.success) {
					state.ai.history.push({ role: 'assistant', content: r.data.suggestion || '' });
					render();
				}
			});
		});
		$('#wudt-ai-clear-chat').on('click', function () {
			post('diagnostics_ai_clear_history').done(function () {
				state.ai.history = [];
				// Mark next request to ignore server-side history
				state.ai.newChat = true;
				render();
			});
		});
		$(document).off('click', '.wudt-ai-copy').on('click', '.wudt-ai-copy', function () {
			var idx = parseInt($(this).attr('data-copy-index'), 10);
			var msg = state.ai.history[idx];
			if (msg) { navigator.clipboard.writeText(msg.content || ''); }
		});
		$('#wudt-ai-apply-fix').on('click', function () {
			if (!state.ai.lastAction || !state.ai.lastAction.action) { return; }
			executeAIAction(state.ai.lastAction, 0);
		});
		
		// Handle individual action execution
		$(document).off('click', '.wudt-ai-apply-action').on('click', '.wudt-ai-apply-action', function () {
			var idx = parseInt($(this).attr('data-action-idx'), 10);
			if (state.ai.lastActions && state.ai.lastActions[idx]) {
				executeAIAction(state.ai.lastActions[idx], idx);
			}
		});
		
		// Dismiss all actions
		$(document).off('click', '#wudt-ai-dismiss-actions').on('click', '#wudt-ai-dismiss-actions', function () {
			state.ai.lastActions = [];
			state.ai.lastAction = null;
			render();
		});
		
		// Legacy single action button (backward compatibility)
		$('#wudt-ai-apply-fix').on('click', function () {
			if (state.ai.lastAction && state.ai.lastAction.action) {
				executeAIAction(state.ai.lastAction, 0);
			}
		});
		
		// Bind AI assistant specific handlers
		bindAIAssistant();
	}

	/**
	 * Auto-execute multiple AI actions and get completion status
	 */
	function autoExecuteAIActions(actions, originalPrompt) {
		if (!actions || actions.length === 0) { return; }
		
		var executionId = 'exec_' + Date.now();
		
		// Set executing state to disable input
		state.ai.executing = true;
		state.ai.executionStatus = 'Executing ' + actions.length + ' task(s)...';
		
		// Add status message to chat
		state.ai.history.push({ 
			role: 'assistant', 
			content: 'Executing ' + actions.length + ' task(s) automatically...',
			isStatus: true
		});
		render();
		scrollAIToBottom();
		
		// Execute batch
		post('diagnostics_ai_execute_batch', {
			actions: JSON.stringify(actions),
			execution_id: executionId
		}).done(function (r) {
			if (r && r.success && r.data) {
				// Update status
				state.ai.history.push({ 
					role: 'assistant', 
					content: 'Tasks executed: ' + r.data.completed + '/' + r.data.total + ' completed.\n\n' + (r.data.summary || ''),
					isStatus: true
				});
				
				// Clear executing state to re-enable input
				state.ai.executing = false;
				state.ai.executionStatus = '';
				
				render();
				scrollAIToBottom();
				
				// Clear the action buttons since we auto-executed
				state.ai.lastActions = [];
				state.ai.lastAction = null;
				
				// Get AI completion message
				getAICompletionMessage(executionId, originalPrompt);
			} else {
				var errMsg = 'Task execution failed.';
				if (r && r.data && r.data.message) {
					errMsg += ' ' + r.data.message;
				}
				state.ai.history.push({ 
					role: 'assistant', 
					content: errMsg,
					isError: true
				});
				
				// Clear executing state to re-enable input
				state.ai.executing = false;
				state.ai.executionStatus = '';
				
				render();
			}
		}).fail(function (xhr) {
			var err = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Server error';
			state.ai.history.push({ 
				role: 'assistant', 
				content: 'Execution failed: ' + err,
				isError: true
			});
			
			// Clear executing state to re-enable input
			state.ai.executing = false;
			state.ai.executionStatus = '';
			
			render();
		});
	}

	/**
	 * Get AI completion message after task execution
	 */
	function getAICompletionMessage(executionId, originalPrompt) {
		post('diagnostics_ai_task_status', {
			execution_id: executionId,
			get_completion: true,
			original_prompt: originalPrompt
		}).done(function (r) {
			if (r && r.success && r.data && r.data.ai_completion) {
				// Add the AI's completion message as a proper response
				state.ai.history.push({ 
					role: 'assistant', 
					content: r.data.ai_completion,
					isCompletion: true
				});
				render();
				scrollAIToBottom();
			}
		}).fail(function () {
			// Silent fail - we already showed execution status
		});
	}

	/**
	 * Execute an AI action with confirmation (manual mode)
	 */
	function executeAIAction(action, idx) {
		if (!action || !action.action) { return; }
		
		var confirmMsg = 'Execute action: ' + action.action + '?';
		var extraInfo = '';
		
		// Build confirmation message based on action type
		switch (action.action) {
			case 'run_sql':
				extraInfo = '\n\nSQL Query:\n' + (action.sql || action.query || 'N/A');
				confirmMsg = 'Execute SQL query?' + extraInfo;
				break;
			case 'edit_file':
				extraInfo = '\n\nFile: ' + (action.path || 'N/A') + '\n(Backup will be created)';
				confirmMsg = 'Edit file?' + extraInfo;
				break;
			case 'create_file':
				extraInfo = '\n\nFile: ' + (action.path || 'N/A');
				confirmMsg = 'Create new file?' + extraInfo;
				break;
			case 'delete_file':
				extraInfo = '\n\nFile: ' + (action.path || 'N/A') + '\n\n⚠️ This cannot be undone!';
				confirmMsg = 'Delete file?' + extraInfo;
				break;
			case 'read_file':
				extraInfo = '\n\nFile: ' + (action.path || 'N/A');
				confirmMsg = 'Read file contents?' + extraInfo;
				break;
			case 'toggle_wp_debug':
				var debugState = action.enable ? 'ENABLE' : 'DISABLE';
				extraInfo = '\n\nAction: ' + debugState + ' WP_DEBUG' + (action.enable_log ? ' and WP_DEBUG_LOG' : '');
				confirmMsg = debugState + ' WordPress debug mode?' + extraInfo;
				break;
			case 'install_plugin':
				extraInfo = '\n\nPlugin: ' + (action.plugin_slug || action.plugin || 'N/A');
				if (action.activate) extraInfo += ' (and activate)';
				confirmMsg = 'Install plugin?' + extraInfo;
				break;
			case 'activate_plugin':
				extraInfo = '\n\nPlugin: ' + (action.plugin || 'N/A');
				confirmMsg = 'Activate plugin?' + extraInfo;
				break;
			case 'disable_plugin':
				extraInfo = '\n\nPlugin: ' + (action.plugin || 'N/A');
				confirmMsg = 'Disable plugin?' + extraInfo;
				break;
			case 'install_theme':
				extraInfo = '\n\nTheme: ' + (action.theme_slug || 'N/A');
				if (action.activate) extraInfo += ' (and activate)';
				confirmMsg = 'Install theme?' + extraInfo;
				break;
			case 'activate_theme':
				extraInfo = '\n\nTheme: ' + (action.theme_slug || 'N/A');
				confirmMsg = 'Switch to theme?' + extraInfo;
				break;
			case 'schedule_cron':
				extraInfo = '\n\nHook: ' + (action.hook || 'N/A') + '\nWhen: ' + (action.timestamp || 'N/A');
				if (action.recurring) extraInfo += ' (recurring: ' + (action.interval || 'hourly') + ')';
				confirmMsg = 'Schedule cron event?' + extraInfo;
				break;
			case 'unschedule_cron':
				extraInfo = '\n\nHook: ' + (action.hook || 'N/A');
				confirmMsg = 'Remove scheduled cron?' + extraInfo;
				break;
			case 'search_replace_db':
				var mode = action.dry_run ? 'DRY RUN - Check matches' : 'LIVE REPLACE';
				extraInfo = '\n\nMode: ' + mode + '\nSearch: ' + (action.search || 'N/A') + '\nReplace: ' + (action.replace || '(empty)');
				if (!action.dry_run) extraInfo += '\n\n⚠️ This will modify database content!';
				confirmMsg = 'Search/Replace in database?' + extraInfo;
				break;
			case 'search_files':
				extraInfo = '\n\nQuery: ' + (action.query || 'N/A') + '\nPath: ' + (action.path || 'WordPress root');
				confirmMsg = 'Search in files?' + extraInfo;
				break;
			case 'chmod':
				extraInfo = '\n\nPath: ' + (action.path || 'N/A') + '\nMode: ' + (action.mode || '644');
				if (action.recursive) extraInfo += ' (recursive)';
				confirmMsg = 'Change file permissions?' + extraInfo;
				break;
			case 'compress':
				extraInfo = '\n\nPaths: ' + ((action.paths || []).join(', ') || 'N/A') + '\nDestination: ' + (action.destination || 'N/A');
				confirmMsg = 'Create zip archive?' + extraInfo;
				break;
			case 'extract':
				extraInfo = '\n\nArchive: ' + (action.archive || 'N/A') + '\nTo: ' + (action.destination || 'N/A');
				confirmMsg = 'Extract archive?' + extraInfo;
				break;
			case 'rename':
				extraInfo = '\n\nFrom: ' + (action.old_path || 'N/A') + '\nTo: ' + (action.new_path || 'N/A');
				confirmMsg = 'Rename/move?' + extraInfo;
				break;
			case 'list_directory':
				extraInfo = '\n\nPath: ' + (action.path || 'WordPress root');
				confirmMsg = 'List directory contents?' + extraInfo;
				break;
			case 'optimize_tables':
				var tableList = (action.tables || []).join(', ') || 'ALL tables';
				extraInfo = '\n\nTables: ' + tableList;
				confirmMsg = 'Optimize database tables?' + extraInfo;
				break;
			case 'repair_tables':
				var tableList = (action.tables || []).join(', ') || 'ALL tables';
				extraInfo = '\n\nTables: ' + tableList;
				confirmMsg = 'Repair database tables?' + extraInfo;
				break;
			case 'get_system_info':
				confirmMsg = 'Get system information?';
				break;
		}
		
		if (!confirm(confirmMsg)) { return; }
		
		post('diagnostics_ai_apply_fix', {
			action_type: action.action,
			params: JSON.stringify(action)
		}).done(function (r) {
			if (r && r.success && r.data && r.data.applied) {
				// Show result with data if available
				var msg = 'Action executed successfully!\n\n' + r.data.message;
				if (r.data.data) {
					if (r.data.data.rows) {
						msg += '\n\nResults (' + r.data.data.row_count + ' rows):\n' + JSON.stringify(r.data.data.rows.slice(0, 5), null, 2);
						if (r.data.data.row_count > 5) {
							msg += '\n... and ' + (r.data.data.row_count - 5) + ' more rows';
						}
					}
					if (r.data.data.content) {
						msg += '\n\nFile Content:\n' + r.data.data.content.substring(0, 500);
						if (r.data.data.content.length > 500) {
							msg += '\n... (truncated)';
						}
					}
					if (r.data.data.backup_path) {
						msg += '\n\nBackup created: ' + r.data.data.backup_path;
					}
				}
				alert(msg);
				
				// Remove executed action from list
				if (state.ai.lastActions && state.ai.lastActions.length > 0) {
					state.ai.lastActions.splice(idx, 1);
					if (state.ai.lastActions.length === 0) {
						state.ai.lastActions = [];
						state.ai.lastAction = null;
					} else {
						state.ai.lastAction = state.ai.lastActions[0];
					}
					render();
				}
			} else {
				var errMsg = 'Action failed.';
				if (r && r.data && r.data.message) {
					errMsg += '\n\n' + r.data.message;
				}
				alert(errMsg);
			}
		}).fail(function (xhr) {
			var err = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Server error';
			alert('Action failed: ' + err);
		});
	}
	
	function bindAIAssistant() {
		// Sidebar quick action links
		$(document).off('click', '#wudt-ai-file-manager-link').on('click', '#wudt-ai-file-manager-link', function () {
			state.tab = 'file_manager';
			render();
		});
		$(document).off('click', '#wudt-ai-database-link').on('click', '#wudt-ai-database-link', function () {
			state.tab = 'database_manager';
			render();
		});
		// Textarea auto-resize
		$(document).off('input', '#wudt-ai-input').on('input', '#wudt-ai-input', function () {
			var $this = $(this);
			$this.css('height', 'auto');
			var newHeight = Math.min(Math.max($this[0].scrollHeight, 56), 200);
			$this.css('height', newHeight + 'px');
		});
		$('#wudt-backup-create').on('click', function () {
			var $btn = $(this);
			
			// Prevent duplicate clicks
			if ($btn.prop('disabled')) { return; }
			
			var components = [];
			$('.wudt-backup-component:checked').each(function () { components.push($(this).val()); });
			
			// CAPTURE auto-download state NOW before render() resets the DOM later
			var autoDownload = $('#wudt-backup-autodownload').is(':checked');
			
			var $progress = $('#wudt-backup-progress');
			var $bar = $progress.find('.wudt-progress-bar');
			var $status = $progress.find('.wudt-progress-status');
			var $percent = $progress.find('.wudt-progress-percent');
			
			$btn.prop('disabled', true).text('Creating Backup...');
			$progress.show();
			$bar.css('width', '5%');
			$percent.text('5%');
			$status.text('Starting backup...');
			
			var progressInterval = null;
			
			// Wait 3 seconds before first poll to allow backup process to start
			setTimeout(function() {
			progressInterval = setInterval(function () {
				post('wudt_backup_progress').done(function (r) {
					if (r && r.success && r.data) {
						var p = r.data;
						$bar.css('width', p.percent + '%');
						$percent.text(p.percent + '%');
						$status.text(p.message || p.status);
						
						if (p.status === 'complete') {
							clearInterval(progressInterval);
							$btn.prop('disabled', false).text('Create Backup');
							$progress.fadeOut(1000);
							
							// Auto-refresh backup list
							post('wudt_backup_list').done(function (r2) {
								if (r2 && r2.success && r2.data && r2.data.backups) {
									// Update the backup_suite tab data
									var tabs = (state.data && state.data.tabs) ? state.data.tabs : [];
									for (var i = 0; i < tabs.length; i++) {
										if (tabs[i].key === 'backup_suite') {
											tabs[i].data = tabs[i].data || {};
											tabs[i].data.backups = r2.data.backups;
											break;
										}
									}
									render();
									// Show success message and handle auto-download (using pre-captured state)
									var newBackup = p.backup || p;
									if (newBackup && newBackup.file) {
										var size = formatSize(newBackup.size || 0);
										
										if (autoDownload && newBackup.file) {
								// Use AJAX download endpoint instead of direct file URL
								var downloadNonce = (window.wudtProAdmin && window.wudtProAdmin.nonce) ? window.wudtProAdmin.nonce : '';
								var downloadUrl = ajaxurl + '?action=wudt_backup_download&file=' + encodeURIComponent(newBackup.file) + '&nonce=' + encodeURIComponent(downloadNonce);
											var iframe = document.createElement('iframe');
											iframe.style.display = 'none';
											iframe.src = downloadUrl;
											document.body.appendChild(iframe);
											setTimeout(function() {
												document.body.removeChild(iframe);
											}, 5000);
											
											alert('Backup created successfully!\n\nSize: ' + size + '\n\nDownload started automatically.');
										} else {
											alert('Backup created successfully!\n\nSize: ' + size);
										}
									}
								}
							});
						}
					}
				});
			}, 10000);
			}, 3000); // 3 second initial delay
			
			// Start the backup
			post('wudt_backup_create', {
				components: JSON.stringify(components),
				gzip: $('#wudt-backup-gzip').is(':checked') ? '1' : '0',
				password: $('#wudt-backup-password').val()
			}).done(function (r) {
				$('#wudt-backup-result').text(JSON.stringify(r, null, 2));
			}).fail(function () {
				if (progressInterval) { clearInterval(progressInterval); }
				$btn.prop('disabled', false).text('Create Backup');
				$progress.hide();
				alert('Backup creation failed. Please try again.');
			});
		});
		$('#wudt-backup-refresh').on('click', function () {
			post('wudt_backup_list').done(function (r) {
				$('#wudt-backup-result').text(JSON.stringify(r, null, 2));
			});
		});
		// Backup Suite - Copy path to clipboard
		$(document).off('click', '.wudt-backup-copy-path').on('click', '.wudt-backup-copy-path', function () {
			var path = $(this).data('path');
			if (path && navigator.clipboard) {
				navigator.clipboard.writeText(path).then(function () {
					alert('Backup path copied to clipboard!\n\nUse this in the Restore Suite tab.');
				});
			}
		});
		// Backup Suite - Delete backup with confirmation
		$(document).off('click', '.wudt-backup-delete').on('click', '.wudt-backup-delete', function () {
			var path = $(this).data('path');
			var name = $(this).data('name');
			if (!path) return;
			
			if (confirm('Are you sure you want to delete this backup?\n\n' + name + '\n\nThis action cannot be undone.')) {
				var $card = $(this).closest('.wudt-backup-card');
				$card.css('opacity', '0.5');
				
				post('wudt_backup_delete', { backup_path: path }).done(function (r) {
					if (r && r.success) {
						$card.fadeOut(300, function () { 
							$(this).remove();
							// Update count after removal
							var count = $('.wudt-backup-card').length;
							$('h3:contains("Available Backups")').text('Available Backups (' + count + ')');
						});
					} else {
						$card.css('opacity', '1');
						alert('Failed to delete backup: ' + (r && r.data && r.data.message ? r.data.message : 'Unknown error'));
					}
				}).fail(function () {
					$card.css('opacity', '1');
					alert('Failed to delete backup. Please try again.');
				});
			}
		});
		// Restore Suite - Refresh backup list
		$(document).off('click', '#wudt-restore-refresh').on('click', '#wudt-restore-refresh', function () {
			status('Loading backups...');
			post('wudt_backup_list').done(function (r) {
				if (r && r.success && r.data && r.data.backups) {
					// Update the backup_suite tab data
					var tabs = (state.data && state.data.tabs) ? state.data.tabs : [];
					for (var i = 0; i < tabs.length; i++) {
						if (tabs[i].key === 'backup_suite') {
							tabs[i].data = tabs[i].data || {};
							tabs[i].data.backups = r.data.backups;
							break;
						}
					}
				}
				render();
				status('Backups loaded');
			}).fail(function () {
				status('Failed to load backups');
			});
		});
		// Restore Suite - Select backup on row click
		$(document).off('click', '.wudt-restore-backup-row').on('click', '.wudt-restore-backup-row', function () {
			var path = $(this).data('path');
			state.restore.selectedPath = path;
			$('#wudt-restore-path').val(path);
			$(this).find('.wudt-restore-select').prop('checked', true);
			$(this).addClass('is-selected').siblings().removeClass('is-selected');
		});
		// Restore Suite - Select backup on radio change
		$(document).off('change', '.wudt-restore-select').on('change', '.wudt-restore-select', function () {
			var path = $(this).val();
			state.restore.selectedPath = path;
			$('#wudt-restore-path').val(path);
			$(this).closest('tr').addClass('is-selected').siblings().removeClass('is-selected');
		});
		// Restore Suite - Save path input to state
		$(document).off('input', '#wudt-restore-path').on('input', '#wudt-restore-path', function () {
			state.restore.selectedPath = $(this).val();
		});
		// Restore Suite - Save safe mode to state
		$(document).off('change', '#wudt-restore-safe').on('change', '#wudt-restore-safe', function () {
			state.restore.safeMode = $(this).is(':checked');
		});
		// Restore Suite - Save preserve plugins to state
		$(document).off('change', '#wudt-restore-preserve-plugins').on('change', '#wudt-restore-preserve-plugins', function () {
			state.restore.preservePlugins = $(this).is(':checked');
		});
		// Restore Suite - Save component selections to state
		$(document).off('change', '.wudt-restore-component').on('change', '.wudt-restore-component', function () {
			var components = [];
			$('.wudt-restore-component:checked').each(function () {
				components.push($(this).val());
			});
			state.restore.components = components;
		});
		// Restore Suite - Save media base to state
		$(document).off('input', '#wudt-restore-media-base').on('input', '#wudt-restore-media-base', function () {
			state.restore.mediaBase = $(this).val();
		});
		// Restore Suite - Save download URL to state
		$(document).off('input', '#wudt-restore-download-url').on('input', '#wudt-restore-download-url', function () {
			state.restore.downloadUrl = $(this).val();
		});
		// Restore Suite - Download backup from URL
		$(document).off('click', '#wudt-restore-download-btn').on('click', '#wudt-restore-download-btn', function () {
			var $btn = $(this);
			var url = $('#wudt-restore-download-url').val();
			if (!url) {
				showToast('Error', 'Please enter a backup file URL', 'error', 4000);
				return;
			}
			if (!confirm('Download backup from:\n' + url + '\n\nThis will download the file to your server. Continue?')) {
				return;
			}
			$btn.prop('disabled', true).text('Downloading...');
			$('#wudt-restore-download-status').text('Downloading backup file... Please wait.').css('color', '#667eea');
			post('wudt_restore_download', { url: url }).done(function (r) {
				if (r && r.success && r.data && r.data.path) {
					state.restore.selectedPath = r.data.path;
					state.restore.downloadedBackup = r.data;
					$('#wudt-restore-path').val(r.data.path);
					$('#wudt-restore-download-status').text('Downloaded: ' + r.data.name + ' (' + formatSize(r.data.size) + '). Ready to restore!').css('color', '#2ecc71');
					$('#wudt-restore-download-run').show();
					showToast('Success', 'Backup downloaded successfully!', 'success', 4000);
				} else {
					var msg = (r && r.data && r.data.message) ? r.data.message : 'Download failed';
					$('#wudt-restore-download-status').text('Error: ' + msg).css('color', '#d63638');
					showToast('Download Failed', msg, 'error', 5000);
				}
				$btn.prop('disabled', false).text('⬇️ Download & Prepare');
			}).fail(function (xhr) {
				var err = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Network error';
				$('#wudt-restore-download-status').text('Error: ' + err).css('color', '#d63638');
				$btn.prop('disabled', false).text('⬇️ Download & Prepare');
			});
		});
		// Restore Suite - Download and immediately restore
		$(document).off('click', '#wudt-restore-download-run').on('click', '#wudt-restore-download-run', function () {
			var url = $('#wudt-restore-download-url').val();
			if (!url) {
				showToast('Error', 'Please enter a backup file URL', 'error', 4000);
				return;
			}
			// Trigger the normal restore process which will use the downloaded file
			$('#wudt-restore-run').trigger('click');
		});
		$(document).off('click', '#wudt-restore-preview').on('click', '#wudt-restore-preview', function () {
			var $btn = $(this);
			var path = $('#wudt-restore-path').val();
			
			if (!path) {
				showToast('Error', 'Please select a backup first', 'error', 4000);
				return;
			}
			
			$btn.prop('disabled', true).text('Loading...');
			post('wudt_restore_preview', { backup_path: path }).done(function (r) {
				$btn.prop('disabled', false).text('Preview Contents');
				if (r && r.success) {
					var content = 'Backup Contents:\n';
					content += '- Database: ' + (r.data.contains.database ? 'Yes ✓' : 'No') + '\n';
					content += '- Config: ' + (r.data.contains.config ? 'Yes ✓' : 'No') + '\n';
					content += '- Files: ' + (r.data.files ? r.data.files.length : 0) + ' items\n\n';
					content += 'First 20 files:\n' + (r.data.files ? r.data.files.slice(0, 20).join('\n') : 'None');
					
					if (r.data.pre_checks) {
						displayPreRestoreChecks(r.data.pre_checks);
					}
					
					$('#wudt-restore-result').text(content).show();
				} else {
					showToast('Error', (r && r.data && r.data.message) || 'Failed to preview backup', 'error', 5000);
				}
			}).fail(function () {
				$btn.prop('disabled', false).text('Preview Contents');
				showToast('Error', 'Failed to connect to server', 'error', 4000);
			});
		});
		
		// Pre-restore checks button
		$(document).off('click', '#wudt-restore-check-btn').on('click', '#wudt-restore-check-btn', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text('Running Checks...');
			
			post('wudt_restore_check').done(function (r) {
				$btn.prop('disabled', false).text('Run Pre-Checks');
				if (r && r.success) {
					displayPreRestoreChecks(r.data);
				} else {
					showToast('Error', 'Failed to run pre-restore checks', 'error', 4000);
				}
			}).fail(function () {
				$btn.prop('disabled', false).text('Run Pre-Checks');
				showToast('Error', 'Failed to connect to server', 'error', 4000);
			});
		});
		
		// Display pre-restore checks
		function displayPreRestoreChecks(data) {
			var $checksDiv = $('#wudt-restore-checks');
			var $content = $('#wudt-restore-checks-content');
			var $warningsDiv = $('#wudt-restore-warnings');
			var $warningsContent = $('#wudt-restore-warnings-content');
			
			var html = '<table style="width:100%;font-size:13px;">';
			html += '<tr><th style="text-align:left;padding:4px;">Check</th><th style="text-align:left;padding:4px;">Status</th><th style="text-align:left;padding:4px;">Value</th></tr>';
			
			if (data.checks) {
				for (var checkName in data.checks) {
					if (checkName === 'can_restore') continue;
					var check = data.checks[checkName];
					var icon = check.pass ? '✅' : '❌';
					var color = check.pass ? '#22c55e' : '#ef4444';
					html += '<tr><td style="padding:4px;">' + esc(checkName) + '</td><td style="padding:4px;color:' + color + '">' + icon + ' ' + esc(check.message) + '</td><td style="padding:4px;">' + esc(check.value) + '</td></tr>';
				}
			}
			html += '</table>';
			
			if (!data.can_restore) {
				html += '<div style="margin-top:10px;padding:8px;background:#fef2f2;border:1px solid #ef4444;border-radius:4px;color:#991b1b;">⚠️ Pre-restore checks failed. Please fix the issues above before proceeding.</div>';
			}
			
			$content.html(html);
			$checksDiv.show();
			
			// Show warnings
			if (data.warnings && data.warnings.length > 0) {
				var warningsHtml = '<ul style="margin:0;padding-left:20px;">';
				for (var i = 0; i < data.warnings.length; i++) {
					warningsHtml += '<li style="margin:4px 0;">' + esc(data.warnings[i]) + '</li>';
				}
				warningsHtml += '</ul>';
				$warningsContent.html(warningsHtml);
				$warningsDiv.show();
			}
		}
		
		$(document).off('click', '#wudt-restore-run').on('click', '#wudt-restore-run', function () {
			console.log('Restore button clicked');
			var $btn = $(this);
			var components = state.restore.components || [];
			var backupPath = state.restore.selectedPath || '';
			console.log('Components selected:', components);
			console.log('Backup path:', backupPath);

			if (components.length === 0) {
				showToast('Restore Error', 'Please select at least one component to restore.', 'error', 5000);
				return;
			}

			if (!backupPath) {
				showToast('Restore Error', 'Please select a backup to restore from.', 'error', 5000);
				return;
			}
			
			// Check if database is selected - show extra warning
			var hasDatabase = components.indexOf('database') !== -1;
			console.log('Has database:', hasDatabase);
			var warningMessage = 'Are you sure you want to restore the following components?\n\n' + components.join(', ');
			if (hasDatabase) {
				warningMessage += '\n\n⚠️ WARNING: Restoring the database will COMPLETELY REPLACE your current database with the backup version. All changes made since the backup will be lost!';
			}
			warningMessage += '\n\nThis action cannot be undone. Are you sure you want to continue?';
			
			console.log('Showing first confirm...');
			if (!confirm(warningMessage)) {
				console.log('First confirm cancelled');
				return;
			}
			console.log('First confirm accepted');
			
			// Final confirmation for database restore
			if (hasDatabase) {
				console.log('Showing second confirm...');
				if (!confirm('FINAL CONFIRMATION: You are about to replace your entire database. Type OK to proceed.')) {
					console.log('Second confirm cancelled');
					return;
				}
				console.log('Second confirm accepted');
			}
			
			var $progress = $('#wudt-restore-progress');
			var $bar = $progress.find('.wudt-progress-bar');
			var $status = $progress.find('.wudt-progress-status');
			var $percent = $progress.find('.wudt-progress-percent');
			var $details = $progress.find('.wudt-progress-details');
			
			console.log('Starting restore AJAX...');
			$btn.prop('disabled', true).text('Running Restore...');
			$progress.show();
			$bar.css('width', '5%');
			$percent.text('5%');
			$status.text('Initializing restore...');
			$details.text('');
			
			var progressInterval = null;
			var statusMessages = {
				'preparing': 'Preparing restore environment...',
				'safety_backup': 'Creating safety backup (this may take a while)...',
				'extracting': 'Extracting backup archive...',
				'restoring_core': 'Replacing WordPress core files...',
				'restoring_plugins': 'Replacing plugins...',
				'restoring_themes': 'Replacing themes...',
				'restoring_uploads': 'Replacing uploads...',
				'restoring_database': 'Restoring database (do not close this window)...',
				'finalizing': 'Finalizing and cleaning up...',
				'complete': 'Restore complete!',
				'error': 'Error during restore'
			};
			
			// Start polling for progress
			var coreRestoreStarted = false;
			var coreFailCount = 0;
			progressInterval = setInterval(function () {
				// During core restore, admin-ajax.php may be temporarily unavailable
				// If we've seen restoring_core status, handle failures differently
				if (coreRestoreStarted) {
					coreFailCount++;
					// After 3 failures during core restore, assume success and reload
					if (coreFailCount >= 3) {
						clearInterval(progressInterval);
						$bar.css('width', '100%');
						$percent.text('100%');
						$status.text('Restore complete - reloading page...');
						$bar.css('background', '#22c55e');
						showToast('Restore Successful', 'Restore complete! Reloading page...', 'success', 3000);
						// Reload page after 2 seconds to reflect restored state
						setTimeout(function() {
							window.location.reload();
						}, 2000);
					}
					return; // Skip the normal AJAX call during core restore
				}

				post('wudt_restore_progress').done(function (r) {
					coreFailCount = 0; // Reset fail counter on success
					if (r && r.success && r.data) {
						var p = r.data;
						$bar.css('width', p.percent + '%');
						$percent.text(p.percent + '%');
						$status.text(statusMessages[p.status] || p.message || p.status);

						if (p.result && p.result.restored) {
							$details.text('Restored: ' + p.result.restored.join(', '));
						}

						// When core restore starts, stop normal polling and wait for reload
						if (p.status === 'restoring_core') {
							coreRestoreStarted = true;
							$details.text('Finalizing restore - page will reload shortly...');
							// Don't clear interval yet, just let it fail silently and count fails
						}

						if (p.status === 'complete' || p.status === 'complete_with_errors') {
							clearInterval(progressInterval);
							$btn.prop('disabled', false).text('🔄 Run Complete Restore');

							// Update nonce if provided to prevent 400 errors
							if (p.result && p.result.new_nonce) {
								window.wudtProAdmin.nonce = p.result.new_nonce;
								console.log('Updated nonce after restore');
							}

							if (p.status === 'complete') {
								$bar.css('background', '#22c55e');
								$bar.css('width', '100%');
								$text.text('✅ 100% - Restore Complete!');
								showToast('✅ Restore Successful!', 'All components restored successfully! Page will refresh...', 'success', 6000);
								// Reload page after 5 seconds to refresh session and prevent logout
								setTimeout(function() {
									console.log('Reloading page to refresh session...');
									window.location.reload();
								}, 5500);
							} else {
								$bar.css('background', '#f59e0b');
								showToast('⚠️ Restore Warning', 'Restore completed with some errors. Check the logs.', 'warning', 7000);
							}

							// Refresh backup list after 2 seconds
							setTimeout(function() {
								post('wudt_backup_list').done(function (r2) {
									if (r2 && r2.success && r2.data && r2.data.backups) {
										var tabs = (state.data && state.data.tabs) ? state.data.tabs : [];
										for (var i = 0; i < tabs.length; i++) {
											if (tabs[i].key === 'backup_suite') {
												tabs[i].data = tabs[i].data || {};
												tabs[i].data.backups = r2.data.backups;
												break;
											}
										}
										render();
									}
								});
							}, 2000);
						} else if (p.status === 'error') {
							clearInterval(progressInterval);
							$btn.prop('disabled', false).text('🔄 Run Complete Restore');
							$bar.css('background', '#ef4444');
							showToast('Restore Failed', p.message || 'An error occurred during restore', 'error', 7000);
						}
					}
				}).fail(function (xhr) {
					// If we're in core restore mode, failures are expected
					if (coreRestoreStarted) {
						coreFailCount++;
					}
					// Continue polling otherwise
				});
			}, 3000);
			
			// Start the restore with extended timeout (5 minutes)
			console.log('Making AJAX call to wudt_restore_run with 5 minute timeout...');
			$.ajax({
				url: window.wudtProAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wudt_restore_run',
					nonce: window.wudtProAdmin.nonce,
					backup_path: backupPath,
					restore_options: JSON.stringify(components),
					safe_mode: state.restore.safeMode ? '1' : '0',
					preserve_plugins: state.restore.preservePlugins ? '1' : '0',
					media_base: state.restore.mediaBase || ''
				},
				timeout: 300000 // 5 minutes
			}).done(function (r) {
				console.log('AJAX response:', r);
				if (r && r.success) {
					console.log('Restore started successfully');
					// Progress is being tracked via polling
				} else {
					console.log('Restore failed:', r);
					clearInterval(progressInterval);
					$btn.prop('disabled', false).text('🔄 Run Complete Restore');
					$progress.hide();
					var msg = (r && r.data && r.data.message) ? r.data.message : 'Failed to start restore';
					showToast('Restore Error', msg, 'error', 5000);
					
					// Show pre-check results if available
					if (r && r.data && r.data.checks) {
						displayPreRestoreChecks(r.data.checks);
					}
				}
			}).fail(function (xhr) {
				console.log('AJAX failed:', xhr);
				clearInterval(progressInterval);
				$btn.prop('disabled', false).text('🔄 Run Complete Restore');
				$progress.hide();
				
				var msg = 'Failed to connect to server. Please try again.';
				if (xhr.status === 423) {
					msg = 'Another restore operation is in progress. Please wait.';
				}
				showToast('Restore Error', msg, 'error', 5000);
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
				if (r && r.success) {
					showToast('Success', (r.data && r.data.message) ? r.data.message : 'File saved successfully', 'success');
				} else {
					showToast('Error', (r && r.data && r.data.message) ? r.data.message : 'Save failed', 'error');
				}
			});
		});
		$('#wudt-fm-search').on('click', function () {
			post('wudt_fm_search', { path: $('#wudt-fm-path').val(), query: $('#wudt-fm-search-text').val() }).done(function (r) {
				if (r && r.success) { $('#wudt-fm-list').text(JSON.stringify(r.data.results, null, 2)); }
			});
		});
		$('#wudt-fm-zip').on('click', function () {
			fmPost('wudt_fm_download_zip', { path: $('#wudt-fm-path').val() }).done(function (r) {
				window.location.href = r.data.url;
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
			state.db.loading = true;
			state.db.loadingMessage = 'Running query...';
			render();
			post('diagnostics_db_query', { query: query }).done(function (r) {
				state.db.lastQueryResult = r;
				if (r && r.success && r.data && r.data.history) { state.db.queryHistory = r.data.history; }
				state.db.loading = false;
				render();
			}).fail(function () {
				state.db.loading = false;
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
			state.db.loading = true;
			state.db.loadingMessage = 'Inserting row...';
			render();
			post('diagnostics_db_insert', { table: state.db.selectedTable, data: JSON.stringify(payload) }).done(function (r) {
				state.db.lastQueryResult = r;
				state.db.loading = false;
				render();
				alert(r && r.success ? 'Row inserted' : 'Insert failed');
				loadDbBrowse();
			}).fail(function () {
				state.db.loading = false;
				render();
			});
		});
		$('#wudt-db-export-run').on('click', function () {
			state.db.loading = true;
			state.db.loadingMessage = 'Exporting data...';
			render();
			post('diagnostics_db_export', {
				table: state.db.selectedTable,
				format: $('#wudt-db-export-format').val(),
				compression: $('#wudt-db-export-compress').val()
			}).done(function (r) {
				state.db.loading = false;
				render();
				if (r && r.success) { window.open(r.data.url, '_blank'); }
			}).fail(function () {
				state.db.loading = false;
				render();
			});
		});
		$(document).on('click', '[data-db-op]', function () {
			var op = String($(this).data('db-op'));
			var confirmRequired = (op === 'empty' || op === 'drop') ? confirm('Confirm ' + op + ' operation?') : true;
			if (!confirmRequired) { return; }
			state.db.loading = true;
			state.db.loadingMessage = 'Running ' + op + '...';
			render();
			post('diagnostics_db_operations', { table: state.db.selectedTable, operation: op, confirm: '1' }).done(function (r) {
				$('#wudt-db-op-result').text(JSON.stringify(r, null, 2));
				state.db.loading = false;
				render();
				loadDbTables();
			}).fail(function () {
				state.db.loading = false;
				render();
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
			state.db.loading = true;
			state.db.loadingMessage = 'Deleting row...';
			render();
			post('diagnostics_db_delete', { table: state.db.selectedTable, where: JSON.stringify(where) }).done(function (r) {
				state.db.lastQueryResult = r;
				state.db.loading = false;
				render();
				loadDbBrowse();
			}).fail(function () {
				state.db.loading = false;
				render();
			});
		});
		$(document).on('click', '.wudt-db-row-copy', function () {
			var idx = parseInt($(this).attr('data-row-index'), 10);
			var row = $.extend({}, state.db.browseRows[idx] || {});
			if (!row || Object.keys(row).length === 0) { alert('Row not found.'); return; }
			var pk = state.db.primaryKey;
			if (pk && typeof row[pk] !== 'undefined') { delete row[pk]; }
			state.db.loading = true;
			state.db.loadingMessage = 'Copying row...';
			render();
			post('diagnostics_db_insert', { table: state.db.selectedTable, data: JSON.stringify(row) }).done(function () {
				state.db.loading = false;
				render();
				loadDbBrowse();
			}).fail(function () {
				state.db.loading = false;
				render();
			});
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
			state.db.loading = true;
			state.db.loadingMessage = 'Updating row...';
			render();
			post('diagnostics_db_update', { table: state.db.selectedTable, data: JSON.stringify(updated), where: JSON.stringify(where) }).done(function () {
				state.db.loading = false;
				render();
				loadDbBrowse();
			}).fail(function () {
				state.db.loading = false;
				render();
			});
		});
		$('#wudt-mw-start-scan').on('click', function () {
			post('wudt_mw_scan_start').done(function (r) {
				$('#wudt-malware-result').text(JSON.stringify(r, null, 2));
			});
		});
		$('#wudt-mw-refresh').on('click', function () {
			post('wudt_mw_scan_status').done(function (r) {
				$('#wudt-malware-result').text(JSON.stringify(r, null, 2));
			});
		});
		$('#wudt-mw-schedule').on('click', function () {
			var email = prompt('Alert email address');
			post('wudt_mw_schedule', { enabled: '1', interval: 'daily', email: email || '' }).done(function (r) {
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
			fmPost('wudt_fm_create', { path: target, entry_type: 'dir' }, 'Folder created').done(function () {
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
			var input = this;
			$.ajax({
				url: window.wudtProAdmin.ajaxUrl,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json'
			}).done(function (r) {
				if (r && r.success) {
					showToast('Uploaded', r.data.message || '', 'success', 3000);
					status('Upload complete');
					loadDirectory(state.currentPath, false);
				} else {
					var msg = (r && r.data && r.data.message) ? r.data.message : 'Upload failed.';
					showToast('Upload failed', msg, 'error', 7000);
					status(msg);
				}
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Upload failed (HTTP ' + xhr.status + '). The file may exceed the server upload limit.';
				showToast('Upload failed', msg, 'error', 7000);
				status(msg);
			}).always(function () {
				input.value = '';
			});
		});
		$('#wudt-fm-download').on('click', function () {
			var path = state.selected.length ? state.selected[0] : state.currentPath;
			status('Preparing download...');
			fmPost('wudt_fm_download_zip', { path: path }).done(function (r) {
				status('');
				window.location.href = r.data.url;
			});
		});
		$(document).off('click.wudtfmcompress').on('click.wudtfmcompress', '#wudt-fm-compress-selected', function () {
			if (!state.selected.length) { return; }
			var archiveName = prompt('Enter archive name:', 'archive.zip');
			if (!archiveName) { return; }
			status('Creating archive...');
			post('wudt_fm_compress', { paths: state.selected, name: archiveName }).done(function (r) {
				if (r && r.success) {
					status(r.data.message);
					state.selected = [];
					loadDirectory(state.currentPath, false);
				} else {
					status('Failed to create archive');
					alert(r && r.data && r.data.message ? r.data.message : 'Failed to create archive');
				}
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Failed to create archive';
				status(msg);
				showToast('Archive failed', msg, 'error', 7000);
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
			if (normalizePath(sourcePath) === destination) { return; }
			fmPost('wudt_fm_rename', { old_path: sourcePath, new_path: destination }, 'Moved').done(function () {
				loadDirectory(state.currentPath, false);
			});
		});

	}

	function sendAIMessage() {
		var prompt = $('#wudt-ai-input').val();
		if (!prompt) { return; }
		var flags = {};
		$('.wudt-ai-ctx:checked').each(function () { flags[$(this).val()] = true; });
		var fileContent = state.editor && state.editor.visible ? (state.editor.content || '') : '';
		var mode = state.ai.mode || 'ask';
		var model = ($('#wudt-ai-model').val() || $('#wudt-ai-model-custom').val() || state.ai.model || '').trim();
		var chatTranscript = flags.chat_transcript ? (state.ai.chatTranscript || '') : '';
		if (mode !== 'agent') { state.ai.lastAction = null; }
		state.ai.history.push({ role: 'user', content: prompt });
		state.ai.typing = true;
		state.ai.executionStarted = false; // Reset for new execution
		render();
		scrollAIToBottom();
		var payload = new URLSearchParams();
		payload.append('action', 'diagnostics_ai_chat_stream');
		payload.append('nonce', window.wudtProAdmin.nonce);
		payload.append('prompt', String(prompt));
		payload.append('context_flags', JSON.stringify(flags));
		payload.append('file_content', String(fileContent));
		payload.append('chat_transcript', String(chatTranscript));
		payload.append('mode', String(mode));
		payload.append('model', String(model));
		// If user started a new chat, tell server to ignore stored history for this request
		if (state.ai.newChat) {
			payload.append('ignore_history', '1');
			state.ai.newChat = false;
		}
		if (state.ai.autoExecute) {
			payload.append('auto_execute', '1');
		}
		fetch(window.wudtProAdmin.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: payload.toString()
		}).then(function (res) {
			if (!res.body) { throw new Error('No stream body'); }
			var reader = res.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';
			var aiText = '';
			state.ai.history.push({ role: 'assistant', content: '' });
			var aiIndex = state.ai.history.length - 1;
			function pump() {
				return reader.read().then(function (result) {
					if (result.done) {
						state.ai.typing = false;
						render();
						return;
					}
					buffer += decoder.decode(result.value, { stream: true });
					var lines = buffer.split('\n');
					buffer = lines.pop() || '';
					for (var i = 0; i < lines.length; i++) {
						if (!lines[i]) { continue; }
						try {
							var evt = JSON.parse(lines[i]);
							if (evt.type === 'chunk') {
								aiText += evt.content || '';
								state.ai.history[aiIndex].content = aiText;
							} else if (evt.type === 'debug') {
								aiText += '\n\n---\n\n' + (evt.content || '');
								state.ai.history[aiIndex].content = aiText;
							} else if (evt.type === 'done' && mode === 'agent' && !state.ai.executionStarted) {
							// Prevent double execution - only run once per response
							state.ai.executionStarted = true;
							
							// Support both single action and multiple actions
							// Actions are NOT auto-executed - user must review and approve each action
							if (evt.actions && Array.isArray(evt.actions) && evt.actions.length > 0) {
								state.ai.lastActions = evt.actions;
								state.ai.lastAction = evt.actions[0];
							} else if (evt.action && evt.action.action) {
								state.ai.lastAction = evt.action;
								state.ai.lastActions = [evt.action];
							} else {
								// No actions found in this response - clear previous actions to avoid confusion
								state.ai.lastActions = [];
								state.ai.lastAction = null;
							}
							
							// Actions are displayed for user review - user must click "Execute" to run each action
							// No auto-execution for safety - all actions require explicit user confirmation
						}
					} catch (e) {}
				}
				render();
				scrollAIToBottom();
				return pump();
			});
		}
		return pump();
	}).catch(function () {
			state.ai.typing = false;
			state.ai.history.push({ role: 'assistant', content: 'AI streaming failed. Try again.' });
			render();
		});
	}

	function scrollAIToBottom() {
		var chat = document.getElementById('wudt-ai-chat-window');
		if (chat) { chat.scrollTop = chat.scrollHeight; }
	}

	function loadDbTables(alsoLoadCurrent) {
		state.db.loading = true;
		state.db.loadingMessage = 'Loading tables...';
		render();
		post('diagnostics_db_tables').done(function (r) {
			if (!r || !r.success) { return; }
			state.db.tables = r.data.tables || [];
			state.db.queryHistory = r.data.query_history || [];
			state.db.safeMode = !!r.data.safe_mode;
			if (!state.db.selectedTable && state.db.tables.length) {
				state.db.selectedTable = state.db.tables[0].name;
			}
			state.db.loading = false;
			render();
			if (alsoLoadCurrent && state.db.selectedTable) {
				loadDbStructure();
				loadDbBrowse();
			}
		}).fail(function () {
			state.db.loading = false;
			render();
		});
	}

	function loadDbBrowse(done) {
		if (!state.db.selectedTable) { return; }
		state.db.loading = true;
		state.db.loadingMessage = 'Loading data...';
		render();
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
			state.db.loading = false;
			render();
			if (typeof done === 'function') { done(); }
		}).fail(function () {
			state.db.loading = false;
			render();
		});
	}

	function loadDbStructure() {
		if (!state.db.selectedTable) { return; }
		state.db.loading = true;
		state.db.loadingMessage = 'Loading structure...';
		render();
		post('diagnostics_db_structure', { table: state.db.selectedTable }).done(function (r) {
			if (!r || !r.success) { return; }
			state.db.structure = r.data.structure || [];
			state.db.loading = false;
			render();
		}).fail(function () {
			state.db.loading = false;
			render();
		});
	}

	function editFile(path) {
		status('Opening file...');
		state.editor.loading = true;
		state.editor.loadingMessage = 'Opening ' + pathBasename(path) + '...';
		render();
		post('wudt_fm_read', { path: path }).done(function (r) {
			state.editor.loading = false;
			if (!r || !r.success) {
				status('Failed to open file');
				showToast('Open Failed', (r && r.data && r.data.message) ? r.data.message : 'Could not open file', 'error');
				console.error('editFile failed:', r);
				render();
				return;
			}
			openEditorWindow(path, String(r.data.content || ''));
			status('File opened');
			showToast('File Opened', pathBasename(path), 'success', 3000);
		}).fail(function (xhr, statusText, error) {
			state.editor.loading = false;
			status('Failed to open file');
			showToast('Open Failed', 'Network error: ' + statusText, 'error');
			console.error('editFile AJAX failed:', statusText, error);
			render();
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
		persistFileManagerState();
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
		persistFileManagerState();
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
		state.editor.loading = true;
		state.editor.loadingMessage = 'Saving ' + pathBasename(targetPath) + '...';
		status('Saving...');
		render();
		post('wudt_fm_write', { path: targetPath, content: state.editor.content }).done(function (r) {
			state.editor.loading = false;
			if (!r || !r.success) {
				status('Save failed');
				var errorMsg = (r && r.data && r.data.message) ? r.data.message : 'Save failed';
				showToast('Save Failed', errorMsg, 'error');
				render();
				return;
			}
			state.editor.path = targetPath;
			state.editor.title = pathBasename(targetPath);
			state.editor.original = state.editor.content;
			status('Saved');
			showToast('File Saved', state.editor.title, 'success');
			loadDirectory(state.currentPath, false);
			render();
			if (typeof onDone === 'function') { onDone(); }
		}).fail(function (xhr, statusText, error) {
			state.editor.loading = false;
			status('Save failed');
			showToast('Save Failed', 'Network error: ' + statusText, 'error');
			render();
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
			html += '<button data-act="compress">Compress to ZIP</button>';
			// Show Extract option for ZIP files
			if (item.type === 'file' && item.name.toLowerCase().endsWith('.zip')) {
				html += '<button data-act="extract">Extract Here</button>';
			}
			html += '<button data-act="download">Download</button>';
			html += '<button data-act="copy_path">Copy Path</button>';
			html += '<button data-act="properties">Properties</button>';
		} else {
			html += '<button data-act="new_folder">New Folder</button>';
			html += '<button data-act="upload">Upload File</button>';
			html += '<button data-act="refresh">Refresh</button>';
			html += '<button data-act="paste">Paste</button>';
			// Show Compress Selected if files are selected
			if (state.selected.length > 0) {
				html += '<button data-act="compress_selected">Compress Selected (' + state.selected.length + ')</button>';
			}
		}
		html += '</div>';
		menu.html(html).show();
		var menuBox = menu.find('.wudt-fm-menu');
		var maxLeft = Math.max(8, window.innerWidth - menuBox.outerWidth() - 8);
		var maxTop = Math.max(8, window.innerHeight - menuBox.outerHeight() - 8);
		var left = Math.min(Math.max(8, x), maxLeft);
		var top = Math.min(Math.max(8, y), maxTop);
		menu.css({ left: left + 'px', top: top + 'px' });
		menu.find('button').on('click', function (e) {
			e.stopPropagation();
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
			fmPost('wudt_fm_rename', { old_path: item.path, new_path: normalizePath(parent + '/' + name) }, 'Renamed').done(function () {
				loadDirectory(state.currentPath, false);
			});
			return;
		}
		if (action === 'delete' && item) {
			if (!confirm('Delete ' + item.name + '?')) { return; }
			var targets = (state.selected.length > 1 && state.selected.indexOf(item.path) !== -1) ? state.selected.slice() : [item.path];
			if (targets.length > 1 && !confirm('Delete all ' + targets.length + ' selected items?')) { return; }
			status('Deleting...');
			fmPost('wudt_fm_delete', { paths: targets }, 'Deleted').always(function () {
				state.selected = [];
				loadDirectory(state.currentPath, false);
			});
			return;
		}
		if (action === 'download' && item) {
			status('Preparing download...');
			fmPost('wudt_fm_download_zip', { path: item.path }).done(function (r) { status(''); window.location.href = r.data.url; });
			return;
		}
		if (action === 'compress' && item) {
			var defaultName = item.name + '.zip';
			var archiveName = prompt('Enter archive name:', defaultName);
			if (!archiveName) { return; }
			status('Creating archive...');
			post('wudt_fm_compress', { paths: [item.path], name: archiveName }).done(function (r) {
				if (r && r.success) {
					status(r.data.message);
					loadDirectory(state.currentPath, false);
				} else {
					status('Failed to create archive');
					alert(r && r.data && r.data.message ? r.data.message : 'Failed to create archive');
				}
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Failed to create archive';
				status(msg);
				showToast('Archive failed', msg, 'error', 7000);
			});
			return;
		}
		if (action === 'compress_selected') {
			var archiveName = prompt('Enter archive name:', 'archive.zip');
			if (!archiveName) { return; }
			status('Creating archive...');
			post('wudt_fm_compress', { paths: state.selected, name: archiveName }).done(function (r) {
				if (r && r.success) {
					status(r.data.message);
					state.selected = [];
					loadDirectory(state.currentPath, false);
				} else {
					status('Failed to create archive');
					alert(r && r.data && r.data.message ? r.data.message : 'Failed to create archive');
				}
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Failed to create archive';
				status(msg);
				showToast('Archive failed', msg, 'error', 7000);
			});
			return;
		}
		if (action === 'extract' && item) {
			if (!confirm('Extract "' + item.name + '" to this folder?')) { return; }
			status('Extracting archive...');
			post('wudt_fm_extract', { path: item.path }).done(function (r) {
				if (r && r.success) {
					status(r.data.message);
					loadDirectory(state.currentPath, false);
				} else {
					status('Failed to extract archive');
					alert(r && r.data && r.data.message ? r.data.message : 'Failed to extract archive');
				}
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Failed to extract archive';
				status(msg);
				showToast('Extract failed', msg, 'error', 7000);
			});
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
			fmPost('wudt_fm_rename', { old_path: state.clipboardPath, new_path: normalizePath(state.currentPath + '/' + base) }, 'Moved').done(function () {
				state.clipboardPath = '';
				loadDirectory(state.currentPath, false);
			});
		}
	}

	function persistFileManagerState() {
		var payload = {
			lastPath: state.currentPath || '',
			openFile: state.editor.path || '',
			editorOpen: !!state.editor.visible
		};
		try {
			window.localStorage.setItem('wudt.fm.state', JSON.stringify(payload));
		} catch (e) {}
		post('wudt_state_save', { state: JSON.stringify(payload) });
	}

	function bootstrapFileManagerState() {
		var local = {};
		try {
			local = JSON.parse(window.localStorage.getItem('wudt.fm.state') || '{}');
		} catch (e) {}
		post('wudt_state_get').done(function (r) {
			var remote = (r && r.success && r.data && r.data.state) ? r.data.state : {};
			var lastPath = remote.lastPath || local.lastPath || '';
			if (lastPath) {
				loadDirectory(lastPath, true, function () {
					var filePath = remote.openFile || local.openFile || '';
					var editorOpen = !!remote.editorOpen || !!local.editorOpen;
					if (editorOpen && filePath) {
						editFile(filePath);
					}
				});
			}
		});
	}

	// Load individual tab data via AJAX
	function loadTabData(tabKey) {
		status('Loading ' + titleCase(tabKey) + '...');
		post('wudt_pro_load_tab', { tab: tabKey }).done(function (r) {
			state.tabLoading = false;
			if (r && r.success && r.data) {
				// Update the tab data in state
				var tabs = state.data.tabs || [];
				var found = false;
				for (var i = 0; i < tabs.length; i++) {
					if (tabs[i].key === tabKey) {
						tabs[i].data = r.data;
						found = true;
						break;
					}
				}
				if (!found) {
					state.data.tabs.push({
						key: tabKey,
						label: titleCase(tabKey),
						data: r.data
					});
				}
				status('');
				render();

				// Initialize tab-specific data after render
				if (tabKey === 'database_manager') {
					state.db._loadedOnce = true;
					loadDbTables(true);
				}

				if (tabKey === 'file_manager') {
					var d = tabData('file_manager');
					if (!state.currentPath && d && d.root) {
						state.currentPath = normalizePath(d.root || '/');
						state.dirCache[state.currentPath] = parseListing(d.entries || []);
						updateHistory(state.currentPath);
					}
				}
			} else {
				status('Failed to load ' + titleCase(tabKey));
				render();
			}
		}).fail(function () {
			state.tabLoading = false;
			status('Failed to load ' + titleCase(tabKey));
			render();
		});
	}

	function renderSMTP() {
		var d = tabData('smtp') || {};
		var enabled = d.enabled || false;
		var host = d.host || '';
		var port = d.port || 587;
		var encryption = d.encryption || 'tls';
		var auth = d.auth !== false;
		var user = d.user || '';
		var passSet = d.pass_set || false;
		var fromEmail = d.from_email || '';
		var fromName = d.from_name || '';
		var testResult = d.mail_test || {};

		var html = '<div class="wudt-card">' +
			'<h3><span class="dashicons dashicons-email"></span> ' + esc('SMTP Configuration') + '</h3>' +
			'<p>' + esc('Configure SMTP settings to send emails through an external mail server instead of the default WordPress mail function.') + '</p>';

		// Connection test result
		if (testResult && testResult.message) {
			html += '<div class="notice ' + (testResult.success ? 'notice-success' : 'notice-error') + ' is-dismissible">' +
				'<p><strong>' + esc(testResult.success ? 'Connected' : 'Connection Failed') + ':</strong> ' + esc(testResult.message) + '</p>' +
				'</div>';
		}

		html += '<form id="wudt-smtp-form">' +
			// SMTP Enabled Toggle
			'<table class="form-table">' +
			'<tr>' +
			'<th scope="row">' + esc('Enable SMTP') + '</th>' +
			'<td>' +
			'<label class="wudt-toggle-switch">' +
			'<input type="checkbox" name="enabled" value="1" ' + (enabled ? 'checked' : '') + ' id="wudt-smtp-enabled">' +
			'<span class="slider"></span>' +
			'</label>' +
			'<span class="wudt-toggle-label" id="wudt-smtp-status">' + esc(enabled ? 'Enabled' : 'Disabled') + '</span>' +
			'<p class="description">' + esc('Enable to use SMTP for all WordPress emails.') + '</p>' +
			'</td>' +
			'</tr>' +
			// SMTP Host
			'<tr>' +
			'<th scope="row"><label for="smtp_host">' + esc('SMTP Host') + '</label></th>' +
			'<td>' +
			'<input type="text" name="host" id="smtp_host" class="regular-text" value="' + esc(host) + '" placeholder="' + esc('e.g., smtp.gmail.com') + '">' +
			'<p class="description">' + esc('Your SMTP server hostname.') + '</p>' +
			'</td>' +
			'</tr>' +
			// SMTP Port
			'<tr>' +
			'<th scope="row"><label for="smtp_port">' + esc('SMTP Port') + '</label></th>' +
			'<td>' +
			'<input type="number" name="port" id="smtp_port" class="small-text" value="' + esc(port) + '" min="1" max="65535">' +
			'<p class="description">' + esc('Common ports: 25, 465 (SSL), 587 (TLS)') + '</p>' +
			'</td>' +
			'</tr>' +
			// Encryption
			'<tr>' +
			'<th scope="row"><label for="smtp_encryption">' + esc('Encryption') + '</label></th>' +
			'<td>' +
			'<select name="encryption" id="smtp_encryption">' +
			'<option value="tls" ' + (encryption === 'tls' ? 'selected' : '') + '>' + esc('TLS (Recommended)') + '</option>' +
			'<option value="ssl" ' + (encryption === 'ssl' ? 'selected' : '') + '>' + esc('SSL') + '</option>' +
			'<option value="none" ' + (encryption === 'none' ? 'selected' : '') + '>' + esc('None') + '</option>' +
			'</select>' +
			'<p class="description">' + esc('Select the encryption method.') + '</p>' +
			'</td>' +
			'</tr>' +
			// Authentication
			'<tr>' +
			'<th scope="row">' + esc('SMTP Authentication') + '</th>' +
			'<td>' +
			'<label class="wudt-toggle-switch">' +
			'<input type="checkbox" name="auth" value="1" ' + (auth ? 'checked' : '') + ' id="smtp_auth">' +
			'<span class="slider"></span>' +
			'</label>' +
			'<span class="wudt-toggle-label">' + esc(auth ? 'Enabled' : 'Disabled') + '</span>' +
			'<p class="description">' + esc('Most SMTP servers require authentication.') + '</p>' +
			'</td>' +
			'</tr>' +
			// Username
			'<tr>' +
			'<th scope="row"><label for="smtp_user">' + esc('SMTP Username') + '</label></th>' +
			'<td>' +
			'<input type="text" name="user" id="smtp_user" class="regular-text" value="' + esc(user) + '" placeholder="' + esc('your@email.com') + '">' +
			'</td>' +
			'</tr>' +
			// Password
			'<tr>' +
			'<th scope="row"><label for="smtp_pass">' + esc('SMTP Password') + '</label></th>' +
			'<td>' +
			'<input type="password" name="pass" id="smtp_pass" class="regular-text" ' + (passSet ? 'placeholder="' + esc('Enter to change (hidden)') + '"' : 'placeholder="' + esc('Your SMTP password') + '"') + '>' +
			'<button type="button" class="button" id="wudt-toggle-smtp-pass" style="margin-left:5px;">' + esc('Show') + '</button>' +
			'</td>' +
			'</tr>' +
			// From Email
			'<tr>' +
			'<th scope="row"><label for="smtp_from_email">' + esc('From Email') + '</label></th>' +
			'<td>' +
			'<input type="email" name="from_email" id="smtp_from_email" class="regular-text" value="' + esc(fromEmail) + '" placeholder="' + esc(getOption('admin_email')) + '">' +
			'<p class="description">' + esc('The sender email address. Defaults to admin email if empty.') + '</p>' +
			'</td>' +
			'</tr>' +
			// From Name
			'<tr>' +
			'<th scope="row"><label for="smtp_from_name">' + esc('From Name') + '</label></th>' +
			'<td>' +
			'<input type="text" name="from_name" id="smtp_from_name" class="regular-text" value="' + esc(fromName) + '" placeholder="' + esc(getOption('blogname')) + '">' +
			'<p class="description">' + esc('The sender name. Defaults to site name if empty.') + '</p>' +
			'</td>' +
			'</tr>' +
			'</table>' +
			'<p class="submit">' +
			'<button type="button" class="button button-primary" id="wudt-save-smtp">' + esc('Save Settings') + '</button>' +
			'</p>' +
			'</form>' +
			'</div>';

		// Test Email Section
		html += '<div class="wudt-card" style="margin-top: 20px;">' +
			'<h3><span class="dashicons dashicons-email-alt"></span> ' + esc('Send Test Email') + '</h3>' +
			'<p>' + esc('Send a test email to verify your SMTP configuration.') + '</p>' +
			'<table class="form-table">' +
			'<tr>' +
			'<th scope="row"><label for="smtp_test_to">' + esc('To Email') + '</label></th>' +
			'<td>' +
			'<input type="email" id="smtp_test_to" class="regular-text" value="' + esc(getOption('admin_email')) + '">' +
			'<button type="button" class="button button-secondary" id="wudt-send-test-email" style="margin-left:5px;">' + esc('Send Test') + '</button>' +
			'</td>' +
			'</tr>' +
			'</table>' +
			'<div id="smtp-test-result" style="margin-top: 10px;"></div>' +
			'</div>';

		return html;
	}

	// Helper to get WordPress option (simplified)
	function getOption(name) {
		if (window.wudtProAdmin && window.wudtProAdmin.data && window.wudtProAdmin.data.options) {
			return window.wudtProAdmin.data.options[name] || '';
		}
		return '';
	}

	function renderSiteMigration() {
		return '<div id="wudt-migration-root"></div>';
	}

	function mountSiteMigration() {
		var el = document.getElementById('wudt-migration-root');
		if (el && window.WUDTMigration) {
			window.WUDTMigration.mount(el, tabData('site_migration'));
		}
	}

	$(function () {
		render();
		
		// Auto-collapse WordPress sidebar on AI assistant page
		if (window.location.href.indexOf('page=wudt-ai-assistant') !== -1) {
			// Add folded class to body to collapse sidebar
			$('body').addClass('folded');
			// Also collapse the menu if not already folded
			if (typeof setUserSetting === 'function') {
				setUserSetting('mfold', 'o'); // 'o' = folded/collapsed
			}
			// Trigger WordPress menu toggle if available
			if ($('#collapse-menu').length) {
				$('#collapse-menu').trigger('click');
			}
		}
		
		// Load initial tab data if not dashboard or if no data exists
		var initialTab = state.tab;
		var hasTabData = false;
		var tabs = state.data.tabs || [];
		for (var i = 0; i < tabs.length; i++) {
			if (tabs[i].key === initialTab && tabs[i].data && Object.keys(tabs[i].data).length > 0) {
				hasTabData = true;
				break;
			}
		}
		if (!hasTabData || initialTab !== 'dashboard') {
			state.tabLoading = true;
			render();
			loadTabData(initialTab);
		}
		bootstrapFileManagerState();

		// SMTP Tab Event Handlers
		$(document).on('click', '#wudt-save-smtp', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text('Saving...');
			
			var data = {
				enabled: $('#wudt-smtp-enabled').is(':checked') ? '1' : '',
				host: $('#smtp_host').val(),
				port: $('#smtp_port').val(),
				encryption: $('#smtp_encryption').val(),
				auth: $('#smtp_auth').is(':checked') ? '1' : '',
				user: $('#smtp_user').val(),
				pass: $('#smtp_pass').val(),
				from_email: $('#smtp_from_email').val(),
				from_name: $('#smtp_from_name').val()
			};
			
			post('wudt_save_smtp_settings', data).done(function (r) {
				if (r && r.success) {
					alert('Settings saved successfully!');
					// Reload tab data to show updated test result
					loadTabData('smtp');
				} else {
					alert('Error: ' + ((r && r.data && r.data.message) ? r.data.message : 'Failed to save settings'));
				}
			}).fail(function () {
				alert('Failed to save settings. Please try again.');
			}).always(function () {
				$btn.prop('disabled', false).text('Save Settings');
			});
		});

		$(document).on('click', '#wudt-send-test-email', function () {
			var $btn = $(this);
			var toEmail = $('#smtp_test_to').val();
			
			if (!toEmail || !toEmail.includes('@')) {
				alert('Please enter a valid email address.');
				return;
			}
			
			$btn.prop('disabled', true).text('Sending...');
			$('#smtp-test-result').html('<p>Sending test email...</p>');
			
			post('wudt_send_test_email', { to: toEmail }).done(function (r) {
				if (r && r.success) {
					$('#smtp-test-result').html('<div class="notice notice-success"><p>' + esc(r.data.message) + '</p></div>');
				} else {
					$('#smtp-test-result').html('<div class="notice notice-error"><p>' + esc((r && r.data && r.data.message) ? r.data.message : 'Failed to send test email') + '</p></div>');
				}
			}).fail(function () {
				$('#smtp-test-result').html('<div class="notice notice-error"><p>Failed to send test email. Check your SMTP settings.</p></div>');
			}).always(function () {
				$btn.prop('disabled', false).text('Send Test');
			});
		});

		$(document).on('click', '#wudt-toggle-smtp-pass', function () {
			var $input = $('#smtp_pass');
			var $btn = $(this);
			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$btn.text('Hide');
			} else {
				$input.attr('type', 'password');
				$btn.text('Show');
			}
		});

		$(document).on('click', '#wudt-rescue-copy', function () {
			var $input = $('#wudt-rescue-url');
			$input[0].select();
			try { document.execCommand('copy'); } catch (e) { /* ignore */ }
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText($input.val()); }
			$(this).text('Copied ✓');
		});

		$(document).on('click', '#wudt-rescue-regen', function () {
			if (!confirm('Create a new emergency link? The old link stops working.')) { return; }
			post('wudt_rescue_regenerate').done(function (r) {
				if (r && r.success) {
					$('#wudt-rescue-url').val(r.data.rescue_url);
					loadTabData('recovery');
				}
			});
		});

		$(document).on('change', '#wudt-smtp-enabled', function () {
			var enabled = $(this).is(':checked');
			$('#wudt-smtp-status').text(enabled ? 'Enabled' : 'Disabled');
		});

	});
})(jQuery);
