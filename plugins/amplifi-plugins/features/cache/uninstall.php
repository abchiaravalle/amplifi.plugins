<?php
/**
 * Uninstall amplifi.lockcache.
 *
 * Removes the cache directory and all contents (cache files, .htaccess, debug log) when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cache_dir = WP_CONTENT_DIR . '/pp-static-cache/';
if ( ! is_dir( $cache_dir ) ) {
	return;
}

// Remove all files (cache-*.html, .htaccess, ppsc-debug.log).
$files = array_merge(
	(array) glob( $cache_dir . 'cache-*.html' ),
	array( $cache_dir . '.htaccess', $cache_dir . 'ppsc-debug.log' )
);
foreach ( $files as $file ) {
	if ( $file && is_file( $file ) ) {
		@unlink( $file );
	}
}
@rmdir( $cache_dir );
