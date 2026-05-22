<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds Anthropic prompt messages for per-post and per-global schema generation.
 *
 * @package Amplifi\Schema\AI
 */
final class Prompt_Builder {

	/**
	 * Rough heuristic: 1 token ~= 4 chars of English.
	 * Keeps the head (70%) and tail (20%) of long text, inserting a truncation notice.
	 */
	public static function trim_to_token_budget( string $text, int $budget_tokens ): string {
		$max_chars = $budget_tokens * 4;
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}
		$head = substr( $text, 0, (int) ( $max_chars * 0.7 ) );
		$tail = substr( $text, -(int) ( $max_chars * 0.2 ) );
		return $head . "\n\n[...content truncated...]\n\n" . $tail;
	}

	/**
	 * Build system + user messages for per-post schema generation.
	 *
	 * @param array{title: string, url: string, post_type: string, content: string, existing: array|null} $ctx
	 * @param int $content_budget_tokens Max tokens to allocate for page content.
	 * @return array{system: string, user: string}
	 */
	public static function build_for_post( array $ctx, int $content_budget_tokens = 6000 ): array {
		$system = 'You generate schema.org JSON-LD for web pages. Pick the most specific @type from schema.org '
			. 'that fits the content. Return strictly valid JSON-LD that would pass Google Rich Results '
			. 'validation. Use https://schema.org as @context. Do not invent properties.';

		$existing      = $ctx['existing'] ?? null;
		$existing_note = $existing
			? "\nExisting schema (revise it):\n" . self::encode_json( $existing )
			: '';

		$content = self::trim_to_token_budget( (string) ( $ctx['content'] ?? '' ), $content_budget_tokens );

		$user = "Title: {$ctx['title']}\nURL: {$ctx['url']}\nPost type: {$ctx['post_type']}\n\nContent:\n{$content}{$existing_note}";

		return [ 'system' => $system, 'user' => $user ];
	}

	/**
	 * Build system + user messages for site-global schema generation.
	 *
	 * @param string $key  One of: organization, website, localbusiness (falls back to Thing).
	 * @param array  $site_ctx Site context data (name, url, etc.).
	 * @return array{system: string, user: string}
	 */
	public static function build_for_global( string $key, array $site_ctx ): array {
		$system = 'You generate schema.org JSON-LD describing the site itself. Return strictly valid JSON-LD.';

		$type = match ( $key ) {
			'organization'  => 'Organization',
			'website'       => 'WebSite',
			'localbusiness' => 'LocalBusiness',
			default         => 'Thing',
		};

		$user = "Generate a $type JSON-LD entity for this site:\n" . self::encode_json( $site_ctx );

		return [ 'system' => $system, 'user' => $user ];
	}

	/**
	 * JSON-encode a value, using wp_json_encode when available (production)
	 * and falling back to json_encode for unit tests (no WP loaded).
	 */
	private static function encode_json( mixed $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}
		return (string) json_encode( $data );
	}
}
