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
	// -------------------------------------------------------------------------
	// Preload Cache
	// -------------------------------------------------------------------------

	var preloadPollTimer = null;

	function preloadUpdateUI( data ) {
		var $start  = $('#acwpt-preload-start');
		var $stop   = $('#acwpt-preload-stop');
		var $bar    = $('#acwpt-preload-bar');
		var $wrap   = $('#acwpt-preload-bar-wrap');
		var $label  = $('#acwpt-preload-label');
		var $status = $('#acwpt-preload-status');

		if ( ! data || ! data.running && ! data.total ) {
			$wrap.hide();
			$start.prop('disabled', false).show();
			$stop.hide();
			$status.text('');
			return;
		}

		var total     = data.total || 0;
		var completed = data.completed || 0;
		var failed    = data.failed || 0;
		var pct       = total > 0 ? Math.round( ( completed / total ) * 100 ) : 0;

		$wrap.show();
		$bar.css('width', pct + '%');

		var labelParts = [ completed + ' / ' + total + ' translated' ];
		if ( failed > 0 ) { labelParts.push( failed + ' failed' ); }
		$label.text( labelParts.join( ', ' ) );

		if ( data.running ) {
			$start.prop('disabled', true).hide();
			$stop.show();
			$status.text('Running\u2026');
		} else {
			$start.prop('disabled', false).show();
			$stop.hide();
			$status.text( 'Complete! ' + completed + ' translations cached.' + ( failed > 0 ? ' (' + failed + ' failed)' : '' ) );
			stopPreloadPoll();
		}
	}

	function startPreloadPoll() {
		if ( preloadPollTimer ) { return; }
		preloadPollTimer = setInterval( function() {
			$.post( acwptAdmin.ajaxurl, { action: 'acwpt_preload_status', nonce: acwptAdmin.nonce }, function( r ) {
				if ( r.success ) { preloadUpdateUI( r.data ); }
			});
		}, 2500 );
	}

	function stopPreloadPoll() {
		if ( preloadPollTimer ) {
			clearInterval( preloadPollTimer );
			preloadPollTimer = null;
		}
	}

	// Restore state on page load if a run is already in progress.
	$.post( acwptAdmin.ajaxurl, { action: 'acwpt_preload_status', nonce: acwptAdmin.nonce }, function( r ) {
		if ( r.success && r.data && r.data.running ) {
			preloadUpdateUI( r.data );
			startPreloadPoll();
		}
	});

	$('#acwpt-preload-start').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#acwpt-preload-status').text('Starting\u2026');

		$.post( acwptAdmin.ajaxurl, { action: 'acwpt_preload_start', nonce: acwptAdmin.nonce }, function( r ) {
			if ( r.success ) {
				if ( r.data.done ) {
					$btn.prop('disabled', false);
					$('#acwpt-preload-status').text( r.data.message );
				} else {
					preloadUpdateUI({ running: true, total: r.data.total, completed: 0, failed: 0 });
					startPreloadPoll();
				}
			} else {
				$btn.prop('disabled', false);
				$('#acwpt-preload-status').text('Error: ' + ( r.data || 'Unknown error' ) );
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			$('#acwpt-preload-status').text('Request failed.');
		});
	});

	$('#acwpt-preload-stop').on('click', function() {
		stopPreloadPoll();
		$.post( acwptAdmin.ajaxurl, { action: 'acwpt_preload_stop', nonce: acwptAdmin.nonce }, function() {
			preloadUpdateUI( null );
			$('#acwpt-preload-status').text('Stopped.');
		});
	});

})(jQuery);
