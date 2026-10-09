/**
 * Diagnostics Toolkit - Admin JavaScript
 * Enhanced with modern UI utilities
 */

(function ($) {
	'use strict';

	function getSavedDarkMode() {
		try {
			return window.localStorage.getItem('wudt-dark-mode') === 'true';
		} catch (e) {
			return false;
		}
	}

	const state = {
		data: (window.wudtAdmin && window.wudtAdmin.data) || { tabs: [] },
		activeTab: 'system_info',
		darkMode: getSavedDarkMode(),
	};

	function escHtml(str) {
		return String(str || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
	}

	function post(action, payload = {}) {
		return $.ajax({
			url: window.wudtAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: Object.assign({
				action,
				nonce: window.wudtAdmin.nonce,
			}, payload),
		});
	}

	function responseMessage(res, xhr) {
		if (res && res.data && res.data.message) {
			return res.data.message;
		}
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		if (xhr && xhr.status) {
			return `Request failed (HTTP ${xhr.status}).`;
		}
		return 'The request could not be completed. Please try again.';
	}

	function notify(type, message) {
		$('#wudt-status').text(message);
		if (window.WUDTUI && window.WUDTUI.Toast && window.WUDTUI.Toast[type]) {
			window.WUDTUI.Toast[type](escHtml(message));
		}
	}

	function runButton($button, pendingText, request, onSuccess) {
		const originalText = $button.text();
		$button.prop('disabled', true).text(pendingText);
		request.done((res) => {
			if (res && res.success) {
				onSuccess(res);
			} else {
				notify('error', responseMessage(res));
			}
		}).fail((xhr) => {
			notify('error', responseMessage(null, xhr));
		}).always(() => {
			$button.prop('disabled', false).text(originalText);
		});
	}

	function refreshData(onSuccess) {
		post('wudt_refresh_dashboard').done((res) => {
			if (res && res.success) {
				state.data = res.data;
				render();
				onSuccess();
			} else {
				notify('error', responseMessage(res));
			}
		}).fail((xhr) => {
			notify('error', responseMessage(null, xhr));
		});
	}

	function copyText(text) {
		const legacyCopy = () => new Promise((resolve, reject) => {
			const textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', '');
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			document.body.appendChild(textarea);
			textarea.select();
			const copied = document.execCommand('copy');
			textarea.remove();
			if (copied) {
				resolve();
			} else {
				reject(new Error('Clipboard access is unavailable in this browser.'));
			}
		});

		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).catch(legacyCopy);
		}
		return legacyCopy();
	}

	function downloadReport(content, filename) {
		const blob = new Blob([content], { type: 'application/json;charset=utf-8' });
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = filename || 'diagnostic-report.json';
		link.hidden = true;
		document.body.appendChild(link);
		link.click();
		link.remove();
		window.setTimeout(() => window.URL.revokeObjectURL(url), 1000);
	}

	function render() {
		const tabs = state.data.tabs || [];
		const tab = tabs.find((t) => t.key === state.activeTab) || tabs[0] || { data: {} };
		const app = $('#wudt-admin-app');
		app.toggleClass('wudt-dark', state.darkMode);
		app.html(`
			<div class="wudt-app-shell">
				<div class="wudt-toolbar">
					<span class="wudt-badge">Generated: ${escHtml(state.data.generated_at || 'N/A')}</span>
					<button class="button button-primary" id="wudt-refresh">Refresh</button>
					<button class="button" id="wudt-export-json">Export JSON</button>
					<button class="button" id="wudt-export-text">Copy Text</button>
					<button class="button" id="wudt-email-report">Email Report</button>
					<button class="button" id="wudt-dark-mode">${state.darkMode ? 'Light Mode' : 'Dark Mode'}</button>
					<span class="wudt-status" id="wudt-status"></span>
				</div>
				<div class="wudt-tabs">
					${tabs.map((t) => `<button class="wudt-tab ${t.key === state.activeTab ? 'is-active' : ''}" data-key="${escHtml(t.key)}">${escHtml(t.label)}</button>`).join('')}
				</div>
				<div class="wudt-panel">${renderTab(tab.key, tab.data || {})}</div>
			</div>
		`);
		bindEvents();
	}

	function renderRows(obj, limit = 200) {
		return Object.keys(obj || {}).slice(0, limit).map((k) => `<tr><th>${escHtml(k)}</th><td>${escHtml(typeof obj[k] === 'object' ? JSON.stringify(obj[k]) : obj[k])}</td></tr>`).join('');
	}

	function renderJsonCard(title, data) {
		return `<div class="wudt-card"><h3>${escHtml(title)}</h3><pre class="wudt-pre">${escHtml(JSON.stringify(data, null, 2))}</pre></div>`;
	}

	function renderTab(key, data) {
		if (key === 'system_info') {
			return `
				<div class="wudt-grid">
					<div class="wudt-col-6 wudt-card"><h3>Environment</h3><table class="wudt-kv"><tbody>${renderRows(data)}</tbody></table></div>
					<div class="wudt-col-6 wudt-card"><h3>Plugins</h3><pre class="wudt-pre">${escHtml(JSON.stringify(data.plugins || [], null, 2))}</pre></div>
				</div>`;
		}
		if (key === 'error_logs') return `<div class="wudt-toolbar"><button class="button" id="wudt-clear-logs">Clear Logs</button></div>${renderJsonCard('Error Logs', data)}`;
		if (key === 'conflict_detector') return `<div class="wudt-toolbar"><button class="button" id="wudt-toggle-test-mode">${data.test_mode_enabled ? 'Disable' : 'Enable'} Test Mode</button></div>${renderJsonCard('Conflict Insights', data)}`;
		if (key === 'rest_api') {
			return `
				<div class="wudt-card">
					<h3>REST Route Tester</h3>
					<div class="wudt-grid">
						<div class="wudt-col-8"><input id="wudt-route" class="wudt-input" placeholder="/wp/v2/posts" /></div>
						<div class="wudt-col-4"><select id="wudt-method" class="wudt-select"><option>GET</option><option>POST</option></select></div>
						<div class="wudt-col-12"><textarea id="wudt-body" class="wudt-textarea" placeholder='{"per_page":1}'></textarea></div>
						<div class="wudt-col-12"><button class="button button-primary" id="wudt-test-route">Test Route</button></div>
					</div>
					<pre id="wudt-rest-result" class="wudt-pre"></pre>
				</div>`;
		}
		if (key === 'cron') return renderJsonCard('Cron Jobs', data.jobs || []);
		if (key === 'db_tools') return renderJsonCard('Database Diagnostics', data);
		return renderJsonCard('Diagnostics Data', data);
	}

	function bindEvents() {
		$('.wudt-tab').on('click', function () { state.activeTab = $(this).data('key'); render(); });
		$('#wudt-dark-mode').on('click', function () {
			state.darkMode = !state.darkMode;
			let preferenceSaved = true;
			try {
				window.localStorage.setItem('wudt-dark-mode', state.darkMode);
			} catch (e) {
				preferenceSaved = false;
			}
			render();
			notify(
				preferenceSaved ? 'success' : 'warning',
				preferenceSaved
					? (state.darkMode ? 'Dark mode enabled' : 'Light mode enabled')
					: 'Theme changed for this session, but the preference could not be saved.'
			);
		});
		$('#wudt-refresh').on('click', function () {
			const $button = $(this);
			runButton($button, 'Refreshing...', post('wudt_refresh_dashboard'), (res) => {
				state.data = res.data;
				render();
				notify('success', 'Dashboard refreshed successfully.');
			});
		});
		$('#wudt-export-json').on('click', function () {
			const $button = $(this);
			runButton($button, 'Exporting...', post('wudt_export_report'), (res) => {
				if (!res.data || !res.data.content) {
					notify('error', 'The report could not be prepared for download.');
					return;
				}
				downloadReport(res.data.content, res.data.filename);
				notify('success', 'Diagnostic report downloaded.');
			});
		});
		$('#wudt-export-text').on('click', function () {
			copyText(JSON.stringify(state.data, null, 2)).then(() => {
				notify('success', 'Report copied to clipboard.');
			}).catch((error) => {
				notify('error', error.message || 'Could not copy the report.');
			});
		});
		$('#wudt-email-report').on('click', function () {
			const send = (email) => {
				post('wudt_email_report', { email }).done((res) => {
					if (res && res.success) {
						notify('success', res.data.message || 'Report sent successfully.');
					} else {
						notify('error', responseMessage(res));
					}
				}).fail((xhr) => {
					notify('error', responseMessage(null, xhr));
				});
			};
			if (window.WUDTUI && window.WUDTUI.Modal && window.WUDTUI.Modal.open) {
				window.WUDTUI.Modal.open({
					title: 'Email Report',
					content: '<p>Enter email address to send the report:</p><input type="email" id="wudt-email-input" class="wudt-input" placeholder="email@example.com" required>',
					confirmText: 'Send',
					onConfirm: function () {
						const input = document.getElementById('wudt-email-input');
						send(input ? input.value.trim() : '');
					}
				});
			} else {
				const email = window.prompt('Send report to email:');
				if (email !== null) { send(email.trim()); }
			}
		});
		$('#wudt-clear-logs').on('click', function () {
			const $button = $(this);
			if (!window.confirm('Clear all saved diagnostic error logs?')) { return; }
			runButton($button, 'Clearing...', post('wudt_clear_error_logs'), () => {
				refreshData(() => notify('success', 'Error logs cleared.'));
			});
		});
		$('#wudt-toggle-test-mode').on('click', function () {
			const detector = state.data.tabs.find((tab) => tab.key === 'conflict_detector');
			const enabled = detector && detector.data && detector.data.test_mode_enabled ? '0' : '1';
			const $button = $(this);
			runButton($button, 'Saving...', post('wudt_toggle_test_mode', { enabled }), () => {
				refreshData(() => notify('success', enabled === '1' ? 'Test mode enabled.' : 'Test mode disabled.'));
			});
		});
		$('#wudt-test-route').on('click', function () {
			const $button = $(this);
			const route = $.trim($('#wudt-route').val());
			if (!route) {
				notify('warning', 'Enter a REST API route to test.');
				return;
			}
			runButton($button, 'Testing...', post('wudt_test_rest_route', {
				method: $('#wudt-method').val(),
				route,
				body: $('#wudt-body').val(),
			}), (res) => {
				$('#wudt-rest-result').text(JSON.stringify(res.data, null, 2));
				notify('success', 'REST route test completed.');
			});
		});
	}

	// Apply dark mode on init
	if (state.darkMode) {
		$('#wudt-admin-app').addClass('wudt-dark');
	}

	$(render);
})(jQuery);
