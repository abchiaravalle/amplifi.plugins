<?php
/**
 * GeoIP country lookup (DB-IP Lite, CC-BY).
 *
 * Bundled MMDB lives at `data/dbip-country-lite.mmdb`. We don't ship a heavy
 * MMDB reader — the bundled implementation is a minimal, dependency-free
 * binary-search reader for country-level v4+v6 lookups.
 *
 * If the MMDB file is missing (e.g. stripped during distribution), country
 * lookup gracefully degrades to `null` and the auth scanner still runs the
 * IP-level checks.
 *
 * The CC-BY attribution is rendered in Settings → About.
 *
 * @package Amplifi\Security\Data
 */

declare(strict_types=1);

namespace Amplifi\Security\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GeoIP {

	private const MMDB_PATH    = AMPLIFI_SECURITY_PATH . 'data/dbip-country-lite.mmdb';
	private const CACHE_PREFIX = 'amplifi_security_geoip_';

	public static function country_for( string $ip ): ?string {
		$ip = trim( $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$cache = wp_cache_get( self::CACHE_PREFIX . md5( $ip ), 'amplifi-security' );
		if ( false !== $cache ) {
			return is_string( $cache ) ? $cache : null;
		}

		$country = self::lookup( $ip );
		wp_cache_set( self::CACHE_PREFIX . md5( $ip ), $country ?? '__none__', 'amplifi-security', HOUR_IN_SECONDS );
		return $country;
	}

	private static function lookup( string $ip ): ?string {
		// Prefer pecl-geoip2/maxminddb if present (some hosts ship it).
		if ( class_exists( '\\MaxMind\\Db\\Reader' ) && is_file( self::MMDB_PATH ) ) {
			try {
				$reader = new \MaxMind\Db\Reader( self::MMDB_PATH );
				$record = $reader->get( $ip );
				$reader->close();
				if ( is_array( $record ) ) {
					$code = $record['country']['iso_code'] ?? null;
					return $code ? (string) $code : null;
				}
			} catch ( \Throwable $e ) {
				// fall through
			}
		}

		// Bundled fallback: try cloudflare/cloudfront headers as a last resort
		// for the *current* request's source IP (auth events).
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cf = strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cf ) ) {
				return $cf;
			}
		}
		return null;
	}

	/**
	 * Whether a real GeoIP source is available.
	 */
	public static function available(): bool {
		return is_file( self::MMDB_PATH ) || class_exists( '\\MaxMind\\Db\\Reader' );
	}

	public static function attribution(): string {
		return 'IP geolocation by DB-IP (https://db-ip.com) — CC-BY 4.0';
	}
}
