/**
 * WP Ultimate Diagnostics Toolkit - Modern Admin JavaScript
 * Enhanced UI interactions and functionality
 */

(function($) {
	'use strict';

	// State management
	const state = {
		currentTab: null,
		moduleData: {},
		loading: false
	};

	// ============================================
	// Initialization
	// ============================================
	function init() {
		console.log('[WUDT] Initializing Modern Admin...');
		
		// Check if we have tabs
		const $tabs = $('.wudt-tab');
		const $panels = $('.wudt-panel');
		console.log('[WUDT] Found', $tabs.length, 'tabs and', $panels.length, 'panels');
		
		if ($tabs.length === 0) {
			console.error('[WUDT] No tabs found!');
			return;
		}
		
		bindEvents();
		
		// Load first tab by default
		const $firstTab = $tabs.first();
		const firstTabKey = $firstTab.data('tab');
		console.log('[WUDT] First tab key:', firstTabKey);
		
		if (firstTabKey) {
			switchTab(firstTabKey);
		}

		// Initialize dark mode from saved preference
		if (localStorage.getItem('wudt-dark-mode') === 'true') {
			$('body').addClass('wudt-dark');
		}
		
		console.log('[WUDT] Initialization complete');
	}

	// ============================================
	// Event Binding
	// ============================================
	function bindEvents() {
		// Tab switching
		$(document).on('click', '.wudt-tab', function(e) {
			e.preventDefault();
			const tab = $(this).data('tab');
			console.log('[WUDT] Tab clicked:', tab);
			switchTab(tab);
		});

		// Run module check
		$(document).on('click', '.wudt-run-module', function() {
			const module = $(this).data('module');
			runModule(module);
		});

		// Dark mode toggle
		$(document).on('click', '#wudt-dark-mode-toggle', function() {
			toggleDarkMode();
		});

		// Quick actions
		$(document).on('click', '#wudt-quick-scan', function() {
			runFullScan();
		});

		$(document).on('click', '#wudt-quick-clear-cache', function() {
			clearCache();
		});

		$(document).on('click', '#wudt-quick-export', function() {
			exportReport();
		});

		$(document).on('click', '#wudt-quick-help', function() {
			showHelp();
		});

		// Auto Recovery actions
		$(document).on('click', '.wudt-restore-plugin', function() {
			const plugin = $(this).data('plugin');
			restorePlugin(plugin);
		});

		$(document).on('click', '.wudt-restore-theme', function() {
			restoreTheme();
		});

		$(document).on('click', '.wudt-disable-safe-mode', function() {
			disableSafeMode();
		});

		// Keyboard shortcuts
		$(document).on('keydown', function(e) {
			// Ctrl/Cmd + Shift + R = Refresh current tab
			if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'R') {
				e.preventDefault();
				if (state.currentTab) {
					loadTabData(state.currentTab);
					WUDTUI.Toast.info('Refreshing current tab...');
				}
			}
		});
	}

	// ============================================
	// Tab Management
	// ============================================
	function switchTab(tab) {
		if (state.loading || tab === state.currentTab) {
			return;
		}

		console.log('[WUDT] Switching to tab:', tab);

		// Update tab UI
		$('.wudt-tab').removeClass('is-active');
		const $targetTab = $(`.wudt-tab[data-tab="${tab}"]`);
		$targetTab.addClass('is-active');
		console.log('[WUDT] Tab element found:', $targetTab.length > 0);

		// Hide all panels
		$('.wudt-panel').hide();

		// Show target panel
		const $panel = $(`.wudt-panel[data-panel="${tab}"]`);
		console.log('[WUDT] Panel element found:', $panel.length > 0, 'data-panel:', tab);
		
		if ($panel.length === 0) {
			console.error('[WUDT] Panel not found for tab:', tab);
			WUDTUI.Toast.error('Panel not found for tab: ' + tab);
			return;
		}
		
		$panel.show();
		console.log('[WUDT] Panel shown');

		state.currentTab = tab;

		// Load data if not cached
		if (!state.moduleData[tab]) {
			console.log('[WUDT] Loading tab data...');
			loadTabData(tab);
		} else {
			console.log('[WUDT] Using cached data');
			renderPanel(tab, state.moduleData[tab]);
		}

		// Update URL hash for direct linking
		window.location.hash = tab;
	}

	// ============================================
	// Data Loading
	// ============================================
	function loadTabData(tab) {
		const $panel = $(`.wudt-panel[data-panel="${tab}"]`);
		const $content = $panel.find('.wudt-panel__content');

		// Show skeleton loader
		$content.html(`
			<div class="wudt-skeleton-loader">
				<div class="wudt-skeleton wudt-skeleton--title"></div>
				<div class="wudt-skeleton wudt-skeleton--text"></div>
				<div class="wudt-skeleton wudt-skeleton--text"></div>
				<div class="wudt-skeleton wudt-skeleton--text"></div>
			</div>
		`);

		WUDTUI.Ajax.post('wudt_get_dashboard_data', { tab: tab }, {
			showError: false
		}).done(function(response) {
			if (response.success) {
				state.moduleData[tab] = response.data.data;
				renderPanel(tab, response.data.data);
			} else {
				showPanelError(tab, response.data?.message || 'Failed to load data');
			}
		}).fail(function() {
			showPanelError(tab, 'Network error. Please try again.');
		});
	}

	// ============================================
	// Panel Rendering
	// ============================================
	function renderPanel(tab, data) {
		const $panel = $(`.wudt-panel[data-panel="${tab}"]`);
		const $content = $panel.find('.wudt-panel__content');

		// Generate HTML based on data type
		let html = '';
		
		// Custom rendering for auto_recovery tab
		if (tab === 'auto_recovery' && typeof data === 'object' && data !== null) {
			html = renderAutoRecoveryPanel(data);
		} else if (typeof data === 'object' && data !== null) {
			html = renderDataObject(data);
		} else {
			html = `<pre class="wudt-pre">${escapeHtml(String(data))}</pre>`;
		}

		$content.html(html);
		
		// Add animation
		$content.addClass('wudt-animate-fade-in');
		setTimeout(() => $content.removeClass('wudt-animate-fade-in'), 300);
	}

	/**
	 * Render Auto Recovery panel with action buttons
	 */
	function renderAutoRecoveryPanel(data) {
		let html = '<div class="wudt-auto-recovery">';
		
		// Safe Mode Status
		const safeModeActive = data.safe_mode_active === true;
		html += '<div class="wudt-section">';
		html += '<h3>Safe Mode Status</h3>';
		if (safeModeActive) {
			html += '<div class="wudt-alert wudt-alert--warning">';
			html += '<span class="wudt-alert__icon">⚠️</span>';
			html += '<div class="wudt-alert__content">';
			html += '<strong>Safe Mode is Active</strong>';
			html += '<p>WUDT detected a fatal error in itself. Advanced features are temporarily disabled to keep the site accessible.</p>';
			html += '<button class="wudt-btn wudt-btn--primary wudt-disable-safe-mode">Exit Safe Mode</button>';
			html += '</div></div>';
		} else {
			html += '<div class="wudt-alert wudt-alert--success">';
			html += '<span class="wudt-alert__icon">✅</span>';
			html += '<div class="wudt-alert__content">';
			html += '<strong>Safe Mode Inactive</strong>';
			html += '<p>WUDT is operating normally. All features are enabled.</p>';
			html += '</div></div>';
		}
		html += '</div>';
		
		// Disabled Plugins
		const disabledPlugins = data.disabled_plugins || {};
		const pluginCount = Object.keys(disabledPlugins).length;
		
		html += '<div class="wudt-section">';
		html += '<h3>Disabled Plugins</h3>';
		if (pluginCount > 0) {
			html += `<p class="wudt-section-desc">${pluginCount} plugin(s) were automatically deactivated due to fatal errors:</p>`;
			html += '<div class="wudt-list">';
			for (const [plugin, info] of Object.entries(disabledPlugins)) {
				html += '<div class="wudt-list-item">';
				html += '<div class="wudt-list-item__info">';
				html += `<code class="wudt-list-item__title">${escapeHtml(plugin)}</code>`;
				if (info.error && info.error.message) {
					html += `<div class="wudt-list-item__subtitle">${escapeHtml(info.error.message)}</div>`;
				}
				if (info.time) {
					html += `<div class="wudt-list-item__meta">Disabled: ${escapeHtml(info.time)}</div>`;
				}
				html += '</div>';
				html += `<button class="wudt-btn wudt-btn--secondary wudt-btn--sm wudt-restore-plugin" data-plugin="${escapeHtml(plugin)}">Restore</button>`;
				html += '</div>';
			}
			html += '</div>';
		} else {
			html += '<div class="wudt-empty">';
			html += '<div class="wudt-empty__icon">✅</div>';
			html += '<div class="wudt-empty__message">No plugins have been automatically disabled.</div>';
			html += '</div>';
		}
		html += '</div>';
		
		// Disabled Theme
		const disabledTheme = data.disabled_theme || {};
		html += '<div class="wudt-section">';
		html += '<h3>Theme Recovery</h3>';
		if (disabledTheme.theme) {
			html += '<div class="wudt-list">';
			html += '<div class="wudt-list-item">';
			html += '<div class="wudt-list-item__info">';
			html += `<code class="wudt-list-item__title">${escapeHtml(disabledTheme.theme)}</code>`;
			if (disabledTheme.error && disabledTheme.error.message) {
				html += `<div class="wudt-list-item__subtitle">${escapeHtml(disabledTheme.error.message)}</div>`;
			}
			if (disabledTheme.time) {
				html += `<div class="wudt-list-item__meta">Switched: ${escapeHtml(disabledTheme.time)}</div>`;
			}
			html += '</div>';
			html += '<button class="wudt-btn wudt-btn--secondary wudt-btn--sm wudt-restore-theme">Restore Theme</button>';
			html += '</div>';
			html += '</div>';
		} else {
			html += '<div class="wudt-empty">';
			html += '<div class="wudt-empty__icon">✅</div>';
			html += '<div class="wudt-empty__message">No theme changes have been made.</div>';
			html += '</div>';
		}
		html += '</div>';
		
		// Recovery Log
		const recoveryLog = data.recovery_log || [];
		html += '<div class="wudt-section">';
		html += '<h3>Recovery Log</h3>';
		if (recoveryLog.length > 0) {
			html += '<div class="wudt-log">';
			// Show last 10 entries
			const recentLog = recoveryLog.slice(-10).reverse();
			for (const entry of recentLog) {
				html += '<div class="wudt-log-entry">';
				html += `<div class="wudt-log-entry__time">${escapeHtml(entry.time || 'Unknown')}</div>`;
				html += `<div class="wudt-log-entry__action">${escapeHtml(entry.action || 'Unknown')}</div>`;
				if (entry.target) {
					html += `<div class="wudt-log-entry__target">${escapeHtml(entry.target)}</div>`;
				}
				if (entry.error_message) {
					html += `<div class="wudt-log-entry__message">${escapeHtml(entry.error_message)}</div>`;
				}
				html += '</div>';
			}
			html += '</div>';
		} else {
			html += '<div class="wudt-empty">';
			html += '<div class="wudt-empty__message">No recovery events logged yet.</div>';
			html += '</div>';
		}
		html += '</div>';
		
		// Add custom styles
		html += '<style>';
		html += '.wudt-auto-recovery .wudt-section { margin-bottom: 30px; }';
		html += '.wudt-auto-recovery .wudt-section h3 { margin-bottom: 16px; font-size: 1.1rem; }';
		html += '.wudt-auto-recovery .wudt-section-desc { color: var(--wudt-gray-600); margin-bottom: 12px; }';
		html += '.wudt-alert { display: flex; gap: 16px; padding: 16px; border-radius: 8px; margin-bottom: 16px; }';
		html += '.wudt-alert--warning { background: #fff3cd; border: 1px solid #ffc107; }';
		html += '.wudt-alert--success { background: #d4edda; border: 1px solid #28a745; }';
		html += '.wudt-alert__icon { font-size: 24px; }';
		html += '.wudt-alert__content p { margin: 8px 0 0; }';
		html += '.wudt-list { border: 1px solid var(--wudt-gray-200); border-radius: 8px; overflow: hidden; }';
		html += '.wudt-list-item { display: flex; justify-content: space-between; align-items: center; padding: 16px; border-bottom: 1px solid var(--wudt-gray-200); }';
		html += '.wudt-list-item:last-child { border-bottom: none; }';
		html += '.wudt-list-item__info { flex: 1; }';
		html += '.wudt-list-item__title { font-family: monospace; font-size: 0.9rem; }';
		html += '.wudt-list-item__subtitle { font-size: 0.875rem; color: #d63638; margin-top: 4px; }';
		html += '.wudt-list-item__meta { font-size: 0.75rem; color: var(--wudt-gray-600); margin-top: 4px; }';
		html += '.wudt-log { border: 1px solid var(--wudt-gray-200); border-radius: 8px; overflow: hidden; max-height: 300px; overflow-y: auto; }';
		html += '.wudt-log-entry { padding: 12px 16px; border-bottom: 1px solid var(--wudt-gray-200); font-size: 0.875rem; }';
		html += '.wudt-log-entry:last-child { border-bottom: none; }';
		html += '.wudt-log-entry__time { font-size: 0.75rem; color: var(--wudt-gray-600); }';
		html += '.wudt-log-entry__action { font-weight: 600; margin: 4px 0; }';
		html += '.wudt-log-entry__target { font-family: monospace; font-size: 0.8rem; color: var(--wudt-gray-700); }';
		html += '.wudt-log-entry__message { color: #d63638; font-size: 0.8rem; margin-top: 4px; }';
		html += '</style>';
		
		html += '</div>';
		return html;
	}

	function renderDataObject(data, level = 0) {
		if (level > 3) {
			return `<pre class="wudt-pre">${escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
		}

		let html = '<div class="wudt-data-grid">';
		
		for (const [key, value] of Object.entries(data)) {
			const formattedKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
			
			html += '<div class="wudt-data-item">';
			html += `<div class="wudt-data-label">${escapeHtml(formattedKey)}</div>`;
			
			if (typeof value === 'object' && value !== null) {
				if (Array.isArray(value)) {
					html += `<div class="wudt-data-value wudt-badge wudt-badge--neutral">${value.length} items</div>`;
					html += renderDataObject(value, level + 1);
				} else {
					html += renderDataObject(value, level + 1);
				}
			} else {
				const badgeClass = getValueBadgeClass(value);
				html += `<div class="wudt-data-value wudt-badge ${badgeClass}">${escapeHtml(String(value))}</div>`;
			}
			
			html += '</div>';
		}
		
		html += '</div>';
		return html;
	}

	function getValueBadgeClass(value) {
		const str = String(value).toLowerCase();
		if (str === 'true' || str === 'ok' || str === 'success') {
			return 'wudt-badge--success';
		}
		if (str === 'false' || str === 'error' || str === 'failed') {
			return 'wudt-badge--error';
		}
		if (str === 'warning' || str === 'warn') {
			return 'wudt-badge--warning';
		}
		return 'wudt-badge--neutral';
	}

	function showPanelError(tab, message) {
		const $panel = $(`.wudt-panel[data-panel="${tab}"]`);
		const $content = $panel.find('.wudt-panel__content');
		
		$content.html(`
			<div class="wudt-empty">
				<div class="wudt-empty__icon">⚠️</div>
				<div class="wudt-empty__title">Error Loading Data</div>
				<div class="wudt-empty__message">${escapeHtml(message)}</div>
				<button type="button" class="wudt-btn wudt-btn--secondary" onclick="location.reload()">
					Retry
				</button>
			</div>
		`);
	}

	// ============================================
	// Module Operations
	// ============================================
	function runModule(module) {
		const $button = $(`.wudt-run-module[data-module="${module}"]`);
		
		// Show loading state
		WUDTUI.Loading.button($button, true);
		
		WUDTUI.Ajax.post('wudt_run_module', { module: module }).done(function(response) {
			if (response.success) {
				// Update cached data
				state.moduleData[module] = response.data.data;
				
				// Re-render panel
				if (state.currentTab === module) {
					renderPanel(module, response.data.data);
				}
				
				WUDTUI.Toast.success(response.data.message);
			} else {
				WUDTUI.Toast.error(response.data?.message || 'Module check failed');
			}
		}).always(function() {
			WUDTUI.Loading.button($button, false);
		});
	}

	// ============================================
	// Quick Actions
	// ============================================
	function runFullScan() {
		WUDTUI.Modal.confirm(
			'This will run all diagnostic checks. This may take a few minutes.',
			{
				title: 'Full System Scan',
				confirmText: 'Start Scan',
				onConfirm: function() {
					WUDTUI.Toast.info('Starting full system scan...');
					
					// Run each module sequentially
					const modules = window.wudtModernAdmin?.tabs || [];
					let current = 0;
					
					function runNext() {
						if (current >= modules.length) {
							WUDTUI.Toast.success('Full system scan completed!');
							return;
						}
						
						const module = modules[current];
						current++;
						
						WUDTUI.Ajax.post('wudt_run_module', { module: module.key }).always(function() {
							// Small delay between modules
							setTimeout(runNext, 500);
						});
					}
					
					runNext();
				}
			}
		);
	}

	function clearCache() {
		WUDTUI.Toast.info('Clearing cache...');
		
		// Clear local data
		state.moduleData = {};
		
		// Reload current tab
		if (state.currentTab) {
			loadTabData(state.currentTab);
		}
		
		WUDTUI.Toast.success('Cache cleared successfully');
	}

	function exportReport() {
		const reportData = {
			generated_at: new Date().toISOString(),
			site: window.location.hostname,
			modules: state.moduleData
		};
		
		const blob = new Blob([JSON.stringify(reportData, null, 2)], { type: 'application/json' });
		const url = URL.createObjectURL(blob);
		
		const a = document.createElement('a');
		a.href = url;
		a.download = `diagnostics-report-${new Date().toISOString().split('T')[0]}.json`;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
		
		WUDTUI.Toast.success('Report exported successfully');
	}

	function showHelp() {
		WUDTUI.Modal.open({
			title: 'Keyboard Shortcuts',
			content: `
				<div class="wudt-shortcuts-list">
					<div class="wudt-shortcut-item">
						<span class="wudt-shortcut-key">Ctrl + Shift + R</span>
						<span class="wudt-shortcut-desc">Refresh current tab</span>
					</div>
					<div class="wudt-shortcut-item">
						<span class="wudt-shortcut-key">Ctrl + Shift + D</span>
						<span class="wudt-shortcut-desc">Toggle dark mode</span>
					</div>
					<div class="wudt-shortcut-item">
						<span class="wudt-shortcut-key">Tab</span>
						<span class="wudt-shortcut-desc">Navigate between tabs</span>
					</div>
				</div>
				<style>
					.wudt-shortcuts-list { display: grid; gap: 12px; }
					.wudt-shortcut-item { display: flex; justify-content: space-between; align-items: center; }
					.wudt-shortcut-key { background: var(--wudt-gray-100); padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 0.875rem; }
					.wudt-shortcut-desc { color: var(--wudt-gray-600); }
				</style>
			`,
			showFooter: true,
			confirmText: 'Got it'
		});
	}

	// ============================================
	// Auto Recovery Functions
	// ============================================
	function restorePlugin(plugin) {
		WUDTUI.Modal.confirm(
			`Are you sure you want to restore plugin: ${plugin}? This may cause the site to crash again if the error persists.`,
			{
				title: 'Restore Plugin',
				confirmText: 'Restore',
				confirmClass: 'wudt-btn--primary',
				onConfirm: function() {
					WUDTUI.Ajax.post('wudt_restore_plugin', { plugin: plugin }).done(function(response) {
						if (response.success) {
							WUDTUI.Toast.success(response.data.message || 'Plugin restored successfully');
							// Reload auto_recovery tab to update the list
							if (state.currentTab === 'auto_recovery') {
								loadTabData('auto_recovery');
							}
						} else {
							WUDTUI.Toast.error(response.data?.message || 'Failed to restore plugin');
						}
					});
				}
			}
		);
	}

	function restoreTheme() {
		WUDTUI.Modal.confirm(
			'Are you sure you want to restore the previous theme? This may cause the site to crash again if the error persists.',
			{
				title: 'Restore Theme',
				confirmText: 'Restore',
				confirmClass: 'wudt-btn--primary',
				onConfirm: function() {
					WUDTUI.Ajax.post('wudt_restore_theme', {}).done(function(response) {
						if (response.success) {
							WUDTUI.Toast.success(response.data.message || 'Theme restored successfully');
							// Reload auto_recovery tab
							if (state.currentTab === 'auto_recovery') {
								loadTabData('auto_recovery');
							}
						} else {
							WUDTUI.Toast.error(response.data?.message || 'Failed to restore theme');
						}
					});
				}
			}
		);
	}

	function disableSafeMode() {
		WUDTUI.Modal.confirm(
			'Are you sure you want to exit Safe Mode? This will re-enable all WUDT advanced features.',
			{
				title: 'Exit Safe Mode',
				confirmText: 'Exit Safe Mode',
				confirmClass: 'wudt-btn--primary',
				onConfirm: function() {
					WUDTUI.Ajax.post('wudt_disable_safe_mode', {}).done(function(response) {
						if (response.success) {
							WUDTUI.Toast.success(response.data.message || 'Safe mode disabled');
							// Reload auto_recovery tab
							if (state.currentTab === 'auto_recovery') {
								loadTabData('auto_recovery');
							}
						} else {
							WUDTUI.Toast.error(response.data?.message || 'Failed to disable safe mode');
						}
					});
				}
			}
		);
	}

	// ============================================
	// Utilities
	// ============================================
	function toggleDarkMode() {
		$('body').toggleClass('wudt-dark');
		const isDark = $('body').hasClass('wudt-dark');
		localStorage.setItem('wudt-dark-mode', isDark);
		WUDTUI.Toast.info(isDark ? 'Dark mode enabled' : 'Dark mode disabled');
	}

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// ============================================
	// Initialize on DOM ready
	// ============================================
	$(document).ready(init);

})(jQuery);
