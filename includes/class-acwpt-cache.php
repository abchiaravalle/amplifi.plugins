<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Cache {

	/**
	 * Create the translations table.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			language VARCHAR(10) NOT NULL,
			translated_title TEXT,
			translated_content LONGTEXT,
			translated_excerpt TEXT,
			content_hash VARCHAR(32) NOT NULL DEFAULT '',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY post_lang (post_id, language),
			KEY language (language)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the translations table.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get the table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'acwpt_translations';
	}

	/**
	 * Get a cached translation.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language code.
	 * @return object|null Row object or null.
	 */
	public static function get( $post_id, $language ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND language = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$language
			)
		);
	}

	/**
	 * Store or update a translation.
	 */
	public static function set( $post_id, $language, $title, $content, $excerpt, $content_hash ) {
		global $wpdb;
		$table = self::table_name();

		$existing = self::get( $post_id, $language );

		if ( $existing ) {
			return $wpdb->update(
				$table,
				array(
					'translated_title'   => $title,
					'translated_content' => $content,
					'translated_excerpt' => $excerpt,
					'content_hash'       => $content_hash,
					'updated_at'         => current_time( 'mysql' ),
				),
				array(
					'post_id'  => $post_id,
					'language' => $language,
				),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		return $wpdb->insert(
			$table,
			array(
				'post_id'            => $post_id,
				'language'           => $language,
				'translated_title'   => $title,
				'translated_content' => $content,
				'translated_excerpt' => $excerpt,
				'content_hash'       => $content_hash,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete all cached translations for a post.
	 */
	public static function delete_post( $post_id ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Delete all cached translations for a language.
	 */
	public static function delete_language( $language ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->delete( $table, array( 'language' => $language ), array( '%s' ) );
	}

	/**
	 * Clear the entire cache.
	 */
	public static function flush_all() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get cache statistics.
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_language = $wpdb->get_results(
			"SELECT language, COUNT(*) as count FROM {$table} GROUP BY language ORDER BY language", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			OBJECT_K
		);

		return array(
			'total'       => $total,
			'by_language' => $by_language,
		);
	}
}
