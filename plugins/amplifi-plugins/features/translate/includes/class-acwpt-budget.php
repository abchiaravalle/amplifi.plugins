<?php
/**
 * Spend ceiling and billing-state guard.
 *
 * Two problems this solves, both found in the production-readiness review.
 *
 * 1. NO CIRCUIT BREAKER. Queue depth was bounded; dollars were not. A crawler
 *    walking every /{lang}/ URL, a theme update that rewrites markup sitewide,
 *    or an accidental flush all converted directly into unbounded API spend.
 *    The only defence was that queues drain per-tick, which throttles the RATE
 *    of spend, not its total. The first runaway would have been discovered on
 *    an invoice.
 *
 * 2. DESTROYING A CACHE YOU CANNOT REBUILD. Flushing translations is only safe
 *    if the API can actually re-translate. If billing is exhausted or the key
 *    is invalid, a flush turns a working multilingual site into an English one
 *    with no way back until someone tops up. Every destructive path now checks
 *    first.
 *
 * @package amplifi-translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Budget {

	/** Default monthly ceiling in USD, for every site unless overridden. */
	const DEFAULT_MONTHLY_LIMIT = 100.0;

	const OPTION_SPEND = 'acwpt_monthly_spend';
	const OPTION_STATE = 'acwpt_billing_state';

	/**
	 * Configured monthly limit.
	 *
	 * Order of precedence: a wp-config constant (so it cannot be raised from
	 * the admin UI on a client site), then the stored setting, then the
	 * default. A limit of 0 means unlimited and must be set deliberately.
	 */
	public static function monthly_limit() {
		if ( defined( 'ACWPT_MONTHLY_LIMIT' ) ) {
			return (float) ACWPT_MONTHLY_LIMIT;
		}
		$settings = get_option( 'acwpt_settings', array() );
		if ( isset( $settings['monthly_limit'] ) && '' !== $settings['monthly_limit'] ) {
			return (float) $settings['monthly_limit'];
		}
		return (float) apply_filters( 'acwpt_default_monthly_limit', self::DEFAULT_MONTHLY_LIMIT );
	}

	/** Current calendar month key, e.g. 2026-09. */
	private static function period() {
		return gmdate( 'Y-m' );
	}

	/**
	 * Spend so far this month.
	 */
	public static function spent_this_month() {
		$all = (array) get_option( self::OPTION_SPEND, array() );
		$p   = self::period();
		return isset( $all[ $p ] ) ? (float) $all[ $p ] : 0.0;
	}

	/**
	 * Record spend against the current month.
	 *
	 * Keeps twelve months of history so the admin can show a trend, and so a
	 * sudden jump is visible rather than being averaged away.
	 */
	public static function record( $cost ) {
		$cost = (float) $cost;
		if ( $cost <= 0 ) {
			return;
		}
		$all = (array) get_option( self::OPTION_SPEND, array() );
		$p   = self::period();

		$all[ $p ] = ( isset( $all[ $p ] ) ? (float) $all[ $p ] : 0.0 ) + $cost;

		if ( count( $all ) > 12 ) {
			krsort( $all );
			$all = array_slice( $all, 0, 12, true );
		}
		update_option( self::OPTION_SPEND, $all, false );
	}

	/**
	 * Is there budget left to make a call?
	 *
	 * @return true|WP_Error
	 */
	public static function check() {
		$limit = self::monthly_limit();
		if ( $limit <= 0 ) {
			return true; // Explicitly unlimited.
		}

		$spent = self::spent_this_month();
		if ( $spent < $limit ) {
			return true;
		}

		return new WP_Error(
			'acwpt_budget_exceeded',
			sprintf(
				'Monthly translation budget reached: $%s of $%s spent in %s. Translation is paused; cached translations continue to serve. Raise the limit in amplifi.studio → Translate, or wait for the next billing month.',
				number_format( $spent, 2 ),
				number_format( $limit, 2 ),
				self::period()
			)
		);
	}

	/**
	 * Remember why the API last refused us, so destructive actions can check.
	 *
	 * Billing failures are the ones that matter: an exhausted balance means a
	 * flush cannot be undone.
	 */
	public static function note_api_error( $message ) {
		$message = (string) $message;
		$billing = ( false !== stripos( $message, 'credit balance' ) )
			|| ( false !== stripos( $message, 'billing' ) )
			|| ( false !== stripos( $message, 'quota' ) )
			|| ( false !== stripos( $message, 'insufficient' ) );

		update_option(
			self::OPTION_STATE,
			array(
				'ok'      => ! $billing,
				'message' => $message,
				'at'      => time(),
			),
			false
		);
	}

	/** Clear the failure state after a successful call. */
	public static function note_api_ok() {
		$state = (array) get_option( self::OPTION_STATE, array() );
		if ( empty( $state ) || ! empty( $state['ok'] ) ) {
			return;
		}
		update_option( self::OPTION_STATE, array( 'ok' => true, 'at' => time() ), false );
	}

	/**
	 * Is it safe to DESTROY cached translations right now?
	 *
	 * Called before any flush. Refuses when the budget is spent or the last
	 * API error was a billing failure, because in either case the cache cannot
	 * be rebuilt and the site would silently fall back to English.
	 *
	 * @return true|WP_Error
	 */
	public static function can_afford_rebuild() {
		$budget = self::check();
		if ( is_wp_error( $budget ) ) {
			return new WP_Error(
				'acwpt_flush_blocked',
				'Refusing to delete cached translations: ' . $budget->get_error_message()
			);
		}

		$state = (array) get_option( self::OPTION_STATE, array() );
		if ( isset( $state['ok'] ) && ! $state['ok'] ) {
			return new WP_Error(
				'acwpt_flush_blocked',
				'Refusing to delete cached translations: the last API call failed with a billing error (' .
					esc_html( (string) ( $state['message'] ?? 'unknown' ) ) .
					'). Deleting now would leave the site in English with no way to rebuild.'
			);
		}

		return true;
	}

	/**
	 * Admin notice when translation is paused.
	 */
	public static function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$budget = self::check();
		if ( ! is_wp_error( $budget ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>amplifi.translate:</strong> %s</p></div>',
			esc_html( $budget->get_error_message() )
		);
	}
}
