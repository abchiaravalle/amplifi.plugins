<?php
/**
 * Scanner: posts whose rendered SEO title exceeds 60 characters.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes rendered SEO title per post and flags those over 60 chars.
 */
class Amplifi_Optimize_Title_Scanner implements Amplifi_Optimize_Scanner_Interface {

	const MAX_LEN = 60;

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
	public function scan( array $args = array() ): array {
		$limit  = max( 1, (int) ( $args['limit'] ?? 200 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$settings   = $this->plugin->get_settings();
		$post_types = array_values( array_filter( (array) $settings['included_post_types'] ) );
		if ( ! $post_types ) {
			$post_types = array( 'post', 'page' );
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		$inserted = 0;
		$skipped  = 0;
		$examined = 0;
		foreach ( $query->posts as $post_id ) {
			$examined++;
			$post_id  = (int) $post_id;
			$rendered = $this->plugin->seo->rendered_title( $post_id );
			if ( mb_strlen( $rendered ) <= self::MAX_LEN ) {
				$skipped++;
				continue;
			}
			if ( $this->plugin->db->pending_exists( $this->fix_type(), 'post', $post_id ) ) {
				$skipped++;
				continue;
			}
			$ok = $this->plugin->db->insert(
				array(
					'fix_type'      => $this->fix_type(),
					'target_type'   => 'post',
					'target_id'     => $post_id,
					'current_value' => $rendered,
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
