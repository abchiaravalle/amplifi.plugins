<?php
/**
 * Cron worker: drains the queue and triggers the daily report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Cron {

	public static function register() {
		add_action( 'acalt_cron_drain', array( __CLASS__, 'drain' ) );
		add_action( 'acalt_daily_report', array( 'ACALT_Report', 'send' ) );
	}

	public static function drain() {
		// Recover any stuck `processing` rows from a tick that died.
		ACALT_Queue::reset_stale( 300 );

		$jobs = ACALT_Queue::claim_batch( 10 );
		if ( empty( $jobs ) ) {
			return;
		}

		foreach ( $jobs as $job ) {
			$attempts = (int) $job->attempts + 1;

			$result = ACALT_Generator::generate( $job );

			if ( ! empty( $result['ok'] ) ) {
				ACALT_Queue::mark_done(
					(int) $job->id,
					(string) $result['alt'],
					(int) $result['tokens_in'],
					(int) $result['tokens_out'],
					(float) $result['cost']
				);
				continue;
			}

			if ( ! empty( $result['skip'] ) ) {
				ACALT_Queue::mark_skipped( (int) $job->id, (string) $result['reason'] );
				ACALT_Generator::record_usage( 0, 0, 0, 'skipped' );
				continue;
			}

			if ( ! empty( $result['park'] ) ) {
				// Daily cap reached — stop processing the rest of this batch
				// to avoid spinning. Park this job and exit the tick.
				ACALT_Queue::park( (int) $job->id, (string) $result['reason'] );
				break;
			}

			// Real failure.
			ACALT_Queue::mark_retry( (int) $job->id, $attempts, (string) $result['reason'], 3 );
			if ( $attempts >= 3 ) {
				ACALT_Generator::record_usage( 0, 0, 0, 'failed' );
			}
		}
	}
}
