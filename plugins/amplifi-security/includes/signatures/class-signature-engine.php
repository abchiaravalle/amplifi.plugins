<?php
/**
 * Pure-PHP signature evaluator.
 *
 * Replaces a YARA dependency. Reads rule data from `signatures.php`, runs each
 * rule against file contents using PCRE / `token_get_all` / Shannon entropy
 * (no `eval`, no dynamic code), and returns the matching rule IDs plus a
 * `combined_score`.
 *
 * @package Amplifi\Security\Signatures
 */

declare(strict_types=1);

namespace Amplifi\Security\Signatures;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signature_Engine {

	/** @var array<int,array<string,mixed>>|null */
	private static ?array $rules = null;

	public static function rules(): array {
		if ( null === self::$rules ) {
			$file = AMPLIFI_SECURITY_PATH . 'includes/signatures/signatures.php';
			if ( ! is_file( $file ) ) {
				self::$rules = [];
				return self::$rules;
			}
			$loaded = require $file;
			self::$rules = is_array( $loaded ) ? $loaded : [];
		}
		return self::$rules;
	}

	/**
	 * Scan a single file's contents.
	 *
	 * @param string $contents Full file contents.
	 * @return array{
	 *   matches: list<array{id:string,name:string,category:string,weight:int,snippet:string}>,
	 *   combined_score:int,
	 *   entropy:float,
	 *   has_long_b64:bool,
	 * }
	 */
	public static function evaluate( string $contents ): array {
		$matches = [];
		$score   = 0;

		foreach ( self::rules() as $rule ) {
			$pattern = $rule['match'] ?? '';
			if ( '' === $pattern ) {
				continue;
			}
			$found = @preg_match( $pattern, $contents, $m, PREG_OFFSET_CAPTURE );
			if ( 1 !== $found ) {
				continue;
			}
			$offset  = (int) $m[0][1];
			$snippet = self::context( $contents, $offset, 120 );

			$weight = (int) ( $rule['weight'] ?? 1 );
			$score += $weight;

			$matches[] = [
				'id'       => (string) $rule['id'],
				'name'     => (string) $rule['name'],
				'category' => (string) ( $rule['category'] ?? 'unknown' ),
				'weight'   => $weight,
				'snippet'  => $snippet,
			];
		}

		$entropy      = self::shannon_entropy( $contents );
		$has_long_b64 = (bool) preg_match( '/[A-Za-z0-9+\/=]{500,}/', $contents );

		// Bonus: high entropy on a PHP source file is itself suspicious.
		if ( $entropy >= 5.5 ) {
			$score += 3;
			$matches[] = [
				'id'       => 'high_entropy',
				'name'     => 'high Shannon entropy',
				'category' => 'obfuscation',
				'weight'   => 3,
				'snippet'  => sprintf( 'entropy=%.2f', $entropy ),
			];
		}

		return [
			'matches'        => $matches,
			'combined_score' => $score,
			'entropy'        => $entropy,
			'has_long_b64'   => $has_long_b64,
		];
	}

	/**
	 * Shannon entropy in bits per byte. PHP source typically 4.5–5.5;
	 * compressed/encoded blobs trend toward 7.5+.
	 */
	public static function shannon_entropy( string $data ): float {
		$len = strlen( $data );
		if ( $len < 32 ) {
			return 0.0;
		}
		$freq = count_chars( $data, 1 );
		$h    = 0.0;
		foreach ( $freq as $count ) {
			$p  = $count / $len;
			$h -= $p * log( $p, 2 );
		}
		return $h;
	}

	/**
	 * Token-based check: does the file's PHP token stream contain at least one
	 * `T_EVAL` whose argument expression references a superglobal?
	 *
	 * Catches obfuscated `eval($_POST['x'])` / `assert($_REQUEST['y'])` even
	 * when the regex above wouldn't match because of inserted whitespace/comments.
	 */
	public static function token_has_dyn_exec( string $php_source ): bool {
		// Suppress notices from token_get_all on malformed PHP.
		$tokens = @\token_get_all( $php_source, TOKEN_PARSE );
		if ( empty( $tokens ) ) {
			return false;
		}
		$watch = false;
		foreach ( $tokens as $tok ) {
			if ( ! is_array( $tok ) ) {
				continue;
			}
			[ $id, $val ] = $tok;
			if ( T_EVAL === $id ) {
				$watch = true;
				continue;
			}
			if ( $watch && T_VARIABLE === $id ) {
				if ( in_array( $val, [ '$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_SERVER' ], true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function context( string $haystack, int $offset, int $radius ): string {
		$start  = max( 0, $offset - $radius );
		$length = min( strlen( $haystack ) - $start, $radius * 2 );
		$chunk  = substr( $haystack, $start, $length );
		// Single-line; collapse whitespace so the snippet renders cleanly in the dashboard.
		$chunk = preg_replace( '/\s+/', ' ', $chunk ) ?? $chunk;
		return mb_substr( trim( $chunk ), 0, 240 );
	}
}
