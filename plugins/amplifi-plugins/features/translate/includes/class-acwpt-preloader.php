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
	const WATCHDOG_HOOK = 'acwpt_preload_watchdog';
	const RECONCILE_HOOK = 'acwpt_reconcile_dirty';
	const BATCH_SIZE    = 3;

	/**
	 * Register the cron hook. Call from plugin init.
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::WATCHDOG_HOOK, array( __CLASS__, 'watchdog' ) );
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'reconcile_dirty' ) );
		self::ensure_watchdog();
	}

	/**
	 * Re-translate the rendered strings of posts whose content changed.
	 *
	 * Runs in the background because it needs the rendered page and may call the
	 * API. A post save only records the post ID; nothing is discarded up front,
	 * so the site keeps serving its existing translations until better ones
	 * exist. Worst case a page briefly shows slightly outdated wording — far
	 * better than the previous behaviour, where one save wiped every language.
	 */
	public static function reconcile_dirty() {
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return;
		}
		$dirty = ACWPT_String_Store::dirty_posts();
		if ( ! $dirty ) {
			return;
		}

		$languages = ACWPT_Languages::get_enabled_codes();
		if ( ! $languages ) {
			foreach ( $dirty as $pid ) {
				ACWPT_String_Store::clear_post_dirty( $pid );
			}
			return;
		}

		// Bounded per tick: reconciliation renders pages and can call the API.
		$batch = array_slice( $dirty, 0, 2 );

		foreach ( $batch as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				ACWPT_String_Store::clear_post_dirty( $post_id );
				continue;
			}

			foreach ( $languages as $lang ) {
				try {
					$status = array();
					self::warm_page_strings( $post, $lang, $status );
				} catch ( \Throwable $e ) {
					error_log( 'ACWPT reconcile: post ' . $post_id . ' -> ' . $lang . ': ' . $e->getMessage() );
				}
			}

			ACWPT_String_Store::clear_post_dirty( $post_id );
		}

		if ( ACWPT_String_Store::dirty_posts() ) {
			self::schedule_reconcile();
		}
	}

	/**
	 * Queue a reconciliation pass.
	 */
	public static function schedule_reconcile() {
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::RECONCILE_HOOK );
		}
	}

	/**
	 * Keep a recurring watchdog scheduled.
	 *
	 * The batch chain is self-continuing: each run schedules the next while work
	 * remains. But that chain is a single thread of causality — if one link is
	 * lost (a spawn request that never lands, a PHP fatal mid-batch, a deploy or
	 * host restart between ticks, an object-cache flush that drops the scheduled
	 * event) the queue simply stops, silently, with no error and no retry.
	 *
	 * A long preload must not depend on an unbroken chain, and must never depend
	 * on an operator's terminal staying connected. This watchdog re-arms the
	 * chain from a recurring event, so the run resumes on its own.
	 */
	public static function ensure_watchdog() {
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return;
		}
		$existing = wp_get_scheduled_event( self::WATCHDOG_HOOK );

		// A non-recurring entry means wp_schedule_event() ran before the custom
		// interval was registered and silently degraded to a one-shot. That
		// watchdog fires once and then the site is unprotected again, which is
		// worse than none because it looks scheduled.
		if ( $existing && empty( $existing->schedule ) ) {
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
			$existing = false;
		}

		if ( ! $existing ) {
			$schedules = wp_get_schedules();
			if ( isset( $schedules['acwpt_five_minutes'] ) ) {
				wp_schedule_event( time() + 60, 'acwpt_five_minutes', self::WATCHDOG_HOOK );
			} else {
				// Interval not registered yet this request; fall back to hourly
				// so the site is never left without a backstop.
				wp_schedule_event( time() + 60, 'hourly', self::WATCHDOG_HOOK );
			}
		}
	}

	/**
	 * Resume a stalled run.
	 *
	 * Fires on a schedule regardless of what the batch chain is doing. If there
	 * is queued work but no batch pending, the chain is broken — restart it.
	 */
	public static function watchdog() {
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return;
		}
		$queue = get_option( self::QUEUE_OPTION, array() );

		// Post queue stalled?
		if ( ! empty( $queue ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// A stale lock from a killed batch would block every future run.
			$status = self::get_status();
			$lock   = get_transient( self::LOCK_KEY );
			if ( $lock && ! empty( $status['last_tick'] ) && ( time() - (int) $status['last_tick'] ) > 300 ) {
				delete_transient( self::LOCK_KEY );
			}
			wp_schedule_single_event( time(), self::CRON_HOOK );
			self::spawn();
		}

		// String queue stalled? Same reasoning, different queue.
		if ( class_exists( 'ACWPT_String_Queue' ) && class_exists( 'ACWPT_Languages' ) ) {
			foreach ( ACWPT_Languages::get_enabled_codes() as $lang ) {
				if ( ACWPT_String_Queue::pending( $lang ) > 0 ) {
					ACWPT_String_Queue::spawn();
					break;
				}
			}
		}

		// Posts edited but never reconciled? Their translations are drifting.
		if ( class_exists( 'ACWPT_String_Store' ) && ACWPT_String_Store::dirty_posts() ) {
			self::schedule_reconcile();
		}
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
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return 0;
		}
		$enabled = ACWPT_Languages::get_enabled_codes();
		if ( empty( $enabled ) ) {
			return 0;
		}

		$posts = get_posts( array(
			'post_type'      => acwpt_post_types(),
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
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return false;
		}
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
		// KILL SWITCH. The watchdog rebuilt and restarted this preloader after
		// it was paused, and it re-billed ~$50 in an afternoon translating each
		// post twice ($0.10/page) and writing half the result to a table this
		// site does not have. The string warm (strings only, $0.03/page) is the
		// supported path; this stays off until the option is removed.
		if ( get_option( 'acwpt_preloader_disabled' ) ) {
			return array();
		}
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

		// Heartbeat: lets the watchdog tell "working" from "died holding the lock".
		$status['last_tick'] = time();
		update_option( self::STATUS_OPTION, $status, false );

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

		$strings = array_merge( self::extract_strings( $html ), self::extract_head_strings( $html ) );
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
				// STORE IDENTITY RESULTS. A brand or model name that correctly
				// stays unchanged ("Ascentialytics", "CMT-VSR") was skipped here,
				// so the next warm or render found it "missing" and paid to
				// translate it again, on every pass, in every language. The
				// string queue stopped doing this long ago; the preloader never
				// did.
				if ( is_string( $target ) && '' !== $target ) {
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
	 * SEO head text of the ENGLISH page: <title>, meta description, og:title,
	 * og:description, twitter:title, twitter:description.
	 *
	 * These never pass through the body extractor. At render time they are
	 * translated from the store by filter_seo_text() and translate_meta_tags(),
	 * and only QUEUED on a miss. With the queues pinned at their cap the miss
	 * was often dropped, so inner pages (/transportation/adas/ in German, for
	 * one) served an English title and meta description to search engines
	 * indefinitely. Warming them with the page closes that gap.
	 *
	 * @param string $html
	 * @return string[]
	 */
	private static function extract_head_strings( $html ) {
		$out = array();
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			$out[] = $m[1];
		}
		if ( preg_match_all(
			'/<meta\s+[^>]*?(?:name|property)\s*=\s*["\'](?:description|og:title|og:description|twitter:title|twitter:description)["\'][^>]*>/i',
			$html,
			$mm
		) ) {
			foreach ( $mm[0] as $tag ) {
				if ( preg_match( '/content\s*=\s*"([^"]*)"/i', $tag, $c ) || preg_match( "/content\\s*=\\s*'([^']*)'/i", $tag, $c ) ) {
					$out[] = $c[1];
				}
			}
		}
		$clean = array();
		foreach ( $out as $x ) {
			$x = trim( html_entity_decode( $x, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( mb_strlen( $x ) >= 2 ) {
				$clean[] = $x;
			}
		}
		return array_values( array_unique( $clean ) );
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
	 * Translate ONE post into ONE language, warming both caches.
	 *
	 * Shared by the cron batch worker and the WP-CLI command so there is a
	 * single definition of "preloaded" — warming post content alone leaves the
	 * translated URL rendering source-language text for everything the page
	 * builder and theme chrome contribute.
	 *
	 * @param WP_Post $post
	 * @param string  $language
	 * @param bool    $with_strings Also warm the rendered-page string cache.
	 * @return array|WP_Error {post_cached:bool, strings:int}
	 */
	public static function preload_one( $post, $language, $with_strings = true ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post );
		}
		if ( ! $post ) {
			return new WP_Error( 'no_post', 'Post not found.' );
		}

		$result = array( 'post_cached' => false, 'strings' => 0 );

		$hash   = self::content_hash( $post );
		$cached = ACWPT_Cache::get( $post->ID, $language );

		if ( $cached && isset( $cached->content_hash ) && $cached->content_hash === $hash ) {
			$result['post_cached'] = true;
		} else {
			$t = ACWPT_Translator::translate(
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
				$language
			);

			if ( is_wp_error( $t ) ) {
				// A post-content failure must not abort the string pass: on
				// page-builder sites almost all visible text comes from strings,
				// so the page is still largely translatable without it.
				error_log( 'ACWPT Preloader: post ' . $post->ID . ' -> ' . $language . ': ' . $t->get_error_message() );
			} else {
				ACWPT_Cache::set(
					$post->ID,
					$language,
					$t['title'],
					$t['content'],
					$t['excerpt'],
					$hash
				);
				$result['post_cached'] = true;
			}
		}

		if ( $with_strings ) {
			$status = array();
			self::warm_page_strings( $post, $language, $status );
			$result['strings'] = (int) ( $status['strings'] ?? 0 );
		}

		return $result;
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
		// Schedule slightly in the future, not at time(). An event timestamped
		// "now" is consumed by the very cron run that spawned it, so the chain
		// can appear to vanish: wp_next_scheduled() reports nothing pending
		// while work remains. A small offset keeps the next link observable and
		// leaves room for the current request to finish writing state.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
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

	/**
	 * Hash of a post's translatable source.
	 *
	 * Public because cache invalidation, the status report, and the CLI all need
	 * to answer the same question — "has the source changed since we translated
	 * it?" — and must agree on the answer.
	 */
	public static function content_hash( $post ) {
		$settings       = get_option( 'acwpt_settings', array() );
		$custom_version = isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0;
		return md5( $post->post_title . '||' . $post->post_content . '||' . $post->post_excerpt . '||v' . $custom_version );
	}
}
