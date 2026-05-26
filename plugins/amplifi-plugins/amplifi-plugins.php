<?php
/**
 * Plugin Name:       amplifi.plugins
 * Plugin URI:        https://amplifi.studio/
 * Description:       The complete amplifi.studio WordPress suite. Schema, SEO optimization, security, translation, sync, magic links, podcasts, static cache, and more.
 * Version:           3.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Amplifi Studio
 * Author URI:        https://amplifi.studio/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       amplifi-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMPLIFI_PLUGINS_VERSION', '3.0.2' );
define( 'AMPLIFI_PLUGINS_FILE', __FILE__ );
define( 'AMPLIFI_PLUGINS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AMPLIFI_PLUGINS_URL', plugin_dir_url( __FILE__ ) );
define( 'AMPLIFI_PLUGINS_BASENAME', plugin_basename( __FILE__ ) );

// Load the shared framework FIRST.
// Each feature also has a framework copy, but the AMPLIFI_FRAMEWORK_LOADED guard
// makes them no-ops. This ensures the menu, hub, and auto-updater are registered once.
require_once AMPLIFI_PLUGINS_PATH . 'includes/amplifi-framework.php';

// Register the combined plugin for GitHub auto-updates.
// The framework's updater matches zip assets by slug — 'amplifi-plugins' matches
// 'amplifi-plugins-v3.0.0.zip'. Individual features no longer need their own zips.
add_action( 'init', function () {
	global $amplifi_plugins;
	$amplifi_plugins['amplifi-plugins'] = [
		'slug'        => 'amplifi-plugins',
		'name'        => 'amplifi.plugins',
		'description' => 'The complete amplifi.studio suite.',
		'version'     => AMPLIFI_PLUGINS_VERSION,
		'file'        => AMPLIFI_PLUGINS_FILE,
	];
}, 99 );

// ---- Feature bootstraps ----
// Each feature defines its own constants, loads its own classes, hooks into WP.
// The framework is already loaded above, so features' own framework loads are no-ops.

$amplifi_features = [
	'schema'    => 'features/schema/ac-schema.php',
	'security'  => 'features/security/amplifi-security.php',
	'meta'      => 'features/meta/ac-bulk-meta.php',
	'magic'     => 'features/magic/ac-magic-links.php',
	'pods'      => 'features/pods/ac-pods.php',
	'cache'     => 'features/cache/ac-static-cache.php',
	'sync'      => 'features/sync/ac-sync.php',
	'translate' => 'features/translate/ac-wp-translator.php',
	'alt'       => 'features/alt/ac-alt-text.php',
	'optimize'  => 'features/optimize/amplifi-optimize.php',
];

foreach ( $amplifi_features as $feature_name => $feature_path ) {
	$full_path = AMPLIFI_PLUGINS_PATH . $feature_path;
	if ( is_file( $full_path ) ) {
		require_once $full_path;
	}
}

// ---- Master activation hook ----
// Feature files call register_activation_hook( __FILE__, ... ) but __FILE__
// points to the feature file, not the main plugin file. WordPress only fires
// activation hooks for the file declared in "Plugin Name:". So we call each
// feature's installer/activator manually here.
register_activation_hook( __FILE__, function () {
	// Schema
	if ( class_exists( \Amplifi\Schema\Installer::class ) ) {
		\Amplifi\Schema\Installer::install();
	}
	if ( class_exists( \Amplifi\Schema\Activator::class ) ) {
		\Amplifi\Schema\Activator::activate();
	}
	// Security
	if ( class_exists( \Amplifi\Security\Installer::class ) ) {
		\Amplifi\Security\Installer::install();
	}
	if ( class_exists( \Amplifi\Security\Activator::class ) ) {
		\Amplifi\Security\Activator::activate();
	}
	// ac-bulk-meta: creates FAQ table on activation
	if ( class_exists( 'AC_Bulk_Meta' ) ) {
		$m = new \AC_Bulk_Meta();
		if ( method_exists( $m, 'activate' ) ) {
			$m->activate();
		}
	}
	// ac-wp-translator: creates translations table on activation
	if ( class_exists( 'ACWPT_Admin' ) ) {
		if ( method_exists( 'ACWPT_Admin', 'activate_plugin' ) ) {
			\ACWPT_Admin::activate_plugin();
		}
	}
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	if ( class_exists( \Amplifi\Schema\Deactivator::class ) ) {
		\Amplifi\Schema\Deactivator::deactivate();
	}
	if ( class_exists( \Amplifi\Security\Deactivator::class ) ) {
		\Amplifi\Security\Deactivator::deactivate();
	}
	flush_rewrite_rules();
} );

// ---- Detect old individual amplifi plugins ----
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$old_plugins = [
		'ac-schema/ac-schema.php'              => 'amplifi.schema',
		'amplifi-security/amplifi-security.php' => 'amplifi.security',
		'ac-bulk-meta/ac-bulk-meta.php'         => 'amplifi.meta',
		'ac-magic-links/ac-magic-links.php'     => 'amplifi.magic',
		'ac-pods/ac-pods.php'                   => 'amplifi.pods',
		'ac-static-cache/ac-static-cache.php'   => 'amplifi.lockcache',
		'ac-sync/ac-sync.php'                   => 'amplifi.sync',
		'ac-wp-translator/ac-wp-translator.php' => 'amplifi.translate',
		'ac-alt-text/ac-alt-text.php'           => 'amplifi.alt',
		'ac-optimize/amplifi-optimize.php'      => 'amplifi.optimize',
	];
	$active = [];
	foreach ( $old_plugins as $basename => $label ) {
		if ( is_plugin_active( $basename ) ) {
			$active[ $basename ] = $label;
		}
	}
	if ( empty( $active ) ) {
		return;
	}
	$names = implode( ', ', $active );
	$nonce = wp_create_nonce( 'amplifi_plugins_deactivate_old' );
	echo '<div class="notice notice-warning" id="amplifi-old-plugins-notice">';
	echo '<p><strong>amplifi.plugins:</strong> Detected old individual plugins still active: <em>' . esc_html( $names ) . '</em>. ';
	echo 'These features are now included here. ';
	echo '<button type="button" class="button button-primary" id="amplifi-deactivate-old">Deactivate old plugins</button></p>';
	echo '</div>';
	echo '<script>document.getElementById("amplifi-deactivate-old").addEventListener("click",function(){';
	echo 'fetch(ajaxurl+"?action=amplifi_deactivate_old_plugins&_wpnonce=' . esc_js( $nonce ) . '",{credentials:"same-origin"})';
	echo '.then(function(){location.reload();});});</script>';
} );

add_action( 'wp_ajax_amplifi_deactivate_old_plugins', function () {
	check_ajax_referer( 'amplifi_plugins_deactivate_old' );
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$old_plugins = [
		'ac-schema/ac-schema.php',
		'amplifi-security/amplifi-security.php',
		'ac-bulk-meta/ac-bulk-meta.php',
		'ac-magic-links/ac-magic-links.php',
		'ac-pods/ac-pods.php',
		'ac-static-cache/ac-static-cache.php',
		'ac-sync/ac-sync.php',
		'ac-wp-translator/ac-wp-translator.php',
		'ac-alt-text/ac-alt-text.php',
		'ac-optimize/amplifi-optimize.php',
	];
	deactivate_plugins( $old_plugins, false, false );
	wp_send_json_success();
} );
