<?php
/**
 * Append-only audit log writer.
 *
 * Every event the plugin cares about — auth, user lifecycle, plugin/theme
 * lifecycle, privileged option changes, scan/triage/alert lifecycle, plugin
 * self-events — passes through `Audit_Logger::log()`. The row is sealed by
 * `Audit_Chain::compute_hash()` and the chain head is mirrored into
 * `wp_options`.
 *
 * Never log secrets. Filter sensitive option keys explicitly.
 *
 * @package Amplifi\Security\Audit
 */

declare(strict_types=1);

namespace Amplifi\Security\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Logger {

	private const SECRET_KEY_PATTERNS = [
		'/api[_-]?key/i',
		'/secret/i',
		'/password/i',
		'/auth[_-]?token/i',
		'/bearer/i',
		'/private[_-]?key/i',
		'/access[_-]?key/i',
	];

	/**
	 * Write an audit event.
	 *
	 * @param string                       $event_type Event slug (snake_case).
	 * @param array<string,mixed>          $data       Event payload. Optional `target_type`,
	 *                                                 `target_id`, `actor_user_id`, `actor_ip`
	 *                                                 fields will be lifted out of the payload.
	 *                                                 Anything else stored as JSON in event_data.
	 */
	public static function log( string $event_type, array $data = [] ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_audit';

		// Lift out structured columns.
		$actor_user_id = $data['actor_user_id'] ?? get_current_user_id() ?: null;
		$actor_ip      = $data['actor_ip']      ?? self::client_ip();
		$actor_ua      = $data['actor_ua']      ?? self::client_ua();
		$target_type   = $data['target_type']   ?? null;
		$target_id     = $data['target_id']     ?? null;

		// Strip structural fields from the JSON payload to avoid duplication.
		$payload = $data;
		unset(
			$payload['actor_user_id'],
			$payload['actor_ip'],
			$payload['actor_ua'],
			$payload['target_type'],
			$payload['target_id']
		);

		$payload    = self::redact( $payload );
		$created_at = current_time( 'mysql', true );
		$prev_hash  = Audit_Chain::head();

		$row = [
			'event_type'    => $event_type,
			'actor_user_id' => $actor_user_id ? (int) $actor_user_id : null,
			'actor_ip'      => $actor_ip,
			'actor_ua'      => $actor_ua,
			'target_type'   => $target_type,
			'target_id'     => $target_id ? (string) $target_id : null,
			'event_data'    => wp_json_encode( $payload ),
			'prev_hash'     => '' !== $prev_hash ? $prev_hash : null,
			'created_at'    => $created_at,
		];

		$row['row_hash'] = Audit_Chain::compute_hash( $prev_hash, $row );

		$wpdb->insert( $table, $row );

		Audit_Chain::set_head( $row['row_hash'] );
	}

	/**
	 * Prune entries older than retention window. Called from daily cron.
	 *
	 * @return int rows deleted
	 */
	public static function prune( int $retention_days ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'amplifi_security_audit';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Recursively replace values whose keys match secret patterns.
	 */
	private static function redact( array $payload ): array {
		$out = [];
		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) && self::is_secret_key( $key ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function is_secret_key( string $key ): bool {
		foreach ( self::SECRET_KEY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return true;
			}
		}
		return false;
	}

	public static function client_ip(): ?string {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	public static function client_ua(): ?string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}
		$ua = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );
		return '' !== $ua ? mb_substr( $ua, 0, 1024 ) : null;
	}
}
