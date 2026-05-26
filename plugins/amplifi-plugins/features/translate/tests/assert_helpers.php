<?php
/**
 * Minimal assertion helpers. No external dependencies.
 * Tests print PASS/FAIL lines and exit non-zero on first failure.
 */

function t_pass( $name ) {
    fwrite( STDOUT, "  PASS: {$name}\n" );
}

function t_fail( $name, $msg = '' ) {
    fwrite( STDERR, "  FAIL: {$name}" . ( $msg ? " — {$msg}" : '' ) . "\n" );
    exit( 1 );
}

function t_assert( $cond, $name, $msg = '' ) {
    if ( $cond ) { t_pass( $name ); } else { t_fail( $name, $msg ); }
}

function t_equals( $expected, $actual, $name ) {
    if ( $expected === $actual ) {
        t_pass( $name );
    } else {
        $e = var_export( $expected, true );
        $a = var_export( $actual, true );
        t_fail( $name, "expected={$e} actual={$a}" );
    }
}

function t_section( $title ) {
    fwrite( STDOUT, "\n== {$title} ==\n" );
}
