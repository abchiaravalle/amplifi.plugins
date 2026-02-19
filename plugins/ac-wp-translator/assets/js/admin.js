(function($) {
	'use strict';

	// Fetch available models from OpenAI and populate the select.
	function fetchModels() {
		var select  = $('#acwpt_model');
		var status  = $('#acwpt-model-status');
		var current = select.val();
		var apiKey  = $('#acwpt_api_key').val();

		status.text('Loading models...').removeClass('success error');

		$.post(acwptAdmin.ajaxurl, {
			action:  'acwpt_fetch_models',
			nonce:   acwptAdmin.nonce,
			api_key: apiKey
		}, function(response) {
			if (response.success && Array.isArray(response.data)) {
				var models = response.data;
				select.empty();

				// Ensure current saved model is in the list.
				if (current && models.indexOf(current) === -1) {
					models.push(current);
					models.sort();
				}

				for (var i = 0; i < models.length; i++) {
					var label = models[i];
					if (models[i] === 'gpt-4o-mini') {
						label = 'gpt-4o-mini (recommended)';
					}
					var opt = $('<option>').val(models[i]).text(label);
					if (models[i] === current) {
						opt.prop('selected', true);
					}
					select.append(opt);
				}

				status.text(models.length + ' models loaded').addClass('success').removeClass('error');
				setTimeout(function() { status.text(''); }, 3000);
			} else {
				status.text(response.data || 'Could not load models.').addClass('error').removeClass('success');
			}
		}).fail(function() {
			status.text('Failed to fetch models.').addClass('error').removeClass('success');
		});
	}

	// Load models on page load.
	fetchModels();

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
				// Refresh models list with the (possibly new) key.
				fetchModels();
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
