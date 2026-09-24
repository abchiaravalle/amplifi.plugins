<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Translator {

	/**
	 * Model pricing per token. Verify against Anthropic's published rates
	 * before each release (https://www.anthropic.com/pricing).
	 * Unknown models fall back to Sonnet pricing.
	 */
	private static $pricing = array(
		'claude-haiku-4-5'  => array( 'input' => 0.000001,  'output' => 0.000005 ),
		'claude-sonnet-4-5' => array( 'input' => 0.000003,  'output' => 0.000015 ),
		'claude-sonnet-4-6' => array( 'input' => 0.000003,  'output' => 0.000015 ),
		'claude-opus-4-5'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
		'claude-opus-4-6'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
	);

	private static $default_model = 'claude-haiku-4-5';

	/**
	 * Translate a post's title, content, and excerpt via Anthropic Claude.
	 */
	public static function translate( $title, $content, $excerpt, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
		}

		$custom = array(
			'never_translate'     => isset( $settings['never_translate'] )     ? (array) $settings['never_translate'] : array(),
			'glossary'            => isset( $settings['glossary'] )            ? (array) $settings['glossary']        : array(),
			'custom_instructions' => isset( $settings['custom_instructions'] ) ? (array) $settings['custom_instructions'] : array(),
		);

		// Apply sentinels to the source text before assembling the user message.
		$glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

		$title   = ACWPT_Glossary::apply_keep_sentinels( $title,   $custom['never_translate'] );
		$content = ACWPT_Glossary::apply_keep_sentinels( $content, $custom['never_translate'] );
		$excerpt = ACWPT_Glossary::apply_keep_sentinels( $excerpt, $custom['never_translate'] );

		$title   = ACWPT_Glossary::apply_glossary_sentinels( $title,   $glossary_entries );
		$content = ACWPT_Glossary::apply_glossary_sentinels( $content, $glossary_entries );
		$excerpt = ACWPT_Glossary::apply_glossary_sentinels( $excerpt, $glossary_entries );

		$system_prompt = ACWPT_Prompts::build_content_prompt( $language, $custom );

		$has_excerpt  = ! empty( trim( $excerpt ) );
		$user_message = "===TITLE===\n{$title}\n\n===CONTENT===\n{$content}";
		if ( $has_excerpt ) {
			$user_message .= "\n\n===EXCERPT===\n{$excerpt}";
		}

		// Scale the timeout with the amount of text being translated. A fixed 60s
		// was not enough for large page-builder posts: an 861KB rendered page
		// failed with "cURL error 28: Operation timed out after 60001ms" on
		// staging, leaving the post untranslated while its strings succeeded.
		// Output is roughly proportional to input, so budget from source length.
		$source_chars = strlen( $title ) + strlen( $content ) + strlen( $excerpt );
		$timeout      = (int) max( 60, min( 300, ceil( $source_chars / 250 ) ) );

		$data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 16000, $timeout );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		self::record_usage( $data, $model, 'content' );

		$translated_text = $data['content'][0]['text'];

		$parsed = self::parse_response( $translated_text, $has_excerpt );

		// Strip glossary wrappers (replace with mandated translation), then strip never-translate wrappers.
		foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
			$parsed[ $k ] = ACWPT_Glossary::strip_glossary_sentinels( $parsed[ $k ] );
			$parsed[ $k ] = ACWPT_Glossary::strip_keep_sentinels( $parsed[ $k ] );
		}

		return $parsed;
	}

	/**
	 * Batch translate an array of short strings via Anthropic Claude.
	 */
	public static function translate_strings( $strings, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
		}
		if ( empty( $strings ) ) {
			return array();
		}

		$custom = array(
			'never_translate'     => isset( $settings['never_translate'] )     ? (array) $settings['never_translate'] : array(),
			'glossary'            => isset( $settings['glossary'] )            ? (array) $settings['glossary']        : array(),
			'custom_instructions' => isset( $settings['custom_instructions'] ) ? (array) $settings['custom_instructions'] : array(),
		);
		$glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

		$originals = array_values( $strings );
		$indexed   = array();
		foreach ( $originals as $i => $s ) {
			$wrapped = ACWPT_Glossary::apply_keep_sentinels( $s, $custom['never_translate'] );
			$wrapped = ACWPT_Glossary::apply_glossary_sentinels( $wrapped, $glossary_entries );
			$indexed[ (string) $i ] = $wrapped;
		}

		$system_prompt = ACWPT_Prompts::build_strings_prompt( $language, $custom );
		$user_message  = wp_json_encode( $indexed, JSON_UNESCAPED_UNICODE );

		// Budget the timeout from the WHOLE request, not just the payload.
		//
		// Sizing on the user message alone was wrong: 20 short strings is ~2,000
		// chars, which floored the timeout at 30s — while the system prompt had
		// grown past 10,000 chars as language packs accumulated guidance rules,
		// and agglutinative targets like Turkish expand output well beyond the
		// input. Turkish timed out on three separate runs at that floor. The
		// request is already billed server-side when we hang up, so a short
		// timeout wastes money AND loses the result.
		$request_chars = strlen( $user_message ) + strlen( $system_prompt );
		$timeout       = (int) max( 90, min( 240, ceil( $request_chars / 90 ) ) );

		$data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 8192, $timeout );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		self::record_usage( $data, $model, 'strings' );

		$text       = $data['content'][0]['text'];
		$translated = ACWPT_Glossary::extract_first_json_object( $text );
		if ( ! is_array( $translated ) ) {
			return new WP_Error( 'parse_error', 'Could not parse string translation response.' );
		}

		// BATCH INTEGRITY. Reject a response whose key set does not match what we
		// sent, instead of trusting it positionally.
		//
		// Three blind reviewers independently found the same corruption on the
		// live Polish homepage: the sales phone number rendered as "dostepnosc
		// strony internetowej", a logo's alt text became "Poprzednia", and a
		// seven-item resources list lost "Applications" while every remaining
		// label slid up one slot.
		//
		// One cause. The model dropped an item and RENUMBERED the rest, so key
		// "3" now held the translation of input 4. Each value was individually
		// plausible, so nothing downstream could tell; the damage only shows at
		// document level. A missing key already fell back to the original, but
		// a SHIFTED object has every key present and is silently wrong.
		//
		// Count mismatch is the detectable signature. On mismatch the whole
		// batch is failed so the queue retries it, rather than storing garbage
		// that looks like a successful translation.
		if ( count( $translated ) !== count( $originals ) ) {
			return new WP_Error(
				'acwpt_batch_mismatch',
				sprintf(
					'Translation response returned %d items for %d inputs; rejecting the batch to avoid a shifted mapping.',
					count( $translated ),
					count( $originals )
				)
			);
		}

		$result = array();
		foreach ( $originals as $i => $original ) {
			$key = (string) $i;
			if ( ! array_key_exists( $key, $translated ) ) {
				return new WP_Error(
					'acwpt_batch_mismatch',
					'Translation response was missing key ' . $key . '; rejecting the batch.'
				);
			}
			$val = (string) $translated[ $key ];
			$val = ACWPT_Glossary::strip_glossary_sentinels( $val );
			$val = ACWPT_Glossary::strip_keep_sentinels( $val );
			$val = self::typography_text_only( array( __CLASS__, 'localize_quotes' ), $val, $language );
			$val = self::typography_text_only( array( __CLASS__, 'localize_thousands' ), $val, $language );
			// PER-ITEM STRUCTURE CHECK. The count/key check above catches a
			// DROPPED item but not a SWAPPED pair: both keys present, values
			// exchanged. Measured on prod, pl and cs stored "Report an issue or
			// request services from our support team." as "<span
			// class=\"tmm-brand-word\">Lismar</span> ...", so the support form
			// showed an NDT descriptor. A translation must carry the same tag
			// names (ignoring <br>, which languages legitimately drop) and the
			// same brand-span presence as its source; otherwise it belongs to a
			// different input and is not stored. It stays missing and is
			// retried in a later, differently-composed batch.
			if ( self::structure_signature( $val ) !== self::structure_signature( $original ) ) {
				error_log( 'ACWPT: rejected mismatched pair for ' . $language . ': ' . mb_substr( $original, 0, 60 ) );
				continue;
			}
			$result[ $original ] = $val;
		}

		return $result;
	}

	/**
	 * Apply typography localisation to TEXT ONLY, never to markup.
	 *
	 * This exists because I broke the live Polish homepage with it. The new
	 * whole-block extraction sends a sentence WITH its inline HTML to the
	 * translator, and localize_quotes() then converted the ASCII quotes inside
	 * HTML ATTRIBUTES into Polish typographic quotes:
	 *
	 *   <span class="textorange">  ->  <span class=„textorange”>
	 *
	 * The browser then parsed the rest of the hero as one giant class value, so
	 * "Impossible? Done." vanished into an attribute and the heading rendered
	 * empty. Every language with paired quote marks was exposed to this.
	 *
	 * Fix: mask every tag before typography runs, restore afterwards. Quotes
	 * inside markup are syntax and must stay ASCII; quotes in prose are
	 * typography and should be localised.
	 *
	 * @param callable $fn   Typography function to apply to text nodes.
	 * @param string   $text Possibly HTML-bearing string.
	 * @param string   $lang Language code.
	 * @return string
	 */
	private static function typography_text_only( callable $fn, $text, $lang ) {
		if ( false === strpos( $text, '<' ) ) {
			return $fn( $text, $lang ); // plain text: nothing to protect
		}

		$tags = array();
		$masked = preg_replace_callback(
			'/<[^>]+>/',
			function ( $m ) use ( &$tags ) {
				$key = "\x01" . count( $tags ) . "\x02";
				$tags[ $key ] = $m[0];
				return $key;
			},
			$text
		);

		$masked = $fn( $masked, $lang );

		return strtr( $masked, $tags );
	}

	/**
	 * Convert straight ASCII quotes to the target language's paired marks.
	 *
	 * Belt-and-braces alongside the prompt rule. Quote convention is mechanical
	 * and language-determined — there is no judgement involved — so enforcing it
	 * in code is strictly more reliable than asking the model twice. Native
	 * reviewers flagged mismatched pairs (opening „ closing ") as a high-severity
	 * defect in five languages across two rounds even after the prompt said not
	 * to, because the English source carries straight quotes and they survive.
	 *
	 * Only balanced pairs are touched; an apostrophe or a lone quote is left
	 * alone rather than guessed at.
	 *
	 * @param string $text
	 * @param string $language
	 * @return string
	 */
	public static function localize_quotes( $text, $language ) {
		$code = strtolower( substr( (string) $language, 0, 2 ) );

		// open, close, and whether the language wants no-break spaces inside.
		$marks = array(
			'de' => array( '„', '“', false ),
			'cs' => array( '„', '“', false ),
			'pl' => array( '„', '”', false ),
			'ro' => array( '„', '”', false ),
			'hu' => array( '„', '”', false ),
			'fr' => array( '«', '»', true ),
			'es' => array( '«', '»', false ),
			'it' => array( '«', '»', false ),
			'pt' => array( '«', '»', false ),
			'ru' => array( '«', '»', false ),
			'zh' => array( '“', '”', false ),
			'ja' => array( '「', '」', false ),
			// Turkish uses straight quotes, but must still have FOREIGN marks
			// normalised away — a German „ leaked into Turkish output because
			// the old code returned early for any language without an entry.
			'tr' => array( '"', '"', false ),
			'en' => array( '"', '"', false ),
		);

		if ( ! isset( $marks[ $code ] ) ) {
			return self::typography_text_only( array( __CLASS__, 'localize_apostrophes' ), $text, $code );
		}

		list( $open, $close, $nbsp ) = $marks[ $code ];

		// Normalise any FOREIGN localised mark to a straight quote first.
		//
		// The model sometimes reaches for a different language's convention —
		// German „ appearing in French, Chinese or Turkish output. Folding
		// every known mark back to " lets the pairing logic below make a single
		// correct decision instead of leaving a mark this language never uses.
		$all_marks = array( '„', '«', '»', '“', '”', '「', '」', '‟' );
		foreach ( array_diff( $all_marks, array( $open, $close ) ) as $f ) {
			$text = str_replace( $f, '"', $text );
		}

		if ( '"' === $open ) {
			// Straight-quote language: normalisation above is the whole job.
			return self::typography_text_only( array( __CLASS__, 'localize_apostrophes' ), $text, $code );
		}

		if ( false === strpos( $text, '"' ) && false === strpos( $text, $open ) ) {
			return self::typography_text_only( array( __CLASS__, 'localize_apostrophes' ), $text, $code );
		}

		// Pair them up in order: first quote opens, next closes.
		$text = preg_replace_callback(
			'/"([^"]*)"/u',
			function ( $m ) use ( $open, $close, $nbsp ) {
				$inner = $m[1];
				if ( '' === trim( $inner ) ) {
					return $m[0];
				}
				return $nbsp
					? $open . "\xC2\xA0" . $inner . "\xC2\xA0" . $close
					: $open . $inner . $close;
			},
			$text
		);

		// HALF-CONVERTED PAIRS.
		//
		// The model frequently localises the OPENING mark and leaves the closing
		// one straight — especially when a <x-keep> sentinel sits between them,
		// which visually separates the two quotes in its context. The paired
		// regex above cannot see that case because only one straight quote
		// remains, so it has no partner to match. Observed in all ten languages.
		//
		// Close any opening mark that is followed by a straight quote before the
		// next opening mark.
		$o = preg_quote( $open, '/' );
		$text = preg_replace(
			'/' . $o . '([^"' . $o . ']*)"/u',
			$nbsp ? $open . "\xC2\xA0" . '$1' . "\xC2\xA0" . $close : $open . '$1' . $close,
			$text
		);

		// And the mirror case: straight opening quote, localised closing mark.
		$c = preg_quote( $close, '/' );
		$text = preg_replace(
			'/"([^"' . $c . ']*)' . $c . '/u',
			$nbsp ? $open . "\xC2\xA0" . '$1' . "\xC2\xA0" . $close : $open . '$1' . $close,
			$text
		);

		return self::typography_text_only( array( __CLASS__, 'localize_french_spacing' ), self::typography_text_only( array( __CLASS__, 'localize_apostrophes' ), $text, $code ), $code );
	}

	/**
	 * Tag-name set (excluding br/wbr) plus brand-span presence. Two strings
	 * with different signatures cannot be translations of each other.
	 */
	private static function structure_signature( $text ) {
		preg_match_all( '/<\s*([a-z][a-z0-9]*)\b/i', (string) $text, $m );
		$names = array_unique( array_diff( array_map( 'strtolower', $m[1] ), array( 'br', 'wbr' ) ) );
		sort( $names );
		return implode( ',', $names ) . '|' . ( false !== stripos( (string) $text, 'tmm-brand' ) ? 'B' : '' );
	}

	/**
	 * Localise English thousands separators ("13,000") in translated text.
	 *
	 * Deterministic, so it lives in code rather than the prompt (same rule as
	 * the quote and apostrophe classes). A Spanish reader parses "13,000" as
	 * thirteen-point-zero; reviewers flagged it on the live page.
	 * Only digit groups of exactly three after a comma are touched, so phone
	 * numbers, dates and decimals are left alone.
	 */
	public static function localize_thousands( $text, $code ) {
		$sep = array(
			'de' => '.', 'es' => '.', 'it' => '.', 'pt' => '.', 'ro' => '.', 'tr' => '.',
			'fr' => "\u{202F}", 'pl' => "\u{00A0}", 'cs' => "\u{00A0}",
		);
		if ( ! isset( $sep[ $code ] ) ) {
			return $text; // zh and en keep the comma
		}
		return preg_replace_callback(
			'/(?<![\d.,])\d{1,3}(?:,\d{3})+(?![\d,]|\.\d)/u',
			function ( $m ) use ( $sep, $code ) {
				return str_replace( ',', $sep[ $code ], $m[0] );
			},
			$text
		);
	}

	/**
	 * Apply French no-break space typography.
	 *
	 * French requires a no-break space inside guillemets and before the
	 * two-part punctuation marks ? ! ; :. The pairing logic above only inserts
	 * them for quotes it converts itself, so a guillemet the model produced —
	 * or any question mark anywhere in the string — was left with an ordinary
	 * space. A native reviewer measured it: 1 of 3 question marks had the
	 * correct space, and « Impossible used a plain one.
	 *
	 * U+202F (narrow no-break space) is the correct character before ? ! ; :
	 * and inside guillemets per Imprimerie nationale practice; U+00A0 is the
	 * widely-accepted fallback and is what most browsers render identically.
	 *
	 * @param string $text
	 * @param string $code Two-letter language code.
	 * @return string
	 */
	public static function localize_french_spacing( $text, $code ) {
		if ( 'fr' !== $code ) {
			return $text;
		}

		$nnbsp = "\xE2\x80\xAF"; // U+202F

		// Inside guillemets: « text » — collapse whatever is there to one NNBSP.
		$text = preg_replace( '/«[\s\x{00A0}\x{202F}]*/u', '«' . $nnbsp, $text );
		$text = preg_replace( '/[\s\x{00A0}\x{202F}]*»/u', $nnbsp . '»', $text );

		// Before ? ! ; : — but never inside a URL, where ? and : are syntax.
		$text = preg_replace_callback(
			'/(\S)[\s\x{00A0}\x{202F}]*([?!;:])/u',
			function ( $m ) use ( $nnbsp ) {
				// Leave URLs and times alone: https://  12:30
				if ( preg_match( '#https?$|/$|\d$#u', $m[1] ) && ':' === $m[2] ) {
					return $m[0];
				}
				return $m[1] . $nnbsp . $m[2];
			},
			$text
		);

		return $text;
	}

	/**
	 * Convert straight apostrophes to the typographic form where required.
	 *
	 * Same class of defect as quotation marks, and same reasoning: mechanical,
	 * language-determined, no judgement involved. A native French reviewer
	 * flagged "all 37 apostrophes are ASCII straight ' instead of ’" as HIGH
	 * severity after three rounds of prompt guidance — exactly the pattern that
	 * proved unfixable by prompting for quotes.
	 *
	 * Only languages whose typography REQUIRES the curly form are touched.
	 * Turkish uses the straight apostrophe before suffixes on proper nouns
	 * (Ascential'in) and must be left alone.
	 *
	 * @param string $text
	 * @param string $code Two-letter language code.
	 * @return string
	 */
	public static function localize_apostrophes( $text, $code ) {
		// fr/it elide constantly (l'équilibrage, dell'azienda); ca/pt use it too.
		$needs_curly = array( 'fr', 'it', 'ca' );
		if ( ! in_array( $code, $needs_curly, true ) || false === strpos( $text, "'" ) ) {
			return $text;
		}

		// Only a WORD-INTERNAL apostrophe is an elision. A leading or trailing
		// one is likely a quote the caller has already handled, or punctuation
		// we should not guess at.
		return preg_replace( '/(?<=\p{L})\'(?=\p{L})/u', "\xE2\x80\x99", $text );
	}

	// =========================================================================
	// Anthropic API
	// =========================================================================

	/**
	 * Make a Messages API call to Anthropic. Returns array on success,
	 * WP_Error on failure. Caller is responsible for prompt assembly and
	 * response parsing.
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $system      Top-level system prompt.
	 * @param string $user        User message body.
	 * @param int    $max_tokens
	 * @param int    $timeout
	 * @return array|WP_Error     Decoded response body on success.
	 */
	private static function call_anthropic( $api_key, $model, $system, $user, $max_tokens = 8192, $timeout = 30 ) {
		// SPEND CEILING. Checked before every request, not after.
		//
		// Queue depth was bounded but dollars were not, so a crawler walking
		// every /{lang}/ URL or a sitewide markup change converted straight
		// into unbounded spend. On breach we stop translating and keep serving
		// cache, which degrades gracefully instead of billing indefinitely.
		$budget = ACWPT_Budget::check();
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'max_tokens'  => $max_tokens,
						'temperature' => 0.3,
						'system'      => $system,
						'messages'    => array(
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
			// Remember billing failures so destructive actions can refuse.
			// An exhausted balance means a flushed cache cannot be rebuilt.
			ACWPT_Budget::note_api_error( $msg );
			return new WP_Error( 'anthropic_error', 'Anthropic API error: ' . $msg );
		}

		if ( empty( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'empty_response', 'Anthropic returned an empty response.' );
		}

		// A good response clears any recorded billing failure, so destructive
		// actions are unblocked once the account is funded again.
		ACWPT_Budget::note_api_ok();

		return $data;
	}

	// =========================================================================
	// Usage Tracking
	// =========================================================================

	/**
	 * Record API usage from a response.
	 *
	 * @param array  $data     Decoded API response body.
	 * @param string $model    Model used.
	 * @param string $type     'content' or 'strings'.
	 */
	private static function record_usage( $data, $model, $type ) {
		if ( empty( $data['usage'] ) ) {
			return;
		}

		$input_tokens  = (int) ( $data['usage']['input_tokens']  ?? 0 );
		$output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );

		$pricing = isset( self::$pricing[ $model ] ) ? self::$pricing[ $model ] : self::$pricing['claude-sonnet-4-5'];
		$cost    = ( $input_tokens * $pricing['input'] ) + ( $output_tokens * $pricing['output'] );

		// Feed the monthly ceiling. Separate from acwpt_usage, which is
		// lifetime reporting: the budget needs a per-month figure it can
		// compare against a limit.
		ACWPT_Budget::record( $cost );

		$month = gmdate( 'Y-m' );
		$usage = get_option( 'acwpt_usage', array() );

		if ( ! isset( $usage[ $month ] ) ) {
			$usage[ $month ] = array(
				'requests'             => 0,
				'prompt_tokens'        => 0,
				'completion_tokens'    => 0,
				'total_tokens'         => 0,
				'estimated_cost'       => 0.0,
				'content_translations' => 0,
				'string_translations'  => 0,
			);
		}

		$usage[ $month ]['requests']          += 1;
		$usage[ $month ]['prompt_tokens']     += $input_tokens;   // schema kept for back-compat
		$usage[ $month ]['completion_tokens'] += $output_tokens;
		$usage[ $month ]['total_tokens']      += $input_tokens + $output_tokens;
		$usage[ $month ]['estimated_cost']    += $cost;

		if ( $type === 'content' ) {
			$usage[ $month ]['content_translations'] += 1;
		} else {
			$usage[ $month ]['string_translations'] += 1;
		}

		update_option( 'acwpt_usage', $usage, false );
	}

	/**
	 * Get usage stats. Returns array keyed by 'YYYY-MM' with most recent first.
	 */
	public static function get_usage() {
		$usage = get_option( 'acwpt_usage', array() );
		krsort( $usage );
		return $usage;
	}

	/**
	 * Get usage for the current month.
	 */
	public static function get_current_month_usage() {
		$usage = get_option( 'acwpt_usage', array() );
		$month = gmdate( 'Y-m' );
		return isset( $usage[ $month ] ) ? $usage[ $month ] : null;
	}

	/**
	 * Get all-time totals.
	 */
	public static function get_total_usage() {
		$usage  = get_option( 'acwpt_usage', array() );
		$totals = array(
			'requests'             => 0,
			'prompt_tokens'        => 0,
			'completion_tokens'    => 0,
			'total_tokens'         => 0,
			'estimated_cost'       => 0.0,
			'content_translations' => 0,
			'string_translations'  => 0,
			'months'               => count( $usage ),
		);

		foreach ( $usage as $month_data ) {
			$totals['requests']             += $month_data['requests'] ?? 0;
			$totals['prompt_tokens']        += $month_data['prompt_tokens'] ?? 0;
			$totals['completion_tokens']    += $month_data['completion_tokens'] ?? 0;
			$totals['total_tokens']         += $month_data['total_tokens'] ?? 0;
			$totals['estimated_cost']       += $month_data['estimated_cost'] ?? 0;
			$totals['content_translations'] += $month_data['content_translations'] ?? 0;
			$totals['string_translations']  += $month_data['string_translations'] ?? 0;
		}

		return $totals;
	}

	// =========================================================================
	// Response Parsing
	// =========================================================================

	private static function parse_response( $text, $has_excerpt ) {
		$result = array(
			'title'   => '',
			'content' => '',
			'excerpt' => '',
		);

		if ( preg_match( '/===TITLE===\s*(.*?)(?=\s*===CONTENT===)/s', $text, $m ) ) {
			$result['title'] = trim( $m[1] );
		}

		// Always look for EXCERPT delimiter in the response — Claude sometimes
		// emits it even when the source had no excerpt. Split there if found,
		// then discard the excerpt if the caller didn't expect one.
		if ( preg_match( '/===CONTENT===\s*(.*?)(?=\s*===EXCERPT===)/s', $text, $m ) ) {
			$result['content'] = trim( $m[1] );
			if ( $has_excerpt && preg_match( '/===EXCERPT===\s*(.*)/s', $text, $em ) ) {
				$result['excerpt'] = trim( $em[1] );
			}
		} elseif ( preg_match( '/===CONTENT===\s*(.*)/s', $text, $m ) ) {
			$result['content'] = trim( $m[1] );
		}

		// Defensive: scrub any stray delimiter tokens that slipped through
		// (e.g., Claude echoing the section headers verbatim in content).
		// Also normalise any &nbsp; / &NBSP; HTML entities to actual U+00A0
		// characters — some themes escape titles via esc_html() which would
		// turn &nbsp; into &amp;nbsp; and render it as literal text.
		$nbsp = "\xC2\xA0"; // UTF-8 encoding of U+00A0
		foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
			$result[ $k ] = preg_replace( '/={3,}\s*(?:TITLE|CONTENT|EXCERPT)\s*={3,}/', '', $result[ $k ] );
			$result[ $k ] = preg_replace( '/&nbsp;/i', $nbsp, $result[ $k ] );
			$result[ $k ] = trim( $result[ $k ] );
		}

		if ( empty( $result['title'] ) && empty( $result['content'] ) ) {
			$result['content'] = trim( $text );
		}

		return $result;
	}

	/**
	 * Test the API key by making a minimal Messages call.
	 */
	public static function test_api_key( $api_key ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::$default_model,
						'max_tokens' => 16,
						'messages'   => array(
							array( 'role' => 'user', 'content' => 'Reply with the single word: ok' ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'api_test_failed', $msg );
		}

		return true;
	}
}
