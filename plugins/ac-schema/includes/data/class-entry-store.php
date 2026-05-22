<?php
declare(strict_types=1);
namespace Amplifi\Schema\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed wpdb helpers for the ac_schema_entries table.
 *
 * Unique key: (scope_type, scope_id, schema_type).
 * save() upserts so callers don't need to differentiate create vs update.
 */
final class Entry_Store {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ac_schema_entries';
	}

	/**
	 * Upsert a schema entry row.
	 *
	 * @param array $row Keys: scope_type, scope_id, schema_type, source, json_ld.
	 * @return int The row id (inserted or existing).
	 */
	public function save( array $row ): int {
		global $wpdb;

		$json = (string) $row['json_ld'];
		$hash = hash( 'sha256', $json );
		$now  = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table} (scope_type, scope_id, schema_type, source, json_ld, hash, updated_at)
			 VALUES (%s,%s,%s,%s,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE source=VALUES(source), json_ld=VALUES(json_ld), hash=VALUES(hash), updated_at=VALUES(updated_at)",
			(string) $row['scope_type'],
			(string) $row['scope_id'],
			(string) $row['schema_type'],
			(string) $row['source'],
			$json,
			$hash,
			$now
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			return $id;
		}

		// ON DUPLICATE KEY UPDATE returns insert_id = 0 on MySQL 5.7 when no PK change.
		// Fall back to a SELECT to retrieve the existing row id.
		return (int) ( $this->find_id(
			(string) $row['scope_type'],
			(string) $row['scope_id'],
			(string) $row['schema_type']
		) ?? 0 );
	}

	/**
	 * Return just the id for a given unique key, or null if not found.
	 */
	public function find_id( string $scope_type, string $scope_id, string $schema_type ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE scope_type=%s AND scope_id=%s AND schema_type=%s",
			$scope_type,
			$scope_id,
			$schema_type
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $id ? (int) $id : null;
	}

	/**
	 * Return the full row for a given unique key, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_one( string $scope_type, string $scope_id, string $schema_type ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE scope_type=%s AND scope_id=%s AND schema_type=%s",
			$scope_type,
			$scope_id,
			$schema_type
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ?: null;
	}

	/**
	 * Return all rows for a given scope, ordered by schema_type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function find_all_for_scope( string $scope_type, string $scope_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE scope_type=%s AND scope_id=%s ORDER BY schema_type",
			$scope_type,
			$scope_id
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $rows ?: [];
	}

	/**
	 * Return the full row for a given primary key id, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d",
			$id
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ?: null;
	}

	/**
	 * Delete a row by primary key id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
