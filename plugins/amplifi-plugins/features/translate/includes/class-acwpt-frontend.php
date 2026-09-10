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
		// Register language URL prefix as a WordPress query var and inject rewrite rules.
		add_filter( 'query_vars', array( $this, 'add_language_query_var' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'add_language_rewrite_rules' ) );

		// Detect language from the parsed query var (fires after WP routes the URL).
		// Priority 1 so it runs before maybe_serve_sitemap.
		add_action( 'parse_request', array( $this, 'detect_language_from_query' ), 1 );

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

		// Full page output buffer to translate all visible text and prefix links.
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

		// Schedule a rewrite flush if the plugin version changed (new rules may have been added).
		$installed_version = get_option( 'acwpt_version', '0' );
		if ( version_compare( $installed_version, ACWPT_VERSION, '<' ) ) {
			update_option( 'acwpt_version', ACWPT_VERSION );
			update_option( 'acwpt_flush_rules', true );
		}

		// Flush rewrite rules if pending — deferred to 'init' priority 999 so all post types
		// and taxonomies have already registered their rewrite rules before we flush.
		if ( get_option( 'acwpt_flush_rules' ) ) {
			add_action( 'init', function() {
				flush_rewrite_rules();
				delete_option( 'acwpt_flush_rules' );
			}, 999 );
		}

		// Multilingual sitemap.
		add_action( 'parse_request', array( $this, 'maybe_serve_sitemap' ) );
		add_filter( 'robots_txt', array( $this, 'add_sitemap_to_robots' ), 10, 2 );

		// Fix canonical URL for translated pages.
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );

		// Prevent WordPress redirect_canonical from redirecting /es/blog/ → /blog/.
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 10, 2 );

		// Identify translated pages with a debug header.
		add_action( 'send_headers', array( $this, 'send_translated_page_headers' ) );

		// Console log on translated pages for admins.
		add_action( 'wp_footer', array( $this, 'debug_console_log' ), 99 );

		// Elementor: translate the final HTML output (Elementor bypasses the_content for builder content).
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			add_filter( 'elementor/frontend/the_content', array( $this, 'translate_elementor_content' ), 10, 1 );
		}
	}

	// =========================================================================
	// URL / Language Detection (via WordPress rewrite rules)
	// =========================================================================

	/**
	 * Register acwpt_lang as an allowed WordPress query var.
	 */
	public function add_language_query_var( $vars ) {
		$vars[] = 'acwpt_lang';
		return $vars;
	}

	/**
	 * Inject language-prefixed rewrite rules for every enabled language.
	 *
	 * For each existing WordPress rewrite rule we create a parallel rule with
	 * the language code prepended, e.g. ^es/about/?$ in addition to ^about/?$.
	 * This means /es/about/ is a genuine, distinct URL — host caches key to it
	 * correctly and there is no REQUEST_URI mutation.
	 */
	public function add_language_rewrite_rules( $rules ) {
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return $rules;
		}

		$lang_group = '(' . implode( '|', array_map( 'preg_quote', $enabled ) ) . ')';
		$new_rules  = array();

		// Add the homepage rule FIRST so it is evaluated before WordPress's
		// catch-all pagename rule ((.?.+?)(?:/([0-9]+))?/?$), which would
		// otherwise match /es/ as pagename "es", find no such page, and 404.
		$new_rules[ '^' . $lang_group . '/?$' ] = 'index.php?acwpt_lang=$matches[1]';

		foreach ( $rules as $regex => $redirect ) {
			$stripped = ltrim( $regex, '^' );

			if ( $stripped === '' || $stripped === '$' ) {
				// Homepage rule already added above — just preserve the original.
				$new_rules[ $regex ] = $redirect;
			} else {
				// Shift all $matches[N] indices up by 1 (the lang group becomes $matches[1]).
				$shifted = preg_replace_callback(
					'/\$matches\[(\d+)\]/',
					function( $m ) { return '$matches[' . ( (int) $m[1] + 1 ) . ']'; },
					$redirect
				);
				$new_rules[ '^' . $lang_group . '/' . $stripped ] = $shifted . '&acwpt_lang=$matches[1]';
				$new_rules[ $regex ] = $redirect;
			}
		}

		return $new_rules;
	}

	/**
	 * Detect the current language from the acwpt_lang query var set by rewrite rules.
	 * Runs on parse_request, after WordPress has matched the URL to its rewrite rules.
	 */
	public function detect_language_from_query( $wp ) {
		if ( is_admin() ) {
			return;
		}

		if ( empty( $wp->query_vars['acwpt_lang'] ) ) {
			return;
		}

		$lang    = $wp->query_vars['acwpt_lang'];
		$enabled = ACWPT_Languages::get_enabled_codes();

		if ( ! in_array( $lang, $enabled, true ) ) {
			return;
		}

		$this->current_language = $lang;

		// Remove from query vars so WP_Query doesn't see it as a public parameter.
		unset( $wp->query_vars['acwpt_lang'] );

		if ( defined( 'ACWPT_DEBUG' ) && ACWPT_DEBUG ) {
			error_log( 'ACWPT: Detected language ' . $this->current_language . ' via rewrite rule.' );
		}
	}

	private function get_home_path() {
		$home = home_url();
		$path = wp_parse_url( $home, PHP_URL_PATH );
		return $path ? rtrim( $path, '/' ) . '/' : '/';
	}

	/**
	 * Send an informational header identifying the active translation language.
	 * Translated pages now have distinct URLs via rewrite rules, so host caches
	 * key them correctly — no need to suppress caching.
	 */
	public function send_translated_page_headers() {
		if ( ! $this->current_language ) {
			return;
		}
		header( 'X-ACWPT-Language: ' . sanitize_text_field( $this->current_language ), true );
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

		$settings       = get_option( 'acwpt_settings', array() );
		$custom_version = isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0;
		$content_hash   = md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt . '||v' . $custom_version );
		$cached       = ACWPT_Cache::get( $post->ID, $this->current_language );

		if ( $cached && $cached->content_hash === $content_hash ) {
			$this->translations[ $post->ID ] = $cached;
			if ( defined( 'ACWPT_DEBUG' ) && ACWPT_DEBUG ) {
				error_log( 'ACWPT: Using cached translation for post ' . $post->ID . ' lang=' . $this->current_language );
			}
			return;
		}

		// STALE-WHILE-REVALIDATE: never translate post content inside the
		// visitor's request. This was a blocking call with a 60s timeout, so a
		// cold page could exhaust the host gateway budget before rendering a
		// single byte. Serve the source language now; queue the real translation.
		//
		// A stale cached row (content edited since it was translated) is still
		// better than untranslated source, so it is used until the refresh lands.
		if ( $cached ) {
			$this->translations[ $post->ID ] = $cached;
		}

		if ( class_exists( 'ACWPT_Preloader' ) ) {
			ACWPT_Preloader::start_for_post( $post->ID );
		}

		if ( defined( 'ACWPT_DEBUG' ) && ACWPT_DEBUG ) {
			error_log( 'ACWPT: post ' . $post->ID . ' lang=' . $this->current_language . ' not cached — queued for background translation' );
		}
	}

	// =========================================================================
	// Site-wide String Translation Cache
	// =========================================================================

	/**
	 * Persist string translations to the unbounded store.
	 */
	private function save_string_cache( $cache ) {
		$populated_at = isset( $cache['_populated_at'] ) ? $cache['_populated_at'] : null;
		unset( $cache['_populated_at'] );

		// Persist to the unbounded string store. The previous implementation kept
		// everything in a single option capped at 500 entries, which silently
		// evicted (and later re-billed) the oldest strings on any real site.
		if ( $cache ) {
			ACWPT_String_Store::set_many( $this->current_language, $cache );
		}

		if ( null !== $populated_at ) {
			$cache['_populated_at'] = $populated_at;
			update_option( 'acwpt_strings_meta_' . $this->current_language, array( '_populated_at' => $populated_at ), false );
		}

		$this->string_cache = $cache;
	}

	/**
	 * Load the string translation cache for the current language.
	 *
	 * Reads are now served per-string from ACWPT_String_Store, so this holds only
	 * the strings already resolved during THIS request rather than the whole site's
	 * translation set.
	 */
	private function load_string_cache() {
		if ( null === $this->string_cache ) {
			$this->string_cache = array();

			$meta = get_option( 'acwpt_strings_meta_' . $this->current_language, array() );
			if ( is_array( $meta ) && isset( $meta['_populated_at'] ) ) {
				$this->string_cache['_populated_at'] = (int) $meta['_populated_at'];
			}
		}
		return $this->string_cache;
	}

	/**
	 * Get a single string translation from cache.
	 */
	private function get_string_translation( $original ) {
		$cache = $this->load_string_cache();
		if ( isset( $cache[ $original ] ) ) {
			return $cache[ $original ];
		}

		$hit = ACWPT_String_Store::get( $this->current_language, $original );
		if ( null !== $hit ) {
			$this->string_cache[ $original ] = $hit;
			return $hit;
		}

		return null;
	}

	/**
	 * Pre-translate all site-wide strings in a single batch API call.
	 * Skips expensive DB queries if the cache was fully populated within the last hour.
	 */
	private function prepare_string_translations() {
		$cache = $this->load_string_cache();

		// If the cache was fully populated recently, skip the DB queries entirely.
		$populated_at = isset( $cache['_populated_at'] ) ? (int) $cache['_populated_at'] : 0;
		if ( $populated_at && ( time() - $populated_at ) < HOUR_IN_SECONDS ) {
			return;
		}

		$strings_needed = array();

		// Site title and tagline.
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

		// Nav menu items from all registered menus.
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

		// Page titles used in block nav (core/page-list).
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

		if ( ! empty( $strings_needed ) ) {
			// Resolve from the store, then defer any misses. This runs on `wp`,
			// inside the visitor's request, so it must never call the API.
			$found = ACWPT_String_Store::get_many( $this->current_language, array_values( $strings_needed ) );
			foreach ( $found as $source => $target ) {
				$cache[ $source ] = $target;
			}

			$missing = array();
			foreach ( $strings_needed as $s ) {
				if ( ! isset( $found[ $s ] ) ) {
					$missing[] = $s;
				}
			}

			if ( $missing ) {
				ACWPT_String_Queue::enqueue( $this->current_language, $missing );
			}
		}

		// Mark the cache as fully populated so DB queries are skipped for the next hour.
		$cache['_populated_at'] = time();
		$this->save_string_cache( $cache );
	}

	/**
	 * Clear all string translation caches (all languages).
	 */
	public function clear_all_string_caches() {
		$enabled = ACWPT_Languages::get_enabled_codes();
		foreach ( $enabled as $code ) {
			delete_option( 'acwpt_strings_' . $code );        // legacy option store
			delete_option( 'acwpt_strings_meta_' . $code );   // populated-at marker
			ACWPT_String_Store::flush( $code );
			ACWPT_String_Queue::clear( $code );
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
			$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( ACWPT_Languages::bcp47( $this->current_language ) ) . '"', $output );
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

	/**
	 * Prevent WordPress from redirecting /es/about/ back to /about/.
	 *
	 * WordPress's redirect_canonical() compares the request URI to the post
	 * permalink and redirects if they differ. On translated URLs the request URI
	 * always differs from the original permalink, so without this filter every
	 * translated page would 301 back to the source-language URL.
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( $this->current_language ) {
			return false;
		}
		return $redirect_url;
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
		$result = preg_replace_callback(
			'/<a\s[^>]*data-acwpt-lang[^>]*>.*?<\/a>/is',
			function( $m ) use ( &$protected_links ) {
				$placeholder = '<!--ACWPT_PROT_' . count( $protected_links ) . '-->';
				$protected_links[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);
		$html = $result !== null ? $result : $html;

		// 1. Translate meta tags (description, OG, Twitter).
		$result = $this->translate_meta_tags( $html );
		$html   = $result !== null ? $result : $html;

		// 2. Resolve visible strings from the store; queue misses for background
		//    translation. This no longer calls the API inline — see
		//    ensure_strings_cached_for_html() for why.
		$this->ensure_strings_cached_for_html( $html );
		$result = $this->translate_html_blob( $html );
		$html   = $result !== null ? $result : $html;

		// 3. Prefix all internal links with language code.
		$html = $this->prefix_internal_links( $html );

		// 4. Fix og:url to point to translated URL.
		$result = $this->fix_og_url( $html );
		$html   = $result !== null ? $result : $html;

		// Restore protected language switcher links.
		foreach ( $protected_links as $placeholder => $link ) {
			$html = str_replace( $placeholder, $link, $html );
		}

		// 5. Decide cacheability. A partially-translated page must NOT be cached,
		//    or the untranslated source text is frozen in the edge cache until the
		//    TTL expires. Only fully-resolved pages are marked cacheable.
		$this->mark_page_cacheability( $html );

		return $html;
	}

	/**
	 * Emit caching headers for a translated page.
	 *
	 * Translated URLs are real URLs backed by real rewrite rules, so once a page
	 * is fully translated the host and CDN can cache it like any other page. Until
	 * then it is explicitly marked no-store so a half-English render cannot stick.
	 *
	 * @param string $html Final page HTML.
	 */
	private function mark_page_cacheability( $html ) {
		if ( headers_sent() ) {
			return;
		}

		$pending = ACWPT_String_Queue::pending( $this->current_language );
		$full    = ( 0 === $pending ) && $this->page_fully_translated( $html );

		if ( $full ) {
			header( 'X-ACWPT-Cacheable: 1', true );
			header( 'X-ACWPT-Translation: complete', true );
			return;
		}

		header( 'X-ACWPT-Cacheable: 0', true );
		header( 'X-ACWPT-Translation: partial', true );
		// Keep the edge from freezing a partially translated render.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
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
				// Defer: a synchronous per-tag API call here added up to six more
				// blocking round-trips to every cold page render.
				ACWPT_String_Queue::enqueue( $this->current_language, array( $original ) );
			}
			if ( $translated && $translated !== $original ) {
				$tag = str_replace( $cm[1], esc_attr( $translated ), $tag );
			}
		}
		return $tag;
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
		if ( preg_match( '/^https?:/', $text ) || preg_match( '/[{}<>]/', $text ) ) {
			return $m[0];
		}
		$translated = $this->get_string_translation( $text );
		if ( $translated ) {
			return $m[1] . str_replace( $text, $translated, $m[2] ) . $m[3];
		}
		return $m[0];
	}

	// =========================================================================
	// Elementor: translate builder output (Elementor bypasses the_content)
	// =========================================================================

	/**
	 * Filter Elementor's frontend HTML so all visible text is translated.
	 */
	public function translate_elementor_content( $content ) {
		if ( ! $this->current_language || empty( $content ) ) {
			return $content;
		}
		$this->ensure_strings_cached_for_html( $content );
		return $this->translate_html_blob( $content );
	}

	/**
	 * Resolve every translatable string in the page against the store, and hand
	 * anything still missing to the background queue.
	 *
	 * STALE-WHILE-REVALIDATE: this method never calls the translation API. It
	 * previously issued one synchronous API call per 40 missing strings while the
	 * visitor's request was held open, which on a page with a few hundred
	 * untranslated strings exceeded the host gateway timeout and returned a 502
	 * (measured: 6 sequential calls, 54s, against WP Engine's 60s ceiling).
	 *
	 * The first view of a cold page now renders the source language for whatever
	 * is not yet translated and completes in normal page-load time; the queue
	 * fills in the rest for subsequent views.
	 *
	 * @param string $html Full page HTML.
	 */
	private function ensure_strings_cached_for_html( $html ) {
		$strings = $this->extract_translatable_strings_from_html( $html );
		if ( empty( $strings ) ) {
			return;
		}

		$strings = array_values( array_unique( $strings ) );

		// One indexed query for the whole page instead of an option blob.
		$found = ACWPT_String_Store::get_many( $this->current_language, $strings );

		$cache = $this->load_string_cache();
		foreach ( $found as $source => $target ) {
			$cache[ $source ] = $target;
		}
		$this->string_cache = $cache;

		$missing = array();
		foreach ( $strings as $s ) {
			if ( ! isset( $found[ $s ] ) ) {
				$missing[] = $s;
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		// Defer. Never block the visitor on an API call.
		ACWPT_String_Queue::enqueue( $this->current_language, $missing );

		if ( defined( 'ACWPT_DEBUG' ) && ACWPT_DEBUG ) {
			error_log( sprintf(
				'ACWPT: %d/%d strings cached for %s; %d queued for background translation.',
				count( $found ),
				count( $strings ),
				$this->current_language,
				count( $missing )
			) );
		}
	}

	/**
	 * Is this page fully translated?
	 *
	 * Used to decide whether the response may be cached by the host/CDN. Caching a
	 * partially-translated page would freeze the untranslated source text in place
	 * until the cache expired, so only fully-resolved pages are cacheable.
	 *
	 * @param string $html Full page HTML.
	 * @return bool
	 */
	private function page_fully_translated( $html ) {
		$strings = $this->extract_translatable_strings_from_html( $html );
		if ( empty( $strings ) ) {
			return true;
		}

		$strings = array_values( array_unique( $strings ) );
		$found   = ACWPT_String_Store::get_many( $this->current_language, $strings );

		return count( $found ) >= count( $strings );
	}

	/**
	 * Extract text from links and common block elements (for translation collection).
	 */
	private function extract_translatable_strings_from_html( $html ) {
		$out = array();
		// Link text.
		if ( preg_match_all( '/(<a\b[^>]*>)([^<]+)(<\/a>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = trim( $match[2] );
				if ( strlen( $text ) >= 2 && ! preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
					$out[] = $text;
				}
			}
		}
		// Block/text elements (p, span, div, headings, li, td, th, label, figcaption, button, strong, em, b, dt, dd, blockquote, cite, caption).
		if ( preg_match_all( '/(<(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = trim( $match[2] );
				if ( strlen( $text ) >= 2 && ! preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
					if ( ! preg_match( '/^https?:/', $text ) && ! preg_match( '/[{}<>]/', $text ) ) {
						$out[] = $text;
					}
				}
			}
		}
		// Leading text in block elements — text that appears directly after the opening tag but
		// before a child inline tag or <br>. Catches things like "Here's how " before a <span>.
		if ( preg_match_all( '/(?<=>)([^<]{3,})(?=<(?:span|strong|em|a|br|i|b|small|sup|sub)\b)/u', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$text = trim( $text );
				if ( strlen( $text ) >= 2
					&& ! preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					&& ! preg_match( '/^https?:/', $text )
					&& ! preg_match( '/[{}<>]/', $text ) ) {
					$out[] = $text;
				}
			}
		}
		// Trailing text in block elements — text that appears after a closing inline tag but
		// before the closing block tag. Catches ". Builds sites that work reliably." after </span>.
		if ( preg_match_all( '/(?:<\/(?:span|strong|em|a|i|b|small|sup|sub)>)([^<]{3,})(?=<\/(?:p|div|h[1-6]|li|td|th|label|figcaption|button|dt|dd|blockquote)>)/u', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$text = trim( $text );
				if ( strlen( $text ) >= 2
					&& ! preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					&& ! preg_match( '/^https?:/', $text )
					&& ! preg_match( '/[{}<>]/', $text ) ) {
					$out[] = $text;
				}
			}
		}
		// Text after <br> tags and before the closing block tag.
		if ( preg_match_all( '/(?:<br\s*\/?>)([^<]{3,})(?=<\/(?:p|div|h[1-6]|li|td|th|label|figcaption|button|dt|dd|blockquote)>)/u', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$text = trim( $text );
				if ( strlen( $text ) >= 2
					&& ! preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					&& ! preg_match( '/^https?:/', $text )
					&& ! preg_match( '/[{}<>]/', $text ) ) {
					$out[] = $text;
				}
			}
		}
		// Form placeholder attributes.
		if ( preg_match_all( '/\bplaceholder="([^"]{2,})"/i', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$out[] = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			}
		}
		// Submit button value attributes.
		if ( preg_match_all( '/<input\b[^>]*\btype=["\']submit["\'][^>]*\bvalue="([^"]{2,})"/i', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$out[] = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( preg_match_all( '/<input\b[^>]*\bvalue="([^"]{2,})"[^>]*\btype=["\']submit["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$out[] = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			}
		}
		return array_unique( $out );
	}

	/**
	 * Run link and element translation over an HTML blob (uses string cache).
	 */
	private function translate_html_blob( $html ) {
		// Translate <a> link text.
		$html = preg_replace_callback(
			'/(<a\b[^>]*>)([^<]+)(<\/a>)/i',
			array( $this, 'translate_link_text_callback' ),
			$html
		);
		// Translate text in block elements (same set as extract).
		$html = preg_replace_callback(
			'/(<(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)>)/i',
			array( $this, 'translate_element_text_callback' ),
			$html
		);
		// Translate leading text nodes in block elements (text before a child inline tag or <br>).
		$html = preg_replace_callback(
			'/(?<=>)([^<]{3,})(?=<(?:span|strong|em|a|br|i|b|small|sup|sub)\b)/u',
			function ( $m ) {
				$raw  = $m[1];
				$text = trim( $raw );
				if ( strlen( $text ) < 2
					|| preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					|| preg_match( '/^https?:/', $text )
					|| preg_match( '/[{}<>]/', $text ) ) {
					return $m[0];
				}
				$translated = $this->get_string_translation( $text );
				if ( $translated ) {
					return str_replace( $text, $translated, $raw );
				}
				return $m[0];
			},
			$html
		);
		// Translate trailing text nodes in block elements (text after a closing inline tag).
		$html = preg_replace_callback(
			'/(<\/(?:span|strong|em|a|i|b|small|sup|sub)>)([^<]{3,})(<\/(?:p|div|h[1-6]|li|td|th|label|figcaption|button|dt|dd|blockquote)>)/u',
			function ( $m ) {
				$raw  = $m[2];
				$text = trim( $raw );
				if ( strlen( $text ) < 2
					|| preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					|| preg_match( '/^https?:/', $text )
					|| preg_match( '/[{}<>]/', $text ) ) {
					return $m[0];
				}
				$translated = $this->get_string_translation( $text );
				if ( $translated ) {
					return $m[1] . str_replace( $text, $translated, $raw ) . $m[3];
				}
				return $m[0];
			},
			$html
		);
		// Translate text after <br> tags (before the closing block tag).
		$html = preg_replace_callback(
			'/(<br\s*\/?>)([^<]{3,})(<\/(?:p|div|h[1-6]|li|td|th|label|figcaption|button|dt|dd|blockquote)>)/u',
			function ( $m ) {
				$raw  = $m[2];
				$text = trim( $raw );
				if ( strlen( $text ) < 2
					|| preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					|| preg_match( '/^https?:/', $text )
					|| preg_match( '/[{}<>]/', $text ) ) {
					return $m[0];
				}
				$translated = $this->get_string_translation( $text );
				if ( $translated ) {
					return $m[1] . str_replace( $text, $translated, $raw ) . $m[3];
				}
				return $m[0];
			},
			$html
		);
		// Translate form placeholder attributes.
		$html = preg_replace_callback(
			'/\bplaceholder="([^"]{2,})"/i',
			function ( $m ) {
				$original   = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				$translated = $this->get_string_translation( $original );
				return $translated ? 'placeholder="' . esc_attr( $translated ) . '"' : $m[0];
			},
			$html
		);
		// Translate submit button value attributes.
		$html = preg_replace_callback(
			'/<input\b([^>]*)\bvalue="([^"]{2,})"([^>]*)>/i',
			function ( $m ) {
				if ( ! preg_match( '/\btype=["\']submit["\']/i', $m[0] ) ) {
					return $m[0];
				}
				$original   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				$translated = $this->get_string_translation( $original );
				if ( $translated ) {
					return '<input' . $m[1] . 'value="' . esc_attr( $translated ) . '"' . $m[3] . '>';
				}
				return $m[0];
			},
			$html
		);
		return $html;
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
		echo '<link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $source ) ) . '" href="' . esc_url( $original_url ) . '" />' . "\n";

		foreach ( $enabled as $code ) {
			$url = $this->get_translated_url( $code, $post );
			echo '<link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $code ) ) . '" href="' . esc_url( $url ) . '" />' . "\n";
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

		// Strip language prefix — REQUEST_URI is no longer mutated by the plugin,
		// so /es/about/ needs to be normalised to /about/ for switcher URL generation.
		if ( $this->current_language ) {
			$prefix = '/' . $this->current_language . '/';
			if ( strpos( $path, $prefix ) === 0 ) {
				$path = '/' . substr( $path, strlen( $prefix ) );
			} elseif ( $path === '/' . $this->current_language || $path === '/' . $this->current_language . '/' ) {
				$path = '/';
			}
		}

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

		wp_enqueue_style( 'acwpt-frontend', ACWPT_PLUGIN_URL . 'assets/css/frontend.css', array(), acwpt_asset_version( 'assets/css/frontend.css' ) );

		$show_suggestion = isset( $settings['show_suggestion'] ) ? (bool) $settings['show_suggestion'] : true;

		if ( $show_suggestion && ! $this->current_language ) {
			wp_enqueue_script( 'acwpt-detect', ACWPT_PLUGIN_URL . 'assets/js/detect.js', array(), acwpt_asset_version( 'assets/js/detect.js' ), true );

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

	/**
	 * Output debug script on translated pages (always, so console shows language state without ACWPT_DEBUG).
	 */
	public function debug_console_log() {
		if ( ! $this->current_language || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$queried = get_queried_object();
		$post_id = ( $queried && $queried instanceof WP_Post ) ? $queried->ID : 0;
		$has_translation = $post_id && isset( $this->translations[ $post_id ] );
		$info = array(
			'currentLanguage'  => $this->current_language,
			'queriedPostId'    => $post_id,
			'hasTranslation'  => $has_translation,
			'translationCount' => count( $this->translations ),
		);
		echo '<script>if(typeof console!=="undefined"&&console.log){console.log("[ACWPT]", ' . wp_json_encode( $info ) . ');}</script>' . "\n";
	}

	// =========================================================================
	// Cache Invalidation
	// =========================================================================

	public function invalidate_post_cache( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		ACWPT_Cache::delete_post( $post_id );

		// Only clear string caches for pages — they appear in nav menus and page lists.
		// Regular post saves don't affect nav text so there's no need to re-translate strings.
		if ( $post->post_type === 'page' ) {
			$this->clear_all_string_caches();
		}

		// Invalidate the sitemap cache.
		delete_transient( 'acwpt_sitemap_xml' );

		// Auto-preload: queue background translation for this post if enabled.
		if ( $post->post_status === 'publish' ) {
			$settings = get_option( 'acwpt_settings', array() );
			if ( ! empty( $settings['preload_auto'] ) ) {
				ACWPT_Preloader::start_for_post( $post_id );
			}
		}
	}

	// =========================================================================
	// Multilingual Sitemap
	// =========================================================================

	/**
	 * Intercept requests for /acwpt-sitemap.xml and serve the sitemap.
	 */
	public function maybe_serve_sitemap( $wp ) {
		if ( ! isset( $wp->request ) || $wp->request !== 'acwpt-sitemap.xml' ) {
			return;
		}

		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return; // Let WordPress 404 normally.
		}

		// Serve from cache if available.
		$xml = get_transient( 'acwpt_sitemap_xml' );
		if ( ! $xml ) {
			$xml = $this->generate_sitemap_xml();
			set_transient( 'acwpt_sitemap_xml', $xml, HOUR_IN_SECONDS );
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml;
		exit;
	}

	/**
	 * Add sitemap URL to robots.txt.
	 */
	public function add_sitemap_to_robots( $output, $public ) {
		if ( $public ) {
			$enabled = ACWPT_Languages::get_enabled_codes();
			if ( ! empty( $enabled ) ) {
				$output .= "\nSitemap: " . home_url( '/acwpt-sitemap.xml' ) . "\n";
			}
		}
		return $output;
	}

	/**
	 * Generate the multilingual sitemap XML with hreflang annotations.
	 *
	 * Each published post/page gets a <url> entry for every language version.
	 * Every entry includes <xhtml:link> alternates pointing to all language
	 * versions plus x-default (the source language URL).
	 */
	private function generate_sitemap_xml() {
		$enabled = ACWPT_Languages::get_enabled_codes();
		$source  = ACWPT_Languages::get_source();
		$home    = home_url();

		$posts = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			$lastmod   = get_post_modified_time( 'c', true, $post );

			$relative  = str_replace( $home, '', $permalink );
			$relative  = '/' . ltrim( $relative, '/' );

			// Build URLs for all language versions.
			$lang_urls            = array();
			$lang_urls[ $source ] = $permalink;
			foreach ( $enabled as $code ) {
				$lang_urls[ $code ] = home_url( '/' . $code . $relative );
			}

			// A <url> block for each language version.
			foreach ( $lang_urls as $lang => $url ) {
				$xml .= "  <url>\n";
				$xml .= '    <loc>' . esc_url( $url ) . "</loc>\n";
				if ( $lastmod ) {
					$xml .= '    <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
				}
				// Hreflang alternates (every version, including self).
				foreach ( $lang_urls as $alt_lang => $alt_url ) {
					$xml .= '    <xhtml:link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $alt_lang ) ) . '" href="' . esc_url( $alt_url ) . '" />' . "\n";
				}
				$xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $permalink ) . '" />' . "\n";
				$xml .= "  </url>\n";
			}
		}

		$xml .= '</urlset>';

		return $xml;
	}
}
