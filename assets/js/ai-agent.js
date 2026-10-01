/**
 * WP Diagnostics — AI Agent chat UI (Gemini / Claude / OpenAI / OpenRouter).
 */
(function ($) {
	'use strict';

	// Read lazily: this script loads before the localized wudtProAdmin object is printed.
	function cfg() {
		return window.wudtProAdmin || {};
	}

	var S = {
		root: null,
		boot: null,
		settings: null,
		conversations: [],
		conv: null,          // {id,title,provider,model}
		items: [],
		status: 'idle',      // idle | thinking | approval | error
		error: '',
		stopRequested: false,
		provider: '',
		model: '',
		models: {},
		showSettings: false,
		expanded: {},
		draft: '',
		notice: '',
		settingsDraft: null,
		sidebarOpen: false
	};

	var SUGGESTIONS = [
		{ icon: '🩺', text: 'Check my site for errors and fix anything that is broken.' },
		{ icon: '🎨', text: 'Design a modern, responsive home page for this site with Elementor (hero, services, testimonials, call to action, contact).' },
		{ icon: '⚡', text: 'Why is my site slow? Find the causes and fix what you safely can.' },
		{ icon: '🧯', text: 'Read the latest fatal error in the logs, find the cause and fix it.' },
		{ icon: '🧩', text: 'List the active plugins and tell me which ones are outdated, conflicting or unnecessary.' },
		{ icon: '🖌️', text: 'Set up global Elementor colors and fonts for a clean, professional brand.' }
	];

	/* ----------------------------------------------------------------- */

	function esc(v) {
		return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function ajax(action, data, timeout) {
		return $.ajax({
			url: cfg().ajaxUrl,
			type: 'POST',
			dataType: 'json',
			timeout: timeout || 600000,
			data: $.extend({ action: action, nonce: cfg().nonce }, data || {})
		});
	}

	function errMsg(r, xhr) {
		if (r && r.data && r.data.message) { return r.data.message; }
		if (xhr && xhr.status === 0) { return 'Network error or the request timed out.'; }
		if (xhr && xhr.status) { return 'Server error (HTTP ' + xhr.status + '). Check the PHP error log.'; }
		return 'Unexpected error.';
	}

	/** Minimal, safe Markdown → HTML. */
	function md(src) {
		var text = String(src || '');
		var blocks = [];
		text = text.replace(/```([a-zA-Z0-9_-]*)\n?([\s\S]*?)```/g, function (m, lang, code) {
			blocks.push('<pre class="wudt-ag-code"><code>' + esc(code.replace(/\n$/, '')) + '</code></pre>');
			return '\u0000' + (blocks.length - 1) + '\u0000';
		});
		var lines = esc(text).split('\n');
		var html = '';
		var list = null;
		function closeList() { if (list) { html += '</' + list + '>'; list = null; } }
		lines.forEach(function (line) {
			var m;
			if ((m = line.match(/^\s*[-*•]\s+(.*)$/))) {
				if (list !== 'ul') { closeList(); html += '<ul>'; list = 'ul'; }
				html += '<li>' + inline(m[1]) + '</li>';
			} else if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) {
				if (list !== 'ol') { closeList(); html += '<ol>'; list = 'ol'; }
				html += '<li>' + inline(m[1]) + '</li>';
			} else if ((m = line.match(/^(#{1,4})\s+(.*)$/))) {
				closeList();
				html += '<h' + (m[1].length + 2) + '>' + inline(m[2]) + '</h' + (m[1].length + 2) + '>';
			} else if (/^\s*$/.test(line)) {
				closeList();
				html += '<div class="wudt-ag-gap"></div>';
			} else {
				closeList();
				html += '<p>' + inline(line) + '</p>';
			}
		});
		closeList();
		return html.replace(/\u0000(\d+)\u0000/g, function (m, i) { return blocks[+i]; });
	}

	function inline(s) {
		return s
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
			.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
	}

	function providerInfo(id) {
		var list = (S.settings && S.settings.providers) || [];
		for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } }
		return null;
	}

	function configured() {
		return ((S.settings && S.settings.providers) || []).filter(function (p) { return p.configured; });
	}

	/* ----------------------------------------------------------------- */

	function render() {
		if (!S.root || !document.body.contains(S.root)) { return; }
		var scroller = S.root.querySelector('.wudt-ag-messages');
		var atBottom = !scroller || (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80);
		var draftEl = S.root.querySelector('#wudt-ag-input');
		if (draftEl) { S.draft = draftEl.value; }

		if (!S.settings) {
			S.root.innerHTML = '<div class="wudt-ag"><div class="wudt-ag-loading"><span class="spinner is-active"></span> Loading AI agent…</div></div>';
			return;
		}

		var h = '<div class="wudt-ag' + (S.sidebarOpen ? ' is-sidebar-open' : '') + '">';
		h += renderSidebar();
		h += '<section class="wudt-ag-main">' + renderHeader();
		if (S.notice) { h += '<div class="wudt-ag-notice">' + esc(S.notice) + '</div>'; }
		h += '<div class="wudt-ag-messages">' + renderMessages() + '</div>';
		h += renderComposer() + '</section>';
		if (S.showSettings) { h += renderSettings(); }
		h += '</div>';
		S.root.innerHTML = h;

		var input = S.root.querySelector('#wudt-ag-input');
		if (input) { input.value = S.draft; autosize(input); }
		var sc = S.root.querySelector('.wudt-ag-messages');
		if (sc && (atBottom || S.scrollToBottom)) {
			sc.scrollTop = sc.scrollHeight;
			S.scrollToBottom = false;
		}
	}

	function renderSidebar() {
		var h = '<aside class="wudt-ag-sidebar"><button class="button button-primary wudt-ag-new" data-ag="new">＋ New chat</button><div class="wudt-ag-convs">';
		if (!S.conversations.length) {
			h += '<p class="wudt-ag-muted">No conversations yet.</p>';
		}
		S.conversations.forEach(function (c) {
			var active = S.conv && S.conv.id === c.id;
			h += '<div class="wudt-ag-conv' + (active ? ' is-active' : '') + '" data-ag="open" data-id="' + esc(c.id) + '">'
				+ '<span class="wudt-ag-conv-title">' + esc(c.title || 'Untitled') + '</span>'
				+ '<button class="wudt-ag-conv-del" data-ag="delete" data-id="' + esc(c.id) + '" title="Delete">✕</button></div>';
		});
		return h + '</div></aside>';
	}

	function renderHeader() {
		var list = configured();
		var h = '<header class="wudt-ag-header"><button class="wudt-ag-burger" data-ag="sidebar" title="Conversations">☰</button>'
			+ '<div class="wudt-ag-title">' + esc(S.conv ? S.conv.title : 'AI Agent') + '</div><div class="wudt-ag-controls">';
		if (list.length) {
			h += '<div class="wudt-ag-seg">';
			list.forEach(function (p) {
				h += '<button class="' + (S.provider === p.id ? 'is-active' : '') + '" data-ag="provider" data-id="' + esc(p.id) + '">' + esc(p.label.replace('Anthropic ', '').replace('Google ', '')) + '</button>';
			});
			h += '</div>';
			var models = S.models[S.provider] || (providerInfo(S.provider) || {}).models || [];
			if (S.model && models.indexOf(S.model) === -1) { models = [S.model].concat(models); }
			h += '<select class="wudt-ag-model" data-ag-change="model" title="Model">';
			models.forEach(function (m) { h += '<option value="' + esc(m) + '"' + (m === S.model ? ' selected' : '') + '>' + esc(m) + '</option>'; });
			h += '</select>';
		}
		h += '<label class="wudt-ag-toggle" title="Run changes without asking each time (all changes can still be undone)"><input type="checkbox" data-ag-change="auto"' + (S.settings.auto_approve ? ' checked' : '') + '> Auto-approve</label>'
			+ '<button class="wudt-ag-icon" data-ag="settings" title="AI settings">⚙</button></div></header>';
		return h;
	}

	function renderMessages() {
		if (!configured().length) {
			return '<div class="wudt-ag-empty"><div class="wudt-ag-empty-icon">🔑</div><h2>Connect an AI provider</h2>'
				+ '<p>Add a Google Gemini or Anthropic Claude API key to start. You can add both and switch at any time.</p>'
				+ '<button class="button button-primary button-hero" data-ag="settings">Add API key</button></div>';
		}
		if (!S.items.length && S.status === 'idle') {
			var h = '<div class="wudt-ag-empty"><div class="wudt-ag-empty-icon">✨</div><h2>What should I do on ' + esc((S.boot.site || {}).name || 'your site') + '?</h2>'
				+ '<p>I can diagnose and fix errors, edit code safely, manage plugins and themes, and design Elementor pages. Changes are backed up and can be undone.</p><div class="wudt-ag-suggest">';
			SUGGESTIONS.forEach(function (s) {
				if (!S.boot.elementor && /Elementor/.test(s.text)) { return; }
				h += '<button data-ag="suggest" data-text="' + esc(s.text) + '"><span>' + s.icon + '</span>' + esc(s.text) + '</button>';
			});
			return h + '</div></div>';
		}
		var out = '';
		var pending = [];
		S.items.forEach(function (it) {
			if (it.type === 'user') {
				out += '<div class="wudt-ag-msg is-user"><div class="wudt-ag-bubble">' + esc(it.text).replace(/\n/g, '<br>') + '</div></div>';
			} else if (it.type === 'assistant') {
				out += '<div class="wudt-ag-msg is-ai"><div class="wudt-ag-avatar">AI</div><div class="wudt-ag-bubble">' + md(it.text) + '</div></div>';
			} else if (it.type === 'tool') {
				if (it.status === 'pending') { pending.push(it); }
				out += renderTool(it);
			}
		});
		if (S.status === 'thinking') {
			out += '<div class="wudt-ag-msg is-ai"><div class="wudt-ag-avatar">AI</div><div class="wudt-ag-thinking"><i></i><i></i><i></i> <span>' + esc(S.thinkingLabel || 'Thinking…') + '</span></div></div>';
		}
		if (S.status === 'approval' && pending.length) {
			out += '<div class="wudt-ag-approval"><div><strong>Approve ' + (pending.length > 1 ? pending.length + ' changes' : 'this change') + '?</strong>'
				+ '<span class="wudt-ag-muted"> Everything is backed up and can be undone.</span></div>'
				+ '<div class="wudt-ag-approval-actions"><button class="button button-primary" data-ag="approve-all">Approve</button>'
				+ '<button class="button" data-ag="approve-always">Approve &amp; don’t ask again</button>'
				+ '<button class="button wudt-ag-danger" data-ag="reject-all">Reject</button></div></div>';
		}
		if (S.status === 'error') {
			out += '<div class="wudt-ag-error"><strong>Something went wrong.</strong> ' + esc(S.error)
				+ '<div><button class="button" data-ag="retry">Retry</button> <button class="button-link" data-ag="settings">Check AI settings</button></div></div>';
		}
		return out;
	}

	function renderTool(it) {
		var icons = { done: '✓', error: '!', pending: '⏸', rejected: '✕', running: '…' };
		var open = !!S.expanded[it.id];
		var h = '<div class="wudt-ag-tool is-' + esc(it.status) + (it.write ? ' is-write' : '') + '">'
			+ '<button class="wudt-ag-tool-head" data-ag="expand" data-id="' + esc(it.id) + '">'
			+ '<span class="wudt-ag-tool-icon">' + (icons[it.status] || '•') + '</span>'
			+ '<span class="wudt-ag-tool-sum">' + esc(it.summary) + '</span>'
			+ '<span class="wudt-ag-tool-chev">' + (open ? '▾' : '▸') + '</span></button>';
		var meta = it.meta || {};
		var links = (meta.links || []).map(function (l) {
			return '<a class="button button-small" href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.label) + '</a>';
		}).join(' ');
		if (links || (meta.change_id && it.status === 'done')) {
			h += '<div class="wudt-ag-tool-links">' + links
				+ (meta.change_id && it.status === 'done' ? ' <button class="button button-small" data-ag="undo" data-id="' + esc(meta.change_id) + '">Undo</button>' : '') + '</div>';
		}
		if (open) {
			h += '<div class="wudt-ag-tool-body">';
			var args = it.args || {};
			Object.keys(args).forEach(function (k) {
				var v = args[k];
				var pretty = v;
				if (/^[[{]/.test(String(v))) {
					try { pretty = JSON.stringify(JSON.parse(v), null, 2); } catch (e) { /* keep raw */ }
				}
				h += '<div class="wudt-ag-kv"><span>' + esc(k) + '</span><pre>' + esc(pretty) + '</pre></div>';
			});
			if (it.result) { h += '<div class="wudt-ag-kv"><span>result</span><pre>' + esc(it.result) + '</pre></div>'; }
			if (it.status === 'pending') {
				h += '<div class="wudt-ag-tool-decide"><button class="button button-primary button-small" data-ag="approve-one" data-id="' + esc(it.id) + '">Approve only this</button> '
					+ '<button class="button button-small" data-ag="reject-one" data-id="' + esc(it.id) + '">Reject only this</button></div>';
			}
			h += '</div>';
		}
		return h + '</div>';
	}

	function renderComposer() {
		var busy = S.status === 'thinking';
		var disabled = !configured().length;
		return '<footer class="wudt-ag-composer"><textarea id="wudt-ag-input" rows="1" placeholder="' + (disabled ? 'Add an API key to start…' : 'Ask me to fix an error, build a page… (Shift+Enter = new line)') + '"' + (disabled ? ' disabled' : '') + '></textarea>'
			+ (busy
				? '<button class="button wudt-ag-send is-stop" data-ag="stop">■ Stop</button>'
				: '<button class="button button-primary wudt-ag-send" data-ag="send"' + (disabled ? ' disabled' : '') + '>Send ➤</button>')
			+ '</footer>';
	}

	function renderSettings() {
		var d = S.settingsDraft;
		var h = '<div class="wudt-ag-modal" data-ag="close-settings-bg"><div class="wudt-ag-dialog" role="dialog" aria-label="AI settings">'
			+ '<div class="wudt-ag-dialog-head"><h2>AI settings</h2><button class="wudt-ag-icon" data-ag="close-settings">✕</button></div>'
			+ '<p class="wudt-ag-muted">Keys are stored encrypted in this site’s database. Add both Gemini and Claude to switch between them in the chat.</p>';
		S.settings.providers.forEach(function (p) {
			var pd = d.providers[p.id];
			h += '<div class="wudt-ag-provider' + (p.configured ? ' is-configured' : '') + '"><div class="wudt-ag-provider-head"><strong>' + esc(p.label) + '</strong>'
				+ (p.configured ? '<span class="wudt-ag-badge">Key saved · ' + esc(p.key_preview) + '</span>' : '<a href="' + esc(p.key_url) + '" target="_blank" rel="noopener">Get a key ↗</a>') + '</div>'
				+ '<div class="wudt-ag-provider-row"><input type="password" autocomplete="new-password" class="wudt-ag-input" data-key="' + esc(p.id) + '" value="' + esc(pd.key) + '" placeholder="' + (p.configured ? 'Enter a new key to replace it' : 'API key (' + esc(p.key_hint) + ')') + '">'
				+ '<input type="text" class="wudt-ag-input" list="wudt-ag-ml-' + esc(p.id) + '" data-model="' + esc(p.id) + '" value="' + esc(pd.model) + '" placeholder="Model">'
				+ '<datalist id="wudt-ag-ml-' + esc(p.id) + '">' + (S.models[p.id] || p.models).map(function (m) { return '<option value="' + esc(m) + '">'; }).join('') + '</datalist></div>'
				+ '<div class="wudt-ag-provider-actions">'
				+ (p.configured ? '<button class="button button-small" data-ag="test" data-id="' + esc(p.id) + '">Test connection</button> <button class="button button-small" data-ag="load-models" data-id="' + esc(p.id) + '">Load models</button> <button class="button-link wudt-ag-danger" data-ag="remove-key" data-id="' + esc(p.id) + '">Remove key</button>' : '')
				+ '<span class="wudt-ag-test" id="wudt-ag-test-' + esc(p.id) + '"></span></div></div>';
		});
		h += '<div class="wudt-ag-provider"><label><input type="checkbox" id="wudt-ag-set-auto"' + (d.auto_approve ? ' checked' : '') + '> Auto-approve changes (no confirmation for each change)</label>'
			+ '<label class="wudt-ag-inline">Max output tokens <input type="number" class="small-text" id="wudt-ag-set-tokens" min="1024" max="64000" step="1000" value="' + esc(d.max_tokens) + '"></label></div>'
			+ '<div class="wudt-ag-dialog-foot"><button class="button" data-ag="close-settings">Cancel</button><button class="button button-primary" data-ag="save-settings">Save settings</button></div>'
			+ '</div></div>';
		return h;
	}

	function autosize(el) {
		el.style.height = 'auto';
		el.style.height = Math.min(220, el.scrollHeight) + 'px';
	}

	/* ----------------------------------------------------------------- */

	function applyResponse(data) {
		if (data.conversation) {
			var isNew = !S.conv || S.conv.id !== data.conversation.id;
			S.conv = data.conversation;
			if (isNew) { refreshConversations(); }
		}
		if (data.items) { S.items = data.items; }
	}

	function handle(r, xhr) {
		if (!r || !r.success) {
			S.status = 'error';
			S.error = errMsg(r, xhr);
			render();
			return;
		}
		applyResponse(r.data);
		var st = r.data.status;
		if (st === 'error') {
			S.status = 'error';
			S.error = r.data.error || 'The AI provider returned an error.';
		} else if (st === 'continue') {
			if (S.stopRequested) {
				S.status = 'idle';
				S.stopRequested = false;
			} else {
				S.status = 'thinking';
				S.thinkingLabel = 'Working…';
				render();
				proceed({});
				return;
			}
		} else if (st === 'approval') {
			S.status = 'approval';
		} else {
			S.status = 'idle';
		}
		render();
	}

	function send(text) {
		text = $.trim(text || '');
		if (!text || S.status === 'thinking') { return; }
		S.draft = '';
		var input = S.root && S.root.querySelector('#wudt-ag-input');
		if (input) { input.value = ''; }
		S.stopRequested = false;
		S.scrollToBottom = true;
		S.items.push({ type: 'user', text: text });
		S.status = 'thinking';
		S.thinkingLabel = 'Thinking…';
		render();
		ajax('wudt_ai_agent_send', {
			id: S.conv ? S.conv.id : '',
			message: text,
			provider: S.provider,
			model: S.model,
			auto_approve: S.settings.auto_approve ? 1 : ''
		}).done(function (r) { handle(r); }).fail(function (xhr) { handle(null, xhr); });
	}

	function proceed(extra) {
		if (!S.conv) { return; }
		S.status = 'thinking';
		render();
		ajax('wudt_ai_agent_continue', $.extend({
			id: S.conv.id,
			provider: S.provider,
			model: S.model,
			auto_approve: S.settings.auto_approve ? 1 : ''
		}, extra || {})).done(function (r) { handle(r); }).fail(function (xhr) { handle(null, xhr); });
	}

	function decide(decisions) {
		S.thinkingLabel = 'Applying changes…';
		proceed({ decisions: JSON.stringify(decisions) });
	}

	function pendingIds() {
		return S.items.filter(function (i) { return i.type === 'tool' && i.status === 'pending'; }).map(function (i) { return i.id; });
	}

	function refreshConversations() {
		ajax('wudt_ai_agent_bootstrap').done(function (r) {
			if (r && r.success) { S.conversations = r.data.conversations || []; render(); }
		});
	}

	function openConversation(id) {
		S.status = 'thinking';
		S.thinkingLabel = 'Loading…';
		S.items = [];
		S.sidebarOpen = false;
		render();
		ajax('wudt_ai_agent_conversation', { id: id }).done(function (r) {
			if (!r || !r.success) { S.status = 'error'; S.error = errMsg(r); render(); return; }
			S.conv = { id: r.data.id, title: r.data.title, provider: r.data.provider, model: r.data.model };
			S.items = r.data.items || [];
			if (r.data.provider && providerInfo(r.data.provider) && providerInfo(r.data.provider).configured) {
				S.provider = r.data.provider;
				S.model = r.data.model || providerInfo(r.data.provider).model;
			}
			S.status = r.data.status === 'approval' ? 'approval' : 'idle';
			S.scrollToBottom = true;
			render();
		}).fail(function (xhr) { S.status = 'error'; S.error = errMsg(null, xhr); render(); });
	}

	function openSettings() {
		var draft = { providers: {}, auto_approve: S.settings.auto_approve, max_tokens: S.settings.max_tokens };
		S.settings.providers.forEach(function (p) { draft.providers[p.id] = { key: '', model: p.model }; });
		S.settingsDraft = draft;
		S.showSettings = true;
		render();
	}

	function collectSettingsDraft() {
		if (!S.settingsDraft) { return; }
		$(S.root).find('[data-key]').each(function () { S.settingsDraft.providers[$(this).data('key')].key = $(this).val(); });
		$(S.root).find('[data-model]').each(function () { S.settingsDraft.providers[$(this).data('model')].model = $(this).val(); });
		S.settingsDraft.auto_approve = $('#wudt-ag-set-auto').is(':checked');
		S.settingsDraft.max_tokens = $('#wudt-ag-set-tokens').val();
	}

	function applySettings(settings) {
		S.settings = settings;
		var p = providerInfo(S.provider);
		if (!p || !p.configured) {
			S.provider = settings.active;
			p = providerInfo(S.provider);
		}
		if (p && (!S.model || S.modelProvider !== S.provider)) {
			S.model = p.model;
			S.modelProvider = S.provider;
		}
	}

	function saveSettings(extra) {
		collectSettingsDraft();
		var keys = {};
		var models = {};
		Object.keys(S.settingsDraft.providers).forEach(function (id) {
			keys[id] = S.settingsDraft.providers[id].key;
			models[id] = S.settingsDraft.providers[id].model;
		});
		return ajax('wudt_ai_agent_save_settings', $.extend({
			keys: JSON.stringify(keys),
			models: JSON.stringify(models),
			auto_approve: S.settingsDraft.auto_approve ? '1' : '0',
			max_tokens: S.settingsDraft.max_tokens
		}, extra || {}), 60000).done(function (r) {
			if (r && r.success) {
				var before = S.provider;
				applySettings(r.data.settings);
				if (!before || !providerInfo(before) || !providerInfo(before).configured) {
					S.provider = r.data.settings.active;
					S.model = (providerInfo(S.provider) || {}).model || '';
				} else {
					S.model = (providerInfo(S.provider) || {}).model || S.model;
				}
				S.settingsDraft.providers = {};
				S.settings.providers.forEach(function (p) { S.settingsDraft.providers[p.id] = { key: '', model: p.model }; });
			}
		});
	}

	function loadModels(provider, refresh) {
		return ajax('wudt_ai_agent_models', { provider: provider, refresh: refresh ? 1 : '' }, 60000).done(function (r) {
			if (r && r.success) { S.models[provider] = r.data.models || []; render(); }
		});
	}

	/* ----------------------------------------------------------------- */

	function bindEvents() {
		$(document).off('.wudtag');

		$(document).on('click.wudtag', '#wudt-ai-agent-root [data-ag]', function (e) {
			var $b = $(this);
			var act = $b.data('ag');
			var id = $b.data('id');
			if (act === 'close-settings-bg' && e.target !== this) { return; }
			if (act !== 'open') { e.preventDefault(); }
			e.stopPropagation();

			switch (act) {
				case 'new':
					S.conv = null; S.items = []; S.status = 'idle'; S.sidebarOpen = false; render();
					$('#wudt-ag-input').trigger('focus');
					break;
				case 'open':
					if ($(e.target).closest('.wudt-ag-conv-del').length) { return; }
					openConversation(id);
					break;
				case 'delete':
					if (!window.confirm('Delete this conversation? (Changes made on the site are not undone.)')) { return; }
					ajax('wudt_ai_agent_delete', { id: id }).done(function (r) {
						if (r && r.success) {
							S.conversations = r.data.conversations || [];
							if (S.conv && S.conv.id === id) { S.conv = null; S.items = []; S.status = 'idle'; }
							render();
						}
					});
					break;
				case 'sidebar':
					S.sidebarOpen = !S.sidebarOpen; render();
					break;
				case 'provider':
					S.provider = id;
					S.model = (providerInfo(id) || {}).model || '';
					S.modelProvider = id;
					render();
					if (!S.models[id]) { loadModels(id, false); }
					break;
				case 'suggest':
					send($b.data('text'));
					break;
				case 'send':
					send($('#wudt-ag-input').val());
					break;
				case 'stop':
					S.stopRequested = true;
					S.thinkingLabel = 'Stopping after the current step…';
					render();
					break;
				case 'retry':
					S.error = '';
					S.thinkingLabel = 'Retrying…';
					proceed({});
					break;
				case 'expand':
					S.expanded[id] = !S.expanded[id]; render();
					break;
				case 'approve-all':
					var all = {};
					pendingIds().forEach(function (pid) { all[pid] = 'approve'; });
					decide(all);
					break;
				case 'approve-always':
					S.settings.auto_approve = true;
					ajax('wudt_ai_agent_save_settings', { auto_approve: '1' });
					var all2 = {};
					pendingIds().forEach(function (pid) { all2[pid] = 'approve'; });
					decide(all2);
					break;
				case 'reject-all':
					var none = {};
					pendingIds().forEach(function (pid) { none[pid] = 'reject'; });
					decide(none);
					break;
				case 'approve-one':
				case 'reject-one':
					var one = {};
					pendingIds().forEach(function (pid) { one[pid] = pid === id ? (act === 'approve-one' ? 'approve' : 'reject') : (act === 'approve-one' ? 'reject' : 'approve'); });
					decide(one);
					break;
				case 'undo':
					if (!window.confirm('Undo this change?')) { return; }
					$b.prop('disabled', true).text('Undoing…');
					ajax('wudt_ai_agent_undo', { change_id: id }).done(function (r) {
						S.notice = r && r.success ? r.data.message : errMsg(r);
						$b.text(r && r.success ? 'Undone' : 'Undo');
						render();
						setTimeout(function () { S.notice = ''; render(); }, 6000);
					});
					break;
				case 'settings':
					openSettings();
					break;
				case 'close-settings':
				case 'close-settings-bg':
					S.showSettings = false; render();
					break;
				case 'save-settings':
					$b.prop('disabled', true).text('Saving…');
					saveSettings().done(function (r) {
						if (r && r.success) { S.showSettings = false; S.notice = 'AI settings saved.'; setTimeout(function () { S.notice = ''; render(); }, 4000); }
						else { window.alert(errMsg(r)); }
						render();
					}).fail(function (xhr) { window.alert(errMsg(null, xhr)); render(); });
					break;
				case 'remove-key':
					if (!window.confirm('Remove this API key?')) { return; }
					collectSettingsDraft();
					S.settingsDraft.providers[id].key = '__delete__';
					saveSettings().always(function () { render(); });
					break;
				case 'test':
					var $out = $('#wudt-ag-test-' + id).text('Testing…').removeClass('is-ok is-bad');
					collectSettingsDraft();
					ajax('diagnostics_ai_test_api', { provider: id, model: S.settingsDraft.providers[id].model }, 120000).done(function (r) {
						$out.text(r && r.success ? '✓ ' + r.data.message : '✕ ' + errMsg(r)).addClass(r && r.success ? 'is-ok' : 'is-bad');
					}).fail(function (xhr) { $out.text('✕ ' + errMsg(null, xhr)).addClass('is-bad'); });
					break;
				case 'load-models':
					collectSettingsDraft();
					$b.prop('disabled', true).text('Loading…');
					loadModels(id, true).always(function () { render(); });
					break;
			}
		});

		$(document).on('change.wudtag', '#wudt-ai-agent-root [data-ag-change]', function () {
			var what = $(this).data('ag-change');
			if (what === 'model') {
				S.model = $(this).val();
				var models = {};
				models[S.provider] = S.model;
				ajax('wudt_ai_agent_save_settings', { models: JSON.stringify(models) });
			} else if (what === 'auto') {
				S.settings.auto_approve = $(this).is(':checked');
				ajax('wudt_ai_agent_save_settings', { auto_approve: S.settings.auto_approve ? '1' : '0' });
			}
		});

		$(document).on('keydown.wudtag', '#wudt-ag-input', function (e) {
			if ((e.key === 'Enter' || e.keyCode === 13) && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				send($(this).val());
			}
		});
		$(document).on('input.wudtag', '#wudt-ag-input', function () {
			S.draft = this.value;
			autosize(this);
		});
	}

	window.WUDTAgent = {
		mount: function (el) {
			S.root = el;
			bindEvents();
			if (S.settings) { render(); return; }
			render();
			ajax('wudt_ai_agent_bootstrap', {}, 60000).done(function (r) {
				if (!r || !r.success) {
					el.innerHTML = '<div class="notice notice-error"><p>' + esc(errMsg(r)) + '</p></div>';
					return;
				}
				S.boot = r.data;
				S.conversations = r.data.conversations || [];
				applySettings(r.data.settings);
				render();
				if (S.provider && providerInfo(S.provider) && providerInfo(S.provider).configured) { loadModels(S.provider, false); }
			}).fail(function (xhr) {
				el.innerHTML = '<div class="notice notice-error"><p>' + esc(errMsg(null, xhr)) + '</p></div>';
			});
		}
	};
})(jQuery);
