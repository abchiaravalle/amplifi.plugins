<?php
// Feature module of amplifi-plugins (amplifi.consent, v1.9.1); bundled, not a standalone plugin.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACCONSENT_VERSION' ) ) {
	return;
}
define( 'ACCONSENT_VERSION', '3.1.7' );
define( 'ACCONSENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACCONSENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACCONSENT_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACCONSENT_PLUGIN_DIR . 'includes/amplifi-framework.php';

// Core classes.
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-store.php';
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-consent-log.php';
require_once ACCONSENT_PLUGIN_DIR . 'includes/class-acconsent-webhook.php';
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

		// Load translations. Required because this plugin ships via GitHub /
		// the amplifi.studio updater, NOT wp.org, so the .org language-pack
		// auto-loader doesn't apply; without this an operator's .mo in
		// wp-content/languages/plugins/ won't load on the 6.0 minimum target.
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		// Create/upgrade the consent-log table on a version bump (covers sites
		// that updated the plugin without re-running the activation hook). Runs on
		// `init` (not just admin_init) so front-end-only / headless sites that
		// rarely load wp-admin still get the schema migration — otherwise a
		// post-update /consent write could hit a missing column and silently 404.
		// Cheap: maybe_upgrade short-circuits on a single option compare.
		add_action( 'init', array( 'Amplifi_Consent_Log', 'maybe_upgrade' ) );

		// Daily retention purge (no-op unless retention_days > 0).
		add_action( 'acconsent_daily_purge', array( 'Amplifi_Consent_Log', 'purge_expired' ) );
		if ( ! wp_next_scheduled( 'acconsent_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'acconsent_daily_purge' );
		}
	}

	/** Load the plugin's translations from /languages. */
	public static function load_textdomain() {
		load_plugin_textdomain( 'amplifi-consent', false, dirname( plugin_basename( ACCONSENT_PLUGIN_FILE ) ) . '/languages' );
	}
}

register_activation_hook( ACCONSENT_PLUGIN_FILE, array( 'Amplifi_Consent_Store', 'activate' ) );

// On deactivation, unschedule the daily purge so no orphan cron event lingers
// for a deactivated-but-not-deleted plugin. (Uninstall also clears it.)
register_deactivation_hook( ACCONSENT_PLUGIN_FILE, function () {
	wp_clear_scheduled_hook( 'acconsent_daily_purge' );
} );

add_action( 'plugins_loaded', array( 'Amplifi_Consent', 'instance' ) );
