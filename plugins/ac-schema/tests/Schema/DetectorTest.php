<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Detector;

final class DetectorTest extends TestCase {
	private Detector $d;

	protected function setUp(): void {
		$this->d = new Detector();
	}

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/../fixtures/' . $name );
	}

	public function test_detects_yoast(): void {
		$found = $this->d->parse_html( $this->fixture( 'yoast-head.html' ) );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'yoast', $found[0]['source'] );
		$this->assertSame( 'Article', $found[0]['schema_type'] );
	}

	public function test_detects_rankmath(): void {
		$found = $this->d->parse_html( $this->fixture( 'rankmath-head.html' ) );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'rankmath', $found[0]['source'] );
	}

	public function test_detects_seopress(): void {
		$found = $this->d->parse_html( $this->fixture( 'seopress-head.html' ) );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'seopress', $found[0]['source'] );
	}

	public function test_detects_aioseo(): void {
		$found = $this->d->parse_html( $this->fixture( 'aioseo-head.html' ) );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'aioseo', $found[0]['source'] );
	}

	public function test_marks_unknown_when_no_signature(): void {
		$found = $this->d->parse_html( $this->fixture( 'manual-head.html' ) );
		$this->assertNotEmpty( $found );
		$this->assertSame( 'unknown', $found[0]['source'] );
		$this->assertSame( 'Person', $found[0]['schema_type'] );
	}

	public function test_expands_graph_into_individual_entries(): void {
		// Yoast wraps in @graph array — verify each entity comes out as its own entry.
		$html = '<script type="application/ld+json" class="yoast-schema-graph">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"Article"},{"@type":"Person"}]}</script>';
		$found = $this->d->parse_html( $html );
		$this->assertCount( 3, $found );
		$types = array_column( $found, 'schema_type' );
		$this->assertSame( [ 'WebPage', 'Article', 'Person' ], $types );
	}

	public function test_ignores_invalid_json_blocks(): void {
		$html = '<script type="application/ld+json">{ not json</script><script type="application/ld+json">{"@type":"Thing"}</script>';
		$found = $this->d->parse_html( $html );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Thing', $found[0]['schema_type'] );
	}

	public function test_no_script_returns_empty(): void {
		$this->assertSame( [], $this->d->parse_html( '<html><body>no schema here</body></html>' ) );
	}
}
