<?php
/**
 * Health dashboard — landing page for the menu.
 *
 * Reports:
 *   - last successful scan + summary
 *   - last triage call + spend (today/MTD/projected)
 *   - Anthropic API status (last response code, latency)
 *   - findings volume trend (30-day count by verdict)
 *   - top 5 categories this month
 *   - red/yellow/green overall status pill
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Audit\Audit_Chain;
use Amplifi\Security\Canary\Canary;
use Amplifi\Security\Self_Defense\Self_Integrity;
use Amplifi\Security\Triage\Spend_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health_Page {

	public static function register(): void {}

	public static function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}

		$last_scan_ts  = (int) get_option( 'amplifi_security_last_scan_ts', 0 );
		$summary       = (string) get_option( 'amplifi_security_last_scan_summary', '' );
		$triage_ok     = (bool) get_option( 'amplifi_security_last_triage_ok', true );
		$self_ok       = Self_Integrity::is_ok();
		$chain         = Audit_Chain::verify( 500 );
		$spend         = Spend_Tracker::summary();

		$overall = ( $triage_ok && $self_ok && $chain['verified'] ) ? 'green' : ( $self_ok ? 'yellow' : 'red' );
		$pill = match ( $overall ) {
			'green'  => '<span style="background:#2c7a2c;color:#fff;padding:4px 10px;border-radius:12px">All systems normal</span>',
			'yellow' => '<span style="background:#c79e00;color:#fff;padding:4px 10px;border-radius:12px">Attention needed</span>',
			'red'    => '<span style="background:#a00;color:#fff;padding:4px 10px;border-radius:12px">Action required</span>',
		};

		echo '<div class="wrap amplifi-security">';
		echo '<h1>' . esc_html__( 'amplifi.security — Health', 'amplifi-security' ) . '</h1>';
		echo '<p style="font-size:16px">' . $pill . '</p>';

		echo '<table class="form-table"><tbody>';
		self::row( __( 'Last scan', 'amplifi-security' ), $last_scan_ts ? gmdate( 'Y-m-d H:i:s', $last_scan_ts ) . ' UTC' : esc_html__( 'never', 'amplifi-security' ) );
		self::row( __( 'Last scan summary', 'amplifi-security' ), $summary ?: '—' );
		self::row( __( 'Last triage OK', 'amplifi-security' ), $triage_ok ? '✓' : '✗' );
		self::row( __( 'Plugin self-integrity', 'amplifi-security' ), $self_ok ? '✓' : '✗' );
		self::row( __( 'Audit chain', 'amplifi-security' ), $chain['verified'] ? '✓ verified' : '⚠ ' . count( $chain['broken_at'] ) . ' break(s)' );
		self::row( __( 'Spend today', 'amplifi-security' ), '$' . number_format( $spend['today'], 4 ) . ' / cap $' . number_format( $spend['daily_cap'], 2 ) );
		self::row( __( 'Spend this month', 'amplifi-security' ), '$' . number_format( $spend['month'], 4 ) . ' / cap $' . number_format( $spend['monthly_cap'], 2 ) . ' (projected: $' . number_format( $spend['projected_month_end'], 2 ) . ')' );
		self::row( __( 'Canary URL', 'amplifi-security' ), Canary::url() ? '<code>' . esc_html( Canary::url() ) . '</code>' : '—' );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Findings — last 30 days', 'amplifi-security' ) . '</h2>';
		self::render_volume_table();

		echo '<h2>' . esc_html__( 'Top categories this month', 'amplifi-security' ) . '</h2>';
		self::render_top_categories();

		echo '</div>';
	}

	private static function row( string $label, string $value_html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>';
	}

	private static function render_volume_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT verdict, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND verdict IS NOT NULL GROUP BY verdict",
				$cutoff
			),
			ARRAY_A
		);
		$counts = [ 'confirmed' => 0, 'likely' => 0, 'worth_reviewing' => 0, 'benign' => 0 ];
		foreach ( (array) $rows as $r ) {
			$counts[ (string) $r['verdict'] ] = (int) $r['n'];
		}
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>Verdict</th><th>30d count</th></tr></thead><tbody>';
		foreach ( $counts as $v => $n ) {
			echo '<tr><td>' . esc_html( $v ) . '</td><td>' . (int) $n . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_top_categories(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		$start = gmdate( 'Y-m-01' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category, COUNT(*) AS n FROM {$table}
				 WHERE created_at >= %s AND category IS NOT NULL
				 GROUP BY category ORDER BY n DESC LIMIT 5",
				$start
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			echo '<p>—</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>Category</th><th>This month</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['category'] ) . '</td><td>' . (int) $r['n'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
