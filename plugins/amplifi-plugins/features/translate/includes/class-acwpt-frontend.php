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
		if ( version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ) {
			add_action( 'set_transient', array( $this, 'drop_translated_transient' ), 10, 2 );
			add_action( 'set_site_transient', array( $this, 'drop_translated_site_transient' ), 10, 2 );
		} else {
			add_action( 'setted_transient', array( $this, 'drop_translated_transient' ), 10, 2 );
			add_action( 'setted_site_transient', array( $this, 'drop_translated_site_transient' ), 10, 2 );
		}

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

		// Custom nav renderers call wp_get_nav_menu_items() directly and never
		// run wp_nav_menu(), so wp_nav_menu_objects never fires for them. This
		// site's header is one: the ac-tm-megamenu plugin builds its own markup,
		// so the switcher rendered as a dead "Language" link. Expanding at the
		// data layer covers both paths.
		add_filter( 'wp_get_nav_menu_items', array( $this, 'expand_language_menu_items_raw' ), 20, 3 );
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

		// Fold the translated sitemap into whichever SEO plugin owns the index,
		// so a crawler arriving at the conventional path still finds every
		// language rather than an English-only tree.
		add_filter( 'wpseo_sitemap_index', array( $this, 'add_to_seo_sitemap_index' ) );
		add_filter( 'rank_math/sitemap/index', array( $this, 'add_to_seo_sitemap_index' ) );

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

	/**
	 * Delete every cached translation, for every enabled language.
	 *
	 * Guarded, because this is only a recoverable action if the API can
	 * actually re-translate. With an exhausted balance or an invalid key, a
	 * flush turns a working multilingual site into an English one with no way
	 * back — and on this site that is ~$8 per language to rebuild.
	 *
	 * @param bool $force Skip the affordability check (CLI escape hatch).
	 * @return true|WP_Error
	 */
	public function clear_all_string_caches( $force = false ) {
		if ( ! $force ) {
			$can = ACWPT_Budget::can_afford_rebuild();
			if ( is_wp_error( $can ) ) {
				return $can;
			}
		}

		$enabled = ACWPT_Languages::get_enabled_codes();
		foreach ( $enabled as $code ) {
			delete_option( 'acwpt_strings_' . $code );        // legacy option store
			delete_option( 'acwpt_strings_meta_' . $code );   // populated-at marker
			ACWPT_String_Store::flush( $code );
			ACWPT_String_Queue::clear( $code );
		}
		$this->string_cache = null;

		return true;
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

		// Titles of OTHER posts (news cards, product cards, related items) come
		// through here without a per-post translation, so they rendered English
		// in the news grid on every language. Resolve them from the string store
		// and queue misses, the same as any other visible string.
		//
		// The result is wrapped in a done-fence so the page buffer does not
		// re-extract the translated title as if it were English source. Without
		// the fence the buffer counted every translated headline as "missing"
		// (fr 30, it 23, ro 17 per page) and kept the page uncacheable. The
		// fence only goes on when output is buffered, so titles in feeds or
		// attributes never carry markers.
		$plain = trim( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $plain || ! $this->is_translatable_prose( $plain ) ) {
			return $title;
		}
		$t = $this->get_string_translation( $plain );
		if ( ! $t ) {
			if ( class_exists( 'ACWPT_String_Queue' ) ) {
				ACWPT_String_Queue::enqueue( $this->current_language, array( $plain ) );
			}
			return $title;
		}
		if ( ob_get_level() > 0 && ! is_feed() && ! wp_doing_ajax() && ! doing_filter( 'wp_head' ) ) {
			$this->title_reverse[ mb_strtolower( $t ) ] = $plain; // for unsplice_translated_titles()
			return '<!--acwpt:done-->' . $t . '<!--/acwpt:done-->';
		}
		return $t;
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

		// Yoast passes these strings HTML-encoded ("Test &amp; Measurement",
		// "Ascential&#039;s"), while the store is keyed on decoded text. The
		// encoded lookup always missed, so <title>, meta description and og:*
		// stayed English on every language even after the queue had translated
		// the decoded string. Measured: encoded=miss, decoded=HIT for both.
		$decoded    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$translated = $this->get_string_translation( $decoded );
		if ( ! $translated && $decoded !== $text ) {
			$translated = $this->get_string_translation( $text );
		}
		if ( $translated ) {
			// Hand back in the same encoding Yoast gave us.
			return $decoded !== $text ? esc_html( $translated ) : $translated;
		}

		// Head strings (title, meta description, og:*) are produced by the SEO
		// plugin and never pass through the body extractor, so a miss here was
		// never queued: nine of ten languages served an English <title> and meta
		// description indefinitely. Queue it; the next render picks it up.
		if ( class_exists( 'ACWPT_String_Queue' ) && $this->is_translatable_prose( $text ) ) {
			ACWPT_String_Queue::enqueue( $this->current_language, array( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		}
		return $text;
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
	 * Sort long option lists (country pickers) by their translated label.
	 *
	 * A country list translated in place keeps the English order, so
	 * "Deutschland" sits between Georgia and Ghana and a German buyer looking
	 * under D on a required field does not find it. Only selects with 20+
	 * options whose labels were translated are touched; leading placeholder
	 * options (empty value) keep their position; option values and attributes
	 * are never changed.
	 */
	private function resort_translated_selects( $html ) {
		$lang   = $this->current_language;
		$locale = array( 'de' => 'de_DE', 'pl' => 'pl_PL', 'ro' => 'ro_RO', 'tr' => 'tr_TR', 'zh' => 'zh_CN',
			'it' => 'it_IT', 'fr' => 'fr_FR', 'es' => 'es_ES', 'pt' => 'pt_PT', 'cs' => 'cs_CZ' );
		$coll   = class_exists( 'Collator' ) ? new Collator( $locale[ $lang ] ?? $lang ) : null;

		return preg_replace_callback(
			'#(<select\b[^>]*>)(.*?)(</select>)#is',
			function ( $m ) use ( $coll ) {
				if ( ! preg_match_all( '#<option\b([^>]*)>(.*?)</option>#is', $m[2], $ops, PREG_SET_ORDER ) || count( $ops ) < 20 ) {
					return $m[0];
				}
				$head = array();
				$rest = array();
				foreach ( $ops as $o ) {
					$is_placeholder = preg_match( '#\bvalue\s*=\s*(["\'])\1#', $o[1] ) || ! preg_match( '#\bvalue\s*=#', $o[1] );
					if ( $is_placeholder && ! $rest ) {
						$head[] = $o[0];
					} else {
						$rest[] = $o;
					}
				}
				usort(
					$rest,
					function ( $a, $b ) use ( $coll ) {
						$x = html_entity_decode( strip_tags( $a[2] ), ENT_QUOTES, 'UTF-8' );
						$y = html_entity_decode( strip_tags( $b[2] ), ENT_QUOTES, 'UTF-8' );
						return $coll ? $coll->compare( $x, $y ) : strcasecmp( remove_accents( $x ), remove_accents( $y ) );
					}
				);
				// Rebuild only the option run; anything else inside the select
				// (optgroups are not used by this form) is left as it was.
				$sorted = implode( '', $head ) . implode( '', array_map( function ( $o ) { return $o[0]; }, $rest ) );
				return $m[1] . $sorted . $m[3];
			},
			$html
		);
	}

	/**
	 * Look up the English path of this language request in the site's redirect
	 * table (EPS 301 Redirects, {prefix}redirects) and return the language
	 * version of its destination. Same-site destinations only; external
	 * targets and missing rows return null so the normal 404 handling runs.
	 *
	 * @return string|null
	 */
	private function resolve_site_redirect_for_language() {
		global $wpdb;

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$lang = $this->current_language;
		$path = preg_replace( '#^/' . preg_quote( $lang, '#' ) . '(/|$)#', '/', $path );
		$key  = trim( $path, '/' );
		if ( '' === $key ) {
			return null;
		}

		$to = null;

		// 1. Yoast SEO Premium redirects (plain format). On this site Yoast owns
		//    the page-move redirects: /contact/ -> /how-can-we-help/,
		//    /adas/ -> /transportation/adas/, etc.
		$plain = get_option( 'wpseo-premium-redirects-export-plain', array() );
		if ( is_array( $plain ) ) {
			foreach ( array( $key, $key . '/', '/' . $key, '/' . $key . '/' ) as $k ) {
				if ( isset( $plain[ $k ]['url'] ) && in_array( (int) ( $plain[ $k ]['type'] ?? 301 ), array( 301, 302, 307, 308 ), true ) ) {
					$to = (string) $plain[ $k ]['url'];
					break;
				}
			}
		}

		// 2. EPS 301 Redirects table ({prefix}redirects).
		if ( null === $to ) {
			$table = $wpdb->prefix . 'redirects';
			static $has_table = null;
			if ( null === $has_table ) {
				$has_table = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			}
			if ( $has_table ) {
				$to = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT url_to FROM {$table} WHERE status IN ('301','302') AND url_from IN (%s, %s, %s, %s) ORDER BY id LIMIT 1",
						$key, $key . '/', '/' . $key, '/' . $key . '/'
					)
				);
			}
		}
		if ( ! $to ) {
			return null;
		}

		// Yoast stores site-relative targets without a leading slash.
		if ( ! ctype_digit( (string) $to ) && ! preg_match( '#^(https?:)?//#i', $to ) && '/' !== substr( $to, 0, 1 ) ) {
			$to = '/' . $to;
		}
		// Trailing slash, matching the site's permalinks.
		if ( '/' === substr( $to, 0, 1 ) && '/' !== substr( $to, -1 ) && false === strpos( $to, '?' ) && false === strpos( $to, '#' ) && ! preg_match( '#\.[a-z0-9]{2,4}$#i', $to ) ) {
			$to .= '/';
		}

		// EPS stores a post ID for internal targets.
		if ( ctype_digit( (string) $to ) ) {
			$to = get_permalink( (int) $to );
			if ( ! $to ) {
				return null;
			}
		}

		$home = untrailingslashit( home_url() );
		if ( 0 === strpos( $to, $home ) ) {
			$dest = substr( $to, strlen( $home ) );
		} elseif ( '/' === substr( $to, 0, 1 ) && '//' !== substr( $to, 0, 2 ) ) {
			$dest = $to;
		} else {
			return null; // external destination: leave it to the English site
		}
		$dest = '' === $dest ? '/' : $dest;
		if ( preg_match( '#^/' . preg_quote( $lang, '#' ) . '(/|$)#', $dest ) ) {
			return $home . $dest;
		}
		return $home . '/' . $lang . $dest;
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

		// HONOUR THE SITE'S OWN REDIRECTS FIRST.
		//
		// The English site 301s moved pages through its redirect table
		// (/contact/ -> /how-can-we-help/, /adas/ -> /transportation/adas/). A
		// language URL for the same old path is a 404 to WordPress, so it fell
		// through to the language home: the German "Kontaktieren Sie uns" CTA
		// (/de/contact/) dropped buyers back on /de/ instead of the contact
		// page. Round-10 reviewer rated it the most damaging item on the page;
		// round 8 found nine such dead ends on /pt/. Resolve the redirect the
		// English site would apply, and send the visitor to the SAME
		// destination inside their language.
		$mapped = $this->resolve_site_redirect_for_language();
		if ( $mapped ) {
			wp_safe_redirect( $mapped, 301 );
			exit;
		}

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
	 * Keep translated text out of language-neutral caches.
	 *
	 * Transients are shared by every language. A plugin that caches rendered
	 * data built from get_the_title() (ac-wp-resource-center's
	 * 'resource_hub_popular_week', 1 hour) stored whatever language built it
	 * first: the ENGLISH /resource-hub/ showed Polish titles with the done-
	 * fence visible as literal text ("<!--acwpt:done-->Wyważarki do śmigieł…"),
	 * and the Polish hub showed Czech titles. Found by the round-B Polish
	 * review, 2026-09-25.
	 *
	 * On a translated request, any transient whose value carries a fence is
	 * deleted right after it is written, so only a source-language request can
	 * populate a shared cache. Translated requests read the English value and
	 * the buffer translates it on output as usual. Cost: the cached query is
	 * recomputed on translated requests until an English request rebuilds it.
	 *
	 * @param string $transient
	 * @param mixed  $value
	 */
	public function drop_translated_transient( $transient, $value = null ) {
		if ( ! $this->current_language || $this->current_language === ACWPT_Languages::get_source() ) {
			return;
		}
		static $busy = false;
		if ( $busy ) {
			return;
		}
		$blob = is_string( $value ) ? $value : wp_json_encode( $value );
		if ( ! is_string( $blob ) || false === strpos( $blob, 'acwpt:done' ) ) {
			return;
		}
		$busy = true;
		delete_transient( $transient );
		$busy = false;
	}

	public function drop_translated_site_transient( $transient, $value = null ) {
		if ( ! $this->current_language || $this->current_language === ACWPT_Languages::get_source() ) {
			return;
		}
		$blob = is_string( $value ) ? $value : wp_json_encode( $value );
		if ( is_string( $blob ) && false !== strpos( $blob, 'acwpt:done' ) ) {
			delete_site_transient( $transient );
		}
	}

	/**
	 * Start output buffering on translated pages to post-process the full HTML.
	 */
	public function start_output_buffer() {
		if ( ! $this->current_language || is_admin() ) {
			return;
		}
		ob_start( array( $this, 'safe_output_buffer' ) );
	}

	/**
	 * Never serve a blank or truncated page.
	 *
	 * Every preg_* call returns null when it hits a PCRE limit, and a null
	 * anywhere in the pipeline became an EMPTY 200 response: 27 Polish pages
	 * went blank for ~4 hours on 2026-09-25 with no error visible to
	 * monitoring. Whatever goes wrong inside translation, the visitor gets
	 * at worst the untranslated page. A result under 60% of the input size
	 * or missing </html> when the input had it is treated as a failure.
	 *
	 * @param string $html
	 * @return string
	 */
	public function safe_output_buffer( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		try {
			$out = $this->process_output_buffer( $html );
		} catch ( \Throwable $e ) {
			error_log( 'ACWPT: buffer exception, serving source page: ' . $e->getMessage() );
			$this->mark_fallback( 'exception' );
			return $html;
		}
		$had_close = false !== stripos( $html, '</html>' );
		if ( ! is_string( $out ) || '' === $out
			|| strlen( $out ) < 0.6 * strlen( $html )
			|| ( $had_close && false === stripos( $out, '</html>' ) ) ) {
			error_log( sprintf( 'ACWPT: buffer produced %s (%d of %d bytes), serving source page. preg_last_error=%d', is_string( $out ) ? 'short output' : 'null', is_string( $out ) ? strlen( $out ) : 0, strlen( $html ), preg_last_error() ) );
			$this->mark_fallback( is_string( $out ) ? 'short' : 'null' );
			return $html;
		}
		return $out;
	}

	private function mark_fallback( $why ) {
		if ( ! headers_sent() ) {
			header( 'X-ACWPT-Translation: fallback-' . $why, true );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		}
	}

	/**
	 * Process the full page HTML: translate nav, footer, meta tags, prefix links.
	 */
	public function process_output_buffer( $html ) {
		if ( ! $this->current_language || empty( $html ) ) {
			return $html;
		}

		// A fenced title can end up inside an attribute (alt="", title="",
		// aria-label="") where a comment would render as literal text. Strip
		// fences inside tags first, in both raw and entity-escaped form; those
		// strings are already translated and attributes are handled elsewhere.
		$html = preg_replace_callback(
			'/<[a-z][^>]*(?:acwpt:done|&lt;!--acwpt)[^>]*>/i',
			function ( $m ) {
				return str_replace(
					array( '<!--acwpt:done-->', '<!--/acwpt:done-->', '&lt;!--acwpt:done--&gt;', '&lt;!--/acwpt:done--&gt;' ),
					'',
					$m[0]
				);
			},
			$html
		);

		// Rebuild template sentences that have a translated title spliced in.
		$html = $this->unsplice_translated_titles( $html );

		// FENCES INSIDE SCRIPTS ARE NEVER REAL FENCES.
		//
		// A theme script embeds the post title in JavaScript, e.g.
		//   var productName = "(esc_js of strtolower(get_the_title()))";
		// so a fenced title arrives JSON/JS-escaped: the closing marker becomes
		// <!--\/acwpt:done--> (or \u003C!--...), which the mask below cannot
		// pair. Its opening marker then paired with the NEXT real closing marker
		// ~250 KB later, and everything between was masked as "already
		// translated": on every catalog page the 19 copies of the rebrand modal
		// stayed English under a "complete" header. Found by the 100-page Polish
		// review; confirmed live (served page carried one orphan escaped closer
		// inside tmDivisionModal's script). Strip every marker form inside
		// <script> first; the title text itself is already translated.
		$pre_strip = $html;
		$html = preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script>)#is',
			function ( $m ) {
				if ( false === stripos( $m[2], 'acwpt:done' ) ) {
					return $m[0];
				}
				$body = preg_replace( '#(?:<|\\\\u003[cC]|\\\\x3[cC])!--\\\\?/?acwpt:done--(?:>|\\\\u003[eE]|\\\\x3[eE])#', '', $m[2] );
				return $m[1] . ( null === $body ? $m[2] : $body ) . $m[3];
			},
			$html
		);
		if ( null === $html ) {
			$html = $pre_strip; // regex engine limit: skip this pass, never blank the page
		}

		// Set aside regions already translated upstream so no pass below re-reads
		// them as English source. Restored just before the buffer returns.
		//
		// Their LINKS still need the language prefix. Masking the whole region
		// also hid its hrefs from prefix_internal_links(), so every link inside
		// Elementor content ("our many brands", "diverse industries", "Learn
		// More") sent Spanish, French, Polish readers to the English page.
		// Reviewers flagged exactly those three links. Prefix them here, then mask.
		//
		// The region may not contain another OPENING marker: an orphan opener
		// (its closer lost to escaping) must never pair across a later fenced
		// item and swallow the page between them.
		//
		// Done with a linear string scan, NOT a regex. The tempered pattern
		// (?:(?!open).)*? backtracks per character and exceeded PCRE limits
		// on long pages; preg_replace_callback() then returned null and the
		// page was served as a 200 with an EMPTY body. 27 of the 100 Polish
		// review pages went blank that way for ~4 hours on 2026-09-25.
		$done_regions = array();
		$open_m  = '<!--acwpt:done-->';
		$close_m = '<!--/acwpt:done-->';
		if ( false !== strpos( $html, $open_m ) ) {
			$out = '';
			$pos = 0;
			while ( false !== ( $a = strpos( $html, $open_m, $pos ) ) ) {
				$b = strpos( $html, $close_m, $a + strlen( $open_m ) );
				if ( false === $b ) {
					break; // orphan opener: leave the rest untouched
				}
				$next_open = strpos( $html, $open_m, $a + strlen( $open_m ) );
				if ( false !== $next_open && $next_open < $b ) {
					// Orphan opener before this close: drop it, resume at the next opener.
					$out .= substr( $html, $pos, $a - $pos );
					$pos  = $a + strlen( $open_m );
					continue;
				}
				$inner                = substr( $html, $a + strlen( $open_m ), $b - $a - strlen( $open_m ) );
				$key                  = '<!--ACWPT_DONE_' . count( $done_regions ) . '-->';
				$done_regions[ $key ] = $this->prefix_internal_links( $inner );
				$out                 .= substr( $html, $pos, $a - $pos ) . $key;
				$pos                  = $b + strlen( $close_m );
			}
			$html = $out . substr( $html, $pos );
		}

		// Stash the SOURCE html. Cacheability is judged against this, not
		// against the translated output — see count_unresolved_source_strings().
		$this->source_html_for_coverage = $html;

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

		// Record how much of the SOURCE page we could resolve.
		//
		// Must be measured here, before substitution replaced the English with
		// the target language. Measuring afterwards re-extracts the TRANSLATED
		// strings and looks them up in an en->xx store keyed on English, so
		// virtually nothing matches: 504 of 507 "missing" on a page that was
		// fully translated. That marked every page partial, disabled edge
		// caching for all ten languages, and re-queued German strings to be
		// translated into German — forever.
		$this->coverage_missing = $this->count_unresolved_source_strings();
		if ( $this->coverage_missing >= 0 ) {
			$this->coverage_missing += (int) $this->upstream_missing;
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

		// 4c. Client-rendered third-party widgets.
		//
		// A reviewer drove the real conversion path and found the Marketo lead
		// form entirely in English: FIRST NAME, LAST NAME, Submit. Those labels
		// are injected by Marketo's own JS after our response is sent, so zero
		// of them exist in the HTML we produce and no server-side pass can ever
		// reach them. Measured: "FIRST NAME" appears 0 times in our output.
		//
		// The form sits directly in the conversion path, so leaving it English
		// undoes the rest of the work. We ship the translations we already hold
		// for those labels plus a MutationObserver that applies them as the
		// widget renders. Text nodes and placeholders only — never values,
		// names or anything the vendor submits.
		$html = $this->inject_client_widget_translations( $html );

		// 5. Decide cacheability. A partially-translated page must NOT be cached,
		//    or the untranslated source text is frozen in the edge cache until the
		//    TTL expires. Only fully-resolved pages are marked cacheable.
		$this->mark_page_cacheability( $html );

		// Put the upstream-translated regions back.
		if ( $done_regions ) {
			$html = strtr( $html, $done_regions );
		}
		// Strip any fence a filter left unconsumed (e.g. content that never
		// reached the buffer's mask step).
		$html = str_replace(
			array( '<!--acwpt:done-->', '<!--/acwpt:done-->', '&lt;!--acwpt:done--&gt;', '&lt;!--/acwpt:done--&gt;' ),
			'',
			$html
		);

		// Re-sort translated <select> lists by their visible label.
		$html = $this->resort_translated_selects( $html );

		// KEEP FORM SUBMITTERS IN THEIR LANGUAGE.
		//
		// Lead forms post to Salesforce with a hidden retURL, and inline scripts
		// send the browser on with window.location.href = '/thank-you/'. Both
		// pointed at the English page, so a buyer who had just handed over their
		// details on /fr/ landed on English, then the thank-you page's countdown
		// (location.href = '/') ejected them to the English homepage. Round-8
		// reviewers flagged it. Approved by Adam: each language uses its own
		// thank-you page. Only same-site, root-relative or same-host targets are
		// rewritten; the Salesforce endpoint itself is untouched. Runs after upstream
		// (Elementor) regions are restored: the thank-you countdown lives in one.
		$lang  = $this->current_language;
		$home  = untrailingslashit( home_url() );
		$html  = preg_replace(
			'#(name="retURL"\s+value=")(' . preg_quote( $home, '#' ) . ')?/(?!' . preg_quote( $lang, '#' ) . '/)([^"]*)"#',
			'$1' . $home . '/' . $lang . '/$3"',
			$html
		);
		$html  = preg_replace_callback(
			'#(location\.href\s*=\s*)([\'"])/(?!/)(?!' . preg_quote( $lang, '#' ) . '/)([^\'"]*)\2#',
			function ( $m ) use ( $lang ) {
				return $m[1] . $m[2] . '/' . $lang . '/' . $m[3] . $m[2];
			},
			$html
		);

		// Localise English thousands separators in rendered text nodes. Runs after
		// upstream regions are restored: the "13,000" counter is Elementor output.
		//
		// Figures such as the "13,000 projects" counter are never extracted as
		// strings (a bare number is not prose), so the translator-side separator
		// fix never touched them and every language showed "13,000", which a
		// Spanish or German reader parses as thirteen. Applied to text between
		// tags only, with script/style/textarea bodies masked, so attributes,
		// data-* values and code are left alone.
		if ( class_exists( 'ACWPT_Translator' ) && method_exists( 'ACWPT_Translator', 'localize_thousands' ) ) {
			$masked = array();
			$html   = preg_replace_callback(
				'#<(script|style|textarea)\b[^>]*>.*?</\1>#is',
				function ( $m ) use ( &$masked ) {
					$k            = '<!--ACWPT_NUM_' . count( $masked ) . '-->';
					$masked[ $k ] = $m[0];
					return $k;
				},
				$html
			);
			$lang = $this->current_language;
			$html = preg_replace_callback(
				'/>([^<>]*\d{1,3},\d{3}[^<>]*)</u',
				function ( $m ) use ( $lang ) {
					return '>' . ACWPT_Translator::localize_thousands( $m[1], $lang ) . '<';
				},
				$html
			);
			if ( $masked ) {
				$html = strtr( $html, $masked );
			}
		}


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
	/** Unresolved source strings for this request; -1 until measured. */
	private $coverage_missing = -1;

	/** Buffered page html BEFORE substitution, for coverage measurement. */
	private $source_html_for_coverage = '';

	/** Source strings with no stored translation on this request. */
	private $coverage_missing_list = array();

	/** Missing strings counted in regions translated upstream (Elementor). */
	private $upstream_missing = 0;

	/** translated title (lower-cased) => English title, filled by filter_title(). */
	private $title_reverse = array();

	/**
	 * Un-splice translated titles from English template sentences.
	 *
	 * Theme templates build text around a post title. Every catalog page ends
	 * with "Contact our engineering team for a custom quote or technical
	 * consultation regarding [esc_html( tm_sentence_case( get_the_title() ) )]."
	 * get_the_title() is translated by filter_title(), so the page carried an
	 * ENGLISH sentence with a POLISH product name spliced in, which matches no
	 * stored key and could never be translated: "...regarding szlifowanie cnc."
	 * on all 20 catalog pages in the 100-page review. tm_sentence_case() also
	 * lower-cased "CNC", because the fence markers glued to the word made it
	 * fail the all-caps test.
	 *
	 * Here the title still carries its done-fence, HTML-escaped by esc_html().
	 * For each text node containing one:
	 *   - a node that is only the title: restore the stored translation's own
	 *     casing ("szlifowanie cnc" -> "Szlifowanie CNC");
	 *   - a sentence around a title: rebuild the ENGLISH sentence (trying the
	 *     casings the theme may have applied), and if that sentence is stored,
	 *     the whole node becomes its proper translation, with the right case
	 *     government ("...dotyczącej szlifowania CNC."). If not stored, the
	 *     English sentence is queued so it translates in the background.
	 * Runs before extraction and masking, so the rest of the buffer sees clean
	 * text.
	 */
	private function unsplice_translated_titles( $html ) {
		$open  = '&lt;!--acwpt:done--&gt;';
		$close = '&lt;!--/acwpt:done--&gt;';
		if ( ! $this->title_reverse || false === strpos( $html, $open ) ) {
			return $html;
		}
		$seg_re = '#' . preg_quote( $open, '#' ) . '(.*?)' . preg_quote( $close, '#' ) . '#us';

		$forms_for = function ( $en ) {
			return array_values( array_unique( array_filter( array(
				function_exists( 'tm_sentence_case' ) ? tm_sentence_case( $en ) : '',
				$en,
				mb_strtolower( $en ),
				ucfirst( mb_strtolower( $en ) ),
			) ) ) );
		};

		return preg_replace_callback(
			'#>([^<>]*?' . preg_quote( $open, '#' ) . '[^<>]*?)<#us',
			function ( $m ) use ( $open, $close, $seg_re, $forms_for ) {
				$node = $m[1];
				if ( ! preg_match_all( $seg_re, $node, $segs, PREG_SET_ORDER ) ) {
					return $m[0];
				}
				$titles = array();
				foreach ( $segs as $s ) {
					$inner = html_entity_decode( $s[1], ENT_QUOTES, 'UTF-8' );
					$en    = $this->title_reverse[ mb_strtolower( $inner ) ] ?? null;
					if ( ! $en ) {
						return $m[0];
					}
					$titles[] = array( 'inner' => $inner, 'en' => $en );
				}

				// Only the title in this node.
				if ( 1 === count( $segs ) && trim( $segs[0][0] ) === trim( $node ) ) {
					$t = $this->get_string_translation( $titles[0]['en'] );
					if ( $t && mb_strtolower( $t ) === mb_strtolower( $titles[0]['inner'] ) ) {
						return '>' . str_replace( $segs[0][0], esc_html( $t ), $node ) . '<';
					}
					return '>' . str_replace( $segs[0][0], esc_html( $titles[0]['inner'] ), $node ) . '<';
				}

				// A sentence built around one or more titles.
				$parts  = preg_split( $seg_re, $node );
				$combos = array( array() );
				foreach ( $titles as $ti ) {
					$next = array();
					foreach ( $combos as $c ) {
						foreach ( $forms_for( $ti['en'] ) as $f ) {
							$next[] = array_merge( $c, array( $f ) );
						}
					}
					$combos = $next;
				}
				// $forms_for() lists the theme's own casing (tm_sentence_case) first,
				// so combos[0] is the sentence the English page actually shows:
				// the one most likely stored, and the one queued on a miss.
				$first = null;
				foreach ( $combos as $c ) {
					$sentence = $parts[0];
					foreach ( $c as $k => $f ) {
						$sentence .= esc_html( $f ) . ( $parts[ $k + 1 ] ?? '' );
					}
					$key = $this->normalize_candidate( $sentence );
					if ( null === $first ) {
						$first = $key;
					}
					$t = $this->get_string_translation( $key );
					if ( $t ) {
						$lead  = preg_match( '/^\s*/u', $node, $lw ) ? $lw[0] : '';
						$trail = preg_match( '/\s*$/u', $node, $tw ) ? $tw[0] : '';
						return '>' . $lead . esc_html( $t ) . $trail . '<';
					}
				}
				if ( $first && class_exists( 'ACWPT_String_Queue' ) ) {
					ACWPT_String_Queue::enqueue( $this->current_language, array( $first ) );
				}
				return $m[0];
			},
			$html
		);
	}

	/**
	 * Emit a translation map for labels injected by third-party JS.
	 *
	 * Only strings we ALREADY have a cached translation for are emitted, so
	 * this never triggers an API call during a page render and never blocks.
	 * A missing label simply stays English until the queue has translated it.
	 */
	private function inject_client_widget_translations( $html ) {
		if ( ! $this->current_language || $this->current_language === ACWPT_Languages::get_source() ) {
			return $html;
		}
		// Labels injected by JavaScript after our response is sent. Marketo
		// forms, the theme's floating "Get in touch" tab (created with
		// document.createElement in footer.php), and the chat widget's
		// greeting all rendered English on every language in round 4.

		$labels = array(
			'First Name', 'Last Name', 'Email Address', 'Company Name', 'Title',
			'Phone Number', 'Submit', 'Country', 'State', 'City', 'Comments',
			'How did you first hear about us?',
			'Is there anything specific that you\'d like to discuss?',
			'By checking this box, I consent to receive marketing communications.',
			'Interested In', 'Choose files', 'Required', 'Please complete this field.',
			'Get in touch', 'How can I help you today?', 'New Chat', 'Try one of these:',
			'Type your message...', 'Send',
			// Theme header utility bar (#custom-banner), built in inline JS.
			// Harvested from the live page; round-8 reviewers in ro/tr flagged
			// the whole purple bar as English on their pages.
			'Schedule an Ascentialytics demo', 'Learn more about Ascentialytics',
			'Login to your account', 'Submit a support request form',
			'Learn about available Service Level Agreements', 'Call us for support',
			'Marketplace login', 'Follow Ascential Technologies', 'Search', 'Search…',
			'Chat Assistant', 'Viewing resources for:', 'ADAS Garage podcast',
			'Thank you! Your download will begin shortly.', 'No messages to download.',
			// Support-form uploader and validation messages (inline JS).
			'Drag & drop files here, or', 'Choose files', 'Remove',
			'Video, photo, audio, PDF, or ZIP. Up to 5 files, 250 MB each.',
			'Please fill in all required fields marked with a red asterisk (*).',
			'Please select a priority for this request.',
			'Please complete the security verification before submitting.',
			'Please wait for your file uploads to finish before submitting.',
			'An error occurred while submitting your request. Please try again or contact support directly.',
			// Fragments of messages the uploader builds by concatenation.
			' is too large (max 250 MB).',
			' is not an accepted type (video, photo, audio, PDF, or ZIP).',
			'You can upload up to ', ' files.',
		);

		$map = array();
		foreach ( $labels as $label ) {
			$t = $this->get_string_translation( $label );
			if ( $t && $t !== $label ) {
				$map[ $label ] = $t;
			}
			// Marketo renders labels uppercase with a trailing colon.
			$upper = mb_strtoupper( $label, 'UTF-8' );
			if ( $t && $t !== $label ) {
				$map[ $upper ]        = mb_strtoupper( $t, 'UTF-8' );
				$map[ $label . ':' ]  = $t . ':';
				$map[ $upper . ':' ]  = mb_strtoupper( $t, 'UTF-8' ) . ':';
			}
		}

		// The uploader writes its label with innerHTML, so the DOM text node is
		// "Drag & drop files here, or" but the source literal is "&amp;".
		if ( isset( $map['Drag & drop files here, or'] ) ) {
			$map['Drag &amp; drop files here, or'] = esc_html( $map['Drag & drop files here, or'] );
		}

		// Fragments of concatenated messages ("<file> is too large (max 250
		// MB).") cannot be matched whole; they are swapped inside text nodes.
		$frag = array();
		foreach ( array( ' is too large (max 250 MB).', ' is not an accepted type (video, photo, audio, PDF, or ZIP).', 'You can upload up to ', ' files.' ) as $f ) {
			$tf = $this->get_string_translation( trim( $f ) );
			if ( $tf && $tf !== trim( $f ) ) {
				$lead         = ' ' === substr( $f, 0, 1 ) ? ' ' : '';
				$trail        = ' ' === substr( $f, -1 ) ? ' ' : '';
				$frag[ $f ]   = $lead . $tf . $trail;
			}
		}

		if ( empty( $map ) && empty( $frag ) ) {
			return $html;
		}

		$json   = wp_json_encode( $map, JSON_UNESCAPED_UNICODE );
		$fjson  = wp_json_encode( (object) $frag, JSON_UNESCAPED_UNICODE );
		// Runs over the whole body: theme scripts inject the header bar, the
		// side tab, the uploader and chat anywhere in the document. Text nodes,
		// placeholder, aria-label, title and submit values only; form values and
		// names are never touched. Debounced so a busy page is scanned at most
		// once per animation frame.
		$script = '<script id="acwpt-widget-i18n">(function(){'
			. 'var M=' . $json . ',F=' . $fjson . ';'
			. 'function tx(s){var k=s.trim();if(M[k])return s.replace(k,M[k]);var o=s;for(var f in F){if(o.indexOf(f)>-1)o=o.split(f).join(F[f]);}return o;}'
			. 'function at(e,a){var v=e.getAttribute(a);if(v){var k=v.trim();if(M[k])e.setAttribute(a,M[k]);}}'
			. 'function tr(r){if(!r)return;'
			. 'if(r.nodeType===3){var n=tx(r.nodeValue);if(n!==r.nodeValue)r.nodeValue=n;return;}'
			. 'if(r.nodeType!==1||r.tagName==="SCRIPT"||r.tagName==="STYLE")return;'
			. 'at(r,"placeholder");at(r,"aria-label");at(r,"title");'
			. 'if(r.type==="submit"&&r.value&&M[r.value.trim()])r.value=M[r.value.trim()];'
			. 'for(var i=0;i<r.childNodes.length;i++)tr(r.childNodes[i]);}'
			. 'var q=0;function run(){q=0;tr(document.body);}'
			. 'function soon(){if(!q){q=1;(window.requestAnimationFrame||setTimeout)(run);}}'
			. 'var A=window.alert;window.alert=function(m){return A.call(window,typeof m==="string"?tx(m):m);};'
			. 'if(document.readyState!=="loading")run();else document.addEventListener("DOMContentLoaded",run);'
			. 'new MutationObserver(function(m){for(var i=0;i<m.length;i++){if(m[i].addedNodes.length){soon();return;}}})'
			. '.observe(document.documentElement,{childList:true,subtree:true});'
			. '})();</script>';

		return str_replace( '</body>', $script . '</body>', $html );
	}

	/**
	 * Count source strings on this request that have no stored translation.
	 *
	 * Uses the buffered ORIGINAL html captured before substitution, so the
	 * lookup keys match the store. Returns 0 when everything resolved, which
	 * is the only state in which the page may be cached at the edge.
	 */
	private function count_unresolved_source_strings() {
		if ( ! isset( $this->source_html_for_coverage ) || '' === $this->source_html_for_coverage ) {
			return -1; // Unknown: treat as not-fully-translated.
		}

		$strings = $this->extract_translatable_strings_from_html( $this->source_html_for_coverage );
		if ( empty( $strings ) ) {
			return 0;
		}
		$strings = array_values( array_unique( $strings ) );
		$found   = ACWPT_String_Store::get_many( $this->current_language, $strings );

		$buffer_miss = array_values( array_diff( $strings, array_keys( $found ) ) );
		if ( $buffer_miss ) {
			// Already-translated output is not a missing source string.
			$buffer_miss = array_values( array_diff( $buffer_miss, ACWPT_String_Store::known_translations( $this->current_language, $buffer_miss ) ) );
		}
		$this->coverage_missing_list = array_merge( $this->coverage_missing_list, $buffer_miss );

		return count( $buffer_miss );
	}

	private function mark_page_cacheability( $html ) {
		if ( headers_sent() ) {
			return;
		}

		// Coverage was measured against the SOURCE html before substitution
		// (see process_output_buffer). Re-deriving it from $html here would
		// inspect the translated output against an English-keyed store and
		// always report a miss.
		//
		// Queue depth is deliberately NOT part of this test: a global backlog
		// from some other page must not make THIS page uncacheable, or a busy
		// site never caches anything.
		$full = ( 0 === (int) $this->coverage_missing );

		// Coverage diagnostics. The header is just a number, so it is safe on a
		// public page and lets anyone see WHY a page is uncacheable without
		// backend access. The string list goes to a transient only when the
		// request carries the site's debug token. Added because nine languages
		// reported "partial" with every extracted string stored and an empty
		// queue, and there was no way to see what the check was counting.
		header( 'X-ACWPT-Missing: ' . (int) $this->coverage_missing, true );
		$token = (string) get_option( 'acwpt_debug_token', '' );
		if ( '' !== $token && isset( $_GET['acwpt_debug'] ) && hash_equals( $token, (string) wp_unslash( $_GET['acwpt_debug'] ) ) ) {
			set_transient(
				'acwpt_cov_debug_' . $this->current_language,
				array(
					'missing'      => (int) $this->coverage_missing,
					'source_bytes' => strlen( (string) $this->source_html_for_coverage ),
					'strings'      => array_slice( $this->coverage_missing_list, 0, 60 ),
				),
				900
			);
		}

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
			$original = html_entity_decode( $cm[1], ENT_QUOTES, 'UTF-8' );
			// The SEO plugin has usually ALREADY translated this tag through
			// filter_seo_text(). Re-reading that output as English source found
			// a stale target-to-target row and swapped correct Polish for an
			// older ungrammatical copy ("z mysla o bezpieczenstwo"), which three
			// reviewers flagged as blocking. Finished output is left alone.
			if ( array() !== ACWPT_String_Store::known_translations( $this->current_language, array( $original ) ) ) {
				return $tag;
			}
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

		// Coverage for this region is measured HERE, against its English source,
		// because the page buffer will only ever see the translated result.
		$cands = $this->extract_translatable_strings_from_html( $content );
		if ( $cands ) {
			$found = ACWPT_String_Store::get_many( $this->current_language, $cands );
			$miss  = array_values( array_diff( $cands, array_keys( $found ) ) );
			if ( $miss ) {
				// Same rule as the buffer path: finished output is not missing source.
				$miss = array_values( array_diff( $miss, ACWPT_String_Store::known_translations( $this->current_language, $miss ) ) );
			}
			$this->upstream_missing += count( $miss );
			// Keep the strings, not just the count, so the debug transient can
			// name them. The total above was all that survived before, so the
			// only visible symptom was a number with nothing behind it.
			$this->coverage_missing_list = array_merge( $this->coverage_missing_list, $miss );
		}

		$translated = $this->translate_html_blob( $content );

		// FENCE THE ALREADY-TRANSLATED REGION.
		//
		// Elementor content is translated here, before the page output buffer
		// runs. The buffer then re-extracted this region, which is already in
		// the target language, and looked it up in an English-keyed store:
		//   - it counted as "missing", so every page reported partial and sent
		//     Cache-Control: no-store (fr, zh, es, pt, cs), and
		//   - where it got queued, the queue "translated" German into German and
		//     the buffer then substituted that second rewrite over correct text
		//     (measured: de 73, pl 216, ro 61, tr 51, it 37 such rows).
		// The markers let the buffer skip what has already been done.
		return '<!--acwpt:done-->' . $translated . '<!--/acwpt:done-->';
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

		if ( $missing ) {
			$missing = array_values( array_diff( $missing, ACWPT_String_Store::known_translations( $this->current_language, $missing ) ) );
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
	 * Keys inside embedded JSON whose VALUES are human-readable copy.
	 *
	 * Deliberately narrow. Translating the wrong key breaks the widget: url,
	 * id, size, type, _id and every setting name must pass through untouched.
	 */
	const JSON_TEXT_KEYS = array(
		'heading_text', 'label', 'title', 'text', 'description', 'subtitle',
		'button_text', 'link_text', 'caption', 'placeholder', 'cta_text',
		'tab_title', 'item_title', 'nav_label', 'menu_title',
		// Consent banner copy (features/consent), same shape.
		'banner_title', 'banner_message', 'accept_label', 'reject_label',
		'manage_label', 'save_label', 'toast_accepted', 'toast_rejected',
		// amplifi-instant-search widget. Its placeholder was translated through
		// the attribute pass while every other label stayed English, and its
		// searchUrl pointed at the English root (reviewer, it/marketer).
		'placeholder', 'all_label', 'empty_title', 'empty_body', 'close_label',
		'results_label', 'hint', 'view_all', 'prefs_label', 'dns_label', 'limit_spi_label',
	);

	/**
	 * Add the language prefix to a same-site URL found in JSON config.
	 * Absolute URLs on this host and root-relative paths are prefixed; external
	 * URLs, anchors, assets and already-prefixed paths are returned unchanged.
	 */
	private function localize_json_url( $url ) {
		$lang = $this->current_language;
		if ( '' === $url || ! $lang ) {
			return $url;
		}
		$home  = untrailingslashit( home_url() );
		$codes = ACWPT_Languages::get_enabled_codes();
		$path  = null;
		if ( 0 === strpos( $url, $home ) ) {
			$path = substr( $url, strlen( $home ) );
			$path = '' === $path ? '/' : $path;
		} elseif ( '/' === substr( $url, 0, 1 ) && '//' !== substr( $url, 0, 2 ) ) {
			$path = $url;
		}
		if ( null === $path
			|| preg_match( '#^/(?:' . implode( '|', array_map( 'preg_quote', $codes ) ) . ')(/|$)#', $path )
			|| preg_match( '#^/(wp-|feed)#', $path )
			|| preg_match( '/\.(css|js|png|jpe?g|svg|webp|pdf|xml|txt)(\?|$)/i', $path ) ) {
			return $url;
		}
		$prefixed = '/' . $lang . $path;
		return 0 === strpos( $url, $home ) ? $home . $prefixed : $prefixed;
	}

	/**
	 * Recursively translate allowlisted values inside decoded JSON settings.
	 *
	 * @param mixed $node
	 * @param bool  $changed Set true when at least one value was replaced.
	 * @return mixed
	 */
	private function translate_json_text( $node, &$changed ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}

		$out = array();
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = $this->translate_json_text( $value, $changed );
				continue;
			}
			// Same-site URLs inside JSON config (searchUrl, custom_url) keep the
			// visitor on the English site unless they carry the language prefix.
			if ( is_string( $value ) && in_array( (string) $key, array( 'searchUrl', 'custom_url', 'url', 'link' ), true ) ) {
				$out[ $key ] = $this->localize_json_url( $value );
				continue;
			}
			if ( ! is_string( $value ) || ! in_array( (string) $key, self::JSON_TEXT_KEYS, true ) ) {
				$out[ $key ] = $value;
				continue;
			}

			$text = trim( $value );
			if ( mb_strlen( $text ) < 3 || mb_strlen( $text ) > 300
				|| preg_match( '#^(https?://|/|\#|[\d\s\.\-:/%]+$)#', $text ) ) {
				$out[ $key ] = $value;
				continue;
			}

			$t = $this->get_string_translation( $text );
			if ( $t ) {
				$out[ $key ] = $t;
				$changed     = true;
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Recursively collect translatable strings from decoded JSON settings.
	 *
	 * @param mixed $node
	 * @return string[]
	 */
	private static function collect_json_text( $node ) {
		$found = array();
		if ( ! is_array( $node ) ) {
			return $found;
		}

		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$found = array_merge( $found, self::collect_json_text( $value ) );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( ! in_array( (string) $key, self::JSON_TEXT_KEYS, true ) ) {
				continue;
			}

			$text = trim( $value );
			if ( mb_strlen( $text ) < 3 || mb_strlen( $text ) > 300 ) {
				continue;
			}
			// Skip anything that is clearly not prose.
			if ( preg_match( '#^(https?://|/|\#|[\d\s\.\-:/%]+$)#', $text ) ) {
				continue;
			}
			$found[] = $text;
		}

		return $found;
	}

	/**
	 * Is this string prose a translator should see at all?
	 *
	 * The live Polish page rendered the sales phone number as "dostepnosc
	 * strony internetowej". The number had been EXTRACTED and sent for
	 * translation: the numeric guard was /^[\d\s\.\-:\/\+]+$/, which does not
	 * allow parentheses, so "+1 (616) 234-1000" was treated as prose. Anything
	 * that is an identifier rather than language must never enter the batch:
	 * it cannot be improved by translation and it can be corrupted by it.
	 *
	 * @param string $text Normalised candidate.
	 * @return bool
	 */
	private function is_translatable_prose( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text || mb_strlen( $text ) < 2 ) {
			return false;
		}

		// Phone numbers, in any common punctuation style.
		if ( preg_match( '/^[\+\(\)\d\s\.\-\/x#]{7,}$/u', $text ) ) {
			return false;
		}
		// Pure numerics, ranges, percentages, counters.
		if ( preg_match( '/^[\d\s\.,:\/\+\-%x×]+$/u', $text ) ) {
			return false;
		}
		// URLs, emails, file paths, hashes.
		if ( preg_match( '/^(https?:|mailto:|tel:|www\.|\/|#)/i', $text ) ) {
			return false;
		}
		if ( preg_match( '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i', $text ) ) {
			return false;
		}
		// Markup or template syntax that leaked into a text node.
		if ( preg_match( '/[{}]|<\/?[a-z]+\s*\/?>$/i', $text ) && false === strpos( $text, ' ' ) ) {
			return false;
		}

		// JAVASCRIPT AND TEMPLATE SOURCE. On prod these were being extracted as
		// prose from inline <script> and <script type="text/template"> blocks:
		//
		//   ').text(msg.content).html() + '     ' + inlineAgentName + '
		//   '; messageHtml += '                 {{ data.display_name }}
		//
		// Each was billed, stored as an identity row, and — worse — counted by
		// the coverage check, which marked every translated page "partial" and
		// sent Cache-Control: no-store. That silently re-created the edge-cache
		// defeat fixed earlier, by a different route.
		if ( preg_match( '/\{\{.*\}\}|\{%.*%\}|<%.*%>/s', $text ) ) {
			return false; // Handlebars / Twig / Underscore placeholders
		}
		// A trailing ';' alone is NOT code: legal recitals and list items end
		// with one ("WHEREAS, Ascential agrees to perform ... Agreement;").
		// Treating it as a JS fragment left every such clause in English on
		// the Polish terms pages (100-page review, round B). Only reject it
		// when the text also looks like code: quotes/plus at an edge, or a
		// method chain / arrow / function, or no spaces at all.
		$looks_code = preg_match( '/(^[\'"+]|[\'"+]$|\)\s*\.\s*[a-z]+\(|\+=|\.html\(|\.text\(|=>|\bfunction\s*\()/i', $text );
		$semi_code  = preg_match( '/(^;|;$)/', $text ) && ( false === strpos( $text, ' ' ) || preg_match( '/[=(){}\[\]]\s*;$/', $text ) );
		if ( $looks_code || $semi_code ) {
			return false; // string concatenation and method-chain fragments
		}
		if ( preg_match( '/^oEmbed\s*\((JSON|XML)\)$/i', trim( $text ) ) ) {
			return false; // WordPress <link> discovery titles, not visible copy
		}
		// Bare template field names that leaked without their {{ }} braces,
		// e.g. "post_title". A single snake_case token is an identifier.
		if ( preg_match( '/^[a-z]+(?:_[a-z0-9]+)+$/', trim( $text ) ) ) {
			return false;
		}

		return true;
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
		// HARVEST NAMED CONFIG OBJECTS BEFORE STRIPPING SCRIPTS.
		//
		// The strip below removes every non-JSON <script>, which would also
		// delete `var ACCONSENT = {...}` — the cookie banner's copy, the first
		// thing a visitor sees. Caught before deploying: the config pass sat
		// after the strip and would have silently gone dead, re-breaking the
		// banner that was fixed earlier. Pull the allowlisted keys out first.
		$out = array();
		if ( preg_match_all( '/\bvar\s+[A-Z][A-Z0-9_]{3,}\s*=\s*(\{.*?\})\s*;/s', $html, $pre ) ) {
			foreach ( $pre[1] as $obj ) {
				$data = json_decode( $obj, true );
				if ( is_array( $data ) ) {
					foreach ( self::collect_json_text( $data ) as $text ) {
						$out[] = $text;
					}
				}
			}
		}

		// Strip executable and template blocks. Their contents are code, not
		// copy, and every pass below would otherwise mine them for strings.
		// application/json blocks are kept: a dedicated pass reads those.
		$html = preg_replace(
			'#<script\b(?![^>]*type=["\']application/(?:ld\+)?json["\'])[^>]*>.*?</script>#is',
			'',
			$html
		);
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		$html = preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', '', $html );

		// $out already holds the harvested config strings; do not reset it.

		// Link text.
		if ( preg_match_all( '/(<a\b[^>]*>)([^<]+)(<\/a>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = $this->normalize_candidate( $match[2] );
				if ( $this->is_translatable_prose( $text ) ) {
					$out[] = $text;
				}
			}
		}
		// WHOLE BLOCKS THAT CONTAIN INLINE MARKUP.
		//
		// A Polish reviewer flagged "Standing on the shoulders of nasze liczne
		// marki, like Burke Porter" and the heading "See how we bring it all
		// razem". Cause: the pattern below matches >text< only, so ANY inline
		// tag shatters a sentence into fragments that are translated
		// independently and without context:
		//
		//   "Standing on the shoulders of"      <- orphan
		//   "our many brands"                   <- link label
		//   ", like Burke Porter, we serve"     <- orphan
		//
		// Claude sees three unrelated snippets, translates whichever it can
		// make sense of, and the page ships a half-English sentence. No amount
		// of prompt tuning fixes that — the model never sees the sentence.
		//
		// So: capture the block's FULL inner HTML when it contains inline
		// markup, and send that as one unit. Inline tags are preserved in the
		// string, so the model keeps them in place. Handled before the
		// text-only pass so the fragments are never produced.
		if ( preg_match_all(
			'/<(p|h[1-6]|li|td|th|figcaption|blockquote|dd|dt|caption)\b[^>]*>(.*?)<\/\1>/is',
			$html,
			$bm,
			PREG_SET_ORDER
		) ) {
			foreach ( $bm as $bmatch ) {
				$inner = $bmatch[2];

				// Only interesting when it MIXES text and inline markup.
				if ( false === strpos( $inner, '<' ) ) {
					continue; // plain text: the pass below handles it
				}
				// Block-level children mean this is a container, not a sentence.
				if ( preg_match( '/<(?:p|div|section|article|ul|ol|table|h[1-6])\b/i', $inner ) ) {
					continue;
				}
				// Must contain real prose outside the tags.
				$outside = trim( preg_replace( '/<[^>]+>/', '', $inner ) );
				if ( mb_strlen( $outside ) < 8 ) {
					continue;
				}

				$candidate = $this->normalize_candidate( $inner );
				if ( mb_strlen( $candidate ) >= 8 && mb_strlen( $candidate ) <= 800 ) {
					$out[] = $candidate;
				}
			}
		}

		// COPY INSIDE A NAMED JS CONFIG OBJECT.
		//
		// The consent banner is the FIRST thing a visitor sees and it stayed
		// English: its copy ships as `var ACCONSENT = {"settings":{...}}` in an
		// inline <script>, and the extractor deliberately ignores scripts —
		// rewriting arbitrary JS would break the page.
		//
		// So this targets NAMED config objects only, and within them only keys
		// that are plainly labels. Everything else in the script is untouched.
		// Handles the consent banner and any other feature that localises copy
		// the same way.
		if ( preg_match_all(
			'/\bvar\s+[A-Z][A-Z0-9_]{3,}\s*=\s*(\{.*?\})\s*;/s',
			$html,
			$jm,
			PREG_SET_ORDER
		) ) {
			foreach ( $jm as $j ) {
				$data = json_decode( $j[1], true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( self::collect_json_text( $data ) as $text ) {
					$out[] = $text;
				}
			}
		}

		// LABELS THAT WRAP A BRAND SPAN.
		//
		// The megamenu writes cards as:
		//   <span class="tmm-card-label"><span class="tmm-brand-word">Ascential
		//   Care</span> - Available service level agreements</span>
		//
		// The brand span is its own element, so the prose after it is a trailing
		// text node inside a <span> — matched by none of the passes above
		// (<span> is not in the nested-block list, and the whole-block pass
		// only covers p/h/li/td). Four menu labels stayed English on every
		// language because of this one shape.
		//
		// Captured as the WHOLE label including the inner span, so the brand
		// stays protected by the never-translate sentinels and the sentence
		// reads as one unit.
		if ( preg_match_all(
			'/<(span|a)\b[^>]*>((?:(?!<\/?(?:span|a)\b).)*?<span\b[^>]*class="[^"]*brand[^"]*"[^>]*>[^<]{2,60}<\/span>[^<]{3,140})<\/\1>/is',
			$html,
			$lm,
			PREG_SET_ORDER
		) ) {
			foreach ( $lm as $lmatch ) {
				$candidate = $this->normalize_candidate( $lmatch[2] );
				if ( mb_strlen( $candidate ) >= 8 && mb_strlen( $candidate ) <= 300 ) {
					$out[] = $candidate;
				}
			}
		}

		// TEXT BEFORE A NESTED CHILD ELEMENT.
		//
		// The footer heading "Affiliates:" is written as
		//   <li style="...">Affiliates: <ul> ... </ul></li>
		// so its text is followed by a CHILD element rather than a closing tag.
		// The block pass needs >text< adjacent, the nested-block pass needs a
		// preceding </div>, and the inline-leading pass only looks ahead to
		// INLINE tags — none matched, so it stayed English while every sibling
		// heading around it translated. A reviewer spotted it precisely because
		// its neighbours were Polish.
		if ( preg_match_all(
			'/<(?:li|td|th|dt|dd|h[1-6]|p|div)\b[^>]*>\s*([^<>{}]{3,120}?)\s*<(?:ul|ol|div|span|section|table|p)\b/i',
			$html,
			$cm
		) ) {
			foreach ( $cm[1] as $text ) {
				$text = $this->normalize_candidate( $text );
				if ( $this->is_translatable_prose( $text ) ) {
					$out[] = $text;
				}
			}
		}

		// SELECT OPTIONS. 209 <option> tags on this site's support form and the
		// extractor saw none of them — the block pattern does not include
		// <option>, so every request-type, service-type and country label
		// shipped in English on every language. The reviewer listed these
		// explicitly ("the request-type options", "the whole country list").
		//
		// The option's VALUE attribute is left alone: it is what gets submitted
		// and often keys server-side logic. Only the visible label is touched.
		if ( preg_match_all( '/<select\b[^>]*>(.*?)<\/select>/is', $html, $sm, PREG_SET_ORDER ) ) {
			foreach ( $sm as $sel ) {
				// Skip country pickers. ~200 options x 10 languages is ~2,000
				// calls of no commercial value: the list is proper nouns, the
				// submitted value must stay stable, and a buyer finds their own
				// country regardless of the label language.
				if ( preg_match_all( '/<option\b[^>]*>([^<]{2,120})<\/option>/i', $sel[1], $opts ) ) {
					foreach ( $opts[1] as $text ) {
						$text = $this->normalize_candidate( $text );
						if ( $this->is_translatable_prose( $text ) ) {
							$out[] = $text;
						}
					}
				}
			}
		}
		if ( preg_match_all( '/<optgroup\b[^>]*\blabel="([^"]{2,120})"/i', $html, $gm ) ) {
			foreach ( $gm[1] as $text ) {
				$text = $this->normalize_candidate( $text );
				if ( mb_strlen( $text ) >= 2 ) {
					$out[] = $text;
				}
			}
		}

		// TEXT INSIDE JSON EMBEDDED IN AN ATTRIBUTE.
		//
		// This theme ships nav config in <script type="application/json">, so copy
		// like "See how we bring it all together" never exists in the DOM as
		// text — it is a value inside a JSON blob (acf-sticky-nav-data). Nothing
		// above could see it, which is why the reviewer found headings still in
		// English while the body was Polish. Verified by dumping the real keys
		// off the live page rather than guessing the shape.
		//
		// Only a conservative allowlist of keys is read: anything that is
		// plainly a label or heading. URLs, ids, booleans and sizes are never
		// touched.
		if ( preg_match_all(
			'/<script\b[^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is',
			$html,
			$dm,
			PREG_SET_ORDER
		) ) {
			foreach ( $dm as $d ) {
				$data = json_decode( html_entity_decode( trim( $d[1] ), ENT_QUOTES, 'UTF-8' ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( self::collect_json_text( $data ) as $text ) {
					$out[] = $text;
				}
			}
		}

		// TEXT AFTER A NESTED BLOCK, e.g. the stat labels:
		//   <div class="metric-text"><div class="number">70+</div> years of innovation </div>
		//
		// The block pass below only matches >text< with no intervening tag, and
		// the inline-trailing pass only looks after INLINE closers. A label
		// sitting after a nested <div> matched neither, so "years of
		// innovation", "home countries", "expert people" and "global locations"
		// were never extracted and shipped in English on every language.
		if ( preg_match_all( '/<\/(?:div|p|span|h[1-6])>\s*([^<>{}]{3,120}?)\s*<\/(?:div|p|li|td)>/u', $html, $tm ) ) {
			foreach ( $tm[1] as $text ) {
				$text = $this->normalize_candidate( $text );
				if ( $this->is_translatable_prose( $text ) ) {
					$out[] = $text;
				}
			}
		}

		// Block/text elements (p, span, div, headings, li, td, th, label, figcaption, button, strong, em, b, dt, dd, blockquote, cite, caption).
		// Leaf <section>/<article>/<summary>/<small> text too: a chat-transcript
		// widget renders plain text straight into <section class=markdown-section>,
		// which left a whole paragraph English on /pl/extended-warranties/ and
		// /pl/events/aistech-2026/ (100-page review, round B). Only elements whose
		// entire content is text are matched ([^<]{2,}), so containers are unaffected.
		if ( preg_match_all( '/(<(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption|section|article|summary|small)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption|section|article|summary|small)>)/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = $this->normalize_candidate( $match[2] );
				if ( $this->is_translatable_prose( $text ) ) {
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
		// ACCESSIBILITY AND MEDIA ATTRIBUTES.
		//
		// alt, aria-label and title are read by search engines, screen readers
		// and — increasingly — by AI agents navigating a page. They were never
		// extracted, so a German page shipped 60 English aria-labels ("About",
		// "Accept All", "Aerospace & industrials") and English alt text. To an
		// agent driving the site in German, every control is unlabelled.
		//
		// alt is also the only description an image has: an LLM asked about a
		// product photo has nothing else to read.
		foreach ( array( 'alt', 'aria-label', 'title' ) as $attr ) {
			// Either quote style. Themes emit placeholder='Search...' with single
			// quotes; the double-quote-only pattern left that search box English
			// in all ten languages (round-6 review, pt/translator).
			if ( preg_match_all( '/\b' . preg_quote( $attr, '/' ) . '=(["\'])([^"\']{2,}?)\1/i', $html, $am ) ) {
				foreach ( $am[2] as $text ) {
					$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
					// Skip anything that is not prose: URLs, numbers, codes.
					if ( preg_match( '#^(https?://|[\d\s\.\-:/]+$)#', $text ) ) {
						continue;
					}
					$out[] = $text;
				}
			}
		}

		// Form placeholder attributes.
		if ( preg_match_all( '/\bplaceholder=(["\'])([^"\']{2,}?)\1/i', $html, $m ) ) {
			$m[1] = $m[2];
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
		// DEDUPE BY CONTAINMENT, and cap runaway blocks.
		//
		// Measured on prod: German held 10,204 rows for a page Polish renders in
		// 2,402, and 1,953 of them (19%) were a SUBSTRING of another row — the
		// same sentence stored both whole and in fragments, because the passes
		// above deliberately overlap so that no markup shape is missed.
		//
		// Each fragment is separately billed, separately stored, and inflates the
		// queue toward MAX_QUEUE, where further strings are silently discarded.
		// Five languages stalled at that ceiling and could never complete.
		//
		// Keeping the LONGEST form is correct: a whole sentence translates better
		// than its pieces, which is the entire reason the whole-block pass exists.
		return $this->dedupe_candidates( $out, $html );
	}

	/**
	 * Drop candidates wholly contained in a longer candidate, and skip blocks
	 * too large to translate usefully.
	 *
	 * @param string[] $candidates
	 * @return string[]
	 */
	private function dedupe_candidates( array $candidates, $html = '' ) {
		$candidates = array_values( array_unique( array_filter( array_map( 'trim', $candidates ), 'strlen' ) ) );

		// ONE GATE FOR EVERY PASS. is_translatable_prose() is applied here, at
		// the single exit, so no extraction pass can bypass it.
		$candidates = array_values(
			array_filter(
				$candidates,
				function ( $c ) {
					if ( false !== strpos( $c, 'acwpt:done' ) || false !== strpos( $c, 'ACWPT_DONE_' ) ) {
						return false; // already-translated output, never source
					}
					// A block whose only text is a <time> element is a date that
					// WordPress already rendered in the page language (date_i18n).
					// Treating it as English queued "22 de setembro de 2026" to be
					// translated into Portuguese and stored it as a source row.
					if ( false !== stripos( $c, '<time' ) && '' === trim( strip_tags( preg_replace( '#<time\b[^>]*>.*?</time>#is', '', $c ) ) ) ) {
						return false;
					}
					$probe = false !== strpos( $c, '<' ) ? trim( strip_tags( $c ) ) : $c;
					return '' !== $probe && $this->is_translatable_prose( $probe );
				}
			)
		);

		usort(
			$candidates,
			function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);

		// Normalise the page the same way candidates are normalised, so an
		// occurrence count compares like with like.
		$page = '';
		if ( '' !== $html ) {
			$page = preg_replace( '/\s+/u', ' ', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		// DEDUPE BY OCCURRENCE, NOT BY CONTAINMENT.
		//
		// A candidate that appears inside a longer candidate is only a redundant
		// fragment if EVERY occurrence of it on the page sits inside that longer
		// string. Plain containment got this wrong four times:
		//   "Manage"                              inside "General Manager"
		//   "Start a service ticket"              inside a longer CTA sentence
		//   "Report an issue ... support team."   inside the Bauer variant
		//   "Elevating support, Enhancing value"  inside the nav JSON copy
		// Each is ALSO a standalone string elsewhere on the page, so dropping it
		// left that occurrence in English. The previous patch ("keep short
		// labels / keep complete sentences") fixed the first three and missed
		// the fourth because the heading contains a comma. Counting occurrences
		// is the actual definition of redundancy, so it needs no special cases.
		$kept = array();
		foreach ( $candidates as $c ) {
			if ( mb_strlen( $c ) > 1200 ) {
				continue; // oversized blocks belong to the content translator
			}

			$containers = array();
			foreach ( $kept as $k ) {
				if ( false !== mb_strpos( $k, $c ) ) {
					$containers[] = $k;
				}
			}

			if ( $containers && '' !== $page ) {
				$own    = substr_count( $page, $c );
				$inside = 0;
				foreach ( $containers as $k ) {
					$inside += substr_count( $page, $k ) * substr_count( $k, $c );
				}
				if ( $own > $inside ) {
					$kept[] = $c; // has at least one standalone occurrence
				}
				continue;
			}

			if ( ! $containers ) {
				$kept[] = $c;
			}
		}

		return $kept;
	}


	/**
	 * Run link and element translation over an HTML blob (uses string cache).
	 */
	private function translate_html_blob( $html ) {
		// WHOLE-BLOCK PASS FIRST.
		//
		// Must run before the fragment passes below, otherwise those replace
		// the pieces individually and the whole-block translation can never
		// match. See the extractor for why fragments produce half-English
		// sentences.
		$html = preg_replace_callback(
			'/(<(p|h[1-6]|li|td|th|figcaption|blockquote|dd|dt|caption)\b[^>]*>)(.*?)(<\/\2>)/is',
			function ( $m ) {
				$inner = $m[3];

				if ( false === strpos( $inner, '<' ) ) {
					return $m[0]; // plain text: later pass owns it
				}
				if ( preg_match( '/<(?:p|div|section|article|ul|ol|table|h[1-6])\b/i', $inner ) ) {
					return $m[0]; // container, not a sentence
				}
				$outside = trim( preg_replace( '/<[^>]+>/', '', $inner ) );
				if ( mb_strlen( $outside ) < 8 ) {
					return $m[0];
				}

				$candidate = $this->normalize_candidate( $inner );
				if ( mb_strlen( $candidate ) < 8 || mb_strlen( $candidate ) > 800 ) {
					return $m[0];
				}

				$translated = $this->get_string_translation( $candidate );
				if ( ! $translated ) {
					return $m[0];
				}

				// Refuse a translation that dropped the inline markup — losing
				// a link is worse than leaving the sentence in English.
				$want = preg_match_all( '/<a\b/i', $inner );
				$got  = preg_match_all( '/<a\b/i', $translated );
				if ( $want !== $got ) {
					return $m[0];
				}

				return $m[1] . $translated . $m[4];
			},
			$html
		);

		// Translate <a> link text.
		$html = preg_replace_callback(
			'/(<a\b[^>]*>)([^<]+)(<\/a>)/i',
			array( $this, 'translate_link_text_callback' ),
			$html
		);
		// Translate copy inside named JS config objects (consent banner etc).
		$html = preg_replace_callback(
			'/(\bvar\s+[A-Z][A-Z0-9_]{3,}\s*=\s*)(\{.*?\})(\s*;)/s',
			function ( $m ) {
				$data = json_decode( $m[2], true );
				if ( ! is_array( $data ) ) {
					return $m[0];
				}
				$changed = false;
				$walked  = $this->translate_json_text( $data, $changed );
				if ( ! $changed ) {
					return $m[0];
				}
				$encoded = wp_json_encode( $walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! $encoded || null === json_decode( $encoded, true ) ) {
					return $m[0]; // never emit JS that will not parse
				}
				return $m[1] . $encoded . $m[3];
			},
			$html
		);

		// Labels that wrap a brand span (megamenu cards). Mirrors the extractor.
		$html = preg_replace_callback(
			'/(<(span|a)\b[^>]*>)((?:(?!<\/?(?:span|a)\b).)*?<span\b[^>]*class="[^"]*brand[^"]*"[^>]*>[^<]{2,60}<\/span>[^<]{3,140})(<\/\2>)/is',
			function ( $m ) {
				$candidate = $this->normalize_candidate( $m[3] );
				if ( mb_strlen( $candidate ) < 8 || mb_strlen( $candidate ) > 300 ) {
					return $m[0];
				}
				$t = $this->get_string_translation( $candidate );
				if ( ! $t ) {
					return $m[0];
				}
				// Refuse anything that lost the brand span.
				if ( substr_count( strtolower( $t ), '<span' ) !== substr_count( strtolower( $m[3] ), '<span' ) ) {
					return $m[0];
				}
				return $m[1] . $t . $m[4];
			},
			$html
		);

		// Text that precedes a nested child element (e.g. "Affiliates:" + <ul>).
		$html = preg_replace_callback(
			'/(<(?:li|td|th|dt|dd|h[1-6]|p|div)\b[^>]*>\s*)([^<>{}]{3,120}?)(\s*<(?:ul|ol|div|span|section|table|p)\b)/i',
			function ( $m ) {
				$text = $this->normalize_candidate( $m[2] );
				if ( ! $this->is_translatable_prose( $text ) ) {
					return $m[0];
				}
				$t = $this->get_string_translation( $text );
				return $t ? $m[1] . esc_html( $t ) . $m[3] : $m[0];
			},
			$html
		);

		// Translate <option> labels, leaving the submitted value untouched.
		$html = preg_replace_callback(
			'/(<option\b[^>]*>)([^<]{2,120})(<\/option>)/i',
			function ( $m ) {
				$text = $this->normalize_candidate( $m[2] );
				if ( mb_strlen( $text ) < 2 || preg_match( '/^[\d\s\.\-:\/\+]+$/u', $text ) ) {
					return $m[0];
				}
				$t = $this->get_string_translation( $text );
				return $t ? $m[1] . esc_html( $t ) . $m[3] : $m[0];
			},
			$html
		);
		$html = preg_replace_callback(
			'/(<optgroup\b[^>]*\blabel=")([^"]{2,120})(")/i',
			function ( $m ) {
				$text = $this->normalize_candidate( $m[2] );
				$t    = mb_strlen( $text ) >= 2 ? $this->get_string_translation( $text ) : '';
				return $t ? $m[1] . esc_attr( $t ) . $m[3] : $m[0];
			},
			$html
		);

		// Translate copy inside embedded JSON settings (Elementor data-settings).
		//
		// Decode, walk the allowlisted keys, re-encode. If anything fails to
		// round-trip the original attribute is returned untouched — a widget
		// that renders in English is recoverable, a widget with corrupt JSON
		// is a white screen.
		$html = preg_replace_callback(
			'/(<script\b[^>]*type=["\']application\/json["\'][^>]*>)(.*?)(<\/script>)/is',
			function ( $m ) {
				$data = json_decode( html_entity_decode( trim( $m[2] ), ENT_QUOTES, 'UTF-8' ), true );
				if ( ! is_array( $data ) ) {
					return $m[0];
				}

				$changed = false;
				$walked  = $this->translate_json_text( $data, $changed );
				if ( ! $changed ) {
					return $m[0];
				}

				$encoded = wp_json_encode( $walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! $encoded || null === json_decode( $encoded, true ) ) {
					return $m[0]; // never emit something that will not parse
				}

				return $m[1] . $encoded . $m[3];
			},
			$html
		);

		// Text after a nested block (stat labels etc). Mirrors the extractor.
		$html = preg_replace_callback(
			'/(<\/(?:div|p|span|h[1-6])>\s*)([^<>{}]{3,120}?)(\s*<\/(?:div|p|li|td)>)/u',
			function ( $m ) {
				$text = $this->normalize_candidate( $m[2] );
				if ( mb_strlen( $text ) < 3
					|| preg_match( '/^[\d\s\.\-:\/\+%]+$/u', $text )
					|| preg_match( '/^https?:/i', $text ) ) {
					return $m[0];
				}
				$t = $this->get_string_translation( $text );
				return $t ? $m[1] . $t . $m[3] : $m[0];
			},
			$html
		);

		// Translate text in block elements (same set as extract).
		$html = preg_replace_callback(
			'/(<(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption|section|article|summary|small)\b[^>]*>)([^<]{2,})(<\/(?:p|span|div|h[1-6]|li|td|th|label|figcaption|button|strong|em|b|dt|dd|blockquote|cite|caption|section|article|summary|small)>)/i',
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
				// Store keys are entity-DECODED (normalize_candidate); the page
				// text is not ("pressure &amp; flow"). Look up the decoded form,
				// replace the raw form, emit escaped.
				$translated = $this->get_string_translation( $this->normalize_candidate( $text ) );
				if ( $translated && $this->normalize_candidate( $text ) !== $text ) {
					$translated = esc_html( $translated );
				}
				if ( $translated ) {
					return str_replace( $text, $translated, $raw );
				}
				return $m[0];
			},
			$html
		);
		// Translate trailing text nodes (text after a closing inline tag).
		//
		// The closing side must include INLINE parents too. Icon-led items are
		// <span class="tm-ticker-item"><i class="fa-..."></i> Measure in 1 or
		// 2 planes</span>: the text sits between </i> and </span>. With only
		// block closers allowed, every such item stayed English on every
		// catalog page (11 per page on /catalog/axle-balancing-machines/) even
		// though the extractor had stored the translation. Found by the
		// 100-page Polish review; the render reported "complete" because
		// coverage counted the stored string, not the substituted page.
		$html = preg_replace_callback(
			'/(<\/(?:span|strong|em|a|i|b|small|sup|sub)>)([^<]{3,})(<\/(?:p|div|h[1-6]|li|td|th|label|figcaption|button|dt|dd|blockquote|span|a|strong|em|small)>)/u',
			function ( $m ) {
				$raw  = $m[2];
				$text = trim( $raw );
				if ( strlen( $text ) < 2
					|| preg_match( '/^[\d\s\.\-:\/\?!,]+$/', $text )
					|| preg_match( '/^https?:/', $text )
					|| preg_match( '/[{}<>]/', $text ) ) {
					return $m[0];
				}
				// Store keys are entity-DECODED (normalize_candidate); the page
				// text is not ("pressure &amp; flow"). Look up the decoded form,
				// replace the raw form, emit escaped.
				$translated = $this->get_string_translation( $this->normalize_candidate( $text ) );
				if ( $translated && $this->normalize_candidate( $text ) !== $text ) {
					$translated = esc_html( $translated );
				}
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
				// Store keys are entity-DECODED (normalize_candidate); the page
				// text is not ("pressure &amp; flow"). Look up the decoded form,
				// replace the raw form, emit escaped.
				$translated = $this->get_string_translation( $this->normalize_candidate( $text ) );
				if ( $translated && $this->normalize_candidate( $text ) !== $text ) {
					$translated = esc_html( $translated );
				}
				if ( $translated ) {
					return $m[1] . str_replace( $text, $translated, $raw ) . $m[3];
				}
				return $m[0];
			},
			$html
		);
		// Translate accessibility and media attributes.
		foreach ( array( 'alt', 'aria-label', 'title' ) as $attr ) {
			$html = preg_replace_callback(
				'/\b(' . preg_quote( $attr, '/' ) . ')=(["\'])([^"\']{2,}?)\2/i',
				function ( $m ) {
					$m    = array( $m[0], $m[1], $m[3] );
					$text = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
					if ( preg_match( '#^(https?://|[\d\s\.\-:/]+$)#', $text ) ) {
						return $m[0];
					}
					$translated = $this->get_string_translation( $text );
					return $translated
						? $m[1] . '="' . esc_attr( $translated ) . '"'
						: $m[0];
				},
				$html
			);
		}

		// Translate form placeholder attributes.
		$html = preg_replace_callback(
			'/\bplaceholder=(?:"([^"]{2,})"|\'([^\']{2,})\')/i',
			function ( $m ) {
				$raw        = '' !== $m[1] ? $m[1] : ( $m[2] ?? '' );
				$original   = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
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

		// ROOT-RELATIVE LINKS. The pass above only matches ABSOLUTE same-site
		// URLs, so every href="/catalog/" survived untouched. Measured on the
		// live Polish homepage: 0 of 67 relative links carried /pl/, which is
		// what the reviewer meant by "links leave the Polish site". A visitor
		// clicking almost any nav or footer item landed on English, and the
		// language signal died with them.
		//
		// Protocol-relative (//host) and anchors (#x) must be left alone, hence
		// the negative lookaheads.
		$html = preg_replace_callback(
			'/href="\/(?!\/|wp-admin|wp-content|wp-includes|wp-json|wp-login|feed|xmlrpc|wp-cron|(?:' . $codes . ')\/)([^"]*)"/',
			function ( $m ) use ( $lang ) {
				$path = $m[1];

				// Leave asset and non-page targets where they are.
				if ( preg_match( '/\.(css|js|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|pdf|zip|mp4|webm|xml|txt)(\?|#|$)/i', $path ) ) {
					return $m[0];
				}

				return 'href="/' . $lang . '/' . $path . '"';
			},
			$html
		);

		// Bare root link: href="/" -> href="/<lang>/".
		$html = preg_replace(
			'/href="\/(?!\/)"/',
			'href="/' . $lang . '/"',
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
	 * Expand the switcher at the DATA layer, for renderers that skip wp_nav_menu().
	 *
	 * wp_nav_menu_objects only fires inside wp_nav_menu(). A theme or plugin
	 * that calls wp_get_nav_menu_items() and writes its own markup — as this
	 * site's ac-tm-megamenu does — never triggers it, so the switcher appeared
	 * as an inert "Language" item with no submenu.
	 *
	 * Runs on the raw item list instead, which both paths share. Guarded so it
	 * cannot double-expand when wp_nav_menu() is used: the later filter sees
	 * items whose URLs are already real language URLs, not the magic token.
	 *
	 * @param array  $items Menu items.
	 * @param object $menu  Menu object.
	 * @param array  $args  Query args.
	 * @return array
	 */
	public function expand_language_menu_items_raw( $items, $menu = null, $args = array() ) {
		if ( is_admin() || empty( $items ) || ! is_array( $items ) ) {
			return $items;
		}

		$has_token = false;
		foreach ( $items as $it ) {
			if ( isset( $it->url ) && '#acwpt-language-switcher' === $it->url ) {
				$has_token = true;
				break;
			}
		}
		if ( ! $has_token ) {
			return $items;
		}

		return $this->expand_language_menu_items( $items, (object) $args );
	}

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
	 * Fold the translated sitemap into the SEO plugin's sitemap index.
	 *
	 * The site was advertising TWO sitemaps. Yoast's /sitemap_index.xml lists
	 * 14 children and contains zero translated URLs; ours lists 11 and contains
	 * every language. A search engine that finds the Yoast index first — which
	 * it will, because that is the conventional path and the one linked from
	 * the SEO plugin's own output — sees an English-only site and no signal
	 * that translations exist.
	 *
	 * Adding our index as an extra entry means either discovery path reaches
	 * the full set, without asking the client to disable their SEO plugin's
	 * sitemap.
	 *
	 * @param string $links Existing sitemap index entries (Yoast).
	 * @return string
	 */
	public function add_to_seo_sitemap_index( $links ) {
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return $links;
		}
		$links .= "<sitemap>\n"
			. "<loc>" . esc_url( home_url( '/acwpt-sitemap.xml' ) ) . "</loc>\n"
			. "<lastmod>" . esc_html( gmdate( 'c' ) ) . "</lastmod>\n"
			. "</sitemap>\n";
		return $links;
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
