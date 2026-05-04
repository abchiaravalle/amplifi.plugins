<?php
/**
 * PSR-4-style autoloader adapted to WP file naming conventions.
 *
 * Maps the namespace prefix `Amplifi\Security\` onto `includes/`:
 *   - Sub-namespaces become lowercase directory names with underscores
 *     converted to hyphens (e.g. `Amplifi\Security\Crypto\Secret_Store`
 *     → `includes/crypto/class-secret-store.php`).
 *   - Class names become `class-<lowercased-with-hyphens>.php`.
 *   - Interface names become `interface-<lowercased-with-hyphens>.php`.
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	private const PREFIX = 'Amplifi\\Security\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$leaf     = array_pop( $parts );

		$dir_segments = array_map(
			static fn( string $segment ): string => strtolower( str_replace( '_', '-', $segment ) ),
			$parts
		);

		$file_basename = strtolower( str_replace( '_', '-', $leaf ) );

		// Look for class-<name>.php first, then interface-<name>.php.
		$candidates = [
			'class-' . $file_basename . '.php',
			'interface-' . $file_basename . '.php',
		];

		$base = AMPLIFI_SECURITY_PATH . 'includes/';
		if ( ! empty( $dir_segments ) ) {
			$base .= implode( '/', $dir_segments ) . '/';
		}

		foreach ( $candidates as $file ) {
			$path = $base . $file;
			if ( is_file( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
