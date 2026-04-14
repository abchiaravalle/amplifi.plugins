<?php
/**
 * Unit tests for ACWPT_Translator::parse_response().
 *
 * Since parse_response is private, we use reflection to exercise it.
 */

require_once __DIR__ . '/bootstrap.php';

// Minimal WP stubs required just to load the class file.
if ( ! function_exists( 'wp_remote_post' ) )              { function wp_remote_post( $url, $args = array() ) { return array(); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) )     { function wp_remote_retrieve_body( $r ) { return ''; } }
if ( ! function_exists( 'is_wp_error' ) )                  { function is_wp_error( $x ) { return false; } }
if ( ! function_exists( 'get_option' ) )                   { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) )                { function update_option( $k, $v, $autoload = null ) { return true; } }
if ( ! function_exists( 'current_time' ) )                 { function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! class_exists( 'WP_Error' ) )                        { class WP_Error { public function __construct( ...$a ) {} } }

require_once __DIR__ . '/../includes/class-acwpt-glossary.php';
require_once __DIR__ . '/../includes/class-acwpt-prompts.php';
require_once __DIR__ . '/../includes/class-acwpt-translator.php';

function call_parse( $text, $has_excerpt ) {
    $r = new ReflectionMethod( 'ACWPT_Translator', 'parse_response' );
    return $r->invoke( null, $text, $has_excerpt );
}

t_section( 'parse_response: clean response with title + content + excerpt' );
$out = call_parse(
    "===TITLE===\nKontakt z nami\n\n===CONTENT===\n<p>Amplifi pomaga firmom.</p>\n\n===EXCERPT===\nZacznij dziś.",
    true
);
t_equals( 'Kontakt z nami',           $out['title'],   'title parsed' );
t_equals( '<p>Amplifi pomaga firmom.</p>', $out['content'], 'content parsed' );
t_equals( 'Zacznij dziś.',            $out['excerpt'], 'excerpt parsed' );

t_section( 'parse_response: Claude emits EXCERPT delimiter even when source had none' );
// This is the real-world bug: $has_excerpt=false but response contains ===EXCERPT===
$out = call_parse(
    "===TITLE===\nKontakt\n\n===CONTENT===\n<p>Firma.</p>\n\n===EXCERPT===",
    false
);
t_equals( 'Kontakt',          $out['title'],   'title parsed' );
t_equals( '<p>Firma.</p>',    $out['content'], 'content stops at EXCERPT delimiter' );
t_equals( '',                 $out['excerpt'], 'excerpt discarded (has_excerpt=false)' );
t_assert( strpos( $out['content'], '===EXCERPT===' ) === false, 'EXCERPT delimiter does not leak into content' );

t_section( 'parse_response: EXCERPT with body but source had none — still discarded' );
$out = call_parse(
    "===TITLE===\nT\n\n===CONTENT===\n<p>C</p>\n\n===EXCERPT===\nUnexpected excerpt body",
    false
);
t_equals( '<p>C</p>', $out['content'], 'content stops at EXCERPT' );
t_equals( '',         $out['excerpt'], 'excerpt body discarded' );
t_assert( strpos( $out['content'], 'Unexpected' )    === false, 'excerpt body does not leak into content' );
t_assert( strpos( $out['content'], '===EXCERPT===' ) === false, 'EXCERPT delimiter does not leak' );

t_section( 'parse_response: no excerpt delimiter, no excerpt expected' );
$out = call_parse(
    "===TITLE===\nTitle\n\n===CONTENT===\n<p>Body</p>",
    false
);
t_equals( 'Title',       $out['title'],   'title' );
t_equals( '<p>Body</p>', $out['content'], 'content' );
t_equals( '',            $out['excerpt'], 'excerpt' );

t_section( 'parse_response: defensive scrub of stray delimiter tokens inside content' );
$out = call_parse(
    "===TITLE===\nT\n\n===CONTENT===\n<p>A</p>\n\n===EXCERPT===\n<p>B</p>",
    true
);
t_equals( '<p>A</p>', $out['content'], 'content clean' );
t_equals( '<p>B</p>', $out['excerpt'], 'excerpt clean' );

t_section( 'parse_response: fallback when no delimiters at all' );
$out = call_parse( 'Just some plain text response', false );
t_equals( 'Just some plain text response', $out['content'], 'falls back to full text in content' );

echo "\nALL PASS\n";
