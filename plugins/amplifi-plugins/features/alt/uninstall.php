<?php
/**
 * Uninstall amplifi.alt.
 *
 * Drop the jobs table, clear options, unschedule cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'acalt_jobs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'acalt_settings' );
delete_option( 'acalt_daily_stats' );

wp_clear_scheduled_hook( 'acalt_cron_drain' );
wp_clear_scheduled_hook( 'acalt_daily_report' );
