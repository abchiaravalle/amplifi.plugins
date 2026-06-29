<?php
/**
 * Uninstall amplifi.consent — remove all options. No custom tables to drop.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'acconsent_settings' );
delete_option( 'acconsent_scripts' );
delete_option( 'acconsent_cookies' );
