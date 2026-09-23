<?php
/**
 * Translate JSON-LD structured data.
 *
 * Every translated page was emitting schema that declared itself en-US, which
 * contradicts <html lang>, the hreflang cluster and og:locale — all of which
 * are correct. Search engines and LLM answer engines read JSON-LD to decide
 * what a page is about and which audience it serves; a German page whose
 * schema says en-US is telling them the body copy is a mistake.
 *
 * Handles Yoast, RankMath, AIOSEO and any hand-rolled graph, because it works
 * on the rendered JSON rather than hooking a vendor API.
 *
 * @package amplifi-translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Schema {

	/**
	 * Properties whose VALUES are human-readable prose and should be translated.
	 *
	 * Deliberately conservative. Translating the wrong key breaks the graph:
	 * @type, @id, @context, url, image, sameAs, telephone, email, sku, price
	 * and every identifier must survive byte-identical or the structured data
	 * stops validating.
	 */
	const TEXT_KEYS = array(
		'name',
		'headline',
		'alternateName',
		'description',
		'articleSection',
		'jobTitle',
		'caption',
		'text',
		'abstract',
		'disambiguatingDescription',
		'slogan',
		'keywords',
		'acceptedAnswer',
		'question',
		'answerCount',
	);

	/**
	 * Properties that are URLs and must be language-prefixed, not translated.
	 */
	const URL_KEYS = array( 'url', '@id', 'mainEntityOfPage', 'target', 'urlTemplate' );

	public static function init() {
		// Late so the SEO plugin has already rendered its graph.
		add_filter( 'acwpt_filter_output', array( __CLASS__, 'filter_html' ), 50, 2 );
	}

	/**
	 * Rewrite every JSON-LD block in the page for the current language.
	 *
	 * @param string $html Full page HTML.
	 * @param string $lang Target language code.
	 * @return string
	 */
	public static function filter_html( $html, $lang ) {
		if ( false === stripos( $html, 'application/ld+json' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'#(<script[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
			function ( $m ) use ( $lang ) {
				$decoded = json_decode( trim( $m[2] ), true );
				if ( ! is_array( $decoded ) ) {
					return $m[0]; // Malformed or unexpected: never mangle it.
				}
				$walked  = self::walk( $decoded, $lang );
				$encoded = wp_json_encode( $walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! $encoded ) {
					return $m[0];
				}
				return $m[1] . $encoded . $m[3];
			},
			$html
		);
	}

	/**
	 * Recursively translate prose values and localise language + URL fields.
	 *
	 * @param mixed  $node
	 * @param string $lang
	 * @return mixed
	 */
	private static function walk( $node, $lang ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}

		$out = array();
		foreach ( $node as $key => $value ) {
			// inLanguage is the whole point: report the ACTUAL language.
			if ( 'inLanguage' === $key ) {
				$out[ $key ] = ACWPT_Languages::bcp47( $lang );
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::walk( $value, $lang );
				continue;
			}

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$out[ $key ] = $value;
				continue;
			}

			// URLs get a language prefix, never a translation.
			if ( in_array( $key, self::URL_KEYS, true ) ) {
				$out[ $key ] = self::localize_url( $value, $lang );
				continue;
			}

			// Prose: translate from cache only. This runs during page render,
			// so it must never block on an API call; a miss is queued by the
			// normal string pipeline and served translated next request.
			if ( in_array( $key, self::TEXT_KEYS, true ) && strlen( $value ) > 2 ) {
				// Skip anything that is obviously not prose.
				if ( preg_match( '#^(https?://|[\d\s\.\-:/]+$|[A-Z0-9_\-]{2,10}$)#', $value ) ) {
					$out[ $key ] = $value;
					continue;
				}
				$fe = ACWPT_Frontend::instance();
				$t  = $fe->get_string_translation( $value );
				$out[ $key ] = $t ? $t : $value;
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Add the language prefix to a same-site URL.
	 *
	 * A @id or url pointing at the English page makes the graph describe the
	 * wrong document, which is the same self-cancelling signal the canonical
	 * bug caused.
	 */
	private static function localize_url( $url, $lang ) {
		if ( $lang === ACWPT_Languages::get_source() ) {
			return $url;
		}
		$home = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url; // Off-site: leave alone.
		}
		if ( false !== strpos( $url, '/' . $lang . '/' ) ) {
			return $url; // Already prefixed.
		}
		$path = substr( $url, strlen( $home ) );
		return $home . '/' . $lang . ( '' === $path ? '/' : $path );
	}
}
