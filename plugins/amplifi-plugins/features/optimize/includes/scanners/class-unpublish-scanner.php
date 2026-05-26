<?php
/**
 * Scanner: posts/pages that look like unpublish candidates.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-signal heuristic scanner. Any one signal flips the candidate flag.
 * The Claude proposer then decides the action.
 */
class Amplifi_Optimize_Unpublish_Scanner implements Amplifi_Optimize_Scanner_Interface {

	const TITLE_REGEX  = "/test|draft|don'?t delete|coming soon|reference|temp|placeholder|-2|-3|copy of/i";
	const URL_REGEX    = '#/(.+-(2|3|temp|old|new|copy))/?$#i';
	const STALE_YEARS  = 3;
	const THIN_CHARS   = 100;

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
		return 'unpublish';
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
			)
		);

		$inserted = 0;
		$skipped  = 0;
		$examined = 0;

		foreach ( $query->posts as $post ) {
			$examined++;
			$reasons = $this->evaluate( $post );
			if ( ! $reasons ) {
				$skipped++;
				continue;
			}
			if ( $this->plugin->db->pending_exists( $this->fix_type(), 'post', (int) $post->ID ) ) {
				$skipped++;
				continue;
			}
			$ok = $this->plugin->db->insert(
				array(
					'fix_type'          => $this->fix_type(),
					'target_type'       => 'post',
					'target_id'         => (int) $post->ID,
					'current_value'     => $post->post_title,
					'proposed_metadata' => array(
						'reasons'        => $reasons,
						'url'            => get_permalink( $post ),
						'modified'       => $post->post_modified,
					),
					'status'            => 'pending',
				)
			);
			if ( $ok ) {
				$inserted++;
			}
		}

		return compact( 'inserted', 'examined', 'skipped' );
	}

	/**
	 * Returns the list of fired signal names for a post, or empty if none.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function evaluate( WP_Post $post ): array {
		$reasons = array();

		if ( preg_match( self::TITLE_REGEX, $post->post_title ) ) {
			$reasons[] = 'title_pattern';
		}

		$clean = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		if ( mb_strlen( $clean ) < self::THIN_CHARS ) {
			$reasons[] = 'thin_content';
		}

		$permalink = get_permalink( $post );
		if ( $permalink && preg_match( self::URL_REGEX, $permalink ) ) {
			$reasons[] = 'url_suffix';
		}

		$modified_ts = strtotime( $post->post_modified_gmt . ' UTC' );
		if ( $modified_ts && ( time() - $modified_ts ) > self::STALE_YEARS * YEAR_IN_SECONDS ) {
			if ( ! $this->has_inbound_links( $post ) ) {
				$reasons[] = 'stale_no_inbound';
			}
		}

		return $reasons;
	}

	/**
	 * Checks if any other published post links to this one by URL.
	 *
	 * @param WP_Post $post Target post.
	 */
	private function has_inbound_links( WP_Post $post ): bool {
		global $wpdb;
		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return false;
		}
		// Match by relative path so siteurl changes don't break detection.
		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! $path || '/' === $path ) {
			return false;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND ID != %d
				AND post_content LIKE %s",
				$post->ID,
				'%href=%' . $wpdb->esc_like( $path ) . '%'
			)
		);
		return $count > 0;
	}
}
