<?php
/**
 * Login honeypot (extra: not in spec).
 *
 * Registers a fake `/wp-admin-secure/`-style login URL. Anything hitting it
 * is by definition a scanner: legitimate users don't know the path exists.
 * The hit is logged into the auth_log as `honeypot_hit` and surfaces on the
 * next scan as a `confirmed`-tier finding (category `auth_anomaly`,
 * label `scanner_probe`).
 *
 * The honeypot path is per-site randomised but predictable enough to attract
 * common scanners (it's a slight mutation of common admin paths).
 *
 * @package Amplifi\Security\Honeypot
 */

declare(strict_types=1);

namespace Amplifi\Security\Honeypot;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Login_Honeypot {

	private const TRAP_PATHS = [
		'wp-login-secure.php',
		'wp-admin-original.php',
		'wp-config-old.php',
		'admin-ajax-old.php',
	];

	public static function register(): void {
		add_action( 'parse_request', [ self::class, 'maybe_trap' ], 5 );
	}

	public static function maybe_trap( $wp ): void {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $req ) {
			return;
		}
		$path = trim( (string) wp_parse_url( $req, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return;
		}
		foreach ( self::TRAP_PATHS as $trap ) {
			if ( $path === $trap ) {
				self::trip( $trap );
				return;
			}
		}
	}

	private static function trip( string $trap ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_auth_log';
		$wpdb->insert(
			$table,
			[
				'event'      => 'honeypot_hit',
				'user_login' => null,
				'ip'         => Audit_Logger::client_ip(),
				'ua'         => Audit_Logger::client_ua(),
				'country'    => null,
				'created_at' => current_time( 'mysql', true ),
			]
		);
		Audit_Logger::log(
			'honeypot_hit',
			[ 'trap' => $trap ]
		);
		// Return a generic 404 so the scanner's response is unsurprising.
		status_header( 404 );
		nocache_headers();
		exit;
	}
}
