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
        return preg_replace( '#<x-keep>(.*?)</x-keep>#s', '$1', $text );
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
            return null;
        }

        $json    = substr( $text, $start, $end - $start + 1 );
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : null;
    }
}
