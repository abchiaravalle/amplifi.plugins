<?php
/**
 * Shell / backdoor scanner.
 *
 * Walks `wp-content/`, `wp-includes/`, `wp-admin/`, and the WP root, hashing
 * every PHP file and running the `Signature_Engine` against its contents.
 * Files in `wp-content/uploads/**\/*.php` are flagged regardless of signature
 * matches — PHP in uploads is almost always malicious.
 *
 * Excludes:
 *   - `wp-content/cache/` and any user-configured glob exclusions
 *   - the plugin's own files (covered by Self_Integrity)
 *   - files larger than 4 MB (almost certainly not a shell, and reading them
 *     would balloon scan time)
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

use Amplifi\Security\Signatures\Signature_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shell_Scanner implements Scanner {

	private const MAX_FILE_SIZE = 4_194_304; // 4 MB
	private const MAX_FILES_PER_RUN = 8000;

	public function name(): string { return 'shell'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'shell', $settings['enabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings   = [];
		$exclusions = $this->compile_exclusions();
		$count      = 0;

		foreach ( $this->iterate_php_files() as $path ) {
			if ( $count >= self::MAX_FILES_PER_RUN ) {
				break;
			}
			$count++;

			$rel = $this->rel( $path );
			if ( $this->is_excluded( $rel, $exclusions ) ) {
				continue;
			}

			$size = @filesize( $path );
			if ( false === $size || $size > self::MAX_FILE_SIZE ) {
				continue;
			}

			$contents = @file_get_contents( $path );
			if ( false === $contents ) {
				continue;
			}

			$result = Signature_Engine::evaluate( $contents );
			$in_uploads = str_contains( $rel, '/wp-content/uploads/' );

			$dyn_exec = false;
			if ( str_starts_with( ltrim( $contents ), '<?php' ) || str_contains( $contents, '<?php' ) ) {
				$dyn_exec = Signature_Engine::token_has_dyn_exec( $contents );
			}

			// Decision: any match in 'shell' or 'prompt_injection' category, or
			// combined_score >= 7, or any PHP file inside uploads, or token-level
			// dynamic exec → produce a finding.
			$is_finding = $in_uploads || $dyn_exec;
			foreach ( $result['matches'] as $m ) {
				if ( in_array( $m['category'], [ 'shell', 'prompt_injection' ], true ) ) {
					$is_finding = true;
				}
			}
			if ( $result['combined_score'] >= 7 ) {
				$is_finding = true;
			}

			if ( ! $is_finding ) {
				continue;
			}

			$findings[] = [
				'type'    => $in_uploads ? 'shell_in_uploads' : 'suspicious_php',
				'subtype' => $dyn_exec ? 'dynamic_exec_token' : null,
				'evidence' => [
					'path'           => $rel,
					'size'           => (int) $size,
					'mtime'          => gmdate( 'Y-m-d\TH:i:s\Z', (int) filemtime( $path ) ),
					'sha256'         => hash( 'sha256', $contents ),
					'entropy'        => round( $result['entropy'], 3 ),
					'combined_score' => $result['combined_score'],
					'matches'        => $result['matches'],
					'token_dyn_exec' => $dyn_exec,
					'in_uploads'     => $in_uploads,
				],
			];
		}

		return $findings;
	}

	/**
	 * Yield PHP file paths under WP roots (skipping the plugin's own dir).
	 *
	 * @return iterable<string>
	 */
	private function iterate_php_files(): iterable {
		$roots = array_unique(
			array_filter(
				[
					untrailingslashit( ABSPATH ),
					WP_CONTENT_DIR,
					ABSPATH . 'wp-includes',
					ABSPATH . 'wp-admin',
				]
			)
		);

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
					function ( $current ) {
						$path = $current->getPathname();
						// Skip our own plugin dir to avoid false-flagging signature definitions.
						if ( str_starts_with( $path, untrailingslashit( AMPLIFI_SECURITY_PATH ) ) ) {
							return false;
						}
						// Skip cache & node_modules & vendor early.
						foreach ( [ '/wp-content/cache/', '/node_modules/', '/vendor/' ] as $skip ) {
							if ( str_contains( $path, $skip ) ) {
								return false;
							}
						}
						return true;
					}
				)
			);
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				if ( strtolower( $file->getExtension() ) !== 'php' ) {
					continue;
				}
				yield $file->getPathname();
			}
		}
	}

	private function compile_exclusions(): array {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$globs    = (array) ( $settings['file_exclusions'] ?? [] );
		$out      = [];
		foreach ( $globs as $g ) {
			$g = (string) $g;
			if ( '' === $g ) {
				continue;
			}
			$pattern = '#^' . str_replace( [ '\\*\\*', '\\*', '\\?' ], [ '.*', '[^/]*', '.' ], preg_quote( $g, '#' ) ) . '$#i';
			$out[]   = $pattern;
		}
		return $out;
	}

	private function is_excluded( string $rel, array $patterns ): bool {
		foreach ( $patterns as $p ) {
			if ( preg_match( $p, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	private function rel( string $abs ): string {
		$abs  = str_replace( '\\', '/', $abs );
		$base = str_replace( '\\', '/', untrailingslashit( ABSPATH ) );
		return str_starts_with( $abs, $base ) ? substr( $abs, strlen( $base ) ) : $abs;
	}
}
