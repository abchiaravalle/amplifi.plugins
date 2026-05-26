<?php
/**
 * Alert router.
 *
 * Resolves (category × verdict) → channel using the user-configured matrix,
 * applies the hardcoded floors (`confirmed malware` cannot be muted), respects
 * quiet hours per-category, and dispatches via SMTP2Go and Textbelt.
 *
 * Per-incident emails fire immediately for `confirmed` and `likely`.
 * `worth_reviewing` items accumulate for the daily digest.
 *
 * @package Amplifi\Security\Alerts
 */

declare(strict_types=1);

namespace Amplifi\Security\Alerts;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Alert_Router {

	private const CHANNEL_EMAIL_SMS = 'email_sms';
	private const CHANNEL_EMAIL     = 'email';
	private const CHANNEL_DIGEST    = 'digest';
	private const CHANNEL_LOG       = 'log';
	private const CHANNEL_MUTE      = 'mute';

	public static function register(): void {
		add_action( 'amplifi_security_daily_digest', [ self::class, 'send_daily_digest' ] );
	}

	public static function route_findings_for_scan( int $scan_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, category, verdict, rationale, recommended_action, type, evidence
				 FROM {$table}
				 WHERE scan_id = %d AND status = 'triaged' AND verdict IS NOT NULL",
				$scan_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			self::route_finding( $row );
		}
	}

	public static function route_finding( array $row ): void {
		$category = (string) ( $row['category'] ?? 'other' );
		$verdict  = (string) ( $row['verdict']  ?? 'worth_reviewing' );

		$channel = self::resolve_channel( $category, $verdict );
		if ( self::CHANNEL_MUTE === $channel ) {
			return;
		}
		if ( self::CHANNEL_LOG === $channel ) {
			return; // already in audit log via scan_completed
		}
		if ( self::CHANNEL_DIGEST === $channel ) {
			return; // sent on daily cron
		}

		// Quiet hours? Confirmed always pierces; lower verdicts defer to digest.
		if ( self::in_quiet_hours() && 'confirmed' !== $verdict ) {
			return;
		}

		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$subj = sprintf( '[amplifi.security] %s — %s on %s', strtoupper( $verdict ), $row['type'], $site );
		[ $text, $html ] = self::format_body( $row );

		Smtp2Go_Client::send( self::recipients_for_category( $category ), $subj, $text, $html );

		if ( self::CHANNEL_EMAIL_SMS === $channel && 'confirmed' === $verdict ) {
			$rationale = (string) ( $row['rationale'] ?? '' );
			$first_sentence = preg_split( '/(?<=[.!?])\s/', $rationale )[0] ?? mb_substr( $rationale, 0, 100 );
			Textbelt_Client::send(
				sprintf( '[amplifi.security] CONFIRMED %s on %s. %s Check email.', $row['type'], $site, $first_sentence )
			);
		}

		Audit_Logger::log(
			'alert_sent',
			[
				'finding_id' => (int) $row['id'],
				'category'   => $category,
				'verdict'    => $verdict,
				'channel'    => $channel,
			]
		);
	}

	/**
	 * Synchronous dispatch (no DB lookup) — used by the pre-deactivation alert.
	 */
	public static function dispatch_sync( string $category, string $verdict, string $subject, string $body ): void {
		$channel = self::resolve_channel( $category, $verdict );
		if ( self::CHANNEL_MUTE === $channel || self::CHANNEL_LOG === $channel ) {
			// Force at least an email for plugin-deactivation events.
			$channel = self::CHANNEL_EMAIL;
		}
		Smtp2Go_Client::send( self::recipients_for_category( $category ), $subject, $body );
		if ( self::CHANNEL_EMAIL_SMS === $channel && 'confirmed' === $verdict ) {
			Textbelt_Client::send( $subject . ' — ' . mb_substr( $body, 0, 120 ) );
		}
	}

	public static function send_daily_digest(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, category, verdict, type, rationale, created_at
				 FROM {$table}
				 WHERE created_at >= %s AND verdict IS NOT NULL AND verdict != 'benign'",
				$cutoff
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return; // skip the digest entirely if zero non-benign — avoid creating noise
		}

		// Filter to ones routed to digest.
		$by_verdict = [ 'confirmed' => [], 'likely' => [], 'worth_reviewing' => [] ];
		foreach ( $rows as $row ) {
			$cat  = (string) $row['category'];
			$verd = (string) $row['verdict'];
			$ch   = self::resolve_channel( $cat, $verd );
			if ( self::CHANNEL_DIGEST !== $ch && 'worth_reviewing' !== $verd ) {
				continue;
			}
			$by_verdict[ $verd ][] = $row;
		}
		if ( empty( $by_verdict['confirmed'] ) && empty( $by_verdict['likely'] ) && empty( $by_verdict['worth_reviewing'] ) ) {
			return;
		}

		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = [];
		$lines[] = sprintf( 'amplifi.security daily digest for %s', $site );
		$lines[] = '';
		foreach ( $by_verdict as $verd => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			$lines[] = sprintf( '== %s (%d) ==', strtoupper( $verd ), count( $items ) );
			foreach ( $items as $f ) {
				$lines[] = sprintf( '#%d [%s] %s — %s', $f['id'], $f['category'], $f['type'], mb_substr( (string) $f['rationale'], 0, 200 ) );
			}
			$lines[] = '';
		}

		$lines[] = 'Open the dashboard to triage these: ' . admin_url( 'admin.php?page=amplifi-security-findings' );

		Smtp2Go_Client::send(
			self::default_recipients(),
			sprintf( '[amplifi.security] Daily digest — %s', $site ),
			implode( "\n", $lines )
		);
	}

	/* ------------------------------------------------------------------ */

	private static function resolve_channel( string $category, string $verdict ): string {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$matrix   = $settings['routing_matrix'] ?? [];
		$resolved = $matrix[ $category ][ $verdict ] ?? null;

		// Hardcoded floor: confirmed malware cannot be muted.
		if ( 'malware' === $category && 'confirmed' === $verdict ) {
			if ( in_array( $resolved, [ self::CHANNEL_MUTE, self::CHANNEL_LOG, self::CHANNEL_DIGEST, null ], true ) ) {
				return self::CHANNEL_EMAIL_SMS;
			}
		}

		return $resolved ?: self::CHANNEL_DIGEST;
	}

	private static function in_quiet_hours(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$qh       = $settings['quiet_hours'] ?? [];
		if ( empty( $qh['enabled'] ) ) {
			return false;
		}
		$start = (int) ( $qh['start'] ?? 22 );
		$end   = (int) ( $qh['end']   ?? 7 );
		$hour  = (int) gmdate( 'G' );
		if ( $start === $end ) {
			return false;
		}
		return $start < $end ? ( $hour >= $start && $hour < $end ) : ( $hour >= $start || $hour < $end );
	}

	private static function default_recipients(): array {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$list     = (array) ( $settings['notification_recipients'] ?? [] );
		$list     = array_values( array_filter( array_map( 'sanitize_email', $list ) ) );
		if ( empty( $list ) ) {
			$list = array_values( array_filter( [ get_option( 'admin_email' ) ] ) );
		}
		return $list;
	}

	private static function recipients_for_category( string $category ): array {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$over     = $settings['recipients_by_category'] ?? [];
		if ( ! empty( $over[ $category ] ) ) {
			return array_values( array_filter( array_map( 'sanitize_email', (array) $over[ $category ] ) ) );
		}
		return self::default_recipients();
	}

	/**
	 * @return array{0:string,1:string} [text, html]
	 */
	private static function format_body( array $row ): array {
		$site       = wp_parse_url( home_url(), PHP_URL_HOST );
		$id         = (int) $row['id'];
		$rationale  = (string) ( $row['rationale']          ?? '' );
		$action     = (string) ( $row['recommended_action'] ?? '' );
		$type       = (string) ( $row['type']               ?? '' );
		$category   = (string) ( $row['category']           ?? '' );
		$verdict    = (string) ( $row['verdict']            ?? '' );
		$detail_url = admin_url( 'admin.php?page=amplifi-security-findings&id=' . $id );
		$benign_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=amplifi_security_mark_fp&id=' . $id ),
			'amplifi_security_mark_fp_' . $id
		);

		$text = <<<TEXT
amplifi.security — {$verdict} {$category} on {$site}

Type: {$type}
Finding ID: #{$id}

What we saw:
{$rationale}

Recommended first action:
{$action}

Open in dashboard: {$detail_url}
Mark as false positive (one-click): {$benign_url}
TEXT;

		$html = sprintf(
			'<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1f1f1f">
				<h2 style="margin:0 0 8px">amplifi.security &mdash; %1$s %2$s</h2>
				<p style="margin:0 0 12px"><strong>Site:</strong> %3$s &nbsp; <strong>Type:</strong> %4$s &nbsp; <strong>ID:</strong> #%5$d</p>
				<p style="margin:0 0 12px"><strong>What we saw:</strong><br>%6$s</p>
				<p style="margin:0 0 12px"><strong>Recommended first action:</strong><br>%7$s</p>
				<p style="margin:0 0 12px">
					<a href="%8$s" style="background:#1f6feb;color:#fff;padding:10px 14px;text-decoration:none;border-radius:4px">Open in dashboard</a>
					&nbsp;
					<a href="%9$s" style="color:#666">Mark as false positive</a>
				</p>
			</div>',
			esc_html( $verdict ),
			esc_html( $category ),
			esc_html( $site ),
			esc_html( $type ),
			$id,
			nl2br( esc_html( $rationale ) ),
			nl2br( esc_html( $action ) ),
			esc_url( $detail_url ),
			esc_url( $benign_url )
		);

		return [ $text, $html ];
	}
}
