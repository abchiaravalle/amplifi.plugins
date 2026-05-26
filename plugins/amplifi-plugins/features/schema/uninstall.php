<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$tables = [
	$wpdb->prefix . 'ac_schema_entries',
	$wpdb->prefix . 'ac_schema_bulk_jobs',
	$wpdb->prefix . 'ac_schema_spend',
];
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$option_keys = [
	'ac_schema_settings',
	'ac_schema_global_organization',
	'ac_schema_global_website',
	'ac_schema_global_localbusiness',
	'ac_schema_url_rules',
	'ac_schema_db_version',
	'ac_schema_onboarding_complete',
	'ac_schema_meta_import_status',
];
foreach ( $option_keys as $option_key ) { delete_option( $option_key ); }

delete_post_meta_by_key( '_ac_schema_overrides' );
delete_post_meta_by_key( '_ac_schema_detected_cache' );
