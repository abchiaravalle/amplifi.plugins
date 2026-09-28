<?php
/**
 * Server-side batch re-translation (Anthropic Message Batches API).
 *
 * WHY
 *  - Self-sufficient: runs on WP-Cron inside WordPress. Nothing depends on an
 *    operator's machine or an SSH session (a dropped SSH link stranded a
 *    re-translation half-way on 2026-09-26).
 *  - Half price: batch requests are billed at 50% of standard rates and are
 *    stacked with prompt caching.
 *
 * FLOW (one job per language)
 *  enqueue_language( lang, sources, model )  -> job option with pending sources
 *  cron tick (every 5 min, one lock):
 *    - submit: up to 25 strings per request, up to 400 requests per batch
 *      (same prompt, term block and gates as translate_strings())
 *    - poll: when a batch has ended, stream results, run every per-item gate
 *      (count/key integrity, structure signature, mixed script, terminology),
 *      store passing rows with set_many() (locked rows are never touched),
 *      requeue failed items once, record spend at 50%.
 *  Budget: a submit is refused if the estimated batch cost would cross the
 *  monthly ceiling; results already paid for are always stored.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Batch {

	const OPT_JOBS   = 'acwpt_batch_jobs';

	/** In-flight estimated cost, recomputed at the start of each tick and grown as batches are submitted. */
	private static $committed_live = 0.0;
	const CRON_HOOK  = 'acwpt_batch_tick';
	const PER_REQ    = 25;
	const MAX_REQS   = 400;
	const EST_PER_ST = 0.0010; // $/string at batch rates: measured $0.00066 (pt/fr/es) - $0.00086 (de/it/cs/ro); 0.0010 = margin

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		if ( self::jobs() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'acwpt_5min', self::CRON_HOOK );
		}
	}

	public static function schedule( $s ) {
		$s['acwpt_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (amplifi.translate batch)' );
		return $s;
	}

	/** Jobs are read and written straight from the options table (the object cache served stale state under concurrency before). */
	private static function jobs() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPT_JOBS ) );
		$v   = $raw ? json_decode( $raw, true ) : array();
		return is_array( $v ) ? $v : array();
	}

	private static function save( array $jobs ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			self::OPT_JOBS,
			wp_json_encode( $jobs, JSON_UNESCAPED_UNICODE )
		) );
		wp_cache_delete( self::OPT_JOBS, 'options' );
	}

	/**
	 * Queue a language for re-translation.
	 *
	 * @param string   $lang
	 * @param string[] $sources English source strings
	 * @param string   $model
	 * @return int number queued (locked rows excluded)
	 */
	public static function enqueue_language( $lang, array $sources, $model = 'claude-sonnet-4-6', $cap = 0.0 ) {
		global $wpdb;
		$t      = ACWPT_String_Store::table_name();
		$locked = array();
		foreach ( array_chunk( array_map( 'md5', $sources ), 150 ) as $hc ) {
			$ph = implode( ',', array_fill( 0, count( $hc ), '%s' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_hash FROM {$t} WHERE language=%s AND locked=1 AND source_hash IN ({$ph})", array_merge( array( $lang ), $hc ) ) ) as $h ) {
				$locked[ $h ] = 1;
			}
		}
		$todo = array_values( array_filter( array_unique( $sources ), function ( $s ) use ( $locked ) { return '' !== trim( $s ) && ! isset( $locked[ md5( $s ) ] ); } ) );
		$jobs = self::jobs();
		$carry = isset( $jobs[ $lang ]['batches'] ) ? (array) $jobs[ $lang ]['batches'] : array(); // keep billed in-flight batches
		$jobs[ $lang ] = array(
			'model'    => $model,
			'pending'  => $todo,
			'retried'  => array(),
			'batches'  => $carry,
			'stats'    => array( 'queued' => count( $todo ), 'stored' => 0, 'failed' => 0, 'cost' => 0.0 ),
			'cap'      => (float) $cap, // hard USD cap for this job (0 = none); approved per run
			'created'  => time(),
		);
		self::save( $jobs );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 30, 'acwpt_5min', self::CRON_HOOK );
		}
		return count( $todo );
	}

	public static function status() {
		$out = array();
		foreach ( self::jobs() as $lang => $j ) {
			$out[ $lang ] = array(
				'pending'  => count( $j['pending'] ),
				'in_flight'=> count( $j['batches'] ),
				'stats'    => $j['stats'],
			);
		}
		return $out;
	}

	/** One cron tick: poll finished batches, then submit new ones. */
	public static function tick() {
		if ( get_transient( 'acwpt_batch_lock' ) ) {
			return;
		}
		set_transient( 'acwpt_batch_lock', 1, 280 );
		try {
			self::$committed_live = self::committed();
			$jobs = self::jobs();
			foreach ( $jobs as $lang => &$job ) {
				$before = array_sum( array_map( function ( $b ) { return (float) ( $b['est'] ?? 0 ); }, (array) $job['batches'] ) );
				self::poll( $lang, $job );
				$after  = array_sum( array_map( function ( $b ) { return (float) ( $b['est'] ?? 0 ); }, (array) $job['batches'] ) );
				self::$committed_live -= ( $before - $after ); // finished batches are now real spend
				self::submit( $lang, $job );
			}
			unset( $job );
			$jobs = array_filter( $jobs, function ( $j ) { return $j['pending'] || $j['batches']; } );
			self::save( $jobs );
			if ( ! $jobs ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		} finally {
			delete_transient( 'acwpt_batch_lock' );
		}
	}

	private static function settings() {
		$s = get_option( 'acwpt_settings', array() );
		return array(
			'api_key' => isset( $s['api_key'] ) ? $s['api_key'] : '',
			'custom'  => array(
				'never_translate'     => isset( $s['never_translate'] ) ? (array) $s['never_translate'] : array(),
				'glossary'            => isset( $s['glossary'] ) ? (array) $s['glossary'] : array(),
				'custom_instructions' => isset( $s['custom_instructions'] ) ? (array) $s['custom_instructions'] : array(),
			),
		);
	}

	private static function http( $method, $path, $body = null, $timeout = 60 ) {
		$cfg  = self::settings();
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'x-api-key'         => $cfg['api_key'],
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$r = wp_remote_request( 'https://api.anthropic.com' . $path, $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = wp_remote_retrieve_response_code( $r );
		$raw  = wp_remote_retrieve_body( $r );
		if ( $code < 200 || $code >= 300 ) {
			$d = json_decode( $raw, true );
			ACWPT_Budget::note_api_error( $d['error']['message'] ?? "HTTP {$code}" );
			return new WP_Error( 'acwpt_batch_http', ( $d['error']['message'] ?? "HTTP {$code}" ) );
		}
		return $raw;
	}

	/** Estimated cost of all batches still in flight (spend is only recorded when results arrive). */
	private static function committed() {
		$sum = 0.0;
		foreach ( self::jobs() as $j ) {
			foreach ( (array) $j['batches'] as $b ) {
				$sum += (float) ( $b['est'] ?? 0 );
			}
		}
		return $sum;
	}

	private static function submit( $lang, array &$job ) {
		if ( ! empty( $job['hold'] ) ) {
			return; // held (e.g. waiting for new rules); results of in-flight batches are still collected
		}
		if ( ! $job['pending'] || count( $job['batches'] ) >= 2 ) {
			return; // at most two batches in flight per language
		}
		$take = array_slice( $job['pending'], 0, self::PER_REQ * self::MAX_REQS );
		$est  = count( $take ) * self::EST_PER_ST;
		// PER-JOB HARD CAP (approved per run). Spent so far + everything this
		// job still has in flight + this batch must stay under it; otherwise a
		// smaller batch that fits is sent, or nothing.
		if ( ! empty( $job['cap'] ) ) {
			$inflight = 0.0;
			foreach ( (array) $job['batches'] as $b ) { $inflight += (float) ( $b['est'] ?? 0 ); }
			$room = (float) $job['cap'] - (float) $job['stats']['cost'] - $inflight;
			if ( $est > $room ) {
				$fit  = (int) floor( max( 0, $room ) / self::EST_PER_ST );
				$fit -= $fit % self::PER_REQ;
				if ( $fit < self::PER_REQ ) {
					$job['stats']['note'] = sprintf( 'job cap reached: spent $%.2f + in flight $%.2f of cap $%.2f', $job['stats']['cost'], $inflight, $job['cap'] );
					return;
				}
				$take = array_slice( $take, 0, $fit );
				$est  = count( $take ) * self::EST_PER_ST;
			}
		}
		$lim  = ACWPT_Budget::monthly_limit();
		// Keep HEADROOM under the ceiling for live translation of new/edited
		// content: a bulk re-run must never be the thing that hits the cap.
		$head = (float) apply_filters( 'acwpt_batch_headroom', 15.0 );
		$base = ACWPT_Budget::spent_this_month() + self::$committed_live;
		if ( $lim > 0 && $base + $est >= $lim - $head ) {
			// Submit a smaller batch that fits, if any.
			$fit  = (int) floor( max( 0, $lim - $head - $base ) / self::EST_PER_ST );
			$fit  = $fit - ( $fit % self::PER_REQ );
			if ( $fit >= self::PER_REQ ) {
				$take = array_slice( $take, 0, $fit );
				$est  = count( $take ) * self::EST_PER_ST;
			}
		}
		if ( $lim > 0 && $base + $est >= $lim - $head ) {
			$job['stats']['note'] = sprintf( 'waiting for budget: $%.2f + est $%.2f would reach the $%.2f ceiling', ACWPT_Budget::spent_this_month(), $est, $lim );
			return;
		}
		$cfg    = self::settings();
		$system = ACWPT_Prompts::build_strings_prompt( $lang, $cfg['custom'] );
		$gloss  = ACWPT_Glossary::entries_for_language( $cfg['custom']['glossary'], $lang );
		$reqs   = array();
		$map    = array();
		foreach ( array_chunk( $take, self::PER_REQ ) as $n => $chunk ) {
			$indexed = array();
			foreach ( array_values( $chunk ) as $i => $s ) {
				$w = ACWPT_Glossary::apply_keep_sentinels( $s, $cfg['custom']['never_translate'] );
				$indexed[ (string) $i ] = ACWPT_Glossary::apply_glossary_sentinels( $w, $gloss );
			}
			$user = wp_json_encode( $indexed, JSON_UNESCAPED_UNICODE );
			$rows = ACWPT_Terms::relevant( $lang, $chunk );
			if ( $rows ) {
				$user = ACWPT_Terms::prompt_block( $rows ) . "\n\nSTRINGS TO TRANSLATE (JSON):\n" . $user;
			}
			$id          = $lang . '-' . substr( md5( implode( "\x1f", $chunk ) ), 0, 12 ) . '-' . $n;
			$map[ $id ]  = array_values( $chunk );
			$reqs[]      = array(
				'custom_id' => $id,
				'params'    => array(
					'model'       => $job['model'],
					'max_tokens'  => 8192,
					'temperature' => 0.3,
					'system'      => array( array( 'type' => 'text', 'text' => $system, 'cache_control' => array( 'type' => 'ephemeral' ) ) ),
					'messages'    => array( array( 'role' => 'user', 'content' => $user ) ),
				),
			);
		}
		$raw = self::http( 'POST', '/v1/messages/batches', array( 'requests' => $reqs ), 120 );
		if ( is_wp_error( $raw ) ) {
			$job['stats']['note'] = 'submit failed: ' . $raw->get_error_message();
			return;
		}
		$d = json_decode( $raw, true );
		if ( empty( $d['id'] ) ) {
			$job['stats']['note'] = 'submit: no batch id';
			return;
		}
		$job['batches'][ $d['id'] ] = array( 'map' => $map, 'submitted' => time(), 'est' => $est );
		self::$committed_live += $est;
		$job['pending']             = array_slice( $job['pending'], count( $take ) );
		$job['stats']['note']       = 'submitted ' . $d['id'] . ' (' . count( $take ) . ' strings)';
	}

	private static function poll( $lang, array &$job ) {
		foreach ( $job['batches'] as $bid => $b ) {
			$raw = self::http( 'GET', '/v1/messages/batches/' . rawurlencode( $bid ) );
			if ( is_wp_error( $raw ) ) {
				continue;
			}
			$st = json_decode( $raw, true );
			if ( 'ended' !== ( $st['processing_status'] ?? '' ) || empty( $st['results_url'] ) ) {
				continue;
			}
			$res = self::http( 'GET', '/v1/messages/batches/' . rawurlencode( $bid ) . '/results', null, 180 );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			foreach ( preg_split( '/\r?\n/', trim( (string) $res ) ) as $line ) {
				$r = json_decode( $line, true );
				if ( ! $r || empty( $r['custom_id'] ) || ! isset( $b['map'][ $r['custom_id'] ] ) ) {
					continue;
				}
				$src = $b['map'][ $r['custom_id'] ];
				if ( 'succeeded' !== ( $r['result']['type'] ?? '' ) ) {
					self::requeue( $job, $src );
					continue;
				}
				$msg = $r['result']['message'];
				$job['stats']['cost'] += self::cost( $msg, $job['model'] );
				$out = ACWPT_Translator::accept_batch_output( $src, $lang, $msg['content'][0]['text'] ?? '' );
				if ( is_wp_error( $out ) ) {
					self::requeue( $job, $src );
					continue;
				}
				if ( $out['ok'] ) {
					ACWPT_String_Store::set_many( $lang, $out['ok'] );
					$job['stats']['stored'] += count( $out['ok'] );
				}
				if ( $out['retry'] ) {
					self::requeue( $job, $out['retry'] );
				}
			}
			unset( $job['batches'][ $bid ] );
		}
	}

	/**
	 * Hold a language (no new submissions) and cancel its in-flight batches.
	 * Requests Anthropic already processed are still billed; poll() stores them
	 * when the batch ends. Unprocessed ones come back 'canceled' and are
	 * re-queued (held), so nothing is lost.
	 */
	public static function hold_and_cancel( $lang ) {
		$jobs = self::jobs();
		if ( empty( $jobs[ $lang ] ) ) {
			return array();
		}
		$jobs[ $lang ]['hold'] = true;
		$out = array();
		foreach ( array_keys( (array) $jobs[ $lang ]['batches'] ) as $bid ) {
			$r = self::http( 'POST', '/v1/messages/batches/' . rawurlencode( $bid ) . '/cancel', new stdClass() );
			$d = is_wp_error( $r ) ? null : json_decode( $r, true );
			$out[ $bid ] = is_wp_error( $r ) ? 'error: ' . $r->get_error_message() : ( ( $d['processing_status'] ?? '?' ) . ' ' . wp_json_encode( $d['request_counts'] ?? array() ) );
		}
		$jobs[ $lang ]['stats']['note'] = 'held: waiting for new rules';
		self::save( $jobs );
		return $out;
	}

	/** Release a hold. */
	public static function release( $lang ) {
		$jobs = self::jobs();
		if ( isset( $jobs[ $lang ] ) ) {
			unset( $jobs[ $lang ]['hold'] );
			self::save( $jobs );
		}
	}

	/** Requeue once; a string that fails twice is counted as failed and left on its old text. */
	private static function requeue( array &$job, array $src ) {
		foreach ( $src as $s ) {
			$h = md5( $s );
			if ( isset( $job['retried'][ $h ] ) ) {
				$job['stats']['failed']++;
				continue;
			}
			$job['retried'][ $h ] = 1;
			$job['pending'][]     = $s;
		}
	}

	/** Batch pricing: 50% of standard, on every token class; recorded against the monthly ceiling. */
	private static function cost( array $msg, $model ) {
		$u  = $msg['usage'] ?? array();
		$in = 0 === strpos( $model, 'claude-haiku' ) ? 1e-6 : 3e-6;
		$c  = ( ( $u['input_tokens'] ?? 0 ) * $in + ( $u['cache_creation_input_tokens'] ?? 0 ) * $in * 1.25
			+ ( $u['cache_read_input_tokens'] ?? 0 ) * $in * 0.1 + ( $u['output_tokens'] ?? 0 ) * $in * 5 ) * 0.5;
		ACWPT_Budget::record( $c );
		return $c;
	}
}
