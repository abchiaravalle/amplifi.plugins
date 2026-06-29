<?php
/*
Plugin Name: amplifi.consent
Plugin URI: https://github.com/abchiaravalle/amplifi.plugins
Description: First-party cookie consent manager that HARD-WITHHOLDS tracking scripts until the visitor accepts. Scripts you add are rendered as type="text/plain" and only released after consent — nothing fires on reject. Per-category toggles, accept/reject toast, 180-day localStorage consent, a [amplifi-consent-manager] shortcode, and an admin cookie scanner that loads each script in a sandboxed first-party iframe to detect the cookies it sets so you can categorize them.
Version: 1.0.0
Author: amplifi.studio
Author URI: https://amplifi.studio
License: MIT
Text Domain: amplifi-consent
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACCONSENT_VERSION' ) ) {
	return;
}
define( 'ACCONSENT_VERSION', '1.0.0' );
define( 'ACCONSENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACCONSENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACCONSENT_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACCONSENT_PLUGIN_DIR . 'includes/amplifi-framework.php';

// Core classes.
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-store.php';
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-frontend.php';
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-admin.php';
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-rest.php';

class Amplifi_Consent {

	/** @var Amplifi_Consent|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register with the amplifi.studio framework (adds submenu under amplifi.studio).
		amplifi_register_plugin(
			'ac-consent',
			'Consent',
			'First-party cookie consent that hard-withholds tracking scripts until the visitor accepts.',
			ACCONSENT_VERSION,
			ACCONSENT_PLUGIN_FILE,
			array( 'Amplifi_Consent_Admin', 'render_main_page' )
		);

		// Subsystems.
		Amplifi_Consent_Frontend::init();
		Amplifi_Consent_Admin::init();
		Amplifi_Consent_Rest::init();
	}
}

register_activation_hook( ACCONSENT_PLUGIN_FILE, array( 'Amplifi_Consent_Store', 'activate' ) );

add_action( 'plugins_loaded', array( 'Amplifi_Consent', 'instance' ) );
