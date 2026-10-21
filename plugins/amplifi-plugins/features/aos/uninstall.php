<?php
/**
 * Uninstall amplifi AOS: remove its single settings option.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'amplifi_aos_settings' );
