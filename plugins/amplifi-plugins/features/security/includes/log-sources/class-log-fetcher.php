<?php
/**
 * URL-based raw log fetcher.
 *
 * Reads each user-configured log URL with `wp_remote_get`, applies the per-
 * source byte cap, and returns the raw text — Claude does the correlation,
 * we don't parse.
 *
 * Failure modes are explicitly non-blocking: a slow or unreachable log URL
 * just doesn't contribute to this run, and after 5 consecutive failures the
 * source is auto-disabled and a dashboard warning surfaces.
 *
 * @package Amplifi\Security\Log_Sources
 */

declare(strict_types=1);

namespace Amplifi\Security\Log_Sources;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Crypto\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Log_Fetcher {

	public const MAX_TOTAL_BYTES_PER_TRIAGE = 524_288; // 500 KB
	public const MAX_SOURCES                = 5;
	public const MAX_BYTES_PER_SOURCE_HARD  = 10_485_760; // 10 MB
	public const FAILURE_DISABLE_AT         = 5;

	/**
	 * @return array<string,string> Map of source-name → raw text.
	 */
	public static function fetch_all( int $total_byte_budget = self::MAX_TOTAL_BYTES_PER_TRIAGE ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_log_sources';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sources = (array) $wpdb->get_results(
			"SELECT id, name, url, auth_type, auth_secret, max_bytes, consecutive_failures
			 FROM {$table}
			 WHERE enabled = 1
			 ORDER BY id ASC
			 LIMIT " . self::MAX_SOURCES,
			ARRAY_A
		);
		if ( empty( $sources ) ) {
			return [];
		}

		$out      = [];
		$used     = 0;
		$per_src  = (int) max( 1, floor( $total_byte_budget / max( 1, count( $sources ) ) ) );
		foreach ( $sources as $src ) {
			$body = self::fetch_one( $src, min( (int) $src['max_bytes'], $per_src ) );
			if ( null === $body ) {
				continue;
			}
			$slice = mb_substr( $body, max( 0, mb_strlen( $body ) - $per_src ) );
			$used += strlen( $slice );
			$out[ (string) $src['name'] ] = $slice;
			if ( $used >= $total_byte_budget ) {
				break;
			}
		}
		return $out;
	}

	private static function fetch_one( array $src, int $byte_cap ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_log_sources';

		$url   = (string) $src['url'];
		$cap   = max( 4096, min( $byte_cap, self::MAX_BYTES_PER_SOURCE_HARD ) );

		$args = [
			'timeout'   => 15,
			'sslverify' => true,
			'redirection' => 3,
			'user-agent' => 'amplifi-security/' . AMPLIFI_SECURITY_VERSION,
			'headers'   => [
				// Many log servers honor a Range trailer; if they don't, we'll just truncate.
				'Range' => 'bytes=-' . $cap,
			],
		];

		$auth_type = (string) ( $src['auth_type'] ?? 'none' );
		$secret    = $src['auth_secret'] ? Secret_Store::try_decrypt( (string) $src['auth_secret'] ) : null;
		if ( $secret && 'basic' === $auth_type ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $secret );
		} elseif ( $secret && 'bearer' === $auth_type ) {
			$args['headers']['Authorization'] = 'Bearer ' . $secret;
		} elseif ( $secret && 'custom_header' === $auth_type ) {
			[ $name, $value ] = array_pad( explode( ':', $secret, 2 ), 2, '' );
			$name = trim( (string) $name );
			$value = ltrim( (string) $value );
			if ( '' !== $name ) {
				$args['headers'][ $name ] = $value;
			}
		}

		$resp = wp_remote_get( $url, $args );

		$ok = ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) >= 200 && (int) wp_remote_retrieve_response_code( $resp ) < 300;
		if ( ! $ok ) {
			$failures = (int) $src['consecutive_failures'] + 1;
			$disabled = $failures >= self::FAILURE_DISABLE_AT;
			$wpdb->update(
				$table,
				[
					'last_fetch_at'        => current_time( 'mysql', true ),
					'last_status'          => 'failed',
					'consecutive_failures' => $failures,
					'enabled'              => $disabled ? 0 : 1,
				],
				[ 'id' => (int) $src['id'] ]
			);
			Audit_Logger::log(
				'log_source_fetch_failed',
				[
					'source_id'   => (int) $src['id'],
					'name'        => $src['name'],
					'failures'    => $failures,
					'disabled'    => $disabled,
					'http_error'  => is_wp_error( $resp ) ? $resp->get_error_message() : (int) wp_remote_retrieve_response_code( $resp ),
				]
			);
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $resp );
		$wpdb->update(
			$table,
			[
				'last_fetch_at'        => current_time( 'mysql', true ),
				'last_status'          => 'ok',
				'consecutive_failures' => 0,
			],
			[ 'id' => (int) $src['id'] ]
		);
		// Truncate to cap (Range headers aren't always honored).
		if ( strlen( $body ) > $cap ) {
			$body = substr( $body, -$cap );
		}
		return $body;
	}
}
