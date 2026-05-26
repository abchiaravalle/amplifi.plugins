<?php
/**
 * SMTP2Go REST API client.
 *
 * Plain HTTP, JSON in/out — `wp_remote_post` only. Uses the v3 REST endpoint
 * `https://api.smtp2go.com/v3/email/send` because REST gets through more
 * locked-down hosts than SMTP, and we don't want to depend on PHPMailer's
 * outbound config.
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

final class Smtp2Go_Client {

	public const ENDPOINT = 'https://api.smtp2go.com/v3/email/send';

	public static function configured(): bool {
		return '' !== self::api_key() && '' !== self::sender();
	}

	public static function send( array $to, string $subject, string $text, string $html = '' ): bool {
		$key = self::api_key();
		if ( '' === $key ) {
			return self::wp_mail_fallback( $to, $subject, $text, $html );
		}

		$body = [
			'api_key'    => $key,
			'sender'     => self::sender(),
			'to'         => array_values( $to ),
			'subject'    => $subject,
			'text_body'  => $text,
		];
		if ( '' !== $html ) {
			$body['html_body'] = $html;
		}

		$resp = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => [
					'content-type' => 'application/json',
					'user-agent'   => 'amplifi-security/' . AMPLIFI_SECURITY_VERSION,
				],
				'body' => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $resp ) ) {
			Audit_Logger::log( 'smtp2go_transport_error', [ 'message' => $resp->get_error_message() ] );
			return self::wp_mail_fallback( $to, $subject, $text, $html );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			Audit_Logger::log( 'smtp2go_http_error', [ 'code' => $code, 'body' => mb_substr( (string) wp_remote_retrieve_body( $resp ), 0, 500 ) ] );
			return self::wp_mail_fallback( $to, $subject, $text, $html );
		}
		Audit_Logger::log( 'alert_email_sent', [ 'to' => array_map( 'self::mask_email', $to ), 'subject' => $subject ] );
		return true;
	}

	public static function ping( string $key, string $sender ): bool {
		if ( '' === $key || '' === $sender ) {
			return false;
		}
		$resp = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => [ 'content-type' => 'application/json' ],
				'body'      => wp_json_encode(
					[
						'api_key'   => $key,
						'sender'    => $sender,
						'to'        => [ $sender ],
						'subject'   => 'amplifi.security test',
						'text_body' => 'amplifi.security test alert. If you got this, alerting works.',
					]
				),
			]
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code >= 200 && $code < 300;
	}

	public static function api_key(): string {
		$enc = (string) get_option( 'amplifi_security_smtp2go_key', '' );
		if ( '' === $enc ) {
			return '';
		}
		return Secret_Store::try_decrypt( $enc ) ?? '';
	}

	public static function set_api_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( 'amplifi_security_smtp2go_key' );
			return;
		}
		update_option( 'amplifi_security_smtp2go_key', Secret_Store::encrypt( $key ), false );
	}

	public static function sender(): string {
		return (string) get_option( 'amplifi_security_smtp2go_sender', '' );
	}

	public static function set_sender( string $email ): void {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			delete_option( 'amplifi_security_smtp2go_sender' );
			return;
		}
		update_option( 'amplifi_security_smtp2go_sender', $email, false );
	}

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		[ $local, $domain ] = $parts;
		return mb_substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/**
	 * Last-resort fallback. Used when SMTP2Go is unavailable so alerts still
	 * have a chance of getting through (especially for the synchronous
	 * pre-deactivation alert).
	 */
	private static function wp_mail_fallback( array $to, string $subject, string $text, string $html ): bool {
		$headers = $html !== ''
			? [ 'Content-Type: text/html; charset=UTF-8' ]
			: [];
		$ok = wp_mail( $to, $subject, $html !== '' ? $html : $text, $headers );
		Audit_Logger::log( 'alert_email_wp_mail_fallback', [ 'ok' => (bool) $ok, 'subject' => $subject ] );
		return (bool) $ok;
	}
}
