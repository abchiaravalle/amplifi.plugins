<?php
/**
 * Schema installer.
 *
 * Owns the source of truth for every `wp_amplifi_security_*` table and runs
 * `dbDelta` on activation and on version-bump page loads.
 *
 * dbDelta formatting rules (yes, they really do matter):
 *   - exactly two spaces between PRIMARY KEY and `(`
 *   - each KEY/PRIMARY KEY on its own line
 *   - column types lowercased
 *   - no backticks around column names
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	public const TABLES = [
		'findings',
		'baseline',
		'auth_log',
		'audit',
		'scans',
		'verdict_cache',
		'log_sources',
		'vuln_feed',
		'spend',
	];

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'amplifi_security_';

		$queries = [];

		// Findings: scanner output + triage verdicts.
		$queries[] = "CREATE TABLE {$prefix}findings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned DEFAULT NULL,
			type varchar(40) NOT NULL,
			subtype varchar(60) DEFAULT NULL,
			category varchar(40) DEFAULT NULL,
			category_label varchar(80) DEFAULT NULL,
			evidence longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending_triage',
			verdict varchar(20) DEFAULT NULL,
			confidence decimal(3,2) DEFAULT NULL,
			rationale text DEFAULT NULL,
			recommended_action text DEFAULT NULL,
			user_marked_fp tinyint(1) NOT NULL DEFAULT 0,
			triaged_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status, created_at),
			KEY category_verdict (category, verdict),
			KEY scan_id (scan_id)
		) {$charset_collate};";

		// Baseline: file hashes for integrity diffing.
		$queries[] = "CREATE TABLE {$prefix}baseline (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(500) NOT NULL,
			hash char(64) NOT NULL,
			source varchar(20) NOT NULL,
			source_version varchar(40) DEFAULT NULL,
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_unique (path(191)),
			KEY source (source)
		) {$charset_collate};";

		// Auth log: real-time login/auth events for the auth-anomaly scanner.
		$queries[] = "CREATE TABLE {$prefix}auth_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event varchar(40) NOT NULL,
			user_login varchar(60) DEFAULT NULL,
			ip varchar(45) DEFAULT NULL,
			ua text DEFAULT NULL,
			country char(2) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_login_created (user_login, created_at)
		) {$charset_collate};";

		// Audit log: cross-cutting hash-chained event journal.
		$queries[] = "CREATE TABLE {$prefix}audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			actor_ip varchar(45) DEFAULT NULL,
			actor_ua text DEFAULT NULL,
			target_type varchar(40) DEFAULT NULL,
			target_id varchar(120) DEFAULT NULL,
			event_data longtext NOT NULL,
			prev_hash char(64) DEFAULT NULL,
			row_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_created (event_type, created_at),
			KEY actor_created (actor_user_id, created_at)
		) {$charset_collate};";

		// Scans: one row per scan run with token spend for that scan's triage call.
		$queries[] = "CREATE TABLE {$prefix}scans (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			scanners_run longtext DEFAULT NULL,
			findings_count int(11) NOT NULL DEFAULT 0,
			triage_tokens_in int(11) DEFAULT NULL,
			triage_tokens_out int(11) DEFAULT NULL,
			triage_cost_usd decimal(10,5) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at)
		) {$charset_collate};";

		// Verdict cache: skip re-triage on benign findings seen recently.
		$queries[] = "CREATE TABLE {$prefix}verdict_cache (
			cache_key char(64) NOT NULL,
			verdict varchar(20) NOT NULL,
			rationale text DEFAULT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (cache_key),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		// Log sources: user-configured raw-text log URLs for forensic correlation.
		$queries[] = "CREATE TABLE {$prefix}log_sources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(60) NOT NULL,
			url varchar(500) NOT NULL,
			auth_type varchar(20) NOT NULL DEFAULT 'none',
			auth_secret text DEFAULT NULL,
			max_bytes int(11) NOT NULL DEFAULT 2097152,
			last_fetch_at datetime DEFAULT NULL,
			last_status varchar(20) DEFAULT NULL,
			consecutive_failures int(11) NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY enabled (enabled)
		) {$charset_collate};";

		// Vulnerability feed cache (Wordfence Intelligence v3).
		$queries[] = "CREATE TABLE {$prefix}vuln_feed (
			vuln_id varchar(80) NOT NULL,
			component_slug varchar(120) NOT NULL,
			component_type varchar(20) NOT NULL,
			affected_versions varchar(100) DEFAULT NULL,
			fixed_in varchar(40) DEFAULT NULL,
			cvss decimal(3,1) DEFAULT NULL,
			cves longtext DEFAULT NULL,
			exploit_observed tinyint(1) NOT NULL DEFAULT 0,
			raw_record longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (vuln_id),
			KEY component (component_slug, component_type)
		) {$charset_collate};";

		// Spend tracker (one bucket per UTC date).
		$queries[] = "CREATE TABLE {$prefix}spend (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			date date NOT NULL,
			tokens_in bigint(20) unsigned NOT NULL DEFAULT 0,
			tokens_out bigint(20) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(10,5) NOT NULL DEFAULT 0,
			triage_calls int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY date_unique (date)
		) {$charset_collate};";

		foreach ( $queries as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'amplifi_security_db_version', AMPLIFI_SECURITY_DB_VERSION, false );
	}

	/**
	 * Run dbDelta if the stored db version differs from the constant.
	 *
	 * Called on every page load via `Plugin::init()` so file-copy upgrades
	 * (no activation hook) still reach the schema.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( 'amplifi_security_db_version' );
		if ( (string) $installed === AMPLIFI_SECURITY_DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function drop_all(): void {
		global $wpdb;
		$prefix = $wpdb->prefix . 'amplifi_security_';
		foreach ( self::TABLES as $table ) {
			// Identifier is wholly internal — no user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
		}
	}
}
