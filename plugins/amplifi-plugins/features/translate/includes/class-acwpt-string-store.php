<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent, unbounded store for short string translations.
 *
 * Replaces the previous storage model, which kept every translated string for a
 * language in a single `acwpt_strings_{lang}` option capped at 500 entries. That
 * cap silently evicted the oldest entries via array_slice(), so on any site with
 * more than ~500 distinct strings the evicted entries were re-translated — and
 * re-billed — the next time a page needed them.
 *
 * Storage is one row per (language, source string), keyed by an MD5 of the source
 * so lookups are indexed rather than loading an entire serialized option.
 *
 * Table: {$wpdb->prefix}acwpt_strings
 */
class ACWPT_String_Store {

	const DB_VERSION        = '3.4.0';
	const DB_VERSION_OPTION = 'acwpt_strings_db_version';

	/**
	 * In-memory read cache, keyed by language then source hash, so a single
	 * request never queries the same string twice.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $memo = array();

	// =========================================================================
	// Schema
	// =========================================================================

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'acwpt_strings';
	}

	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			language VARCHAR(10) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			source_text TEXT NOT NULL,
			translated_text TEXT NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY lang_hash (language, source_hash),
			KEY language (language)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Self-heal: create the table if a stored DB version is missing or stale.
	 * Cheap enough to call on load because it short-circuits on an option read.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_table();
		self::migrate_from_options();
	}

	/**
	 * One-time back-fill of the legacy `acwpt_strings_{lang}` options into the
	 * table. Non-destructive: the legacy options are left in place so a rollback
	 * to a previous plugin version still finds its data.
	 *
	 * @return int Number of strings migrated.
	 */
	public static function migrate_from_options() {
		if ( ! class_exists( 'ACWPT_Languages' ) ) {
			return 0;
		}

		$migrated = 0;
		$codes    = array_keys( ACWPT_Languages::get_all() );

		foreach ( $codes as $lang ) {
			$legacy = get_option( 'acwpt_strings_' . $lang, null );
			if ( ! is_array( $legacy ) || empty( $legacy ) ) {
				continue;
			}

			$pairs = array();
			foreach ( $legacy as $source => $translated ) {
				if ( '_populated_at' === $source ) {
					continue;
				}
				if ( ! is_string( $source ) || ! is_string( $translated ) || '' === $translated ) {
					continue;
				}
				$pairs[ $source ] = $translated;
			}

			if ( $pairs ) {
				$migrated += self::set_many( $lang, $pairs );
			}
		}

		return $migrated;
	}

	// =========================================================================
	// Reads
	// =========================================================================

	/**
	 * Look up many source strings at once.
	 *
	 * @param string   $language Language code.
	 * @param string[] $sources  Source strings.
	 * @return array<string,string> Map of source => translation, only for hits.
	 */
	public static function get_many( $language, array $sources ) {
		global $wpdb;

		$found = array();
		if ( empty( $sources ) ) {
			return $found;
		}

		$sources = array_values( array_unique( $sources ) );
		$lookup  = array();
		$need    = array();

		foreach ( $sources as $s ) {
			$h = md5( $s );
			$lookup[ $h ] = $s;

			if ( isset( self::$memo[ $language ][ $h ] ) ) {
				$found[ $s ] = self::$memo[ $language ][ $h ];
				continue;
			}
			$need[] = $h;
		}

		if ( empty( $need ) ) {
			return $found;
		}

		$table = self::table_name();

		// Chunk the IN() clause so a huge page cannot build an oversized query.
		foreach ( array_chunk( $need, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$params       = array_merge( array( $language ), $chunk );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT source_hash, translated_text FROM {$table} WHERE language = %s AND source_hash IN ({$placeholders})",
					$params
				)
			);

			if ( ! $rows ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! isset( $lookup[ $row->source_hash ] ) ) {
					continue;
				}
				$source                                       = $lookup[ $row->source_hash ];
				$found[ $source ]                             = $row->translated_text;
				self::$memo[ $language ][ $row->source_hash ] = $row->translated_text;
			}
		}

		return $found;
	}

	/**
	 * Look up a single string.
	 *
	 * @return string|null Translation, or null on a miss.
	 */
	public static function get( $language, $source ) {
		$hit = self::get_many( $language, array( $source ) );
		return isset( $hit[ $source ] ) ? $hit[ $source ] : null;
	}

	/**
	 * Which of these sources are NOT yet translated?
	 *
	 * @return string[] The missing source strings, in input order.
	 */
	public static function missing( $language, array $sources ) {
		$have    = self::get_many( $language, $sources );
		$missing = array();
		foreach ( array_unique( $sources ) as $s ) {
			if ( ! isset( $have[ $s ] ) ) {
				$missing[] = $s;
			}
		}
		return $missing;
	}

	// =========================================================================
	// Writes
	// =========================================================================

	/**
	 * Upsert many translations in one statement.
	 *
	 * @param string               $language Language code.
	 * @param array<string,string> $pairs    Map of source => translation.
	 * @return int Rows written.
	 */
	public static function set_many( $language, array $pairs ) {
		global $wpdb;

		if ( empty( $pairs ) ) {
			return 0;
		}

		$table   = self::table_name();
		$written = 0;

		foreach ( array_chunk( $pairs, 200, true ) as $chunk ) {
			$values = array();
			$params = array();

			foreach ( $chunk as $source => $translated ) {
				$source     = (string) $source;
				$translated = (string) $translated;
				if ( '' === $source || '' === $translated ) {
					continue;
				}
				$values[] = '(%s, %s, %s, %s)';
				$hash     = md5( $source );
				array_push( $params, $language, $hash, $source, $translated );

				self::$memo[ $language ][ $hash ] = $translated;
			}

			if ( empty( $values ) ) {
				continue;
			}

			$sql = "INSERT INTO {$table} (language, source_hash, source_text, translated_text) VALUES "
				. implode( ', ', $values )
				. ' ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), updated_at = CURRENT_TIMESTAMP';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$res = $wpdb->query( $wpdb->prepare( $sql, $params ) );
			if ( false !== $res ) {
				$written += count( $values );
			}
		}

		return $written;
	}

	public static function set( $language, $source, $translated ) {
		return self::set_many( $language, array( $source => $translated ) );
	}

	// =========================================================================
	// Maintenance
	// =========================================================================

	/**
	 * @return int Number of stored strings for a language (all languages if null).
	 */
	public static function count( $language = null ) {
		global $wpdb;
		$table = self::table_name();

		if ( null === $language ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE language = %s", $language )
		);
	}

	/**
	 * Delete every stored string for one language (or all languages).
	 */
	public static function flush( $language = null ) {
		global $wpdb;
		$table = self::table_name();

		if ( null === $language ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
			self::$memo = array();
			return;
		}

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$table} WHERE language = %s", $language )
		);
		unset( self::$memo[ $language ] );
	}

	/**
	 * Drop the in-memory read cache. Used by long-running CLI loops so memory
	 * does not grow without bound across many pages.
	 */
	public static function reset_memo() {
		self::$memo = array();
	}

	public static function stats() {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT language, COUNT(*) AS n, MAX(updated_at) AS last_updated FROM {$table} GROUP BY language"
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->language ] = array(
				'strings'      => (int) $r->n,
				'last_updated' => $r->last_updated,
			);
		}
		return $out;
	}
}
