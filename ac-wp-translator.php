<?php
/**
 * Plugin Name: AC WP Translator
 * Plugin URI: https://github.com/adamchiaravalle/ac-wp-translator
 * Description: AI-powered real-time translation using OpenAI. Translates pages and posts with URL-based language prefixes (/es/, /fr/, etc.) and smart caching.
 * Version: 1.0.0
 * Author: Adam Chiaravalle
 * License: GPL v2 or later
 * Text Domain: ac-wp-translator
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACWPT_VERSION', '1.0.0' );
define( 'ACWPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACWPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACWPT_PLUGIN_FILE', __FILE__ );

require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-languages.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-cache.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-translator.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-admin.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-frontend.php';

// Bootstrap.
add_action( 'plugins_loaded', 'acwpt_init', 1 );

function acwpt_init() {
	ACWPT_Frontend::instance()->init();

	if ( is_admin() ) {
		ACWPT_Admin::instance()->init();
	}
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
