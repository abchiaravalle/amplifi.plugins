<?php
/**
 * Uninstall AC WP Translator.
 *
 * Cleans up database table and options when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the translations table.
$table = $wpdb->prefix . 'acwpt_translations';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove options.
delete_option( 'acwpt_settings' );
delete_option( 'acwpt_flush_rules' );
