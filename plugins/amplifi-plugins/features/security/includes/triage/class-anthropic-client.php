<?php
/**
 * Thin wrapper around the Anthropic Messages API.
 *
 * Uses `wp_remote_post` (no Composer SDK), enforces JSON output via the
 * Messages API's `tools` + `tool_choice` mechanism (forced single-tool call,
 * which is the most reliable way to get strict JSON across Claude versions).
 *
 * - Honors `Retry-After` on 429.
 * - Returns parsed verdicts on success.
 * - Throws on protocol errors so the caller can drop into naive mode.
 *
 * @package Amplifi\Security\Triage
 */

declare(strict_types=1);

namespace Amplifi\Security\Triage;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Crypto\Secret_Store;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Anthropic_Client {

	public const ENDPOINT       = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION    = '2023-06-01';
	public const DEFAULT_MODEL  = 'claude-haiku-4-5-20251001';
	public const ALLOWED_MODELS = [
		'claude-haiku-4-5-20251001',
		'claude-sonnet-4-6',
	];

	/**
	 * Send the triage request.
	 *
	 * @param string                     $system   System prompt.
	 * @param string                     $user_msg User message body.
	 * @param array<string,mixed>        $schema   JSON schema for the verdict tool.
	 * @param string                     $model    Model id.
	 * @return array{
	 *     content: array<string,mixed>,
	 *     usage: array{input_tokens:int,output_tokens:int},
	 *     model: string,
	 *     stop_reason: ?string,
	 * }
	 */
	public static function call( string $system, string $user_msg, array $schema, string $model = self::DEFAULT_MODEL ): array {
		if ( ! in_array( $model, self::ALLOWED_MODELS, true ) ) {
			$model = self::DEFAULT_MODEL;
		}

		$api_key = self::api_key();
		if ( '' === $api_key ) {
			throw new RuntimeException( 'amplifi.security: Anthropic API key not configured' );
		}

		$tool_name = 'submit_verdicts';
		$body = [
			'model'       => $model,
			'max_tokens'  => 4096,
			'temperature' => 0.0,
			'system'      => $system,
			'tools'       => [
				[
					'name'         => $tool_name,
					'description'  => 'Submit triage verdicts for the batch of findings.',
					'input_schema' => $schema,
				],
			],
			'tool_choice' => [ 'type' => 'tool', 'name' => $tool_name ],
			'messages'    => [
				[ 'role' => 'user', 'content' => $user_msg ],
			],
		];

		$attempts = 0;
		while ( true ) {
			$attempts++;
			$resp = wp_remote_post(
				self::ENDPOINT,
				[
					'timeout'   => 60,
					'sslverify' => true,
					'headers'   => [
						'x-api-key'         => $api_key,
						'anthropic-version' => self::API_VERSION,
						'content-type'      => 'application/json',
						'user-agent'        => 'amplifi-security/' . AMPLIFI_SECURITY_VERSION,
					],
					'body'      => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $resp ) ) {
				if ( $attempts < 3 ) {
					usleep( 500_000 );
					continue;
				}
				throw new RuntimeException( 'Anthropic API transport error: ' . $resp->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body_text = wp_remote_retrieve_body( $resp );

			if ( 429 === $code || 529 === $code ) {
				$retry = (int) wp_remote_retrieve_header( $resp, 'retry-after' );
				if ( $attempts >= 3 ) {
					throw new RuntimeException( 'Anthropic rate-limited after retries' );
				}
				sleep( max( 1, min( 30, $retry ) ) );
				continue;
			}

			if ( 401 === $code || 403 === $code ) {
				Audit_Logger::log( 'anthropic_auth_error', [ 'code' => $code ] );
				throw new RuntimeException( 'Anthropic API auth error (key invalid?)' );
			}

			if ( $code < 200 || $code >= 300 ) {
				throw new RuntimeException(
					sprintf(
						'Anthropic API HTTP %d: %s',
						$code,
						mb_substr( (string) $body_text, 0, 500 )
					)
				);
			}

			$decoded = json_decode( (string) $body_text, true );
			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException( 'Anthropic API returned non-JSON' );
			}

			$tool_input = self::extract_tool_input( $decoded, $tool_name );

			return [
				'content'     => $tool_input,
				'usage'       => [
					'input_tokens'  => (int) ( $decoded['usage']['input_tokens']  ?? 0 ),
					'output_tokens' => (int) ( $decoded['usage']['output_tokens'] ?? 0 ),
				],
				'model'       => (string) ( $decoded['model']       ?? $model ),
				'stop_reason' => $decoded['stop_reason']             ?? null,
			];
		}
	}

	private static function extract_tool_input( array $decoded, string $tool_name ): array {
		if ( empty( $decoded['content'] ) || ! is_array( $decoded['content'] ) ) {
			throw new RuntimeException( 'Anthropic API: empty content array' );
		}
		foreach ( $decoded['content'] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['name'] ?? '' ) === $tool_name ) {
				return is_array( $block['input'] ?? null ) ? $block['input'] : [];
			}
		}
		throw new RuntimeException( 'Anthropic API: tool_use block not present' );
	}

	public static function api_key(): string {
		$encrypted = (string) get_option( 'amplifi_security_anthropic_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return Secret_Store::try_decrypt( $encrypted ) ?? '';
	}

	public static function set_api_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( 'amplifi_security_anthropic_key' );
			return;
		}
		update_option( 'amplifi_security_anthropic_key', Secret_Store::encrypt( $key ), false );
	}

	/**
	 * Tiny test call to validate a key without spending real tokens. Uses 1
	 * input token of "ping" with `max_tokens=1`.
	 */
	public static function ping( string $key, string $model = self::DEFAULT_MODEL ): bool {
		if ( '' === $key ) {
			return false;
		}
		$resp = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => [
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'      => $model,
					'max_tokens' => 1,
					'messages'   => [ [ 'role' => 'user', 'content' => 'ping' ] ],
				] ),
			]
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Approximate USD cost from token usage.
	 * Pricing as of 2026-Q1 (in USD per million tokens):
	 *   - Haiku 4.5:   input $1.00 / output $5.00
	 *   - Sonnet 4.6:  input $3.00 / output $15.00
	 */
	public static function estimate_cost( string $model, int $input_tokens, int $output_tokens ): float {
		[ $in_per_m, $out_per_m ] = match ( $model ) {
			'claude-sonnet-4-6' => [ 3.0, 15.0 ],
			default              => [ 1.0, 5.0 ],
		};
		return round( ( $input_tokens / 1_000_000 ) * $in_per_m + ( $output_tokens / 1_000_000 ) * $out_per_m, 6 );
	}
}
