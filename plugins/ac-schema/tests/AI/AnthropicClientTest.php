<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Anthropic_Client;

final class AnthropicClientTest extends TestCase {
	public function test_generate_jsonld_returns_parsed_payload(): void {
		$fake = function ( array $req ) {
			return [
				'ok'   => true,
				'body' => [
					'content' => [ [
						'type'  => 'tool_use',
						'name'  => 'emit_jsonld',
						'input' => [
							'@context' => 'https://schema.org',
							'@type'    => 'Article',
							'headline' => 'Hi',
						],
					] ],
					'usage'   => [ 'input_tokens' => 100, 'output_tokens' => 50 ],
				],
			];
		};
		$client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
		$r = $client->generate_jsonld( 'sys', 'user' );
		$this->assertSame( 'Article', $r['jsonld']['@type'] );
		$this->assertSame( 'Hi', $r['jsonld']['headline'] );
		$this->assertSame( 100, $r['input_tokens'] );
		$this->assertSame( 50, $r['output_tokens'] );
		$this->assertArrayNotHasKey( 'error', $r );
	}

	public function test_error_response_returns_error_shape(): void {
		$fake = fn( array $req ) => [ 'ok' => false, 'error' => 'boom' ];
		$client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
		$r = $client->generate_jsonld( 'sys', 'user' );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'boom', $r['error'] );
	}

	public function test_response_missing_tool_use_returns_error(): void {
		$fake = fn( array $req ) => [
			'ok'   => true,
			'body' => [
				'content' => [ [ 'type' => 'text', 'text' => 'plain text response' ] ],
				'usage'   => [ 'input_tokens' => 1, 'output_tokens' => 1 ],
			],
		];
		$client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
		$r = $client->generate_jsonld( 'sys', 'user' );
		$this->assertArrayHasKey( 'error', $r );
		$this->assertSame( 'no_tool_use_block', $r['error'] );
	}

	public function test_request_payload_uses_tool_choice(): void {
		$captured = null;
		$fake = function ( array $req ) use ( &$captured ) {
			$captured = $req;
			return [
				'ok'   => true,
				'body' => [
					'content' => [ [ 'type' => 'tool_use', 'name' => 'emit_jsonld', 'input' => [ '@type' => 'Thing' ] ] ],
					'usage'   => [ 'input_tokens' => 1, 'output_tokens' => 1 ],
				],
			];
		};
		$client = new Anthropic_Client( 'sk-test', 'claude-haiku-4-5-20251001', $fake );
		$client->generate_jsonld( 'sys-prompt', 'user-prompt' );
		$this->assertSame( 'claude-haiku-4-5-20251001', $captured['model'] );
		$this->assertSame( 'sys-prompt', $captured['system'] );
		$this->assertSame( 'user-prompt', $captured['messages'][0]['content'] );
		$this->assertSame( 'tool', $captured['tool_choice']['type'] );
		$this->assertSame( 'emit_jsonld', $captured['tool_choice']['name'] );
	}
}
