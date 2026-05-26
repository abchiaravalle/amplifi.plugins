<?php
/**
 * Self-integrity scanner — checks that the plugin's own files haven't been tampered with.
 *
 * Strategy:
 *   - At activation, walk the plugin directory, compute SHA-256 of every PHP/JSON/MMDB
 *     file, store the per-file map and a Merkle-style root hash.
 *   - On every scheduled scan, recompute and diff. Any mismatch fires a `confirmed`
 *     finding in category `core_tampering` and flips `amplifi_security_self_integrity_ok`
 *     to `0` (the canary will start returning `last_triage_ok: false`).
 *   - Plugin does NOT self-repair — that's the user's job — but does drop into
 *     "naive mode" (signature-only, no Claude calls) until the installer manually
 *     re-validates.
 *
 * @package Amplifi\Security\Self_Defense
 */

declare(strict_types=1);

namespace Amplifi\Security\Self_Defense;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Self_Integrity {

	private const BASELINE_OPTION = 'amplifi_security_self_baseline';
	private const ROOT_OPTION     = 'amplifi_security_self_baseline_hash';
	private const STATUS_OPTION   = 'amplifi_security_self_integrity_ok';

	public static function record_baseline(): void {
		$map  = self::compute_file_map();
		$root = self::root_hash( $map );

		update_option( self::BASELINE_OPTION, $map, false );
		update_option( self::ROOT_OPTION, $root, false );
		update_option( self::STATUS_OPTION, '1', false );

		Audit_Logger::log(
			'self_baseline_recorded',
			[
				'file_count' => count( $map ),
				'root_hash'  => $root,
			]
		);
	}

	/**
	 * Verify against the recorded baseline.
	 *
	 * @return array{ok:bool,changed:string[],added:string[],removed:string[]}
	 */
	public static function verify(): array {
		$baseline = get_option( self::BASELINE_OPTION );
		if ( ! is_array( $baseline ) ) {
			// No baseline → record one now and treat as OK.
			self::record_baseline();
			return [ 'ok' => true, 'changed' => [], 'added' => [], 'removed' => [] ];
		}

		$current = self::compute_file_map();

		$changed = [];
		$added   = [];
		$removed = [];

		foreach ( $current as $path => $hash ) {
			if ( ! isset( $baseline[ $path ] ) ) {
				$added[] = $path;
			} elseif ( ! hash_equals( $baseline[ $path ], $hash ) ) {
				$changed[] = $path;
			}
		}
		foreach ( $baseline as $path => $hash ) {
			if ( ! isset( $current[ $path ] ) ) {
				$removed[] = $path;
			}
		}

		$ok = empty( $changed ) && empty( $added ) && empty( $removed );
		update_option( self::STATUS_OPTION, $ok ? '1' : '0', false );

		if ( ! $ok ) {
			Audit_Logger::log(
				'self_integrity_failed',
				[
					'changed' => array_slice( $changed, 0, 50 ),
					'added'   => array_slice( $added,   0, 50 ),
					'removed' => array_slice( $removed, 0, 50 ),
				]
			);
		}

		return [
			'ok'      => $ok,
			'changed' => $changed,
			'added'   => $added,
			'removed' => $removed,
		];
	}

	public static function root_hash_stored(): string {
		return (string) get_option( self::ROOT_OPTION, '' );
	}

	public static function is_ok(): bool {
		return '1' === (string) get_option( self::STATUS_OPTION, '1' );
	}

	private static function compute_file_map(): array {
		$base = realpath( AMPLIFI_SECURITY_PATH );
		if ( false === $base ) {
			return [];
		}
		$base_len = strlen( $base ) + 1;

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS )
		);

		$map = [];
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getPathname();
			$ext  = strtolower( $file->getExtension() );
			// Hash every code/data file. Skip transient/test/build artifacts.
			if ( ! in_array( $ext, [ 'php', 'json', 'js', 'css', 'mmdb', 'txt', 'md', 'pot' ], true ) ) {
				continue;
			}
			$rel = substr( $path, $base_len );
			// Don't include the baseline file itself if it ever lands on disk.
			if ( str_contains( $rel, '/.git/' ) || str_contains( $rel, '/tests/fixtures/' ) ) {
				continue;
			}
			$hash = @hash_file( 'sha256', $path );
			if ( false === $hash ) {
				continue;
			}
			$map[ str_replace( '\\', '/', $rel ) ] = $hash;
		}
		ksort( $map );
		return $map;
	}

	private static function root_hash( array $map ): string {
		$ctx = hash_init( 'sha256' );
		foreach ( $map as $path => $hash ) {
			hash_update( $ctx, $path . "\x00" . $hash . "\n" );
		}
		return hash_final( $ctx );
	}
}
