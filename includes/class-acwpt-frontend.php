<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Frontend {

	private static $instance = null;

	/** @var string|null Current language code (null = source language). */
	private $current_language = null;

	/** @var array Cached translation objects keyed by post_id. */
	private $translations = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the current translation language (null if source/default).
	 */
	public function get_current_language() {
		return $this->current_language;
	}

	public function init() {
		// Detect language from URL as early as possible.
		$this->detect_language();

		// Content filters.
		add_filter( 'the_title', array( $this, 'filter_title' ), 1, 2 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 1 );
		add_filter( 'the_excerpt', array( $this, 'filter_excerpt' ), 1 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title' ), 1 );

		// Pre-fetch translations once the query is ready.
		add_action( 'wp', array( $this, 'prepare_translations' ) );

		// SEO: hreflang tags.
		add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 1 );

		// HTML lang attribute.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );

		// Language switcher shortcode.
		add_shortcode( 'acwpt_switcher', array( $this, 'render_switcher' ) );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Invalidate cache when posts are saved.
		add_action( 'save_post', array( $this, 'invalidate_post_cache' ), 10, 2 );

		// Flush rewrite rules if pending.
		if ( get_option( 'acwpt_flush_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'acwpt_flush_rules' );
		}

		// Fix canonical URL for translated pages.
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// URL / Language Detection
	// -------------------------------------------------------------------------

	/**
	 * Detect language prefix from URL and strip it so WordPress resolves the original page.
	 */
	private function detect_language() {
		if ( is_admin() ) {
			return;
		}

		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$home_path   = $this->get_home_path();

		// Get relative path after the home URL path.
		$relative = $request_uri;
		if ( $home_path && strpos( $relative, $home_path ) === 0 ) {
			$relative = substr( $relative, strlen( $home_path ) );
		}
		$relative = '/' . ltrim( $relative, '/' );

		// Build regex for enabled language codes.
		$codes_pattern = implode( '|', array_map( 'preg_quote', $enabled ) );

		if ( preg_match( '#^/(' . $codes_pattern . ')(/.*)?$#', $relative, $matches ) ) {
			$this->current_language = $matches[1];
			$new_relative           = isset( $matches[2] ) ? $matches[2] : '/';
			if ( empty( $new_relative ) ) {
				$new_relative = '/';
			}

			// Rewrite REQUEST_URI so WordPress resolves the original post/page.
			$_SERVER['REQUEST_URI'] = $home_path . ltrim( $new_relative, '/' );
		}
	}

	/**
	 * Get the path component of home_url().
	 */
	private function get_home_path() {
		$home = home_url();
		$path = wp_parse_url( $home, PHP_URL_PATH );
		return $path ? rtrim( $path, '/' ) . '/' : '/';
	}

	// -------------------------------------------------------------------------
	// Translation Pre-fetch
	// -------------------------------------------------------------------------

	/**
	 * Pre-fetch or generate translations for queried posts.
	 */
	public function prepare_translations() {
		if ( ! $this->current_language ) {
			return;
		}

		// Single post/page.
		$queried = get_queried_object();
		if ( $queried && $queried instanceof WP_Post ) {
			$this->ensure_translation( $queried );
		}

		// Archive pages: translate titles for posts in the loop.
		global $wp_query;
		if ( $wp_query && ! empty( $wp_query->posts ) && ! is_singular() ) {
			foreach ( $wp_query->posts as $post ) {
				$this->ensure_translation( $post );
			}
		}
	}

	/**
	 * Ensure a translation exists for a post (from cache or API).
	 */
	private function ensure_translation( $post ) {
		if ( isset( $this->translations[ $post->ID ] ) ) {
			return;
		}

		$content_hash = md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt );

		// Check cache.
		$cached = ACWPT_Cache::get( $post->ID, $this->current_language );

		if ( $cached && $cached->content_hash === $content_hash ) {
			$this->translations[ $post->ID ] = $cached;
			return;
		}

		// Translate via OpenAI.
		$result = ACWPT_Translator::translate(
			$post->post_title,
			$post->post_content,
			$post->post_excerpt,
			$this->current_language
		);

		if ( is_wp_error( $result ) ) {
			// Log and fall back to original content.
			error_log( 'ACWPT translation error for post ' . $post->ID . ': ' . $result->get_error_message() );
			return;
		}

		// Save to cache.
		ACWPT_Cache::set(
			$post->ID,
			$this->current_language,
			$result['title'],
			$result['content'],
			$result['excerpt'],
			$content_hash
		);

		$this->translations[ $post->ID ] = (object) array(
			'translated_title'   => $result['title'],
			'translated_content' => $result['content'],
			'translated_excerpt' => $result['excerpt'],
		);
	}

	// -------------------------------------------------------------------------
	// Content Filters
	// -------------------------------------------------------------------------

	public function filter_title( $title, $post_id = 0 ) {
		if ( ! $this->current_language || ! $post_id ) {
			return $title;
		}

		// Don't translate menu items or other non-content post types.
		$post_type = get_post_type( $post_id );
		if ( $post_type === 'nav_menu_item' || $post_type === 'wp_navigation' ) {
			return $title;
		}

		if ( isset( $this->translations[ $post_id ] ) && ! empty( $this->translations[ $post_id ]->translated_title ) ) {
			return $this->translations[ $post_id ]->translated_title;
		}

		return $title;
	}

	public function filter_content( $content ) {
		if ( ! $this->current_language ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( $post_id && isset( $this->translations[ $post_id ] ) && ! empty( $this->translations[ $post_id ]->translated_content ) ) {
			return $this->translations[ $post_id ]->translated_content;
		}

		return $content;
	}

	public function filter_excerpt( $excerpt ) {
		if ( ! $this->current_language ) {
			return $excerpt;
		}

		$post_id = get_the_ID();
		if ( $post_id && isset( $this->translations[ $post_id ] ) && ! empty( $this->translations[ $post_id ]->translated_excerpt ) ) {
			return $this->translations[ $post_id ]->translated_excerpt;
		}

		return $excerpt;
	}

	public function filter_document_title( $title_parts ) {
		if ( ! $this->current_language ) {
			return $title_parts;
		}

		$queried = get_queried_object();
		if ( $queried && $queried instanceof WP_Post && isset( $this->translations[ $queried->ID ] ) ) {
			$title_parts['title'] = $this->translations[ $queried->ID ]->translated_title;
		}

		return $title_parts;
	}

	// -------------------------------------------------------------------------
	// HTML lang attribute
	// -------------------------------------------------------------------------

	public function filter_language_attributes( $output ) {
		if ( $this->current_language ) {
			$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $this->current_language ) . '"', $output );
		}
		return $output;
	}

	// -------------------------------------------------------------------------
	// Canonical URL
	// -------------------------------------------------------------------------

	public function filter_canonical_url( $canonical, $post ) {
		if ( $this->current_language ) {
			$canonical = $this->get_translated_url( $this->current_language, $post );
		}
		return $canonical;
	}

	// -------------------------------------------------------------------------
	// Hreflang Tags
	// -------------------------------------------------------------------------

	public function output_hreflang_tags() {
		if ( ! is_singular() ) {
			return;
		}

		$post    = get_queried_object();
		if ( ! $post || ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$source  = ACWPT_Languages::get_source();
		$enabled = ACWPT_Languages::get_enabled_codes();

		if ( empty( $enabled ) ) {
			return;
		}

		$original_url = get_permalink( $post );

		// x-default and source language.
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $original_url ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="' . esc_attr( $source ) . '" href="' . esc_url( $original_url ) . '" />' . "\n";

		// Each enabled language.
		foreach ( $enabled as $code ) {
			$url = $this->get_translated_url( $code, $post );
			echo '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------------
	// Language Switcher Shortcode
	// -------------------------------------------------------------------------

	public function render_switcher( $atts ) {
		$enabled = ACWPT_Languages::get_enabled();
		if ( empty( $enabled ) ) {
			return '';
		}

		$source      = ACWPT_Languages::get_source();
		$source_lang = ACWPT_Languages::get( $source );
		$current     = $this->current_language ? $this->current_language : $source;

		// Build the current page's URL without language prefix.
		$current_url = $this->get_current_page_path();

		$html  = '<div class="acwpt-switcher-wrap">';
		$html .= '<select class="acwpt-switcher" onchange="if(this.value)window.location.href=this.value;">';

		// Source language option.
		$source_label = ACWPT_Languages::label( $source );
		$source_url   = home_url( $current_url );
		$html        .= '<option value="' . esc_url( $source_url ) . '"' . selected( $current, $source, false ) . '>';
		$html        .= esc_html( $source_label );
		$html        .= '</option>';

		// Enabled language options.
		foreach ( $enabled as $code => $lang ) {
			$label = ACWPT_Languages::label( $code );
			$url   = home_url( '/' . $code . $current_url );
			$html .= '<option value="' . esc_url( $url ) . '"' . selected( $current, $code, false ) . '>';
			$html .= esc_html( $label );
			$html .= '</option>';
		}

		$html .= '</select>';
		$html .= '</div>';

		return $html;
	}

	// -------------------------------------------------------------------------
	// URL Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the current page's path (without language prefix, without home path).
	 */
	private function get_current_page_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$home_path   = $this->get_home_path();

		$path = $request_uri;
		if ( $home_path && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( $path, '/' );

		// Strip query string.
		$path = strtok( $path, '?' );

		return $path;
	}

	/**
	 * Build a translated URL for a given language and post.
	 */
	private function get_translated_url( $lang_code, $post = null ) {
		if ( $post ) {
			$permalink = get_permalink( $post );
			$home      = home_url();
			$relative  = str_replace( $home, '', $permalink );
			$relative  = '/' . ltrim( $relative, '/' );
			return home_url( '/' . $lang_code . $relative );
		}

		$path = $this->get_current_page_path();
		return home_url( '/' . $lang_code . $path );
	}

	// -------------------------------------------------------------------------
	// Frontend Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets() {
		$settings = get_option( 'acwpt_settings', array() );

		wp_enqueue_style( 'acwpt-frontend', ACWPT_PLUGIN_URL . 'assets/css/frontend.css', array(), ACWPT_VERSION );

		$show_suggestion = isset( $settings['show_suggestion'] ) ? (bool) $settings['show_suggestion'] : true;

		if ( $show_suggestion && ! $this->current_language ) {
			wp_enqueue_script( 'acwpt-detect', ACWPT_PLUGIN_URL . 'assets/js/detect.js', array(), ACWPT_VERSION, true );

			$enabled    = ACWPT_Languages::get_enabled();
			$lang_names = array();
			foreach ( $enabled as $code => $lang ) {
				$lang_names[ $code ] = ACWPT_Languages::label( $code );
			}

			wp_localize_script( 'acwpt-detect', 'acwptDetect', array(
				'languages'   => ACWPT_Languages::get_enabled_codes(),
				'names'       => $lang_names,
				'currentLang' => ACWPT_Languages::get_source(),
				'homeUrl'     => home_url(),
				'currentPath' => $this->get_current_page_path(),
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Cache Invalidation
	// -------------------------------------------------------------------------

	public function invalidate_post_cache( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		ACWPT_Cache::delete_post( $post_id );
	}
}
