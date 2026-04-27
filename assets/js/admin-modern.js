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
		bindEvents();
		
		// Load first tab by default
		const $firstTab = $('.wudt-tab').first();
		if ($firstTab.length) {
			switchTab($firstTab.data('tab'));
		}

		// Initialize dark mode from saved preference
		if (localStorage.getItem('wudt-dark-mode') === 'true') {
			$('body').addClass('wudt-dark');
		}
	}

	// ============================================
	// Event Binding
	// ============================================
	function bindEvents() {
		// Tab switching
		$(document).on('click', '.wudt-tab', function() {
			const tab = $(this).data('tab');
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

		// Update tab UI
		$('.wudt-tab').removeClass('is-active');
		$(`.wudt-tab[data-tab="${tab}"]`).addClass('is-active');

		// Hide all panels
		$('.wudt-panel').hide();

		// Show target panel
		const $panel = $(`.wudt-panel[data-panel="${tab}"]`);
		$panel.show();

		state.currentTab = tab;

		// Load data if not cached
		if (!state.moduleData[tab]) {
			loadTabData(tab);
		} else {
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
		
		if (typeof data === 'object' && data !== null) {
			html = renderDataObject(data);
		} else {
			html = `<pre class="wudt-pre">${escapeHtml(String(data))}</pre>`;
		}

		$content.html(html);
		
		// Add animation
		$content.addClass('wudt-animate-fade-in');
		setTimeout(() => $content.removeClass('wudt-animate-fade-in'), 300);
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
