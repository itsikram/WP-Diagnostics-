(function ($) {
	'use strict';

	const state = {
		data: window.wudtAdmin?.data || { tabs: [] },
		activeTab: 'system_info',
		darkMode: false,
	};

	function escHtml(str) {
		return String(str || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
	}

	function post(action, payload = {}) {
		return $.post(window.wudtAdmin.ajaxUrl, {
			action,
			nonce: window.wudtAdmin.nonce,
			...payload,
		});
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

	function setStatus(message) {
		$('#wudt-status').text(message);
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
		$('#wudt-dark-mode').on('click', function () { state.darkMode = !state.darkMode; render(); });
		$('#wudt-refresh').on('click', function () {
			setStatus('Refreshing...');
			post('wudt_refresh_dashboard').done((res) => { if (res.success) { state.data = res.data; render(); setStatus('Refreshed'); } });
		});
		$('#wudt-export-json').on('click', function () {
			post('wudt_export_report').done((res) => { if (res.success) window.open(res.data.file, '_blank'); });
		});
		$('#wudt-export-text').on('click', function () {
			navigator.clipboard.writeText(JSON.stringify(state.data, null, 2));
		});
		$('#wudt-email-report').on('click', function () {
			const email = prompt('Send report to email:');
			if (email) post('wudt_email_report', { email }).done((res) => alert((res.data && res.data.message) || 'Done'));
		});
		$('#wudt-clear-logs').on('click', function () { post('wudt_clear_error_logs').done(() => post('wudt_refresh_dashboard').done((res) => { state.data = res.data; render(); })); });
		$('#wudt-toggle-test-mode').on('click', function () {
			const current = (state.data.tabs.find((t) => t.key === 'conflict_detector')?.data?.test_mode_enabled) ? '0' : '1';
			post('wudt_toggle_test_mode', { enabled: current }).done(() => post('wudt_refresh_dashboard').done((res) => { state.data = res.data; render(); }));
		});
		$('#wudt-test-route').on('click', function () {
			setStatus('Testing REST route...');
			post('wudt_test_rest_route', {
				method: $('#wudt-method').val(),
				route: $('#wudt-route').val(),
				body: $('#wudt-body').val(),
			}).done((res) => {
				$('#wudt-rest-result').text(JSON.stringify(res, null, 2));
				setStatus('REST test complete');
			});
		});
	}

	$(render);
})(jQuery);
