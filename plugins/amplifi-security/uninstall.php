<?php
/**
 * Uninstall handler — fires when the plugin is *deleted* (not just deactivated).
 *
 * Drops every `wp_amplifi_security_*` table and removes every `amplifi_security_*`
 * option, unless the user toggled `Preserve data on uninstall` in Settings.
 *
 * @package Amplifi\Security
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$preserve = (bool) get_option( 'amplifi_security_preserve_data_on_uninstall', false );

if ( ! $preserve ) {
	$tables = [
		'findings',
		'baseline',
		'auth_log',
		'audit',
		'scans',
		'verdict_cache',
		'log_sources',
		'vuln_feed',
		'spend',
	];
	foreach ( $tables as $table ) {
		$name = $wpdb->prefix . 'amplifi_security_' . $table;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
	}

	// Remove all amplifi_security_* options.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'amplifi_security_' ) . '%'
		)
	);

	// Remove transients (both site and network).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_amplifi_security_%',
			'_transient_timeout_amplifi_security_%'
		)
	);

	// Clear scheduled cron events.
	foreach ( [
		'amplifi_security_run_scan',
		'amplifi_security_audit_prune',
		'amplifi_security_vuln_feed_refresh',
		'amplifi_security_daily_digest',
		'amplifi_security_self_integrity',
	] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
