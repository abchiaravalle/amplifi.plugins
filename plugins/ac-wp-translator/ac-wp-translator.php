<?php
/**
 * Plugin Name: amplifi.translate
 * Plugin URI: https://github.com/abchiaravalle/amplifi.plugins
 * Description: AI-powered real-time translation using OpenAI. Translates pages and posts with URL-based language prefixes (/es/, /fr/, etc.) and smart caching. By amplifi.studio.
 * Version: 1.2.2
 * Author: amplifi.studio
 * Author URI: https://amplifi.studio
 * License: MIT
 * Text Domain: ac-wp-translator
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACWPT_VERSION', '1.2.2' );
define( 'ACWPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACWPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACWPT_PLUGIN_FILE', __FILE__ );

/**
 * Cache-busting version string for an asset (uses file mtime when available).
 *
 * @param string $relative_path Path relative to plugin dir, e.g. 'assets/css/frontend.css'.
 * @return string Version query arg for enqueue.
 */
function acwpt_asset_version( $relative_path ) {
	$path = ACWPT_PLUGIN_DIR . $relative_path;
	if ( file_exists( $path ) ) {
		return ACWPT_VERSION . '.' . filemtime( $path );
	}
	return ACWPT_VERSION;
}

// Load amplifi.studio shared framework.
require_once ACWPT_PLUGIN_DIR . 'includes/amplifi-framework.php';

require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-languages.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-cache.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-translator.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-preloader.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-admin.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-frontend.php';

// Register with the amplifi.studio framework.
amplifi_register_plugin(
	'ac-wp-translator',
	'Translate',
	'AI-powered real-time translation using OpenAI with URL-based language prefixes and smart caching.',
	ACWPT_VERSION,
	__FILE__,
	array( ACWPT_Admin::instance(), 'render_page' )
);

// Bootstrap.
add_action( 'plugins_loaded', 'acwpt_init', 1 );

function acwpt_init() {
	ACWPT_Preloader::register();
	ACWPT_Frontend::instance()->init();

	if ( is_admin() ) {
		ACWPT_Admin::instance()->init();
	}
}

// Register a nav menu location so Appearance > Menus is available (even in block themes).
add_action( 'after_setup_theme', 'acwpt_register_nav_menus', 20 );

function acwpt_register_nav_menus() {
	register_nav_menus( array(
		'acwpt_languages' => 'Language Switcher (amplifi.translate)',
	) );
}

// Activation.
register_activation_hook( __FILE__, 'acwpt_activate' );

function acwpt_activate() {
	ACWPT_Cache::create_table();

	$defaults = array(
		'api_key'            => '',
		'source_language'    => 'en',
		'enabled_languages'  => array(),
		'show_flags'         => true,
		'show_suggestion'    => true,
		'model'              => 'gpt-4o-mini',
		'preload_auto'       => false,
	);

	if ( ! get_option( 'acwpt_settings' ) ) {
		update_option( 'acwpt_settings', $defaults );
	}

	// Flush rewrite rules so language prefixes work.
	flush_rewrite_rules();
}

// Deactivation.
register_deactivation_hook( __FILE__, 'acwpt_deactivate' );

function acwpt_deactivate() {
	flush_rewrite_rules();
}
