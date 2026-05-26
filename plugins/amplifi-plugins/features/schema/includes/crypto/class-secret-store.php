<?php
/**
 * Symmetric encryption for secrets at rest.
 *
 * Derives an AES-256-GCM key from WordPress's `AUTH_KEY`-family constants via
 * HKDF-SHA256. API keys, log-source credentials, and any other persisted
 * secrets pass through `encrypt()` before hitting the database and are never
 * logged or echoed in plaintext.
 *
 * Ciphertext layout (after `v1:` prefix and base64):
 *   12-byte IV  ||  16-byte GCM tag  ||  ciphertext
 *
 * The version prefix lets us rotate algorithms in future without ambiguity
 * about how to decrypt legacy values.
 *
 * @package Amplifi\Schema\Crypto
 */

declare(strict_types=1);

namespace Amplifi\Schema\Crypto;

use RuntimeException;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Secret_Store {

	private const CIPHER     = 'aes-256-gcm';
	private const PREFIX     = 'v1:';
	private const KEY_INFO   = 'amplifi-security:secret-store:v1';
	private const IV_LENGTH  = 12;
	private const TAG_LENGTH = 16;

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::derive_key();
		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'amplifi.schema: encryption failed' );
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}
		if ( ! str_starts_with( $encoded, self::PREFIX ) ) {
			throw new InvalidArgumentException( 'amplifi.schema: unknown ciphertext version' );
		}

		$blob = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $blob || strlen( $blob ) < self::IV_LENGTH + self::TAG_LENGTH + 1 ) {
			throw new InvalidArgumentException( 'amplifi.schema: malformed ciphertext' );
		}

		$iv         = substr( $blob, 0, self::IV_LENGTH );
		$tag        = substr( $blob, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $blob, self::IV_LENGTH + self::TAG_LENGTH );

		$key       = self::derive_key();
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			throw new RuntimeException( 'amplifi.schema: decryption failed (auth tag mismatch)' );
		}

		return $plaintext;
	}

	/**
	 * Best-effort decrypt that returns null on any failure. Use this in
	 * non-critical paths where a missing/bad value should degrade gracefully.
	 */
	public static function try_decrypt( string $encoded ): ?string {
		try {
			return self::decrypt( $encoded );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Mask a secret for display (last 4 chars visible).
	 */
	public static function mask( string $value ): string {
		$len = strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}
		return str_repeat( '•', max( 8, $len - 4 ) ) . substr( $value, -4 );
	}

	private static function derive_key(): string {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ] as $name ) {
			if ( defined( $name ) ) {
				$material .= (string) constant( $name );
			}
		}

		if ( '' === $material ) {
			// Fall back to per-site stable material if wp-config has no keys
			// (extremely rare — WP installer always seeds them, but defensive).
			$fallback = get_option( 'amplifi_security_fallback_key' );
			if ( ! is_string( $fallback ) || '' === $fallback ) {
				$fallback = bin2hex( random_bytes( 64 ) );
				update_option( 'amplifi_security_fallback_key', $fallback, false );
			}
			$material = $fallback;
		}

		$cached = hash_hkdf( 'sha256', $material, 32, self::KEY_INFO );
		return $cached;
	}

	/**
	 * Store an encrypted secret in wp_options under a stable key prefix.
	 */
	public static function set( string $key, string $plaintext ): void {
		$option = 'ac_schema_secret_' . sanitize_key( $key );
		update_option( $option, self::encrypt( $plaintext ), false );
	}

	/**
	 * Retrieve and decrypt a secret. Returns null if unset or decryption fails.
	 */
	public static function get( string $key ): ?string {
		$option = 'ac_schema_secret_' . sanitize_key( $key );
		$cipher = get_option( $option, '' );
		if ( ! is_string( $cipher ) || $cipher === '' ) {
			return null;
		}
		return self::try_decrypt( $cipher );
	}

	public static function delete( string $key ): void {
		$option = 'ac_schema_secret_' . sanitize_key( $key );
		delete_option( $option );
	}
}
