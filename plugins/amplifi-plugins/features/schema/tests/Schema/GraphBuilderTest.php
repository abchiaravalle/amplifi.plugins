<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Graph_Builder;

final class GraphBuilderTest extends TestCase {
	private function fake_store( array $entries ): object {
		return new class( $entries ) {
			public function __construct( public array $entries ) {}
			public function find_all_for_scope( string $type, string $id ): array {
				$key = $type . ':' . $id;
				$rows = [];
				foreach ( $this->entries[ $key ] ?? [] as $entry ) {
					$rows[] = [
						'schema_type' => $entry['@type'] ?? 'Thing',
						'json_ld'     => json_encode( $entry ),
					];
				}
				return $rows;
			}
		};
	}

	public function test_builds_graph_with_global_and_post_entries(): void {
		$store = $this->fake_store( [
			'global:organization' => [ [ '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Acme' ] ],
			'global:website'      => [ [ '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Acme Blog' ] ],
			'post:42'             => [ [ '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Hi' ] ],
		] );
		$gb    = new Graph_Builder( $store );
		$graph = $gb->build( [ 'post_id' => 42, 'url_rules' => [] ] );

		$this->assertSame( 'https://schema.org', $graph['@context'] );
		$this->assertCount( 3, $graph['@graph'] );
		$types = array_column( $graph['@graph'], '@type' );
		$this->assertSame( [ 'Organization', 'WebSite', 'Article' ], $types );
	}

	public function test_skips_localbusiness_when_absent(): void {
		$store = $this->fake_store( [
			'global:organization' => [ [ '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Acme' ] ],
		] );
		$gb    = new Graph_Builder( $store );
		$graph = $gb->build( [ 'post_id' => 0, 'url_rules' => [] ] );
		$this->assertCount( 1, $graph['@graph'] );
	}

	public function test_includes_url_rule_entries(): void {
		$store = $this->fake_store( [
			'url_rule:rule-7' => [ [ '@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'Blog index' ] ],
		] );
		$gb    = new Graph_Builder( $store );
		$graph = $gb->build( [ 'post_id' => 0, 'url_rules' => [ 'rule-7' ] ] );
		$this->assertCount( 1, $graph['@graph'] );
		$this->assertSame( 'CollectionPage', $graph['@graph'][0]['@type'] );
	}

	public function test_strips_inner_contexts(): void {
		$store = $this->fake_store( [
			'global:organization' => [ [ '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Acme' ] ],
		] );
		$gb    = new Graph_Builder( $store );
		$graph = $gb->build( [ 'post_id' => 0, 'url_rules' => [] ] );
		$this->assertArrayNotHasKey( '@context', $graph['@graph'][0] );
	}

	public function test_handles_invalid_json_gracefully(): void {
		$store = new class {
			public function find_all_for_scope( string $type, string $id ): array {
				if ( $type === 'global' && $id === 'organization' ) {
					return [ [ 'schema_type' => 'Organization', 'json_ld' => '{ not json' ] ];
				}
				return [];
			}
		};
		$gb    = new Graph_Builder( $store );
		$graph = $gb->build( [ 'post_id' => 0, 'url_rules' => [] ] );
		// Invalid JSON is silently dropped — empty graph.
		$this->assertSame( [], $graph['@graph'] );
	}
}
