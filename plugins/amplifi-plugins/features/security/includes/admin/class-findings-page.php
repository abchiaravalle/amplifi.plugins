<?php
/**
 * Findings list page.
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Findings_Page {

	public static function register(): void {
		add_action( 'admin_post_amplifi_security_findings_action', [ self::class, 'handle_action' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'amplifi_security_findings';
		$verdict = isset( $_GET['verdict'] ) ? sanitize_key( wp_unslash( (string) $_GET['verdict'] ) ) : '';
		$category= isset( $_GET['category'] ) ? sanitize_key( wp_unslash( (string) $_GET['category'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per     = 30;
		$offset  = ( $page - 1 ) * $per;

		$where  = [ '1=1' ];
		$values = [];
		if ( $verdict !== '' ) {
			$where[] = 'verdict = %s';
			$values[] = $verdict;
		}
		if ( $category !== '' ) {
			$where[] = 'category = %s';
			$values[] = $category;
		}
		if ( $status !== '' ) {
			$where[] = 'status = %s';
			$values[] = $status;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results(
			$wpdb->prepare( $list_sql, ...array_merge( $values, [ $per, $offset ] ) ),
			ARRAY_A
		);
		// phpcs:enable

		echo '<div class="wrap amplifi-security">';
		echo '<h1>' . esc_html__( 'Findings', 'amplifi-security' ) . '</h1>';
		self::render_filters( $verdict, $category, $status );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'ID', 'When', 'Type', 'Category', 'Verdict', 'Confidence', 'Status', 'Rationale', 'Action' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No findings yet.', 'amplifi-security' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$mark_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=amplifi_security_findings_action&op=mark_fp&id=' . (int) $r['id'] ),
				'amplifi_security_findings_action'
			);
			$dismiss_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=amplifi_security_findings_action&op=dismiss&id=' . (int) $r['id'] ),
				'amplifi_security_findings_action'
			);
			echo '<tr>';
			echo '<td>#' . (int) $r['id'] . '</td>';
			echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['type'] ) . ( $r['subtype'] ? ' / ' . esc_html( (string) $r['subtype'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['category'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['verdict'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['confidence'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $r['status'] ) . '</td>';
			echo '<td><div style="max-width:480px">' . esc_html( (string) ( $r['rationale'] ?? '' ) ) . '</div></td>';
			echo '<td>
				<a href="' . esc_url( $mark_url ) . '">' . esc_html__( 'Mark FP', 'amplifi-security' ) . '</a><br>
				<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'amplifi-security' ) . '</a>
			</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		self::render_pagination( $page, $per, $total );
		echo '</div>';
	}

	private static function render_filters( string $verdict, string $category, string $status ): void {
		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="amplifi-security-findings"/>';

		echo '<label>' . esc_html__( 'Verdict', 'amplifi-security' ) . ' ';
		echo '<select name="verdict"><option value="">' . esc_html__( 'all', 'amplifi-security' ) . '</option>';
		foreach ( [ 'confirmed', 'likely', 'worth_reviewing', 'benign' ] as $v ) {
			$sel = $verdict === $v ? ' selected' : '';
			echo '<option value="' . esc_attr( $v ) . '"' . $sel . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( 'Category', 'amplifi-security' ) . ' ';
		echo '<select name="category"><option value="">' . esc_html__( 'all', 'amplifi-security' ) . '</option>';
		foreach ( [ 'malware', 'core_tampering', 'plugin_theme_tampering', 'privilege_escalation', 'content_injection', 'auth_anomaly', 'vulnerability', 'cron_anomaly', 'config_change', 'other' ] as $c ) {
			$sel = $category === $c ? ' selected' : '';
			echo '<option value="' . esc_attr( $c ) . '"' . $sel . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( 'Status', 'amplifi-security' ) . ' ';
		echo '<select name="status"><option value="">' . esc_html__( 'all', 'amplifi-security' ) . '</option>';
		foreach ( [ 'pending_triage', 'triaged', 'dismissed', 'quarantined' ] as $s ) {
			$sel = $status === $s ? ' selected' : '';
			echo '<option value="' . esc_attr( $s ) . '"' . $sel . '>' . esc_html( $s ) . '</option>';
		}
		echo '</select></label> ';

		submit_button( __( 'Filter', 'amplifi-security' ), 'secondary', '', false );
		echo '</form>';
	}

	private static function render_pagination( int $page, int $per, int $total ): void {
		$pages = max( 1, (int) ceil( $total / $per ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $p = 1; $p <= $pages; $p++ ) {
			$url = add_query_arg( 'paged', $p );
			$cls = $p === $page ? ' class="current"' : '';
			echo '<a href="' . esc_url( $url ) . '"' . $cls . '>' . (int) $p . '</a> ';
		}
		echo '</div></div>';
	}

	public static function handle_action(): void {
		check_admin_referer( 'amplifi_security_findings_action' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		$op = sanitize_key( wp_unslash( (string) ( $_GET['op'] ?? '' ) ) );
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=amplifi-security-findings' ) );
			exit;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';

		switch ( $op ) {
			case 'mark_fp':
				$wpdb->update(
					$table,
					[ 'verdict' => 'benign', 'user_marked_fp' => 1, 'status' => 'dismissed' ],
					[ 'id' => $id ]
				);
				Audit_Logger::log( 'finding_marked_fp', [ 'finding_id' => $id ] );
				break;
			case 'dismiss':
				$wpdb->update( $table, [ 'status' => 'dismissed' ], [ 'id' => $id ] );
				Audit_Logger::log( 'finding_dismissed', [ 'finding_id' => $id ] );
				break;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=amplifi-security-findings' ) );
		exit;
	}
}
