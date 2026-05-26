<?php
/**
 * Audit log page — searchable view + chain-integrity status + export.
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Audit\Audit_Chain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Page {

	public static function register(): void {
		add_action( 'admin_post_amplifi_security_audit_export', [ self::class, 'handle_export' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_audit';

		$event = isset( $_GET['event'] )  ? sanitize_key( wp_unslash( (string) $_GET['event'] ) )  : '';
		$user  = isset( $_GET['user'] )   ? (int) $_GET['user'] : 0;
		$page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per   = 50;
		$offset = ( $page - 1 ) * $per;

		$where  = [ '1=1' ];
		$values = [];
		if ( $event !== '' ) {
			$where[] = 'event_type = %s';
			$values[] = $event;
		}
		if ( $user > 0 ) {
			$where[] = 'actor_user_id = %d';
			$values[] = $user;
		}
		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				...array_merge( $values, [ $per, $offset ] )
			),
			ARRAY_A
		);
		// phpcs:enable

		$verify  = Audit_Chain::verify( 1000 );
		$status  = $verify['verified']
			? '<span style="color:#2c7a2c">✓ verified</span>'
			: '<span style="color:#a00">⚠ ' . count( $verify['broken_at'] ) . ' chain break(s)</span>';

		echo '<div class="wrap amplifi-security">';
		echo '<h1>' . esc_html__( 'Audit Log', 'amplifi-security' ) . '</h1>';
		echo '<p>' . esc_html__( 'Hash chain status:', 'amplifi-security' ) . ' ' . $status . '</p>';

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=amplifi_security_audit_export' ),
			'amplifi_security_audit_export'
		);
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'amplifi-security' ) . '</a></p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="amplifi-security-audit"/>';
		echo '<label>' . esc_html__( 'Event:', 'amplifi-security' ) . ' <input type="text" name="event" value="' . esc_attr( $event ) . '"></label> ';
		echo '<label>' . esc_html__( 'User ID:', 'amplifi-security' ) . ' <input type="number" name="user" value="' . esc_attr( (string) ( $user ?: '' ) ) . '" style="width:80px"></label> ';
		submit_button( __( 'Filter', 'amplifi-security' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ 'ID', 'When (UTC)', 'Event', 'Actor', 'IP', 'Target', 'Data' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$actor_user_id = (int) ( $r['actor_user_id'] ?? 0 );
			$user_label    = '—';
			if ( $actor_user_id > 0 ) {
				$u = get_userdata( $actor_user_id );
				$user_label = $u ? $u->user_login . ' (#' . $actor_user_id . ')' : '#' . $actor_user_id;
			}
			echo '<tr>';
			echo '<td>' . (int) $r['id'] . '</td>';
			echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['event_type'] ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['actor_ip'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['target_type'] ?? '' ) ) . ':' . esc_html( (string) ( $r['target_id'] ?? '' ) ) . '</td>';
			echo '<td><pre style="max-width:480px;white-space:pre-wrap">' . esc_html( (string) $r['event_data'] ) . '</pre></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	public static function handle_export(): void {
		check_admin_referer( 'amplifi_security_audit_export' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_audit';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT id, created_at, event_type, actor_user_id, actor_ip, target_type, target_id, event_data, prev_hash, row_hash FROM {$table} ORDER BY id ASC",
			ARRAY_A
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="amplifi-security-audit-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'id', 'created_at_utc', 'event_type', 'actor_user_id', 'actor_ip', 'target_type', 'target_id', 'event_data', 'prev_hash', 'row_hash' ] );
		foreach ( $rows as $r ) {
			fputcsv( $out, [
				$r['id'], $r['created_at'], $r['event_type'], $r['actor_user_id'],
				$r['actor_ip'], $r['target_type'], $r['target_id'],
				$r['event_data'], $r['prev_hash'], $r['row_hash'],
			] );
		}
		fclose( $out );
		exit;
	}
}
