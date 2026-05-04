<?php
/**
 * Verdict cache.
 *
 * Caches `benign` verdicts by a hash of (type, path, file_hash, signals) for
 * a configurable TTL (default 7 days). Skips re-triage on identical findings
 * to keep token spend down.
 *
 * Integrity findings always re-triage on file_hash change (the cache key
 * includes the hash, so a modified file naturally misses the cache).
 *
 * @package Amplifi\Security\Triage
 */

declare(strict_types=1);

namespace Amplifi\Security\Triage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verdict_Cache {

	public const TTL_DAYS = 7;

	public static function key_for( string $type, array $evidence ): string {
		$signals = (array) ( $evidence['matches'] ?? $evidence['signals'] ?? [] );
		$signal_ids = [];
		foreach ( $signals as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) ) {
				$signal_ids[] = $s['id'];
			} elseif ( is_string( $s ) ) {
				$signal_ids[] = $s;
			}
		}
		sort( $signal_ids );

		$canonical = wp_json_encode( [
			'type'    => $type,
			'path'    => $evidence['path']   ?? null,
			'sha256'  => $evidence['sha256'] ?? $evidence['file_hash'] ?? null,
			'signals' => $signal_ids,
			'subtype' => $evidence['subtype'] ?? null,
		] );

		return hash( 'sha256', (string) $canonical );
	}

	public static function lookup( string $key ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_verdict_cache';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT verdict, rationale, expires_at FROM {$table} WHERE cache_key = %s", $key ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE cache_key = %s", $key ) );
			return null;
		}
		return $row;
	}

	public static function store( string $key, string $verdict, string $rationale ): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'amplifi_security_verdict_cache';
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TTL_DAYS * DAY_IN_SECONDS );
		$wpdb->replace(
			$table,
			[
				'cache_key'  => $key,
				'verdict'    => $verdict,
				'rationale'  => $rationale,
				'expires_at' => $expires,
			]
		);
	}

	public static function purge_expired(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_verdict_cache';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) )
		);
	}
}
