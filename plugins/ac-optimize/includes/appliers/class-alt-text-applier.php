<?php
/**
 * Applier: writes alt text to attachment meta.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the proposed alt text on the attachment.
 */
class Amplifi_Optimize_Alt_Text_Applier implements Amplifi_Optimize_Applier_Interface {

	/**
	 * Plugin instance.
	 *
	 * @var Amplifi_Optimize_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Amplifi_Optimize_Plugin $plugin Plugin singleton.
	 */
	public function __construct( Amplifi_Optimize_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fix_type(): string {
		return 'alt_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function apply( array $suggestion ): array {
		$attachment_id = (int) $suggestion['target_id'];
		$value         = (string) $suggestion['proposed_value'];

		if ( ! get_post( $attachment_id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Attachment no longer exists.', 'amplifi-optimize' ),
			);
		}

		$previous = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$ok       = false !== update_post_meta( $attachment_id, '_wp_attachment_image_alt', $value );

		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'error' => __( 'Failed to write alt text.', 'amplifi-optimize' ),
			);
		}
		return array(
			'ok'       => true,
			'snapshot' => $previous,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function undo( array $suggestion ): array {
		$attachment_id = (int) $suggestion['target_id'];
		$snapshot      = (string) ( $suggestion['previous_snapshot'] ?? '' );
		$ok            = false !== update_post_meta( $attachment_id, '_wp_attachment_image_alt', $snapshot );
		return $ok ? array( 'ok' => true ) : array(
			'ok'    => false,
			'error' => __( 'Failed to restore previous alt text.', 'amplifi-optimize' ),
		);
	}
}
