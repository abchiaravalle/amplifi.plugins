<?php
/**
 * Database wrapper for the suggestions table.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All reads and writes against `{prefix}_amplifi_optimize_suggestions`.
 *
 * Centralised here so SQL prepare and JSON serialisation lives in one place.
 */
class Amplifi_Optimize_Database {

	const DB_VERSION = '1.0.0';

	/**
	 * Returns the fully-qualified table name.
	 */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'amplifi_optimize_suggestions';
	}

	/**
	 * Creates/updates the table using dbDelta.
	 */
	public function install(): void {
		global $wpdb;
		$table   = $this->table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			fix_type VARCHAR(50) NOT NULL,
			target_type VARCHAR(20) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			current_value LONGTEXT NULL,
			proposed_value LONGTEXT NULL,
			proposed_metadata LONGTEXT NULL,
			claude_response_raw LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			applied_at DATETIME NULL,
			previous_snapshot LONGTEXT NULL,
			error_message TEXT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			KEY idx_status_type (status, fix_type),
			KEY idx_target (target_type, target_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'amplifi_optimize_db_version', self::DB_VERSION );
	}

	/**
	 * Inserts a pending suggestion. Returns insert id or 0.
	 *
	 * @param array<string,mixed> $data Row data.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$row = array(
			'fix_type'          => (string) ( $data['fix_type'] ?? '' ),
			'target_type'       => (string) ( $data['target_type'] ?? 'post' ),
			'target_id'         => (int) ( $data['target_id'] ?? 0 ),
			'current_value'     => isset( $data['current_value'] ) ? (string) $data['current_value'] : null,
			'proposed_value'    => isset( $data['proposed_value'] ) ? (string) $data['proposed_value'] : null,
			'proposed_metadata' => isset( $data['proposed_metadata'] ) ? wp_json_encode( $data['proposed_metadata'] ) : null,
			'claude_response_raw' => isset( $data['claude_response_raw'] ) ? (string) $data['claude_response_raw'] : null,
			'status'            => (string) ( $data['status'] ?? 'pending' ),
			'error_message'     => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
		);

		$ok = $wpdb->insert( $this->table_name(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a row by id.
	 *
	 * @param int                 $id   Suggestion id.
	 * @param array<string,mixed> $data Fields to update.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( isset( $data['proposed_metadata'] ) && is_array( $data['proposed_metadata'] ) ) {
			$data['proposed_metadata'] = wp_json_encode( $data['proposed_metadata'] );
		}

		return false !== $wpdb->update( $this->table_name(), $data, array( 'id' => $id ) );
	}

	/**
	 * Fetches a single suggestion as decoded array.
	 *
	 * @param int $id Suggestion id.
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amplifi_optimize_suggestions WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * Lists suggestions with simple filters.
	 *
	 * @param array{type?:string,status?:string,page?:int,per_page?:int} $args Filters.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args = array() ): array {
		global $wpdb;

		$type     = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		$status   = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();
		if ( $type ) {
			$where[]   = 'fix_type = %s';
			$params[] = $type;
		}
		if ( $status ) {
			$where[]   = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$table     = $this->table_name();

		// Count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		// Rows.
		$rows_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows_params   = array_merge( $params, array( $per_page, $offset ) );
		$rows          = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array_map( array( $this, 'decode_row' ), (array) $rows );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts rows by status for a fix type (or all).
	 *
	 * @param string $fix_type Optional fix type filter.
	 * @return array<string,int>
	 */
	public function counts_by_status( string $fix_type = '' ): array {
		global $wpdb;
		$table = $this->table_name();
		if ( $fix_type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE fix_type = %s GROUP BY status", $fix_type ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Counts rows grouped by fix_type for a given status.
	 *
	 * @param string $status Status to group on.
	 * @return array<string,int>
	 */
	public function counts_by_fix_type( string $status = 'pending' ): array {
		global $wpdb;
		$table = $this->table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT fix_type, COUNT(*) AS n FROM {$table} WHERE status = %s GROUP BY fix_type", $status ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['fix_type'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Returns true if a pending suggestion already exists for this fix_type+target.
	 *
	 * @param string $fix_type    Fix type.
	 * @param string $target_type Target type ('post' or 'attachment').
	 * @param int    $target_id   Target id.
	 */
	public function pending_exists( string $fix_type, string $target_type, int $target_id ): bool {
		global $wpdb;
		$table = $this->table_name();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE fix_type = %s AND target_type = %s AND target_id = %d AND status IN ('pending','approved')",
				$fix_type,
				$target_type,
				$target_id
			)
		);
		return $count > 0;
	}

	/**
	 * Decodes a raw row's JSON metadata into an array.
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	private function decode_row( array $row ): array {
		if ( ! empty( $row['proposed_metadata'] ) && is_string( $row['proposed_metadata'] ) ) {
			$decoded = json_decode( $row['proposed_metadata'], true );
			$row['proposed_metadata'] = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['proposed_metadata'] = array();
		}
		$row['id']        = (int) $row['id'];
		$row['target_id'] = (int) $row['target_id'];
		return $row;
	}

	/**
	 * Returns the last N applied suggestions for undo.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_applied( int $limit = 50 ): array {
		global $wpdb;
		$table = $this->table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'applied' ORDER BY applied_at DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return array_map( array( $this, 'decode_row' ), (array) $rows );
	}
}
