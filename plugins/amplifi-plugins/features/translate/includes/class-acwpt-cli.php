<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WP-CLI commands for amplifi.translate.
 *
 * Preloading from the browser is not viable: the admin AJAX tick runs inside a
 * web request, so a large site hits the host gateway timeout long before the
 * queue drains. These commands run without that ceiling and print real progress,
 * which is what you want for a bounded, observable spend.
 *
 *     wp acwpt status
 *     wp acwpt preload --language=pl
 *     wp acwpt preload --language=pl --post-type=page --limit=5
 *     wp acwpt drain --language=pl
 */
class ACWPT_CLI {

	/**
	 * Show translation coverage and spend.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acwpt status
	 */
	public function status( $args, $assoc ) {
		$settings = get_option( 'acwpt_settings', array() );
		$enabled  = ACWPT_Languages::get_enabled_codes();

		WP_CLI::line( '' );
		WP_CLI::line( 'amplifi.translate' );
		WP_CLI::line( str_repeat( '-', 52 ) );
		WP_CLI::line( sprintf( '  %-22s %s', 'source language:', ACWPT_Languages::get_source() ) );
		WP_CLI::line( sprintf( '  %-22s %s', 'target languages:', $enabled ? implode( ', ', $enabled ) : '(none)' ) );
		WP_CLI::line( sprintf( '  %-22s %s', 'model:', ! empty( $settings['model'] ) ? $settings['model'] : '(default)' ) );
		WP_CLI::line( sprintf( '  %-22s %s', 'post types:', implode( ', ', acwpt_post_types() ) ) );
		WP_CLI::line( '' );

		$types = acwpt_post_types();
		$total = 0;
		foreach ( $types as $pt ) {
			$n      = (int) wp_count_posts( $pt )->publish;
			$total += $n;
			WP_CLI::line( sprintf( '  %-22s %d published', $pt, $n ) );
		}
		WP_CLI::line( sprintf( '  %-22s %d', 'TOTAL', $total ) );
		WP_CLI::line( '' );

		foreach ( $enabled as $lang ) {
			$cached  = ACWPT_Cache::stats();
			$strings = ACWPT_String_Store::count( $lang );
			$pending = ACWPT_String_Queue::pending( $lang );
			WP_CLI::line( sprintf( '  [%s] strings stored: %-7d queued: %d', $lang, $strings, $pending ) );
		}

		$u = ACWPT_Translator::get_total_usage();
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '  API calls: %d   tokens: %s   spend: $%s',
			$u['requests'],
			number_format( $u['total_tokens'] ),
			number_format( $u['estimated_cost'], 4 )
		) );
		WP_CLI::line( '' );
	}

	/**
	 * Preload translations for every post, across all configured post types.
	 *
	 * Warms BOTH caches: post title/content/excerpt, and the rendered-page
	 * strings (nav, header/footer, page-builder widget text). Warming only the
	 * former leaves translated URLs rendering source-language text.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<code>]
	 * : Target language. Defaults to every enabled language.
	 *
	 * [--post-type=<type>]
	 * : Restrict to one post type.
	 *
	 * [--limit=<n>]
	 * : Only process the first N posts. Useful for a bounded cost probe.
	 *
	 * [--skip-strings]
	 * : Only translate post content, skip the rendered-page string pass.
	 *
	 * [--dry-run]
	 * : Report what would be translated without calling the API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acwpt preload --language=pl --limit=5
	 *     wp acwpt preload --language=pl --post-type=press-release
	 */
	public function preload( $args, $assoc ) {
		$languages = ! empty( $assoc['language'] )
			? array( $assoc['language'] )
			: ACWPT_Languages::get_enabled_codes();

		if ( empty( $languages ) ) {
			WP_CLI::error( 'No target languages are enabled.' );
		}

		$types = ! empty( $assoc['post-type'] ) ? array( $assoc['post-type'] ) : acwpt_post_types();
		$limit = ! empty( $assoc['limit'] ) ? (int) $assoc['limit'] : -1;
		$dry   = ! empty( $assoc['dry-run'] );
		$skip  = ! empty( $assoc['skip-strings'] );

		$posts = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No published posts found for: ' . implode( ', ', $types ) );
			return;
		}

		$u0 = ACWPT_Translator::get_total_usage();
		$t0 = microtime( true );

		WP_CLI::line( sprintf(
			'Preloading %d posts x %d language(s)%s',
			count( $posts ),
			count( $languages ),
			$dry ? ' [DRY RUN]' : ''
		) );
		WP_CLI::line( str_repeat( '-', 68 ) );

		$done = 0;
		$fail = 0;

		foreach ( $languages as $lang ) {
			foreach ( $posts as $post ) {
				$title = html_entity_decode( get_the_title( $post ) );
				$label = mb_substr( $title, 0, 44 );

				if ( $dry ) {
					$cached = ACWPT_Cache::get( $post->ID, $lang );
					$state  = $cached ? 'cached' : 'NEEDS TRANSLATION';
					WP_CLI::line( sprintf( '  [%s] %-46s %s', $lang, $label, $state ) );
					continue;
				}

				$p0 = microtime( true );
				$r  = ACWPT_Preloader::preload_one( $post, $lang, ! $skip );
				$el = round( microtime( true ) - $p0, 1 );

				if ( is_wp_error( $r ) ) {
					$fail++;
					WP_CLI::line( sprintf( '  [%s] %-46s FAILED: %s', $lang, $label, $r->get_error_message() ) );
					continue;
				}

				$done++;
				WP_CLI::line( sprintf(
					'  [%s] %-46s %5ss  strings +%d',
					$lang,
					$label,
					$el,
					$r['strings']
				) );
			}
		}

		if ( $dry ) {
			WP_CLI::line( '' );
			WP_CLI::success( 'Dry run complete — no API calls made.' );
			return;
		}

		$u1 = ACWPT_Translator::get_total_usage();
		WP_CLI::line( str_repeat( '-', 68 ) );
		WP_CLI::line( sprintf( '  elapsed: %ss', round( microtime( true ) - $t0, 1 ) ) );
		WP_CLI::line( sprintf( '  posts:   %d done, %d failed', $done, $fail ) );
		WP_CLI::line( sprintf( '  calls:   %d', $u1['requests'] - $u0['requests'] ) );
		WP_CLI::line( sprintf( '  tokens:  %s', number_format( $u1['total_tokens'] - $u0['total_tokens'] ) ) );
		WP_CLI::line( sprintf( '  cost:    $%s  (lifetime $%s)',
			number_format( $u1['estimated_cost'] - $u0['estimated_cost'], 4 ),
			number_format( $u1['estimated_cost'], 4 )
		) );

		foreach ( $languages as $lang ) {
			$pending = ACWPT_String_Queue::pending( $lang );
			if ( $pending > 0 ) {
				WP_CLI::line( sprintf( '  NOTE: %d strings still queued for %s — run: wp acwpt drain --language=%s', $pending, $lang, $lang ) );
			}
		}

		WP_CLI::success( 'Preload complete.' );
	}

	/**
	 * Drain the background string queue to completion.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<code>]
	 * : Target language. Defaults to every enabled language.
	 *
	 * [--max-rounds=<n>]
	 * : Safety stop. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acwpt drain --language=pl
	 */
	public function drain( $args, $assoc ) {
		$languages = ! empty( $assoc['language'] )
			? array( $assoc['language'] )
			: ACWPT_Languages::get_enabled_codes();

		$max_rounds = ! empty( $assoc['max-rounds'] ) ? (int) $assoc['max-rounds'] : 100;

		$u0 = ACWPT_Translator::get_total_usage();
		$t0 = microtime( true );

		foreach ( $languages as $lang ) {
			$start = ACWPT_String_Queue::pending( $lang );
			if ( 0 === $start ) {
				WP_CLI::line( sprintf( '[%s] queue already empty.', $lang ) );
				continue;
			}

			WP_CLI::line( sprintf( '[%s] draining %d queued strings...', $lang, $start ) );

			$round = 0;
			$stall = 0;

			while ( ACWPT_String_Queue::pending( $lang ) > 0 && $round < $max_rounds ) {
				$round++;
				// Each synchronous pass must release the worker lock.
				delete_transient( 'acwpt_string_queue_lock' );

				$before = ACWPT_String_Queue::pending( $lang );
				$s      = ACWPT_String_Queue::process( $lang );
				$after  = ACWPT_String_Queue::pending( $lang );

				WP_CLI::line( sprintf(
					'  round %3d: translated=%3d failed=%3d  pending %4d -> %4d',
					$round, $s['translated'], $s['failed'], $before, $after
				) );

				if ( $after >= $before ) {
					$stall++;
					if ( $stall >= 3 ) {
						WP_CLI::warning( 'No progress in 3 rounds — stopping.' );
						break;
					}
				} else {
					$stall = 0;
				}
			}
		}

		$u1 = ACWPT_Translator::get_total_usage();
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '  elapsed: %ss   calls: %d   cost: $%s',
			round( microtime( true ) - $t0, 1 ),
			$u1['requests'] - $u0['requests'],
			number_format( $u1['estimated_cost'] - $u0['estimated_cost'], 4 )
		) );
		WP_CLI::success( 'Drain complete.' );
	}
}

WP_CLI::add_command( 'acwpt', 'ACWPT_CLI' );
