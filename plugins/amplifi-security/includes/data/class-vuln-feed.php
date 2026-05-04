<?php
/**
 * Wordfence Intelligence vulnerability-feed sync + lookup.
 *
 * Sync: daily cron pulls the Production v3 feed (auth-token gated, free).
 * Lookup: pure DB read against `wp_amplifi_security_vuln_feed`.
 *
 * Uses MITRE-attribution data — when surfacing CVE records the plugin
 * displays the required attribution per the Wordfence ToS.
 *
 * @package Amplifi\Security\Data
 */

declare(strict_types=1);

namespace Amplifi\Security\Data;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Crypto\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Vuln_Feed {

	public const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';

	public static function register(): void {
		add_action( 'amplifi_security_vuln_feed_refresh', [ self::class, 'sync' ] );
	}

	public static function sync(): void {
		$token = self::auth_token();
		if ( '' === $token ) {
			Audit_Logger::log( 'vuln_feed_skip_no_token', [] );
			return;
		}

		$resp = wp_remote_get(
			self::ENDPOINT,
			[
				'timeout'   => 60,
				'sslverify' => true,
				'headers'   => [
					'Authorization' => 'Token ' . $token,
					'User-Agent'    => 'amplifi-security/' . AMPLIFI_SECURITY_VERSION,
				],
			]
		);
		if ( is_wp_error( $resp ) ) {
			Audit_Logger::log( 'vuln_feed_fetch_error', [ 'message' => $resp->get_error_message() ] );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			Audit_Logger::log( 'vuln_feed_http_error', [ 'code' => $code ] );
			return;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			Audit_Logger::log( 'vuln_feed_decode_error', [] );
			return;
		}

		self::ingest( $body );
		update_option( 'amplifi_security_vuln_feed_last_sync', time(), false );
		Audit_Logger::log( 'vuln_feed_synced', [ 'count' => count( $body ) ] );
	}

	private static function ingest( array $records ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_vuln_feed';
		$now   = current_time( 'mysql', true );

		foreach ( $records as $vuln_id => $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$software = $rec['software'] ?? [];
			foreach ( (array) $software as $sw ) {
				$slug = (string) ( $sw['slug'] ?? '' );
				if ( '' === $slug ) {
					continue;
				}
				$type     = (string) ( $sw['type'] ?? 'plugin' );
				$affected = $sw['affected_versions'] ?? [];
				$fixed_in = null;
				$range    = '';
				if ( is_array( $affected ) && ! empty( $affected ) ) {
					$first = reset( $affected );
					$fixed_in = $first['to_version']    ?? null;
					$from     = $first['from_version']  ?? '*';
					$range    = $from . ' .. ' . ( $fixed_in ?: '*' );
				}
				$cves = [];
				if ( ! empty( $rec['cve'] ) ) {
					$cves[] = (string) $rec['cve'];
				}
				if ( ! empty( $rec['cve_link'] ) ) {
					// best-effort additional CVE pulled from MITRE link
				}
				$cvss = isset( $rec['cvss']['score'] ) ? (float) $rec['cvss']['score'] : null;

				$wpdb->replace(
					$table,
					[
						'vuln_id'            => (string) $vuln_id,
						'component_slug'     => $slug,
						'component_type'     => $type,
						'affected_versions'  => $range,
						'fixed_in'           => $fixed_in,
						'cvss'               => $cvss,
						'cves'               => wp_json_encode( $cves ),
						'exploit_observed'   => ! empty( $rec['exploited'] ) ? 1 : 0,
						'raw_record'         => wp_json_encode( $rec ),
						'updated_at'         => $now,
					]
				);
			}
		}
	}

	/**
	 * Find vulnerability records that match the installed component+version.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function matches_for( string $slug, string $version, string $type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_vuln_feed';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vuln_id, component_slug, component_type, affected_versions, fixed_in, cvss, cves, exploit_observed, raw_record
				 FROM {$table}
				 WHERE component_slug = %s AND component_type = %s",
				$slug,
				$type
			),
			ARRAY_A
		);
		$matches = [];
		foreach ( (array) $rows as $row ) {
			$range = (string) ( $row['affected_versions'] ?? '' );
			[ $from, $to ] = self::parse_range( $range );
			$applies = self::version_in_range( $version, $from, $to );
			if ( ! $applies ) {
				continue;
			}
			$cves = json_decode( (string) ( $row['cves'] ?? '[]' ), true ) ?: [];
			$matches[] = [
				'vuln_id'         => $row['vuln_id'],
				'fixed_in'        => $row['fixed_in'],
				'cvss'            => isset( $row['cvss'] ) ? (float) $row['cvss'] : null,
				'cves'            => $cves,
				'exploit_observed'=> ! empty( $row['exploit_observed'] ),
				'wordfence_id'    => $row['vuln_id'],
				'affected_range'  => $range,
			];
		}
		return $matches;
	}

	private static function parse_range( string $range ): array {
		$parts = explode( '..', $range );
		$from  = trim( (string) ( $parts[0] ?? '*' ) );
		$to    = trim( (string) ( $parts[1] ?? '*' ) );
		return [ $from === '' ? '*' : $from, $to === '' ? '*' : $to ];
	}

	private static function version_in_range( string $version, string $from, string $to ): bool {
		// Normalise — strip non-version suffixes.
		$v = preg_replace( '/[^\d.]+.*$/', '', $version ) ?: $version;
		if ( '*' !== $from && version_compare( $v, $from, '<' ) ) {
			return false;
		}
		if ( '*' !== $to && version_compare( $v, $to, '>=' ) ) {
			return false;
		}
		return true;
	}

	public static function auth_token(): string {
		$enc = (string) get_option( 'amplifi_security_wordfence_token', '' );
		if ( '' === $enc ) {
			return '';
		}
		return Secret_Store::try_decrypt( $enc ) ?? '';
	}

	public static function set_auth_token( string $token ): void {
		if ( '' === $token ) {
			delete_option( 'amplifi_security_wordfence_token' );
			return;
		}
		update_option( 'amplifi_security_wordfence_token', Secret_Store::encrypt( $token ), false );
	}
}
