<?php
/**
 * Scanner: image attachments without alt text.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds image attachments where `_wp_attachment_image_alt` is empty.
 * Skips small images and SVGs by default.
 */
class Amplifi_Optimize_Alt_Text_Scanner implements Amplifi_Optimize_Scanner_Interface {

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
	public function scan( array $args = array() ): array {
		global $wpdb;

		$limit  = max( 1, (int) ( $args['limit'] ?? 500 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$settings    = $this->plugin->get_settings();
		$min_dim     = max( 1, (int) $settings['min_image_dimension'] );
		$include_svg = (bool) $settings['include_svg'];

		// Direct SQL is appropriate here — we need a NOT EXISTS against postmeta
		// that meta_query cannot express cleanly with WP_Query at scale.
		$sql = "
			SELECT p.ID, p.post_mime_type
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%%'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				AND pm.meta_key = '_wp_attachment_image_alt'
				AND TRIM(pm.meta_value) != ''
			)
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d
		";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$inserted = 0;
		$skipped  = 0;
		$examined = 0;

		foreach ( (array) $rows as $row ) {
			$examined++;
			$attachment_id = (int) $row['ID'];
			$mime          = (string) $row['post_mime_type'];

			if ( 'image/svg+xml' === $mime && ! $include_svg ) {
				$skipped++;
				continue;
			}

			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
				if ( (int) $meta['width'] < $min_dim || (int) $meta['height'] < $min_dim ) {
					$skipped++;
					continue;
				}
			}

			if ( $this->plugin->db->pending_exists( $this->fix_type(), 'attachment', $attachment_id ) ) {
				$skipped++;
				continue;
			}

			$ok = $this->plugin->db->insert(
				array(
					'fix_type'      => $this->fix_type(),
					'target_type'   => 'attachment',
					'target_id'     => $attachment_id,
					'current_value' => '',
					'status'        => 'pending',
				)
			);
			if ( $ok ) {
				$inserted++;
			}
		}

		return compact( 'inserted', 'examined', 'skipped' );
	}
}
