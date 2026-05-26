<?php
/**
 * Deactivation handler.
 *
 * Two responsibilities:
 *   1. `on_pre_deactivate` — fires synchronously on the `deactivate_plugin`
 *      action at priority 1, *before* the active-plugins option is updated.
 *      It dispatches an alert through `Alert_Router` (Phase 4) so the alert
 *      goes out even if subsequent execution is killed.
 *   2. `deactivate` — registered via `register_deactivation_hook`, runs the
 *      cleanup that doesn't need to be synchronous (cron unscheduling).
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

use Amplifi\Security\Alerts\Alert_Router;
use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	/**
	 * Synchronous pre-deactivation alert.
	 *
	 * @param string $plugin                  Plugin basename being deactivated.
	 * @param bool   $network_deactivating    Whether this is a network-wide deactivation.
	 */
	public static function on_pre_deactivate( string $plugin, bool $network_deactivating ): void {
		if ( $plugin !== AMPLIFI_SECURITY_BASENAME ) {
			return;
		}

		$user_id = get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;
		$ip      = self::client_ip();
		$context = [
			'event'   => 'plugin_deactivation_attempt',
			'user_id' => $user_id ?: null,
			'user_login' => $user ? $user->user_login : null,
			'ip'      => $ip,
			'network' => $network_deactivating,
			'when'    => current_time( 'mysql', true ),
		];

		Audit_Logger::log( 'plugin_deactivated', $context );

		// Dispatch a synchronous alert (Phase 4 wires Alert_Router; if it isn't
		// loaded yet — earliest plugin life — fall back to wp_mail).
		if ( class_exists( Alert_Router::class ) ) {
			Alert_Router::dispatch_sync(
				'core_tampering',
				'confirmed',
				__( 'amplifi.security has been deactivated', 'amplifi-security' ),
				self::format_alert_body( $context )
			);
			return;
		}

		self::wp_mail_fallback( $context );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'amplifi_security_run_scan' );
		wp_clear_scheduled_hook( 'amplifi_security_audit_prune' );
		wp_clear_scheduled_hook( 'amplifi_security_vuln_feed_refresh' );
		wp_clear_scheduled_hook( 'amplifi_security_daily_digest' );
		wp_clear_scheduled_hook( 'amplifi_security_self_integrity' );

		Audit_Logger::log(
			'plugin_deactivation_completed',
			[ 'plugin' => AMPLIFI_SECURITY_BASENAME ]
		);
	}

	private static function client_ip(): ?string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	private static function format_alert_body( array $context ): string {
		$site = home_url();
		return sprintf(
			"amplifi.security on %s is being deactivated.\n\nUser: %s (id %s)\nIP: %s\nWhen: %s UTC\n\nIf this was you, ignore. If not, this is a strong compromise signal — investigate immediately.",
			$site,
			$context['user_login'] ?? 'unknown',
			$context['user_id'] ?? 'n/a',
			$context['ip'] ?? 'unknown',
			$context['when']
		);
	}

	private static function wp_mail_fallback( array $context ): void {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$recipients = $settings['notification_recipients'] ?? [];
		if ( empty( $recipients ) ) {
			$recipients = [ get_option( 'admin_email' ) ];
		}
		if ( empty( $recipients ) ) {
			return;
		}
		wp_mail(
			$recipients,
			sprintf( '[amplifi.security] Plugin deactivated on %s', wp_parse_url( home_url(), PHP_URL_HOST ) ),
			self::format_alert_body( $context )
		);
	}
}
