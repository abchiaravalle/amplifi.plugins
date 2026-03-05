<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACSYNC_Admin {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_acsync_regenerate_key', array( $this, 'ajax_regenerate_key' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'amplifi-studio_page_amplifi-ac-sync' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'acsync-admin', false );
		wp_add_inline_style( 'acsync-admin', $this->get_css() );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( 'acsync_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$log      = get_option( 'acsync_connection_log', array() );
		$log      = array_slice( array_reverse( $log ), 0, 10 );

		echo '<div class="wrap acsync-wrap">';
		echo '<h1>amplifi.sync</h1>';
		echo '<p>REST API endpoints for syncing files, database, and media between environments.</p>';

		// API Key section.
		echo '<div class="acsync-card">';
		echo '<h2>API Key</h2>';
		echo '<p>Use this key in the <code>X-AmpliSync-Key</code> header when connecting the sync TUI.</p>';
		echo '<div class="acsync-key-row">';
		echo '<input type="text" id="acsync-api-key" value="' . esc_attr( $api_key ) . '" readonly class="regular-text acsync-key-input" data-masked="true" />';
		echo '<button type="button" class="button" onclick="acsyncToggleKey()">Show</button>';
		echo '<button type="button" class="button" onclick="acsyncCopyKey()">Copy</button>';
		echo '<button type="button" class="button button-secondary" onclick="acsyncRegenerateKey()">Regenerate</button>';
		echo '</div>';
		echo '</div>';

		// API Info section.
		echo '<div class="acsync-card">';
		echo '<h2>API Endpoints</h2>';
		echo '<p>Base URL: <code>' . esc_html( rest_url( 'amplifi-sync/v1/' ) ) . '</code></p>';
		echo '<table class="widefat fixed striped">';
		echo '<thead><tr><th>Method</th><th>Endpoint</th><th>Purpose</th></tr></thead>';
		echo '<tbody>';
		$endpoints = array(
			array( 'GET',    '/status',              'Site info, versions, active plugins/themes' ),
			array( 'GET',    '/files/manifest',      'File tree with MD5 hashes' ),
			array( 'GET',    '/files/read',          'Download file content' ),
			array( 'POST',   '/files/write',         'Upload file to path' ),
			array( 'DELETE', '/files/delete',         'Delete a file' ),
			array( 'GET',    '/db/tables',           'List tables with row counts' ),
			array( 'GET',    '/db/export',           'Export table as JSON' ),
			array( 'POST',   '/db/import',           'Import rows into table' ),
			array( 'POST',   '/db/query',            'Execute read-only SQL' ),
			array( 'POST',   '/db/execute',          'Execute write SQL' ),
			array( 'POST',   '/db/backup',           'Full database dump' ),
			array( 'POST',   '/db/restore',          'Restore database from dump' ),
			array( 'GET',    '/media/list',          'Media library items' ),
			array( 'POST',   '/media/import',        'Import media from URL' ),
			array( 'POST',   '/elementor/regenerate', 'Regenerate Elementor CSS' ),
		);
		foreach ( $endpoints as $ep ) {
			printf(
				'<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( $ep[0] ),
				esc_html( $ep[1] ),
				esc_html( $ep[2] )
			);
		}
		echo '</tbody></table>';
		echo '</div>';

		// Connection Log section.
		echo '<div class="acsync-card">';
		echo '<h2>Connection Log</h2>';
		if ( empty( $log ) ) {
			echo '<p>No API requests recorded yet.</p>';
		} else {
			echo '<table class="widefat fixed striped">';
			echo '<thead><tr><th>Time</th><th>IP</th><th>Endpoint</th><th>Status</th></tr></thead>';
			echo '<tbody>';
			foreach ( $log as $entry ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
					esc_html( $entry['time'] ?? '' ),
					esc_html( $entry['ip'] ?? '' ),
					esc_html( $entry['endpoint'] ?? '' ),
					esc_html( $entry['status'] ?? '' )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '</div>';

		// Inline JS.
		echo '<script>' . $this->get_js() . '</script>';
	}

	public function ajax_regenerate_key() {
		check_ajax_referer( 'acsync_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$settings            = get_option( 'acsync_settings', array() );
		$settings['api_key'] = wp_generate_password( 48, false );
		update_option( 'acsync_settings', $settings );
		wp_send_json_success( array( 'key' => $settings['api_key'] ) );
	}

	private function get_css() {
		return '
			.acsync-wrap { max-width: 900px; }
			.acsync-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 16px 0; }
			.acsync-key-row { display: flex; gap: 8px; align-items: center; margin-top: 10px; }
			.acsync-key-input { font-family: monospace; }
			.acsync-key-input[data-masked="true"] { -webkit-text-security: disc; }
		';
	}

	private function get_js() {
		$nonce = wp_create_nonce( 'acsync_admin' );
		return "
			function acsyncToggleKey() {
				var el = document.getElementById('acsync-api-key');
				var masked = el.getAttribute('data-masked') === 'true';
				el.setAttribute('data-masked', masked ? 'false' : 'true');
				el.previousElementSibling || el.nextElementSibling;
				event.target.textContent = masked ? 'Hide' : 'Show';
			}
			function acsyncCopyKey() {
				var el = document.getElementById('acsync-api-key');
				navigator.clipboard.writeText(el.value);
				event.target.textContent = 'Copied!';
				setTimeout(function(){ event.target.textContent = 'Copy'; }, 2000);
			}
			function acsyncRegenerateKey() {
				if (!confirm('Regenerate API key? The TUI will need the new key to connect.')) return;
				jQuery.post(ajaxurl, { action: 'acsync_regenerate_key', nonce: '{$nonce}' }, function(r) {
					if (r.success) {
						document.getElementById('acsync-api-key').value = r.data.key;
						alert('API key regenerated.');
					}
				});
			}
		";
	}
}
