<?php
/**
 * Uninstall amplifi.pods.
 *
 * Removes all podcast episode posts, post meta, and RSS cache transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all amplifi-podcasts posts and their meta.
$post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		'amplifi-podcasts'
	)
);

if ( ! empty( $post_ids ) ) {
	foreach ( $post_ids as $id ) {
		wp_delete_post( $id, true );
	}
}

// Delete RSS cache transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acpods_rss_%' OR option_name LIKE '_transient_timeout_acpods_rss_%'"
);
