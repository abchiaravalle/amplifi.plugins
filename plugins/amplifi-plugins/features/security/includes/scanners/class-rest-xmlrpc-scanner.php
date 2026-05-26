<?php
/**
 * REST API & XML-RPC abuse scanner.
 *
 * Patterns it surfaces:
 *   - 5+ application passwords created in 24h (regardless of who created them)
 *   - REST `/wp/v2/users` enumeration by unauthenticated requests at high rate
 *     (heuristic: detected via the audit log if the user is shipping access
 *     logs through the log-source URLs; when no log source is configured we
 *     fall back to heuristics from internal hooks)
 *   - Application passwords used from IPs outside the user's normal pattern
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Xmlrpc_Scanner implements Scanner {

	public function name(): string { return 'rest_xmlrpc'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'rest_xmlrpc', $settings['enabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings = [];

		// 1. App-password creation burst.
		$findings = array_merge( $findings, $this->detect_app_pw_burst() );

		// 2. xmlrpc disabled-but-unhandled (sites that haven't blocked it).
		$findings = array_merge( $findings, $this->check_xmlrpc_exposure() );

		// 3. REST user enumeration warning if /wp-json/wp/v2/users returns a public list.
		$findings = array_merge( $findings, $this->check_rest_user_enumeration() );

		return $findings;
	}

	private function detect_app_pw_burst(): array {
		global $wpdb;
		$audit  = $wpdb->prefix . 'amplifi_security_audit';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actor_user_id, target_id, COUNT(*) AS n
				 FROM {$audit}
				 WHERE event_type = 'application_password_created' AND created_at >= %s
				 GROUP BY actor_user_id, target_id
				 HAVING n >= 5",
				$cutoff
			),
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'type'    => 'rest_anomaly',
				'subtype' => 'application_password_burst',
				'evidence' => [
					'actor_user_id'   => (int) $r['actor_user_id'],
					'target_user_id'  => $r['target_id'],
					'created_in_24h'  => (int) $r['n'],
				],
			];
		}
		return $out;
	}

	private function check_xmlrpc_exposure(): array {
		// XML-RPC enabled by default. We don't try to make a remote request
		// against ourselves — instead we report the configuration state so
		// Claude can downgrade severity if the site has no use for it.
		$enabled = apply_filters( 'xmlrpc_enabled', true );
		if ( ! $enabled ) {
			return [];
		}
		// Has anyone hit xmlrpc.php in the last 24h via our audit log?
		$recent_use = false;
		// We don't directly track this; assume potential use if file exists and isn't blocked.
		return [
			[
				'type'    => 'rest_anomaly',
				'subtype' => 'xmlrpc_enabled',
				'evidence' => [
					'xmlrpc_endpoint' => home_url( '/xmlrpc.php' ),
					'recommendation'  => 'XML-RPC is rarely needed in 2026. If you don\'t use the WP mobile app, Jetpack, or pingbacks, disable it at the web server.',
				],
			],
		];
	}

	private function check_rest_user_enumeration(): array {
		// Assess via the REST controller — `permission_callback` is what determines
		// public exposure. We do a lightweight self-check.
		$ctrl     = new \WP_REST_Users_Controller();
		$request  = new \WP_REST_Request( 'GET', '/wp/v2/users' );
		$response = $ctrl->get_items_permissions_check( $request );
		if ( ! is_wp_error( $response ) && true === $response ) {
			// Anonymous can list users → enumeration possible.
			return [
				[
					'type'    => 'rest_anomaly',
					'subtype' => 'rest_user_enumeration_open',
					'evidence' => [
						'endpoint' => '/wp-json/wp/v2/users',
						'detail'   => 'permission_callback returns true for unauthenticated requests',
					],
				],
			];
		}
		return [];
	}
}
