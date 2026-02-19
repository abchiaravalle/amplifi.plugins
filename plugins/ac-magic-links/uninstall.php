<?php
/**
 * Uninstall amplifi.magic.
 *
 * Cleans up post meta when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all magic link token data from post meta.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ocml_tokens_hashed_named' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ocml_token_usages_named' ) );
