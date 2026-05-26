<?php
// Intentionally NO `declare(strict_types=1)` here: this file must parse on PHP < 8.1
// so the version-check below can render a graceful admin notice before anything
// 8.1-only is required.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'AMPLIFI_SCHEMA_VERSION' ) ) {
	// Already loaded (e.g. duplicate plugin folder). Bail.
	return;
}

define( 'AMPLIFI_SCHEMA_VERSION', '3.0.2' );
define( 'AMPLIFI_SCHEMA_DB_VERSION', '1' );
define( 'AMPLIFI_SCHEMA_FILE', __FILE__ );
define( 'AMPLIFI_SCHEMA_PATH', plugin_dir_path( __FILE__ ) );
define( 'AMPLIFI_SCHEMA_URL', plugin_dir_url( __FILE__ ) );
define( 'AMPLIFI_SCHEMA_BASENAME', plugin_basename( __FILE__ ) );
define( 'AMPLIFI_SCHEMA_SLUG', 'amplifi-schema' );
define( 'AMPLIFI_SCHEMA_MIN_PHP', '8.1' );
define( 'AMPLIFI_SCHEMA_MIN_WP', '6.4' );
define( 'AMPLIFI_SCHEMA_ACTIVE', true );

/**
 * Render a persistent admin notice. Used when env requirements aren't met.
 *
 * @param string $message HTML-escaped already.
 */
function amplifi_schema_render_blocking_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>amplifi.schema:</strong> ' . $message . '</p></div>';
		}
	);
}

// Environment gate: check PHP and WP versions before loading any 8.1+ code.
if ( version_compare( PHP_VERSION, AMPLIFI_SCHEMA_MIN_PHP, '<' ) ) {
	amplifi_schema_render_blocking_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			esc_html__( 'requires PHP %1$s or newer. This site is running PHP %2$s. The plugin will not run until PHP is upgraded.', 'amplifi-schema' ),
			esc_html( AMPLIFI_SCHEMA_MIN_PHP ),
			esc_html( PHP_VERSION )
		)
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, AMPLIFI_SCHEMA_MIN_WP, '<' ) ) {
	amplifi_schema_render_blocking_notice(
		sprintf(
			/* translators: 1: required WP version, 2: current WP version */
			esc_html__( 'requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'amplifi-schema' ),
			esc_html( AMPLIFI_SCHEMA_MIN_WP ),
			esc_html( $wp_version )
		)
	);
	return;
}

// OpenSSL is required for at-rest encryption of secrets. If unavailable, the
// plugin cannot run safely.
if ( ! extension_loaded( 'openssl' ) ) {
	amplifi_schema_render_blocking_notice(
		esc_html__( 'requires the OpenSSL PHP extension to encrypt API keys at rest.', 'amplifi-schema' )
	);
	return;
}

// Load the amplifi.studio shared framework (top-level menu, hub, auto-updates).
require_once AMPLIFI_SCHEMA_PATH . 'includes/amplifi-framework.php';

// Load namespace autoloader and bootstrap.
require_once AMPLIFI_SCHEMA_PATH . 'includes/class-autoloader.php';
\Amplifi\Schema\Autoloader::register();

register_activation_hook( __FILE__, [ \Amplifi\Schema\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Amplifi\Schema\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	( new \Amplifi\Schema\Plugin() )->boot();
}, 20 );
