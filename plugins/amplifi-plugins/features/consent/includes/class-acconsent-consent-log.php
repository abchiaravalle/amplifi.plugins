<?php
/**
 * amplifi.consent — consent log (server-side record of consent).
 *
 * GDPR Art. 7(1) requires the controller to DEMONSTRATE consent. Client
 * localStorage is not a defensible record (user-wipeable, forgeable). This class
 * owns the best-effort, server-side record: one row per consent event, with the
 * legally-significant facts STAMPED BY THE SERVER (timestamp, policy version,
 * catalog hash, GPC signal, hashed IP) so they are not client-supplied. Rows are
 * retained until the operator-configured retention window purges them (so it is
 * append-only in normal operation, not immutable).
 *
 * The client supplies only what it legitimately knows: the category choices. The
 * subject id is the server-issued first-party cookie (re-read server-side, never
 * trusted from the request body), and the record is only ATTRIBUTED to a visitor
 * when the POST carries a visitor-bound token matching that cookie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Log {

	const DB_VERSION_OPT = 'acconsent_db_version';
	const DB_VERSION     = '4';

	// Write-failure alerting (C4): when the server-side consent record starts
	// failing to write (e.g. a DB permission problem, disk full, a self-heal
	// that couldn't complete), the banner keeps showing to visitors but their
	// choices silently stop being recorded. Surface that loudly instead of
	// letting it fail silently forever.
	const ALERT_OPT       = 'acconsent_log_alert';
	const ALERT_THRESHOLD = 5;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'acconsent_log';
	}

	/**
	 * Create / upgrade the consent-log table. Called from activation and on a
	 * version-bump page load (maybe_upgrade).
	 */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			receipt_id CHAR(36) NOT NULL,
			jti VARCHAR(32) NULL DEFAULT NULL,
			visitor_id VARCHAR(64) NOT NULL DEFAULT '',
			event VARCHAR(20) NOT NULL DEFAULT '',
			categories TEXT NULL,
			legal_snapshot TEXT NULL,
			policy_version VARCHAR(40) NOT NULL DEFAULT '',
			catalog_hash VARCHAR(64) NOT NULL DEFAULT '',
			gpc TINYINT(1) NOT NULL DEFAULT 0,
			country VARCHAR(2) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT '',
			ip_hash VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			url VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			created_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_id (receipt_id),
			UNIQUE KEY jti (jti),
			KEY visitor_id (visitor_id),
			KEY created_gmt (created_gmt),
			KEY country (country)
		) {$charset};";
		dbDelta( $sql );

		// dbDelta reliably ADDS columns but is notoriously unreliable at adding a
		// new UNIQUE KEY to an EXISTING table (v2→v3 upgrade). The UNIQUE jti
		// index is our authoritative single-use guard, so verify it explicitly
		// and create it if missing. Guard against pre-existing duplicate jti rows
		// (there shouldn't be any — jti is new in v3 — but be safe) by only
		// adding the constraint when no duplicates exist; otherwise dedupe first.
		if ( self::has_column( 'jti' ) && ! self::has_index( 'jti' ) ) {
			// Remove any duplicate non-NULL jti rows, keeping the earliest id.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->query( "DELETE a FROM {$table} a INNER JOIN {$table} b ON a.jti = b.jti AND a.jti IS NOT NULL AND a.id > b.id" );
			// Re-check immediately before ALTER (a racing request may have added
			// it) and suppress errors so a lost race is quiet, not logged.
			if ( ! self::has_index( 'jti' ) ) {
				$prev = $wpdb->suppress_errors( true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY jti (jti)" );
				$wpdb->suppress_errors( $prev );
			}
		}

		// Only stamp the version as migrated once the schema is ACTUALLY in the
		// target shape. If the UNIQUE jti index couldn't be created (insufficient
		// privileges, engine quirk), leave the version unbumped so maybe_upgrade()
		// retries on a later request rather than silently degrading the
		// authoritative single-use guard to the non-atomic transient pre-check.
		if ( self::has_column( 'jti' ) && ! self::has_index( 'jti' ) ) {
			return;
		}
		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPT ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** True if the given index (key name) exists on the consent-log table. */
	private static function has_index( $key_name ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$found = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key_name ) );
		return ! empty( $found );
	}

	/**
	 * After a failed insert, decide whether it was a duplicate-jti collision (a
	 * replay) rather than a real DB fault. Prefers the locale-independent driver
	 * errno (1062 = ER_DUP_ENTRY); falls back to re-selecting the row by jti so a
	 * non-mysqli driver or a localized error message is still handled correctly.
	 */
	private static function is_duplicate_jti( $jti ) {
		global $wpdb;
		// mysqli path: $wpdb->dbh is a mysqli object with ->errno.
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
			if ( 1062 === (int) mysqli_errno( $wpdb->dbh ) ) {
				return true;
			}
		}
		// Fallback: the row exists now ⇒ a concurrent/earlier insert won the jti.
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE jti = %s", $jti ) ) > 0;
	}

	/** True if the given column exists on the consent-log table. */
	private static function has_column( $column ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$found = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		return ! empty( $found );
	}

	/**
	 * Record a consent event. Returns the full receipt array (server-stamped
	 * fields included) so the caller can echo it back to the client and/or
	 * forward it to the webhook.
	 *
	 * @param array $input  Client-supplied: visitor_id, event, categories[], source.
	 * @return array|WP_Error
	 */
	public static function record( $input ) {
		global $wpdb;

		$valid_events = array( 'grant', 'deny', 'update', 'withdraw', 'gpc' );
		$event        = isset( $input['event'] ) && in_array( $input['event'], $valid_events, true ) ? $input['event'] : 'update';

		// Categories: keep only known keys, coerce to bool. necessary is always true.
		$known      = array_keys( Amplifi_Consent_Store::categories() );
		$categories = array();
		foreach ( $known as $k ) {
			if ( 'necessary' === $k ) {
				$categories[ $k ] = true;
				continue;
			}
			$categories[ $k ] = ! empty( $input['categories'][ $k ] );
		}
		// CCPA §1798.121 "Limit the Use of Sensitive PI" assertion: this is
		// purely a RECORDED assertion of the right having been exercised (the
		// actual blocking is unconditional client-side per H2 point 8), folded
		// into the categories JSON blob as an extra key rather than a new
		// column, so no schema migration is needed for it.
		$categories['_sensitive_pi_limited'] = ! empty( $input['sensitive_pi_limited'] );

		$visitor_id = isset( $input['visitor_id'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', substr( (string) $input['visitor_id'], 0, 64 ) ) : '';
		$source     = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : 'banner';

		// Versions the visitor SAW: prefer the render-time values carried in the
		// signed token ($input['rendered']) over the server's CURRENT values, so a
		// cached/delayed POST attests to the policy/catalog the user actually saw.
		$rendered      = isset( $input['rendered'] ) && is_array( $input['rendered'] ) ? $input['rendered'] : array();
		$policy_ver    = isset( $rendered['policy_version'] ) ? (string) $rendered['policy_version'] : Amplifi_Consent_Store::policy_version();
		$catalog_hash  = isset( $rendered['catalog_hash'] ) ? (string) $rendered['catalog_hash'] : Amplifi_Consent_Store::catalog_hash();

		// SERVER-STAMPED fields — the auditable facts the client can't forge.
		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', true );
		$receipt   = array(
			'receipt_id'     => self::uuid4(),
			'visitor_id'     => $visitor_id,
			'event'          => $event,
			'categories'     => wp_json_encode( $categories ),
			'policy_version' => $policy_ver,
			'catalog_hash'   => $catalog_hash,
			'gpc'            => self::gpc_present() ? 1 : 0,
			'country'        => self::client_country(),
			'source'         => $source,
			'ip_hash'        => self::ip_hash(),
			'user_agent'     => self::capture_user_agent(),
			'url'            => self::referer_path(),
			'created_at'     => $now_local,
			'created_gmt'    => $now_gmt,
		);

		// Snapshot of the legal-doc versions live at consent time. Prefer the
		// render-time snapshot carried in the signed token (so a cached/delayed
		// POST attests to the policy texts the visitor ACTUALLY saw) and fall
		// back to the current snapshot for the nonce path. Stored in its OWN
		// column (not folded into categories) so an auditor can query exactly
		// which policy texts (Privacy v3, Terms v2…) applied to each receipt.
		$legal_snapshot = ( isset( $rendered['legal_snapshot'] ) && is_array( $rendered['legal_snapshot'] ) )
			? $rendered['legal_snapshot']
			: Amplifi_Consent_Store::legal_snapshot();

		$db_row                   = $receipt;
		$db_row['categories']     = wp_json_encode( $categories );
		$db_row['legal_snapshot'] = wp_json_encode( $legal_snapshot );

		// Single-use token id: stored in a UNIQUE column so a duplicate token can
		// NEVER produce a second row — the DB enforces it atomically on every
		// host (no object cache required). NULL for the nonce/legacy path (multiple
		// NULLs are allowed by a UNIQUE index), so those writes are unaffected.
		$jti = isset( $input['jti'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['jti'] ) : '';
		$has_jti_col = self::has_column( 'jti' );
		if ( $has_jti_col ) {
			$db_row['jti'] = ( '' !== $jti ) ? $jti : null;
		}

		// Self-heal: if a needed column OR the UNIQUE jti index is missing (plugin
		// updated but migration hasn't completed on a front-end-only request, or a
		// prior ALTER failed), run the upgrade and retry — never silently drop the
		// record, and never run without the authoritative single-use guard.
		if ( ! self::has_column( 'legal_snapshot' ) || ! $has_jti_col || ! self::has_index( 'jti' ) || ! self::has_column( 'country' ) ) {
			self::install();
			if ( ! isset( $db_row['jti'] ) && self::has_column( 'jti' ) ) {
				$db_row['jti'] = ( '' !== $jti ) ? $jti : null;
			}
		}

		// Suppress wpdb's error print so a duplicate-jti collision is handled
		// quietly (it's an expected replay, not a fault).
		$prev_suppress = $wpdb->suppress_errors( true );
		$ok = $wpdb->insert( self::table(), $db_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $prev_suppress );
		if ( false === $ok ) {
			// Duplicate UNIQUE key on jti = the token was already spent → replay.
			// Detect it ROBUSTLY: prefer the driver errno (1062 = ER_DUP_ENTRY),
			// which is locale-independent; only if errno is unavailable do we
			// confirm by re-selecting the row by jti. Never rely on the English
			// error-message text (it varies by lc_messages / driver wrapper).
			if ( '' !== $jti && self::is_duplicate_jti( $jti ) ) {
				return new WP_Error( 'acconsent_replay', 'Consent token already used.', array( 'status' => 409 ) );
			}
			// A genuine write failure (not an expected replay) — alert the admin
			// so a silently-failing consent record doesn't go unnoticed while the
			// banner keeps showing to visitors.
			self::note_write_failure( $wpdb->last_error );
			return new WP_Error( 'acconsent_db', 'Failed to write consent record.' );
		}

		// Successful write: clear any standing failure alert so a transient blip
		// doesn't keep the admin notice showing forever after the DB recovers.
		self::clear_failure_alert();

		// Decode categories back to an array for the return payload / webhook.
		$receipt['categories'] = $categories;
		$receipt['legal']      = $legal_snapshot;
		$receipt['id']         = (int) $wpdb->insert_id;
		return $receipt;
	}

	/**
	 * The referring page, with the QUERY STRING and fragment stripped. Full
	 * referer URLs can carry PII / UTM identifiers; data minimization (CPRA
	 * § 7002, GDPR Art. 5(1)(c)) says keep only the path we actually need.
	 */
	private static function referer_path() {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}
		$ref   = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $ref );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		return substr( $scheme . $parts['host'] . $path, 0, 255 );
	}

	/**
	 * Purge consent-log rows older than the configured retention window. No-op
	 * when retention_days is 0 (keep forever). Wired to a daily cron.
	 */
	public static function purge_expired() {
		$settings = Amplifi_Consent_Store::get_settings();
		$days     = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 0;
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cutoff ) );
	}

	/**
	 * Paginated read for the admin log viewer + CSV/JSON export and the DSAR
	 * lookup routes. Supports optional filters — visitor_id (exact),
	 * date_from/date_to (Y-m-d, inclusive), country (exact, case-insensitive)
	 * — built into a dynamic WHERE clause via $wpdb->prepare(). `limit` is
	 * capped at 5000 (was 1000) so a regulator/DSAR export of a busy site's
	 * full history doesn't require dozens of paginated requests.
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args( $args, array( 'limit' => 50, 'offset' => 0 ) );
		$limit  = max( 1, min( 5000, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );
		$table  = self::table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['visitor_id'] ) ) {
			$visitor_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', substr( (string) $args['visitor_id'], 0, 64 ) );
			if ( '' !== $visitor_id ) {
				$where[]  = 'visitor_id = %s';
				$params[] = $visitor_id;
			}
		}
		if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
			$where[]  = 'created_gmt >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
			$where[]  = 'created_gmt <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $args['country'] ) ) {
			$where[]  = 'country = %s';
			$params[] = strtoupper( substr( sanitize_text_field( (string) $args['country'] ), 0, 2 ) );
		}

		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Permanently delete every consent-log row for a given visitor_id. Used by
	 * the DSAR "delete this visitor's records" admin action and the matching
	 * REST route. Returns the number of rows deleted.
	 */
	public static function delete_by_visitor( $visitor_id ) {
		global $wpdb;
		$visitor_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', substr( (string) $visitor_id, 0, 64 ) );
		if ( '' === $visitor_id ) {
			return 0;
		}
		return (int) $wpdb->delete( self::table(), array( 'visitor_id' => $visitor_id ) );
	}

	/* ---------------- helpers ---------------- */

	private static function uuid4() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000, wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}

	public static function gpc_present() {
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'];
	}

	/**
	 * Privacy-preserving IP record. Modes (settings): 'hash' (default, salted
	 * SHA-256, non-reversible), 'truncate' (drop last octet/segment), 'none'.
	 */
	private static function ip_hash() {
		$settings = Amplifi_Consent_Store::get_settings();
		$mode     = isset( $settings['ip_mode'] ) ? $settings['ip_mode'] : 'truncate';
		if ( 'none' === $mode ) {
			return '';
		}
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' === $ip ) {
			return '';
		}
		if ( 'truncate' === $mode ) {
			if ( strpos( $ip, '.' ) !== false ) { // IPv4
				$parts = explode( '.', $ip );
				$parts[ count( $parts ) - 1 ] = '0';
				return implode( '.', $parts );
			}
			if ( strpos( $ip, ':' ) !== false ) { // IPv6 → keep /48
				$seg = explode( ':', $ip );
				return implode( ':', array_slice( $seg, 0, 3 ) ) . '::';
			}
			return '';
		}
		// hash (default) — salted with WP AUTH_SALT so it's site-unique, non-reversible.
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'acconsent';
		return hash( 'sha256', $ip . '|' . $salt );
	}

	/**
	 * Best-effort ISO-3166-1 alpha-2 country code from Cloudflare's
	 * CF-IPCountry header. Only trusted when the `trust_proxy` setting is on
	 * (mirroring the existing IP-trust gating pattern elsewhere in this
	 * plugin) — an untrusted origin can't be handed a spoofable geo header.
	 * Cloudflare's value can also be "XX" (unknown) or "T1" (Tor) for special
	 * cases; we don't over-validate, just cap at 2 chars.
	 */
	private static function client_country() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		$settings = Amplifi_Consent_Store::get_settings();
		if ( empty( $settings['trust_proxy'] ) ) {
			return '';
		}
		return strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
	}

	/**
	 * Lightweight regex-based extraction of browser name+major-version and OS
	 * family from a raw User-Agent string, for the 'minimal' ua_mode. Enough
	 * to debug a disputed consent (which browser/OS the visitor used) without
	 * retaining the full fingerprintable UA string.
	 */
	private static function minimize_user_agent( $ua ) {
		$browser = 'Unknown';
		$os      = 'Unknown';
		if ( preg_match( '/Edg\/([\d.]+)/', $ua, $m ) ) {
			$browser = 'Edge ' . strtok( $m[1], '.' );
		} elseif ( preg_match( '/OPR\/([\d.]+)/', $ua, $m ) ) {
			$browser = 'Opera ' . strtok( $m[1], '.' );
		} elseif ( preg_match( '/Chrome\/([\d.]+)/', $ua, $m ) && false === strpos( $ua, 'Edg/' ) ) {
			$browser = 'Chrome ' . strtok( $m[1], '.' );
		} elseif ( preg_match( '/Firefox\/([\d.]+)/', $ua, $m ) ) {
			$browser = 'Firefox ' . strtok( $m[1], '.' );
		} elseif ( preg_match( '/Version\/([\d.]+).*Safari/', $ua, $m ) ) {
			$browser = 'Safari ' . strtok( $m[1], '.' );
		}
		if ( preg_match( '/Windows NT ([\d.]+)/', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/Mac OS X/', $ua ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/Android/', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/iPhone|iPad|iOS/', $ua ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/CrOS/', $ua ) ) {
			$os = 'ChromeOS';
		} elseif ( preg_match( '/Linux/', $ua ) ) {
			$os = 'Linux';
		}
		return $browser . ' / ' . $os;
	}

	/**
	 * Capture the request User-Agent per the configured ua_mode setting:
	 * 'full' (raw string, truncated to 255 chars), 'minimal' (browser+OS only
	 * — the default; enough to debug a disputed consent without keeping the
	 * fingerprintable full string), or 'none' (don't store it at all).
	 */
	public static function capture_user_agent() {
		$settings = Amplifi_Consent_Store::get_settings();
		$mode     = isset( $settings['ua_mode'] ) ? $settings['ua_mode'] : 'minimal';
		if ( 'none' === $mode || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		$raw = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
		if ( 'full' === $mode ) {
			return $raw;
		}
		return self::minimize_user_agent( $raw );
	}

	/* ---------------- write-failure alerting (C4) ---------------- */

	/**
	 * Note a genuine consent-log write failure (NOT a duplicate-jti replay —
	 * those are expected and never alert). Accumulates a running count/first/
	 * last-failed-at/last-error in a single option, and — once the failure
	 * count reaches ALERT_THRESHOLD — emails the site admin, at most once per
	 * day, so a persistent DB problem is actually noticed instead of silently
	 * dropping consent records forever.
	 */
	private static function note_write_failure( $error_msg ) {
		$alert = get_option( self::ALERT_OPT, array() );
		$now   = time();
		$alert['count']           = isset( $alert['count'] ) ? ( (int) $alert['count'] + 1 ) : 1;
		$alert['first_failed_at'] = isset( $alert['first_failed_at'] ) ? $alert['first_failed_at'] : $now;
		$alert['last_failed_at']  = $now;
		$alert['last_error']      = substr( (string) $error_msg, 0, 500 );
		update_option( self::ALERT_OPT, $alert, false );
		if ( $alert['count'] >= self::ALERT_THRESHOLD ) {
			$last_emailed = isset( $alert['emailed_at'] ) ? (int) $alert['emailed_at'] : 0;
			if ( ( $now - $last_emailed ) > DAY_IN_SECONDS ) {
				self::send_failure_email( $alert );
				$alert['emailed_at'] = $now;
				update_option( self::ALERT_OPT, $alert, false );
			}
		}
	}

	/** Clear the standing failure alert once a write succeeds again. */
	private static function clear_failure_alert() {
		if ( get_option( self::ALERT_OPT, false ) ) {
			delete_option( self::ALERT_OPT );
		}
	}

	/** Email the site admin that consent-log writes have been failing. */
	private static function send_failure_email( $alert ) {
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] amplifi.consent: consent-log writes are failing', 'amplifi-consent' ), get_bloginfo( 'name' ) );
		$body    = sprintf(
			"The cookie-consent banner on %s is showing to visitors, but the server-side\nconsent record has failed to write %d time(s) since %s.\n\nThis means consent choices are NOT being recorded server-side right now.\n\nLast error: %s\n\nCheck amplifi.studio > Consent > Consent Log, and your database user's ALTER\nTABLE permission (the plugin self-heals its schema automatically when it can).",
			home_url(),
			$alert['count'],
			gmdate( 'Y-m-d H:i:s', $alert['first_failed_at'] ) . ' UTC',
			$alert['last_error']
		);
		wp_mail( $to, $subject, $body );
	}

	/** Current standing failure alert, or null when none is active. */
	public static function get_alert() {
		return get_option( self::ALERT_OPT, null );
	}
}
