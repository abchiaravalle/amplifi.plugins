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
	 * Process one batch of translations. Called by WP-Cron (or a tick request).
	 *
	 * @param int $max How many items to process this call. Defaults to BATCH_SIZE.
	 * @return array Status after processing.
	 */
	public static function process_batch( $max = null ) {
		if ( $max === null ) {
			$max = self::BATCH_SIZE;
		}

		// Prevent concurrent batches.
		if ( get_transient( self::LOCK_KEY ) ) {
			return self::get_status() ?: array();
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
			return $status ?: array();
		}

		for ( $i = 0; $i < $max; $i++ ) {
			if ( empty( $queue ) ) {
				break;
			}
			$item = array_shift( $queue );

			// Per-item try/catch — one bad translate must not nuke the run.
			try {
				$post = get_post( $item['post_id'] );
				if ( ! $post ) {
					$status['failed'] = ( $status['failed'] ?? 0 ) + 1;
				} else {
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

					// Warm the STRING cache for this post's rendered page.
					//
					// Post-level translation alone is not enough: a page-builder
					// site keeps most of its visible text outside post_content
					// (Elementor widget data, nav, header/footer chrome), and that
					// text is translated from the RENDERED html by the string
					// pipeline. Without this pass the preloader reports every post
					// complete while the translated URL still renders source-
					// language text and — on a cold page — blocks long enough to
					// trip the host gateway timeout.
					self::warm_page_strings( $post, $item['language'], $status );
				}
			} catch ( \Throwable $e ) {
				error_log( 'ACWPT Preloader: exception translating post ' . ( $item['post_id'] ?? '?' ) . ' → ' . ( $item['language'] ?? '?' ) . ': ' . $e->getMessage() );
				$status['failed'] = ( $status['failed'] ?? 0 ) + 1;
			}

			// Persist queue + status after EACH item so a subsequent fatal
			// can't lose items we've already processed or still have pending.
			update_option( self::QUEUE_OPTION, $queue, false );
			update_option( self::STATUS_OPTION, $status, false );
		}

		delete_transient( self::LOCK_KEY );

		if ( ! empty( $queue ) ) {
			self::schedule_next();
		} else {
			$status['finished_at'] = time();
			update_option( self::STATUS_OPTION, $status, false );
		}

		return $status;
	}

	// =========================================================================
	// Status / Control
	// =========================================================================

	/**
	 * Fetch a post's rendered page and translate every string in it that is not
	 * already stored. Runs in the background (cron / CLI), so it is allowed to
	 * make blocking API calls — unlike the front-end path.
	 *
	 * @param WP_Post $post     The source post.
	 * @param string  $language Target language code.
	 * @param array   $status   Status record, updated by reference.
	 */
	private static function warm_page_strings( $post, $language, &$status ) {
		if ( ! class_exists( 'ACWPT_String_Store' ) || ! class_exists( 'ACWPT_Frontend' ) ) {
			return;
		}

		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'   => 60,
				'sslverify' => false,
				// Marker so a site can identify (and skip logging) warm-up hits.
				'headers'   => array( 'X-ACWPT-Preload' => '1' ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			error_log( 'ACWPT Preloader: could not fetch ' . $url . ' — ' . $resp->get_error_message() );
			return;
		}

		$html = wp_remote_retrieve_body( $resp );
		if ( '' === $html ) {
			return;
		}

		$strings = self::extract_strings( $html );
		if ( empty( $strings ) ) {
			return;
		}

		$missing = ACWPT_String_Store::missing( $language, $strings );
		if ( empty( $missing ) ) {
			return;
		}

		foreach ( array_chunk( $missing, 40 ) as $chunk ) {
			$translated = ACWPT_Translator::translate_strings( $chunk, $language );
			if ( is_wp_error( $translated ) ) {
				error_log( 'ACWPT Preloader: string batch failed for ' . $language . ' — ' . $translated->get_error_message() );
				break;
			}

			$pairs = array();
			foreach ( $translated as $source => $target ) {
				// An unchanged value is a legitimate non-translation (brand name,
				// number); storing it would cache a non-translation as a result.
				if ( is_string( $target ) && '' !== $target && $target !== $source ) {
					$pairs[ $source ] = $target;
				}
			}

			if ( $pairs ) {
				ACWPT_String_Store::set_many( $language, $pairs );
				$status['strings'] = ( $status['strings'] ?? 0 ) + count( $pairs );
			}
		}
	}

	/**
	 * Reuse the frontend's extraction rules so the preloader and the renderer
	 * always agree on what counts as a translatable string. Duplicating the
	 * patterns here would let the two drift apart silently.
	 *
	 * @param string $html
	 * @return string[]
	 */
	private static function extract_strings( $html ) {
		$fe  = ACWPT_Frontend::instance();
		$ref = new ReflectionClass( 'ACWPT_Frontend' );

		if ( ! $ref->hasMethod( 'extract_translatable_strings_from_html' ) ) {
			return array();
		}

		$m = $ref->getMethod( 'extract_translatable_strings_from_html' );
		$m->setAccessible( true );

		$strings = (array) $m->invoke( $fe, $html );
		return array_values( array_unique( array_filter( $strings ) ) );
	}

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
	 * Drive one small unit of progress synchronously. Intended for browser-driven
	 * polling from the admin UI so the preloader isn't dependent on WP-Cron
	 * firing reliably (DISABLE_WP_CRON sites, blocked self-HTTP, etc.).
	 *
	 * @return array Current status.
	 */
	public static function tick() {
		if ( ! self::is_running() ) {
			return self::get_status() ?: array();
		}
		return self::process_batch( 1 );
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
