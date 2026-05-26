<?php
/**
 * Applier: writes SEO title via Amplifi_Optimize_SEO_Detector. Does not
 * touch post_title unless `also_update_post_title` is true in metadata.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Title applier.
 */
class Amplifi_Optimize_Title_Applier implements Amplifi_Optimize_Applier_Interface {

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
		return 'title';
	}

	/**
	 * {@inheritdoc}
	 */
	public function apply( array $suggestion ): array {
		$post_id = (int) $suggestion['target_id'];
		$value   = (string) $suggestion['proposed_value'];
		$meta    = is_array( $suggestion['proposed_metadata'] ?? null ) ? $suggestion['proposed_metadata'] : array();
		$also_post_title = ! empty( $meta['also_update_post_title'] );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'ok'    => false,
				'error' => __( 'Post no longer exists.', 'amplifi-optimize' ),
			);
		}

		$key      = $this->plugin->seo->title_key();
		$previous = array(
			'seo_title'  => $key ? (string) get_post_meta( $post_id, $key, true ) : '',
			'post_title' => $post->post_title,
		);

		$ok = $this->plugin->seo->set_title( $post_id, $value );
		if ( ! $ok && ! $key ) {
			$ok = true; // no SEO plugin — we'll let post_title carry the title.
		}

		if ( $also_post_title ) {
			$updated = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $value,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return array(
					'ok'    => false,
					'error' => $updated->get_error_message(),
				);
			}
		}

		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'error' => __( 'Failed to write SEO title.', 'amplifi-optimize' ),
			);
		}
		return array(
			'ok'       => true,
			'snapshot' => wp_json_encode( $previous ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function undo( array $suggestion ): array {
		$post_id  = (int) $suggestion['target_id'];
		$snapshot = json_decode( (string) ( $suggestion['previous_snapshot'] ?? '' ), true );
		if ( ! is_array( $snapshot ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No undo snapshot available.', 'amplifi-optimize' ),
			);
		}
		$this->plugin->seo->set_title( $post_id, (string) ( $snapshot['seo_title'] ?? '' ) );
		if ( isset( $snapshot['post_title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => (string) $snapshot['post_title'],
				)
			);
		}
		return array( 'ok' => true );
	}
}
