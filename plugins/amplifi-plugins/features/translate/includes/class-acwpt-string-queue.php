<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background queue for string translation (stale-while-revalidate).
 *
 * Previously, a page whose strings were not yet cached translated them inline:
 * ensure_strings_cached_for_html() issued N sequential Claude calls while the
 * visitor's request was held open by the output buffer. On a page with many
 * untranslated strings that exceeded the host's gateway timeout and returned a
 * 502 — observed on WP Engine, which cuts requests at 60s.
 *
 * This queue inverts that: the request substitutes whatever is already cached,
 * returns immediately, and defers the misses to a background worker. The visitor
 * sees source-language text for the not-yet-translated fragments on the first
 * view and fully translated output once the queue drains.
 *
 * Queue:  wp_option acwpt_string_queue_{lang} — de-duplicated list of sources
 * Lock:   transient acwpt_string_queue_lock   — prevents concurrent workers
 */
class ACWPT_String_Queue {

	const QUEUE_OPTION_PREFIX = 'acwpt_string_queue_';
	const LOCK_KEY            = 'acwpt_string_queue_lock';
	const LOCK_STAMP_OPTION   = 'acwpt_string_queue_lock_at';
	const CRON_HOOK           = 'acwpt_process_string_queue';

	/** Max strings pulled from the queue per worker pass. */
	const BATCH_SIZE = 40;

	/** Max API batches per worker pass, so one cron tick stays bounded. */
	const MAX_BATCHES_PER_RUN = 3;

	/** Hard ceiling on queue length, to bound a runaway crawl. */
	const MAX_QUEUE = 5000;

	public static function register() {
		add_action( self::CRON_HOOK, array( 'ACWPT_String_Queue', 'process' ) );
	}

	// =========================================================================
	// Enqueue
	// =========================================================================

	private static function option_name( $language ) {
		if ( ! is_string( $language ) ) {
			// Fail loudly rather than building "acwpt_string_queue_Array" and
			// reading a queue that cannot exist.
			_doing_it_wrong(
				__METHOD__,
				'Language code must be a string, ' . gettype( $language ) . ' given.',
				'3.3.7'
			);
			$language = '';
		}
		return self::QUEUE_OPTION_PREFIX . $language;
	}

	/**
	 * Add source strings to the pending queue for a language.
	 *
	 * @param string   $language Language code.
	 * @param string[] $sources  Source strings needing translation.
	 * @return int Number newly queued.
	 */
	public static function enqueue( $language, array $sources ) {
		if ( empty( $sources ) ) {
			return 0;
		}

		$queue = get_option( self::option_name( $language ), array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		// Keyed by source so duplicates collapse without an O(n^2) scan.
		$before = count( $queue );
		foreach ( $sources as $s ) {
			if ( ! is_string( $s ) || '' === $s ) {
				continue;
			}
			if ( count( $queue ) >= self::MAX_QUEUE ) {
				break;
			}
			$queue[ $s ] = 1;
		}

		$added = count( $queue ) - $before;
		if ( $added > 0 ) {
			update_option( self::option_name( $language ), $queue, false );
			self::spawn();
		}

		return $added;
	}

	/**
	 * @return int Pending strings for a language.
	 */
	public static function pending( $language ) {
		$queue = get_option( self::option_name( $language ), array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}

	public static function clear( $language ) {
		delete_option( self::option_name( $language ) );
	}

	// =========================================================================
	// Worker
	// =========================================================================

	/**
	 * Drain up to MAX_BATCHES_PER_RUN batches from every language queue.
	 *
	 * @param string|null $only_language Restrict to one language.
	 * @return array Summary counters.
	 */
	public static function process( $only_language = null ) {
		$summary = array( 'translated' => 0, 'failed' => 0, 'remaining' => 0 );

		// A worker killed mid-batch (dropped connection, PHP fatal, deploy)
		// leaves the lock set. Without this the queue would stay frozen until
		// the transient expired — and on a site with a persistent object cache
		// that can outlive the request that set it.
		$lock = get_transient( self::LOCK_KEY );
		if ( $lock ) {
			$held_since = (int) get_option( self::LOCK_STAMP_OPTION, 0 );
			if ( $held_since && ( time() - $held_since ) > 300 ) {
				delete_transient( self::LOCK_KEY );
			} else {
				return $summary;
			}
		}

		set_transient( self::LOCK_KEY, 1, 120 );
		update_option( self::LOCK_STAMP_OPTION, time(), false );

		// Accept a single code OR an array of codes.
		//
		// Passing an array used to produce array( array('de','pl',...) ), so
		// $lang inside the loop was itself an array. option_name() then
		// concatenated it into the literal option name "acwpt_string_queue_Array",
		// which does not exist — the queue silently processed NOTHING and
		// returned a clean-looking {"translated":0,"failed":0,"remaining":0}.
		// The only symptom was a PHP "Array to string conversion" notice, easy
		// to read past. A no-op that reports success is worse than a failure.
		if ( is_array( $only_language ) ) {
			$languages = array_values( array_filter( $only_language, 'is_string' ) );
		} elseif ( $only_language ) {
			$languages = array( (string) $only_language );
		} else {
			$languages = class_exists( 'ACWPT_Languages' ) ? ACWPT_Languages::get_enabled_codes() : array();
		}

		foreach ( (array) $languages as $lang ) {
			$queue = get_option( self::option_name( $lang ), array() );
			if ( ! is_array( $queue ) || empty( $queue ) ) {
				continue;
			}

			for ( $batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++ ) {
				if ( empty( $queue ) ) {
					break;
				}

				$chunk = array_slice( array_keys( $queue ), 0, self::BATCH_SIZE );
				if ( empty( $chunk ) ) {
					break;
				}

				// Anything already translated by another worker is dropped free.
				$still_missing = ACWPT_String_Store::missing( $lang, $chunk );

				if ( empty( $still_missing ) ) {
					foreach ( $chunk as $s ) {
						unset( $queue[ $s ] );
					}
					update_option( self::option_name( $lang ), $queue, false );
					continue;
				}

				try {
					$translated = ACWPT_Translator::translate_strings( $still_missing, $lang );
				} catch ( \Throwable $e ) {
					error_log( 'ACWPT String Queue: exception for ' . $lang . ': ' . $e->getMessage() );
					$summary['failed'] += count( $still_missing );
					break;
				}

				if ( is_wp_error( $translated ) ) {
					error_log( 'ACWPT String Queue: ' . $lang . ' → ' . $translated->get_error_message() );
					$summary['failed'] += count( $still_missing );
					// Leave the chunk queued; a later run retries it.
					break;
				}

				$pairs = array();
				foreach ( $translated as $source => $target ) {
					// STORE IDENTITY RESULTS. Do not skip them.
					//
					// This used to drop any $target === $source pair and then
					// dequeue the chunk anyway. The comment below claimed that
					// stopped a loop; it created one. Dequeuing without storing
					// means the next render misses in get_many(), re-enqueues,
					// re-calls the API, gets the same identity result, and
					// dequeues again — permanently.
					//
					// On a B2B manufacturing site the affected set is large and
					// constant: API, ISO 9001, CNC, ROI, SaaS, 2024, part
					// numbers, and any brand not in never_translate. Every one
					// was re-billed on essentially every queue cycle, in all ten
					// languages, on every site — a spend floor no amount of
					// warming could remove, and the likely reason cost accrued
					// on a site whose queues read as drained.
					//
					// A genuine identity result is a CORRECT translation and
					// belongs in the cache. The real failure case the old guard
					// was reaching for is a parse miss, and translate_strings()
					// already handles that by leaving the chunk queued above.
					if ( is_string( $target ) && '' !== $target ) {
						$pairs[ $source ] = $target;
					}
				}

				if ( $pairs ) {
					ACWPT_String_Store::set_many( $lang, $pairs );
					$summary['translated'] += count( $pairs );
				}

				// Dequeue the chunk. Safe now that identity results are stored:
				// the next lookup hits the cache instead of re-queuing.
				foreach ( $chunk as $s ) {
					unset( $queue[ $s ] );
				}
				update_option( self::option_name( $lang ), $queue, false );
			}

			$summary['remaining'] += count( $queue );
		}

		delete_transient( self::LOCK_KEY );

		if ( $summary['remaining'] > 0 ) {
			self::schedule_next();
		}

		return $summary;
	}

	// =========================================================================
	// Scheduling
	// =========================================================================

	/**
	 * Schedule a worker pass shortly. Safe to call repeatedly.
	 */
	public static function spawn() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
		self::poke_cron();
	}

	private static function schedule_next() {
		wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		self::poke_cron();
	}

	/**
	 * Sites running with DISABLE_WP_CRON get no cron on page views, so nudge
	 * wp-cron.php directly. Non-blocking: never delays the visitor's response.
	 */
	private static function poke_cron() {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
	}
}
