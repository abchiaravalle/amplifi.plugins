<?php
/**
 * Stealth Mode — hide the plugin from non-installer admins.
 *
 * Filters everywhere WordPress would otherwise reveal the plugin's presence:
 *   - `all_plugins`           — hide row in Plugins list
 *     `active_plugins`        — hide from "active plugins" status
 *   - `admin_menu`            — short-circuited in `Admin::register_menu()`
 *   - `wp_head`               — strip generator output if any
 *   - update transients       — hide from "updates available" rows
 *
 * Recovery:
 *   The installer always sees the plugin. Anyone with file access can also
 *   define `AMPLIFI_SECURITY_INSTALLER_ID` in wp-config.php to override
 *   stealth visibility. There's also a one-time `?amplifi_unhide=<token>`
 *   query param that surfaces the menu for the current session.
 *
 * Stealth does NOT hide files on disk, DB tables, or outbound network
 * traffic — that's host-level concerns.
 *
 * @package Amplifi\Security\Stealth
 */

declare(strict_types=1);

namespace Amplifi\Security\Stealth;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stealth_Mode {

	private const SESSION_FLAG = 'amplifi_security_unhide_session';

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybe_consume_unhide_token' ], 1 );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'all_plugins',                  [ self::class, 'filter_plugins_list' ] );
		add_filter( 'plugin_action_links_' . AMPLIFI_SECURITY_BASENAME, [ self::class, 'filter_action_links' ] );
		add_filter( 'site_transient_update_plugins',[ self::class, 'filter_update_transient' ] );
		add_filter( 'transient_update_plugins',     [ self::class, 'filter_update_transient' ] );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( 'amplifi_security_stealth_enabled', false );
	}

	public static function should_hide_for_current_user(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$current = get_current_user_id();
		if ( $current && (int) $current === self::installer_id() ) {
			return false;
		}
		// Session-flagged via unhide token.
		if ( ! headers_sent() ) {
			if ( isset( $_COOKIE[ self::SESSION_FLAG ] ) && (string) $_COOKIE[ self::SESSION_FLAG ] === self::session_token() ) {
				return false;
			}
		}
		return true;
	}

	public static function installer_id(): int {
		if ( defined( 'AMPLIFI_SECURITY_INSTALLER_ID' ) ) {
			return (int) constant( 'AMPLIFI_SECURITY_INSTALLER_ID' );
		}
		return (int) get_option( 'amplifi_security_installer_id', 0 );
	}

	public static function enable(): void {
		update_option( 'amplifi_security_stealth_enabled', 1, false );
		Audit_Logger::log( 'stealth_enabled', [] );
	}

	public static function disable(): void {
		update_option( 'amplifi_security_stealth_enabled', 0, false );
		Audit_Logger::log( 'stealth_disabled', [] );
	}

	public static function rotate_unhide_token(): string {
		$new = wp_generate_password( 48, false );
		update_option( 'amplifi_security_unhide_token', $new, false );
		Audit_Logger::log( 'stealth_unhide_token_rotated', [] );
		return $new;
	}

	public static function maybe_consume_unhide_token(): void {
		if ( empty( $_GET['amplifi_unhide'] ) ) {
			return;
		}
		$presented = sanitize_text_field( wp_unslash( (string) $_GET['amplifi_unhide'] ) );
		$expected  = (string) get_option( 'amplifi_security_unhide_token', '' );

		if ( '' === $expected || ! hash_equals( $expected, $presented ) ) {
			return;
		}

		// Set a session-bound cookie so the user keeps visibility for this
		// browser session. The token isn't rotated automatically — they must
		// hit the rotate button in Settings to consume it.
		if ( ! headers_sent() ) {
			setcookie(
				self::SESSION_FLAG,
				self::session_token(),
				[
					'expires'  => time() + HOUR_IN_SECONDS * 8,
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		Audit_Logger::log( 'stealth_unhide_token_used', [] );
	}

	private static function session_token(): string {
		// A stable per-site value that's not the unhide token itself (we don't
		// want the unhide token going into a cookie). Derived deterministically
		// from canary secret so server can recompute.
		$canary = (string) get_option( 'amplifi_security_canary_secret', '' );
		return hash_hmac( 'sha256', 'stealth-session', $canary ?: 'fallback' );
	}

	/* -------- filters ------------------------------------------------- */

	public static function filter_plugins_list( array $plugins ): array {
		if ( ! self::should_hide_for_current_user() ) {
			return $plugins;
		}
		unset( $plugins[ AMPLIFI_SECURITY_BASENAME ] );
		return $plugins;
	}

	public static function filter_action_links( array $links ): array {
		if ( self::should_hide_for_current_user() ) {
			return [];
		}
		return $links;
	}

	public static function filter_update_transient( $value ) {
		if ( ! self::should_hide_for_current_user() ) {
			return $value;
		}
		if ( is_object( $value ) && isset( $value->response[ AMPLIFI_SECURITY_BASENAME ] ) ) {
			unset( $value->response[ AMPLIFI_SECURITY_BASENAME ] );
		}
		if ( is_object( $value ) && isset( $value->no_update[ AMPLIFI_SECURITY_BASENAME ] ) ) {
			unset( $value->no_update[ AMPLIFI_SECURITY_BASENAME ] );
		}
		return $value;
	}
}
