<?php
/**
 * Job queue for alt text generation.
 *
 * One row per attachment we want to process. Workers claim batches of
 * `pending` rows, transition them to `processing`, then `done` / `failed`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Queue {

	const TABLE = 'acalt_jobs';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			source VARCHAR(16) NOT NULL DEFAULT 'bulk',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			alt_generated TEXT NULL,
			tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_attachment (attachment_id),
			KEY idx_status (status),
			KEY idx_updated (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a job, ignoring duplicates (UNIQUE on attachment_id).
	 *
	 * @return bool true if a row was inserted, false otherwise.
	 */
	public static function enqueue( $attachment_id, $source = 'bulk' ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (attachment_id, status, source, created_at, updated_at)
				 VALUES (%d, 'pending', %s, %s, %s)",
				$attachment_id,
				$source,
				$now,
				$now
			)
		);

		return (bool) $result;
	}

	/**
	 * Reset stale `processing` rows back to `pending`. Called at the start of
	 * each worker tick to recover from a tick that died mid-run.
	 */
	public static function reset_stale( $threshold_seconds = 300 ) {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $threshold_seconds );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status='pending', updated_at=%s
				 WHERE status='processing' AND updated_at < %s",
				current_time( 'mysql', true ),
				$cutoff
			)
		);
	}

	/**
	 * Claim up to $limit pending jobs and return them. Uses a UUID-like marker
	 * in last_error to identify just-claimed rows since we can't return the
	 * affected IDs from UPDATE directly in MySQL.
	 *
	 * @return array Array of row objects.
	 */
	public static function claim_batch( $limit = 10 ) {
		global $wpdb;
		$table  = self::table_name();
		$marker = '__claim_' . wp_generate_password( 12, false );
		$now    = current_time( 'mysql', true );

		// Atomically claim a batch by tagging them with the marker.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status='processing', last_error=%s, updated_at=%s
				 WHERE status='pending'
				 ORDER BY id ASC
				 LIMIT %d",
				$marker,
				$now,
				$limit
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE last_error=%s ORDER BY id ASC",
				$marker
			)
		);

		// Clear the marker so it doesn't leak into the audit trail.
		if ( $rows ) {
			$wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET last_error=NULL WHERE last_error=%s", $marker )
			);
		}

		return $rows ?: array();
	}

	public static function mark_done( $job_id, $alt, $tokens_in, $tokens_out, $cost_usd ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'        => 'done',
				'alt_generated' => $alt,
				'tokens_in'     => $tokens_in,
				'tokens_out'    => $tokens_out,
				'cost_usd'      => $cost_usd,
				'last_error'    => null,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%d', '%d', '%f', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function mark_skipped( $job_id, $reason ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'     => 'skipped',
				'last_error' => $reason,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function mark_retry( $job_id, $attempts, $error, $max_attempts = 3 ) {
		global $wpdb;
		$status = ( $attempts >= $max_attempts ) ? 'failed' : 'pending';
		$wpdb->update(
			self::table_name(),
			array(
				'status'     => $status,
				'attempts'   => $attempts,
				'last_error' => $error,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Park a job (e.g. daily cap reached). Sets status back to pending so the
	 * next tick (or next day) picks it up, without consuming a retry attempt.
	 */
	public static function park( $job_id, $reason ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'     => 'pending',
				'last_error' => $reason,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function reset_for_retry( $job_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'     => 'pending',
				'attempts'   => 0,
				'last_error' => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function counts() {
		global $wpdb;
		$table  = self::table_name();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" );
		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
			'skipped'    => 0,
		);
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}
		return $counts;
	}

	public static function recent( $limit = 20, $status = null ) {
		global $wpdb;
		$table = self::table_name();
		if ( $status ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status=%s ORDER BY updated_at DESC LIMIT %d",
					$status,
					$limit
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d",
				$limit
			)
		);
	}

	public static function paged( $page = 1, $per_page = 25, $status = null ) {
		global $wpdb;
		$table  = self::table_name();
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * $per_page;

		if ( $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status=%s ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status=%s", $status ) );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function get( $job_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table_name() . " WHERE id=%d", $job_id )
		);
	}

	/**
	 * Enqueue every image attachment that has no alt text, in batches.
	 *
	 * @return int Number of newly enqueued jobs.
	 */
	public static function enqueue_missing_alt( $batch_size = 200, $max_batches = 50 ) {
		global $wpdb;
		$enqueued = 0;
		$offset   = 0;

		for ( $i = 0; $i < $max_batches; $i++ ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} m
					   ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
					 WHERE p.post_type = 'attachment'
					   AND p.post_mime_type LIKE 'image/%%'
					   AND (m.meta_value IS NULL OR m.meta_value = '')
					 ORDER BY p.ID ASC
					 LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				if ( self::enqueue( (int) $id, 'bulk' ) ) {
					$enqueued++;
				}
			}

			$offset += $batch_size;
		}

		return $enqueued;
	}
}
