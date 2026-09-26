<?php
/**
 * Language term bases: enforced industry terminology.
 *
 * A 100-page blind review of the Polish site found wrong industry terms on
 * 83 of 91 hand-read pages ("transmisja" for gearbox, "chłodziwo" for
 * refrigerant, "wyważarka bez wirnika" for a non-rotating balancer). The model
 * was never told the trade word, and a long prose rule pack did not help: the
 * same concept was rendered two or three ways on one page.
 *
 * Each language may ship includes/prompts/terms/<lang>.php returning a list of
 *   array( 'en' => source term, '<lang>' => canonical translation,
 *          'not' => array( banned renderings seen in the wild ) )
 *
 * Enforcement, in translate_strings():
 *   1. PROMPT: only the entries whose English term occurs in the batch are
 *      sent, as a compact "use exactly" table, so the prompt stays small and
 *      cacheable across batches that share terms.
 *   2. GATE: every returned string is checked for banned renderings of a
 *      term whose English is in its source. A hit is re-translated on its
 *      own with an explicit correction; if the retry still contains a banned
 *      form, the string is NOT stored (it stays missing and is retried later)
 *      rather than shipping a known-wrong term.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Terms {

	/** @var array<string,array> */
	private static $cache = array();

	/**
	 * Load a language's term base. Missing file = no terms (feature is inert).
	 *
	 * @param string $lang
	 * @return array[] each: en, target, not[]
	 */
	public static function load( $lang ) {
		$lang = preg_replace( '/[^a-z0-9_-]/i', '', strtolower( (string) $lang ) );
		if ( isset( self::$cache[ $lang ] ) ) {
			return self::$cache[ $lang ];
		}
		$path = ( defined( 'ACWPT_PLUGIN_DIR' ) ? rtrim( ACWPT_PLUGIN_DIR, '/\\' ) . '/includes' : __DIR__ ) . '/prompts/terms/' . $lang . '.php';
		$rows = file_exists( $path ) ? require $path : array();
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$en = isset( $r['en'] ) ? trim( (string) $r['en'] ) : '';
			$tr = isset( $r[ $lang ] ) ? trim( (string) $r[ $lang ] ) : '';
			if ( '' === $en || '' === $tr ) {
				continue;
			}
			$not = array();
			foreach ( (array) ( $r['not'] ?? array() ) as $n ) {
				$n = trim( (string) $n );
				// A banned form must never equal the canonical form, and very
				// short banned forms would match inside ordinary words.
				if ( '' !== $n && mb_strtolower( $n ) !== mb_strtolower( $tr ) && mb_strlen( $n ) >= 4 ) {
					$not[] = $n;
				}
			}
			$out[] = array( 'en' => $en, 'target' => $tr, 'not' => $not );
		}
		// Longest English term first, so "residual unbalance" wins over "unbalance".
		usort(
			$out,
			function ( $a, $b ) {
				return mb_strlen( $b['en'] ) - mb_strlen( $a['en'] );
			}
		);
		self::$cache[ $lang ] = $out;
		return $out;
	}

	/**
	 * Entries whose English term appears in any of the given source strings.
	 *
	 * @param string   $lang
	 * @param string[] $sources
	 * @return array[]
	 */
	public static function relevant( $lang, array $sources ) {
		$terms = self::load( $lang );
		if ( ! $terms ) {
			return array();
		}
		$hay = mb_strtolower( wp_strip_all_tags( implode( "\n", $sources ) ) );
		$hit = array();
		foreach ( $terms as $t ) {
			// en '*' = a language-wide ban (e.g. informal imperatives under a
			// formal register). It applies to every source string.
			if ( '*' === $t['en'] || self::contains_word( $hay, mb_strtolower( $t['en'] ) ) ) {
				$hit[] = $t;
			}
		}
		return $hit;
	}

	/**
	 * Compact prompt block for the relevant entries only.
	 *
	 * @param array[] $entries
	 * @return string
	 */
	public static function prompt_block( array $entries ) {
		if ( ! $entries ) {
			return '';
		}
		$lines = array( 'REQUIRED TERMINOLOGY for this batch. Use exactly these renderings (inflect them as the sentence requires; never substitute a synonym, never use a form listed as wrong):' );
		foreach ( $entries as $e ) {
			if ( '*' === $e['en'] ) {
				$lines[] = '- NEVER write: ' . implode( ', ', $e['not'] ) . '. ' . $e['target'];
				continue;
			}
			$line = '- "' . $e['en'] . '" = "' . $e['target'] . '"';
			if ( $e['not'] ) {
				$line .= '  (wrong: ' . implode( ', ', array_slice( $e['not'], 0, 4 ) ) . ')';
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Banned renderings present in a translation whose source contains the term.
	 *
	 * Matching is on word stems: a banned form matches if every one of its
	 * words appears as a word PREFIX in order, so inflected forms
	 * ("transmisji", "transmisją") are caught by the banned "transmisja" via
	 * its stem. The stem is the word minus its last 1-2 letters, never shorter
	 * than 4 letters, which keeps common short words from triggering.
	 *
	 * @param string  $source
	 * @param string  $translation
	 * @param array[] $entries
	 * @return array[] each: en, target, found
	 */
	public static function violations( $source, $translation, array $entries ) {
		$src = mb_strtolower( wp_strip_all_tags( (string) $source ) );
		$out = mb_strtolower( wp_strip_all_tags( (string) $translation ) );
		$bad = array();
		foreach ( $entries as $e ) {
			if ( '*' === $e['en'] ) {
				// Exact whole-word match only: a stem would catch legitimate
				// formal words ('Odkryj' must not match 'odkrywamy').
				foreach ( $e['not'] as $n ) {
					if ( self::contains_word( $out, mb_strtolower( $n ) ) ) {
						$bad[] = array( 'en' => '*', 'target' => $e['target'], 'found' => $n );
						break;
					}
				}
				continue;
			}
			if ( ! self::contains_word( $src, mb_strtolower( $e['en'] ) ) ) {
				continue;
			}
			foreach ( $e['not'] as $n ) {
				// For CJK the canonical form is often a substring of the banned
				// one ("加工单元" inside "加工单元格", "调试" inside "委托调试"), so
				// "canonical present" says nothing. Judge the banned form alone.
				$cjk = (bool) preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $n . $e['target'] );
				if ( self::contains_stems( $out, mb_strtolower( $n ) )
					&& ( $cjk || ! self::contains_stems( $out, mb_strtolower( $e['target'] ) ) ) ) {
					$bad[] = array( 'en' => $e['en'], 'target' => $e['target'], 'found' => $n );
					break;
				}
			}
		}
		return $bad;
	}

	private static function contains_word( $hay, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		// Chinese/Japanese have no word spacing: a term sits between other
		// letters, so a word-boundary match can never fire and the gate would
		// be silently inert for zh. Plain substring there.
		if ( preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $needle ) ) {
			return false !== mb_strpos( $hay, $needle );
		}
		return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $hay );
	}

	private static function contains_stems( $hay, $phrase ) {
		if ( preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $phrase ) ) {
			return false !== mb_strpos( $hay, trim( $phrase ) ); // no inflection in CJK
		}
		// A Latin-script banned form inside CJK output (English left untranslated,
		// e.g. "Motor Graders" in a Chinese page) has no letters around it to
		// stem against; match it literally, case-insensitively.
		if ( preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $hay ) ) {
			return false !== mb_stripos( $hay, trim( $phrase ) );
		}
		$words = preg_split( '/\s+/u', trim( $phrase ) );
		$parts = array();
		foreach ( $words as $w ) {
			$len = mb_strlen( $w );
			if ( $len >= 6 ) {
				$w = mb_substr( $w, 0, $len - 2 );
			} elseif ( $len >= 5 ) {
				$w = mb_substr( $w, 0, $len - 1 );
			}
			$parts[] = '(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '\p{L}*';
		}
		return (bool) preg_match( '/' . implode( '[\s\-–]+', $parts ) . '/u', $hay );
	}
}
