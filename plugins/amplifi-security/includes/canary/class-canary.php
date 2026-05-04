<?php
/**
 * External heartbeat / canary endpoint.
 *
 * The canary is registered very early (`muplugins_loaded` priority 1) so it
 * intercepts the request before any potentially-malicious plugin can short-
 * circuit `parse_request`. It serves a tiny plaintext body containing the
 * current scan/triage health and a HMAC the user can verify externally.
 *
 * URL form:  https://example.com/?amplifi_canary={slug}
 *
 * The slug is per-site randomised so an attacker can't enumerate "is
 * amplifi.security installed here" by hitting a known path. Once an attacker
 * already has admin access to the site they can read the slug — at that point
 * the audit log and downtime alert have already fired.
 *
 * @package Amplifi\Security\Canary
 */

declare(strict_types=1);

namespace Amplifi\Security\Canary;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Canary {

	public static function register(): void {
		// Earliest reasonable hook for plugin-level code.
		add_action( 'muplugins_loaded', [ self::class, 'maybe_serve' ], 1 );
		add_action( 'parse_request',    [ self::class, 'maybe_serve' ], 1 );
	}

	public static function maybe_serve(): void {
		if ( empty( $_GET['amplifi_canary'] ) ) {
			return;
		}
		$presented = sanitize_text_field( wp_unslash( (string) $_GET['amplifi_canary'] ) );
		$slug      = (string) get_option( 'amplifi_security_canary_slug', '' );

		if ( '' === $slug || ! hash_equals( $slug, $presented ) ) {
			// Wrong slug — return a generic 404 so we don't leak the canary's existence.
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "not found\n";
			exit;
		}

		self::serve();
	}

	public static function serve(): void {
		$state = self::current_state();

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Amplifi-Status: ' . ( $state['triage_ok'] ? 'alive' : 'degraded' ) );

		$body  = "amplifi-security-alive\n";
		$body .= 'ts: ' . $state['ts'] . "\n";
		$body .= 'last_scan: ' . $state['last_scan'] . "\n";
		$body .= 'last_triage_ok: ' . ( $state['triage_ok'] ? 'true' : 'false' ) . "\n";
		$body .= 'findings_open_confirmed: ' . $state['findings_open_confirmed'] . "\n";
		$body .= 'self_integrity_ok: ' . ( $state['self_integrity_ok'] ? 'true' : 'false' ) . "\n";
		$body .= 'sig: ' . self::sign( $state ) . "\n";

		echo $body;
		exit;
	}

	public static function current_state(): array {
		$now             = time();
		$last_scan_ts    = (int) get_option( 'amplifi_security_last_scan_ts', 0 );
		$last_triage_ok  = (bool) get_option( 'amplifi_security_last_triage_ok', true );
		$self_ok         = '1' === (string) get_option( 'amplifi_security_self_integrity_ok', '1' );
		$confirmed_open  = self::count_open_confirmed();

		// Did Sentinel run recently? "Recently" = 2× scan interval.
		$interval = self::scan_interval_seconds();
		if ( $last_scan_ts > 0 && ( $now - $last_scan_ts ) > ( 2 * $interval ) ) {
			$last_triage_ok = false;
		}

		return [
			'ts'                       => $now,
			'last_scan'                => $last_scan_ts ? gmdate( 'Y-m-d\TH:i:s\Z', $last_scan_ts ) : 'never',
			'triage_ok'                => $last_triage_ok && $self_ok,
			'self_integrity_ok'        => $self_ok,
			'findings_open_confirmed'  => $confirmed_open,
		];
	}

	private static function scan_interval_seconds(): int {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$key      = $settings['scan_interval'] ?? 'four_hours';
		return match ( $key ) {
			'two_hours'   => 2 * HOUR_IN_SECONDS,
			'eight_hours' => 8 * HOUR_IN_SECONDS,
			'twelve_hours'=> 12 * HOUR_IN_SECONDS,
			'daily'       => DAY_IN_SECONDS,
			default       => 4 * HOUR_IN_SECONDS,
		};
	}

	private static function count_open_confirmed(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE verdict = %s AND status NOT IN ('dismissed','quarantined')",
				'confirmed'
			)
		);
	}

	public static function sign( array $state ): string {
		$secret = (string) get_option( 'amplifi_security_canary_secret', '' );
		if ( '' === $secret ) {
			return 'unsigned';
		}
		$payload = $state['ts'] . '|' . $state['last_scan'] . '|' . ( $state['triage_ok'] ? '1' : '0' );
		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Build the public canary URL.
	 */
	public static function url(): string {
		$slug = (string) get_option( 'amplifi_security_canary_slug', '' );
		if ( '' === $slug ) {
			return '';
		}
		return add_query_arg( 'amplifi_canary', $slug, home_url( '/' ) );
	}

	public static function rotate_slug(): string {
		$new = wp_generate_password( 32, false );
		update_option( 'amplifi_security_canary_slug', $new, false );
		Audit_Logger::log( 'canary_slug_rotated', [] );
		return $new;
	}
}
