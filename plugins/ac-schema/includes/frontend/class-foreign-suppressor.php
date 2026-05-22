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

	/** @return string[] Schema types overridden for the current request. */
	private function overridden_types(): array {
		if ( ! is_singular() ) {
			return [];
		}
		$list = get_post_meta( get_queried_object_id(), '_ac_schema_overrides', true );
		return is_array( $list ) ? $list : [];
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
