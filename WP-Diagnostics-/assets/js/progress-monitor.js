/**
 * Progress Monitor JavaScript
 * Real-time updates for debugging tool progress
 */
(function($) {
	'use strict';

	const ProgressMonitor = {
		refreshInterval: null,
		autoRefresh: true,
		refreshDelay: 2000,

		init: function() {
			this.bindEvents();
			this.startAutoRefresh();
			this.refreshNow();
		},

		bindEvents: function() {
			$('#wudt-auto-refresh').on('change', this.toggleAutoRefresh.bind(this));
			$('#wudt-refresh-now').on('click', this.refreshNow.bind(this));
			$('#wudt-cleanup-old').on('click', this.cleanupOld.bind(this));
			$(document).on('click', '.wudt-cancel-btn', this.cancelOperation.bind(this));
		},

		toggleAutoRefresh: function() {
			this.autoRefresh = $('#wudt-auto-refresh').is(':checked');
			if (this.autoRefresh) {
				this.startAutoRefresh();
			} else {
				this.stopAutoRefresh();
			}
		},

		startAutoRefresh: function() {
			this.stopAutoRefresh();
			if (this.autoRefresh) {
				this.refreshInterval = setInterval(this.refreshNow.bind(this), this.refreshDelay);
			}
		},

		stopAutoRefresh: function() {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
				this.refreshInterval = null;
			}
		},

		refreshNow: function() {
			this.loadModuleStatus();
			this.loadRecentOperations();
		},

		loadModuleStatus: function() {
			$.ajax({
				url: wudtProgressMonitor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wudt_get_module_progress',
					nonce: wudtProgressMonitor.nonce
				},
				success: function(response) {
					if (response.success) {
						this.updateModuleGrid(response.data.modules);
						this.updateStatusSummary(response.data);
						this.updateActiveOperations(response.data.active_operations);
					}
				}.bind(this)
			});
		},

		loadRecentOperations: function() {
			$.ajax({
				url: wudtProgressMonitor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wudt_get_all_operations',
					limit: 20,
					nonce: wudtProgressMonitor.nonce
				},
				success: function(response) {
					if (response.success) {
						this.updateOperationsTable(response.data.operations);
					}
				}.bind(this)
			});
		},

		updateModuleGrid: function(modules) {
			const $grid = $('#wudt-modules-grid');
			
			Object.keys(modules).forEach(function(key) {
				const module = modules[key];
				let $card = $grid.find('[data-module-key="' + key + '"]');
				
				if ($card.length === 0) {
					// Create new card if doesn't exist
					return;
				}

				// Update status classes
				$card.removeClass('ready running completed failed cancelled');
				$card.addClass(module.status);
				
				// Update status badge
				const statusLabel = wudtProgressMonitor.labels[module.status] || module.status;
				$card.find('.wudt-module-status')
					.removeClass('ready running completed failed cancelled')
					.addClass(module.status)
					.text(statusLabel);
			});
		},

		updateStatusSummary: function(data) {
			$('#active-operations-count').text(data.active_operations ? data.active_operations.length : 0);
			
			// Count ready modules
			let readyCount = 0;
			let errorCount = 0;
			
			if (data.modules) {
				Object.values(data.modules).forEach(function(module) {
					if (module.status === 'ready') readyCount++;
					if (module.status === 'failed' || (module.errors && module.errors.length > 0)) {
						errorCount++;
					}
				});
			}
			
			$('#modules-ready-count').text(readyCount + '/' + Object.keys(data.modules || {}).length);
			$('#errors-count').text(errorCount);
			
			// Estimate completed today (would need proper tracking)
			$('#completed-today-count').text('-');
		},

		updateActiveOperations: function(operations) {
			const $container = $('#wudt-active-operations-list');
			
			if (!operations || operations.length === 0) {
				$container.html('<p class="wudt-no-operations">' + 
					wudtProgressMonitor.labels.ready + '</p>');
				return;
			}

			let html = '';
			operations.forEach(function(op) {
				const progress = op.progress || 0;
				const processed = op.processed || 0;
				const total = op.total_items || 0;
				const elapsed = this.formatDuration(op.elapsed || 0);
				const estimated = op.estimated ? this.formatDuration(op.estimated) : '-';
				
				html += '<div class="wudt-operation-progress" data-operation-id="' + op.operation_id + '">';
				html += '<div class="progress-header">';
				html += '<span class="progress-title">' + this.escapeHtml(op.title || op.operation_type) + '</span>';
				html += '<button class="wudt-cancel-btn" data-operation-id="' + op.operation_id + '">';
				html += wudtProgressMonitor.labels.cancel || 'Cancel';
				html += '</button>';
				html += '</div>';
				html += '<div class="progress-bar">';
				html += '<div class="progress-bar-fill" style="width: ' + progress + '%"></div>';
				html += '</div>';
				html += '<div class="progress-info">';
				html += '<span>' + this.escapeHtml(op.message || '') + '</span>';
				html += '<span>' + processed + '/' + total + ' (' + progress.toFixed(1) + '%) | ';
				html += 'Elapsed: ' + elapsed + ' | Est: ' + estimated + '</span>';
				html += '</div>';
				html += '</div>';
			}.bind(this));
			
			$container.html(html);
		},

		updateOperationsTable: function(operations) {
			const $tbody = $('#wudt-recent-operations tbody');
			
			if (!operations || operations.length === 0) {
				$tbody.html('<tr class="no-items"><td colspan="6">' + 
					'No operations yet.' + '</td></tr>');
				return;
			}

			let html = '';
			operations.slice(0, 10).forEach(function(op) {
				const statusClass = 'status-' + op.status;
				const statusLabel = wudtProgressMonitor.labels[op.status] || op.status;
				const startTime = op.start_time ? new Date(op.start_time * 1000).toLocaleString() : '-';
				const duration = op.elapsed ? this.formatDuration(op.elapsed) : '-';
				
				html += '<tr>';
				html += '<td><strong>' + this.escapeHtml(op.title || op.operation_type) + '</strong><br>';
				html += '<small>' + op.operation_type + '</small></td>';
				html += '<td><span class="wudt-module-status ' + statusClass + '">' + statusLabel + '</span></td>';
				html += '<td>' + (op.progress || 0).toFixed(1) + '%</td>';
				html += '<td>' + startTime + '</td>';
				html += '<td>' + duration + '</td>';
				html += '<td>-</td>';
				html += '</tr>';
			}.bind(this));
			
			$tbody.html(html);
		},

		cancelOperation: function(e) {
			e.preventDefault();
			const operationId = $(e.target).data('operation-id');
			
			if (!confirm('Are you sure you want to cancel this operation?')) {
				return;
			}
			
			$.ajax({
				url: wudtProgressMonitor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wudt_cancel_operation',
					operation_id: operationId,
					nonce: wudtProgressMonitor.nonce
				},
				success: function(response) {
					if (response.success) {
						this.refreshNow();
					}
				}.bind(this)
			});
		},

		cleanupOld: function(e) {
			e.preventDefault();
			
			if (!confirm('Clean up old completed operations?')) {
				return;
			}
			
			$.ajax({
				url: wudtProgressMonitor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wudt_cleanup_operations',
					older_than_hours: 24,
					nonce: wudtProgressMonitor.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						this.refreshNow();
					}
				}.bind(this)
			});
		},

		formatDuration: function(seconds) {
			if (seconds < 60) {
				return seconds + 's';
			}
			const mins = Math.floor(seconds / 60);
			const secs = seconds % 60;
			if (mins < 60) {
				return mins + 'm ' + secs + 's';
			}
			const hours = Math.floor(mins / 60);
			const remainingMins = mins % 60;
			return hours + 'h ' + remainingMins + 'm';
		},

		escapeHtml: function(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	$(document).ready(function() {
		ProgressMonitor.init();
	});

})(jQuery);
