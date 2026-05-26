<?php
// Intentionally NO `declare(strict_types=1)` here: this file must parse on PHP < 8.1
// so the version-check below can render a graceful admin notice before anything
// 8.1-only is required.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'AMPLIFI_SECURITY_VERSION' ) ) {
	// Already loaded (e.g. duplicate plugin folder). Bail.
	return;
}

define( 'AMPLIFI_SECURITY_VERSION', '3.0.7' );
define( 'AMPLIFI_SECURITY_DB_VERSION', '1' );
define( 'AMPLIFI_SECURITY_FILE', __FILE__ );
define( 'AMPLIFI_SECURITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'AMPLIFI_SECURITY_URL', plugin_dir_url( __FILE__ ) );
define( 'AMPLIFI_SECURITY_BASENAME', plugin_basename( __FILE__ ) );
define( 'AMPLIFI_SECURITY_SLUG', 'amplifi-security' );
define( 'AMPLIFI_SECURITY_MIN_PHP', '8.1' );
define( 'AMPLIFI_SECURITY_MIN_WP', '6.4' );

/**
 * Render a persistent admin notice. Used when env requirements aren't met.
 *
 * @param string $message HTML-escaped already.
 */
function amplifi_security_render_blocking_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>amplifi.security:</strong> ' . $message . '</p></div>';
		}
	);
}

// Environment gate: check PHP and WP versions before loading any 8.1+ code.
if ( version_compare( PHP_VERSION, AMPLIFI_SECURITY_MIN_PHP, '<' ) ) {
	amplifi_security_render_blocking_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			esc_html__( 'requires PHP %1$s or newer. This site is running PHP %2$s. The plugin will not run until PHP is upgraded.', 'amplifi-security' ),
			esc_html( AMPLIFI_SECURITY_MIN_PHP ),
			esc_html( PHP_VERSION )
		)
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, AMPLIFI_SECURITY_MIN_WP, '<' ) ) {
	amplifi_security_render_blocking_notice(
		sprintf(
			/* translators: 1: required WP version, 2: current WP version */
			esc_html__( 'requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'amplifi-security' ),
			esc_html( AMPLIFI_SECURITY_MIN_WP ),
			esc_html( $wp_version )
		)
	);
	return;
}

// OpenSSL is required for at-rest encryption of secrets. If unavailable, the
// plugin cannot run safely.
if ( ! extension_loaded( 'openssl' ) ) {
	amplifi_security_render_blocking_notice(
		esc_html__( 'requires the OpenSSL PHP extension to encrypt API keys at rest.', 'amplifi-security' )
	);
	return;
}

// Load the amplifi.studio shared framework (top-level menu, hub, auto-updates).
require_once AMPLIFI_SECURITY_PATH . 'includes/amplifi-framework.php';

// Load namespace autoloader and bootstrap.
require_once AMPLIFI_SECURITY_PATH . 'includes/class-autoloader.php';
\Amplifi\Security\Autoloader::register();

require_once AMPLIFI_SECURITY_PATH . 'includes/class-activator.php';
require_once AMPLIFI_SECURITY_PATH . 'includes/class-deactivator.php';

register_activation_hook( __FILE__, [ \Amplifi\Security\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Amplifi\Security\Deactivator::class, 'deactivate' ] );

// Boot the plugin once WP is fully loaded.
add_action(
	'plugins_loaded',
	static function () {
		\Amplifi\Security\Plugin::instance()->init();
	}
);
