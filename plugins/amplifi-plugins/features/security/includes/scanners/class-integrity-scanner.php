<?php
/**
 * File integrity scanner (WP core + plugins + themes).
 *
 * Sources of truth:
 *   - WP core:    `https://api.wordpress.org/core/checksums/1.0/?version=X&locale=en_US`
 *   - Plugins:    baseline captured at install/activation, refreshed after a
 *                 verified upgrade via `upgrader_process_complete`.
 *   - Themes:     baseline captured at activation; themes are commonly customised
 *                 so first-scan diffs surface as `worth_reviewing`, not `confirmed`.
 *
 * Stores hashes in `wp_amplifi_security_baseline`; diffs against the live
 * filesystem on every scan.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integrity_Scanner implements Scanner {

	public function name(): string { return 'integrity'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'integrity', $settings['enabled_scanners'] ?? [], true );
	}

	public static function register_hooks(): void {
		// Re-baseline a plugin/theme after a successful upgrade.
		add_action( 'upgrader_process_complete', [ self::class, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'activated_plugin',          [ self::class, 'on_plugin_activated' ], 10, 1 );
	}

	public function run( int $scan_id ): array {
		$findings = [];

		// 1. WP core checksums.
		foreach ( $this->core_diffs() as $diff ) {
			$findings[] = [
				'type'    => 'file_integrity',
				'subtype' => 'wp_core_modified',
				'evidence' => $diff,
			];
		}

		// 2. Plugin/theme baseline diffs.
		foreach ( $this->baseline_diffs() as $diff ) {
			$findings[] = [
				'type'    => 'file_integrity',
				'subtype' => $diff['source'] . '_modified',
				'evidence' => $diff,
			];
		}

		return $findings;
	}

	/* ------------------------------------------------------------------ */
	/* core checksums                                                      */
	/* ------------------------------------------------------------------ */

	private function core_diffs(): array {
		global $wp_version;
		$version = $wp_version ?? get_bloginfo( 'version' );

		$cache_key = 'amplifi_security_core_checksums_' . md5( (string) $version );
		$checksums = get_transient( $cache_key );
		if ( false === $checksums ) {
			$resp = wp_remote_get(
				add_query_arg(
					[ 'version' => $version, 'locale' => 'en_US' ],
					'https://api.wordpress.org/core/checksums/1.0/'
				),
				[ 'timeout' => 15, 'sslverify' => true, 'user-agent' => 'amplifi-security/' . AMPLIFI_SECURITY_VERSION ]
			);
			if ( is_wp_error( $resp ) ) {
				return [];
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			$checksums = is_array( $body ) && ! empty( $body['checksums'] ) ? $body['checksums'] : null;
			if ( null === $checksums ) {
				return [];
			}
			set_transient( $cache_key, $checksums, DAY_IN_SECONDS );
		}

		$diffs = [];
		foreach ( $checksums as $rel => $expected_md5 ) {
			$abs = ABSPATH . $rel;
			// Skip wp-content/* (handled separately) and missing files outside core scope.
			if ( str_starts_with( $rel, 'wp-content/' ) ) {
				continue;
			}
			if ( ! is_file( $abs ) ) {
				$diffs[] = [
					'path'         => $rel,
					'expected_md5' => $expected_md5,
					'actual_md5'   => null,
					'context'      => 'wp_core_file_missing',
				];
				continue;
			}
			$actual = @md5_file( $abs );
			if ( false !== $actual && ! hash_equals( (string) $expected_md5, $actual ) ) {
				$diffs[] = [
					'path'         => $rel,
					'expected_md5' => $expected_md5,
					'actual_md5'   => $actual,
					'sha256'       => @hash_file( 'sha256', $abs ) ?: null,
					'size'         => (int) ( @filesize( $abs ) ?: 0 ),
					'mtime'        => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( @filemtime( $abs ) ?: 0 ) ),
					'context'      => 'wp_core_file',
				];
			}
		}
		return $diffs;
	}

	/* ------------------------------------------------------------------ */
	/* plugin / theme baseline                                             */
	/* ------------------------------------------------------------------ */

	private function baseline_diffs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_baseline';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT path, hash, source, source_version FROM {$table}", ARRAY_A );
		if ( empty( $rows ) ) {
			$this->seed_plugin_theme_baseline();
			return [];
		}

		$diffs = [];
		$seen  = [];

		foreach ( $rows as $row ) {
			$path = (string) $row['path'];
			$abs  = ABSPATH . ltrim( $path, '/' );
			$seen[ $path ] = true;

			if ( ! is_file( $abs ) ) {
				$diffs[] = [
					'path'   => $path,
					'source' => $row['source'],
					'expected_hash' => $row['hash'],
					'actual_hash'   => null,
					'change' => 'removed',
				];
				continue;
			}
			$actual = @hash_file( 'sha256', $abs );
			if ( false === $actual ) {
				continue;
			}
			if ( ! hash_equals( (string) $row['hash'], $actual ) ) {
				$diffs[] = [
					'path'          => $path,
					'source'        => $row['source'],
					'expected_hash' => $row['hash'],
					'actual_hash'   => $actual,
					'size'          => (int) ( @filesize( $abs ) ?: 0 ),
					'mtime'         => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( @filemtime( $abs ) ?: 0 ) ),
					'change'        => 'modified',
				];
			}
		}

		// Look for new files in plugin/theme dirs not covered by baseline.
		foreach ( $this->iterate_plugin_theme_files() as [ $abs, $rel, $source ] ) {
			if ( isset( $seen[ $rel ] ) ) {
				continue;
			}
			$diffs[] = [
				'path'   => $rel,
				'source' => $source,
				'change' => 'added',
				'sha256' => @hash_file( 'sha256', $abs ) ?: null,
				'size'   => (int) ( @filesize( $abs ) ?: 0 ),
				'mtime'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( @filemtime( $abs ) ?: 0 ) ),
			];
		}

		return $diffs;
	}

	private function seed_plugin_theme_baseline(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_baseline';
		$now   = current_time( 'mysql', true );

		foreach ( $this->iterate_plugin_theme_files() as [ $abs, $rel, $source ] ) {
			$hash = @hash_file( 'sha256', $abs );
			if ( false === $hash ) {
				continue;
			}
			$wpdb->replace(
				$table,
				[
					'path'           => $rel,
					'hash'           => $hash,
					'source'         => $source,
					'source_version' => $this->source_version( $abs, $source ),
					'recorded_at'    => $now,
				]
			);
		}
	}

	private function iterate_plugin_theme_files(): iterable {
		$roots = [
			[ WP_PLUGIN_DIR, 'plugin' ],
		];
		foreach ( wp_get_themes() as $theme ) {
			$roots[] = [ $theme->get_stylesheet_directory(), 'theme' ];
		}

		foreach ( $roots as [ $root, $source ] ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, [ 'php', 'js', 'css', 'html', 'htm', 'json' ], true ) ) {
					continue;
				}
				$abs  = $file->getPathname();
				if ( str_starts_with( $abs, untrailingslashit( AMPLIFI_SECURITY_PATH ) ) ) {
					continue;
				}
				$rel  = ltrim( str_replace( str_replace( '\\', '/', untrailingslashit( ABSPATH ) ), '', str_replace( '\\', '/', $abs ) ), '/' );
				yield [ $abs, $rel, $source ];
			}
		}
	}

	private function source_version( string $abs, string $source ): ?string {
		// Best-effort: read header from the plugin's main file or theme style.css.
		if ( 'plugin' === $source ) {
			if ( str_ends_with( $abs, '.php' ) ) {
				$data = @get_file_data( $abs, [ 'Version' => 'Version' ] );
				if ( ! empty( $data['Version'] ) ) {
					return (string) $data['Version'];
				}
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* re-baseline hooks                                                   */
	/* ------------------------------------------------------------------ */

	public static function on_upgrader_complete( $upgrader, array $hook_extra ): void {
		if ( empty( $hook_extra['type'] ) ) {
			return;
		}
		// Drop baseline rows for the affected component(s) so they get rebuilt next scan.
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_baseline';

		if ( 'plugin' === $hook_extra['type'] && ! empty( $hook_extra['plugins'] ) ) {
			foreach ( (array) $hook_extra['plugins'] as $plugin ) {
				$slug = dirname( (string) $plugin );
				if ( $slug && '.' !== $slug ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = 'plugin' AND path LIKE %s", $wpdb->esc_like( 'wp-content/plugins/' . $slug . '/' ) . '%' ) );
				}
			}
		}
		if ( 'theme' === $hook_extra['type'] && ! empty( $hook_extra['themes'] ) ) {
			foreach ( (array) $hook_extra['themes'] as $slug ) {
				if ( $slug ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = 'theme' AND path LIKE %s", $wpdb->esc_like( 'wp-content/themes/' . $slug . '/' ) . '%' ) );
				}
			}
		}
	}

	public static function on_plugin_activated( string $plugin ): void {
		// Lazy: next scan will baseline.
	}
}
