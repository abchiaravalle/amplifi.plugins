<?php
/**
 * Textbelt SMS client.
 *
 * Reserved for `confirmed` verdicts only. Hard-capped at 3/day in code
 * regardless of UI configuration so a runaway loop can't burn $$$.
 *
 * @package Amplifi\Security\Alerts
 */

declare(strict_types=1);

namespace Amplifi\Security\Alerts;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Crypto\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Textbelt_Client {

	public const ENDPOINT      = 'https://textbelt.com/text';
	public const HARD_DAILY_CAP = 3;
	private const COUNTER_OPT   = 'amplifi_security_sms_counter';

	public static function configured(): bool {
		return '' !== self::api_key() && '' !== self::phone();
	}

	public static function send( string $body ): bool {
		if ( ! self::configured() ) {
			return false;
		}
		if ( ! self::within_daily_cap() ) {
			Audit_Logger::log( 'sms_blocked_daily_cap', [] );
			return false;
		}

		$resp = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout'   => 10,
				'sslverify' => true,
				'body'      => [
					'phone'   => self::phone(),
					'message' => mb_substr( $body, 0, 320 ),
					'key'     => self::api_key(),
				],
			]
		);
		if ( is_wp_error( $resp ) ) {
			Audit_Logger::log( 'sms_transport_error', [ 'message' => $resp->get_error_message() ] );
			return false;
		}
		$code   = (int) wp_remote_retrieve_response_code( $resp );
		$json   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$ok     = $code >= 200 && $code < 300 && is_array( $json ) && ! empty( $json['success'] );
		if ( $ok ) {
			self::record_send();
		}
		Audit_Logger::log(
			$ok ? 'sms_sent' : 'sms_failed',
			[
				'http_code' => $code,
				'reason'    => is_array( $json ) ? ( $json['error'] ?? null ) : null,
			]
		);
		return $ok;
	}

	private static function within_daily_cap(): bool {
		$counter = (array) get_option( self::COUNTER_OPT, [] );
		$today   = gmdate( 'Y-m-d' );
		$count   = (int) ( $counter[ $today ] ?? 0 );

		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$ui_cap   = (int) ( $settings['sms_quota_per_day'] ?? self::HARD_DAILY_CAP );
		$cap      = max( 0, min( self::HARD_DAILY_CAP, $ui_cap ) );

		return $count < $cap;
	}

	private static function record_send(): void {
		$counter = (array) get_option( self::COUNTER_OPT, [] );
		$today   = gmdate( 'Y-m-d' );
		$counter = array_filter(
			$counter,
			static fn( $value, $key ) => $key === $today,
			ARRAY_FILTER_USE_BOTH
		);
		$counter[ $today ] = (int) ( $counter[ $today ] ?? 0 ) + 1;
		update_option( self::COUNTER_OPT, $counter, false );
	}

	public static function api_key(): string {
		$enc = (string) get_option( 'amplifi_security_textbelt_key', '' );
		if ( '' === $enc ) {
			return '';
		}
		return Secret_Store::try_decrypt( $enc ) ?? '';
	}

	public static function set_api_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( 'amplifi_security_textbelt_key' );
			return;
		}
		update_option( 'amplifi_security_textbelt_key', Secret_Store::encrypt( $key ), false );
	}

	public static function phone(): string {
		return (string) get_option( 'amplifi_security_textbelt_phone', '' );
	}

	public static function set_phone( string $phone ): void {
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		update_option( 'amplifi_security_textbelt_phone', (string) $phone, false );
	}
}
