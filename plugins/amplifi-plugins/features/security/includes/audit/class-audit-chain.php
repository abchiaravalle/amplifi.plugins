<?php
/**
 * HMAC hash-chain over the audit log.
 *
 * Each row's `row_hash` = HMAC-SHA256( site_secret, prev_hash || canonical(row) ).
 * The most recent row's hash is mirrored into `wp_options` under
 * `amplifi_security_audit_chain_head` so a verifier can compare on-disk and
 * in-options heads — divergence means either the table was tampered or the
 * options row was tampered, both alarming.
 *
 * Verification produces a list of broken row IDs. The plugin surfaces those
 * as their own `confirmed` finding with category `core_tampering` /
 * label `audit_chain_broken`.
 *
 * @package Amplifi\Security\Audit
 */

declare(strict_types=1);

namespace Amplifi\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Chain {

	private const KEY_INFO = 'amplifi-security:audit-chain:v1';

	/**
	 * Compute the chained HMAC for a row.
	 *
	 * @param string $prev_hash Previous row's hash (empty string for first row).
	 * @param array  $row       Row fields. Required keys: event_type, event_data, created_at.
	 *                          Optional: actor_user_id, actor_ip, target_type, target_id.
	 */
	public static function compute_hash( string $prev_hash, array $row ): string {
		$canonical = implode(
			"\x1f",
			[
				$prev_hash,
				(string) ( $row['event_type'] ?? '' ),
				(string) ( $row['actor_user_id'] ?? '' ),
				(string) ( $row['actor_ip'] ?? '' ),
				(string) ( $row['target_type'] ?? '' ),
				(string) ( $row['target_id'] ?? '' ),
				(string) ( $row['event_data'] ?? '' ),
				(string) ( $row['created_at'] ?? '' ),
			]
		);
		return hash_hmac( 'sha256', $canonical, self::secret() );
	}

	/**
	 * Verify the chain in chronological order.
	 *
	 * @param int      $limit       Max rows to scan (0 = all).
	 * @param int|null $start_after Only verify rows with id > this value.
	 * @return array{verified:bool,broken_at:int[],scanned:int}
	 */
	public static function verify( int $limit = 0, ?int $start_after = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_audit';

		$start_after = $start_after ?? 0;

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $start_after, $limit );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC", $start_after );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return [
				'verified'  => true,
				'broken_at' => [],
				'scanned'   => 0,
			];
		}

		$expected_prev = '';
		// If we're starting partway through, look up the previous row's hash to anchor.
		if ( $start_after > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$expected_prev = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT row_hash FROM {$table} WHERE id <= %d ORDER BY id DESC LIMIT 1", $start_after )
			);
		}

		$breaks = [];
		foreach ( $rows as $row ) {
			$stored_prev = (string) ( $row['prev_hash'] ?? '' );
			$stored_row  = (string) $row['row_hash'];

			if ( $stored_prev !== $expected_prev ) {
				$breaks[] = (int) $row['id'];
			}
			$computed = self::compute_hash( $stored_prev, $row );
			if ( ! hash_equals( $computed, $stored_row ) ) {
				$breaks[] = (int) $row['id'];
			}
			$expected_prev = $stored_row;
		}

		return [
			'verified'  => empty( $breaks ),
			'broken_at' => array_values( array_unique( $breaks ) ),
			'scanned'   => count( $rows ),
		];
	}

	public static function head(): string {
		return (string) get_option( 'amplifi_security_audit_chain_head', '' );
	}

	public static function set_head( string $hash ): void {
		update_option( 'amplifi_security_audit_chain_head', $hash, false );
	}

	private static function secret(): string {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT' ] as $name ) {
			if ( defined( $name ) ) {
				$material .= (string) constant( $name );
			}
		}
		if ( '' === $material ) {
			$material = (string) get_option( 'siteurl' ) . '|amplifi-security|fallback';
		}
		$cached = hash_hkdf( 'sha256', $material, 32, self::KEY_INFO );
		return $cached;
	}
}
