<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACWPT_Glossary {

    /**
     * Wrap each term in $never_list with <x-keep>...</x-keep> in $text.
     * Case-sensitive, whole-word match. Longest terms wrapped first to avoid
     * double-wrapping shorter terms that are substrings of longer ones.
     *
     * Note: word-boundary detection uses Unicode letter/number lookarounds, so
     * for logographic scripts (CJK) terms only match when surrounded by spaces,
     * punctuation, or string boundaries — terms embedded in a run of CJK
     * characters without separators will pass through untouched (fail-safe).
     */
    public static function apply_keep_sentinels( $text, array $never_list ) {
        if ( ! is_string( $text ) || $text === '' || empty( $never_list ) ) {
            return (string) $text;
        }

        // Defensive: NUL bytes should never appear in WP post content. Strip
        // them so our placeholder-token strategy below cannot collide.
        if ( strpos( $text, "\0" ) !== false ) {
            $text = str_replace( "\0", '', $text );
        }

        // Dedupe + sort longest-first so "Acme Cloud" wraps before "Acme".
        $terms = array_values( array_unique( array_filter( $never_list, 'strlen' ) ) );
        usort( $terms, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

        // Use a placeholder strategy: replace each match with a unique token,
        // then swap tokens for sentinels. Prevents wrapping content that's
        // already inside a sentinel from a longer earlier term.
        $tokens = array();
        foreach ( $terms as $i => $term ) {
            $token = "\0KEEP_{$i}\0";
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}_])/u';
            $text = preg_replace( $pattern, $token, $text );
            $tokens[ $token ] = '<x-keep>' . $term . '</x-keep>';
        }

        return strtr( $text, $tokens );
    }

    /**
     * Strip <x-keep>...</x-keep> wrappers, preserving inner content.
     */
    public static function strip_keep_sentinels( $text ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return (string) $text;
        }
        $out = preg_replace( '#<x-keep>(.*?)</x-keep>#s', '$1', $text );
        // PCRE can return null on backtrack/recursion limits with very large input.
        return $out !== null ? $out : $text;
    }

    /**
     * Extract the first complete JSON object from a string, tolerating any
     * preamble, code fences, or trailing text. Returns the decoded array or
     * null if no balanced object is found / decoding fails.
     */
    public static function extract_first_json_object( $text ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return null;
        }

        $len   = strlen( $text );
        $start = strpos( $text, '{' );
        if ( $start === false ) {
            return null;
        }

        $depth     = 0;
        $in_str    = false;
        $escaped   = false;
        $end       = -1;

        for ( $i = $start; $i < $len; $i++ ) {
            $ch = $text[ $i ];

            if ( $in_str ) {
                if ( $escaped ) {
                    $escaped = false;
                } elseif ( $ch === '\\' ) {
                    $escaped = true;
                } elseif ( $ch === '"' ) {
                    $in_str = false;
                }
                continue;
            }

            if ( $ch === '"' ) {
                $in_str = true;
            } elseif ( $ch === '{' ) {
                $depth++;
            } elseif ( $ch === '}' ) {
                $depth--;
                if ( $depth === 0 ) {
                    $end = $i;
                    break;
                }
            }
        }

        if ( $end === -1 ) {
            return self::salvage_indexed_pairs( $text );
        }

        $json    = substr( $text, $start, $end - $start + 1 );
        $decoded = json_decode( $json, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        return self::salvage_indexed_pairs( $text );
    }

    /**
     * Recover "index": "value" pairs from a malformed JSON response.
     *
     * Models occasionally emit a value containing an unescaped ASCII double
     * quote — most often when they reach for typographic quotes and close with
     * a plain one, e.g.
     *
     *     "10": "Złożona integracja automatyki „end to end""
     *
     * That terminates the JSON string early, so the brace-walk never balances
     * and a strict decode of the whole object fails. Previously the entire
     * batch was discarded and returned as a parse_error, throwing away up to 40
     * translations that had already been paid for (observed on staging: one bad
     * value at index 10 lost all 40).
     *
     * This recovers each pair independently, so a single malformed value costs
     * only that value. Anything unrecoverable is simply absent from the result;
     * the caller already falls back to the source string for missing indices.
     *
     * @param string $text Raw model output.
     * @return array<string,string>|null
     */
    private static function salvage_indexed_pairs( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return null;
        }

        // Match "<digits>": "<value>" where the value runs to the last quote
        // before the delimiter that ends the pair (a comma + next numeric key,
        // or the closing brace). Non-greedy up to that boundary tolerates
        // unescaped quotes inside the value.
        $pattern = '/"(\d+)"\s*:\s*"(.*?)"\s*(?=,\s*"\d+"\s*:|\s*\}|\s*$)/s';

        if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
            return null;
        }

        $out = array();
        foreach ( $matches as $m ) {
            $value = $m[2];

            // Undo standard JSON escapes that json_decode would have handled.
            $value = str_replace(
                array( '\\"', '\\\\', '\\n', '\\r', '\\t', '\\/' ),
                array( '"', '\\', "\n", "\r", "\t", '/' ),
                $value
            );

            $out[ $m[1] ] = $value;
        }

        return $out ? $out : null;
    }

    /**
     * Return glossary rows that have a non-empty source ('en') and a
     * non-empty translation for the given language code.
     */
    public static function entries_for_language( array $glossary, $lang_code ) {
        $out = array();
        foreach ( $glossary as $row ) {
            $src = isset( $row['en'] ) ? trim( (string) $row['en'] ) : '';
            $tr  = isset( $row[ $lang_code ] ) ? trim( (string) $row[ $lang_code ] ) : '';
            if ( $src === '' || $tr === '' ) {
                continue;
            }
            $out[] = array( 'en' => $src, 'translation' => $tr );
        }
        return $out;
    }

    /**
     * Format a prompt block listing mandatory translations for the model.
     * Returns empty string if no entries.
     */
    public static function format_prompt_block( array $entries ) {
        if ( empty( $entries ) ) {
            return '';
        }
        $lines = array( 'MANDATORY GLOSSARY — translate these source terms EXACTLY as shown:' );
        foreach ( $entries as $e ) {
            $lines[] = sprintf( '"%s" → "%s"', $e['en'], $e['translation'] );
        }
        return implode( "\n", $lines );
    }

    /**
     * Wrap glossary source terms in the input with <x-glossary term="...">
     * sentinels carrying the mandated translation. Case-sensitive whole-word.
     *
     * A pinned term is substituted VERBATIM, so it is only safe where the term
     * can stand uninflected in a real sentence. Two production defects came
     * from ignoring that:
     *
     *   "Aerospace" pinned as a singular adjective forced singular agreement
     *   onto a plural noun and broke a CTA headline in six languages.
     *
     *   "journals" pinned as Turkish "muylular" (bare plural) produced
     *   "kendi muylular sahip rotorlar" — the sentence needs "muylularına"
     *   (possessive + dative) after the postposition "sahip".
     *
     * So: pin a term for a given language ONLY when that language leaves it
     * uninflected in context. For inflecting languages, state the required
     * term in the language pack instead, where the model can decline it.
     *
     * Note: same Unicode word-boundary caveat as apply_keep_sentinels — for
     * logographic scripts the term must be flanked by non-letter chars.
     */
    public static function apply_glossary_sentinels( $text, array $entries ) {
        if ( ! is_string( $text ) || $text === '' || empty( $entries ) ) {
            return (string) $text;
        }

        // Strip NUL bytes defensively so the placeholder strategy below cannot collide.
        if ( strpos( $text, "\0" ) !== false ) {
            $text = str_replace( "\0", '', $text );
        }

        // Longest-first to avoid partial overlap.
        usort( $entries, function ( $a, $b ) { return strlen( $b['en'] ) - strlen( $a['en'] ); } );

        $tokens = array();
        foreach ( $entries as $i => $e ) {
            $token   = "\0GLOSS_{$i}\0";
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( $e['en'], '/' ) . '(?![\p{L}\p{N}_])/u';
            $text    = preg_replace( $pattern, $token, $text );
            $tokens[ $token ] = sprintf(
                '<x-glossary term="%s">%s</x-glossary>',
                str_replace( '"', '&quot;', $e['translation'] ),
                $e['en']
            );
        }
        return strtr( $text, $tokens );
    }

    /**
     * Replace each <x-glossary term="X">...</x-glossary> in $text with X
     * (the mandated translation), discarding whatever the model put inside.
     */
    public static function strip_glossary_sentinels( $text ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return (string) $text;
        }
        $out = preg_replace_callback(
            '#<x-glossary term="([^"]*)">.*?</x-glossary>#s',
            function ( $m ) { return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ); },
            $text
        );
        // PCRE can return null on backtrack/recursion limits with very large input.
        return $out !== null ? $out : $text;
    }
}
