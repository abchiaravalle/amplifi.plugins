<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Prompt_Builder;

final class PromptBuilderTest extends TestCase {
	public function test_trim_to_token_budget_short_text_passes_through(): void {
		$text = 'short text';
		$this->assertSame( $text, Prompt_Builder::trim_to_token_budget( $text, 100 ) );
	}

	public function test_trim_to_token_budget_keeps_head_and_tail(): void {
		$long    = str_repeat( 'word ', 10_000 );
		$trimmed = Prompt_Builder::trim_to_token_budget( $long, 100 );
		$this->assertLessThan( strlen( $long ), strlen( $trimmed ) );
		$this->assertStringStartsWith( 'word', $trimmed );
		$this->assertStringContainsString( 'truncated', $trimmed );
	}

	public function test_build_for_post_includes_required_context(): void {
		$msg = Prompt_Builder::build_for_post( [
			'title'     => 'Hello',
			'url'       => 'https://example.com/hello',
			'post_type' => 'post',
			'content'   => 'Body here.',
			'existing'  => null,
		] );
		$this->assertArrayHasKey( 'system', $msg );
		$this->assertArrayHasKey( 'user', $msg );
		$this->assertStringContainsString( 'Hello', $msg['user'] );
		$this->assertStringContainsString( 'https://example.com/hello', $msg['user'] );
		$this->assertStringContainsString( 'post', $msg['user'] );
		$this->assertStringContainsString( 'schema.org', $msg['system'] );
	}

	public function test_build_for_post_includes_existing_when_provided(): void {
		$msg = Prompt_Builder::build_for_post( [
			'title'     => 'Hi',
			'url'       => 'https://x/y',
			'post_type' => 'post',
			'content'   => 'body',
			'existing'  => [ '@type' => 'Article', 'headline' => 'Hi' ],
		] );
		$this->assertStringContainsString( 'Existing schema', $msg['user'] );
		$this->assertStringContainsString( 'Article', $msg['user'] );
	}

	public function test_build_for_global_organization(): void {
		$msg = Prompt_Builder::build_for_global( 'organization', [
			'name' => 'Acme',
			'url'  => 'https://acme.test',
		] );
		$this->assertStringContainsString( 'Organization', $msg['user'] );
		$this->assertStringContainsString( 'Acme', $msg['user'] );
	}

	public function test_build_for_global_unknown_key_defaults_to_thing(): void {
		$msg = Prompt_Builder::build_for_global( 'mystery', [ 'k' => 'v' ] );
		$this->assertStringContainsString( 'Thing', $msg['user'] );
	}
}
