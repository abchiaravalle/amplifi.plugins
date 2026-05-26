<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Anthropic_Client {
	private const API_URL   = 'https://api.anthropic.com/v1/messages';
	private const TOOL_NAME = 'emit_jsonld';

	/** @var callable */
	private $transport;

	public function __construct(
		private string $api_key,
		private string $model,
		?callable $transport = null
	) {
		$this->transport = $transport ?? [ $this, 'default_transport' ];
	}

	public function generate_jsonld( string $system, string $user ): array {
		$req = [
			'model'      => $this->model,
			'max_tokens' => 2048,
			'system'     => $system,
			'tools'      => [ [
				'name'         => self::TOOL_NAME,
				'description'  => 'Emit a single JSON-LD object describing the page.',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => (object) [],
					'additionalProperties' => true,
				],
			] ],
			'tool_choice' => [ 'type' => 'tool', 'name' => self::TOOL_NAME ],
			'messages'    => [ [ 'role' => 'user', 'content' => $user ] ],
		];
		$resp = ( $this->transport )( $req );
		if ( empty( $resp['ok'] ) ) {
			return [ 'error' => $resp['error'] ?? 'transport_failed' ];
		}
		$body     = $resp['body'];
		$tool_use = null;
		foreach ( $body['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['name'] ?? '' ) === self::TOOL_NAME ) {
				$tool_use = $block;
				break;
			}
		}
		if ( ! $tool_use ) {
			return [ 'error' => 'no_tool_use_block' ];
		}
		return [
			'jsonld'        => $tool_use['input'],
			'input_tokens'  => (int) ( $body['usage']['input_tokens']  ?? 0 ),
			'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
		];
	}

	private function default_transport( array $req ): array {
		$r = wp_remote_post( self::API_URL, [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2024-10-22',
				'content-type'      => 'application/json',
			],
			'body' => wp_json_encode( $req ),
		] );
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => false, 'error' => $r->get_error_message() ];
		}
		$code     = wp_remote_retrieve_response_code( $r );
		$raw_body = (string) wp_remote_retrieve_body( $r );
		$body     = json_decode( $raw_body, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? $raw_body;
			return [ 'ok' => false, 'error' => "HTTP $code: $msg" ];
		}
		return [ 'ok' => true, 'body' => $body ];
	}
}
