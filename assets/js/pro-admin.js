(function ($) {
	'use strict';

	var bootData = (window.wudtProAdmin && window.wudtProAdmin.data) ? window.wudtProAdmin.data : { tabs: [] };

	// Get initial tab from URL hash or default to dashboard
	function getInitialTab() {
		var hash = window.location.hash.replace('#', '');
		var validTabs = ['dashboard', 'backup_suite', 'restore_suite', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp', 'site_migration'];
		if (hash && validTabs.indexOf(hash) !== -1) {
			return hash;
		}
		return (window.wudtProAdmin && window.wudtProAdmin.defaultTab) ? window.wudtProAdmin.defaultTab : 'dashboard';
	}

	var state = {
		data: bootData,
		tab: getInitialTab(),
		tabLoading: false
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
		var tabs = ['dashboard', 'backup_suite', 'restore_suite', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp', 'site_migration'];
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
		if (state.tab === 'malware_enterprise') { return renderMalwareEnterprise(); }
		if (state.tab === 'recovery') { return renderRecovery(); }
		if (state.tab === 'smtp') { return renderSMTP(); }
		if (state.tab === 'site_migration') { return renderSiteMigration(); }
		return '<p>No data.</p>';
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

	function renderBackupSuite() {
		return '<div id="wudt-backup-root"></div>';
	}

	function renderRestoreSuite() {
		return '<div id="wudt-backup-root"></div>';
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
				+ '<table class="wudt-data-table"><thead><tr><th>Option Name</th><th>Size</th></tr></thead><tbody>';
			
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
				+ '<table class="wudt-data-table"><thead><tr><th>Time</th><th>URL</th><th>Load</th><th>Memory</th><th>Queries</th></tr></thead><tbody>';
			
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

		// Safe mode: wp-admin with every other plugin and the theme disabled, for this browser only.
		var rescue = d.rescue || null;
		if (rescue) {
			html += '<div class="wudt-dashboard-section"><div class="wudt-card wudt-rescue-card">'
				+ '<h3>🛟 Safe mode</h3>'
				+ '<p>Open wp-admin with <strong>all other plugins and your theme disabled for your browser only</strong> (visitors are not affected), '
				+ 'so you can find and fix a plugin or theme that breaks the site. It ends automatically after two hours.</p>'
				+ (rescue.safe_mode
					? '<p><a class="button button-primary" href="' + esc(rescue.exit_url) + '">Exit safe mode</a></p>'
					: '<p><button class="button button-primary" id="wudt-safe-mode-enter">Enter safe mode</button></p>')
				+ '<p class="description">The first time you use it, a small must-use plugin is added to wp-content/mu-plugins; it is removed when Diagnostics Toolkit is deactivated. '
				+ 'If wp-admin does not load at all, use the link in WordPress’s “Your Site is Experiencing a Technical Issue” email to log in, then enter safe mode here.</p>'
				+ '</div></div>';
		}

		html += '<div class="wudt-dashboard-section"><div class="wudt-card">'
			+ '<label><input type="checkbox" id="wudt-recovery-auto"' + (d.auto_disable ? ' checked' : '') + '> '
			+ '<strong>Automatically deactivate a plugin (or switch away from a theme) that keeps crashing the site</strong></label>'
			+ '<p class="description">Off by default. When on, a plugin is only deactivated after it caused fatal errors in 3 separate requests within 10 minutes; every action is listed below and can be undone.</p>'
			+ '</div></div>';

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
				+ '<table class="wudt-data-table"><thead><tr><th>Time</th><th>Type</th><th>Plugin</th><th>Confidence</th><th>Error</th></tr></thead><tbody>';
			
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
			var validTabs = ['dashboard', 'backup_suite', 'restore_suite', 'malware_enterprise', 'recovery', 'logs', 'performance', 'security', 'smtp', 'site_migration'];
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

		$(document).on('change', '#wudt-recovery-auto', function () {
			post('wudt_recovery_auto_toggle', { enabled: $(this).is(':checked') ? '1' : '0' });
		});

		$(document).on('click', '#wudt-safe-mode-enter', function () {
			var $b = $(this).prop('disabled', true).text('Entering safe mode…');
			post('wudt_safe_mode_enter').done(function (r) {
				if (r && r.success) {
					window.location.href = r.data.redirect;
					return;
				}
				$b.prop('disabled', false).text('Enter safe mode');
				alert((r && r.data && r.data.message) || 'Could not enter safe mode.');
			});
		});

		$(document).on('change', '#wudt-smtp-enabled', function () {
			var enabled = $(this).is(':checked');
			$('#wudt-smtp-status').text(enabled ? 'Enabled' : 'Disabled');
		});

	});
})(jQuery);
