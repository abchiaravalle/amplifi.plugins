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

		// Optional floating switcher — the only discovery path on a site whose
		// theme has nowhere to place the shortcode.
		add_action( 'wp_footer', array( $this, 'maybe_render_floating_switcher' ), 99 );

		// Language switcher as nav menu item.
		add_filter( 'wp_nav_menu_objects', array( $this, 'expand_language_menu_items' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'add_lang_link_attributes' ), 10, 4 );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Invalidate caches when posts are saved.
		add_action( 'save_post', array( $this, 'invalidate_post_cache' ), 10, 2 );

		// Clear string cache when site title/tagline changes.
		// Site title/tagline are two strings, not the whole site. Retire just
		// those rather than flushing every language's store.
		add_action( 'update_option_blogname', array( $this, 'invalidate_site_identity_strings' ), 10, 2 );
		add_action( 'update_option_blogdescription', array( $this, 'invalidate_site_identity_strings' ), 10, 2 );

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

		// SEO PLUGINS OWN THE <head>, so WordPress core filters are not enough.
		//
		// Yoast, RankMath and AIOSEO each render their own canonical, title,
		// description and Open Graph tags and never consult get_canonical_url
		// or document_title_parts. On this site that shipped three real SEO
		// defects on every translated page:
		//
		//   - canonical pointed at an unrelated EN page, which tells Google to
		//     index that page INSTEAD of the translation. Self-cancelling
		//     against the hreflang cluster, and the single most damaging of the
		//     three.
		//   - <title> stayed English while the body was translated, so the SERP
		//     entry a German buyer sees is in the wrong language.
		//   - og:locale said en_US on every language, mislabelling shares.
		//
		// Hook each vendor's own filters so the translated values win.
		add_filter( 'wpseo_canonical', array( $this, 'filter_seo_canonical' ), 20 );
		add_filter( 'wpseo_opengraph_url', array( $this, 'filter_seo_canonical' ), 20 );
		add_filter( 'wpseo_title', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_opengraph_title', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_twitter_title', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_twitter_description', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'wpseo_locale', array( $this, 'filter_seo_locale' ), 20 );

		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_seo_canonical' ), 20 );
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'rank_math/opengraph/url', array( $this, 'filter_seo_canonical' ), 20 );

		add_filter( 'aioseo_canonical_url', array( $this, 'filter_seo_canonical' ), 20 );
		add_filter( 'aioseo_title', array( $this, 'filter_seo_text' ), 20 );
		add_filter( 'aioseo_description', array( $this, 'filter_seo_text' ), 20 );

		// Prevent WordPress redirect_canonical from redirecting /es/blog/ → /blog/.
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 10, 2 );

		// Keep a missing translated URL inside its own language.
		add_action( 'template_redirect', array( $this, 'handle_language_404' ), 1 );

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
	public function get_string_translation( $original ) {
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
	/**
	 * Retire the stored translations of the site title/tagline only.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function invalidate_site_identity_strings( $old, $new ) {
		if ( ! is_string( $old ) || '' === $old || $old === $new ) {
			return;
		}
		if ( class_exists( 'ACWPT_String_Store' ) ) {
			ACWPT_String_Store::forget( $old );
		}
		$this->string_cache = null;
	}

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
	 * Point an SEO plugin's canonical at the CURRENT language's URL.
	 *
	 * A translated page whose canonical points at the English URL is telling
	 * Google "index that one instead of me". Combined with an hreflang cluster
	 * saying the opposite, the two signals cancel and the translation may not
	 * be indexed at all. This is the most damaging of the three head defects.
	 *
	 * @param string $url Canonical URL the SEO plugin computed.
	 * @return string
	 */
	public function filter_seo_canonical( $url ) {
		if ( $this->current_language === ACWPT_Languages::get_source() ) {
			return $url;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		// Already language-prefixed (hand-set canonical): leave it.
		if ( false !== strpos( $url, '/' . $this->current_language . '/' ) ) {
			return $url;
		}

		$home = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url; // Off-site canonical: not ours to rewrite.
		}

		$path = substr( $url, strlen( $home ) );
		return $home . '/' . $this->current_language . ( '' === $path ? '/' : $path );
	}

	/**
	 * Translate an SEO plugin's title / description / Open Graph text.
	 *
	 * These strings never appear in the page body, so the content pass never
	 * sees them. A translated page kept an English <title>, which is what a
	 * searcher actually reads in the results list.
	 *
	 * Cache-only by design: this runs while rendering <head>, so it must never
	 * block on an API call. A miss is queued and served translated next time.
	 *
	 * @param string $text
	 * @return string
	 */
	public function filter_seo_text( $text ) {
		if ( $this->current_language === ACWPT_Languages::get_source() ) {
			return $text;
		}
		if ( ! is_string( $text ) || strlen( trim( $text ) ) < 2 ) {
			return $text;
		}

		$translated = $this->get_string_translation( $text );
		return $translated ? $translated : $text;
	}

	/**
	 * Report the correct locale to an SEO plugin's Open Graph output.
	 *
	 * og:locale stayed en_US on every translated page, so a share of the German
	 * page announced itself as American English.
	 *
	 * @param string $locale
	 * @return string
	 */
	public function filter_seo_locale( $locale ) {
		if ( $this->current_language === ACWPT_Languages::get_source() ) {
			return $locale;
		}
		return ACWPT_Languages::og_locale( $this->current_language );
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

	/**
	 * Send a missing translated URL to that language's home, not the English one.
	 *
	 * Measured before this fix: /de/nonexistent-page/ returned 301 to the site
	 * root. Two problems with that. It is a soft 404 — Google treats
	 * redirect-to-home for missing content as a quality signal against the
	 * whole property, and the URL can linger in the index. And it dumps a
	 * German visitor onto an English page, which is a worse outcome than a
	 * 404 for a buyer who arrived from a German search result.
	 *
	 * 302 rather than 301 because the target is not a permanent replacement for
	 * the requested URL; the content may appear later, and a 301 would be
	 * cached by browsers and intermediaries indefinitely.
	 */
	public function handle_language_404() {
		if ( ! is_404() || ! $this->current_language ) {
			return;
		}
		if ( $this->current_language === ACWPT_Languages::get_source() ) {
			return;
		}

		$target = home_url( '/' . $this->current_language . '/' );

		// Never redirect the language home to itself.
		$current = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( untrailingslashit( $current ) === untrailingslashit( wp_parse_url( $target, PHP_URL_PATH ) ) ) {
			return;
		}

		/**
		 * Filter the destination for a missing translated URL.
		 *
		 * Return false to keep WordPress's own 404 handling, which is the
		 * better choice for a site that has a designed 404 template per
		 * language.
		 *
		 * @param string $target Language home URL.
		 * @param string $lang   Current language code.
		 */
		$target = apply_filters( 'acwpt_language_404_redirect', $target, $this->current_language );
		if ( ! $target ) {
			return;
		}

		wp_safe_redirect( $target, 302 );
		exit;
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

		// 4b. Translate JSON-LD structured data.
		//
		// Runs after the SEO plugin has rendered its graph and after link
		// prefixing, so it sees the final markup. Every translated page was
		// emitting inLanguage "en-US", contradicting <html lang>, hreflang and
		// og:locale — schema is what search engines and LLM answer engines read
		// to decide what a page is and who it serves.
		if ( class_exists( 'ACWPT_Schema' ) ) {
			$html = ACWPT_Schema::filter_html( $html, $this->current_language );
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
		$text = $this->normalize_candidate( $m[2] );
		if ( strlen( $text ) < 2 || preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
			return $m[0];
		}
		$translated = $this->get_string_translation( $text );
		if ( $translated ) {
			return $m[1] . $translated . $m[3];
		}
		return $m[0];
	}

	/**
	 * Callback: translate text inside an element.
	 */
	public function translate_element_text_callback( $m ) {
		$raw  = $m[2];
		$text = $this->normalize_candidate( $raw );
		if ( strlen( $text ) < 2 || preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
			return $m[0];
		}
		// Skip if it looks like code or a URL.
		if ( preg_match( '/^https?:/', $text ) || preg_match( '/[{}<>]/', $text ) ) {
			return $m[0];
		}
		$translated = $this->get_string_translation( $text );
		if ( $translated ) {
			// Replace the RAW matched text, not the normalised key: the source
			// may carry entities or padding that must not survive substitution.
			return $m[1] . $translated . $m[3];
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
	 * Normalise a candidate string lifted out of raw HTML.
	 *
	 * Entities must be decoded BEFORE the text reaches the model. The extractors
	 * were inconsistent: the leading/trailing-text passes decoded, but the two
	 * main ones (link text and block elements) did not, so strings like
	 * "Actuation &amp; landing gear test" were sent escaped. The model
	 * faithfully preserves the entity, it gets stored that way, and the page
	 * renders a literal "&amp;". Measured on the review set: 9 outputs across
	 * 5 language/model pairs carried &amp; or &#8211; straight through.
	 *
	 * Decoding here also means the store is keyed on the decoded form, so the
	 * same visible sentence is one cache entry rather than several.
	 *
	 * @param string $text Raw inner text from the HTML.
	 * @return string
	 */
	private function normalize_candidate( $text ) {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse the whitespace a page builder leaves between tags; a string
		// differing only by indentation must not become a second cache entry.
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Extract text from links and common block elements (for translation collection).
	 */
	private function extract_translatable_strings_from_html( $html ) {
		$out = array();
		// Link text.
		if ( preg_match_all( '/(<a\b[^>]*>)([^<]+)(<\/a>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = $this->normalize_candidate( $match[2] );
				if ( strlen( $text ) >= 2 && ! preg_match( '/^[\d\s\.\-:\/]+$/', $text ) ) {
					$out[] = $text;
				}
			}
		}
		// Block/text elements (p, span, div, headings, li, td, th, label, figcaption, button, strong, em, b, dt, dd, blockquote, cite, caption).
		if ( preg_match_all( '/(<(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption)>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = $this->normalize_candidate( $match[2] );
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

		// Protect <link rel="alternate" hreflang> and <link rel="canonical">.
		//
		// These carry DELIBERATE, language-specific URLs: x-default and the
		// source-language alternate must keep pointing at the untranslated
		// original. Blind href rewriting prefixed them with the current
		// language, so every translated page told Google "the English version
		// of this page lives at /de/" — a same-URL conflict across all
		// alternates, which invalidates the whole hreflang cluster.
		$protected = array();
		$html      = preg_replace_callback(
			'/<link\b[^>]*\brel=["\'](?:alternate|canonical)["\'][^>]*>/i',
			function ( $m ) use ( &$protected ) {
				$key               = '<!--ACWPT_SEO_' . count( $protected ) . '-->';
				$protected[ $key ] = $m[0];
				return $key;
			},
			$html
		);

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

		// Restore the SEO tags untouched.
		foreach ( $protected as $key => $tag ) {
			$html = str_replace( $key, $tag, $html );
		}

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
		$source  = ACWPT_Languages::get_source();
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return;
		}

		// A page excluded from the sitemap must ALSO carry noindex.
		//
		// Removing a URL from a sitemap does not deindex it — Google reaches
		// pages by crawling links, and the language switcher plus internal link
		// prefixing give every translated page inbound links. Without this, an
		// in-progress rebuild stays indexable in eleven languages even after it
		// is dropped from the sitemap, which is exactly the false sense of
		// safety the exclusion list would otherwise create.
		$post = get_queried_object();
		if ( is_singular() && $post instanceof WP_Post
			&& function_exists( 'acwpt_include_in_sitemap' )
			&& ! acwpt_include_in_sitemap( $post ) ) {
			echo '<meta name="robots" content="noindex, follow" />' . "\n";
			return; // No hreflang cluster for a page that must not be indexed.
		}

		// Resolve the SOURCE-language URL for whatever is being viewed.
		//
		// This previously bailed unless is_singular(), so archives, the blog
		// index, taxonomy and search pages emitted no hreflang at all — on this
		// site that is /events/, /catalog/, /resource-hub/ and every category,
		// i.e. a large share of indexable URLs left with no language signal.
		if ( is_singular() && $post instanceof WP_Post ) {
			$original_url = get_permalink( $post );
		} else {
			// Strip any language prefix from the current request to recover the
			// canonical source URL.
			$path = $this->get_current_page_path();
			$original_url = home_url( $path );
			$post = null;
		}

		if ( ! $original_url ) {
			return;
		}

		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $original_url ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $source ) ) . '" href="' . esc_url( $original_url ) . '" />' . "\n";

		foreach ( $enabled as $code ) {
			$url = $post ? $this->get_translated_url( $code, $post ) : $this->get_translated_url( $code );
			echo '<link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $code ) ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	// =========================================================================
	// Language Switcher Shortcode
	// =========================================================================

	public function render_switcher( $atts ) {
		$atts = shortcode_atts(
			array(
				'style' => 'select',   // select | inline
			),
			$atts,
			'acwpt_switcher'
		);

		return 'inline' === $atts['style']
			? $this->switcher_markup( 'inline' )
			: $this->switcher_markup( 'select' );
	}

	/**
	 * Build the switcher markup.
	 *
	 * @param string $style select|inline|floating
	 * @return string
	 */
	private function switcher_markup( $style ) {
		$enabled = ACWPT_Languages::get_enabled();
		if ( empty( $enabled ) ) {
			return '';
		}

		$source   = ACWPT_Languages::get_source();
		$current  = $this->current_language ? $this->current_language : $source;
		$path     = $this->get_current_page_path();
		$settings = get_option( 'acwpt_settings', array() );
		$flags    = ! isset( $settings['show_flags'] ) || ! empty( $settings['show_flags'] );

		// Source first, then each target, so the list order is stable.
		$options = array( $source => home_url( $path ) );
		foreach ( array_keys( $enabled ) as $code ) {
			$options[ $code ] = home_url( '/' . $code . $path );
		}

		if ( 'select' === $style ) {
			$html  = '<div class="acwpt-switcher-wrap">';
			$html .= '<select class="acwpt-switcher" onchange="if(this.value)window.location.href=this.value;">';
			foreach ( $options as $code => $url ) {
				$html .= '<option value="' . esc_url( $url ) . '"' . selected( $current, $code, false ) . '>'
					. esc_html( ACWPT_Languages::label( $code ) ) . '</option>';
			}
			$html .= '</select></div>';
			return $html;
		}

		// Inline / floating share the same list markup.
		$classes = 'acwpt-switcher-list' . ( 'floating' === $style ? ' acwpt-switcher-floating' : '' );

		$html = '<nav class="' . esc_attr( $classes ) . '" aria-label="Language">';
		if ( 'floating' === $style ) {
			$html .= '<button type="button" class="acwpt-switcher-toggle" aria-expanded="false" aria-haspopup="true">'
				. ( $flags ? '<span class="acwpt-flag">' . esc_html( ACWPT_Languages::flag( $current ) ) . '</span>' : '' )
				. '<span class="acwpt-code">' . esc_html( strtoupper( $current ) ) . '</span>'
				. '</button>';
		}
		$html .= '<ul class="acwpt-switcher-items">';
		foreach ( $options as $code => $url ) {
			$is_current = ( $code === $current );
			$html      .= '<li class="acwpt-switcher-item' . ( $is_current ? ' is-current' : '' ) . '">'
				. '<a href="' . esc_url( $url ) . '" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $code ) ) . '"'
				. ( $is_current ? ' aria-current="true"' : '' ) . ' data-acwpt-lang="' . esc_attr( $code ) . '">'
				. ( $flags ? '<span class="acwpt-flag">' . esc_html( ACWPT_Languages::flag( $code ) ) . '</span> ' : '' )
				. esc_html( ACWPT_Languages::name( $code ) )
				. '</a></li>';
		}
		$html .= '</ul></nav>';

		return $html;
	}

	/**
	 * Inject a floating switcher into the footer.
	 *
	 * A shortcode or a menu item both require someone to place them. On a site
	 * with ten languages the switcher is the only way a visitor discovers the
	 * translations exist at all, so it can be turned on without touching the
	 * theme.
	 */
	public function maybe_render_floating_switcher() {
		if ( is_admin() ) {
			return;
		}

		$settings = get_option( 'acwpt_settings', array() );
		if ( empty( $settings['floating_switcher'] ) ) {
			return;
		}

		$markup = $this->switcher_markup( 'floating' );
		if ( ! $markup ) {
			return;
		}

		$position = isset( $settings['floating_switcher_position'] ) ? $settings['floating_switcher_position'] : 'bottom-right';
		$allowed  = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
		if ( ! in_array( $position, $allowed, true ) ) {
			$position = 'bottom-right';
		}

		echo '<div class="acwpt-floating-root acwpt-pos-' . esc_attr( $position ) . '">' . $markup . '</div>';
		$this->print_switcher_styles();
	}

	/**
	 * Styles for the floating switcher.
	 *
	 * Inlined deliberately: one small block on pages that use it beats an extra
	 * blocking request, and it cannot be lost to a theme's asset pipeline.
	 */
	private function print_switcher_styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
<style id="acwpt-switcher-css">
.acwpt-floating-root{position:fixed;z-index:99999}
.acwpt-pos-bottom-right{right:20px;bottom:20px}
.acwpt-pos-bottom-left{left:20px;bottom:20px}
.acwpt-pos-top-right{right:20px;top:20px}
.acwpt-pos-top-left{left:20px;top:20px}
.acwpt-switcher-floating{position:relative;font-family:inherit;font-size:14px;line-height:1}
.acwpt-switcher-toggle{display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid rgba(0,0,0,.12);border-radius:999px;background:#fff;color:#111;cursor:pointer;box-shadow:0 2px 12px rgba(0,0,0,.12);font:inherit}
.acwpt-switcher-toggle:hover{border-color:rgba(0,0,0,.28)}
.acwpt-switcher-floating .acwpt-switcher-items{position:absolute;right:0;bottom:calc(100% + 10px);min-width:190px;max-height:60vh;overflow-y:auto;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.16);opacity:0;visibility:hidden;transform:translateY(6px);transition:opacity .16s,transform .16s,visibility .16s}
.acwpt-pos-top-right .acwpt-switcher-floating .acwpt-switcher-items,
.acwpt-pos-top-left .acwpt-switcher-floating .acwpt-switcher-items{top:calc(100% + 10px);bottom:auto}
.acwpt-pos-bottom-left .acwpt-switcher-floating .acwpt-switcher-items,
.acwpt-pos-top-left .acwpt-switcher-floating .acwpt-switcher-items{left:0;right:auto}
.acwpt-switcher-floating.is-open .acwpt-switcher-items{opacity:1;visibility:visible;transform:translateY(0)}
.acwpt-switcher-items li{margin:0}
.acwpt-switcher-items a{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:8px;color:#111;text-decoration:none;white-space:nowrap}
.acwpt-switcher-items a:hover{background:rgba(0,0,0,.06)}
.acwpt-switcher-item.is-current a{font-weight:600;background:rgba(0,0,0,.05)}
.acwpt-flag{font-size:16px}
@media (prefers-color-scheme:dark){
.acwpt-switcher-toggle,.acwpt-switcher-floating .acwpt-switcher-items{background:#1c1c1e;color:#f2f2f7;border-color:rgba(255,255,255,.16)}
.acwpt-switcher-items a{color:#f2f2f7}
.acwpt-switcher-items a:hover{background:rgba(255,255,255,.08)}
}
</style>
<script id="acwpt-switcher-js">
(function(){
  var root = document.querySelector('.acwpt-switcher-floating');
  if (!root) { return; }
  var btn = root.querySelector('.acwpt-switcher-toggle');
  if (!btn) { return; }
  function close(){ root.classList.remove('is-open'); btn.setAttribute('aria-expanded','false'); }
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var open = root.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function(e){ if (!root.contains(e.target)) { close(); } });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { close(); } });
})();
</script>
		<?php
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

		// Ignore saves that cannot change public output.
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}

		// Post-level translations are keyed by a content hash, so a stale row is
		// simply not used. Dropping this post's rows is precise and cheap.
		ACWPT_Cache::delete_post( $post_id );

		// NEVER flush the string store here.
		//
		// This previously called clear_all_string_caches() for any page save. In
		// the old capped-option design that discarded at most 500 strings; against
		// the unbounded store it deletes EVERY translated string for EVERY
		// language. Observed on staging: one page save destroyed 12,862 Polish
		// strings — roughly $8 of billed translation — and left the site rendering
		// English until a full re-run.
		//
		// The correct unit of invalidation is the STRING, not the site. A stored
		// string stays valid until its own source text changes, so we only retire
		// strings that this post actually contributed and that no longer appear in
		// its rendered output. That work needs the rendered page, so it happens in
		// the background rather than blocking the editor's save request.
		ACWPT_String_Store::mark_post_dirty( $post_id );
		ACWPT_Preloader::schedule_reconcile();

		// The sitemap lists this post, so its cached XML is now stale.
		$this->flush_sitemap_cache();

		// Queue a re-translation so the translated URL catches up on its own.
		// Defaults ON: a multilingual site whose translations silently drift out
		// of date is worse than one that costs a little to keep current.
		if ( 'publish' === $post->post_status ) {
			$settings = get_option( 'acwpt_settings', array() );
			$auto     = ! isset( $settings['preload_auto'] ) || ! empty( $settings['preload_auto'] );
			if ( $auto ) {
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
		if ( ! isset( $wp->request ) ) {
			return;
		}

		$req = $wp->request;

		// /acwpt-sitemap.xsl            -> browser stylesheet (crawlers ignore)
		if ( 'acwpt-sitemap.xsl' === $req ) {
			require ACWPT_PLUGIN_DIR . 'includes/sitemap-style.php';
			exit;
		}

		// /acwpt-sitemap.xml            -> index of per-language children
		// /acwpt-sitemap-<lang>.xml     -> the URLs for one language
		$is_index = ( 'acwpt-sitemap.xml' === $req );
		$lang     = null;
		if ( ! $is_index && preg_match( '#^acwpt-sitemap-([a-z]{2}(?:-[a-z]{2})?)\.xml$#i', $req, $m ) ) {
			$lang = strtolower( $m[1] );
		}

		if ( ! $is_index && null === $lang ) {
			return;
		}

		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return; // Let WordPress 404 normally.
		}

		$source = ACWPT_Languages::get_source();

		if ( $is_index ) {
			// A SITEMAP INDEX, not one monolithic file.
			//
			// The single file had grown to 12 MB across 6,512 URLs: legal under
			// the 50 MB / 50,000 URL limits, but slow to fetch and slow for a
			// crawler to process, and it forces a full regeneration whenever any
			// one language changes. One child per language keeps each file small
			// and lets Search Console report coverage per market.
			$xml = get_transient( 'acwpt_sitemap_index' );
			if ( ! $xml ) {
				$xml = $this->generate_sitemap_index( array_merge( array( $source ), $enabled ) );
				set_transient( 'acwpt_sitemap_index', $xml, HOUR_IN_SECONDS );
			}
		} else {
			if ( $lang !== $source && ! in_array( $lang, $enabled, true ) ) {
				return; // Unknown language: 404.
			}
			$key = 'acwpt_sitemap_xml_' . $lang;
			$xml = get_transient( $key );
			if ( ! $xml ) {
				$xml = $this->generate_sitemap_xml( $lang );
				set_transient( $key, $xml, HOUR_IN_SECONDS );
			}
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml;
		exit;
	}

	/**
	 * Clear every cached sitemap file.
	 *
	 * Splitting into one child per language turned a single transient into
	 * N+2 of them, so a bare delete_transient('acwpt_sitemap_xml') would now
	 * leave the per-language children serving stale XML after an edit. Public
	 * so the admin screen can reuse it.
	 */
	public function flush_sitemap_cache() {
		delete_transient( 'acwpt_sitemap_xml' );   // legacy single-file key
		delete_transient( 'acwpt_sitemap_index' );

		$langs = ACWPT_Languages::get_enabled_codes();
		$langs[] = ACWPT_Languages::get_source();
		foreach ( array_unique( $langs ) as $code ) {
			delete_transient( 'acwpt_sitemap_xml_' . $code );
		}
	}

	/**
	 * Build the sitemap index listing one child per language.
	 *
	 * @param string[] $langs Source language first, then targets.
	 * @return string
	 */
	private function generate_sitemap_index( array $langs ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		// Browser-only: crawlers ignore the stylesheet, humans get a readable table.
		$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/acwpt-sitemap.xsl' ) ) . '"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $langs as $code ) {
			$xml .= "  <sitemap>\n";
			$xml .= '    <loc>' . esc_url( home_url( '/acwpt-sitemap-' . $code . '.xml' ) ) . "</loc>\n";
			$xml .= '    <lastmod>' . esc_html( gmdate( 'c' ) ) . "</lastmod>\n";
			$xml .= "  </sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
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
	/**
	 * Build the URL set for ONE language.
	 *
	 * Each <url> still carries the full hreflang cluster, including every other
	 * language and x-default, because a cluster is only valid if every member
	 * points at every other member. Splitting by language changes which URLs
	 * are <loc>, not which alternates are declared.
	 *
	 * @param string $lang Language code this file covers.
	 * @return string
	 */
	private function generate_sitemap_xml( $lang = null ) {
		$enabled = ACWPT_Languages::get_enabled_codes();
		$source  = ACWPT_Languages::get_source();
		$home    = home_url();

		if ( null === $lang ) {
			$lang = $source;
		}

		$posts = get_posts( array(
			'post_type'      => acwpt_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		// Drop anything that must not be indexed (password protected, noindex,
		// or slug-excluded in settings).
		$posts = array_filter( $posts, 'acwpt_include_in_sitemap' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/acwpt-sitemap.xsl' ) ) . '"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			$lastmod   = get_post_modified_time( 'c', true, $post );

			$relative  = str_replace( $home, '', $permalink );
			$relative  = '/' . ltrim( $relative, '/' );

			// Build URLs for all language versions (needed for the alternates).
			$lang_urls            = array();
			$lang_urls[ $source ] = $permalink;
			foreach ( $enabled as $code ) {
				$lang_urls[ $code ] = home_url( '/' . $code . $relative );
			}

			if ( ! isset( $lang_urls[ $lang ] ) ) {
				continue;
			}

			// One <url> for THIS language only; alternates still list them all.
			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_url( $lang_urls[ $lang ] ) . "</loc>\n";
			if ( $lastmod ) {
				$xml .= '    <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
			}
			foreach ( $lang_urls as $alt_lang => $alt_url ) {
				$xml .= '    <xhtml:link rel="alternate" hreflang="' . esc_attr( ACWPT_Languages::bcp47( $alt_lang ) ) . '" href="' . esc_url( $alt_url ) . '" />' . "\n";
			}
			$xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $permalink ) . '" />' . "\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}
}
