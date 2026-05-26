<?php
/**
 * Uninstall amplifi.meta.
 *
 * Cleans up database table and options when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the FAQs table.
$table = $wpdb->prefix . 'ac_faqs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove options.
delete_option( 'ac_openai_api_key' );
delete_option( 'ac_global_prompt' );
delete_option( 'ac_site_title_override' );
delete_option( 'ac_webhook_url' );
delete_option( 'ac_webhook_url_set_by' );
delete_option( 'ac_faq_focus' );
delete_option( 'ac_faq_count' );
delete_option( 'ac_faq_deploy_global' );
delete_option( 'ac_jsonld_settings' );
delete_option( 'ac_ai_generation_log' );
delete_option( 'ac_bulk_generation_status' );
