<?php
/**
 * Uninstall amplifi.sync.
 *
 * Cleans up options and backup files when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'acsync_settings' );
delete_option( 'acsync_connection_log' );

// Clean up confirmation token transients (stored as acsync_db_token_{id}).
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acsync_db_token_%' OR option_name LIKE '_transient_timeout_acsync_db_token_%'"
);

// Remove backup files and directory.
$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/acsync-backups';
if ( is_dir( $backup_dir ) ) {
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iter as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}
	@rmdir( $backup_dir );
}
