<?php
declare(strict_types=1);
namespace Amplifi\Schema\Queue;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Job_Store {
	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ac_schema_bulk_jobs';
	}

	public function create( array $scope, string $model, int $total ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table} (status, scope, total, processed, failed, model, started_at, finished_at, cost_usd)
			 VALUES (%s, %s, %d, 0, 0, %s, NULL, NULL, 0)",
			self::STATUS_QUEUED,
			(string) wp_json_encode( $scope ),
			$total,
			$model
		) ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public function find_active(): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 1",
			ARRAY_A
		); // phpcs:ignore
		return $row ?: null;
	}

	public function list_recent( int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		); // phpcs:ignore
		return $rows ?: [];
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $status === self::STATUS_RUNNING ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table} SET status=%s, started_at=COALESCE(started_at, %s) WHERE id=%d",
				$status,
				$now,
				$id
			) ); // phpcs:ignore
			return;
		}
		if ( in_array( $status, [ self::STATUS_COMPLETED, self::STATUS_FAILED ], true ) ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table} SET status=%s, finished_at=%s WHERE id=%d",
				$status,
				$now,
				$id
			) ); // phpcs:ignore
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table} SET status=%s WHERE id=%d",
			$status,
			$id
		) ); // phpcs:ignore
	}

	public function record_progress( int $id, int $delta_processed, int $delta_failed, float $delta_cost ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table}
			    SET processed = processed + %d,
			        failed    = failed + %d,
			        cost_usd  = cost_usd + %f
			  WHERE id = %d",
			$delta_processed,
			$delta_failed,
			$delta_cost,
			$id
		) ); // phpcs:ignore
	}
}
