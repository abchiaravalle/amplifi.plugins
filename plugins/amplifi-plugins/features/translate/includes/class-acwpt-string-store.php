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

	const DB_VERSION        = '3.5.0';
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
			prompt_version VARCHAR(12) NOT NULL DEFAULT '',
			source_text TEXT NOT NULL,
			translated_text TEXT NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY lang_hash (language, source_hash),
			KEY language (language),
			KEY lang_ver (language, prompt_version)
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
	 * Prompt fingerprint for the language, resolved once per request.
	 */
	private static function current_prompt_version( $language ) {
		static $memo = array();
		if ( isset( $memo[ $language ] ) ) {
			return $memo[ $language ];
		}
		if ( ! class_exists( 'ACWPT_Prompts' ) ) {
			return '';
		}

		$s      = get_option( 'acwpt_settings', array() );
		$custom = array(
			'never_translate'     => (array) ( $s['never_translate'] ?? array() ),
			'glossary'            => (array) ( $s['glossary'] ?? array() ),
			'custom_instructions' => (array) ( $s['custom_instructions'] ?? array() ),
		);

		$memo[ $language ] = ACWPT_Prompts::prompt_version( $language, $custom );
		return $memo[ $language ];
	}

	/**
	 * Source strings whose translation predates the current prompt.
	 *
	 * These are STALE, not wrong: they keep serving so the page never falls
	 * back to English, while the queue re-translates them at the new version.
	 * That is what makes a prompt improvement shippable across a fleet without
	 * flushing and re-buying every site.
	 *
	 * @param string $language
	 * @param int    $limit
	 * @return string[] Source texts needing refresh.
	 */
	public static function stale_sources( $language, $limit = 200 ) {
		global $wpdb;

		$version = self::current_prompt_version( $language );
		if ( '' === $version ) {
			return array();
		}

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT source_text FROM ' . self::table_name()
				. ' WHERE language = %s AND prompt_version <> %s LIMIT %d',
				$language,
				$version,
				(int) $limit
			)
		);
	}

	/**
	 * How many stored rows are behind the current prompt.
	 */
	public static function stale_count( $language ) {
		global $wpdb;

		$version = self::current_prompt_version( $language );
		if ( '' === $version ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table_name()
				. ' WHERE language = %s AND prompt_version <> %s',
				$language,
				$version
			)
		);
	}

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
				$values[] = '(%s, %s, %s, %s, %s)';
				$hash     = md5( $source );
				array_push( $params, $language, $hash, $source, $translated, self::current_prompt_version( $language ) );

				self::$memo[ $language ][ $hash ] = $translated;
			}

			if ( empty( $values ) ) {
				continue;
			}

			$sql = "INSERT INTO {$table} (language, source_hash, source_text, translated_text, prompt_version) VALUES "
				. implode( ', ', $values )
				. ' ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), prompt_version = VALUES(prompt_version), updated_at = CURRENT_TIMESTAMP';

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
	/**
	 * Retire one source string across every language.
	 *
	 * The precise alternative to flushing the store: used when a specific piece
	 * of source text changes (site title, tagline) rather than the whole site.
	 *
	 * @param string $source Source text to forget.
	 * @return int Rows removed.
	 */
	public static function forget( $source ) {
		global $wpdb;
		$table = self::table_name();

		$deleted = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE source_hash = %s", md5( $source ) )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::reset_memo();
		return $deleted;
	}

	public static function reset_memo() {
		self::$memo = array();
	}

	// =====================================================================
	// Change tracking
	//
	// Editing a post must NOT discard the site's translations. These methods
	// let a save record "this post's rendered output may have changed" so a
	// background worker can reconcile just that page, instead of the editor's
	// request nuking a store that cost real money to build.
	// =====================================================================

	const DIRTY_OPTION = 'acwpt_dirty_posts';

	/**
	 * Record that a post's rendered output may have changed.
	 *
	 * Deliberately cheap: one option write, no rendering, no API calls. The
	 * expensive reconciliation happens on cron.
	 *
	 * @param int $post_id
	 */
	public static function mark_post_dirty( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$dirty = get_option( self::DIRTY_OPTION, array() );
		if ( ! is_array( $dirty ) ) {
			$dirty = array();
		}

		$dirty[ $post_id ] = time();
		update_option( self::DIRTY_OPTION, $dirty, false );
	}

	/**
	 * @return int[] Post IDs awaiting reconciliation.
	 */
	public static function dirty_posts() {
		$dirty = get_option( self::DIRTY_OPTION, array() );
		return is_array( $dirty ) ? array_map( 'intval', array_keys( $dirty ) ) : array();
	}

	/**
	 * @param int $post_id Post that has been reconciled.
	 */
	public static function clear_post_dirty( $post_id ) {
		$dirty = get_option( self::DIRTY_OPTION, array() );
		if ( ! is_array( $dirty ) ) {
			return;
		}
		unset( $dirty[ (int) $post_id ] );
		update_option( self::DIRTY_OPTION, $dirty, false );
	}

	/**
	 * Per-language translation status for a set of posts.
	 *
	 * Answers "which pages have changed since we last translated them?" by
	 * comparing each post's CURRENT content hash against the hash stored with
	 * its translation — the same test the renderer uses, so the report cannot
	 * disagree with what the site actually serves.
	 *
	 * @param string[] $languages
	 * @param int[]    $post_ids  Omit to cover every configured post type.
	 * @return array<int,array> Keyed by post ID.
	 */
	public static function translation_status( array $languages, array $post_ids = array() ) {
		global $wpdb;

		if ( ! $post_ids ) {
			$post_ids = get_posts( array(
				'post_type'      => function_exists( 'acwpt_post_types' ) ? acwpt_post_types() : array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
		}

		if ( ! $post_ids ) {
			return array();
		}

		$cache_table = $wpdb->prefix . 'acwpt_translations';
		$ids_sql     = implode( ',', array_map( 'intval', $post_ids ) );

		$rows = $wpdb->get_results(
			"SELECT post_id, language, content_hash, updated_at
			   FROM {$cache_table}
			  WHERE post_id IN ({$ids_sql})"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$have = array();
		foreach ( $rows as $r ) {
			$have[ (int) $r->post_id ][ $r->language ] = $r;
		}

		$out = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$current = ACWPT_Preloader::content_hash( $post );
			$entry   = array(
				'post_id'   => (int) $pid,
				'title'     => get_the_title( $post ),
				'post_type' => $post->post_type,
				'modified'  => $post->post_modified_gmt,
				'languages' => array(),
			);

			foreach ( $languages as $lang ) {
				$row = isset( $have[ (int) $pid ][ $lang ] ) ? $have[ (int) $pid ][ $lang ] : null;

				if ( ! $row ) {
					$state = 'missing';
				} elseif ( $row->content_hash !== $current ) {
					$state = 'stale';
				} else {
					$state = 'current';
				}

				$entry['languages'][ $lang ] = array(
					'state'         => $state,
					'translated_at' => $row ? $row->updated_at : null,
				);
			}

			$out[ (int) $pid ] = $entry;
		}

		return $out;
	}

	/**
	 * Export every stored translation for backup.
	 *
	 * @param string|null $language Omit for all languages.
	 * @return array
	 */
	public static function export( $language = null ) {
		global $wpdb;
		$table = self::table_name();

		if ( $language ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT language, source_text, translated_text, updated_at FROM {$table} WHERE language = %s ORDER BY language, id", $language ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results(
				"SELECT language, source_text, translated_text, updated_at FROM {$table} ORDER BY language, id",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$posts = $wpdb->get_results(
			"SELECT post_id, language, translated_title, translated_content, translated_excerpt, content_hash, updated_at
			   FROM {$wpdb->prefix}acwpt_translations"
			. ( $language ? $wpdb->prepare( ' WHERE language = %s', $language ) : '' ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url(),
			'db_version'  => self::DB_VERSION,
			'strings'     => $rows ? $rows : array(),
			'posts'       => $posts ? $posts : array(),
		);
	}

	/**
	 * Restore from an export produced by export().
	 *
	 * Additive by design — restoring a backup must not delete translations made
	 * since it was taken.
	 *
	 * @param array $data
	 * @return array {strings:int, posts:int}
	 */
	public static function import( array $data ) {
		global $wpdb;
		$counts = array( 'strings' => 0, 'posts' => 0 );

		if ( ! empty( $data['strings'] ) && is_array( $data['strings'] ) ) {
			$by_lang = array();
			foreach ( $data['strings'] as $row ) {
				if ( empty( $row['language'] ) || ! isset( $row['source_text'], $row['translated_text'] ) ) {
					continue;
				}
				$by_lang[ $row['language'] ][ $row['source_text'] ] = $row['translated_text'];
			}
			foreach ( $by_lang as $lang => $pairs ) {
				$counts['strings'] += self::set_many( $lang, $pairs );
			}
		}

		if ( ! empty( $data['posts'] ) && is_array( $data['posts'] ) ) {
			foreach ( $data['posts'] as $row ) {
				if ( empty( $row['post_id'] ) || empty( $row['language'] ) ) {
					continue;
				}
				ACWPT_Cache::set(
					(int) $row['post_id'],
					$row['language'],
					$row['translated_title'] ?? '',
					$row['translated_content'] ?? '',
					$row['translated_excerpt'] ?? '',
					$row['content_hash'] ?? ''
				);
				$counts['posts']++;
			}
		}

		return $counts;
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
