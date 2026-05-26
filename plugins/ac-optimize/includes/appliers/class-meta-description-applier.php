<?php
/**
 * Applier: writes the approved meta description to the active SEO plugin.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits a meta description via Amplifi_Optimize_SEO_Detector.
 */
class Amplifi_Optimize_Meta_Description_Applier implements Amplifi_Optimize_Applier_Interface {

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
		return 'meta_description';
	}

	/**
	 * {@inheritdoc}
	 */
	public function apply( array $suggestion ): array {
		$post_id = (int) $suggestion['target_id'];
		$value   = (string) $suggestion['proposed_value'];

		if ( ! get_post( $post_id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Post no longer exists.', 'amplifi-optimize' ),
			);
		}

		$previous = $this->plugin->seo->get_meta_description( $post_id );
		$ok       = $this->plugin->seo->set_meta_description( $post_id, $value );

		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'error' => __( 'Failed to write meta description.', 'amplifi-optimize' ),
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
		$post_id  = (int) $suggestion['target_id'];
		$snapshot = (string) ( $suggestion['previous_snapshot'] ?? '' );
		$ok       = $this->plugin->seo->set_meta_description( $post_id, $snapshot );
		return $ok ? array( 'ok' => true ) : array(
			'ok'    => false,
			'error' => __( 'Failed to restore previous meta description.', 'amplifi-optimize' ),
		);
	}
}
