<?php
/**
 * Helpers for storing the Anthropic API key encrypted at rest.
 *
 * Uses openssl_encrypt with AES-256-CBC keyed off wp_salt('auth'). The
 * encrypted value stored in wp_options always has the `enc:` prefix; legacy
 * unprefixed values are accepted and treated as plaintext during a one-time
 * migration when read.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption helpers — static, no state.
 */
class Amplifi_Optimize_Encryption {

	const PREFIX = 'enc:v1:';
	const CIPHER = 'AES-256-CBC';

	/**
	 * Encrypts a value for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		$key = self::key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ct  = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return $plaintext;
		}
		return self::PREFIX . base64_encode( $iv . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value previously encrypted by encrypt(). Returns plaintext
	 * unchanged if no PREFIX is present.
	 *
	 * @param string $value Stored value.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		$iv    = substr( $raw, 0, $ivlen );
		$ct    = substr( $raw, $ivlen );
		$pt    = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * Returns the symmetric key, hashed from wp_salt('auth').
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
