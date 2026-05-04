<?php
/**
 * AbuseIPDB v2 client (optional, free-tier).
 *
 * Aggressive 6-hour caching keeps a typical site well under the 1,000/day
 * free-tier limit. Logged-in admins' IPs are auto-allowlisted.
 *
 * @package Amplifi\Security\Data
 */

declare(strict_types=1);

namespace Amplifi\Security\Data;

use Amplifi\Security\Crypto\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AbuseIPDB_Client {

	public const ENDPOINT       = 'https://api.abuseipdb.com/api/v2/check';
	public const CACHE_TTL_SECS = 6 * HOUR_IN_SECONDS;

	public static function configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * @return array{confidence:int,reports:int,country:?string}|null
	 */
	public static function lookup( string $ip ): ?array {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		if ( self::is_allowlisted( $ip ) ) {
			return [ 'confidence' => 0, 'reports' => 0, 'country' => null ];
		}

		$cache_key = 'amplifi_security_abuseipdb_' . md5( $ip );
		$hit       = get_transient( $cache_key );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$key = self::api_key();
		if ( '' === $key ) {
			return null;
		}

		$resp = wp_remote_get(
			add_query_arg(
				[ 'ipAddress' => $ip, 'maxAgeInDays' => 90 ],
				self::ENDPOINT
			),
			[
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => [
					'Key'    => $key,
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$data = $json['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$result = [
			'confidence' => (int) ( $data['abuseConfidenceScore'] ?? 0 ),
			'reports'    => (int) ( $data['totalReports']         ?? 0 ),
			'country'    => isset( $data['countryCode'] ) ? (string) $data['countryCode'] : null,
		];
		set_transient( $cache_key, $result, self::CACHE_TTL_SECS );
		return $result;
	}

	private static function is_allowlisted( string $ip ): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$list     = (array) ( $settings['ip_allowlist'] ?? [] );
		if ( in_array( $ip, $list, true ) ) {
			return true;
		}
		// Auto-allowlist currently-logged-in admins' IPs.
		$auto = (array) get_transient( 'amplifi_security_admin_ip_allowlist' );
		if ( in_array( $ip, $auto, true ) ) {
			return true;
		}
		return false;
	}

	public static function note_admin_ip( string $ip ): void {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}
		$auto = (array) get_transient( 'amplifi_security_admin_ip_allowlist' );
		if ( ! in_array( $ip, $auto, true ) ) {
			$auto[] = $ip;
			$auto   = array_slice( $auto, -50 );
			set_transient( 'amplifi_security_admin_ip_allowlist', $auto, 7 * DAY_IN_SECONDS );
		}
	}

	public static function api_key(): string {
		$enc = (string) get_option( 'amplifi_security_abuseipdb_key', '' );
		if ( '' === $enc ) {
			return '';
		}
		return Secret_Store::try_decrypt( $enc ) ?? '';
	}

	public static function set_api_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( 'amplifi_security_abuseipdb_key' );
			return;
		}
		update_option( 'amplifi_security_abuseipdb_key', Secret_Store::encrypt( $key ), false );
	}
}
