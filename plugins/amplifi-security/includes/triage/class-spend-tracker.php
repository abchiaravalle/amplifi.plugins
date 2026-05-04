<?php
/**
 * Anthropic spend tracker.
 *
 * Per-day buckets in `wp_amplifi_security_spend`. Enforces the user's daily
 * and monthly USD ceilings. When at cap, triage pauses (scanners keep running,
 * findings queue with `pending_triage`) — except a single `confirmed`-tier
 * finding is always allowed through (~10¢ on Haiku, won't materially blow the
 * budget).
 *
 * @package Amplifi\Security\Triage
 */

declare(strict_types=1);

namespace Amplifi\Security\Triage;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Spend_Tracker {

	public static function record( string $model, int $tokens_in, int $tokens_out ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_spend';

		$cost = Anthropic_Client::estimate_cost( $model, $tokens_in, $tokens_out );
		$date = gmdate( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (date, tokens_in, tokens_out, cost_usd, triage_calls)
				 VALUES (%s, %d, %d, %f, 1)
				 ON DUPLICATE KEY UPDATE
				    tokens_in = tokens_in + VALUES(tokens_in),
				    tokens_out = tokens_out + VALUES(tokens_out),
				    cost_usd = cost_usd + VALUES(cost_usd),
				    triage_calls = triage_calls + 1",
				$date,
				$tokens_in,
				$tokens_out,
				$cost
			)
		);
	}

	public static function spend_today(): float {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_spend';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT cost_usd FROM {$table} WHERE date = %s", gmdate( 'Y-m-d' ) )
		);
	}

	public static function spend_this_month(): float {
		global $wpdb;
		$table  = $wpdb->prefix . 'amplifi_security_spend';
		$start  = gmdate( 'Y-m-01' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(cost_usd), 0) FROM {$table} WHERE date >= %s", $start )
		);
	}

	/**
	 * Project end-of-month spend by linear extrapolation.
	 */
	public static function projected_month_end(): float {
		$so_far    = self::spend_this_month();
		$day       = (int) gmdate( 'j' );
		$days_in_m = (int) gmdate( 't' );
		if ( $day < 1 ) {
			return $so_far;
		}
		return round( $so_far / $day * $days_in_m, 4 );
	}

	/**
	 * Should we proceed with a triage call?
	 *
	 * @param bool $emergency Allow override for confirmed-tier emergency.
	 * @return array{allowed:bool,reason:?string}
	 */
	public static function check_caps( bool $emergency = false ): array {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$daily    = (float) ( $settings['daily_spend_cap_usd']   ?? 2.0 );
		$monthly  = (float) ( $settings['monthly_spend_cap_usd'] ?? 30.0 );

		$today = self::spend_today();
		$month = self::spend_this_month();

		if ( $emergency ) {
			return [ 'allowed' => true, 'reason' => null ];
		}

		if ( $daily > 0 && $today >= $daily ) {
			Audit_Logger::log( 'triage_paused_daily_cap', [ 'today' => $today, 'cap' => $daily ] );
			return [ 'allowed' => false, 'reason' => 'daily_cap_reached' ];
		}
		if ( $monthly > 0 && $month >= $monthly ) {
			Audit_Logger::log( 'triage_paused_monthly_cap', [ 'month' => $month, 'cap' => $monthly ] );
			return [ 'allowed' => false, 'reason' => 'monthly_cap_reached' ];
		}
		return [ 'allowed' => true, 'reason' => null ];
	}

	public static function summary(): array {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return [
			'today'              => self::spend_today(),
			'month'              => self::spend_this_month(),
			'projected_month_end'=> self::projected_month_end(),
			'daily_cap'          => (float) ( $settings['daily_spend_cap_usd']   ?? 2.0 ),
			'monthly_cap'        => (float) ( $settings['monthly_spend_cap_usd'] ?? 30.0 ),
		];
	}
}
