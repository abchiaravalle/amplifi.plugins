<?php
/**
 * Uninstall handler for amplifi.optimize.
 *
 * Drops table and removes options only when the user has opted in via the
 * `amplifi_optimize_delete_data_on_uninstall` setting.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = (bool) get_option( 'amplifi_optimize_delete_data_on_uninstall', false );
if ( ! $delete_data ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'amplifi_optimize_suggestions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

$options = array(
	'amplifi_optimize_api_key',
	'amplifi_optimize_model',
	'amplifi_optimize_settings',
	'amplifi_optimize_token_usage',
	'amplifi_optimize_db_version',
	'amplifi_optimize_delete_data_on_uninstall',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}
