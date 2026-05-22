<?php
declare(strict_types=1);
namespace Amplifi\Schema\Migration;

use Amplifi\Schema\Data\Entry_Store;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Meta_Importer {
	/**
	 * Import all amplifi.meta JSON-LD post meta into amplifi.schema entries table.
	 *
	 * @return array{imported: int, skipped: int}
	 */
	public static function import_all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ac_jsonld_data'",
			ARRAY_A
		); // phpcs:ignore
		if ( ! is_array( $rows ) ) {
			$rows = [];
		}

		$store    = new Entry_Store();
		$imported = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$raw = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $raw ) ) {
				$json = (string) wp_json_encode( $raw );
				$data = $raw;
			} else {
				$json = (string) $raw;
				$data = json_decode( $json, true );
			}
			if ( ! is_array( $data ) ) {
				$skipped++;
				continue;
			}

			// Handle @graph (multiple entities) by importing each separately.
			$entities = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : [ $data ];
			foreach ( $entities as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$type = $entity['@type'] ?? 'Thing';
				$type = is_array( $type ) ? (string) $type[0] : (string) $type;
				// Ensure each imported entity has its own @context.
				if ( ! isset( $entity['@context'] ) ) {
					$entity = array_merge( [ '@context' => 'https://schema.org' ], $entity );
				}
				$store->save( [
					'scope_type'  => 'post',
					'scope_id'    => (string) $row['post_id'],
					'schema_type' => $type,
					'source'      => 'imported',
					'json_ld'     => (string) wp_json_encode( $entity ),
				] );
				$imported++;
			}
		}

		// Copy amplifi.meta org-level settings into our global Organization entry if empty.
		$meta_settings = get_option( 'ac_jsonld_settings', [] );
		if ( is_array( $meta_settings ) && ! get_option( 'ac_schema_global_organization' ) && ! empty( $meta_settings['organization'] ) && is_array( $meta_settings['organization'] ) ) {
			$org = $meta_settings['organization'];
			if ( ! isset( $org['@context'] ) ) {
				$org = array_merge( [ '@context' => 'https://schema.org', '@type' => 'Organization' ], $org );
			}
			update_option( 'ac_schema_global_organization', $org );
			$store->save( [
				'scope_type'  => 'global',
				'scope_id'    => 'organization',
				'schema_type' => 'Organization',
				'source'      => 'imported',
				'json_ld'     => (string) wp_json_encode( $org ),
			] );
		}

		update_option( 'ac_schema_meta_import_status', 'done' );

		$settings = get_option( 'ac_schema_settings', [] );
		if ( is_array( $settings ) ) {
			$settings['suppress_amplifi_meta_jsonld'] = true;
			update_option( 'ac_schema_settings', $settings );
		}

		return [ 'imported' => $imported, 'skipped' => $skipped ];
	}

	public static function skip(): void {
		update_option( 'ac_schema_meta_import_status', 'skipped' );
	}
}
