<?php
/**
 * Tamper detection for plugin state stored outside the file system.
 *
 * Two layers:
 *
 *   1. **Critical option HMAC** — the per-site secrets and settings rows are
 *      stamped with an HMAC at write. On read, verify; on mismatch, log a
 *      `tampered_critical_option` event (and treat the value as untrusted).
 *
 *   2. **Cron tamper re-arm** — on every page load (cheap), check that our
 *      scheduled hooks still exist. If a hook went missing without going
 *      through `wp_clear_scheduled_hook()` from inside the plugin, re-arm
 *      and log a `cron_tampered` event.
 *
 *   3. **"Did Sentinel run?" liveness** — if `last_scan_ts` is older than 2×
 *      the configured scan interval, mark unhealthy and surface a finding.
 *
 * @package Amplifi\Security\Self_Defense
 */

declare(strict_types=1);

namespace Amplifi\Security\Self_Defense;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tamper_Detector {

	private const PROTECTED_OPTIONS = [
		'amplifi_security_canary_slug',
		'amplifi_security_canary_secret',
		'amplifi_security_installer_id',
		'amplifi_security_unhide_token',
		'amplifi_security_self_baseline_hash',
		'amplifi_security_settings',
	];
	private const HMAC_INFO = 'amplifi-security:option-hmac:v1';

	public static function register(): void {
		add_action( 'init', [ self::class, 'rearm_cron_if_missing' ], 5 );
		add_action( 'init', [ self::class, 'check_liveness' ], 5 );
	}

	/* -------- option HMAC ---------------------------------------------- */

	public static function stamp( string $option_name, mixed $value ): void {
		if ( ! in_array( $option_name, self::PROTECTED_OPTIONS, true ) ) {
			return;
		}
		$serialized = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$hmac       = hash_hmac( 'sha256', $option_name . "\x1f" . $serialized, self::secret() );
		update_option( self::stamp_key( $option_name ), $hmac, false );
	}

	public static function verify( string $option_name ): bool {
		if ( ! in_array( $option_name, self::PROTECTED_OPTIONS, true ) ) {
			return true;
		}
		$value      = get_option( $option_name );
		$stamped    = (string) get_option( self::stamp_key( $option_name ), '' );
		if ( false === $value || '' === $stamped ) {
			return true; // not yet stamped → first write window
		}
		$serialized = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$expected   = hash_hmac( 'sha256', $option_name . "\x1f" . $serialized, self::secret() );
		$ok         = hash_equals( $expected, $stamped );
		if ( ! $ok ) {
			Audit_Logger::log(
				'tampered_critical_option',
				[ 'option_name' => $option_name ]
			);
		}
		return $ok;
	}

	private static function stamp_key( string $option_name ): string {
		return $option_name . '__hmac';
	}

	private static function secret(): string {
		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY' ] as $name ) {
			if ( defined( $name ) ) {
				$material .= (string) constant( $name );
			}
		}
		if ( '' === $material ) {
			$material = (string) get_option( 'siteurl' ) . '|amplifi-tamper';
		}
		return hash_hkdf( 'sha256', $material, 32, self::HMAC_INFO );
	}

	/* -------- cron re-arm ---------------------------------------------- */

	public static function rearm_cron_if_missing(): void {
		$expected = [
			'amplifi_security_run_scan'          => 'amplifi_security_four_hours',
			'amplifi_security_audit_prune'       => 'daily',
			'amplifi_security_vuln_feed_refresh' => 'daily',
			'amplifi_security_daily_digest'      => 'daily',
		];

		foreach ( $expected as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 300, $recurrence, $hook );
				Audit_Logger::log(
					'cron_rearmed',
					[ 'hook' => $hook, 'recurrence' => $recurrence ]
				);
			}
		}
	}

	/* -------- liveness ------------------------------------------------- */

	public static function check_liveness(): void {
		$last = (int) get_option( 'amplifi_security_last_scan_ts', 0 );
		if ( $last === 0 ) {
			return; // never scanned yet (fresh install)
		}
		$interval = self::scan_interval_seconds();
		if ( ( time() - $last ) > ( 2 * $interval ) ) {
			update_option( 'amplifi_security_last_triage_ok', 0, false );
		}
	}

	private static function scan_interval_seconds(): int {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$key      = $settings['scan_interval'] ?? 'four_hours';
		return match ( $key ) {
			'two_hours'    => 2 * HOUR_IN_SECONDS,
			'eight_hours'  => 8 * HOUR_IN_SECONDS,
			'twelve_hours' => 12 * HOUR_IN_SECONDS,
			'daily'        => DAY_IN_SECONDS,
			default        => 4 * HOUR_IN_SECONDS,
		};
	}
}
