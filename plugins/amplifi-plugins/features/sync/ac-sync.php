<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACSYNC_VERSION' ) ) {
	return;
}
define( 'ACSYNC_VERSION', '3.1.7' );
define( 'ACSYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACSYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACSYNC_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACSYNC_PLUGIN_DIR . 'includes/amplifi-framework.php';

// Load plugin classes.
require_once ACSYNC_PLUGIN_DIR . 'includes/class-acsync-admin.php';
require_once ACSYNC_PLUGIN_DIR . 'includes/class-acsync-api.php';

class Amplifi_Sync {

	private $admin;
	private $api;

	public function __construct() {
		$this->admin = new ACSYNC_Admin();
		$this->api   = new ACSYNC_API();

		amplifi_register_plugin(
			'ac-sync',
			'Sync',
			'WordPress environment sync with REST API for file, database, and media operations.',
			ACSYNC_VERSION,
			ACSYNC_PLUGIN_FILE,
			array( $this->admin, 'render_page' )
		);
	}
}

// Activation: generate API key if not set.
register_activation_hook( __FILE__, function () {
	$settings = get_option( 'acsync_settings', array() );
	if ( empty( $settings['api_key'] ) ) {
		$settings['api_key'] = wp_generate_password( 48, false );
		update_option( 'acsync_settings', $settings );
	}
	if ( ! get_option( 'acsync_connection_log' ) ) {
		update_option( 'acsync_connection_log', array() );
	}
} );

new Amplifi_Sync();
