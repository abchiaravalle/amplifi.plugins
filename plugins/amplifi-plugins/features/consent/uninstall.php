<?php
/**
 * Uninstall amplifi.consent — remove all options + the consent-log table.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'acconsent_settings' );
delete_option( 'acconsent_scripts' );
delete_option( 'acconsent_cookies' );
delete_option( 'acconsent_legal' );
delete_option( 'acconsent_db_version' );
delete_option( 'acconsent_token_secret' );

// Drop the consent-log table.
$table = $wpdb->prefix . 'acconsent_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

// Clean up transients (legal-doc URL cache + rate-limit keys).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acconsent_%' OR option_name LIKE '_transient_timeout_acconsent_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear the scheduled retention-purge cron event.
$ts = wp_next_scheduled( 'acconsent_daily_purge' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'acconsent_daily_purge' );
}
wp_clear_scheduled_hook( 'acconsent_daily_purge' );
