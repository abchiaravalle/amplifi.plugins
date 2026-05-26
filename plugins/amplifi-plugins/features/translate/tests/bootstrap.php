<?php
/**
 * Minimal test bootstrap for pure-PHP units.
 * Defines stubs for the WordPress functions we touch in unit tests.
 * For WP-integrated tests, use `wp eval-file` inside Docker instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = '' ) { return $text; }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}

require_once __DIR__ . '/assert_helpers.php';
