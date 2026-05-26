<?php
declare(strict_types=1);
namespace Amplifi\Schema\Frontend;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Foreign_Suppressor {
	public function register(): void {
		add_filter( 'wpseo_schema_graph', [ $this, 'filter_yoast_graph' ], 99, 2 );
		add_filter( 'rank_math/json_ld', [ $this, 'filter_rankmath' ], 99 );
		add_filter( 'seopress_pro_schemas_json', [ $this, 'filter_seopress' ], 99 );
		add_filter( 'aioseo_schema_output', [ $this, 'filter_aioseo' ], 99 );
		// amplifi.meta deferral happens in that plugin's own AMPLIFI_SCHEMA_ACTIVE check (Task 11.2).
	}

	/**
	 * Schema types to suppress from foreign sources for the current request.
	 * Includes both explicitly overridden types AND types that amplifi.schema
	 * already has entries for (auto-override: if we have it, suppress theirs).
	 *
	 * @return string[]
	 */
	private function overridden_types(): array {
		if ( ! is_singular() ) {
			return [];
		}
		$post_id = get_queried_object_id();
		$explicit = get_post_meta( $post_id, '_ac_schema_overrides', true );
		$explicit = is_array( $explicit ) ? $explicit : [];

		// Auto-override: if amplifi.schema has entries for this post, suppress
		// matching types from foreign sources automatically.
		$auto = [];
		if ( class_exists( \Amplifi\Schema\Data\Entry_Store::class ) ) {
			$store   = new \Amplifi\Schema\Data\Entry_Store();
			$entries = $store->find_all_for_scope( 'post', (string) $post_id );
			foreach ( $entries as $entry ) {
				$auto[] = $entry['schema_type'] ?? '';
			}
		}

		return array_values( array_unique( array_filter( array_merge( $explicit, $auto ) ) ) );
	}

	/**
	 * Yoast emits a single @graph array — strip pieces whose @type matches an overridden type.
	 *
	 * @param array $graph
	 * @param mixed $context
	 * @return array
	 */
	public function filter_yoast_graph( $graph, $context = null ) {
		$kill = $this->overridden_types();
		if ( ! $kill || ! is_array( $graph ) ) {
			return $graph;
		}
		return array_values( array_filter( $graph, function ( $piece ) use ( $kill ) {
			$t = $piece['@type'] ?? '';
			$t = is_array( $t ) ? $t : [ $t ];
			return ! array_intersect( $t, $kill );
		} ) );
	}

	/**
	 * Rank Math emits an associative array keyed by schema type (e.g., 'Article' => [...]).
	 * Remove keys matching overridden types.
	 */
	public function filter_rankmath( $data ) {
		$kill = $this->overridden_types();
		if ( ! $kill || ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $kill as $type ) {
			unset( $data[ $type ] );
		}
		return $data;
	}

	/**
	 * SEOPress emits an array of schema entities. Drop entities whose @type matches.
	 */
	public function filter_seopress( $schemas ) {
		$kill = $this->overridden_types();
		if ( ! $kill || ! is_array( $schemas ) ) {
			return $schemas;
		}
		return array_values( array_filter( $schemas, function ( $s ) use ( $kill ) {
			$t = is_array( $s ) ? ( $s['@type'] ?? '' ) : '';
			return ! in_array( $t, $kill, true );
		} ) );
	}

	/**
	 * AIOSEO emits a rendered HTML string. Strip script tags whose body declares an overridden @type.
	 */
	public function filter_aioseo( $output ) {
		$kill = $this->overridden_types();
		if ( ! $kill || ! is_string( $output ) ) {
			return $output;
		}
		foreach ( $kill as $type ) {
			$pattern = '#<script[^>]*application/ld\+json[^>]*>[^<]*"@type"\s*:\s*"' . preg_quote( $type, '#' ) . '"[^<]*</script>#is';
			$output  = (string) preg_replace( $pattern, '', $output );
		}
		return $output;
	}
}
