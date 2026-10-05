/**
 * Settings page async form handling
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		var $form = $('#wudt-settings-form');
		var $submitBtn = $form.find('button[type="submit"], input[type="submit"]');
		var $messageContainer = $('<div class="wudt-settings-message"></div>');
		
		// Insert message container after the form title
		$('.wudt-wrap h1').after($messageContainer);

		// Check for URL params on page load
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.get('saved') === '1') {
			showMessage('Settings saved successfully!', 'success');
			// Clean URL
			window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[?&]saved=1/, ''));
		}
		if (urlParams.get('wp_config_error')) {
			showMessage('Settings saved but wp-config.php error: ' + decodeURIComponent(urlParams.get('wp_config_error')), 'warning');
		}

		$form.on('submit', function(e) {
			e.preventDefault();
			
			// Show loading state
			$submitBtn.prop('disabled', true).addClass('is-loading');
			$submitBtn.data('original-text', $submitBtn.text());
			$submitBtn.text('Saving...');
			
			// Serialize form data
			var formData = $form.serialize();
			formData += '&action=wudt_save_settings_async';
			formData += '&nonce=' + encodeURIComponent(wudtSettings.nonce);
			
			// Send AJAX request
			$.ajax({
				url: wudtSettings.ajaxUrl,
				type: 'POST',
				data: formData,
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						var message = response.data.message || 'Settings saved successfully!';
						if (response.data.wp_config_error) {
							message += '<br><small>Warning: ' + response.data.wp_config_error + '</small>';
							showMessage(message, 'warning');
						} else {
							showMessage(message, 'success');
						}
					} else {
						var errorMsg = response.data && response.data.message 
							? response.data.message 
							: 'Failed to save settings.';
						showMessage(errorMsg, 'error');
					}
				},
				error: function(xhr, status, error) {
					showMessage('Network error: ' + error, 'error');
				},
				complete: function() {
					// Restore button state
					$submitBtn.prop('disabled', false).removeClass('is-loading');
					$submitBtn.text($submitBtn.data('original-text'));
				}
			});
		});

		function showMessage(message, type) {
			var cssClass = 'wudt-notice-' + type;
			var icon = type === 'success' ? '✓' : type === 'warning' ? '⚠' : '✗';
			var title = type === 'success' ? 'Success' : type === 'warning' ? 'Warning' : 'Error';
			
			// Clear any existing messages
			$messageContainer.empty();
			
			var $notice = $([
				'<div class="wudt-notice ' + cssClass + ' is-dismissible">',
				'<p><strong>' + icon + '</strong> <b>' + title + '</b><br>' + message + '</p>',
				'<button type="button" class="wudt-notice-dismiss">&times;</button>',
				'</div>'
			].join(''));
			
			$messageContainer.append($notice);

			// Auto-dismiss after 4 seconds for success
			if (type === 'success') {
				setTimeout(function() {
					dismissNotice($notice);
				}, 4000);
			}

			// Manual dismiss
			$notice.find('.wudt-notice-dismiss').on('click', function() {
				dismissNotice($notice);
			});
		}
		
		function dismissNotice($notice) {
			$notice.addClass('hiding');
			setTimeout(function() {
				$notice.remove();
			}, 300);
		}
	});
})(jQuery);
