<?php
declare(strict_types=1);
namespace Amplifi\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Installer {

	public const DB_VERSION = '1';

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		dbDelta( "CREATE TABLE {$prefix}ac_schema_entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope_type varchar(16) NOT NULL,
			scope_id varchar(191) NOT NULL,
			schema_type varchar(64) NOT NULL,
			source varchar(16) NOT NULL,
			json_ld longtext NOT NULL,
			hash char(64) NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_type_id_schema (scope_type, scope_id, schema_type),
			KEY scope_lookup (scope_type, scope_id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$prefix}ac_schema_bulk_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(16) NOT NULL,
			scope longtext NOT NULL,
			total int NOT NULL DEFAULT 0,
			processed int NOT NULL DEFAULT 0,
			failed int NOT NULL DEFAULT 0,
			model varchar(64) NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			cost_usd decimal(10,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$prefix}ac_schema_spend (
			day date NOT NULL,
			input_tokens bigint(20) NOT NULL DEFAULT 0,
			output_tokens bigint(20) NOT NULL DEFAULT 0,
			cost_usd decimal(10,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (day)
		) $charset;" );

		update_option( 'ac_schema_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'ac_schema_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
