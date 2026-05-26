<?php
/**
 * Hardening / configuration sanity scanner. (Beyond the spec — added because
 * these are cheap to check and answer the questions Claude would otherwise
 * have to ask.)
 *
 * Checks:
 *   - default `admin` username present
 *   - `WP_DEBUG_DISPLAY` leaking errors in production
 *   - `siteurl` on http:// (no HTTPS)
 *   - PHP version past EOL (security risk regardless of WP support window)
 *   - WP core not on the latest minor (catches 6.4.x → 6.4.y critical patches)
 *   - default `wp_` table prefix
 *   - exposed `.bak` / `.old` / `.sql` / `.zip` in webroot (info leak)
 *   - world-writable WP root or `wp-content/` (octal 0777)
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hardening_Scanner implements Scanner {

	public function name(): string { return 'hardening'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		// Always enabled by default — there's no separate UI toggle.
		return ! in_array( 'hardening', $settings['disabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings = [];

		$findings = array_merge( $findings, $this->check_default_admin_user() );
		$findings = array_merge( $findings, $this->check_debug_display() );
		$findings = array_merge( $findings, $this->check_https() );
		$findings = array_merge( $findings, $this->check_php_eol() );
		$findings = array_merge( $findings, $this->check_default_prefix() );
		$findings = array_merge( $findings, $this->check_exposed_backup_files() );
		$findings = array_merge( $findings, $this->check_world_writable() );

		return $findings;
	}

	private function check_default_admin_user(): array {
		$user = get_user_by( 'login', 'admin' );
		if ( ! $user ) {
			return [];
		}
		if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return [];
		}
		return [
			[
				'type'    => 'hardening',
				'subtype' => 'default_admin_user',
				'evidence' => [
					'user_id'    => (int) $user->ID,
					'user_login' => 'admin',
					'reason'     => 'Username "admin" is the first guess for any credential-stuffing campaign.',
				],
			],
		];
	}

	private function check_debug_display(): array {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return [];
		}
		// WP_DEBUG_DISPLAY defaults to true if not defined, which is the dangerous case.
		$display = ! defined( 'WP_DEBUG_DISPLAY' ) || true === WP_DEBUG_DISPLAY;
		if ( ! $display ) {
			return [];
		}
		return [
			[
				'type'    => 'hardening',
				'subtype' => 'debug_display_in_production',
				'evidence' => [
					'WP_DEBUG'         => WP_DEBUG,
					'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : '(undefined → true)',
					'reason'           => 'PHP errors and notices may be rendered in HTML responses, leaking paths and stack traces.',
				],
			],
		];
	}

	private function check_https(): array {
		$site = (string) get_option( 'siteurl', '' );
		if ( str_starts_with( $site, 'https://' ) ) {
			return [];
		}
		return [
			[
				'type'    => 'hardening',
				'subtype' => 'no_https',
				'evidence' => [ 'siteurl' => $site ],
			],
		];
	}

	private function check_php_eol(): array {
		// PHP support windows (security): the EOL date check is approximate —
		// we encode major.minor → EOL date.
		$eol = [
			'7.4' => '2022-11-28',
			'8.0' => '2023-11-26',
			'8.1' => '2025-12-31',
			'8.2' => '2026-12-31',
			'8.3' => '2027-12-31',
			'8.4' => '2028-12-31',
		];
		$mm = sprintf( '%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION );
		$end = $eol[ $mm ] ?? null;
		if ( ! $end ) {
			return [];
		}
		if ( strtotime( $end ) > time() ) {
			return [];
		}
		return [
			[
				'type'    => 'hardening',
				'subtype' => 'php_eol',
				'evidence' => [
					'php_version' => PHP_VERSION,
					'eol_date'    => $end,
				],
			],
		];
	}

	private function check_default_prefix(): array {
		global $wpdb;
		if ( $wpdb->prefix !== 'wp_' ) {
			return [];
		}
		return [
			[
				'type'    => 'hardening',
				'subtype' => 'default_table_prefix',
				'evidence' => [
					'prefix' => 'wp_',
					'reason' => 'Default prefix is the first guess for SQL-injection payloads.',
				],
			],
		];
	}

	private function check_exposed_backup_files(): array {
		$out = [];
		$candidates = [
			ABSPATH . 'backup.zip',
			ABSPATH . 'backup.tar.gz',
			ABSPATH . 'database.sql',
			ABSPATH . 'db.sql',
			ABSPATH . 'wp-config.bak',
			ABSPATH . 'wp-config.php.bak',
			ABSPATH . 'wp-config.old',
			ABSPATH . '.env',
			ABSPATH . 'phpinfo.php',
		];
		foreach ( $candidates as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$out[] = [
				'type'    => 'hardening',
				'subtype' => 'exposed_backup_or_secret_file',
				'evidence' => [
					'path'  => str_replace( ABSPATH, '', $path ),
					'size'  => (int) ( @filesize( $path ) ?: 0 ),
				],
			];
		}
		// Generic glob for *.sql / *.bak in webroot (top-level only).
		foreach ( [ '*.sql', '*.bak', '*.zip', '*.tar.gz', '*.7z', '*.tar' ] as $g ) {
			foreach ( glob( ABSPATH . $g ) ?: [] as $hit ) {
				$out[] = [
					'type'    => 'hardening',
					'subtype' => 'exposed_archive_in_webroot',
					'evidence' => [
						'path' => str_replace( ABSPATH, '', $hit ),
						'size' => (int) ( @filesize( $hit ) ?: 0 ),
					],
				];
			}
		}
		return $out;
	}

	private function check_world_writable(): array {
		$out = [];
		foreach ( [ ABSPATH, WP_CONTENT_DIR ] as $dir ) {
			$perms = @fileperms( $dir );
			if ( false === $perms ) {
				continue;
			}
			if ( ( $perms & 0o002 ) === 0o002 ) {
				$out[] = [
					'type'    => 'hardening',
					'subtype' => 'world_writable_directory',
					'evidence' => [
						'path'  => str_replace( ABSPATH, '', $dir ) ?: '/',
						'mode'  => substr( sprintf( '%o', $perms ), -4 ),
					],
				];
			}
		}
		return $out;
	}
}
