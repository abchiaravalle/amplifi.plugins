<?php
/**
 * Per-language llms.txt.
 *
 * llms.txt is the emerging convention for telling LLM answer engines what a
 * site is and which pages matter. The site already had one, but it was English
 * only, described a 7-language site while 11 were live, and listed zero
 * translated URLs — so an LLM answering a German buyer's question had no German
 * entry point to cite.
 *
 * Design decisions worth keeping:
 *
 * - Translations are STORED, not generated per request. An LLM crawler must
 *   never trigger a synchronous API call, and the content is editorial: once a
 *   human has corrected it, a re-translation must not silently overwrite them.
 * - Every language is editable in wp-admin after translation. Machine output is
 *   the starting point, not the deliverable.
 * - URLs inside the document are rewritten to the language prefix, because a
 *   German llms.txt pointing at English URLs sends the crawler to the wrong
 *   page.
 *
 * @package amplifi-translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Llms {

	/** Option holding per-language documents: [ lang => text ]. */
	const OPTION = 'acwpt_llms_txt';

	/** Option holding per-language last-edited metadata. */
	const META_OPTION = 'acwpt_llms_meta';

	public static function init() {
		// Serve early, straight off REQUEST_URI.
		//
		// Two layers claim these URLs before a normal hook would see them:
		//
		//   1. The feature's own language rewrite rules match /de/llms.txt and
		//      turn it into index.php?pagename=llms.txt&acwpt_lang=de, so by
		//      parse_request the path is already a page query.
		//   2. More fundamentally, WP Engine's nginx treats a .txt extension as
		//      a static file. /de/llms.txt never reaches PHP at all — measured:
		//      a 404 carrying only Cloudflare headers, no WordPress headers,
		//      while /llms.txt worked because a real file exists at the root.
		//
		// Layer 2 cannot be solved in PHP, so the canonical per-language URL is
		// /llms-<lang>.txt, which nginx does not shadow. /<lang>/llms.txt is
		// still accepted when it reaches us, for hosts that pass it through.
		add_action( 'init', array( __CLASS__, 'maybe_serve_early' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'wp_ajax_acwpt_translate_llms', array( __CLASS__, 'ajax_translate' ) );
		add_action( 'wp_ajax_acwpt_save_llms', array( __CLASS__, 'ajax_save' ) );
	}

	/**
	 * Public URL for a language's document.
	 *
	 * /<lang>/llms.txt — matching the convention every llms.txt-aware crawler
	 * already probes. The spec (llmstxt.org) defines only the root /llms.txt
	 * and says nothing about multilingual sites, so there is no official
	 * answer here; this mirrors the de-facto pattern used for robots.txt and
	 * sitemaps, and keeps the filename a crawler is actually looking for.
	 *
	 * Backed by a REAL FILE, not a PHP route: WP Engine's nginx serves any
	 * *.txt path from disk and never reaches PHP, so a dynamic handler at this
	 * URL cannot work. Files are written on save/translate by write_files().
	 */
	public static function url( $lang ) {
		if ( $lang === ACWPT_Languages::get_source() ) {
			return home_url( '/llms.txt' );
		}
		return home_url( '/llms.' . $lang . '.txt' );
	}

	/**
	 * Write every stored document to disk so the host can serve it.
	 *
	 * Called after any save or translate. Static files are also the right
	 * shape for this content: an LLM crawler gets it with zero PHP, and it
	 * survives the plugin being disabled.
	 *
	 * @return array [ lang => bytes|false ]
	 */
	public static function write_files() {
		$written = array();
		$root    = trailingslashit( ABSPATH );
		$source  = ACWPT_Languages::get_source();

		// FLAT FILENAMES, never a per-language directory.
		//
		// Writing /de/llms.txt created a real /de/ directory in the webroot.
		// nginx resolves a real directory before it ever reaches WordPress,
		// found no index.php inside, and returned 403 — so EVERY language
		// homepage went down (/de/, /pl/, /fr/ ... all 403) while deep pages
		// like /de/events/ still worked, because those match no real path.
		// A two-line convenience took out ten homepages.
		//
		// llms.<lang>.txt keeps the .txt extension the convention expects and
		// cannot collide with a rewrite-driven URL.
		foreach ( array_merge( array( $source ), ACWPT_Languages::get_enabled_codes() ) as $lang ) {
			$body = self::get( $lang );
			if ( '' === trim( (string) $body ) ) {
				continue;
			}

			$path = ( $lang === $source )
				? $root . 'llms.txt'
				: $root . 'llms.' . $lang . '.txt';

			$ok               = @file_put_contents( $path, $body ); // phpcs:ignore
			$written[ $lang ] = ( false === $ok ) ? false : $ok;
		}

		return $written;
	}

	/**
	 * Remove language directories created by an earlier version.
	 *
	 * Only deletes a directory that contains nothing but our own llms.txt, so
	 * it can never touch a real site directory that happens to share a
	 * language code.
	 */
	public static function cleanup_language_dirs() {
		$root    = trailingslashit( ABSPATH );
		$removed = array();

		foreach ( ACWPT_Languages::get_enabled_codes() as $lang ) {
			$dir = $root . $lang;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$entries = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
			if ( array_values( $entries ) !== array( 'llms.txt' ) ) {
				continue; // Not ours alone: leave it completely alone.
			}
			@unlink( $dir . '/llms.txt' ); // phpcs:ignore
			if ( @rmdir( $dir ) ) {       // phpcs:ignore
				$removed[] = $lang;
			}
		}

		return $removed;
	}

	/**
	 * Serve llms.txt straight from REQUEST_URI, ahead of the rewrite rules.
	 */
	public static function maybe_serve_early() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return;
		}
		if ( false === stripos( $path, 'llms' ) ) {
			return;
		}
		self::serve_path( $path );
	}

	/**
	 * Serve /llms.txt and /<lang>/llms.txt.
	 */
	public static function maybe_serve( $wp ) {
		if ( ! isset( $wp->request ) ) {
			return;
		}
		self::serve_path( trim( $wp->request, '/' ) );
	}

	/**
	 * Resolve a request path to a language document and emit it.
	 *
	 * @param string $req Path with no leading or trailing slash.
	 */
	private static function serve_path( $req ) {
		$source = ACWPT_Languages::get_source();
		$lang   = null;

		if ( 'llms.txt' === $req ) {
			$lang = $source;
		} elseif ( preg_match( '#^llms\.([a-z]{2})\.txt$#i', $req, $m ) ) {
			$lang = strtolower( $m[1] );
		} elseif ( preg_match( '#^([a-z]{2})/llms\.txt$#i', $req, $m ) ) {
			// Legacy path from an earlier version; kept so existing links and
			// any crawler that already saw it still resolve.
			$lang = strtolower( $m[1] );
		} else {
			return;
		}

		if ( $lang !== $source && ! in_array( $lang, ACWPT_Languages::get_enabled_codes(), true ) ) {
			return; // Unknown language: let WordPress 404.
		}

		$body = self::get( $lang );
		if ( '' === trim( (string) $body ) ) {
			return; // Nothing authored for this language yet: 404 rather than
			        // serve an empty document that looks like a broken site.
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Language: ' . ACWPT_Languages::bcp47( $lang ) );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Stored document for a language, falling back to the source language.
	 */
	public static function get( $lang ) {
		$all = (array) get_option( self::OPTION, array() );
		if ( isset( $all[ $lang ] ) && '' !== trim( (string) $all[ $lang ] ) ) {
			return (string) $all[ $lang ];
		}
		return '';
	}

	public static function set( $lang, $text, $by = 'manual' ) {
		$all          = (array) get_option( self::OPTION, array() );
		$all[ $lang ] = (string) $text;
		update_option( self::OPTION, $all, false );

		$meta          = (array) get_option( self::META_OPTION, array() );
		$meta[ $lang ] = array(
			'updated' => time(),
			'by'      => $by,
			'user'    => get_current_user_id(),
			'bytes'   => strlen( (string) $text ),
		);
		update_option( self::META_OPTION, $meta, false );

		// Keep disk in step with the store. The option is the source of truth
		// (survives a filesystem wipe, travels with a DB export); the file is
		// what the host actually serves.
		self::write_files();
	}

	public static function meta( $lang ) {
		$meta = (array) get_option( self::META_OPTION, array() );
		return isset( $meta[ $lang ] ) ? $meta[ $lang ] : array();
	}

	/**
	 * Seed the source document from the live file if nothing is stored yet.
	 */
	public static function maybe_seed_source() {
		$source = ACWPT_Languages::get_source();
		if ( '' !== self::get( $source ) ) {
			return;
		}
		$path = ABSPATH . 'llms.txt';
		if ( file_exists( $path ) ) {
			self::set( $source, file_get_contents( $path ), 'seed' );
		}
	}

	/**
	 * Translate the source document into one target language.
	 *
	 * Line-by-line rather than whole-document, because llms.txt is structured
	 * markdown: headings and link labels are prose, but URLs and bare markdown
	 * syntax must survive byte-identical. Sending the whole file risks the
	 * model reflowing the structure.
	 *
	 * @return array|WP_Error
	 */
	public static function translate_to( $lang ) {
		$source_lang = ACWPT_Languages::get_source();
		$src         = self::get( $source_lang );

		if ( '' === trim( $src ) ) {
			return new WP_Error( 'acwpt_llms_empty', 'No source llms.txt stored. Seed or paste it first.' );
		}
		if ( $lang === $source_lang ) {
			return new WP_Error( 'acwpt_llms_same', 'That is the source language.' );
		}

		$lines      = preg_split( '/\r\n|\r|\n/', $src );
		$to_translate = array();
		foreach ( $lines as $i => $line ) {
			$stripped = trim( $line );
			if ( '' === $stripped ) {
				continue;
			}
			// Pull the human-readable part out of a markdown link, leave the URL.
			if ( preg_match( '/^\s*-?\s*\[([^\]]+)\]\(([^)]+)\)\s*:?\s*(.*)$/', $line, $m ) ) {
				if ( strlen( trim( $m[1] ) ) > 1 ) { $to_translate[ 'L' . $i . 'a' ] = trim( $m[1] ); }
				if ( strlen( trim( $m[3] ) ) > 1 ) { $to_translate[ 'L' . $i . 'b' ] = trim( $m[3] ); }
				continue;
			}
			// Skip bare URLs and pure punctuation/syntax lines.
			if ( preg_match( '#^\s*(https?://|<|\||-{3,}|={3,})#', $stripped ) ) {
				continue;
			}
			// Headings: translate the text, keep the hashes.
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $stripped, $m ) ) {
				$to_translate[ 'L' . $i . 'h' ] = trim( $m[2] );
				continue;
			}
			if ( strlen( $stripped ) > 2 ) {
				$to_translate[ 'L' . $i ] = $stripped;
			}
		}

		if ( empty( $to_translate ) ) {
			return new WP_Error( 'acwpt_llms_nothing', 'Nothing translatable found in the source document.' );
		}

		$map = array();
		foreach ( array_chunk( $to_translate, 25, true ) as $chunk ) {
			$res = ACWPT_Translator::translate_strings( array_values( $chunk ), $lang );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$keys = array_keys( $chunk );
			$vals = array_values( $chunk );
			foreach ( $vals as $n => $original ) {
				if ( isset( $res[ $original ] ) ) {
					$map[ $keys[ $n ] ] = $res[ $original ];
				}
			}
		}

		// Reassemble, preserving structure and prefixing same-site URLs.
		$out = array();
		foreach ( $lines as $i => $line ) {
			$stripped = trim( $line );
			if ( '' === $stripped ) { $out[] = ''; continue; }

			if ( preg_match( '/^(\s*-?\s*)\[([^\]]+)\]\(([^)]+)\)(\s*:?\s*)(.*)$/', $line, $m ) ) {
				$label = isset( $map[ 'L' . $i . 'a' ] ) ? $map[ 'L' . $i . 'a' ] : $m[2];
				$tail  = isset( $map[ 'L' . $i . 'b' ] ) ? $map[ 'L' . $i . 'b' ] : $m[5];
				$out[] = $m[1] . '[' . $label . '](' . self::localize_url( $m[3], $lang ) . ')' . $m[4] . $tail;
				continue;
			}
			if ( preg_match( '/^(\s*)(#{1,6})\s+(.+)$/', $line, $m ) ) {
				$h     = isset( $map[ 'L' . $i . 'h' ] ) ? $map[ 'L' . $i . 'h' ] : $m[3];
				$out[] = $m[1] . $m[2] . ' ' . $h;
				continue;
			}
			$out[] = isset( $map[ 'L' . $i ] ) ? $map[ 'L' . $i ] : $line;
		}

		// FINAL PASS: localise every remaining same-site URL.
		//
		// Doing this per-branch above missed half of them — 53 of 102 on the
		// first run — because URLs also appear bare inside translated prose,
		// inside lines the model rewrote, and in list items that did not match
		// the markdown-link pattern. One sweep over the finished document
		// catches every occurrence regardless of how the line was handled.
		// localize_url() is idempotent, so a URL already prefixed above is
		// left alone.
		$text = implode( "\n", $out );
		$text = preg_replace_callback(
			'#https?://[^\s\)\]<>"\']+#',
			function ( $u ) use ( $lang ) { return self::localize_url( $u[0], $lang ); },
			$text
		);

		self::set( $lang, $text, 'translated' );

		return array( 'lang' => $lang, 'bytes' => strlen( $text ), 'lines' => count( $out ) );
	}

	/**
	 * Add the language prefix to a URL belonging to THIS site.
	 *
	 * Matches on host, not on the full home_url(), because the stored source
	 * document routinely carries PRODUCTION URLs while the plugin runs on
	 * staging. Comparing full URLs meant every link was treated as third-party
	 * and left alone: measured 0 of 104 rewritten, and an earlier audit found
	 * 20/20 sampled links redirecting. Host matching also covers www vs
	 * non-www and http vs https.
	 *
	 * Known same-site hosts are the WordPress home, the site URL, and any
	 * additional hosts an operator lists in the acwpt_llms_site_hosts filter —
	 * a client's llms.txt may legitimately reference their canonical domain
	 * while the plugin is exercised on a staging clone.
	 */
	private static function localize_url( $url, $lang ) {
		if ( $lang === ACWPT_Languages::get_source() ) {
			return $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return $url;
		}
		$host = strtolower( preg_replace( '/^www\./i', '', $host ) );

		$known = array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( site_url(), PHP_URL_HOST ),
		);

		/**
		 * Filter the hosts treated as belonging to this site.
		 *
		 * @param string[] $hosts
		 */
		$known = apply_filters( 'acwpt_llms_site_hosts', $known );

		$known = array_filter( array_map(
			function ( $h ) { return strtolower( preg_replace( '/^www\./i', '', (string) $h ) ); },
			(array) $known
		) );

		if ( ! in_array( $host, $known, true ) ) {
			return $url; // Genuinely third-party: never rewrite.
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#^/[a-z]{2}/#', $path ) ) {
			return $url; // Already language-prefixed.
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https';
		$orig   = wp_parse_url( $url, PHP_URL_HOST );
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		$frag   = wp_parse_url( $url, PHP_URL_FRAGMENT );

		$out = $scheme . '://' . $orig . '/' . $lang . ( '' === $path ? '/' : $path );
		if ( $query ) { $out .= '?' . $query; }
		if ( $frag )  { $out .= '#' . $frag; }

		return $out;
	}

	// ---------------------------------------------------------------- AJAX --

	public static function ajax_translate() {
		check_ajax_referer( 'acwpt_llms', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		self::maybe_seed_source();

		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$targets = ( 'all' === $lang ) ? ACWPT_Languages::get_enabled_codes() : array( $lang );

		$done = array();
		$errs = array();
		foreach ( $targets as $code ) {
			$r = self::translate_to( $code );
			if ( is_wp_error( $r ) ) {
				$errs[ $code ] = $r->get_error_message();
			} else {
				$done[] = $r;
			}
		}

		wp_send_json_success( array( 'translated' => $done, 'errors' => $errs ) );
	}

	public static function ajax_save() {
		check_ajax_referer( 'acwpt_llms', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$text = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : '';

		$valid = array_merge( array( ACWPT_Languages::get_source() ), ACWPT_Languages::get_enabled_codes() );
		if ( ! in_array( $lang, $valid, true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown language.' ), 400 );
		}

		self::set( $lang, $text, 'manual' );
		wp_send_json_success( array( 'lang' => $lang, 'bytes' => strlen( $text ) ) );
	}
}
