<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Schema\Registry;
use Amplifi\Schema\Schema\Validator;

final class ValidatorTest extends TestCase {
	private Validator $v;
	protected function setUp(): void {
		$this->v = new Validator( new Registry() );
	}

	public function test_invalid_json_returns_error(): void {
		$r = $this->v->validate( '{ not json' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'invalid_json', $r['errors'][0]['code'] );
	}

	public function test_missing_context_errors(): void {
		$r = $this->v->validate( json_encode( [ '@type' => 'Article' ] ) );
		$this->assertFalse( $r['ok'] );
		$codes = array_column( $r['errors'], 'code' );
		$this->assertContains( 'missing_context', $codes );
	}

	public function test_unknown_type_errors(): void {
		$r = $this->v->validate( json_encode( [
			'@context' => 'https://schema.org',
			'@type'    => 'Nonsense123',
		] ) );
		$codes = array_column( $r['errors'], 'code' );
		$this->assertContains( 'unknown_type', $codes );
	}

	public function test_unknown_property_warns(): void {
		$r = $this->v->validate( json_encode( [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => 'Hi',
			'author'        => 'Me',
			'datePublished' => '2026-01-01',
			'image'         => 'https://x/y.jpg',
			'nonsenseProp'  => 'x',
		] ) );
		$codes = array_column( $r['errors'], 'code' );
		$this->assertContains( 'unknown_property', $codes );
	}

	public function test_required_for_rich_results_missing(): void {
		$r = $this->v->validate( json_encode( [
			'@context' => 'https://schema.org',
			'@type'    => 'Article',
			'headline' => 'Hi',
		] ) );
		$codes = array_column( $r['errors'], 'code' );
		$this->assertContains( 'missing_required_for_rich_results', $codes );
	}

	public function test_valid_article_passes(): void {
		$r = $this->v->validate( json_encode( [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => 'Hi',
			'author'        => [ '@type' => 'Person', 'name' => 'Me' ],
			'datePublished' => '2026-01-01',
			'image'         => 'https://x/y.jpg',
		] ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( [], $r['errors'] );
	}
}
