<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$feature_dirs = glob( __DIR__ . '/features/*', GLOB_ONLYDIR );
foreach ( $feature_dirs as $dir ) {
	$uninstall = $dir . '/uninstall.php';
	if ( is_file( $uninstall ) ) {
		include_once $uninstall;
	}
}
