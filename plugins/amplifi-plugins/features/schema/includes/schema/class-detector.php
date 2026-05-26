<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Detector {
	public function detect_for_url( string $url ): array {
		$cache_key = 'ac_schema_detected_' . md5( $url );
		$cached    = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( false !== $cached ) {
			return $cached;
		}

		$r = wp_remote_get( $url, [
			'timeout'     => 5,
			'redirection' => 3,
			'user-agent'  => 'amplifi.schema-detector/1.0',
		] );
		if ( is_wp_error( $r ) ) {
			return [];
		}
		$body = (string) wp_remote_retrieve_body( $r );
		if ( strlen( $body ) > 5_000_000 ) {
			$body = substr( $body, 0, 5_000_000 );
		}
		$found = $this->parse_html( $body );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $found, HOUR_IN_SECONDS );
		}
		return $found;
	}

	/** @return array<int, array{source: string, schema_type: string, json_string: string}> */
	public function parse_html( string $html ): array {
		$found = [];
		if ( ! preg_match_all(
			'#<script\s+[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $found;
		}
		foreach ( $matches as $match ) {
			$tag  = $match[0];
			$json = trim( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$source = $this->guess_source( $tag );
			foreach ( $this->expand_graph( $data ) as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$type    = $entity['@type'] ?? 'Unknown';
				$found[] = [
					'source'      => $source,
					'schema_type' => is_array( $type ) ? implode( ',', $type ) : (string) $type,
					'json_string' => $this->encode( $entity ),
				];
			}
		}
		return $found;
	}

	/** @return array<int, array> */
	private function expand_graph( array $data ): array {
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			return $data['@graph'];
		}
		return [ $data ];
	}

	private function guess_source( string $script_tag ): string {
		if ( stripos( $script_tag, 'yoast-schema-graph' ) !== false || stripos( $script_tag, 'yoast' ) !== false ) {
			return 'yoast';
		}
		if ( stripos( $script_tag, 'rank-math' ) !== false || stripos( $script_tag, 'rank_math' ) !== false ) {
			return 'rankmath';
		}
		if ( stripos( $script_tag, 'seopress' ) !== false ) {
			return 'seopress';
		}
		if ( stripos( $script_tag, 'aioseo' ) !== false ) {
			return 'aioseo';
		}
		if ( stripos( $script_tag, 'amplifi-schema' ) !== false ) {
			return 'amplifi-schema';
		}
		if ( stripos( $script_tag, 'ac-jsonld-data' ) !== false ) {
			return 'amplifi-meta';
		}
		return 'unknown';
	}

	private function encode( array $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}
		return (string) json_encode( $data );
	}
}
