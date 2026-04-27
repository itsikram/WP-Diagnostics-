/**
 * WP Ultimate Diagnostics Toolkit - Search Tool JavaScript
 * Global search and replace functionality
 */

(function($) {
	'use strict';

	// State management
	const state = {
		searchText: '',
		replaceText: '',
		isSearching: false,
		fileResults: [],
		dbResults: [],
		currentPreview: null,
		options: {
			regex: false,
			caseSensitive: false,
			wholeWord: false,
			searchFiles: true,
			searchDb: false,
			directory: 'wp-content',
			extensions: ['php', 'js', 'css'],
			tables: [],
			createBackup: true
		}
	};

	// ============================================
	// Initialization
	// ============================================
	function init() {
		bindEvents();
		loadTables();
		loadSearchHistory();
	}

	// ============================================
	// Event Binding
	// ============================================
	function bindEvents() {
		// Search button
		$(document).on('click', '#wudt-search-btn', function() {
			performSearch();
		});

		// Enter key in search input
		$(document).on('keypress', '#wudt-search-input', function(e) {
			if (e.which === 13) {
				performSearch();
			}
		});

		// Malware pattern buttons
		$(document).on('click', '.wudt-pattern-presets button', function() {
			const pattern = $(this).data('pattern');
			$('#wudt-search-input').val(pattern);
			$('#wudt-regex').prop('checked', true);
			updateOptions();
			WUDTUI.Toast.info('Pattern loaded: ' + $(this).text());
		});

		// Search target toggles
		$(document).on('change', '#wudt-search-files', function() {
			state.options.searchFiles = $(this).is(':checked');
			toggleFilters();
		});

		$(document).on('change', '#wudt-search-db', function() {
			state.options.searchDb = $(this).is(':checked');
			toggleFilters();
		});

		// Advanced options
		$(document).on('change', '#wudt-regex, #wudt-case-sensitive, #wudt-whole-word', function() {
			updateOptions();
		});

		// Directory select
		$(document).on('change', '#wudt-directory', function() {
			state.options.directory = $(this).val();
		});

		// Extension checkboxes
		$(document).on('change', '.wudt-checkbox-group input', function() {
			updateExtensions();
		});

		// Replace preview button
		$(document).on('click', '#wudt-preview-replace-btn', function() {
			showReplacePreview();
		});

		// Modal close buttons
		$(document).on('click', '#wudt-close-preview, #wudt-cancel-replace', function() {
			closeModal();
		});

		// Confirm replace
		$(document).on('click', '#wudt-confirm-replace', function() {
			executeReplace();
		});

		// Export results
		$(document).on('click', '#wudt-export-results', function() {
			exportResults();
		});

		// Show replace section when there are results
		$(document).on('input', '#wudt-search-input', function() {
			const hasValue = $(this).val().trim() !== '';
			const hasResults = state.fileResults.length > 0 || state.dbResults.length > 0;
			$('#wudt-replace-section').toggle(hasValue || hasResults);
		});

		// Backup checkbox
		$(document).on('change', '#wudt-create-backup', function() {
			state.options.createBackup = $(this).is(':checked');
		});
	}

	// ============================================
	// Search Functions
	// ============================================
	function performSearch() {
		const searchText = $('#wudt-search-input').val().trim();

		if (!searchText) {
			WUDTUI.Toast.warning('Please enter search text');
			return;
		}

		if (!state.options.searchFiles && !state.options.searchDb) {
			WUDTUI.Toast.warning('Please select at least one search target (Files or Database)');
			return;
		}

		state.searchText = searchText;
		state.isSearching = true;
		state.fileResults = [];
		state.dbResults = [];

		updateOptions();
		showProgress();

		const promises = [];

		// Search files
		if (state.options.searchFiles) {
			promises.push(searchFiles());
		}

		// Search database
		if (state.options.searchDb) {
			promises.push(searchDatabase());
		}

		Promise.all(promises).then(() => {
			state.isSearching = false;
			hideProgress();
			displayResults();
			saveSearchHistory(searchText);
		}).catch(error => {
			state.isSearching = false;
			hideProgress();
			WUDTUI.Toast.error('Search failed: ' + error.message);
		});
	}

	function searchFiles() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_search_files', {
				search: state.searchText,
				directory: state.options.directory,
				extensions: JSON.stringify(state.options.extensions),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					state.fileResults = response.data.results || [];
					resolve(response.data);
				} else {
					reject(new Error(response.data?.message || 'File search failed'));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	function searchDatabase() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_search_db', {
				search: state.searchText,
				tables: JSON.stringify(state.options.tables),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					state.dbResults = response.data.results || [];
					resolve(response.data);
				} else {
					reject(new Error(response.data?.message || 'Database search failed'));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	// ============================================
	// Results Display
	// ============================================
	function displayResults() {
		const totalMatches = state.fileResults.length + state.dbResults.length;
		
		if (totalMatches === 0) {
			$('#wudt-results-container').hide();
			$('#wudt-replace-section').hide();
			WUDTUI.Toast.info(window.wudtSearchTool.labels.noResults);
			return;
		}

		$('#wudt-results-container').show();
		$('#wudt-replace-section').show();

		// Update count badge
		$('#wudt-results-count').text(totalMatches + ' ' + window.wudtSearchTool.labels.matchesFound);

		// Display file results
		if (state.fileResults.length > 0) {
			$('#wudt-file-results').show();
			displayFileResults();
		} else {
			$('#wudt-file-results').hide();
		}

		// Display database results
		if (state.dbResults.length > 0) {
			$('#wudt-db-results').show();
			displayDbResults();
		} else {
			$('#wudt-db-results').hide();
		}

		WUDTUI.Toast.success(totalMatches + ' matches found');
	}

	function displayFileResults() {
		const $list = $('#wudt-file-results-list');
		$list.empty();

		// Group by file
		const files = {};
		state.fileResults.forEach(result => {
			if (!files[result.file]) {
				files[result.file] = [];
			}
			files[result.file].push(result);
		});

		Object.keys(files).forEach(file => {
			const matches = files[file];
			const firstMatch = matches[0];

			const $item = $('<div class="wudt-result-item">');
			$item.append(`<div class="wudt-result-file">${escapeHtml(file)}</div>`);
			$item.append(`<div class="wudt-result-line">${matches.length} match(es)</div>`);

			// Show first match with context
			if (firstMatch.context_lines && firstMatch.context_lines.length > 0) {
				const $code = $('<div class="wudt-result-code">');
				
				firstMatch.context_lines.forEach(line => {
					const isMatch = line.line === firstMatch.line;
					const lineNum = `<span class="wudt-line-num">${line.line}</span>`;
					const content = isMatch 
						? highlightMatch(line.content, state.searchText, state.options.regex)
						: escapeHtml(line.content);
					
					$code.append(`<div class="${isMatch ? 'wudt-line-match' : ''}">${lineNum} ${content}</div>`);
				});

				$item.append($code);
			}

			$list.append($item);
		});
	}

	function displayDbResults() {
		const $list = $('#wudt-db-results-list');
		$list.empty();

		state.dbResults.forEach(result => {
			const $item = $('<div class="wudt-result-item">');
			
			const badge = `<span class="wudt-badge wudt-badge--neutral">${escapeHtml(result.table)}</span>`;
			const colBadge = `<span class="wudt-badge wudt-badge--primary">${escapeHtml(result.column)}</span>`;
			
			$item.append(`<div class="wudt-result-file">${badge} ${colBadge} ${result.row_id ? 'Row #' + result.row_id : ''}</div>`);
			
			const $code = $('<div class="wudt-result-code">');
			$code.append(highlightMatch(escapeHtml(result.preview), state.searchText, state.options.regex));
			$item.append($code);

			$list.append($item);
		});
	}

	// ============================================
	// Replace Functions
	// ============================================
	function showReplacePreview() {
		state.replaceText = $('#wudt-replace-input').val();

		if (!state.replaceText) {
			WUDTUI.Toast.warning('Please enter replacement text');
			return;
		}

		WUDTUI.Loading.show($('body'), { type: 'spinner', text: 'Generating preview...' });

		const promises = [];

		if (state.options.searchFiles && state.fileResults.length > 0) {
			promises.push(previewFileReplace());
		}

		if (state.options.searchDb && state.dbResults.length > 0) {
			promises.push(previewDbReplace());
		}

		Promise.all(promises).then(results => {
			WUDTUI.Loading.hide($('body'));
			
			const filePreview = results.find(r => r.type === 'file');
			const dbPreview = results.find(r => r.type === 'db');
			
			state.currentPreview = {
				files: filePreview?.data || { total_changes: 0, preview: [] },
				db: dbPreview?.data || { total_changes: 0, preview: [] }
			};

			renderPreviewModal();
			$('#wudt-replace-preview-modal').show();
		}).catch(error => {
			WUDTUI.Loading.hide($('body'));
			WUDTUI.Toast.error('Preview failed: ' + error.message);
		});
	}

	function previewFileReplace() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_preview_replace_files', {
				search: state.searchText,
				replace: state.replaceText,
				directory: state.options.directory,
				extensions: JSON.stringify(state.options.extensions),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					resolve({ type: 'file', data: response.data });
				} else {
					reject(new Error(response.data?.message));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	function previewDbReplace() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_preview_replace_db', {
				search: state.searchText,
				replace: state.replaceText,
				tables: JSON.stringify(state.options.tables),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					resolve({ type: 'db', data: response.data });
				} else {
					reject(new Error(response.data?.message));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	function renderPreviewModal() {
		const totalChanges = state.currentPreview.files.total_changes + state.currentPreview.db.total_changes;
		
		$('#wudt-preview-summary').html(`
			<div class="wudt-preview-summary">
				<p><strong>${totalChanges}</strong> changes will be made:</p>
				<ul>
					<li>${state.currentPreview.files.total_changes} file modifications</li>
					<li>${state.currentPreview.db.total_changes} database row updates</li>
				</ul>
			</div>
		`);

		const $changes = $('#wudt-preview-changes');
		$changes.empty();

		// File changes preview
		state.currentPreview.files.preview.forEach(item => {
			const $item = $(`
				<div class="wudt-preview-item">
					<div class="wudt-preview-header">${escapeHtml(item.file)} (${item.replace_count} replacements)</div>
					<div class="wudt-preview-content">
						<!-- Preview diffs here -->
					</div>
				</div>
			`);
			$changes.append($item);
		});

		// DB changes preview
		state.currentPreview.db.preview.forEach(item => {
			const $item = $(`
				<div class="wudt-preview-item">
					<div class="wudt-preview-header">${escapeHtml(item.table)}.${escapeHtml(item.column)} (Row #${item.row_id})</div>
					<div class="wudt-preview-content">
						<div class="wudt-preview-before">
							<div class="wudt-preview-label">Before:</div>
							<div class="wudt-preview-code">${escapeHtml(item.before.substring(0, 200))}</div>
						</div>
						<div class="wudt-preview-after">
							<div class="wudt-preview-label">After:</div>
							<div class="wudt-preview-code">${escapeHtml(item.after.substring(0, 200))}</div>
						</div>
					</div>
				</div>
			`);
			$changes.append($item);
		});

		// Disable confirm if no changes
		$('#wudt-confirm-replace').prop('disabled', totalChanges === 0);
	}

	function executeReplace() {
		closeModal();

		WUDTUI.Loading.show($('body'), { type: 'spinner', text: 'Applying replacements...' });

		const promises = [];

		if (state.currentPreview.files.total_changes > 0) {
			promises.push(executeFileReplace());
		}

		if (state.currentPreview.db.total_changes > 0) {
			promises.push(executeDbReplace());
		}

		Promise.all(promises).then(results => {
			WUDTUI.Loading.hide($('body'));
			
			const fileResult = results.find(r => r.type === 'file');
			const dbResult = results.find(r => r.type === 'db');

			const message = [];
			if (fileResult) {
				message.push(`${fileResult.data.files_modified} files modified`);
			}
			if (dbResult) {
				message.push(`${dbResult.data.rows_modified} rows updated`);
			}

			WUDTUI.Toast.success('Replace completed: ' + message.join(', '));
			
			// Refresh search to show updated state
			performSearch();
		}).catch(error => {
			WUDTUI.Loading.hide($('body'));
			WUDTUI.Toast.error('Replace failed: ' + error.message);
		});
	}

	function executeFileReplace() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_execute_replace_files', {
				search: state.searchText,
				replace: state.replaceText,
				directory: state.options.directory,
				extensions: JSON.stringify(state.options.extensions),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0',
				confirmed: '1',
				backup: state.options.createBackup ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					resolve({ type: 'file', data: response.data });
				} else {
					reject(new Error(response.data?.message));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	function executeDbReplace() {
		return new Promise((resolve, reject) => {
			WUDTUI.Ajax.post('wudt_execute_replace_db', {
				search: state.searchText,
				replace: state.replaceText,
				tables: JSON.stringify(state.options.tables),
				regex: state.options.regex ? '1' : '0',
				case_sensitive: state.options.caseSensitive ? '1' : '0',
				whole_word: state.options.wholeWord ? '1' : '0',
				confirmed: '1',
				backup: state.options.createBackup ? '1' : '0'
			}, { showError: false }).done(response => {
				if (response.success) {
					resolve({ type: 'db', data: response.data });
				} else {
					reject(new Error(response.data?.message));
				}
			}).fail((xhr, status, error) => {
				reject(new Error(error));
			});
		});
	}

	// ============================================
	// Utility Functions
	// ============================================
	function updateOptions() {
		state.options.regex = $('#wudt-regex').is(':checked');
		state.options.caseSensitive = $('#wudt-case-sensitive').is(':checked');
		state.options.wholeWord = $('#wudt-whole-word').is(':checked');
		state.options.createBackup = $('#wudt-create-backup').is(':checked');
		updateExtensions();
	}

	function updateExtensions() {
		state.options.extensions = [];
		$('.wudt-checkbox-group input:checked').each(function() {
			state.options.extensions.push($(this).val());
		});
	}

	function toggleFilters() {
		$('#wudt-file-filters').toggle(state.options.searchFiles);
		$('#wudt-db-filters').toggle(state.options.searchDb);
	}

	function loadTables() {
		WUDTUI.Ajax.post('wudt_get_tables', {}, { showError: false }).done(response => {
			if (response.success && response.data.tables) {
				const $select = $('#wudt-tables');
				$select.empty();
				
				response.data.tables.forEach(table => {
					const rows = parseInt(table.rows_count, 10) || 0;
					const label = `${table.name} (${rows.toLocaleString()} rows)`;
					$select.append(`<option value="${table.name}">${label}</option>`);
				});
			}
		});
	}

	function showProgress() {
		$('#wudt-search-progress').show();
		$('#wudt-results-container').hide();
	}

	function hideProgress() {
		$('#wudt-search-progress').hide();
	}

	function closeModal() {
		$('#wudt-replace-preview-modal').hide();
	}

	function highlightMatch(text, search, isRegex) {
		if (!text || !search) return text;

		let pattern;
		if (isRegex) {
			try {
				pattern = new RegExp(`(${search})`, 'gi');
			} catch (e) {
				return text;
			}
		} else {
			pattern = new RegExp(`(${escapeRegex(search)})`, 'gi');
		}

		return text.replace(pattern, '<span class="wudt-result-match">$1</span>');
	}

	function escapeRegex(string) {
		return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function escapeHtml(text) {
		if (!text) return '';
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	function exportResults() {
		const results = {
			search: state.searchText,
			options: state.options,
			file_results: state.fileResults,
			db_results: state.dbResults,
			exported_at: new Date().toISOString()
		};

		const blob = new Blob([JSON.stringify(results, null, 2)], { type: 'application/json' });
		const url = URL.createObjectURL(blob);
		
		const a = document.createElement('a');
		a.href = url;
		a.download = `search-results-${new Date().toISOString().split('T')[0]}.json`;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);

		WUDTUI.Toast.success('Results exported');
	}

	function saveSearchHistory(searchText) {
		if (!searchText) return;

		WUDTUI.Ajax.post('wudt_save_search_history', {
			search: searchText,
			type: state.options.searchFiles ? 'file' : 'db'
		}, { showError: false });
	}

	function loadSearchHistory() {
		// Could be implemented to show recent searches in UI
	}

	// ============================================
	// Initialize on document ready
	// ============================================
	$(document).ready(init);

})(jQuery);
