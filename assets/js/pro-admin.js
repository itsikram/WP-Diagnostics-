(function ($) {
	'use strict';

	const state = {
		data: window.wudtProAdmin?.data || { tabs: [] },
		tab: 'dashboard',
		currentPath: '',
	};

	const tabMap = {
		system_info: 'dashboard',
		file_manager: 'file_manager',
		database_manager: 'database_manager',
		malware_scanner: 'malware_scanner',
		logs: 'logs',
		performance: 'performance',
		security: 'security',
		advanced_tools: 'performance',
		external_requests: 'logs',
	};

	function normalizeTabs() {
		state.data.tabs = (state.data.tabs || []).map((t) => ({ ...t, viewKey: tabMap[t.key] || t.key }));
	}

	function post(action, data = {}) {
		return $.post(window.wudtProAdmin.ajaxUrl, { action, nonce: window.wudtProAdmin.nonce, ...data });
	}

	function esc(text) {
		return String(text || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
	}

	function tabData(key) {
		return state.data.tabs.find((t) => t.key === key)?.data || {};
	}

	function render() {
		normalizeTabs();
		const app = $('#wudt-pro-admin-app');
		const tabs = ['dashboard', 'file_manager', 'database_manager', 'malware_scanner', 'logs', 'performance', 'security'];
		app.html(`
			<div class="wudt-toolbar"><button class="button button-primary" id="wudt-pro-refresh">Refresh</button></div>
			<div class="wudt-tabs">${tabs.map((t) => `<button class="wudt-tab ${state.tab === t ? 'is-active' : ''}" data-tab="${t}">${t.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}</button>`).join('')}</div>
			<div class="wudt-panel">${renderPanel()}</div>
		`);
		bind();
	}

	function renderPanel() {
		if (state.tab === 'dashboard') return `<pre>${esc(JSON.stringify(tabData('system_info'), null, 2))}</pre>`;
		if (state.tab === 'file_manager') return renderFileManager();
		if (state.tab === 'database_manager') return renderDbManager();
		if (state.tab === 'malware_scanner') return renderMalware();
		if (state.tab === 'logs') return `<pre>${esc(JSON.stringify(tabData('logs'), null, 2))}</pre>`;
		if (state.tab === 'performance') return `<pre>${esc(JSON.stringify({performance: tabData('performance'), advanced: tabData('advanced_tools'), requests: tabData('external_requests')}, null, 2))}</pre>`;
		if (state.tab === 'security') return `<pre>${esc(JSON.stringify(tabData('security'), null, 2))}</pre>`;
		return '<p>No data.</p>';
	}

	function renderFileManager() {
		const data = tabData('file_manager');
		return `
			<div class="wudt-toolbar">
				<input id="wudt-fm-path" class="regular-text" value="${esc(state.currentPath || data.root || '')}" />
				<button class="button" id="wudt-fm-open">Open</button>
				<input id="wudt-fm-search-text" class="regular-text" placeholder="Search name/content" />
				<button class="button" id="wudt-fm-search">Search</button>
				<button class="button" id="wudt-fm-zip">Download ZIP</button>
			</div>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
				<pre id="wudt-fm-list">${esc(JSON.stringify(data.entries || [], null, 2))}</pre>
				<div>
					<input id="wudt-fm-file-path" class="regular-text" placeholder="File path" />
					<textarea id="wudt-fm-editor" rows="16" class="large-text code"></textarea>
					<div class="wudt-toolbar"><button class="button" id="wudt-fm-read">Read</button><button class="button button-primary" id="wudt-fm-save">Save</button></div>
				</div>
			</div>`;
	}

	function renderDbManager() {
		const data = tabData('database_manager');
		return `
			<div class="wudt-toolbar">
				<button class="button" id="wudt-dbm-list">Reload Tables</button>
				<label><input type="checkbox" id="wudt-dbm-safe" ${data.safe_mode ? 'checked' : ''}/> Safe Mode</label>
			</div>
			<input id="wudt-dbm-table" class="regular-text" placeholder="Table name" />
			<input id="wudt-dbm-search" class="regular-text" placeholder="Search value" />
			<button class="button" id="wudt-dbm-rows">View Rows</button>
			<button class="button" id="wudt-dbm-export-sql">Export SQL</button>
			<button class="button" id="wudt-dbm-export-csv">Export CSV</button>
			<textarea id="wudt-dbm-query" rows="6" class="large-text code" placeholder="SELECT * FROM wp_options LIMIT 10"></textarea>
			<button class="button button-primary" id="wudt-dbm-run-query">Run Query</button>
			<pre id="wudt-dbm-result">${esc(JSON.stringify(data.table_stats || [], null, 2))}</pre>`;
	}

	function renderMalware() {
		const data = tabData('malware_scanner');
		return `
			<div class="wudt-toolbar"><button class="button button-primary" id="wudt-malware-scan">Run Scan</button></div>
			<pre id="wudt-malware-result">${esc(JSON.stringify(data, null, 2))}</pre>`;
	}

	function bind() {
		$('.wudt-tab').on('click', function () { state.tab = $(this).data('tab'); render(); });
		$('#wudt-pro-refresh').on('click', refresh);

		$('#wudt-fm-open').on('click', function () {
			const path = $('#wudt-fm-path').val();
			post('wudt_fm_list', { path }).done((r) => {
				if (r.success) {
					$('#wudt-fm-list').text(JSON.stringify(r.data.entries, null, 2));
					state.currentPath = r.data.path;
				}
			});
		});
		$('#wudt-fm-read').on('click', function () {
			post('wudt_fm_read', { path: $('#wudt-fm-file-path').val() }).done((r) => { if (r.success) $('#wudt-fm-editor').val(r.data.content); });
		});
		$('#wudt-fm-save').on('click', function () {
			post('wudt_fm_write', { path: $('#wudt-fm-file-path').val(), content: $('#wudt-fm-editor').val() }).done((r) => alert(r.data?.message || 'Saved'));
		});
		$('#wudt-fm-search').on('click', function () {
			post('wudt_fm_search', { path: $('#wudt-fm-path').val(), query: $('#wudt-fm-search-text').val() }).done((r) => { if (r.success) $('#wudt-fm-list').text(JSON.stringify(r.data.results, null, 2)); });
		});
		$('#wudt-fm-zip').on('click', function () {
			post('wudt_fm_download_zip', { path: $('#wudt-fm-path').val() }).done((r) => { if (r.success) window.open(r.data.url, '_blank'); });
		});

		$('#wudt-dbm-safe').on('change', function () { post('wudt_dbm_toggle_safe_mode', { safe_mode: this.checked ? '1' : '0' }); });
		$('#wudt-dbm-list').on('click', function () { post('wudt_dbm_list_tables').done((r) => { if (r.success) $('#wudt-dbm-result').text(JSON.stringify(r.data.tables, null, 2)); }); });
		$('#wudt-dbm-rows').on('click', function () {
			post('wudt_dbm_table_rows', { table: $('#wudt-dbm-table').val(), search: $('#wudt-dbm-search').val(), page: 1, per_page: 50 })
				.done((r) => { if (r.success) $('#wudt-dbm-result').text(JSON.stringify(r.data, null, 2)); });
		});
		$('#wudt-dbm-run-query').on('click', function () {
			post('wudt_dbm_run_query', { query: $('#wudt-dbm-query').val() }).done((r) => $('#wudt-dbm-result').text(JSON.stringify(r, null, 2)));
		});
		$('#wudt-dbm-export-sql').on('click', function () {
			post('wudt_dbm_export', { table: $('#wudt-dbm-table').val(), format: 'sql' }).done((r) => { if (r.success) window.open(r.data.url, '_blank'); });
		});
		$('#wudt-dbm-export-csv').on('click', function () {
			post('wudt_dbm_export', { table: $('#wudt-dbm-table').val(), format: 'csv' }).done((r) => { if (r.success) window.open(r.data.url, '_blank'); });
		});

		$('#wudt-malware-scan').on('click', function () {
			post('wudt_malware_scan').done((r) => $('#wudt-malware-result').text(JSON.stringify(r, null, 2)));
		});
	}

	function refresh() {
		post('wudt_pro_refresh_dashboard').done((r) => { if (r.success) { state.data = r.data; render(); } });
	}

	$(render);
})(jQuery);
