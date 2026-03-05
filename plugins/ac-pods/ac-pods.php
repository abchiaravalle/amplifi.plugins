<?php
/*
Plugin Name: amplifi.pods
Plugin URI: https://github.com/abchiaravalle/amplifi.plugins
Description: Podcast carousel and floating player via Apple Podcasts RSS feed or built-in custom post type.
Version: 1.2.7
Author: amplifi.studio
Author URI: https://amplifi.studio
License: MIT
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACPODS_VERSION', '1.2.7' );
define( 'ACPODS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPODS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPODS_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACPODS_PLUGIN_DIR . 'includes/amplifi-framework.php';

class Amplifi_Pods {

	/**
	 * Whether the floating player HTML has been rendered (once per page).
	 */
	private static $player_rendered = false;

	public function __construct() {
		// Register with the amplifi.studio framework.
		amplifi_register_plugin(
			'ac-pods',
			'Pods',
			'Podcast carousel and floating player via Apple Podcasts RSS feed or built-in custom post type.',
			ACPODS_VERSION,
			ACPODS_PLUGIN_FILE,
			array( $this, 'render_admin_page' )
		);

		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_episode_meta_box' ) );
		add_action( 'save_post_amplifi-podcasts', array( $this, 'save_episode_meta' ) );
		add_shortcode( 'amplifi-pods', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	// =========================================================================
	// CPT + Taxonomy
	// =========================================================================

	/**
	 * Register the amplifi-podcasts custom post type.
	 */
	public function register_cpt() {
		register_post_type( 'amplifi-podcasts', array(
			'labels' => array(
				'name'               => 'Podcast Episodes',
				'singular_name'      => 'Episode',
				'add_new'            => 'Add Episode',
				'add_new_item'       => 'Add New Episode',
				'edit_item'          => 'Edit Episode',
				'new_item'           => 'New Episode',
				'view_item'          => 'View Episode',
				'search_items'       => 'Search Episodes',
				'not_found'          => 'No episodes found',
				'not_found_in_trash' => 'No episodes found in Trash',
				'menu_name'          => 'Podcast Episodes',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-microphone',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'  => false,
			'rewrite'      => false,
		) );
	}

	/**
	 * Register the acpods_category taxonomy.
	 */
	public function register_taxonomy() {
		register_taxonomy( 'acpods_category', 'amplifi-podcasts', array(
			'labels' => array(
				'name'          => 'Episode Categories',
				'singular_name' => 'Category',
				'search_items'  => 'Search Categories',
				'all_items'     => 'All Categories',
				'edit_item'     => 'Edit Category',
				'update_item'   => 'Update Category',
				'add_new_item'  => 'Add New Category',
				'new_item_name' => 'New Category Name',
				'menu_name'     => 'Categories',
			),
			'hierarchical' => true,
			'show_ui'      => true,
			'show_admin_column' => true,
			'rewrite'      => false,
		) );
	}

	// =========================================================================
	// Meta Box
	// =========================================================================

	/**
	 * Add the episode details meta box.
	 */
	public function add_episode_meta_box() {
		add_meta_box(
			'acpods_episode_details',
			'Episode Details',
			array( $this, 'render_episode_meta_box' ),
			'amplifi-podcasts',
			'normal',
			'high'
		);
	}

	/**
	 * Render the episode details meta box fields.
	 */
	public function render_episode_meta_box( $post ) {
		wp_nonce_field( 'acpods_save_meta', 'acpods_meta_nonce' );

		$fields = array(
			'_acpods_show_name'          => array( 'label' => 'Show Name',          'placeholder' => 'e.g. The Daily' ),
			'_acpods_apple_show_id'      => array( 'label' => 'Apple Show ID',      'placeholder' => 'e.g. 1200361736' ),
			'_acpods_apple_episode_id'   => array( 'label' => 'Apple Episode ID',   'placeholder' => 'e.g. 1000612345678' ),
			'_acpods_artwork_url'        => array( 'label' => 'Artwork URL',        'placeholder' => 'https://...' ),
			'_acpods_episode_number'     => array( 'label' => 'Episode Number',     'placeholder' => 'e.g. 42' ),
			'_acpods_duration'           => array( 'label' => 'Duration',           'placeholder' => 'e.g. 42 min' ),
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $meta ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $meta['label'] ) . '</label></th>';
			echo '<td><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $meta['placeholder'] ) . '" class="large-text"></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">If Artwork URL is empty, the post\'s featured image will be used. Apple Show ID and Episode ID are used to build the embed player URL.</p>';
	}

	/**
	 * Save episode meta fields.
	 */
	public function save_episode_meta( $post_id ) {
		if ( ! isset( $_POST['acpods_meta_nonce'] ) || ! wp_verify_nonce( $_POST['acpods_meta_nonce'], 'acpods_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$keys = array(
			'_acpods_show_name',
			'_acpods_apple_show_id',
			'_acpods_apple_episode_id',
			'_acpods_artwork_url',
			'_acpods_episode_number',
			'_acpods_duration',
		);

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
			}
		}
	}

	// =========================================================================
	// Shortcode
	// =========================================================================

	/**
	 * [amplifi-pods] shortcode handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'feed'     => '',
			'category' => '',
			'count'    => 12,
		), $atts, 'amplifi-pods' );

		$count = absint( $atts['count'] ) ?: 12;

		// Determine mode: RSS feed vs CPT.
		if ( ! empty( $atts['feed'] ) ) {
			$episodes = $this->fetch_rss_episodes( $atts['feed'], $count );
		} else {
			$episodes = $this->query_cpt_episodes( $atts['category'], $count );
		}

		if ( empty( $episodes ) ) {
			return '<p class="acpods-empty">No podcast episodes found.</p>';
		}

		// Enqueue assets on demand.
		wp_enqueue_style( 'acpods-swiper' );
		wp_enqueue_script( 'acpods-swiper' );
		wp_enqueue_style( 'acpods-styles' );
		wp_enqueue_script( 'acpods-scripts' );

		$html  = $this->render_carousel_html( $episodes );
		$html .= $this->render_player_html();

		return $html;
	}

	// =========================================================================
	// RSS Feed Mode
	// =========================================================================

	/**
	 * Fetch and parse episodes from an Apple Podcasts RSS feed.
	 *
	 * @param string $feed_url RSS feed URL.
	 * @param int    $count    Max episodes.
	 * @return array Normalized episode data.
	 */
	private function fetch_rss_episodes( $feed_url, $count ) {
		$cache_key = 'acpods_rss_' . md5( $feed_url );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return array_slice( $cached, 0, $count );
		}

		$response = wp_remote_get( $feed_url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( ! $xml ) {
			return array();
		}

		$itunes = $xml->channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );

		// Show-level info.
		$show_name     = (string) $xml->channel->title;
		$show_artwork  = '';
		if ( isset( $itunes->image ) ) {
			$show_artwork = (string) $itunes->image->attributes()->href;
		}

		// Try to extract Apple show ID from the channel link or atom links.
		$apple_show_id = $this->extract_apple_show_id( $xml );

		$episodes = array();
		$ep_num   = 0;

		foreach ( $xml->channel->item as $item ) {
			$ep_itunes = $item->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
			$ep_num++;

			// Episode number from iTunes tag or counter.
			$episode_number = '';
			if ( isset( $ep_itunes->episode ) ) {
				$episode_number = (string) $ep_itunes->episode;
			}
			if ( empty( $episode_number ) ) {
				$episode_number = (string) $ep_num;
			}

			// Duration.
			$duration = '';
			if ( isset( $ep_itunes->duration ) ) {
				$duration = $this->normalize_duration( (string) $ep_itunes->duration );
			}

			// Artwork: episode-level, then show-level.
			$artwork = '';
			if ( isset( $ep_itunes->image ) ) {
				$artwork = (string) $ep_itunes->image->attributes()->href;
			}
			if ( empty( $artwork ) ) {
				$artwork = $show_artwork;
			}

			// Description.
			$description = '';
			if ( isset( $ep_itunes->summary ) ) {
				$description = (string) $ep_itunes->summary;
			} elseif ( isset( $item->description ) ) {
				$description = (string) $item->description;
			}

			// Apple episode ID from the guid or link.
			$apple_episode_id = $this->extract_apple_episode_id( $item );

			$episodes[] = array(
				'title'            => (string) $item->title,
				'description'      => wp_strip_all_tags( $description ),
				'show_name'        => $show_name,
				'apple_show_id'    => $apple_show_id,
				'apple_episode_id' => $apple_episode_id,
				'artwork_url'      => $artwork,
				'episode_number'   => $episode_number,
				'duration'         => $duration,
			);
		}

		// Cache all episodes for 1 hour, then slice on retrieval.
		set_transient( $cache_key, $episodes, HOUR_IN_SECONDS );

		return array_slice( $episodes, 0, $count );
	}

	/**
	 * Try to extract the Apple Podcasts show ID from the RSS feed.
	 */
	private function extract_apple_show_id( $xml ) {
		// Check channel link.
		$link = (string) $xml->channel->link;
		if ( preg_match( '/\/id(\d+)/', $link, $m ) ) {
			return $m[1];
		}

		// Check Atom links.
		$atom = $xml->channel->children( 'http://www.w3.org/2005/Atom' );
		if ( isset( $atom->link ) ) {
			foreach ( $atom->link as $alink ) {
				$href = (string) $alink->attributes()->href;
				if ( preg_match( '/\/id(\d+)/', $href, $m ) ) {
					return $m[1];
				}
			}
		}

		return '';
	}

	/**
	 * Try to extract an Apple episode ID from an RSS item.
	 */
	private function extract_apple_episode_id( $item ) {
		// Check <link>.
		$link = (string) $item->link;
		if ( preg_match( '/i=(\d+)/', $link, $m ) ) {
			return $m[1];
		}

		// Check <guid>.
		$guid = (string) $item->guid;
		if ( preg_match( '/i=(\d+)/', $guid, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Normalize a duration value to "X min" format.
	 *
	 * Accepts seconds (integer), "HH:MM:SS", or "MM:SS".
	 */
	private function normalize_duration( $raw ) {
		$raw = trim( $raw );

		// Pure integer = seconds.
		if ( ctype_digit( $raw ) ) {
			$minutes = max( 1, round( intval( $raw ) / 60 ) );
			return $minutes . ' min';
		}

		// HH:MM:SS or MM:SS.
		$parts = explode( ':', $raw );
		if ( count( $parts ) === 3 ) {
			$minutes = max( 1, intval( $parts[0] ) * 60 + intval( $parts[1] ) + round( intval( $parts[2] ) / 60 ) );
			return $minutes . ' min';
		}
		if ( count( $parts ) === 2 ) {
			$minutes = max( 1, intval( $parts[0] ) + round( intval( $parts[1] ) / 60 ) );
			return $minutes . ' min';
		}

		return $raw;
	}

	// =========================================================================
	// CPT Mode
	// =========================================================================

	/**
	 * Query episodes from the amplifi-podcasts CPT.
	 *
	 * @param string $category Taxonomy slug to filter by.
	 * @param int    $count    Max episodes.
	 * @return array Normalized episode data.
	 */
	private function query_cpt_episodes( $category, $count ) {
		$args = array(
			'post_type'      => 'amplifi-podcasts',
			'posts_per_page' => $count,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $category ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'acpods_category',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $category ),
				),
			);
		}

		$query    = new WP_Query( $args );
		$episodes = array();

		foreach ( $query->posts as $post ) {
			$artwork = get_post_meta( $post->ID, '_acpods_artwork_url', true );
			if ( empty( $artwork ) && has_post_thumbnail( $post->ID ) ) {
				$artwork = get_the_post_thumbnail_url( $post->ID, 'medium' );
			}

			$episodes[] = array(
				'title'            => $post->post_title,
				'description'      => wp_strip_all_tags( $post->post_content ),
				'show_name'        => get_post_meta( $post->ID, '_acpods_show_name', true ),
				'apple_show_id'    => get_post_meta( $post->ID, '_acpods_apple_show_id', true ),
				'apple_episode_id' => get_post_meta( $post->ID, '_acpods_apple_episode_id', true ),
				'artwork_url'      => $artwork,
				'episode_number'   => get_post_meta( $post->ID, '_acpods_episode_number', true ),
				'duration'         => get_post_meta( $post->ID, '_acpods_duration', true ),
			);
		}

		wp_reset_postdata();
		return $episodes;
	}

	// =========================================================================
	// Carousel + Player Rendering
	// =========================================================================

	/**
	 * Build the Swiper carousel HTML for a set of episodes.
	 */
	private function render_carousel_html( $episodes ) {
		$id = 'acpods-' . wp_unique_id();

		$html  = '<div class="acpods-carousel-wrap" id="' . esc_attr( $id ) . '">';
		$html .= '<div class="swiper acpods-swiper">';
		$html .= '<div class="swiper-wrapper">';

		foreach ( $episodes as $ep ) {
			$embed_src = '';
			if ( ! empty( $ep['apple_show_id'] ) && ! empty( $ep['apple_episode_id'] ) ) {
				$embed_src = 'https://embed.podcasts.apple.com/us/podcast/id' . esc_attr( $ep['apple_show_id'] ) . '?i=' . esc_attr( $ep['apple_episode_id'] ) . '&theme=auto';
			}

			$html .= '<div class="swiper-slide acpods-card"'
				. ' data-embed-src="' . esc_attr( $embed_src ) . '"'
				. ' data-title="' . esc_attr( $ep['title'] ) . '"'
				. ' data-show="' . esc_attr( $ep['show_name'] ) . '"'
				. ' data-artwork="' . esc_attr( $ep['artwork_url'] ) . '"'
				. ' data-episode-number="' . esc_attr( $ep['episode_number'] ) . '"'
				. ' data-duration="' . esc_attr( $ep['duration'] ) . '"'
				. ' data-description="' . esc_attr( wp_trim_words( $ep['description'], 40, '...' ) ) . '"'
				. '>';

			// Artwork.
			if ( ! empty( $ep['artwork_url'] ) ) {
				$html .= '<div class="acpods-card-artwork">';
				$html .= '<img src="' . esc_url( $ep['artwork_url'] ) . '" alt="' . esc_attr( $ep['title'] ) . '" loading="lazy">';
				$html .= '<div class="acpods-play-overlay"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32"><path d="M8 5v14l11-7z"/></svg></div>';
				$html .= '</div>';
			}

			// Info.
			$html .= '<div class="acpods-card-info">';
			$html .= '<div class="acpods-card-title">' . esc_html( $ep['title'] ) . '</div>';
			$html .= '<div class="acpods-card-meta">';
			if ( ! empty( $ep['show_name'] ) ) {
				$html .= '<span class="acpods-show-name">' . esc_html( $ep['show_name'] ) . '</span>';
			}
			if ( ! empty( $ep['episode_number'] ) ) {
				$html .= '<span class="acpods-ep-num">Ep. ' . esc_html( $ep['episode_number'] ) . '</span>';
			}
			if ( ! empty( $ep['duration'] ) ) {
				$html .= '<span class="acpods-duration">' . esc_html( $ep['duration'] ) . '</span>';
			}
			$html .= '</div>';
			$html .= '</div>';

			$html .= '</div>'; // .swiper-slide
		}

		$html .= '</div>'; // .swiper-wrapper
		$html .= '<div class="swiper-button-prev acpods-nav-prev"></div>';
		$html .= '<div class="swiper-button-next acpods-nav-next"></div>';
		$html .= '</div>'; // .swiper
		$html .= '</div>'; // .acpods-carousel-wrap

		return $html;
	}

	/**
	 * Render the floating player HTML (once per page load).
	 */
	private function render_player_html() {
		if ( self::$player_rendered ) {
			return '';
		}
		self::$player_rendered = true;

		$html  = '<div class="acpods-player" id="acpods-player" style="display:none;">';
		$html .= '<div class="acpods-player-header">';
		$html .= '<span class="acpods-player-title">Now Playing</span>';
		$html .= '<button class="acpods-player-close" aria-label="Close player">&times;</button>';
		$html .= '</div>';
		$html .= '<div class="acpods-player-body">';
		$html .= '<div class="acpods-player-artwork"><img src="" alt=""></div>';
		$html .= '<div class="acpods-player-meta">';
		$html .= '<div class="acpods-player-ep-title"></div>';
		$html .= '<div class="acpods-player-show"></div>';
		$html .= '<div class="acpods-player-details"></div>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '<div class="acpods-player-embed">';
		$html .= '<iframe id="acpods-player-iframe" src="about:blank" allow="autoplay *; encrypted-media *;" frameborder="0" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-top-navigation-by-user-activation" style="width:100%;height:175px;"></iframe>';
		$html .= '</div>';
		$html .= '<div class="acpods-player-desc-toggle">';
		$html .= '<button class="acpods-desc-btn">Show Description</button>';
		$html .= '<div class="acpods-player-desc" style="display:none;"></div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	// =========================================================================
	// Assets
	// =========================================================================

	/**
	 * Register (not enqueue) Swiper and plugin assets.
	 * The shortcode handler enqueues on demand.
	 */
	public function register_assets() {
		// Swiper from CDN.
		wp_register_style( 'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), '11' );
		wp_register_script( 'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), '11', true );

		// Plugin styles.
		wp_register_style( 'acpods-styles', false );
		wp_add_inline_style( 'acpods-styles', $this->get_inline_css() );

		// Plugin scripts.
		wp_register_script( 'acpods-scripts', false, array( 'acpods-swiper' ), ACPODS_VERSION, true );
		wp_add_inline_script( 'acpods-scripts', $this->get_inline_js() );
	}

	/**
	 * Site-agnostic CSS with --acpods-* custom properties.
	 */
	private function get_inline_css() {
		return '
:root {
	--acpods-card-bg: #ffffff;
	--acpods-card-border: #e0e0e0;
	--acpods-card-radius: 12px;
	--acpods-card-shadow: 0 2px 8px rgba(0,0,0,0.08);
	--acpods-card-hover-shadow: 0 4px 16px rgba(0,0,0,0.15);
	--acpods-text-primary: #1a1a1a;
	--acpods-text-secondary: #666666;
	--acpods-text-muted: #999999;
	--acpods-accent: #6366f1;
	--acpods-player-bg: #ffffff;
	--acpods-player-border: #e0e0e0;
	--acpods-player-shadow: 0 -4px 20px rgba(0,0,0,0.15);
	--acpods-overlay-bg: rgba(0,0,0,0.4);
	--acpods-overlay-color: #ffffff;
	--acpods-nav-color: var(--acpods-accent);
}

.acpods-carousel-wrap {
	position: relative;
	padding: 0 40px;
	margin: 24px 0;
}

.acpods-swiper {
	overflow: hidden;
}

.acpods-card {
	background: var(--acpods-card-bg);
	border: 1px solid var(--acpods-card-border);
	border-radius: var(--acpods-card-radius);
	overflow: hidden;
	cursor: pointer;
	transition: box-shadow 0.2s ease, transform 0.2s ease;
	box-shadow: var(--acpods-card-shadow);
}

.acpods-card:hover {
	box-shadow: var(--acpods-card-hover-shadow);
	transform: translateY(-2px);
}

.acpods-card-artwork {
	position: relative;
	width: 100%;
	aspect-ratio: 1;
	overflow: hidden;
}

.acpods-card-artwork img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.acpods-play-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--acpods-overlay-bg);
	color: var(--acpods-overlay-color);
	opacity: 0;
	transition: opacity 0.2s ease;
}

.acpods-card:hover .acpods-play-overlay {
	opacity: 1;
}

.acpods-card-info {
	padding: 12px;
}

.acpods-card-title {
	color: var(--acpods-text-primary);
	font-size: 0.9em;
	font-weight: 600;
	line-height: 1.3;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
	margin-bottom: 6px;
}

.acpods-card-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 0.75em;
	color: var(--acpods-text-muted);
}

.acpods-card-meta span::after {
	content: "\00b7";
	margin-left: 8px;
}
.acpods-card-meta span:last-child::after {
	content: none;
}

/* Navigation arrows */
.acpods-nav-prev,
.acpods-nav-next {
	color: var(--acpods-nav-color) !important;
}
.acpods-nav-prev::after,
.acpods-nav-next::after {
	font-size: 20px !important;
}

/* Floating player */
.acpods-player {
	position: fixed;
	bottom: 20px;
	left: 20px;
	width: 380px;
	max-width: calc(100vw - 40px);
	background: var(--acpods-player-bg);
	border: 1px solid var(--acpods-player-border);
	border-radius: 16px;
	box-shadow: var(--acpods-player-shadow);
	z-index: 99999;
	overflow: hidden;
	animation: acpodsSlideUp 0.3s ease;
}

@keyframes acpodsSlideUp {
	from { transform: translateY(100%); opacity: 0; }
	to   { transform: translateY(0); opacity: 1; }
}

.acpods-player-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--acpods-player-border);
}

.acpods-player-title {
	font-weight: 600;
	font-size: 0.85em;
	color: var(--acpods-text-secondary);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.acpods-player-close {
	background: none;
	border: none;
	font-size: 24px;
	cursor: pointer;
	color: var(--acpods-text-secondary);
	line-height: 1;
	padding: 0;
}

.acpods-player-body {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
}

.acpods-player-artwork {
	flex-shrink: 0;
	width: 60px;
	height: 60px;
	border-radius: 8px;
	overflow: hidden;
}

.acpods-player-artwork img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.acpods-player-meta {
	min-width: 0;
}

.acpods-player-ep-title {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--acpods-text-primary);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.acpods-player-show {
	font-size: 0.8em;
	color: var(--acpods-text-secondary);
	margin-top: 2px;
}

.acpods-player-details {
	font-size: 0.75em;
	color: var(--acpods-text-muted);
	margin-top: 2px;
}

.acpods-player-embed {
	padding: 0 16px;
}

.acpods-player-desc-toggle {
	padding: 8px 16px 12px;
}

.acpods-desc-btn {
	background: none;
	border: none;
	color: var(--acpods-accent);
	cursor: pointer;
	font-size: 0.8em;
	padding: 0;
	text-decoration: underline;
}

.acpods-player-desc {
	font-size: 0.8em;
	color: var(--acpods-text-secondary);
	line-height: 1.5;
	margin-top: 8px;
	max-height: 120px;
	overflow-y: auto;
}

.acpods-empty {
	color: var(--acpods-text-secondary, #666);
	text-align: center;
	padding: 20px;
}

/* Responsive */
@media (max-width: 768px) {
	.acpods-carousel-wrap {
		padding: 0 30px;
	}
	.acpods-player {
		left: 10px;
		bottom: 10px;
		width: calc(100vw - 20px);
		max-width: none;
	}
}
';
	}

	/**
	 * Inline JavaScript for Swiper init and floating player.
	 */
	private function get_inline_js() {
		return '
document.addEventListener("DOMContentLoaded", function() {
	// Init Swiper for each carousel instance.
	document.querySelectorAll(".acpods-swiper").forEach(function(el) {
		new Swiper(el, {
			slidesPerView: 1.2,
			spaceBetween: 16,
			grabCursor: true,
			navigation: {
				prevEl: el.parentElement.querySelector(".acpods-nav-prev"),
				nextEl: el.parentElement.querySelector(".acpods-nav-next"),
			},
			breakpoints: {
				480:  { slidesPerView: 2.2, spaceBetween: 16 },
				768:  { slidesPerView: 3.2, spaceBetween: 20 },
				1024: { slidesPerView: 4.2, spaceBetween: 20 },
				1280: { slidesPerView: 5.2, spaceBetween: 24 },
			}
		});
	});

	// Floating player logic.
	var player   = document.getElementById("acpods-player");
	var iframe   = document.getElementById("acpods-player-iframe");
	if (!player || !iframe) return;

	var pTitle   = player.querySelector(".acpods-player-ep-title");
	var pShow    = player.querySelector(".acpods-player-show");
	var pDetails = player.querySelector(".acpods-player-details");
	var pArt     = player.querySelector(".acpods-player-artwork img");
	var pDesc    = player.querySelector(".acpods-player-desc");
	var descBtn  = player.querySelector(".acpods-desc-btn");
	var closeBtn = player.querySelector(".acpods-player-close");

	// Card click -> open player.
	document.addEventListener("click", function(e) {
		var card = e.target.closest(".acpods-card");
		if (!card) return;

		var embedSrc = card.dataset.embedSrc;
		if (!embedSrc) return;

		pTitle.textContent   = card.dataset.title || "";
		pShow.textContent    = card.dataset.show || "";
		pArt.src             = card.dataset.artwork || "";
		pArt.alt             = card.dataset.title || "";

		var details = [];
		if (card.dataset.episodeNumber) details.push("Ep. " + card.dataset.episodeNumber);
		if (card.dataset.duration) details.push(card.dataset.duration);
		pDetails.textContent = details.join(" \u00b7 ");

		pDesc.textContent    = card.dataset.description || "";
		pDesc.style.display  = "none";
		descBtn.textContent  = "Show Description";

		iframe.src = embedSrc;
		player.style.display = "block";
	});

	// Close button.
	closeBtn.addEventListener("click", function() {
		player.style.display = "none";
		iframe.src = "about:blank";
	});

	// Description toggle.
	descBtn.addEventListener("click", function() {
		var showing = pDesc.style.display !== "none";
		pDesc.style.display = showing ? "none" : "block";
		descBtn.textContent = showing ? "Show Description" : "Hide Description";
	});
});
';
	}

	// =========================================================================
	// Admin Page
	// =========================================================================

	/**
	 * Render the admin documentation + episode list page.
	 */
	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1>amplifi.pods</h1>
			<p>Podcast carousel and floating player. Display episodes from an Apple Podcasts RSS feed or the built-in custom post type.</p>

			<h2>Shortcode Reference</h2>
			<table class="widefat fixed" style="max-width:800px;">
				<thead>
					<tr><th>Attribute</th><th>Description</th><th>Default</th></tr>
				</thead>
				<tbody>
					<tr>
						<td><code>feed</code></td>
						<td>Apple Podcasts RSS feed URL. When set, the plugin fetches episodes from the feed instead of the CPT.</td>
						<td><em>(empty &mdash; uses CPT)</em></td>
					</tr>
					<tr>
						<td><code>category</code></td>
						<td>Filter CPT episodes by <code>acpods_category</code> taxonomy slug. Only applies in CPT mode.</td>
						<td><em>(all categories)</em></td>
					</tr>
					<tr>
						<td><code>count</code></td>
						<td>Maximum number of episodes to display.</td>
						<td><code>12</code></td>
					</tr>
				</tbody>
			</table>

			<h3>Examples</h3>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px;max-width:800px;"><code>[amplifi-pods feed="https://podcasts.apple.com/podcast/id1200361736" count="8"]
[amplifi-pods]
[amplifi-pods category="tech" count="6"]</code></pre>

			<hr>

			<h2>RSS Feed Mode</h2>
			<p>To use RSS mode, find the podcast's RSS feed URL:</p>
			<ol>
				<li>Visit the podcast on <a href="https://podcasts.apple.com" target="_blank">Apple Podcasts</a></li>
				<li>The RSS feed URL is typically provided by the podcast host (Buzzsprout, Libsyn, Anchor, etc.)</li>
				<li>Pass it as the <code>feed</code> attribute. The plugin caches the feed for 1 hour.</li>
			</ol>
			<p>The plugin extracts Apple show/episode IDs from the feed to build embed player URLs. If the feed links contain Apple Podcasts URLs (e.g., <code>podcasts.apple.com/...idXXXX?i=YYYY</code>), the floating player will embed the Apple Podcasts player.</p>

			<hr>

			<h2>Custom Post Type Mode</h2>
			<p>Create episodes under <strong>Podcast Episodes</strong> in the sidebar. Each episode has:</p>
			<ul>
				<li><strong>Title</strong> &mdash; Episode title</li>
				<li><strong>Content</strong> &mdash; Episode description (shown in the floating player)</li>
				<li><strong>Featured Image</strong> &mdash; Used as artwork if no Artwork URL is set in the meta box</li>
				<li><strong>Episode Details</strong> meta box &mdash; Show Name, Apple Show ID, Apple Episode ID, Artwork URL, Episode Number, Duration</li>
			</ul>
			<p>Use <strong>Episode Categories</strong> to group episodes and filter them with the <code>category</code> shortcode attribute.</p>

			<hr>

			<h2>Styling</h2>
			<p>Override the default appearance by setting CSS custom properties in your theme:</p>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px;max-width:800px;"><code>:root {
  --acpods-card-bg: #1a1a1a;
  --acpods-card-border: #333;
  --acpods-text-primary: #fff;
  --acpods-text-secondary: #aaa;
  --acpods-text-muted: #777;
  --acpods-accent: #ff6b6b;
  --acpods-player-bg: #1a1a1a;
  --acpods-player-border: #333;
}</code></pre>
			<p>No font families are set &mdash; the plugin inherits from your theme. No Bootstrap or other framework required.</p>

			<hr>

			<h2>Published Episodes</h2>
			<?php
			$episodes = get_posts( array(
				'post_type'      => 'amplifi-podcasts',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );

			if ( ! empty( $episodes ) ) {
				echo '<table class="widefat fixed striped">';
				echo '<thead><tr><th>Title</th><th>Show Name</th><th>Ep #</th><th>Duration</th><th>Apple Show ID</th><th>Apple Episode ID</th><th>Date</th></tr></thead>';
				echo '<tbody>';
				foreach ( $episodes as $ep ) {
					$edit_link = get_edit_post_link( $ep->ID );
					echo '<tr>';
					echo '<td><a href="' . esc_url( $edit_link ) . '">' . esc_html( $ep->post_title ) . '</a></td>';
					echo '<td>' . esc_html( get_post_meta( $ep->ID, '_acpods_show_name', true ) ) . '</td>';
					echo '<td>' . esc_html( get_post_meta( $ep->ID, '_acpods_episode_number', true ) ) . '</td>';
					echo '<td>' . esc_html( get_post_meta( $ep->ID, '_acpods_duration', true ) ) . '</td>';
					echo '<td>' . esc_html( get_post_meta( $ep->ID, '_acpods_apple_show_id', true ) ) . '</td>';
					echo '<td>' . esc_html( get_post_meta( $ep->ID, '_acpods_apple_episode_id', true ) ) . '</td>';
					echo '<td>' . esc_html( get_the_date( '', $ep ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p><em>No episodes published yet.</em> <a href="' . esc_url( admin_url( 'post-new.php?post_type=amplifi-podcasts' ) ) . '">Create your first episode</a></p>';
			}
			?>
		</div>
		<?php
	}
}

new Amplifi_Pods();
