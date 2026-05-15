<?php
/**
 * Cron worker: drains the queue and triggers the daily report.
 *
 * Each tick is budgeted (default 25 seconds) so we never run up against a
 * managed host's PHP execution-time limit (often 30s) and leave rows stuck
 * in `processing` state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Cron {

	const LAST_DRAIN_OPTION = 'acalt_last_drain_at';
	const TICK_BUDGET_SECONDS = 25;

	public static function register() {
		add_action( 'acalt_cron_drain', array( __CLASS__, 'drain' ) );
		add_action( 'acalt_daily_report', array( 'ACALT_Report', 'send' ) );
	}

	/**
	 * Drain a batch of jobs. Stops early on: empty queue, daily cap, auth
	 * fail, rate limit, or tick budget exhausted.
	 *
	 * @param int $max_jobs  Max jobs to process in this tick.
	 * @param int $budget    Seconds to spend before exiting.
	 * @return int Number of jobs processed (any terminal state) in this tick.
	 */
	public static function drain( $max_jobs = 10, $budget = self::TICK_BUDGET_SECONDS ) {
		update_option( self::LAST_DRAIN_OPTION, time(), false );

		if ( ACALT_Generator::is_paused() ) {
			return 0;
		}

		// Recover any stuck `processing` rows from a tick that died.
		ACALT_Queue::reset_stale( 300 );

		$jobs = ACALT_Queue::claim_batch( $max_jobs );
		if ( empty( $jobs ) ) {
			return 0;
		}

		$start_ts  = microtime( true );
		$processed = 0;

		foreach ( $jobs as $job ) {
			// Per-tick budget. If we're past budget, park unprocessed claimed jobs.
			if ( ( microtime( true ) - $start_ts ) > $budget ) {
				ACALT_Queue::park( (int) $job->id, 'tick budget exhausted' );
				continue;
			}

			$attempts = (int) $job->attempts + 1;
			$result   = ACALT_Generator::generate( $job );

			if ( ! empty( $result['ok'] ) ) {
				ACALT_Queue::mark_done(
					(int) $job->id,
					(string) $result['alt'],
					(int) $result['tokens_in'],
					(int) $result['tokens_out'],
					(float) $result['cost']
				);
				$processed++;
				continue;
			}

			if ( ! empty( $result['skip'] ) ) {
				ACALT_Queue::mark_skipped( (int) $job->id, (string) $result['reason'] );
				ACALT_Generator::record_usage( 0, 0, 0, 'skipped' );
				$processed++;
				continue;
			}

			if ( ! empty( $result['park'] ) ) {
				ACALT_Queue::park( (int) $job->id, (string) $result['reason'] );
				// Auth fail or rate limit — stop the rest of this batch.
				if ( ! empty( $result['rate_limit'] ) || ACALT_Generator::is_paused() ) {
					// Park remaining claimed rows too so they don't sit in `processing`.
					self::park_remaining_claimed( $jobs, $job, $result['reason'] );
					break;
				}
				continue;
			}

			// Real failure.
			ACALT_Queue::mark_retry( (int) $job->id, $attempts, (string) $result['reason'], 3 );
			if ( $attempts >= 3 ) {
				ACALT_Generator::record_usage( 0, 0, 0, 'failed' );
			}
			$processed++;
		}

		return $processed;
	}

	private static function park_remaining_claimed( $all_jobs, $current_job, $reason ) {
		$found = false;
		foreach ( $all_jobs as $j ) {
			if ( ! $found ) {
				if ( (int) $j->id === (int) $current_job->id ) {
					$found = true;
				}
				continue;
			}
			ACALT_Queue::park( (int) $j->id, $reason );
		}
	}

	public static function last_drain_at() {
		return (int) get_option( self::LAST_DRAIN_OPTION, 0 );
	}
}
