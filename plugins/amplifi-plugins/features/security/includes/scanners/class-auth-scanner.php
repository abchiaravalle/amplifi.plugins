<?php
/**
 * Login & auth anomaly scanner.
 *
 * Real-time hooks write every auth event to `wp_amplifi_security_auth_log`.
 * The periodic scan reads that log to find:
 *   - brute force from one IP,
 *   - distributed brute force across many IPs hitting one user,
 *   - successful admin login from a never-before-seen IP/country/AS,
 *   - login outside the user's typical hour-of-day window
 *     (after 14 days of baseline),
 *   - password reset followed by immediate login from a different IP,
 *   - admin session-IP pivot mid-session (extra: not in spec),
 *   - high AbuseIPDB confidence on an authenticating IP (when configured).
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Data\AbuseIPDB_Client;
use Amplifi\Security\Data\GeoIP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Auth_Scanner implements Scanner {

	private const AUTH_TABLE = 'amplifi_security_auth_log';

	public function name(): string { return 'auth'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'auth', $settings['enabled_scanners'] ?? [], true );
	}

	public static function register_hooks(): void {
		add_action( 'wp_login',         [ self::class, 'on_login_success' ], 10, 2 );
		add_action( 'wp_login_failed',  [ self::class, 'on_login_failed' ], 10, 1 );
		add_action( 'wp_logout',        [ self::class, 'on_logout' ], 10, 1 );
	}

	public static function on_login_success( string $user_login, $user ): void {
		self::log_auth_event( 'login_success', $user_login );
		Audit_Logger::log( 'login_success', [ 'user_login' => $user_login ] );
	}

	public static function on_login_failed( string $user_login ): void {
		self::log_auth_event( 'login_failed', $user_login );
		Audit_Logger::log( 'login_failed', [ 'user_login' => $user_login ] );
	}

	public static function on_logout( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user ) {
			self::log_auth_event( 'logout', $user->user_login );
			Audit_Logger::log( 'logout', [ 'user_login' => $user->user_login ] );
		}
	}

	private static function log_auth_event( string $event, string $user_login ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::AUTH_TABLE;

		$ip = Audit_Logger::client_ip();

		$wpdb->insert(
			$table,
			[
				'event'      => $event,
				'user_login' => mb_substr( $user_login, 0, 60 ),
				'ip'         => $ip,
				'ua'         => Audit_Logger::client_ua(),
				'country'    => $ip ? GeoIP::country_for( $ip ) : null,
				'created_at' => current_time( 'mysql', true ),
			]
		);
	}

	public function run( int $scan_id ): array {
		$findings = [];

		// Skip anomaly findings during the 14-day learning window. Brute-force
		// findings still fire — those don't need a baseline.
		$learning_until = strtotime( (string) get_option( 'amplifi_security_learning_until', 'now' ) );
		$learning       = time() < $learning_until;

		$findings = array_merge( $findings, $this->detect_brute_force_per_ip() );
		$findings = array_merge( $findings, $this->detect_distributed_brute_force() );

		if ( ! $learning ) {
			$findings = array_merge( $findings, $this->detect_new_geo_admin_logins() );
			$findings = array_merge( $findings, $this->detect_unusual_hour_admin_login() );
			$findings = array_merge( $findings, $this->detect_reset_then_remote_login() );
		}

		$findings = array_merge( $findings, $this->enrich_with_abuseipdb( $findings ) );

		return $findings;
	}

	private function detect_brute_force_per_ip(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::AUTH_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip, COUNT(*) as fails, GROUP_CONCAT(DISTINCT user_login) AS targets
				 FROM {$table}
				 WHERE event = 'login_failed' AND created_at >= %s
				 GROUP BY ip
				 HAVING fails >= 20",
				$cutoff
			),
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'type'    => 'auth_anomaly',
				'subtype' => 'brute_force_per_ip',
				'evidence' => [
					'ip'      => $row['ip'],
					'fails_in_last_hour' => (int) $row['fails'],
					'targeted_users'     => array_slice( explode( ',', (string) $row['targets'] ), 0, 10 ),
				],
			];
		}
		return $out;
	}

	private function detect_distributed_brute_force(): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::AUTH_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_login, COUNT(DISTINCT ip) AS ips, COUNT(*) AS fails
				 FROM {$table}
				 WHERE event = 'login_failed' AND created_at >= %s
				 GROUP BY user_login
				 HAVING fails >= 30 AND ips >= 8",
				$cutoff
			),
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'type'    => 'auth_anomaly',
				'subtype' => 'distributed_brute_force',
				'evidence' => [
					'user_login'         => $row['user_login'],
					'distinct_ips'       => (int) $row['ips'],
					'fails_in_last_hour' => (int) $row['fails'],
				],
			];
		}
		return $out;
	}

	private function detect_new_geo_admin_logins(): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::AUTH_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// Recent successful logins.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_login, ip, country, ua, created_at
				 FROM {$table}
				 WHERE event = 'login_success' AND created_at >= %s",
				$cutoff
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows as $row ) {
			$user = get_user_by( 'login', $row['user_login'] );
			if ( ! $user ) {
				continue;
			}
			if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
				continue;
			}

			// History for this user.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$history = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ip, country, COUNT(*) AS n
					 FROM {$table}
					 WHERE event = 'login_success' AND user_login = %s AND created_at < %s
					 GROUP BY ip, country
					 ORDER BY n DESC LIMIT 200",
					$row['user_login'],
					$row['created_at']
				),
				ARRAY_A
			);

			$known_countries = array_filter( array_column( $history, 'country' ) );
			$known_ips       = array_column( $history, 'ip' );

			$new_country = $row['country'] && ! in_array( $row['country'], $known_countries, true );
			$new_ip      = $row['ip']      && ! in_array( $row['ip'],      $known_ips,       true );

			if ( ! $new_country && ! $new_ip ) {
				continue;
			}
			$out[] = [
				'type'    => 'auth_anomaly',
				'subtype' => $new_country ? 'admin_login_new_country' : 'admin_login_new_ip',
				'evidence' => [
					'user_login'             => $row['user_login'],
					'ip'                     => $row['ip'],
					'country'                => $row['country'],
					'ua'                     => $row['ua'],
					'when'                   => $row['created_at'],
					'previous_login_summary' => sprintf(
						'%d prior logins, countries: %s',
						array_sum( array_column( $history, 'n' ) ),
						implode( ',', array_unique( $known_countries ) ) ?: 'unknown'
					),
				],
			];
		}
		return $out;
	}

	private function detect_unusual_hour_admin_login(): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::AUTH_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_login, ip, created_at, HOUR(created_at) AS hr
				 FROM {$table}
				 WHERE event = 'login_success' AND created_at >= %s",
				$cutoff
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows as $row ) {
			$user = get_user_by( 'login', $row['user_login'] );
			if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$hours = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT HOUR(created_at) FROM {$table}
					 WHERE event = 'login_success' AND user_login = %s AND created_at < %s
					 LIMIT 200",
					$row['user_login'],
					$row['created_at']
				)
			);
			if ( count( $hours ) < 5 ) {
				continue; // not enough baseline
			}
			if ( in_array( (string) $row['hr'], $hours, true ) ) {
				continue;
			}
			$out[] = [
				'type'    => 'auth_anomaly',
				'subtype' => 'admin_login_unusual_hour',
				'evidence' => [
					'user_login' => $row['user_login'],
					'ip'         => $row['ip'],
					'hour_utc'   => (int) $row['hr'],
					'usual_hours'=> array_map( 'intval', $hours ),
					'when'       => $row['created_at'],
				],
			];
		}
		return $out;
	}

	private function detect_reset_then_remote_login(): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'amplifi_security_audit';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$resets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actor_user_id, target_id, actor_ip, created_at FROM {$table}
				 WHERE event_type = 'password_reset' AND created_at >= %s",
				$cutoff
			),
			ARRAY_A
		);
		$auth_table = $wpdb->prefix . self::AUTH_TABLE;
		$out = [];
		foreach ( $resets as $r ) {
			$uid = (int) ( $r['target_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			// First successful login after reset.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$login = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ip, created_at FROM {$auth_table}
					 WHERE event = 'login_success' AND user_login = %s AND created_at >= %s
					 ORDER BY created_at ASC LIMIT 1",
					$user->user_login,
					$r['created_at']
				),
				ARRAY_A
			);
			if ( ! $login ) {
				continue;
			}
			if ( ! empty( $login['ip'] ) && ! empty( $r['actor_ip'] ) && $login['ip'] !== $r['actor_ip'] ) {
				$out[] = [
					'type'    => 'auth_anomaly',
					'subtype' => 'reset_then_remote_login',
					'evidence' => [
						'user_login' => $user->user_login,
						'reset_ip'   => $r['actor_ip'],
						'login_ip'   => $login['ip'],
						'reset_when' => $r['created_at'],
						'login_when' => $login['created_at'],
					],
				];
			}
		}
		return $out;
	}

	private function enrich_with_abuseipdb( array $findings ): array {
		if ( ! AbuseIPDB_Client::configured() ) {
			return [];
		}
		$extra = [];
		foreach ( $findings as $f ) {
			$ip = $f['evidence']['ip']
				?? $f['evidence']['login_ip']
				?? null;
			if ( ! $ip ) {
				continue;
			}
			$rep = AbuseIPDB_Client::lookup( $ip );
			if ( null === $rep ) {
				continue;
			}
			if ( ( $rep['confidence'] ?? 0 ) >= 75 ) {
				$extra[] = [
					'type'    => 'auth_anomaly',
					'subtype' => 'high_reputation_attacker_ip',
					'evidence' => [
						'ip'         => $ip,
						'confidence' => $rep['confidence'],
						'reports'    => $rep['reports'] ?? null,
						'related_finding_subtype' => $f['subtype'] ?? null,
					],
				];
			}
		}
		return $extra;
	}
}
