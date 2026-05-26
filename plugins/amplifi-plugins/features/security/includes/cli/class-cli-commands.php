<?php
/**
 * WP-CLI command surface (extra: not in spec but cheap to add).
 *
 * Commands:
 *   - `wp amplifi-security scan`         — run the full scan synchronously and print summary.
 *   - `wp amplifi-security findings`     — list recent findings.
 *   - `wp amplifi-security verify`       — verify the audit chain.
 *   - `wp amplifi-security canary`       — print the canary URL.
 *   - `wp amplifi-security stealth`      — toggle stealth mode (on|off|status).
 *
 * @package Amplifi\Security\Cli
 */

declare(strict_types=1);

namespace Amplifi\Security\Cli;

use Amplifi\Security\Audit\Audit_Chain;
use Amplifi\Security\Canary\Canary;
use Amplifi\Security\Scanners\Scan_Runner;
use Amplifi\Security\Stealth\Stealth_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cli_Commands {

	public static function register(): void {
		\WP_CLI::add_command( 'amplifi-security', self::class );
	}

	/**
	 * Run a full scan now.
	 *
	 * ## OPTIONS
	 *
	 * [--quiet]
	 * : Suppress per-scanner timing output.
	 */
	public function scan( array $args, array $assoc ): void {
		$quiet  = ! empty( $assoc['quiet'] );
		$result = Scan_Runner::run();
		\WP_CLI::success( sprintf( 'scan_id=%d findings=%d', $result['scan_id'], $result['findings'] ) );
		if ( ! $quiet ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT scanners_run FROM {$wpdb->prefix}amplifi_security_scans WHERE id = %d", $result['scan_id'] ), ARRAY_A );
			if ( $row && ! empty( $row['scanners_run'] ) ) {
				\WP_CLI::log( $row['scanners_run'] );
			}
		}
	}

	/**
	 * List recent findings.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<int>]
	 * : Default 20.
	 *
	 * [--verdict=<verdict>]
	 * : One of confirmed|likely|worth_reviewing|benign.
	 */
	public function findings( array $args, array $assoc ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'amplifi_security_findings';
		$limit   = max( 1, min( 200, (int) ( $assoc['limit'] ?? 20 ) ) );
		$verdict = sanitize_key( (string) ( $assoc['verdict'] ?? '' ) );

		$where = '1=1';
		$params = [];
		if ( $verdict ) {
			$where = 'verdict = %s';
			$params[] = $verdict;
		}
		$params[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, category, verdict, status, created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d",
				...$params
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			\WP_CLI::log( 'no findings' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'created_at', 'type', 'category', 'verdict', 'status' ] );
	}

	/**
	 * Verify the audit hash chain.
	 */
	public function verify( array $args, array $assoc ): void {
		$result = Audit_Chain::verify();
		if ( $result['verified'] ) {
			\WP_CLI::success( sprintf( 'audit chain verified (%d rows scanned)', $result['scanned'] ) );
		} else {
			\WP_CLI::warning( sprintf(
				'%d chain break(s) detected at row IDs: %s',
				count( $result['broken_at'] ),
				implode( ',', $result['broken_at'] )
			) );
		}
	}

	/**
	 * Print the canary URL.
	 */
	public function canary( array $args, array $assoc ): void {
		$url = Canary::url();
		if ( '' === $url ) {
			\WP_CLI::error( 'canary slug not generated yet — re-activate the plugin' );
		}
		\WP_CLI::log( $url );
	}

	/**
	 * Toggle or query stealth mode.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: on, off, status.
	 */
	public function stealth( array $args, array $assoc ): void {
		$action = strtolower( (string) ( $args[0] ?? 'status' ) );
		switch ( $action ) {
			case 'on':
				Stealth_Mode::enable();
				\WP_CLI::success( 'stealth mode enabled' );
				break;
			case 'off':
				Stealth_Mode::disable();
				\WP_CLI::success( 'stealth mode disabled' );
				break;
			default:
				\WP_CLI::log( Stealth_Mode::is_enabled() ? 'on' : 'off' );
		}
	}
}
