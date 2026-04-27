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
			<div class="wudt-toolbar">
				<button class="button button-primary" id="wudt-refresh">Refresh</button>
				<button class="button" id="wudt-export-json">Export JSON</button>
				<button class="button" id="wudt-export-text">Copy Text</button>
				<button class="button" id="wudt-email-report">Email Report</button>
				<button class="button" id="wudt-dark-mode">${state.darkMode ? 'Light Mode' : 'Dark Mode'}</button>
			</div>
			<div class="wudt-tabs">
				${tabs.map((t) => `<button class="wudt-tab ${t.key === state.activeTab ? 'is-active' : ''}" data-key="${escHtml(t.key)}">${escHtml(t.label)}</button>`).join('')}
			</div>
			<div class="wudt-panel">${renderTab(tab.key, tab.data || {})}</div>
		`);
		bindEvents();
	}

	function renderRows(obj) {
		return Object.keys(obj || {}).map((k) => `<tr><th>${escHtml(k)}</th><td>${escHtml(typeof obj[k] === 'object' ? JSON.stringify(obj[k]) : obj[k])}</td></tr>`).join('');
	}

	function renderTab(key, data) {
		if (key === 'system_info') return `<table class="widefat"><tbody>${renderRows(data)}</tbody></table>`;
		if (key === 'error_logs') return `<pre>${escHtml(JSON.stringify(data, null, 2))}</pre><button class="button" id="wudt-clear-logs">Clear Logs</button>`;
		if (key === 'conflict_detector') return `<pre>${escHtml(JSON.stringify(data, null, 2))}</pre><button class="button" id="wudt-toggle-test-mode">${data.test_mode_enabled ? 'Disable' : 'Enable'} Test Mode</button>`;
		if (key === 'rest_api') return `<input id="wudt-route" class="regular-text" placeholder="/wp/v2/posts" /><select id="wudt-method"><option>GET</option><option>POST</option></select><textarea id="wudt-body" rows="4" class="large-text" placeholder='{"per_page":1}'></textarea><button class="button" id="wudt-test-route">Test Route</button><pre id="wudt-rest-result"></pre>`;
		if (key === 'cron') return `<pre>${escHtml(JSON.stringify(data.jobs || [], null, 2))}</pre>`;
		if (key === 'db_tools') return `<pre>${escHtml(JSON.stringify(data, null, 2))}</pre>`;
		return `<pre>${escHtml(JSON.stringify(data, null, 2))}</pre>`;
	}

	function bindEvents() {
		$('.wudt-tab').on('click', function () { state.activeTab = $(this).data('key'); render(); });
		$('#wudt-dark-mode').on('click', function () { state.darkMode = !state.darkMode; render(); });
		$('#wudt-refresh').on('click', function () {
			post('wudt_refresh_dashboard').done((res) => { if (res.success) { state.data = res.data; render(); } });
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
			post('wudt_test_rest_route', {
				method: $('#wudt-method').val(),
				route: $('#wudt-route').val(),
				body: $('#wudt-body').val(),
			}).done((res) => $('#wudt-rest-result').text(JSON.stringify(res, null, 2)));
		});
	}

	$(render);
})(jQuery);
