<?php
/**
 * Vulnerable plugin/theme scanner.
 *
 * Reads the locally-cached Wordfence Intelligence v3 feed
 * (`Vuln_Feed::sync()` runs daily on its own cron) and cross-references the
 * site's installed plugins/themes by slug + version. Pure DB read; no HTTP
 * here on hot path.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

use Amplifi\Security\Data\Vuln_Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Vuln_Scanner implements Scanner {

	public function name(): string { return 'vuln'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'vuln', $settings['enabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings = [];

		// Plugins
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $basename => $data ) {
			$slug    = dirname( $basename ) ?: basename( $basename, '.php' );
			$version = (string) ( $data['Version'] ?? '0' );
			$active  = is_plugin_active( $basename );
			$matches = Vuln_Feed::matches_for( $slug, $version, 'plugin' );
			foreach ( $matches as $vuln ) {
				$findings[] = [
					'type'    => 'vulnerable_component',
					'subtype' => 'plugin',
					'evidence' => array_merge(
						$vuln,
						[
							'component'        => $slug,
							'current_version'  => $version,
							'plugin_basename'  => $basename,
							'active'           => $active,
							'attribution'      => 'Wordfence Intelligence + MITRE',
						]
					),
				];
			}
		}

		// Themes
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$version = (string) $theme->get( 'Version' );
			$active  = wp_get_theme()->get_stylesheet() === $stylesheet || ( is_child_theme() && get_template() === $stylesheet );
			$matches = Vuln_Feed::matches_for( $stylesheet, $version, 'theme' );
			foreach ( $matches as $vuln ) {
				$findings[] = [
					'type'    => 'vulnerable_component',
					'subtype' => 'theme',
					'evidence' => array_merge(
						$vuln,
						[
							'component'       => $stylesheet,
							'current_version' => $version,
							'active'          => $active,
							'attribution'     => 'Wordfence Intelligence + MITRE',
						]
					),
				];
			}
		}

		// WP core itself.
		global $wp_version;
		$matches = Vuln_Feed::matches_for( 'wordpress', (string) $wp_version, 'core' );
		foreach ( $matches as $vuln ) {
			$findings[] = [
				'type'    => 'vulnerable_component',
				'subtype' => 'core',
				'evidence' => array_merge(
					$vuln,
					[
						'component'       => 'wordpress',
						'current_version' => (string) $wp_version,
						'active'          => true,
						'attribution'     => 'Wordfence Intelligence + MITRE',
					]
				),
			];
		}

		return $findings;
	}
}
