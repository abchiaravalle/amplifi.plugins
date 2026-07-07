<?php
/**
 * amplifi.consent — REST API.
 *
 * Routes:
 *   GET  /config        Public, read-only consent config (categories, cookie
 *                       catalog, legal-doc links, GPC + versioning hints). No
 *                       secrets, no script bodies. The front-end engine reads
 *                       this; it also sets the first-party visitor cookie and
 *                       mints a fresh visitor-bound consent token.
 *   POST /consent       Records a consent EVENT to the server-side log (the
 *                       best-effort server-side record) and mirrors it to the
 *                       webhook. Requires a visitor-bound signed token (or a
 *                       wp_rest nonce for an unattributed write); rate-limited.
 *   GET  /export        Admin-only (manage_options). CSV/JSON export of the
 *                       consent log for DSAR / DPA / audit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Rest {

	const NS = 'amplifi-consent/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/consent', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'post_consent' ),
			'permission_callback' => '__return_true', // validated inside via nonce + rate limit.
		) );

		register_rest_route( self::NS, '/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_export' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// DSAR lookup/erasure — look up (or permanently delete) every
		// consent-log row for one visitor_id, for a real Data Subject Access
		// Request workflow without hand-crafting SQL/curl.
		register_rest_route( self::NS, '/visitor/(?P<visitor_id>[a-zA-Z0-9\-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_visitor' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_visitor' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
	}

	/**
	 * Public config. Intentionally excludes script bodies and the script
	 * inventory's labels are kept minimal. Exposes only what the front-end gate
	 * needs to render + decide.
	 */
	public static function get_config() {
		// L3: a lightweight, more-generous-than-/consent rate limit — this
		// endpoint is legitimately called on every uncached page load per
		// visitor, so the ceiling is high, but an unbounded GET is still an
		// amplification/DoS surface worth capping.
		if ( ! self::config_rate_ok() ) {
			return new WP_Error( 'acconsent_rate', 'Too many requests.', array( 'status' => 429 ) );
		}
		// This response sets a per-visitor cookie and mints a unique token, so it
		// MUST never be cached by a page cache / CDN (a stale token would 403 and
		// force a refresh for many visitors). Send explicit no-store headers.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		}

		$settings = Amplifi_Consent_Store::get_settings();

		// Cookies grouped by category (skip 'unclassified' — not disclosed until reviewed).
		$by_cat = array();
		foreach ( Amplifi_Consent_Store::get_cookies() as $c ) {
			if ( 'unclassified' === $c['category'] ) {
				continue;
			}
			$by_cat[ $c['category'] ][] = array(
				'name'        => $c['name'],
				'domain'      => $c['domain'],
				'duration'    => $c['duration'],
				'description' => $c['description'],
			);
		}

		// Set (or read) the first-party visitor cookie and bind the freshly
		// minted token to it. This endpoint is uncached, so it is the reliable
		// place to set the cookie even when the page HTML is full-page-cached.
		$vid = self::ensure_visitor_cookie();

		return rest_ensure_response( array(
			'enabled'        => (bool) $settings['enabled'],
			'consent_days'   => (int) $settings['consent_days'],
			'policy_version' => Amplifi_Consent_Store::policy_version(),
			'catalog_hash'   => Amplifi_Consent_Store::catalog_hash(),
			'gpc_enabled'    => (bool) $settings['gpc_enabled'],
			'categories'     => Amplifi_Consent_Store::categories(),
			'cookies'        => $by_cat,
			'legal'          => Amplifi_Consent_Store::legal_snapshot(),
			// A fresh consent token (NOT a wp_rest nonce). Page HTML can be
			// full-page-cached so its embedded token may expire; this uncached
			// endpoint mints a live one the client uses to post. It is BOUND to
			// the first-party visitor cookie (set above), so a recorded event
			// attests to a real browser the server issued an id to — not an
			// arbitrary client-asserted visitor_id. Safe to serve publicly: it
			// only authorizes consent writes, not CSRF actions.
			'token'          => self::issue_token( $vid ),
		) );
	}

	/**
	 * Record a consent event server-side, then mirror to the webhook. This is
	 * the best-effort server-side record that helps demonstrate consent (GDPR
	 * Art. 7(1)) — server-stamped and, for attributed writes, visitor-bound.
	 */
	public static function post_consent( $request ) {
		$params  = $request->get_json_params();

		// AUTH: a consent write REQUIRES a server-issued, visitor-bound, single-
		// use signed token (carried in the body so a sendBeacon unload-fallback
		// can authenticate). We intentionally do NOT accept a bare wp_rest nonce:
		// that nonce is published to every anonymous visitor, so accepting it
		// would let a scraper write log rows that bypass visitor-binding, single-
		// use, and render-version binding — polluting the very record meant to be
		// proof. A first-time visitor's page-render token is unbound; the JS
		// upgrades it to a bound token via /config (which sets the cookie) BEFORE
		// posting, so legitimate clients always present a bound token here.
		$token      = isset( $params['token'] ) ? (string) $params['token'] : '';
		$token_data = $token ? self::verify_token( $token ) : false;
		if ( ! $token_data ) {
			return new WP_Error( 'acconsent_auth', 'Invalid or missing consent token.', array( 'status' => 403 ) );
		}

		// The authoritative visitor id is the first-party cookie the server set —
		// NEVER the client-supplied field (that would let anyone fabricate a
		// record for an arbitrary subject). The token MUST be visitor-bound
		// (carries `vh`) and its hash MUST match the cookie now presented: this
		// proves the record belongs to the browser the server issued an id to,
		// and a token minted for browser A can't be replayed for browser B.
		$cookie_vid  = self::read_visitor_cookie();
		$expected_vh = $cookie_vid ? self::visitor_hash( $cookie_vid ) : '';
		if ( empty( $token_data['vh'] ) || ! $expected_vh || ! hash_equals( (string) $token_data['vh'], $expected_vh ) ) {
			return new WP_Error( 'acconsent_vid', 'Consent token not bound to this visitor.', array( 'status' => 403 ) );
		}
		// Subject id is ALWAYS the server-issued first-party cookie (guaranteed
		// non-empty here: the vh check above passed, which requires the cookie).
		$visitor = $cookie_vid;

		// Rate-limit ALWAYS on IP (a client-chosen visitor_id alone is forgeable
		// and rotatable); the visitor id only adds per-visitor granularity.
		// L2: checked BEFORE jti consumption below, so a rate-limited request
		// never burns a legitimate single-use token — a client that gets 429'd
		// can retry with the SAME token instead of losing it to the rate limit.
		if ( ! self::rate_ok( $visitor ) ) {
			return new WP_Error( 'acconsent_rate', 'Too many requests.', array( 'status' => 429 ) );
		}

		// SINGLE-USE pre-filter: a fast cache/transient check rejects an
		// obvious replay before we do any work. The DB UNIQUE key on `jti`
		// (below, in record()) is the AUTHORITATIVE atomic guard — it holds
		// even with no object cache and under true concurrency — so this is
		// only an optimization, not the security boundary. Burn AFTER the vh
		// AND rate-limit checks so neither a mismatched-cookie attempt nor a
		// rate-limited request can waste a legit token.
		if ( isset( $token_data['j'] ) && ! self::consume_jti( $token_data['j'] ) ) {
			return new WP_Error( 'acconsent_replay', 'Consent token already used.', array( 'status' => 409 ) );
		}

		// Versions AND legal docs the visitor actually SAW come from the signed
		// token (render time), not the server's current values — so the receipt
		// can't drift on a cached/delayed POST.
		$render_versions = array();
		if ( isset( $token_data['pv'] ) ) { $render_versions['policy_version'] = (string) $token_data['pv']; }
		if ( isset( $token_data['ch'] ) ) { $render_versions['catalog_hash'] = (string) $token_data['ch']; }
		if ( isset( $token_data['ls'] ) && is_array( $token_data['ls'] ) ) { $render_versions['legal_snapshot'] = $token_data['ls']; }

		$receipt = Amplifi_Consent_Log::record( array(
			'visitor_id'            => $visitor,
			'event'                 => isset( $params['event'] ) ? $params['event'] : 'update',
			'categories'            => isset( $params['categories'] ) ? (array) $params['categories'] : array(),
			'source'                => isset( $params['source'] ) ? $params['source'] : 'banner',
			'rendered'              => $render_versions,
			'jti'                   => isset( $token_data['j'] ) ? (string) $token_data['j'] : '',
			// CCPA §1798.121 "Limit the Use of Sensitive PI" assertion (H2
			// point 9/10) — a purely recorded assertion the right was exercised.
			'sensitive_pi_limited'  => ! empty( $params['sensitive_pi_limited'] ),
		) );

		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}

		// Mirror to webhook (non-blocking, after the DB write).
		if ( class_exists( 'Amplifi_Consent_Webhook' ) ) {
			Amplifi_Consent_Webhook::dispatch( $receipt );
		}

		return rest_ensure_response( array(
			'ok'             => true,
			'receipt_id'     => $receipt['receipt_id'],
			'visitor_id'     => $receipt['visitor_id'],
			'policy_version' => $receipt['policy_version'],
			'catalog_hash'   => $receipt['catalog_hash'],
			'recorded_at'    => $receipt['created_gmt'],
		) );
	}

	/**
	 * Admin-only consent-log export. ?format=csv (default) or json. Supports
	 * ?visitor_id=, ?date_from=, ?date_to= (Y-m-d), ?country=, and ?per_page=
	 * (default 1000, capped 5000) so a DSAR / regulator export of a busy
	 * site's full history doesn't require dozens of paginated requests.
	 */
	public static function get_export( $request ) {
		$format   = $request->get_param( 'format' );
		$per_page = $request->get_param( 'per_page' );
		$rows     = Amplifi_Consent_Log::query( array(
			'limit'      => $per_page ? max( 1, min( 5000, (int) $per_page ) ) : 1000,
			'offset'     => max( 0, (int) $request->get_param( 'offset' ) ),
			'visitor_id' => $request->get_param( 'visitor_id' ),
			'date_from'  => $request->get_param( 'date_from' ),
			'date_to'    => $request->get_param( 'date_to' ),
			'country'    => $request->get_param( 'country' ),
		) );

		if ( 'json' === $format ) {
			return rest_ensure_response( array( 'count' => count( $rows ), 'rows' => $rows ) );
		}

		// CSV.
		$cols = array( 'id', 'receipt_id', 'visitor_id', 'event', 'categories', 'legal_snapshot', 'policy_version', 'catalog_hash', 'gpc', 'country', 'source', 'ip_hash', 'user_agent', 'url', 'created_gmt' );
		$out  = implode( ',', $cols ) . "\n";
		foreach ( (array) $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$v      = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
				$line[] = self::csv_cell( $v );
			}
			$out .= implode( ',', $line ) . "\n";
		}
		$resp = new WP_REST_Response( $out );
		$resp->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$resp->header( 'Content-Disposition', 'attachment; filename="acconsent-log.csv"' );
		return $resp;
	}

	/**
	 * Admin-only DSAR lookup: every consent-log row for one visitor_id.
	 */
	public static function get_visitor( $request ) {
		$visitor_id = $request->get_param( 'visitor_id' );
		$rows       = Amplifi_Consent_Log::query( array( 'visitor_id' => $visitor_id, 'limit' => 1000 ) );
		return rest_ensure_response( array(
			'visitor_id' => $visitor_id,
			'count'      => count( $rows ),
			'rows'       => $rows,
		) );
	}

	/**
	 * Admin-only DSAR erasure: permanently delete every consent-log row for
	 * one visitor_id. Returns the number of rows deleted.
	 */
	public static function delete_visitor( $request ) {
		$n = Amplifi_Consent_Log::delete_by_visitor( $request->get_param( 'visitor_id' ) );
		return rest_ensure_response( array( 'deleted' => $n ) );
	}

	/**
	 * Quote a CSV cell AND neutralize spreadsheet formula injection: a cell that
	 * begins with = + - @ (or tab/CR) is executed as a formula by Excel/Sheets,
	 * so a tracker-set User-Agent like "=cmd|..." could run on an admin's machine
	 * when they open the export. Prefix such cells with a single quote.
	 */
	private static function csv_cell( $v ) {
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "	", "\r" ), true ) ) {
			$v = "'" . $v;
		}
		return '"' . str_replace( '"', '""', $v ) . '"';
	}

	/**
	 * Rate limit ALWAYS on the (attacker-uncontrollable) IP, with an additional
	 * tighter per-visitor sub-limit. A client-chosen visitor_id can be rotated to
	 * dodge a per-visitor-only limit, so the IP ceiling is the real backstop.
	 */
	private static function rate_ok( $visitor = '' ) {
		$ip = self::client_ip();
		if ( ! self::bump( 'ip_' . md5( $ip ), 120 ) ) {
			return false; // hard IP ceiling (shared NAT/CDN tolerant, abuse-proof).
		}
		$visitor = preg_replace( '/[^a-zA-Z0-9\-]/', '', substr( (string) $visitor, 0, 64 ) );
		if ( '' !== $visitor && ! self::bump( 'v_' . $visitor, 20 ) ) {
			return false; // tighter per-visitor sub-limit.
		}
		return true;
	}

	/**
	 * L3: a lightweight, more-generous-than-/consent rate limit for GET
	 * /config. This endpoint is legitimately called on every uncached page
	 * load per visitor (it sets the visitor cookie + mints a fresh token), so
	 * the ceiling is high — this only guards against an unbounded flood.
	 */
	private static function config_rate_ok() {
		return self::bump( 'cfg_ip_' . md5( self::client_ip() ), 300 ); // generous: legitimately called on every uncached page load per visitor.
	}

	/**
	 * Best-effort client IP. Behind a trusted reverse proxy / CDN (Cloudflare,
	 * nginx) REMOTE_ADDR is the proxy, so every visitor would share one rate
	 * bucket. When the `trust_proxy` setting is on, prefer the left-most public
	 * address in X-Forwarded-For (or CF-Connecting-IP). OFF by default because
	 * XFF is client-spoofable on a direct-connect origin.
	 */
	private static function client_ip() {
		$remote   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$settings = Amplifi_Consent_Store::get_settings();
		if ( empty( $settings['trust_proxy'] ) ) {
			return $remote;
		}
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$first = trim( $parts[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return $remote;
	}

	/**
	 * Increment a per-minute counter for $bucket; false once it reaches $max.
	 * Uses an ATOMIC object-cache increment when a persistent cache is present
	 * (so concurrent requests can't interleave read-modify-write and overshoot
	 * the ceiling). Falls back to the transient read/modify/write otherwise —
	 * still effective against the serial floods this guards against.
	 */
	private static function bump( $bucket, $max ) {
		$key = 'acconsent_rl_' . md5( $bucket );

		// Atomic path: wp_cache_add seeds the counter once (atomic), then
		// wp_cache_incr atomically bumps it. Only meaningful with a persistent
		// object cache; on the default non-persistent cache this still works
		// within a request but offers no cross-process atomicity, so we only
		// trust it when wp_using_ext_object_cache() is true.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_incr' ) && function_exists( 'wp_cache_add' ) ) {
			$group = 'acconsent_rl';
			wp_cache_add( $key, 0, $group, MINUTE_IN_SECONDS );
			$n = wp_cache_incr( $key, 1, $group );
			if ( false === $n ) {
				// Key expired between add and incr — reseed.
				wp_cache_set( $key, 1, $group, MINUTE_IN_SECONDS );
				$n = 1;
			}
			return ( (int) $n ) <= $max;
		}

		// Transient fallback (no persistent cache).
		$n = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/* ---------------- consent token (proof a real page render occurred) ---------------- */

	/**
	 * A short-lived signed token issued at page render (or by /config) and
	 * required (or a wp_rest nonce) to write a consent event. It BINDS:
	 *   - pv/ch : the policy_version + catalog_hash live at render time,
	 *   - ls    : the legal-doc snapshot live at render time,
	 *   - vh    : a hash of the first-party visitor cookie,
	 * so the recorded receipt attests to exactly what the visitor saw AND to the
	 * server-issued subject id — none of it can drift or be spoofed on a
	 * (possibly cached/delayed) POST. Format: base64url( payloadJson . '.' .
	 * hmac ). Safe to serve publicly.
	 *
	 * @param string $vid Visitor cookie value to bind (empty = unbound token).
	 */
	public static function issue_token( $vid = '' ) {
		$payload = array(
			't'  => time(),
			'j'  => self::random_jti(), // unique id → single-use enforcement.
			'pv' => Amplifi_Consent_Store::policy_version(),
			'ch' => Amplifi_Consent_Store::catalog_hash(),
			'ls' => Amplifi_Consent_Store::legal_snapshot(),
		);
		if ( '' !== $vid ) {
			$payload['vh'] = self::visitor_hash( $vid );
		}
		$payload = wp_json_encode( $payload );
		$sig = hash_hmac( 'sha256', $payload, self::token_secret() );
		return rtrim( strtr( base64_encode( $payload . '.' . $sig ), '+/', '-_' ), '=' );
	}

	/** A random, URL-safe token id used to enforce single-use (anti-replay). */
	private static function random_jti() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 20, false, false );
		}
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );
	}

	/**
	 * Verify a token's signature + freshness. Returns the decoded payload array
	 * (['t','j','pv','ch','ls','vh'] — vh optional) on success, or false. Does NOT
	 * consume the token — call consume_jti() separately once all checks pass so a
	 * failed validation doesn't burn a legitimate token.
	 */
	public static function verify_token( $token ) {
		$raw = base64_decode( strtr( (string) $token, '-_', '+/' ), true );
		if ( ! $raw || false === strrpos( $raw, '.' ) ) {
			return false;
		}
		$pos     = strrpos( $raw, '.' );
		$payload = substr( $raw, 0, $pos );
		$sig     = substr( $raw, $pos + 1 );
		$expected = hash_hmac( 'sha256', $payload, self::token_secret() );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return false;
		}
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || ! isset( $data['t'] ) || ! ctype_digit( (string) $data['t'] ) ) {
			return false;
		}
		// 2h validity window — long enough for a reasonably-cached page to still
		// post, short enough to bound replay of a captured token. (The token is
		// also visitor-bound AND single-use, so a captured token is only usable
		// once, from the same browser cookie.)
		if ( ( time() - (int) $data['t'] ) > ( 2 * HOUR_IN_SECONDS ) || (int) $data['t'] > ( time() + 300 ) ) {
			return false;
		}
		return $data;
	}

	/**
	 * Single-use enforcement: mark a token's jti consumed and return true the
	 * FIRST time, false on any replay. We only need to remember a jti for the
	 * token's validity window (after that verify_token rejects it on age). With a
	 * persistent object cache, wp_cache_add gives ATOMIC first-writer-wins so two
	 * concurrent POSTs of one token can't both succeed; the transient is the
	 * (non-atomic) fallback when no persistent cache is present. A token with no
	 * jti (legacy) can't be tracked, so we allow it — vh binding still applies.
	 */
	private static function consume_jti( $jti ) {
		$jti = preg_replace( '/[^A-Za-z0-9]/', '', (string) $jti );
		if ( '' === $jti ) {
			return true; // no jti to track (legacy token); rely on vh + freshness.
		}
		$key = 'acconsent_jti_' . $jti;
		// TTL covers the full token age-validity (2h) PLUS the future-clock-skew
		// allowance (+300s) so a skewed-cluster replay can't reopen after the
		// marker expires but while the token is still age-valid, +1min slack.
		$ttl = 2 * HOUR_IN_SECONDS + 300 + MINUTE_IN_SECONDS;

		// Atomic path: wp_cache_add returns FALSE if the key already exists, so a
		// concurrent second POST of the same token loses the race and is rejected.
		// Only trustworthy with a persistent object cache (cross-process).
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_add' ) ) {
			return (bool) wp_cache_add( $key, 1, 'acconsent_jti', $ttl );
		}

		// Transient fallback (no persistent cache): serial-replay protection only.
		if ( get_transient( $key ) ) {
			return false; // already used → replay.
		}
		set_transient( $key, 1, $ttl );
		return true;
	}

	private static function token_secret() {
		$secret = get_option( 'acconsent_token_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'acconsent_token_secret', $secret, false );
		}
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		return $secret . '|' . $salt;
	}

	/* ---------------- first-party visitor binding ---------------- */

	const VID_COOKIE = 'acconsent_vid';

	/**
	 * Read the first-party visitor id from its cookie, sanitized. Empty when the
	 * browser hasn't been issued one yet (or blocks cookies).
	 */
	public static function read_visitor_cookie() {
		if ( empty( $_COOKIE[ self::VID_COOKIE ] ) ) {
			return '';
		}
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', substr( (string) $_COOKIE[ self::VID_COOKIE ], 0, 64 ) );
	}

	/**
	 * Ensure the visitor has a first-party id cookie and return it. Issues a new
	 * random id (and sends the Set-Cookie) when absent. The cookie is the SERVER's
	 * attestation that this browser exists — the token binds to it so a recorded
	 * consent event can't be attributed to an arbitrary client-asserted id. Set
	 * from the uncached /config endpoint so full-page caching can't suppress it.
	 */
	public static function ensure_visitor_cookie() {
		$vid = self::read_visitor_cookie();
		if ( '' !== $vid ) {
			return $vid;
		}
		$vid = wp_generate_uuid4();
		// 1-year, lax, secure-when-https, HttpOnly. The server is the only party
		// that needs this id (it re-reads the cookie on each POST); the JS never
		// needs to read it, so HttpOnly closes a cross-script/XSS exfiltration
		// path that could otherwise let an attacker mint a token bound to a
		// victim's cookie value.
		$secure = is_ssl();
		if ( ! headers_sent() ) {
			setcookie( self::VID_COOKIE, $vid, array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			) );
			$_COOKIE[ self::VID_COOKIE ] = $vid; // available within this request.
		}
		return $vid;
	}

	/** Keyed hash binding a token to a specific visitor cookie (HMAC, truncated). */
	private static function visitor_hash( $vid ) {
		return substr( hash_hmac( 'sha256', 'vid|' . $vid, self::token_secret() ), 0, 32 );
	}
}
