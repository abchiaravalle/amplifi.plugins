<?php
/**
 * Listen for new attachments and enqueue them when auto-on-upload is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Uploader_Hook {

	public static function register() {
		add_action( 'add_attachment', array( __CLASS__, 'on_attachment' ) );
	}

	public static function on_attachment( $attachment_id ) {
		$settings = get_option( 'acalt_settings', array() );
		if ( empty( $settings['auto_on_upload'] ) ) {
			return;
		}

		$post = get_post( $attachment_id );
		if ( ! $post || strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return;
		}

		$existing = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $existing !== '' ) {
			return;
		}

		ACALT_Queue::enqueue( (int) $attachment_id, 'upload' );
	}
}
