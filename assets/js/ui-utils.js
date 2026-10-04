/**
 * Diagnostics Toolkit - UI Utilities
 * Modern UI components and utilities for improved UX
 */

(function($) {
	'use strict';

	// ============================================
	// Toast Notification System
	// ============================================
	const Toast = {
		container: null,
		defaultDuration: 5000,

		init: function() {
			if (!this.container) {
				this.container = $('<div class="wudt-toast-container"></div>').appendTo('body');
			}
		},

		show: function(message, options) {
			this.init();
			options = options || {};
			
			const type = options.type || 'info';
			const title = options.title || this.getDefaultTitle(type);
			const duration = options.duration || this.defaultDuration;
			const icon = options.icon || this.getIcon(type);

			const $toast = $(`
				<div class="wudt-toast wudt-toast--${type}">
					<span class="wudt-toast__icon">${icon}</span>
					<div class="wudt-toast__content">
						<div class="wudt-toast__title">${title}</div>
						<div class="wudt-toast__message">${message}</div>
					</div>
					<button class="wudt-toast__close">&times;</button>
				</div>
			`);

			this.container.append($toast);

			// Auto dismiss
			const dismissTimer = setTimeout(() => {
				this.dismiss($toast);
			}, duration);

			// Manual close
			$toast.find('.wudt-toast__close').on('click', () => {
				clearTimeout(dismissTimer);
				this.dismiss($toast);
			});

			return $toast;
		},

		success: function(message, options) {
			return this.show(message, $.extend({ type: 'success' }, options));
		},

		error: function(message, options) {
			return this.show(message, $.extend({ type: 'error' }, options));
		},

		warning: function(message, options) {
			return this.show(message, $.extend({ type: 'warning' }, options));
		},

		info: function(message, options) {
			return this.show(message, $.extend({ type: 'info' }, options));
		},

		dismiss: function($toast) {
			$toast.css({
				transform: 'translateX(100%)',
				opacity: '0'
			});
			setTimeout(() => $toast.remove(), 300);
		},

		getDefaultTitle: function(type) {
			const titles = {
				success: 'Success',
				error: 'Error',
				warning: 'Warning',
				info: 'Info'
			};
			return titles[type] || 'Notification';
		},

		getIcon: function(type) {
			const icons = {
				success: '✓',
				error: '✕',
				warning: '⚠',
				info: 'ℹ'
			};
			return icons[type] || 'ℹ';
		}
	};

	// ============================================
	// Loading States & Skeletons
	// ============================================
	const Loading = {
		show: function($element, options) {
			options = options || {};
			const type = options.type || 'spinner';
			const text = options.text || 'Loading...';

			$element.addClass('wudt-loading');

			if (type === 'skeleton') {
				const skeletons = options.count || 3;
				let html = '';
				for (let i = 0; i < skeletons; i++) {
					html += '<div class="wudt-skeleton wudt-skeleton--text"></div>';
				}
				$element.data('original-content', $element.html());
				$element.html(html);
			} else if (type === 'spinner') {
				$element.data('original-content', $element.html());
				$element.html(`
					<div class="wudt-flex wudt-items-center wudt-gap-2">
						<span class="wudt-animate-spin">⏳</span>
						<span>${text}</span>
					</div>
				`);
			}

			return $element;
		},

		hide: function($element) {
			$element.removeClass('wudt-loading');
			const original = $element.data('original-content');
			if (original) {
				$element.html(original);
			}
			return $element;
		},

		button: function($button, loading) {
			if (loading) {
				$button.data('original-text', $button.html());
				$button.prop('disabled', true).html(`
					<span class="wudt-animate-spin">⏳</span>
					<span>${$button.data('loading-text') || 'Loading...'}</span>
				`);
			} else {
				$button.prop('disabled', false).html($button.data('original-text'));
			}
			return $button;
		}
	};

	// ============================================
	// Modal System
	// ============================================
	const Modal = {
		open: function(options) {
			options = options || {};
			
			const title = options.title || 'Modal';
			const content = options.content || '';
			const showFooter = options.showFooter !== false;
			const confirmText = options.confirmText || 'Confirm';
			const cancelText = options.cancelText || 'Cancel';
			const onConfirm = options.onConfirm || $.noop;
			const onCancel = options.onCancel || $.noop;

			const $modal = $(`
				<div class="wudt-modal-overlay">
					<div class="wudt-modal">
						<div class="wudt-modal__header">
							<h3 class="wudt-modal__title">${title}</h3>
							<button class="wudt-modal__close">&times;</button>
						</div>
						<div class="wudt-modal__body">${content}</div>
						${showFooter ? `
						<div class="wudt-modal__footer">
							<button class="wudt-btn wudt-btn--secondary wudt-modal__cancel">${cancelText}</button>
							<button class="wudt-btn wudt-btn--primary wudt-modal__confirm">${confirmText}</button>
						</div>
						` : ''}
					</div>
				</div>
			`).appendTo('body');

			// Close handlers
			const close = () => {
				$modal.fadeOut(200, () => $modal.remove());
			};

			$modal.find('.wudt-modal__close, .wudt-modal__cancel').on('click', () => {
				onCancel();
				close();
			});

			$modal.find('.wudt-modal__confirm').on('click', () => {
				onConfirm();
				close();
			});

			// Click outside to close
			$modal.on('click', (e) => {
				if (e.target === $modal[0]) {
					onCancel();
					close();
				}
			});

			// Escape key to close
			$(document).on('keydown.wudt-modal', (e) => {
				if (e.key === 'Escape') {
					onCancel();
					close();
					$(document).off('keydown.wudt-modal');
				}
			});

			return $modal;
		},

		confirm: function(message, options) {
			options = options || {};
			return this.open({
				title: options.title || 'Confirm',
				content: `<p>${message}</p>`,
				confirmText: options.confirmText || 'Yes, proceed',
				cancelText: options.cancelText || 'Cancel',
				onConfirm: options.onConfirm || $.noop,
				onCancel: options.onCancel || $.noop
			});
		},

		alert: function(message, options) {
			options = options || {};
			return this.open({
				title: options.title || 'Alert',
				content: `<p>${message}</p>`,
				confirmText: 'OK',
				showFooter: true,
				onConfirm: options.onConfirm || $.noop
			});
		}
	};

	// ============================================
	// Accordion
	// ============================================
	const Accordion = {
		init: function($container) {
			$container.find('.wudt-accordion__header').on('click', function() {
				const $item = $(this).closest('.wudt-accordion__item');
				const $content = $item.find('.wudt-accordion__content');
				const isOpen = $item.hasClass('is-open');

				// Close all others if accordion is exclusive
				if ($container.hasClass('wudt-accordion--exclusive')) {
					$container.find('.wudt-accordion__item.is-open').each(function() {
						$(this).removeClass('is-open');
					});
				}

				if (isOpen) {
					$item.removeClass('is-open');
				} else {
					$item.addClass('is-open');
				}
			});
		}
	};

	// ============================================
	// Tooltip Enhancement
	// ============================================
	const Tooltip = {
		init: function($container) {
			$container.find('[data-tooltip]').addClass('wudt-tooltip');
		}
	};

	// ============================================
	// Dark Mode Toggle
	// ============================================
	const DarkMode = {
		key: 'wudt-dark-mode',

		init: function() {
			const saved = localStorage.getItem(this.key);
			if (saved === 'true') {
				this.enable();
			}
		},

		toggle: function() {
			if ($('body').hasClass('wudt-dark')) {
				this.disable();
			} else {
				this.enable();
			}
		},

		enable: function() {
			$('body').addClass('wudt-dark');
			localStorage.setItem(this.key, 'true');
			$(document).trigger('wudt:darkmode:change', [true]);
		},

		disable: function() {
			$('body').removeClass('wudt-dark');
			localStorage.setItem(this.key, 'false');
			$(document).trigger('wudt:darkmode:change', [false]);
		},

		isEnabled: function() {
			return $('body').hasClass('wudt-dark');
		}
	};

	// ============================================
	// Keyboard Shortcuts
	// ============================================
	const Keyboard = {
		shortcuts: {},

		init: function() {
			$(document).on('keydown', (e) => {
				const key = this.getKeyCombo(e);
				if (this.shortcuts[key]) {
					this.shortcuts[key](e);
				}
			});
		},

		register: function(combo, callback) {
			this.shortcuts[combo] = callback;
		},

		getKeyCombo: function(e) {
			const parts = [];
			if (e.ctrlKey) parts.push('ctrl');
			if (e.altKey) parts.push('alt');
			if (e.shiftKey) parts.push('shift');
			parts.push(e.key.toLowerCase());
			return parts.join('+');
		}
	};

	// ============================================
	// AJAX Utilities with Loading States
	// ============================================
	const Ajax = {
		post: function(action, data, options) {
			options = options || {};
			data = data || {};
			
			// Get nonce from various sources (wudtProAdmin, wudtSearchTool, etc.)
			if (window.wudtProAdmin && window.wudtProAdmin.nonce) {
				data.nonce = window.wudtProAdmin.nonce;
			} else if (window.wudtSearchTool && window.wudtSearchTool.nonce) {
				data.nonce = window.wudtSearchTool.nonce;
			}

			const $loadingEl = options.loadingElement;
			if ($loadingEl) {
				Loading.show($loadingEl, { type: options.loadingType || 'spinner' });
			}

			// Get ajax URL from various sources
			var ajaxUrl = ajaxurl;
			if (window.wudtProAdmin && window.wudtProAdmin.ajaxUrl) {
				ajaxUrl = window.wudtProAdmin.ajaxUrl;
			} else if (window.wudtSearchTool && window.wudtSearchTool.ajaxUrl) {
				ajaxUrl = window.wudtSearchTool.ajaxUrl;
			}

			return $.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: $.extend({ action: action }, data),
				complete: () => {
					if ($loadingEl) {
						Loading.hide($loadingEl);
					}
				}
			}).fail((xhr, status, error) => {
				if (options.showError !== false) {
					Toast.error('Request failed: ' + error);
				}
			});
		}
	};

	// ============================================
	// Export to global namespace
	// ============================================
	window.WUDTUI = {
		Toast: Toast,
		Loading: Loading,
		Modal: Modal,
		Accordion: Accordion,
		Tooltip: Tooltip,
		DarkMode: DarkMode,
		Keyboard: Keyboard,
		Ajax: Ajax
	};

	// Initialize on document ready
	$(document).ready(function() {
		DarkMode.init();
		Keyboard.init();
		
		// Initialize tooltips and accordions
		Tooltip.init($('body'));
		Accordion.init($('.wudt-accordion'));

		// Global keyboard shortcuts
		Keyboard.register('ctrl+shift+d', () => {
			DarkMode.toggle();
			Toast.info('Dark mode ' + (DarkMode.isEnabled() ? 'enabled' : 'disabled'));
		});
	});

})(jQuery);
