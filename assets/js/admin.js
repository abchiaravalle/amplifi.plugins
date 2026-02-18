(function($) {
	'use strict';

	// Test API key.
	$('#acwpt-test-key').on('click', function() {
		var btn    = $(this);
		var status = $('#acwpt-key-status');
		var apiKey = $('#acwpt_api_key').val();

		if (!apiKey) {
			status.text('Enter an API key first.').removeClass('success').addClass('error');
			return;
		}

		btn.prop('disabled', true);
		status.text('Testing...').removeClass('success error');

		$.post(acwptAdmin.ajaxurl, {
			action: 'acwpt_test_api_key',
			nonce:  acwptAdmin.nonce,
			api_key: apiKey
		}, function(response) {
			btn.prop('disabled', false);
			if (response.success) {
				status.text(response.data).removeClass('error').addClass('success');
			} else {
				status.text(response.data).removeClass('success').addClass('error');
			}
		}).fail(function() {
			btn.prop('disabled', false);
			status.text('Request failed.').removeClass('success').addClass('error');
		});
	});

	// Flush cache.
	$('#acwpt-flush-cache').on('click', function() {
		if (!confirm('Clear all cached translations? They will be regenerated on next visit.')) {
			return;
		}

		var btn    = $(this);
		var status = $('#acwpt-flush-status');

		btn.prop('disabled', true);
		status.text('Clearing...');

		$.post(acwptAdmin.ajaxurl, {
			action: 'acwpt_flush_cache',
			nonce:  acwptAdmin.nonce
		}, function(response) {
			btn.prop('disabled', false);
			if (response.success) {
				status.text('Cache cleared!');
				setTimeout(function() { location.reload(); }, 1000);
			} else {
				status.text('Error: ' + response.data);
			}
		}).fail(function() {
			btn.prop('disabled', false);
			status.text('Request failed.');
		});
	});
})(jQuery);
