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

	/** @var array Cached string translations for current language. */
	private $string_cache = null;

	/** @var bool Guard against recursion in option filters. */
	private $filtering_option = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_current_language() {
		return $this->current_language;
	}

	public function init() {
		// Detect language from URL as early as possible.
		$this->detect_language();

		// Content filters for post body.
		add_filter( 'the_title', array( $this, 'filter_title' ), 1, 2 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 1 );
		add_filter( 'the_excerpt', array( $this, 'filter_excerpt' ), 1 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title' ), 1 );

		// Site-wide option filters.
		add_filter( 'option_blogname', array( $this, 'filter_blogname' ) );
		add_filter( 'option_blogdescription', array( $this, 'filter_blogdescription' ) );

		// Pre-fetch translations once the query is ready.
		add_action( 'wp', array( $this, 'prepare_translations' ) );

		// Full page output buffer to translate nav, footer, meta, and prefix links.
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 0 );

		// SEO: hreflang tags.
		add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 1 );

		// HTML lang attribute.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );

		// Language switcher shortcode.
		add_shortcode( 'acwpt_switcher', array( $this, 'render_switcher' ) );

		// Language switcher as nav menu item.
		add_filter( 'wp_nav_menu_objects', array( $this, 'expand_language_menu_items' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'add_lang_link_attributes' ), 10, 4 );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Invalidate caches when posts are saved.
		add_action( 'save_post', array( $this, 'invalidate_post_cache' ), 10, 2 );

		// Clear string cache when site title/tagline changes.
		add_action( 'update_option_blogname', array( $this, 'clear_all_string_caches' ) );
		add_action( 'update_option_blogdescription', array( $this, 'clear_all_string_caches' ) );

		// Flush rewrite rules if pending.
		if ( get_option( 'acwpt_flush_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'acwpt_flush_rules' );
		}

		// Fix canonical URL for translated pages.
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
	}

	// =========================================================================
	// URL / Language Detection
	// =========================================================================

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

		$relative = $request_uri;
		if ( $home_path && strpos( $relative, $home_path ) === 0 ) {
			$relative = substr( $relative, strlen( $home_path ) );
		}
		$relative = '/' . ltrim( $relative, '/' );

		$codes_pattern = implode( '|', array_map( 'preg_quote', $enabled ) );

		if ( preg_match( '#^/(' . $codes_pattern . ')(/.*)?$#', $relative, $matches ) ) {
			$this->current_language = $matches[1];
			$new_relative           = isset( $matches[2] ) ? $matches[2] : '/';
			if ( empty( $new_relative ) ) {
				$new_relative = '/';
			}
			$_SERVER['REQUEST_URI'] = $home_path . ltrim( $new_relative, '/' );
		}
	}

	private function get_home_path() {
		$home = home_url();
		$path = wp_parse_url( $home, PHP_URL_PATH );
		return $path ? rtrim( $path, '/' ) . '/' : '/';
	}

	// =========================================================================
	// Translation Pre-fetch (posts + site-wide strings)
	// =========================================================================

	public function prepare_translations() {
		if ( ! $this->current_language ) {
			return;
		}

		// Translate post content.
		$queried = get_queried_object();
		if ( $queried && $queried instanceof WP_Post ) {
			$this->ensure_translation( $queried );
		}

		global $wp_query;
		if ( $wp_query && ! empty( $wp_query->posts ) && ! is_singular() ) {
			foreach ( $wp_query->posts as $post ) {
				$this->ensure_translation( $post );
			}
		}

		// Pre-translate site-wide strings (menus, site title, etc.).
		$this->prepare_string_translations();
	}

	private function ensure_translation( $post ) {
		if ( isset( $this->translations[ $post->ID ] ) ) {
			return;
		}

		$content_hash = md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt );
		$cached       = ACWPT_Cache::get( $post->ID, $this->current_language );

		if ( $cached && $cached->content_hash === $content_hash ) {
			$this->translations[ $post->ID ] = $cached;
			return;
		}

		$result = ACWPT_Translator::translate(
			$post->post_title,
			$post->post_content,
			$post->post_excerpt,
			$this->current_language
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'ACWPT translation error for post ' . $post->ID . ': ' . $result->get_error_message() );
			return;
		}

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

	// =========================================================================
	// Site-wide String Translation Cache
	// =========================================================================

	/**
	 * Load the string translation cache for the current language.
	 */
	private function load_string_cache() {
		if ( null === $this->string_cache ) {
			$this->string_cache = get_option( 'acwpt_strings_' . $this->current_language, array() );
			if ( ! is_array( $this->string_cache ) ) {
				$this->string_cache = array();
			}
		}
		return $this->string_cache;
	}

	/**
	 * Get a single string translation from cache.
	 */
	private function get_string_translation( $original ) {
		$cache = $this->load_string_cache();
		return isset( $cache[ $original ] ) ? $cache[ $original ] : null;
	}

	/**
	 * Pre-translate all site-wide strings in a single batch API call.
	 */
	private function prepare_string_translations() {
		$cache = $this->load_string_cache();

		$strings_needed = array();

		// Site title.
		$this->filtering_option = true;
		$blogname = get_option( 'blogname' );
		$blogdesc = get_option( 'blogdescription' );
		$this->filtering_option = false;

		if ( ! empty( $blogname ) && ! isset( $cache[ $blogname ] ) ) {
			$strings_needed[] = $blogname;
		}
		if ( ! empty( $blogdesc ) && ! isset( $cache[ $blogdesc ] ) ) {
			$strings_needed[] = $blogdesc;
		}

		// Collect nav menu items from all registered menus.
		$locations = get_nav_menu_locations();
		if ( ! empty( $locations ) ) {
			foreach ( $locations as $location => $menu_id ) {
				if ( ! $menu_id ) {
					continue;
				}
				$items = wp_get_nav_menu_items( $menu_id );
				if ( $items ) {
					foreach ( $items as $item ) {
						$title = trim( $item->title );
						if ( ! empty( $title ) && ! isset( $cache[ $title ] ) ) {
							$strings_needed[] = $title;
						}
					}
				}
			}
		}

		// Also collect page titles used in block nav (core/page-list).
		$pages = get_pages( array( 'post_status' => 'publish', 'number' => 50 ) );
		if ( $pages ) {
			foreach ( $pages as $page ) {
				$title = trim( $page->post_title );
				if ( ! empty( $title ) && ! isset( $cache[ $title ] ) ) {
					$strings_needed[] = $title;
				}
			}
		}

		// Common theme/footer strings.
		$common_strings = array(
			'Designed with', 'Powered by', 'WordPress', 'Skip to content',
			'Blog', 'About', 'FAQs', 'Authors', 'Events', 'Shop', 'Patterns', 'Themes',
			'Search', 'Menu', 'Close', 'Open', 'Navigation', 'Primary', 'Footer',
			'Read more', 'Continue reading', 'Leave a comment', 'Comments',
		);
		foreach ( $common_strings as $str ) {
			if ( ! isset( $cache[ $str ] ) ) {
				$strings_needed[] = $str;
			}
		}

		$strings_needed = array_unique( array_filter( $strings_needed ) );

		if ( empty( $strings_needed ) ) {
			return;
		}

		// Batch translate in one API call.
		$translated = ACWPT_Translator::translate_strings( $strings_needed, $this->current_language );

		if ( is_wp_error( $translated ) ) {
			error_log( 'ACWPT string translation error: ' . $translated->get_error_message() );
			return;
		}

		// Merge into cache.
		foreach ( $translated as $original => $trans ) {
			$cache[ $original ] = $trans;
		}

		$this->string_cache = $cache;
		update_option( 'acwpt_strings_' . $this->current_language, $cache, false );
	}

	/**
	 * Clear all string translation caches (all languages).
	 */
	public function clear_all_string_caches() {
		$enabled = ACWPT_Languages::get_enabled_codes();
		foreach ( $enabled as $code ) {
			delete_option( 'acwpt_strings_' . $code );
		}
		$this->string_cache = null;
	}

	// =========================================================================
	// Content Filters (post body)
	// =========================================================================

	public function filter_title( $title, $post_id = 0 ) {
		if ( ! $this->current_language || ! $post_id ) {
			return $title;
		}
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
		// Translate page title part.
		$queried = get_queried_object();
		if ( $queried && $queried instanceof WP_Post && isset( $this->translations[ $queried->ID ] ) ) {
			$title_parts['title'] = $this->translations[ $queried->ID ]->translated_title;
		}
		// Translate the site name part.
		if ( isset( $title_parts['site'] ) ) {
			$trans = $this->get_string_translation( $title_parts['site'] );
			if ( $trans ) {
				$title_parts['site'] = $trans;
			}
		}
		return $title_parts;
	}

	// =========================================================================
	// Option Filters (site title, tagline)
	// =========================================================================

	public function filter_blogname( $name ) {
		if ( ! $this->current_language || $this->filtering_option ) {
			return $name;
		}
		$trans = $this->get_string_translation( $name );
		return $trans ? $trans : $name;
	}

	public function filter_blogdescription( $desc ) {
		if ( ! $this->current_language || $this->filtering_option ) {
			return $desc;
		}
		$trans = $this->get_string_translation( $desc );
		return $trans ? $trans : $desc;
	}

	// =========================================================================
	// HTML lang attribute
	// =========================================================================

	public function filter_language_attributes( $output ) {
		if ( $this->current_language ) {
			$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $this->current_language ) . '"', $output );
		}
		return $output;
	}

	// =========================================================================
	// Canonical URL
	// =========================================================================

	public function filter_canonical_url( $canonical, $post ) {
		if ( $this->current_language ) {
			$canonical = $this->get_translated_url( $this->current_language, $post );
		}
		return $canonical;
	}

	// =========================================================================
	// Full Page Output Buffer
	// =========================================================================

	/**
	 * Start output buffering on translated pages to post-process the full HTML.
	 */
	public function start_output_buffer() {
		if ( ! $this->current_language || is_admin() ) {
			return;
		}
		ob_start( array( $this, 'process_output_buffer' ) );
	}

	/**
	 * Process the full page HTML: translate nav, footer, meta tags, prefix links.
	 */
	public function process_output_buffer( $html ) {
		if ( ! $this->current_language || empty( $html ) ) {
			return $html;
		}

		// Protect language switcher links from being translated or re-prefixed.
		$protected_links = array();
		$html = preg_replace_callback(
			'/<a\s[^>]*data-acwpt-lang[^>]*>.*?<\/a>/is',
			function( $m ) use ( &$protected_links ) {
				$placeholder = '<!--ACWPT_PROT_' . count( $protected_links ) . '-->';
				$protected_links[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);

		// 1. Translate meta tags (description, OG, Twitter).
		$html = $this->translate_meta_tags( $html );

		// 2. Translate text in <nav> sections (menus).
		$html = $this->translate_nav_text( $html );

		// 3. Translate text in <footer> sections.
		$html = $this->translate_footer_text( $html );

		// 4. Translate text in <header> sections (outside of main content).
		$html = $this->translate_header_text( $html );

		// 5. Prefix all internal links with language code.
		$html = $this->prefix_internal_links( $html );

		// 6. Fix og:url to point to translated URL.
		$html = $this->fix_og_url( $html );

		// Restore protected language switcher links.
		foreach ( $protected_links as $placeholder => $link ) {
			$html = str_replace( $placeholder, $link, $html );
		}

		return $html;
	}

	/**
	 * Translate meta description, OG tags, Twitter cards.
	 */
	private function translate_meta_tags( $html ) {
		// Match meta tags with name or property for known SEO attributes.
		// Handles both name="..." content="..." and property="..." content="..." in any order.
		$html = preg_replace_callback(
			'/<meta\s+[^>]*?(?:name|property)\s*=\s*["\'](?:description|og:title|og:description|og:site_name|twitter:title|twitter:description)["\'][^>]*>/i',
			array( $this, 'translate_meta_tag_callback' ),
			$html
		);
		return $html;
	}

	/**
	 * Callback to translate a single meta tag's content attribute.
	 */
	public function translate_meta_tag_callback( $match ) {
		$tag = $match[0];
		if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $tag, $cm ) ) {
			$original   = html_entity_decode( $cm[1], ENT_QUOTES, 'UTF-8' );
			$translated = $this->get_string_translation( $original );
			if ( ! $translated ) {
				// Translate on the fly if not cached.
				$result = ACWPT_Translator::translate_strings( array( $original ), $this->current_language );
				if ( ! is_wp_error( $result ) && isset( $result[ $original ] ) ) {
					$translated = $result[ $original ];
					// Update cache.
					$cache = $this->load_string_cache();
					$cache[ $original ] = $translated;
					$this->string_cache = $cache;
					update_option( 'acwpt_strings_' . $this->current_language, $cache, false );
				}
			}
			if ( $translated && $translated !== $original ) {
				$tag = str_replace( $cm[1], esc_attr( $translated ), $tag );
			}
		}
		return $tag;
	}

	/**
	 * Translate text within <nav> elements.
	 */
	private function translate_nav_text( $html ) {
		return preg_replace_callback(
			'/(<nav\b[^>]*>)(.*?)(<\/nav>)/si',
			array( $this, 'translate_section_links' ),
			$html
		);
	}

	/**
	 * Translate text within <header> elements.
	 */
	private function translate_header_text( $html ) {
		return preg_replace_callback(
			'/(<header\b[^>]*>)(.*?)(<\/header>)/si',
			array( $this, 'translate_section_links' ),
			$html
		);
	}

	/**
	 * Translate text within <footer> elements.
	 */
	private function translate_footer_text( $html ) {
		return preg_replace_callback(
			'/(<footer\b[^>]*>)(.*?)(<\/footer>)/si',
			array( $this, 'translate_section_text_and_links' ),
			$html
		);
	}

	/**
	 * Translate link text within a section.
	 */
	public function translate_section_links( $match ) {
		$open    = $match[1];
		$content = $match[2];
		$close   = $match[3];

		// Translate <a> link text.
		$content = preg_replace_callback(
			'/(<a\b[^>]*>)([^<]+)(<\/a>)/i',
			array( $this, 'translate_link_text_callback' ),
			$content
		);

		return $open . $content . $close;
	}

	/**
	 * Translate both link text and plain text within a section (for footer).
	 */
	public function translate_section_text_and_links( $match ) {
		$open    = $match[1];
		$content = $match[2];
		$close   = $match[3];

		// Translate <a> link text.
		$content = preg_replace_callback(
			'/(<a\b[^>]*>)([^<]+)(<\/a>)/i',
			array( $this, 'translate_link_text_callback' ),
			$content
		);

		// Translate text in <p>, <span>, <div>, <h1>-<h6>, <li> (direct text, not nested).
		$content = preg_replace_callback(
			'/(<(?:p|span|div|h[1-6]|li)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li)>)/i',
			array( $this, 'translate_element_text_callback' ),
			$content
		);

		return $open . $content . $close;
	}

	/**
	 * Callback: translate the text inside a link.
	 */
	public function translate_link_text_callback( $m ) {
		$text = trim( $m[2] );
		if ( strlen( $text ) < 2 || preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
			return $m[0];
		}
		$translated = $this->get_string_translation( $text );
		if ( $translated ) {
			return $m[1] . str_replace( $text, $translated, $m[2] ) . $m[3];
		}
		return $m[0];
	}

	/**
	 * Callback: translate text inside an element.
	 */
	public function translate_element_text_callback( $m ) {
		$text = trim( $m[2] );
		if ( strlen( $text ) < 2 || preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
			return $m[0];
		}
		// Skip if it looks like code or a URL.
		if ( preg_match( '/^https?:/', $text ) || preg_match( '/[{}()<>]/', $text ) ) {
			return $m[0];
		}
		$translated = $this->get_string_translation( $text );
		if ( $translated ) {
			return $m[1] . str_replace( $text, $translated, $m[2] ) . $m[3];
		}
		return $m[0];
	}

	/**
	 * Prefix all internal links with the current language code.
	 */
	private function prefix_internal_links( $html ) {
		$home_url = home_url();
		$lang     = $this->current_language;
		$enabled  = ACWPT_Languages::get_enabled_codes();

		$escaped_home = preg_quote( $home_url, '/' );
		$codes        = implode( '|', array_map( 'preg_quote', $enabled ) );

		// Prefix internal page links (not admin, assets, feeds, or already-prefixed).
		$html = preg_replace_callback(
			'/href="(' . $escaped_home . ')\/(?!wp-admin|wp-content|wp-includes|wp-json|wp-login|feed|xmlrpc|wp-cron|(?:' . $codes . ')\/)([^"]*)"/',
			function ( $m ) use ( $lang, $home_url ) {
				return 'href="' . $home_url . '/' . $lang . '/' . $m[2] . '"';
			},
			$html
		);

		// Also prefix bare home URL links.
		$html = str_replace(
			'href="' . $home_url . '"',
			'href="' . $home_url . '/' . $lang . '/"',
			$html
		);
		// Home URL with trailing slash.
		$html = preg_replace(
			'/href="' . $escaped_home . '\/(?!(' . $codes . ')\/)"/',
			'href="' . $home_url . '/' . $lang . '/"',
			$html
		);

		return $html;
	}

	/**
	 * Fix og:url meta tag to point to the translated URL.
	 */
	private function fix_og_url( $html ) {
		$translated_url = $this->get_translated_url( $this->current_language );
		$html = preg_replace(
			'/(<meta\s+[^>]*property\s*=\s*["\']og:url["\'][^>]*content\s*=\s*["\'])([^"\']+)(["\'][^>]*>)/i',
			'$1' . esc_url( $translated_url ) . '$3',
			$html
		);
		return $html;
	}

	// =========================================================================
	// Hreflang Tags
	// =========================================================================

	public function output_hreflang_tags() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post || ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$source  = ACWPT_Languages::get_source();
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return;
		}

		$original_url = get_permalink( $post );

		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $original_url ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="' . esc_attr( $source ) . '" href="' . esc_url( $original_url ) . '" />' . "\n";

		foreach ( $enabled as $code ) {
			$url = $this->get_translated_url( $code, $post );
			echo '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	// =========================================================================
	// Language Switcher Shortcode
	// =========================================================================

	public function render_switcher( $atts ) {
		$enabled = ACWPT_Languages::get_enabled();
		if ( empty( $enabled ) ) {
			return '';
		}

		$source  = ACWPT_Languages::get_source();
		$current = $this->current_language ? $this->current_language : $source;
		$path    = $this->get_current_page_path();

		$html  = '<div class="acwpt-switcher-wrap">';
		$html .= '<select class="acwpt-switcher" onchange="if(this.value)window.location.href=this.value;">';

		// Source language option.
		$source_label = ACWPT_Languages::label( $source );
		$source_url   = home_url( $path );
		$html        .= '<option value="' . esc_url( $source_url ) . '"' . selected( $current, $source, false ) . '>';
		$html        .= esc_html( $source_label );
		$html        .= '</option>';

		foreach ( $enabled as $code => $lang ) {
			$label = ACWPT_Languages::label( $code );
			$url   = home_url( '/' . $code . $path );
			$html .= '<option value="' . esc_url( $url ) . '"' . selected( $current, $code, false ) . '>';
			$html .= esc_html( $label );
			$html .= '</option>';
		}

		$html .= '</select>';
		$html .= '</div>';

		return $html;
	}

	// =========================================================================
	// Language Switcher Nav Menu Item
	// =========================================================================

	/**
	 * Expand the Language Switcher placeholder into real language menu items.
	 */
	public function expand_language_menu_items( $items, $args ) {
		$settings   = get_option( 'acwpt_settings', array() );
		$show_flags = isset( $settings['show_flags'] ) ? (bool) $settings['show_flags'] : true;
		$source     = ACWPT_Languages::get_source();
		$enabled    = ACWPT_Languages::get_enabled();
		$current    = $this->current_language ? $this->current_language : $source;
		$path       = $this->get_current_page_path();

		if ( empty( $enabled ) ) {
			return $items;
		}

		$new_items = array();
		$counter   = 999990;

		foreach ( $items as $item ) {
			if ( $item->url !== '#acwpt-language-switcher' ) {
				$new_items[] = $item;
				continue;
			}

			// Set the top-level item to show the current language.
			$item->title = $this->menu_label( $current, $show_flags );
			$item->url   = '#';
			if ( ! is_array( $item->classes ) ) {
				$item->classes = array();
			}
			$item->classes[] = 'acwpt-menu-switcher';
			$item->classes[] = 'menu-item-has-children';
			$new_items[]     = $item;
			$parent_db_id    = $item->db_id;

			// Source language sub-item (only if not currently on source).
			if ( $current !== $source ) {
				$new_items[] = $this->create_lang_menu_item(
					$counter++,
					$parent_db_id,
					$this->menu_label( $source, $show_flags ),
					home_url( $path )
				);
			}

			// Enabled language sub-items (skip current).
			foreach ( $enabled as $code => $lang ) {
				if ( $code === $current ) {
					continue;
				}
				$new_items[] = $this->create_lang_menu_item(
					$counter++,
					$parent_db_id,
					$this->menu_label( $code, $show_flags ),
					home_url( '/' . $code . $path )
				);
			}
		}

		return $new_items;
	}

	/**
	 * Create a single language sub-menu item object.
	 */
	private function create_lang_menu_item( $id, $parent_id, $title, $url ) {
		$item                        = new stdClass();
		$item->ID                    = $id;
		$item->db_id                 = $id;
		$item->menu_item_parent      = (string) $parent_id;
		$item->object_id             = $id;
		$item->object                = 'custom';
		$item->type                  = 'custom';
		$item->type_label            = '';
		$item->title                 = $title;
		$item->url                   = $url;
		$item->target                = '';
		$item->attr_title            = '';
		$item->description           = '';
		$item->classes               = array( 'menu-item', 'acwpt-lang-item' );
		$item->xfn                   = '';
		$item->current               = false;
		$item->current_item_ancestor = false;
		$item->current_item_parent   = false;

		return $item;
	}

	/**
	 * Build a label for a language (with or without flag emoji).
	 */
	private function menu_label( $code, $show_flags ) {
		$lang = ACWPT_Languages::get( $code );
		if ( ! $lang ) {
			return $code;
		}
		$label = '';
		if ( $show_flags ) {
			$label .= $lang['flag'] . ' ';
		}
		$label .= $lang['name'];
		return $label;
	}

	/**
	 * Add data-acwpt-lang attribute to language sub-item links so the output
	 * buffer can protect them from being re-prefixed.
	 */
	public function add_lang_link_attributes( $atts, $item, $args, $depth ) {
		if ( is_array( $item->classes ) && in_array( 'acwpt-lang-item', $item->classes, true ) ) {
			$atts['data-acwpt-lang'] = '1';
		}
		return $atts;
	}

	// =========================================================================
	// URL Helpers
	// =========================================================================

	private function get_current_page_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$home_path   = $this->get_home_path();

		$path = $request_uri;
		if ( $home_path && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( $path, '/' );
		$path = strtok( $path, '?' );

		return $path;
	}

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

	// =========================================================================
	// Frontend Assets
	// =========================================================================

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

	// =========================================================================
	// Cache Invalidation
	// =========================================================================

	public function invalidate_post_cache( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		ACWPT_Cache::delete_post( $post_id );

		// Also clear string caches since page titles may have changed (used in nav).
		$this->clear_all_string_caches();
	}
}
