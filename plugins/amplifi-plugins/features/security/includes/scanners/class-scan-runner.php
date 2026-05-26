<?php
/**
 * Orchestrates all scanners on the WP-Cron schedule.
 *
 * Flow per run:
 *   1. Open a `scans` row with `started_at`.
 *   2. For each enabled scanner: call `run($scan_id)`, persist findings as
 *      `pending_triage`. Catch exceptions per-scanner so a failing scanner
 *      doesn't kill the whole run.
 *   3. Run self-integrity verification (separate from scanners — its own
 *      finding type with its own action path).
 *   4. Hand the batch to `Triage_Engine` for AI verdicts.
 *   5. Hand triaged verdicts to `Alert_Router`.
 *   6. Stamp `completed_at`, `findings_count`, and triage spend on the
 *      `scans` row. Update `last_scan_ts` and `last_triage_ok` for the canary.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

use Amplifi\Security\Alerts\Alert_Router;
use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Self_Defense\Self_Integrity;
use Amplifi\Security\Triage\Triage_Engine;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scan_Runner {

	public const HOOK = 'amplifi_security_run_scan';

	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'register_intervals' ] );
		add_action( self::HOOK,        [ self::class, 'run' ] );
	}

	public static function register_intervals( array $schedules ): array {
		$schedules['amplifi_security_two_hours']    = [ 'interval' => 2 * HOUR_IN_SECONDS,  'display' => __( 'Every 2 hours', 'amplifi-security' ) ];
		$schedules['amplifi_security_four_hours']   = [ 'interval' => 4 * HOUR_IN_SECONDS,  'display' => __( 'Every 4 hours', 'amplifi-security' ) ];
		$schedules['amplifi_security_eight_hours']  = [ 'interval' => 8 * HOUR_IN_SECONDS,  'display' => __( 'Every 8 hours', 'amplifi-security' ) ];
		$schedules['amplifi_security_twelve_hours'] = [ 'interval' => 12 * HOUR_IN_SECONDS, 'display' => __( 'Every 12 hours', 'amplifi-security' ) ];
		return $schedules;
	}

	/**
	 * @return array{scan_id:int,findings:int,verdicts:int}
	 */
	public static function run(): array {
		global $wpdb;
		$scans_table   = $wpdb->prefix . 'amplifi_security_scans';
		$findings_tbl  = $wpdb->prefix . 'amplifi_security_findings';

		$wpdb->insert(
			$scans_table,
			[ 'started_at' => current_time( 'mysql', true ), 'findings_count' => 0 ]
		);
		$scan_id = (int) $wpdb->insert_id;

		Audit_Logger::log( 'scan_started', [ 'scan_id' => $scan_id ] );

		$scanners      = self::collect_scanners();
		$ran           = [];
		$total         = 0;

		foreach ( $scanners as $scanner ) {
			if ( ! $scanner->enabled() ) {
				continue;
			}
			$name  = $scanner->name();
			$start = microtime( true );

			try {
				$findings = $scanner->run( $scan_id );
			} catch ( Throwable $e ) {
				Audit_Logger::log(
					'scanner_error',
					[
						'scanner' => $name,
						'message' => $e->getMessage(),
					]
				);
				continue;
			}

			$persisted = self::persist_findings( $findings_tbl, $scan_id, $findings );
			$total    += $persisted;
			$ran[]     = [
				'name'     => $name,
				'count'    => $persisted,
				'duration' => round( microtime( true ) - $start, 3 ),
			];
		}

		// Self-integrity check is run regardless of scanner config.
		$integrity = Self_Integrity::verify();
		if ( ! $integrity['ok'] ) {
			$persisted = self::persist_findings(
				$findings_tbl,
				$scan_id,
				[
					[
						'type'     => 'self_integrity',
						'subtype'  => 'plugin_files_modified',
						'evidence' => [
							'changed' => $integrity['changed'],
							'added'   => $integrity['added'],
							'removed' => $integrity['removed'],
						],
					],
				]
			);
			$total += $persisted;
			$ran[]  = [ 'name' => 'self_integrity', 'count' => $persisted, 'duration' => 0 ];
		}

		// Hand off to triage.
		$triage_ok = true;
		try {
			Triage_Engine::triage_pending( $scan_id );
		} catch ( Throwable $e ) {
			$triage_ok = false;
			Audit_Logger::log(
				'triage_error',
				[ 'scan_id' => $scan_id, 'message' => $e->getMessage() ]
			);
		}

		// Dispatch alerts based on verdicts.
		try {
			Alert_Router::route_findings_for_scan( $scan_id );
		} catch ( Throwable $e ) {
			Audit_Logger::log(
				'alert_dispatch_error',
				[ 'scan_id' => $scan_id, 'message' => $e->getMessage() ]
			);
		}

		// Close the scan row.
		$wpdb->update(
			$scans_table,
			[
				'completed_at'   => current_time( 'mysql', true ),
				'findings_count' => $total,
				'scanners_run'   => wp_json_encode( $ran ),
			],
			[ 'id' => $scan_id ]
		);

		update_option( 'amplifi_security_last_scan_ts', time(), false );
		update_option( 'amplifi_security_last_triage_ok', $triage_ok ? 1 : 0, false );

		Audit_Logger::log(
			'scan_completed',
			[
				'scan_id'        => $scan_id,
				'findings_count' => $total,
				'scanners_run'   => $ran,
				'integrity_ok'   => $integrity['ok'],
			]
		);

		return [
			'scan_id'  => $scan_id,
			'findings' => $total,
			'verdicts' => 0,
		];
	}

	/**
	 * @return list<Scanner>
	 */
	public static function collect_scanners(): array {
		$scanners = [
			new Shell_Scanner(),
			new Integrity_Scanner(),
			new Critical_File_Scanner(),
			new Db_Anomaly_Scanner(),
			new Auth_Scanner(),
			new Vuln_Scanner(),
			new Cron_Scanner(),
			new Rest_Xmlrpc_Scanner(),
			new Hardening_Scanner(),
		];

		/**
		 * Filter to add or remove scanners.
		 *
		 * @param Scanner[] $scanners
		 */
		return apply_filters( 'amplifi_security_scanners', $scanners );
	}

	private static function persist_findings( string $table, int $scan_id, array $findings ): int {
		global $wpdb;
		$count = 0;
		$now   = current_time( 'mysql', true );
		foreach ( $findings as $f ) {
			$row = [
				'scan_id'    => $scan_id,
				'type'       => (string) ( $f['type'] ?? 'unknown' ),
				'subtype'    => isset( $f['subtype'] ) ? (string) $f['subtype'] : null,
				'evidence'   => wp_json_encode( $f['evidence'] ?? $f ),
				'status'     => 'pending_triage',
				'created_at' => $now,
			];
			$inserted = $wpdb->insert( $table, $row );
			if ( false !== $inserted ) {
				$count++;
			}
		}
		return $count;
	}
}
