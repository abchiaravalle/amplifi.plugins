<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Registry;

final class RegistryTest extends TestCase {
	public function test_knows_article_type(): void {
		$r = new Registry();
		$this->assertTrue( $r->has_type( 'Article' ) );
	}

	public function test_returns_properties_for_article(): void {
		$r = new Registry();
		$props = $r->properties_for( 'Article' );
		$this->assertContains( 'headline', $props );
		$this->assertContains( 'author', $props );
	}

	public function test_required_for_rich_results_article(): void {
		$r = new Registry();
		$req = $r->required_for_rich_results( 'Article' );
		$this->assertEqualsCanonicalizing(
			[ 'headline', 'author', 'datePublished', 'image' ],
			$req
		);
	}

	public function test_unknown_type_returns_empty(): void {
		$r = new Registry();
		$this->assertFalse( $r->has_type( 'Nonsense123' ) );
		$this->assertSame( [], $r->properties_for( 'Nonsense123' ) );
	}

	public function test_all_types_returns_many(): void {
		$r = new Registry();
		$this->assertGreaterThan( 100, count( $r->all_types() ) );
	}
}
