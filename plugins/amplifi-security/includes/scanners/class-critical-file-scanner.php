<?php
/**
 * Critical-file watch.
 *
 * Tracks the highest-value persistence targets at every scan, with stricter
 * defaults than the broad integrity scanner:
 *
 *   - `.htaccess`            — top attacker target (rewrites, PHP handler tricks).
 *   - `wp-config.php`        — DB creds, security keys, custom hooks. Diffs are
 *     produced *with secret-pattern lines redacted*; the file content itself is
 *     never sent to Claude — only a redacted diff and metadata.
 *   - `wp-content/mu-plugins/*.php` — auto-loaded, no UI to disable.
 *   - WP dropins             — `object-cache.php`, `advanced-cache.php`, `db.php`,
 *     `maintenance.php`, `sunrise.php` (multisite).
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Critical_File_Scanner implements Scanner {

	private const SECRET_LINE_RE = '/(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|DB_PASSWORD|DB_USER|DB_NAME|DB_HOST)/i';
	private const STORE_OPTION   = 'amplifi_security_critical_file_state';

	public function name(): string { return 'critical_file'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'critical_file', $settings['enabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings = [];
		$state    = (array) get_option( self::STORE_OPTION, [] );
		$next     = [];

		foreach ( $this->targets() as $label => $abs ) {
			if ( ! is_file( $abs ) ) {
				if ( isset( $state[ $label ] ) ) {
					$findings[] = [
						'type'    => 'critical_file',
						'subtype' => 'missing',
						'evidence' => [
							'label'    => $label,
							'path'     => $this->rel( $abs ),
							'previous' => $state[ $label ],
							'change'   => 'removed',
						],
					];
				}
				continue;
			}

			$contents = @file_get_contents( $abs );
			if ( false === $contents ) {
				continue;
			}
			$hash = hash( 'sha256', $contents );
			$next[ $label ] = [
				'path'  => $this->rel( $abs ),
				'sha256' => $hash,
				'size'  => strlen( $contents ),
				'mtime' => (int) filemtime( $abs ),
			];

			if ( ! isset( $state[ $label ] ) ) {
				// First scan: record but flag as worth_reviewing — we don't have
				// prior state to know if the current content is already malicious.
				$findings[] = [
					'type'    => 'critical_file',
					'subtype' => 'first_seen',
					'evidence' => [
						'label'  => $label,
						'path'   => $this->rel( $abs ),
						'sha256' => $hash,
						'size'   => strlen( $contents ),
					],
				];
				continue;
			}

			$prior = $state[ $label ];
			if ( hash_equals( (string) ( $prior['sha256'] ?? '' ), $hash ) ) {
				continue; // unchanged
			}

			// Build a redacted diff.
			$prior_contents = (string) ( $prior['contents'] ?? '' );
			$diff           = $this->build_redacted_diff( $prior_contents, $contents );

			$findings[] = [
				'type'    => 'critical_file',
				'subtype' => $label . '_modified',
				'evidence' => [
					'label'        => $label,
					'path'         => $this->rel( $abs ),
					'prior_sha256' => $prior['sha256'] ?? null,
					'sha256'       => $hash,
					'size'         => strlen( $contents ),
					'mtime'        => gmdate( 'Y-m-d\TH:i:s\Z', (int) filemtime( $abs ) ),
					'diff_redacted' => $diff,
				],
			];
		}

		// Persist current state for next-run comparison. Store contents only for
		// non-secret files; for wp-config we only keep hash+meta.
		foreach ( $next as $label => &$entry ) {
			if ( 'wp-config.php' === $label ) {
				continue; // never persist contents
			}
			$abs = $this->targets()[ $label ] ?? null;
			if ( $abs && is_file( $abs ) ) {
				$entry['contents'] = (string) @file_get_contents( $abs );
			}
		}
		update_option( self::STORE_OPTION, $next, false );

		return $findings;
	}

	/**
	 * @return array<string,string>
	 */
	private function targets(): array {
		$out = [
			'.htaccess'      => ABSPATH . '.htaccess',
			'wp-config.php'  => ABSPATH . 'wp-config.php',
		];
		// MU plugins.
		$mu_dir = WPMU_PLUGIN_DIR;
		if ( is_dir( $mu_dir ) ) {
			foreach ( glob( $mu_dir . '/*.php' ) ?: [] as $file ) {
				$out[ 'mu-plugins/' . basename( $file ) ] = $file;
			}
		}
		// Dropins.
		foreach ( [ 'object-cache.php', 'advanced-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'sunrise.php', 'install.php', 'fatal-error-handler.php' ] as $name ) {
			$path = WP_CONTENT_DIR . '/' . $name;
			if ( is_file( $path ) ) {
				$out[ 'dropin/' . $name ] = $path;
			}
		}
		return $out;
	}

	private function rel( string $abs ): string {
		$abs  = str_replace( '\\', '/', $abs );
		$base = str_replace( '\\', '/', untrailingslashit( ABSPATH ) );
		return str_starts_with( $abs, $base ) ? ltrim( substr( $abs, strlen( $base ) ), '/' ) : $abs;
	}

	/**
	 * Produce a unified-diff-ish summary with secret lines redacted.
	 * Don't ship `diff` binary as a dependency — we generate a line-level diff in PHP.
	 */
	private function build_redacted_diff( string $before, string $after ): string {
		$a = preg_split( '/\R/', $before ) ?: [];
		$b = preg_split( '/\R/', $after )  ?: [];
		$out = [];
		$i = $j = 0;
		$max = max( count( $a ), count( $b ) );
		// Naïve line-by-line walk; this is for evidence display, not a real diff algo.
		while ( $i < count( $a ) || $j < count( $b ) ) {
			$la = $a[ $i ] ?? null;
			$lb = $b[ $j ] ?? null;
			if ( $la === $lb ) {
				$i++; $j++;
				continue;
			}
			if ( null !== $la && ( null === $lb || ! in_array( $la, array_slice( $b, $j, 5 ), true ) ) ) {
				$out[] = '- ' . self::redact_secret_line( $la );
				$i++;
			} elseif ( null !== $lb ) {
				$out[] = '+ ' . self::redact_secret_line( $lb );
				$j++;
			}
			if ( count( $out ) > 400 ) {
				$out[] = '… (truncated)';
				break;
			}
		}
		return implode( "\n", $out );
	}

	private static function redact_secret_line( string $line ): string {
		if ( preg_match( self::SECRET_LINE_RE, $line ) ) {
			return preg_replace( '/[\'"][^\'"]+[\'"]/', '"[redacted]"', $line ) ?? '[redacted]';
		}
		return $line;
	}
}
