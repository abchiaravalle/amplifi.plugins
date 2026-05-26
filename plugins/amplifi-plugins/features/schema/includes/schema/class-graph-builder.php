<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Graph_Builder {
	public function __construct( private object $store ) {}

	public function build( array $ctx ): array {
		$graph = [];
		foreach ( [ 'organization', 'website', 'localbusiness' ] as $key ) {
			foreach ( $this->store->find_all_for_scope( 'global', $key ) as $row ) {
				$entity = $this->decode( $row['json_ld'] ?? '' );
				if ( $entity ) {
					$graph[] = $entity;
				}
			}
		}
		foreach ( $ctx['url_rules'] ?? [] as $rule_id ) {
			foreach ( $this->store->find_all_for_scope( 'url_rule', (string) $rule_id ) as $row ) {
				$entity = $this->decode( $row['json_ld'] ?? '' );
				if ( $entity ) {
					$graph[] = $entity;
				}
			}
		}
		if ( ! empty( $ctx['post_id'] ) ) {
			foreach ( $this->store->find_all_for_scope( 'post', (string) $ctx['post_id'] ) as $row ) {
				$entity = $this->decode( $row['json_ld'] ?? '' );
				if ( $entity ) {
					$graph[] = $entity;
				}
			}
		}
		$graph = $this->strip_inner_contexts( $graph );
		return [ '@context' => 'https://schema.org', '@graph' => $graph ];
	}

	private function decode( string $json ): ?array {
		$d = json_decode( $json, true );
		return is_array( $d ) ? $d : null;
	}

	private function strip_inner_contexts( array $items ): array {
		foreach ( $items as &$item ) {
			unset( $item['@context'] );
		}
		return $items;
	}
}
