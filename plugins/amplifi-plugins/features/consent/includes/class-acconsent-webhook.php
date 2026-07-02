<?php
/**
 * amplifi.consent — webhook dispatcher.
 *
 * Optional mirror of the server-side consent log to an external endpoint
 * (data warehouse, SIEM, the agency's central audit store). The DB row is the
 * source of truth; the webhook is a best-effort copy.
 *
 * - Fires AFTER the DB write, so a webhook failure never costs the visitor a
 *   recorded consent.
 * - NON-BLOCKING (`blocking => false`) so the visitor's request is never held
 *   up waiting on the remote endpoint.
 * - HMAC-SHA256 signs the body with a shared secret; the receiver verifies the
 *   `X-Amplifi-Consent-Signature` header to trust authenticity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Webhook {

	/**
	 * Send a consent receipt to the configured webhook. Safe to call on every
	 * event; no-op when disabled or unconfigured.
	 *
	 * @param array $receipt The full server-stamped receipt from Consent_Log::record().
	 */
	public static function dispatch( $receipt ) {
		$settings = Amplifi_Consent_Store::get_settings();
		if ( empty( $settings['webhook_enabled'] ) || empty( $settings['webhook_url'] ) ) {
			return;
		}

		$body = wp_json_encode( array(
			'type'    => 'consent.recorded',
			'site'    => home_url(),
			'receipt' => $receipt,
		) );

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'amplifi.consent/' . ( defined( 'ACCONSENT_VERSION' ) ? ACCONSENT_VERSION : '1.0' ),
		);

		// HMAC-SHA256 signature so the receiver can verify authenticity.
		if ( ! empty( $settings['webhook_secret'] ) ) {
			$sig = hash_hmac( 'sha256', $body, $settings['webhook_secret'] );
			$headers['X-Amplifi-Consent-Signature'] = 'sha256=' . $sig;
		}

		wp_remote_post( $settings['webhook_url'], array(
			'method'      => 'POST',
			'timeout'     => 5,
			'blocking'    => false, // fire-and-forget; never block the visitor.
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
			'sslverify'   => true,
		) );
	}

	/**
	 * Send a test payload from the admin so the user can verify their endpoint
	 * + secret. Returns a human-readable status string. This one IS blocking so
	 * we can report the result.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test() {
		$settings = Amplifi_Consent_Store::get_settings();
		if ( empty( $settings['webhook_url'] ) ) {
			return array( 'ok' => false, 'message' => 'No webhook URL configured.' );
		}
		$body = wp_json_encode( array(
			'type' => 'consent.test',
			'site' => home_url(),
			'time' => current_time( 'mysql', true ),
		) );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $settings['webhook_secret'] ) ) {
			$headers['X-Amplifi-Consent-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $settings['webhook_secret'] );
		}
		$res = wp_remote_post( $settings['webhook_url'], array(
			'method'   => 'POST',
			'timeout'  => 10,
			'blocking' => true,
			'headers'  => $headers,
			'body'     => $body,
		) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => 'Error: ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;
		return array(
			'ok'      => $ok,
			'message' => ( $ok ? 'Success' : 'Endpoint returned' ) . ' HTTP ' . $code . '.',
		);
	}
}
