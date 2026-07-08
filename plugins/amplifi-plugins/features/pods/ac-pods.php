<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACPODS_VERSION' ) ) {
	return;
}
define( 'ACPODS_VERSION', '3.1.11' );
define( 'ACPODS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPODS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPODS_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACPODS_PLUGIN_DIR . 'includes/amplifi-framework.php';

class Amplifi_Pods {

	private static $player_rendered = false;
	private static $instance_count  = 0;

	public function __construct() {
		amplifi_register_plugin(
			'ac-pods',
			'Pods',
			'Podcast carousel and floating player via shortcode — mirrors the Resources page podcast player.',
			ACPODS_VERSION,
			ACPODS_PLUGIN_FILE,
			array( $this, 'render_admin_page' )
		);

		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_episode_meta_box' ) );
		add_action( 'save_post_podcast', array( $this, 'save_episode_meta' ) );
		add_shortcode( 'amplifi-pods', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	// =========================================================================
	// CPT + Taxonomy
	// =========================================================================

	public function register_cpt() {
		if ( post_type_exists( 'podcast' ) ) {
			return;
		}
		register_post_type( 'podcast', array(
			'labels' => array(
				'name'               => 'Podcasts',
				'singular_name'      => 'Podcast',
				'add_new'            => 'Add New Podcast',
				'add_new_item'       => 'Add New Podcast',
				'edit_item'          => 'Edit Podcast',
				'new_item'           => 'New Podcast',
				'view_item'          => 'View Podcast',
				'search_items'       => 'Search Podcasts',
				'not_found'          => 'No podcasts found',
				'not_found_in_trash' => 'No podcasts found in Trash',
				'all_items'          => 'All Podcasts',
				'menu_name'          => 'Podcasts',
			),
			'public'             => true,
			'has_archive'        => true,
			'rewrite'            => array( 'slug' => 'podcasts' ),
			'menu_icon'          => 'dashicons-microphone',
			'menu_position'      => 21,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'show_in_rest'       => true,
			'show_in_nav_menus'  => true,
		) );
	}

	public function register_taxonomy() {
		if ( taxonomy_exists( 'acpods_category' ) ) {
			return;
		}
		register_taxonomy( 'acpods_category', 'podcast', array(
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
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'rewrite'           => false,
		) );
	}

	// =========================================================================
	// ACF Fields (registered if ACF is available)
	// =========================================================================

	public static function register_acf_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group( array(
			'key'      => 'group_acpods_episode',
			'title'    => 'Podcast Episode Details',
			'fields'   => array(
				array(
					'key'          => 'field_acpods_show_name',
					'label'        => 'Show Name',
					'name'         => 'podcast_show_name',
					'type'         => 'text',
					'instructions' => 'e.g. "Planet Money", "Up First"',
					'required'     => 1,
				),
				array(
					'key'          => 'field_acpods_apple_show_id',
					'label'        => 'Apple Podcasts Show ID',
					'name'         => 'podcast_apple_show_id',
					'type'         => 'text',
					'instructions' => 'Numeric ID from the Apple Podcasts URL, e.g. 290783428',
					'required'     => 1,
				),
				array(
					'key'          => 'field_acpods_apple_episode_id',
					'label'        => 'Apple Podcasts Episode ID',
					'name'         => 'podcast_apple_episode_id',
					'type'         => 'text',
					'instructions' => 'The ?i= parameter from the episode URL, e.g. 1000743335282',
					'required'     => 1,
				),
				array(
					'key'          => 'field_acpods_artwork_url',
					'label'        => 'Artwork URL',
					'name'         => 'podcast_artwork_url',
					'type'         => 'url',
					'instructions' => 'Apple Podcasts artwork image URL (mzstatic.com)',
					'required'     => 1,
				),
				array(
					'key'          => 'field_acpods_episode_number',
					'label'        => 'Episode Label',
					'name'         => 'podcast_episode_number',
					'type'         => 'text',
					'instructions' => 'e.g. "Episode 42" or "Feb 13, 2026"',
				),
				array(
					'key'          => 'field_acpods_duration',
					'label'        => 'Duration',
					'name'         => 'podcast_duration',
					'type'         => 'text',
					'instructions' => 'e.g. "45 min", "12 min"',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'podcast',
					),
				),
			),
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'active'                => true,
		) );
	}

	// =========================================================================
	// Meta Box (fallback when ACF is not installed)
	// =========================================================================

	public function add_episode_meta_box() {
		if ( function_exists( 'acf_add_local_field_group' ) ) {
			return; // ACF handles the fields
		}
		add_meta_box(
			'acpods_episode_details',
			'Episode Details',
			array( $this, 'render_episode_meta_box' ),
			'podcast',
			'normal',
			'high'
		);
	}

	public function render_episode_meta_box( $post ) {
		wp_nonce_field( 'acpods_save_meta', 'acpods_meta_nonce' );

		$fields = array(
			'podcast_show_name'        => array( 'label' => 'Show Name',          'placeholder' => 'e.g. Planet Money' ),
			'podcast_apple_show_id'    => array( 'label' => 'Apple Show ID',      'placeholder' => 'e.g. 290783428' ),
			'podcast_apple_episode_id' => array( 'label' => 'Apple Episode ID',   'placeholder' => 'e.g. 1000743335282' ),
			'podcast_artwork_url'      => array( 'label' => 'Artwork URL',        'placeholder' => 'https://...' ),
			'podcast_episode_number'   => array( 'label' => 'Episode Label',      'placeholder' => 'e.g. Episode 42' ),
			'podcast_duration'         => array( 'label' => 'Duration',           'placeholder' => 'e.g. 45 min' ),
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
	}

	public function save_episode_meta( $post_id ) {
		if ( function_exists( 'acf_add_local_field_group' ) ) {
			return; // ACF handles saving
		}
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
			'podcast_show_name',
			'podcast_apple_show_id',
			'podcast_apple_episode_id',
			'podcast_artwork_url',
			'podcast_episode_number',
			'podcast_duration',
		);

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
			}
		}
	}

	// =========================================================================
	// Episode Data Helpers
	// =========================================================================

	private function get_field_value( $field_name, $post_id = false ) {
		if ( function_exists( 'get_field' ) ) {
			return get_field( $field_name, $post_id );
		}
		return get_post_meta( $post_id, $field_name, true );
	}

	private function query_cpt_episodes( $count ) {
		$args = array(
			'post_type'      => 'podcast',
			'posts_per_page' => $count,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query    = new WP_Query( $args );
		$episodes = array();

		foreach ( $query->posts as $post ) {
			$artwork = $this->get_field_value( 'podcast_artwork_url', $post->ID );
			if ( empty( $artwork ) && has_post_thumbnail( $post->ID ) ) {
				$artwork = get_the_post_thumbnail_url( $post->ID, 'medium' );
			}

			$apple_show_id = $this->get_field_value( 'podcast_apple_show_id', $post->ID );
			$show_link     = '';
			if ( ! empty( $apple_show_id ) ) {
				$show_link = 'https://podcasts.apple.com/us/podcast/id' . urlencode( $apple_show_id );
			}

			$episodes[] = array(
				'source'             => 'apple',
				'title'              => $post->post_title,
				'description'        => get_the_excerpt( $post ),
				'show_name'          => $this->get_field_value( 'podcast_show_name', $post->ID ),
				'artwork_url'        => $artwork,
				'duration'           => $this->get_field_value( 'podcast_duration', $post->ID ),
				'episode_num'        => $this->get_field_value( 'podcast_episode_number', $post->ID ),
				'apple_show_id'      => $apple_show_id,
				'apple_episode_id'   => $this->get_field_value( 'podcast_apple_episode_id', $post->ID ),
				'release_date'       => get_the_date( 'Y-m-d', $post ),
				'spotify_episode_id' => '',
				'playlist_id'        => 'apple',
				'show_link'          => $show_link,
			);
		}

		wp_reset_postdata();
		return $episodes;
	}

	private function get_spotify_episodes() {
		if ( ! function_exists( 'nwr_spotify_get_all_episodes' ) ) {
			return array();
		}

		$episodes = array();
		$spotify_episodes = nwr_spotify_get_all_episodes();

		foreach ( $spotify_episodes as $sep ) {
			$show_link = '';
			if ( ! empty( $sep['episode_id'] ) ) {
				$show_link = 'https://open.spotify.com/episode/' . urlencode( $sep['episode_id'] );
			}

			$episodes[] = array(
				'source'             => 'spotify',
				'title'              => $sep['title'],
				'description'        => $sep['description'],
				'show_name'          => $sep['show_name'],
				'artwork_url'        => $sep['artwork_url'],
				'duration'           => $sep['duration'],
				'episode_num'        => '',
				'apple_show_id'      => '',
				'apple_episode_id'   => '',
				'release_date'       => $sep['release_date'],
				'spotify_episode_id' => $sep['episode_id'],
				'playlist_id'        => isset( $sep['playlist_id'] ) ? $sep['playlist_id'] : '',
				'show_link'          => $show_link,
			);
		}

		return $episodes;
	}

	// =========================================================================
	// Shortcode
	// =========================================================================

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'count'          => -1,
			'show_filters'   => 'true',
			'show_header'    => 'true',
			'heading'        => 'Podcasts',
			'subheading'     => 'Featured Podcasts',
			'description'    => 'Listen to conversations with industry leaders, entrepreneurs, and innovators shaping the future of technology and business.',
			'accent_color'   => '#055c5f',
		), $atts, 'amplifi-pods' );

		$count         = intval( $atts['count'] );
		$show_filters  = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
		$show_header   = filter_var( $atts['show_header'], FILTER_VALIDATE_BOOLEAN );
		$accent_color  = sanitize_hex_color( $atts['accent_color'] ) ?: '#055c5f';

		// Merge Apple CPT + Spotify episodes
		$merged = $this->query_cpt_episodes( $count );
		$spotify = $this->get_spotify_episodes();
		$merged = array_merge( $merged, $spotify );

		// Sort by release date DESC
		usort( $merged, function( $a, $b ) {
			return strcmp( $b['release_date'], $a['release_date'] );
		} );

		if ( $count > 0 ) {
			$merged = array_slice( $merged, 0, $count );
		}

		if ( empty( $merged ) ) {
			return '<p style="color: rgba(49,62,92,0.5); text-align: center; padding: 20px;">No podcast episodes found.</p>';
		}

		// Enqueue assets
		wp_enqueue_style( 'acpods-swiper' );
		wp_enqueue_script( 'acpods-swiper' );
		wp_enqueue_style( 'acpods-styles' );
		wp_enqueue_script( 'acpods-scripts' );

		self::$instance_count++;
		$instance_id = 'acpods-instance-' . self::$instance_count;

		// Spotify playlist links for filter pills
		$playlist_links = get_transient( 'nwr_spotify_playlist_links' );
		if ( ! is_array( $playlist_links ) ) {
			$playlist_links = array();
		}

		$html = '<div class="acpods-wrap" id="' . esc_attr( $instance_id ) . '" style="--acpods-accent: ' . esc_attr( $accent_color ) . ';">';

		// Header
		if ( $show_header ) {
			$html .= '<div class="acpods-header">';
			if ( ! empty( $atts['subheading'] ) ) {
				$html .= '<div class="acpods-subheading">' . esc_html( $atts['subheading'] ) . '</div>';
			}
			if ( ! empty( $atts['heading'] ) ) {
				$html .= '<h2 class="acpods-heading">' . esc_html( $atts['heading'] ) . '</h2>';
			}
			if ( ! empty( $atts['description'] ) ) {
				$html .= '<p class="acpods-description">' . esc_html( $atts['description'] ) . '</p>';
			}
			$html .= '</div>';
		}

		// Filter pills
		if ( $show_filters && ( ! empty( $playlist_links ) || count( $merged ) > 0 ) ) {
			$html .= '<div class="acpods-filters">';
			$html .= '<button type="button" class="acpods-filter-pill active" data-filter="all">All</button>';
			foreach ( $playlist_links as $pl ) {
				$html .= '<span class="acpods-filter-group">';
				$html .= '<button type="button" class="acpods-filter-pill" data-filter="' . esc_attr( $pl['id'] ) . '">';
				$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#1DB954"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>';
				$html .= esc_html( $pl['name'] );
				$html .= '</button>';
				if ( ! empty( $pl['url'] ) ) {
					$html .= '<a href="' . esc_url( $pl['url'] ) . '" target="_blank" rel="noopener noreferrer" class="acpods-external-link" title="Open on Spotify">';
					$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
					$html .= '</a>';
				}
				$html .= '</span>';
			}
			$html .= '</div>';
		}

		// Swiper carousel
		$html .= '<div class="acpods-swiper-container">';
		$html .= '<div class="swiper acpods-swiper">';
		$html .= '<div class="swiper-wrapper">';

		foreach ( $merged as $ep ) {
			$source_icon_svg = $ep['source'] === 'spotify'
				? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="#1DB954"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="#000"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>';

			$show_icon_svg = $ep['source'] === 'spotify'
				? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#1DB954"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#000"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>';

			$html .= '<div class="swiper-slide">';
			$html .= '<div class="acpods-card"'
				. ' data-source="' . esc_attr( $ep['source'] ) . '"'
				. ' data-show-id="' . esc_attr( $ep['apple_show_id'] ) . '"'
				. ' data-episode-id="' . esc_attr( $ep['apple_episode_id'] ) . '"'
				. ' data-spotify-episode-id="' . esc_attr( $ep['spotify_episode_id'] ) . '"'
				. ' data-show-name="' . esc_attr( $ep['show_name'] ) . '"'
				. ' data-episode-title="' . esc_attr( $ep['title'] ) . '"'
				. ' data-episode-desc="' . esc_attr( $ep['description'] ) . '"'
				. ' data-show-link="' . esc_attr( $ep['show_link'] ) . '"'
				. ' data-playlist-id="' . esc_attr( $ep['playlist_id'] ) . '"'
				. '>';

			// Artwork with play overlay
			$html .= '<div class="acpods-card-artwork">';
			if ( ! empty( $ep['artwork_url'] ) ) {
				$html .= '<img src="' . esc_url( $ep['artwork_url'] ) . '" alt="' . esc_attr( $ep['title'] ) . '" loading="lazy">';
			}
			$html .= '<div class="acpods-play-btn"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>';
			// Source badge
			$html .= '<div class="acpods-source-badge">' . $source_icon_svg . '</div>';
			$html .= '</div>';

			// Show name bar
			$html .= '<div class="acpods-show-bar">';
			if ( ! empty( $ep['show_name'] ) ) {
				if ( ! empty( $ep['show_link'] ) ) {
					$html .= '<a href="' . esc_url( $ep['show_link'] ) . '" target="_blank" rel="noopener noreferrer" class="acpods-show-link">';
				} else {
					$html .= '<span class="acpods-show-link">';
				}
				$html .= $show_icon_svg . ' ';
				$html .= esc_html( $ep['show_name'] );
				$html .= ! empty( $ep['show_link'] ) ? '</a>' : '</span>';
			}
			$html .= '</div>';

			// Meta line
			$html .= '<div class="acpods-card-meta">';
			if ( ! empty( $ep['release_date'] ) ) {
				$ts = strtotime( $ep['release_date'] );
				if ( $ts ) {
					$html .= '<span>' . esc_html( date( 'M j, Y', $ts ) ) . '</span>';
				}
			}
			if ( ! empty( $ep['episode_num'] ) ) {
				$html .= '<span>&bull;</span><span>' . esc_html( $ep['episode_num'] ) . '</span>';
			}
			if ( ! empty( $ep['duration'] ) ) {
				$html .= '<span>&bull;</span><span>' . esc_html( $ep['duration'] ) . '</span>';
			}
			$html .= '</div>';

			// Title + description
			$html .= '<h4 class="acpods-card-title">' . esc_html( $ep['title'] ) . '</h4>';
			$html .= '<p class="acpods-card-desc">' . esc_html( $ep['description'] ) . '</p>';

			// Listen CTA
			$html .= '<div class="acpods-card-cta">Listen Now <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>';

			$html .= '</div>'; // .acpods-card
			$html .= '</div>'; // .swiper-slide
		}

		$html .= '</div>'; // .swiper-wrapper
		$html .= '</div>'; // .swiper
		$html .= '<div class="acpods-nav"><div class="swiper-button-prev acpods-nav-prev"></div><div class="swiper-button-next acpods-nav-next"></div></div>';
		$html .= '</div>'; // .acpods-swiper-container

		// Floating player (once per page)
		$html .= $this->render_player_html();

		$html .= '</div>'; // .acpods-wrap

		return $html;
	}

	// =========================================================================
	// Floating Player
	// =========================================================================

	private function render_player_html() {
		if ( self::$player_rendered ) {
			return '';
		}
		self::$player_rendered = true;

		$html  = '<div class="acpods-player" id="acpods-player">';
		$html .= '<div class="acpods-player-header">';
		$html .= '<div class="acpods-player-header-info">';
		$html .= '<span class="acpods-player-show-name"></span>';
		$html .= '<span class="acpods-player-ep-title"></span>';
		$html .= '</div>';
		$html .= '<button class="acpods-player-close" aria-label="Close player">';
		$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		$html .= '</button>';
		$html .= '</div>';
		$html .= '<div class="acpods-player-body">';
		$html .= '<div class="acpods-player-loader"><div class="acpods-spinner"></div></div>';
		$html .= '<iframe id="acpods-player-iframe" allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write" src=""></iframe>';
		$html .= '</div>';
		$html .= '<button class="acpods-player-desc-toggle" aria-expanded="false">';
		$html .= '<span>Episode Details</span>';
		$html .= '<svg class="acpods-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
		$html .= '</button>';
		$html .= '<div class="acpods-player-desc-panel">';
		$html .= '<div class="acpods-player-desc-text"></div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public function register_assets() {
		wp_register_style( 'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), '11' );
		wp_register_script( 'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), '11', true );

		wp_register_style( 'acpods-styles', false );
		wp_add_inline_style( 'acpods-styles', $this->get_inline_css() );

		wp_register_script( 'acpods-scripts', false, array( 'acpods-swiper' ), ACPODS_VERSION, true );
		wp_add_inline_script( 'acpods-scripts', $this->get_inline_js() );
	}

	private function get_inline_css() {
		return '
/* amplifi.pods v2 — Podcast carousel + floating player */
.acpods-wrap {
	--acpods-accent: #055c5f;
}

.acpods-header {
	margin-bottom: 2rem;
}

.acpods-subheading {
	text-transform: uppercase;
	letter-spacing: 3px;
	font-weight: 600;
	color: #000;
	margin-bottom: 0.5rem;
	font-size: 0.85rem;
}

.acpods-heading {
	color: #000;
	margin-bottom: 1rem;
}

.acpods-description {
	max-width: 36rem;
	color: #000;
	line-height: 1.75;
	font-size: 1.1rem;
}

/* Filter pills */
.acpods-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	margin-bottom: 1.5rem;
}

.acpods-filter-group {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.acpods-filter-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: transparent;
	color: var(--acpods-accent);
	font-size: 0.8rem;
	padding: 6px 16px;
	border-radius: 20px;
	font-weight: 500;
	border: 2px solid var(--acpods-accent);
	cursor: pointer;
	transition: all 0.2s;
}

.acpods-filter-pill.active {
	background: var(--acpods-accent);
	color: #fff;
}

.acpods-filter-pill:hover {
	opacity: 0.85;
}

.acpods-external-link {
	color: var(--acpods-accent);
	opacity: 0.5;
	transition: opacity 0.2s;
}

.acpods-external-link:hover {
	opacity: 1;
}

/* Swiper container */
.acpods-swiper-container {
	position: relative;
}

.acpods-swiper {
	padding: 0;
	overflow: hidden;
	width: 100%;
}

.acpods-swiper .swiper-wrapper {
	display: flex;
	align-items: stretch;
	width: 100%;
}

.acpods-swiper .swiper-slide {
	height: auto;
	display: flex;
	align-items: stretch;
	box-sizing: border-box;
	flex-shrink: 0;
}

/* Card */
.acpods-card {
	width: 100%;
	background: #fff;
	border: 1px solid rgba(5,92,95,0.1);
	padding: 1rem;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	cursor: pointer;
	box-sizing: border-box;
	min-height: 100%;
	transition: box-shadow 0.2s ease;
}

.acpods-card:hover {
	box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.acpods-card-artwork {
	position: relative;
	width: 100%;
	aspect-ratio: 1;
	background-color: rgba(7,72,91,0.05);
	margin-bottom: 0.75rem;
	overflow: hidden;
}

.acpods-card-artwork img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.acpods-play-btn {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	width: 80px;
	height: 80px;
	background-color: rgba(255,255,255,0.9);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 2;
	transition: all 0.3s ease;
	cursor: pointer;
	color: var(--acpods-accent);
}

.acpods-play-btn svg {
	margin-left: 4px;
}

.acpods-card:hover .acpods-play-btn {
	background-color: rgba(255,255,255,1);
	transform: translate(-50%, -50%) scale(1.1);
}

.acpods-source-badge {
	position: absolute;
	top: 8px;
	right: 8px;
	z-index: 3;
	background: rgba(255,255,255,0.92);
	border-radius: 50%;
	width: 28px;
	height: 28px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.acpods-show-bar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 0.75rem;
	margin-bottom: 0.75rem;
	padding-top: 0.75rem;
	letter-spacing: 0.05em;
	color: var(--acpods-accent);
	font-size: 0.8rem;
	font-weight: 600;
	border-top: 1px solid rgba(5,92,95,0.1);
}

.acpods-show-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--acpods-accent);
	text-decoration: underline;
	text-decoration-style: dotted;
	text-underline-offset: 3px;
}

a.acpods-show-link:hover {
	text-decoration-style: solid;
}

.acpods-card-meta {
	display: flex;
	align-items: center;
	gap: 6px;
	text-transform: uppercase;
	margin-bottom: 0.5rem;
	letter-spacing: 0.1em;
	color: rgba(49,62,92,0.6);
	font-size: 0.7rem;
	font-weight: 500;
}

.acpods-card-title {
	color: var(--acpods-accent);
	line-height: 1.25;
	margin-bottom: 0.5rem;
	font-size: 1.1rem;
}

.acpods-card-desc {
	color: rgba(49,62,92,0.7);
	font-size: 0.875rem;
	line-height: 1.5;
	margin-bottom: 0;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.acpods-card-cta {
	margin-top: 1rem;
	display: flex;
	align-items: center;
	text-transform: uppercase;
	color: var(--acpods-accent);
	letter-spacing: 0.1em;
	font-size: 0.85rem;
	font-weight: 500;
}

.acpods-card-cta svg {
	margin-left: 4px;
}

/* Navigation */
.acpods-nav {
	display: flex;
	justify-content: center;
	align-items: center;
	margin-top: 2rem;
	gap: 1rem;
}

.acpods-nav-prev,
.acpods-nav-next {
	color: #000 !important;
	width: 40px !important;
	height: 40px !important;
	background: transparent !important;
	border: none !important;
	position: static !important;
	margin: 0 8px !important;
	top: auto !important;
	left: auto !important;
	right: auto !important;
	bottom: auto !important;
	transform: none !important;
}

.acpods-nav-prev::after,
.acpods-nav-next::after {
	font-size: 18px !important;
	font-weight: bold;
}

.acpods-nav-prev:hover,
.acpods-nav-next:hover {
	opacity: 1;
}

/* Hidden slides */
.acpods-swiper .swiper-slide-hidden {
	display: none !important;
	width: 0 !important;
	margin: 0 !important;
	padding: 0 !important;
	overflow: hidden;
}

/* ── Floating Player ── */
.acpods-player {
	position: fixed;
	bottom: 24px;
	left: 24px;
	z-index: 99999;
	width: 520px;
	max-width: calc(100vw - 48px);
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 8px 32px rgba(0,0,0,0.18);
	overflow: hidden;
	transform: translateY(120%);
	opacity: 0;
	transition: transform 0.35s cubic-bezier(0.4,0,0.2,1), opacity 0.35s ease;
	pointer-events: none;
}

.acpods-player.is-visible {
	transform: translateY(0);
	opacity: 1;
	pointer-events: auto;
}

.acpods-player-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: var(--acpods-accent, #055c5f);
	color: #fff;
}

.acpods-player-header-info {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
	margin-right: 8px;
}

.acpods-player-show-name {
	font-size: 0.65rem;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	opacity: 0.8;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.acpods-player-ep-title {
	font-size: 0.8rem;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.acpods-player-close {
	background: none;
	border: none;
	color: #fff;
	cursor: pointer;
	padding: 4px;
	line-height: 1;
	opacity: 0.8;
	transition: opacity 0.2s;
	flex-shrink: 0;
}

.acpods-player-close:hover {
	opacity: 1;
}

.acpods-player-body {
	height: 175px;
	position: relative;
	background: #000;
}

.acpods-player-loader {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #f8f8f8;
	z-index: 2;
	transition: opacity 0.3s ease;
}

.acpods-player-loader.is-hidden {
	opacity: 0;
	pointer-events: none;
}

.acpods-spinner {
	width: 28px;
	height: 28px;
	border: 3px solid rgba(5,92,95,0.2);
	border-top-color: var(--acpods-accent, #055c5f);
	border-radius: 50%;
	animation: acpods-spin 0.8s linear infinite;
}

@keyframes acpods-spin {
	to { transform: rotate(360deg); }
}

.acpods-player-body iframe {
	width: 100%;
	height: 100%;
	border: 0;
}

.acpods-player-desc-toggle {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	width: 100%;
	padding: 8px 16px;
	border: none;
	border-top: 1px solid rgba(0,0,0,0.08);
	background: #fff;
	color: var(--acpods-accent, #055c5f);
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	cursor: pointer;
	transition: background 0.2s;
}

.acpods-player-desc-toggle:hover {
	background: rgba(0,0,0,0.03);
}

.acpods-chevron {
	transition: transform 0.25s ease;
}

.acpods-player-desc-toggle.is-open .acpods-chevron {
	transform: rotate(180deg);
}

.acpods-player-desc-panel {
	max-height: 0;
	overflow: hidden;
	transition: max-height 0.3s ease;
}

.acpods-player-desc-panel.is-open {
	max-height: 200px;
}

.acpods-player-desc-text {
	padding: 12px 16px;
	font-size: 0.8rem;
	line-height: 1.5;
	color: rgba(0,0,0,0.65);
	border-top: 1px solid rgba(0,0,0,0.08);
}

@media (max-width: 768px) {
	.acpods-nav-prev,
	.acpods-nav-next {
		width: 32px !important;
		height: 32px !important;
	}
	.acpods-nav-prev::after,
	.acpods-nav-next::after {
		font-size: 18px !important;
	}
}

@media (max-width: 480px) {
	.acpods-player {
		left: 12px;
		right: 12px;
		bottom: 12px;
		width: auto;
		max-width: none;
	}
}
';
	}

	private function get_inline_js() {
		return '
document.addEventListener("DOMContentLoaded", function() {
	// Init Swiper for each acpods instance
	document.querySelectorAll(".acpods-swiper").forEach(function(el) {
		var wrap = el.closest(".acpods-swiper-container");
		var swiperInstance = new Swiper(el, {
			slidesPerView: 1,
			spaceBetween: 12,
			autoHeight: false,
			navigation: {
				nextEl: wrap ? wrap.querySelector(".acpods-nav-next") : null,
				prevEl: wrap ? wrap.querySelector(".acpods-nav-prev") : null,
			},
			breakpoints: {
				768:  { slidesPerView: 2, spaceBetween: 12 },
				992:  { slidesPerView: 4, spaceBetween: 12 },
			},
			on: {
				init: function() {
					var slides = this.slides;
					var maxH = 0;
					slides.forEach(function(s) { if (s.offsetHeight > maxH) maxH = s.offsetHeight; });
					slides.forEach(function(s) { s.style.height = maxH + "px"; });
				},
				resize: function() {
					var slides = this.slides;
					var maxH = 0;
					slides.forEach(function(s) { s.style.height = "auto"; if (s.offsetHeight > maxH) maxH = s.offsetHeight; });
					slides.forEach(function(s) { s.style.height = maxH + "px"; });
				}
			}
		});

		// Store swiper reference for filters
		el._acpodsSwiper = swiperInstance;
	});

	// Filter pills
	document.querySelectorAll(".acpods-filters").forEach(function(filterWrap) {
		var instance = filterWrap.closest(".acpods-wrap");
		if (!instance) return;
		var swiperEl = instance.querySelector(".acpods-swiper");
		if (!swiperEl || !swiperEl._acpodsSwiper) return;
		var swiper = swiperEl._acpodsSwiper;
		var pills = filterWrap.querySelectorAll(".acpods-filter-pill");

		pills.forEach(function(pill) {
			pill.addEventListener("click", function() {
				var filterVal = this.getAttribute("data-filter");

				pills.forEach(function(p) {
					p.style.background = "transparent";
					p.style.color = "var(--acpods-accent, #055c5f)";
					p.classList.remove("active");
				});
				this.style.background = "var(--acpods-accent, #055c5f)";
				this.style.color = "#fff";
				this.classList.add("active");

				swiper.slides.forEach(function(slide) {
					var card = slide.querySelector(".acpods-card");
					if (!card) return;
					var plId = card.getAttribute("data-playlist-id") || "";
					if (filterVal === "all" || plId === filterVal) {
						slide.style.display = "";
						slide.classList.remove("swiper-slide-hidden");
					} else {
						slide.style.display = "none";
						slide.classList.add("swiper-slide-hidden");
					}
				});

				swiper.update();
				swiper.slideTo(0, 0);
			});
		});
	});

	// Floating player
	var player = document.getElementById("acpods-player");
	if (!player) return;

	var iframe     = player.querySelector("iframe");
	var loader     = player.querySelector(".acpods-player-loader");
	var showName   = player.querySelector(".acpods-player-show-name");
	var epTitle    = player.querySelector(".acpods-player-ep-title");
	var closeBtn   = player.querySelector(".acpods-player-close");
	var descToggle = player.querySelector(".acpods-player-desc-toggle");
	var descPanel  = player.querySelector(".acpods-player-desc-panel");
	var descText   = player.querySelector(".acpods-player-desc-text");
	var fpBody     = player.querySelector(".acpods-player-body");

	iframe.addEventListener("load", function() {
		loader.classList.add("is-hidden");
	});

	descToggle.addEventListener("click", function() {
		var isOpen = descPanel.classList.toggle("is-open");
		descToggle.classList.toggle("is-open", isOpen);
		descToggle.setAttribute("aria-expanded", isOpen);
	});

	document.addEventListener("click", function(e) {
		var card = e.target.closest(".acpods-card");
		if (!card) return;

		// Let show name links navigate normally
		if (e.target.closest(".acpods-show-link")) return;
		e.preventDefault();

		var source = card.getAttribute("data-source") || "apple";
		var sName  = card.getAttribute("data-show-name") || "";
		var eTitle = card.getAttribute("data-episode-title") || "";
		var eDesc  = card.getAttribute("data-episode-desc") || "";
		var embedURL = "";

		if (source === "spotify") {
			var spotifyId = card.getAttribute("data-spotify-episode-id") || "";
			embedURL = "https://open.spotify.com/embed/episode/" + spotifyId + "?utm_source=generator&theme=0";
			fpBody.style.height = "232px";
		} else {
			var sId = card.getAttribute("data-show-id") || "";
			var eId = card.getAttribute("data-episode-id") || "";
			embedURL = "https://embed.podcasts.apple.com/us/podcast/id" + sId + "?i=" + eId + "&theme=light";
			fpBody.style.height = "175px";
		}

		loader.classList.remove("is-hidden");
		descPanel.classList.remove("is-open");
		descToggle.classList.remove("is-open");
		descToggle.setAttribute("aria-expanded", "false");

		iframe.src = embedURL;
		showName.textContent = sName;
		epTitle.textContent = eTitle;
		descText.textContent = eDesc;
		player.classList.add("is-visible");
	});

	closeBtn.addEventListener("click", function() {
		player.classList.remove("is-visible");
		descPanel.classList.remove("is-open");
		descToggle.classList.remove("is-open");
		setTimeout(function() {
			iframe.src = "";
			loader.classList.remove("is-hidden");
			fpBody.style.height = "175px";
		}, 350);
	});
});
';
	}

	// =========================================================================
	// Admin Page
	// =========================================================================

	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1>amplifi.pods</h1>
			<p>Podcast carousel and floating player — mirrors the Resources page podcast section. Works as a shortcode you can place anywhere.</p>

			<h2>Shortcode Reference</h2>
			<table class="widefat fixed" style="max-width:800px;">
				<thead>
					<tr><th>Attribute</th><th>Description</th><th>Default</th></tr>
				</thead>
				<tbody>
					<tr>
						<td><code>count</code></td>
						<td>Maximum number of episodes to display. Use <code>-1</code> for all.</td>
						<td><code>-1</code> (all)</td>
					</tr>
					<tr>
						<td><code>show_filters</code></td>
						<td>Show Spotify playlist filter pills above the carousel.</td>
						<td><code>true</code></td>
					</tr>
					<tr>
						<td><code>show_header</code></td>
						<td>Show the heading, subheading, and description above the carousel.</td>
						<td><code>true</code></td>
					</tr>
					<tr>
						<td><code>heading</code></td>
						<td>Main heading text.</td>
						<td><code>Podcasts</code></td>
					</tr>
					<tr>
						<td><code>subheading</code></td>
						<td>Small uppercase text above heading.</td>
						<td><code>Featured Podcasts</code></td>
					</tr>
					<tr>
						<td><code>description</code></td>
						<td>Paragraph text below the heading.</td>
						<td><em>(default description)</em></td>
					</tr>
					<tr>
						<td><code>accent_color</code></td>
						<td>Hex color for accent elements (header, links, CTA).</td>
						<td><code>#055c5f</code></td>
					</tr>
				</tbody>
			</table>

			<h3>Examples</h3>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px;max-width:800px;"><code>[amplifi-pods]
[amplifi-pods count="8" show_header="false"]
[amplifi-pods heading="Our Podcasts" accent_color="#6366f1"]</code></pre>

			<hr>

			<h2>Data Sources</h2>
			<p>The shortcode merges episodes from two sources, sorted by date:</p>
			<ol>
				<li><strong>Podcast CPT</strong> — Create episodes under <strong>Podcasts</strong> in the sidebar. Uses ACF fields (or fallback meta box) for Apple Show ID, Episode ID, artwork, etc.</li>
				<li><strong>Spotify Playlists</strong> — If the <code>nwr_spotify_get_all_episodes()</code> function is available (from the Norwest Resources plugin), Spotify playlist episodes are automatically included.</li>
			</ol>

			<h3>ACF Fields</h3>
			<p>When ACF Pro is active, the plugin registers these fields on the <code>podcast</code> post type:</p>
			<ul>
				<li><strong>Show Name</strong> — e.g. "Planet Money"</li>
				<li><strong>Apple Podcasts Show ID</strong> — Numeric ID from the URL</li>
				<li><strong>Apple Podcasts Episode ID</strong> — The <code>?i=</code> parameter</li>
				<li><strong>Artwork URL</strong> — Apple Podcasts artwork image</li>
				<li><strong>Episode Label</strong> — e.g. "Episode 42" or "Feb 13, 2026"</li>
				<li><strong>Duration</strong> — e.g. "45 min"</li>
			</ul>

			<hr>

			<h2>Styling</h2>
			<p>Override the accent color via shortcode attribute or CSS custom property:</p>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px;max-width:800px;"><code>.acpods-wrap {
  --acpods-accent: #6366f1;
}</code></pre>
			<p>No font families, Bootstrap, or external icon libraries required. All icons are inline SVG.</p>

			<hr>

			<h2>Published Episodes</h2>
			<?php
			$episodes = get_posts( array(
				'post_type'      => 'podcast',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );

			if ( ! empty( $episodes ) ) {
				echo '<table class="widefat fixed striped">';
				echo '<thead><tr><th>Title</th><th>Show Name</th><th>Episode Label</th><th>Duration</th><th>Apple Show ID</th><th>Apple Episode ID</th><th>Date</th></tr></thead>';
				echo '<tbody>';
				foreach ( $episodes as $ep ) {
					$edit_link = get_edit_post_link( $ep->ID );
					echo '<tr>';
					echo '<td><a href="' . esc_url( $edit_link ) . '">' . esc_html( $ep->post_title ) . '</a></td>';
					echo '<td>' . esc_html( $this->get_field_value( 'podcast_show_name', $ep->ID ) ) . '</td>';
					echo '<td>' . esc_html( $this->get_field_value( 'podcast_episode_number', $ep->ID ) ) . '</td>';
					echo '<td>' . esc_html( $this->get_field_value( 'podcast_duration', $ep->ID ) ) . '</td>';
					echo '<td>' . esc_html( $this->get_field_value( 'podcast_apple_show_id', $ep->ID ) ) . '</td>';
					echo '<td>' . esc_html( $this->get_field_value( 'podcast_apple_episode_id', $ep->ID ) ) . '</td>';
					echo '<td>' . esc_html( get_the_date( '', $ep ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p><em>No episodes published yet.</em> <a href="' . esc_url( admin_url( 'post-new.php?post_type=podcast' ) ) . '">Create your first episode</a></p>';
			}
			?>
		</div>
		<?php
	}
}

// Initialize
new Amplifi_Pods();

// Register ACF fields when ACF is ready
add_action( 'acf/init', array( 'Amplifi_Pods', 'register_acf_fields' ) );
