<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background translation preloader.
 *
 * Builds a queue of post×language pairs that are not yet cached, then
 * processes them in small batches via WP-Cron so visitors never trigger
 * a live Claude call on first load.
 *
 * Queue:   wp_option acwpt_preload_queue  — array of {post_id, language}
 * Status:  wp_option acwpt_preload_status — progress counters + timestamps
 * Lock:    transient acwpt_preload_lock   — prevents concurrent batches
 */
class ACWPT_Preloader {

	const QUEUE_OPTION  = 'acwpt_preload_queue';
	const STATUS_OPTION = 'acwpt_preload_status';
	const LOCK_KEY      = 'acwpt_preload_lock';
	const CRON_HOOK     = 'acwpt_process_preload_batch';
	const BATCH_SIZE    = 3;

	/**
	 * Register the cron hook. Call from plugin init.
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( 'ACWPT_Preloader', 'process_batch' ) );
	}

	// =========================================================================
	// Queue Building
	// =========================================================================

	/**
	 * Build a fresh queue for all published posts × all enabled languages,
	 * skipping pairs that are already cached with a matching content hash.
	 *
	 * @return int Number of items queued.
	 */
	public static function start_all() {
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return 0;
		}

		$posts = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$queue = array();
		foreach ( $posts as $post ) {
			$hash = self::content_hash( $post );
			foreach ( $enabled as $lang ) {
				$cached = ACWPT_Cache::get( $post->ID, $lang );
				if ( ! $cached || $cached->content_hash !== $hash ) {
					$queue[] = array( 'post_id' => $post->ID, 'language' => $lang );
				}
			}
		}

		self::init_queue( $queue );
		return count( $queue );
	}

	/**
	 * Queue a single post across all enabled languages (used by auto-preload).
	 * Merges into an existing run if one is in progress.
	 *
	 * @param int $post_id
	 */
	public static function start_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return;
		}

		$hash      = self::content_hash( $post );
		$new_items = array();
		foreach ( $enabled as $lang ) {
			$cached = ACWPT_Cache::get( $post->ID, $lang );
			if ( ! $cached || $cached->content_hash !== $hash ) {
				$new_items[] = array( 'post_id' => $post->ID, 'language' => $lang );
			}
		}

		if ( empty( $new_items ) ) {
			return;
		}

		$status = self::get_status();
		if ( $status && empty( $status['finished_at'] ) ) {
			// Merge into the running queue (deduplicate).
			$existing = get_option( self::QUEUE_OPTION, array() );
			$seen     = array();
			foreach ( $existing as $item ) {
				$seen[ $item['post_id'] . '_' . $item['language'] ] = true;
			}
			foreach ( $new_items as $item ) {
				$key = $item['post_id'] . '_' . $item['language'];
				if ( ! isset( $seen[ $key ] ) ) {
					$existing[] = $item;
					$status['total']++;
				}
			}
			update_option( self::QUEUE_OPTION, $existing, false );
			update_option( self::STATUS_OPTION, $status, false );
			self::spawn();
		} else {
			self::init_queue( $new_items );
		}
	}

	// =========================================================================
	// Batch Processing
	// =========================================================================

	/**
	 * Process one batch of translations. Called by WP-Cron.
	 */
	public static function process_batch() {
		// Prevent concurrent batches.
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 90 );

		$queue  = get_option( self::QUEUE_OPTION, array() );
		$status = get_option( self::STATUS_OPTION, array() );

		if ( empty( $queue ) ) {
			if ( ! empty( $status ) && empty( $status['finished_at'] ) ) {
				$status['finished_at'] = time();
				update_option( self::STATUS_OPTION, $status, false );
			}
			delete_transient( self::LOCK_KEY );
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $item ) {
			$post = get_post( $item['post_id'] );
			if ( ! $post ) {
				$status['failed'] = ( $status['failed'] ?? 0 ) + 1;
				continue;
			}

			$result = ACWPT_Translator::translate(
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
				$item['language']
			);

			if ( is_wp_error( $result ) ) {
				error_log( 'ACWPT Preloader: error translating post ' . $post->ID . ' → ' . $item['language'] . ': ' . $result->get_error_message() );
				$status['failed'] = ( $status['failed'] ?? 0 ) + 1;
			} else {
				ACWPT_Cache::set(
					$post->ID,
					$item['language'],
					$result['title'],
					$result['content'],
					$result['excerpt'],
					self::content_hash( $post )
				);
				$status['completed'] = ( $status['completed'] ?? 0 ) + 1;
			}
		}

		update_option( self::STATUS_OPTION, $status, false );
		delete_transient( self::LOCK_KEY );

		if ( ! empty( $queue ) ) {
			self::schedule_next();
		} else {
			$status['finished_at'] = time();
			update_option( self::STATUS_OPTION, $status, false );
		}
	}

	// =========================================================================
	// Status / Control
	// =========================================================================

	/**
	 * @return array|null Status record, or null if no run has ever been started.
	 */
	public static function get_status() {
		$s = get_option( self::STATUS_OPTION, null );
		return is_array( $s ) ? $s : null;
	}

	/** @return bool */
	public static function is_running() {
		$s = self::get_status();
		return $s && empty( $s['finished_at'] );
	}

	/**
	 * Abort the current run.
	 */
	public static function stop() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		update_option( self::QUEUE_OPTION, array(), false );
		delete_transient( self::LOCK_KEY );

		$s = self::get_status();
		if ( $s && empty( $s['finished_at'] ) ) {
			$s['finished_at'] = time();
			update_option( self::STATUS_OPTION, $s, false );
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private static function init_queue( $queue ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_KEY );

		update_option( self::QUEUE_OPTION, $queue, false );
		update_option( self::STATUS_OPTION, array(
			'total'       => count( $queue ),
			'completed'   => 0,
			'failed'      => 0,
			'started_at'  => time(),
			'finished_at' => null,
		), false );

		self::schedule_next();
	}

	private static function schedule_next() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		self::spawn();
	}

	/**
	 * Fire WP-Cron immediately (non-blocking, same mechanism WP uses internally).
	 */
	private static function spawn() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return; // Site uses real cron; it will fire on schedule.
		}
		wp_remote_post(
			add_query_arg( 'doing_wp_cron', sprintf( '%.22F', microtime( true ) ), site_url( 'wp-cron.php' ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => array(),
			)
		);
	}

	private static function content_hash( $post ) {
		$settings       = get_option( 'acwpt_settings', array() );
		$custom_version = isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0;
		return md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt . '||v' . $custom_version );
	}
}
