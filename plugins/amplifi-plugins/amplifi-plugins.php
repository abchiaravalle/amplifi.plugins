<?php
/**
 * Plugin Name:       amplifi.plugins
 * Plugin URI:        https://amplifi.studio/
 * Description:       The complete amplifi.studio WordPress suite. Schema, SEO optimization, security, translation, sync, magic links, podcasts, static cache, and more.
 * Version:           3.0.3
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

define( 'AMPLIFI_PLUGINS_VERSION', '3.0.3' );
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

// ---- Feature registry ----
$amplifi_all_features = [
	'schema'    => [ 'file' => 'features/schema/ac-schema.php',            'name' => 'Schema',    'desc' => 'AI schema.org JSON-LD generation, editing, and deployment.' ],
	'security'  => [ 'file' => 'features/security/amplifi-security.php',   'name' => 'Security',  'desc' => 'AI-powered security scanning with Claude triage.' ],
	'meta'      => [ 'file' => 'features/meta/ac-bulk-meta.php',           'name' => 'Meta',      'desc' => 'Bulk SEO meta editor with FAQ generation.' ],
	'magic'     => [ 'file' => 'features/magic/ac-magic-links.php',        'name' => 'Magic',     'desc' => 'One-click magic links for password-protected pages.' ],
	'pods'      => [ 'file' => 'features/pods/ac-pods.php',                'name' => 'Pods',      'desc' => 'Podcast carousel and floating player.' ],
	'cache'     => [ 'file' => 'features/cache/ac-static-cache.php',       'name' => 'LockCache', 'desc' => 'Static HTML cache for password-protected posts.' ],
	'sync'      => [ 'file' => 'features/sync/ac-sync.php',               'name' => 'Sync',      'desc' => 'REST API sync between WordPress environments.' ],
	'translate' => [ 'file' => 'features/translate/ac-wp-translator.php',  'name' => 'Translate', 'desc' => 'AI-powered real-time translation via Claude.' ],
	'alt'       => [ 'file' => 'features/alt/ac-alt-text.php',             'name' => 'Alt',       'desc' => 'AI alt text for WordPress images.' ],
	'optimize'  => [ 'file' => 'features/optimize/amplifi-optimize.php',   'name' => 'Optimize',  'desc' => 'AI SEO triage — scan, propose fixes, approve.' ],
];

// Load only enabled features. Default: none enabled.
$amplifi_enabled = get_option( 'amplifi_plugins_enabled_features', [] );
if ( ! is_array( $amplifi_enabled ) ) {
	$amplifi_enabled = [];
}

foreach ( $amplifi_all_features as $slug => $feature ) {
	if ( ! in_array( $slug, $amplifi_enabled, true ) ) {
		continue;
	}
	$full_path = AMPLIFI_PLUGINS_PATH . $feature['file'];
	if ( is_file( $full_path ) ) {
		require_once $full_path;
	}
}

// ---- Master activation hook ----
register_activation_hook( __FILE__, function () {
	if ( class_exists( \Amplifi\Schema\Installer::class ) ) {
		\Amplifi\Schema\Installer::install();
	}
	if ( class_exists( \Amplifi\Schema\Activator::class ) ) {
		\Amplifi\Schema\Activator::activate();
	}
	if ( class_exists( \Amplifi\Security\Installer::class ) ) {
		\Amplifi\Security\Installer::install();
	}
	if ( class_exists( \Amplifi\Security\Activator::class ) ) {
		\Amplifi\Security\Activator::activate();
	}
	if ( class_exists( 'AC_Bulk_Meta' ) ) {
		$m = new \AC_Bulk_Meta();
		if ( method_exists( $m, 'activate' ) ) {
			$m->activate();
		}
	}
	if ( class_exists( 'ACWPT_Admin' ) && method_exists( 'ACWPT_Admin', 'activate_plugin' ) ) {
		\ACWPT_Admin::activate_plugin();
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

// ---- Feature toggle AJAX handler ----
add_action( 'wp_ajax_amplifi_toggle_feature', function () {
	check_ajax_referer( 'amplifi_toggle_feature' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden' );
	}
	$feature = sanitize_key( wp_unslash( $_POST['feature'] ?? '' ) );
	$enable  = (bool) ( $_POST['enable'] ?? false );
	$enabled = get_option( 'amplifi_plugins_enabled_features', [] );
	if ( ! is_array( $enabled ) ) {
		$enabled = [];
	}
	if ( $enable && ! in_array( $feature, $enabled, true ) ) {
		$enabled[] = $feature;
	} elseif ( ! $enable ) {
		$enabled = array_values( array_filter( $enabled, fn( $f ) => $f !== $feature ) );
	}
	update_option( 'amplifi_plugins_enabled_features', $enabled );
	wp_send_json_success( [ 'enabled' => $enabled ] );
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
