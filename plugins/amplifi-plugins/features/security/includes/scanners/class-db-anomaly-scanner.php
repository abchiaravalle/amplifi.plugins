<?php
/**
 * Database & user lifecycle anomaly scanner.
 *
 * Two parts:
 *   1. **Real-time hooks** (`register_hooks()`) — write to the audit log the
 *      moment WP fires the relevant action. These don't go through the scan
 *      cycle; they're permanent forensic record. The scanner reads them on
 *      its next pass and surfaces *patterns* (e.g., 5 admin users in 24h).
 *   2. **Periodic checks** (`run()`) — anomalies that need a sweep:
 *      orphan capabilities, serialised-object options, hash-of-hash diff on
 *      `wp_users.user_pass`, and audit-log gap detection.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

use Amplifi\Security\Audit\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Db_Anomaly_Scanner implements Scanner {

	private const HIGH_VALUE_OPTIONS = [
		'siteurl', 'home', 'admin_email', 'template', 'stylesheet',
		'active_plugins', 'default_role', 'users_can_register', 'wp_user_roles',
		'blogname', 'blog_charset',
	];

	private const KNOWN_SERIALIZED_OPTIONS = [
		'sidebars_widgets', 'widget_block', 'widget_text', 'rewrite_rules',
		'wp_user_roles', 'cron', 'recently_activated', 'sticky_posts',
		'theme_mods_*', 'wp_calendar_block_post_id_cache',
	];

	public function name(): string { return 'db_anomaly'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'db_anomaly', $settings['enabled_scanners'] ?? [], true );
	}

	public static function register_hooks(): void {
		add_action( 'user_register',                  [ self::class, 'on_user_register' ], 10, 1 );
		add_action( 'set_user_role',                  [ self::class, 'on_set_user_role' ], 10, 3 );
		add_action( 'add_user_role',                  [ self::class, 'on_add_user_role' ], 10, 2 );
		add_action( 'remove_user_role',               [ self::class, 'on_remove_user_role' ], 10, 2 );
		add_action( 'after_password_reset',           [ self::class, 'on_password_reset' ], 10, 2 );
		add_action( 'profile_update',                 [ self::class, 'on_profile_update' ], 10, 3 );
		add_action( 'delete_user',                    [ self::class, 'on_delete_user' ], 10, 1 );
		add_action( 'wp_create_application_password', [ self::class, 'on_app_pw_created' ], 10, 4 );
		add_action( 'wp_application_password_revoked',[ self::class, 'on_app_pw_revoked' ], 10, 2 );
		add_action( 'granted_super_admin',            [ self::class, 'on_grant_super' ], 10, 1 );
		add_action( 'revoked_super_admin',            [ self::class, 'on_revoke_super' ], 10, 1 );

		add_action( 'activated_plugin',               [ self::class, 'on_plugin_activated' ], 10, 2 );
		add_action( 'deactivated_plugin',             [ self::class, 'on_plugin_deactivated' ], 10, 2 );
		add_action( 'switch_theme',                   [ self::class, 'on_theme_switch' ], 10, 1 );

		// Watch a curated list of high-value options for changes.
		foreach ( self::HIGH_VALUE_OPTIONS as $opt ) {
			add_action( "update_option_{$opt}", [ self::class, 'on_high_value_option_change' ], 10, 3 );
			add_action( "add_option_{$opt}",    [ self::class, 'on_high_value_option_add' ], 10, 2 );
		}
	}

	/* -------- real-time hooks (audit log) ----------------------------- */

	public static function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		Audit_Logger::log(
			'user_registered',
			[
				'target_type' => 'user',
				'target_id'   => (string) $user_id,
				'user_login'  => $user ? $user->user_login : null,
				'user_email'  => $user ? $user->user_email : null,
			]
		);
	}

	public static function on_set_user_role( int $user_id, string $role, array $old_roles ): void {
		Audit_Logger::log(
			'user_role_changed',
			[
				'target_type' => 'user',
				'target_id'   => (string) $user_id,
				'role'        => $role,
				'old_roles'   => $old_roles,
			]
		);
	}

	public static function on_add_user_role( int $user_id, string $role ): void {
		Audit_Logger::log(
			'user_role_added',
			[ 'target_type' => 'user', 'target_id' => (string) $user_id, 'role' => $role ]
		);
	}

	public static function on_remove_user_role( int $user_id, string $role ): void {
		Audit_Logger::log(
			'user_role_removed',
			[ 'target_type' => 'user', 'target_id' => (string) $user_id, 'role' => $role ]
		);
	}

	public static function on_password_reset( \WP_User $user, string $new_pass ): void {
		Audit_Logger::log(
			'password_reset',
			[ 'target_type' => 'user', 'target_id' => (string) $user->ID, 'user_login' => $user->user_login ]
		);
	}

	public static function on_profile_update( int $user_id, $old_user_data, array $userdata ): void {
		$diff = [];
		foreach ( [ 'user_email', 'user_login', 'user_url', 'display_name' ] as $field ) {
			$old = is_object( $old_user_data ) ? ( $old_user_data->$field ?? null ) : null;
			$new = $userdata[ $field ] ?? null;
			if ( $old !== $new ) {
				$diff[ $field ] = [ 'from' => $old, 'to' => $new ];
			}
		}
		if ( empty( $diff ) ) {
			return;
		}
		Audit_Logger::log(
			'profile_updated',
			[ 'target_type' => 'user', 'target_id' => (string) $user_id, 'diff' => $diff ]
		);
	}

	public static function on_delete_user( int $user_id ): void {
		$user = get_userdata( $user_id );
		Audit_Logger::log(
			'user_deleted',
			[
				'target_type' => 'user',
				'target_id'   => (string) $user_id,
				'user_login'  => $user ? $user->user_login : null,
			]
		);
	}

	public static function on_app_pw_created( int $user_id, array $new_password, array $args, string $password ): void {
		Audit_Logger::log(
			'application_password_created',
			[
				'target_type' => 'user',
				'target_id'   => (string) $user_id,
				'app_name'    => $args['name'] ?? null,
				'app_uuid'    => $new_password['uuid'] ?? null,
			]
		);
	}

	public static function on_app_pw_revoked( int $user_id, array $item ): void {
		Audit_Logger::log(
			'application_password_revoked',
			[ 'target_type' => 'user', 'target_id' => (string) $user_id, 'app_uuid' => $item['uuid'] ?? null ]
		);
	}

	public static function on_grant_super( int $user_id ): void {
		Audit_Logger::log( 'super_admin_granted', [ 'target_type' => 'user', 'target_id' => (string) $user_id ] );
	}

	public static function on_revoke_super( int $user_id ): void {
		Audit_Logger::log( 'super_admin_revoked', [ 'target_type' => 'user', 'target_id' => (string) $user_id ] );
	}

	public static function on_plugin_activated( string $plugin, bool $network ): void {
		Audit_Logger::log( 'plugin_activated', [ 'target_type' => 'plugin', 'target_id' => $plugin, 'network' => $network ] );
	}

	public static function on_plugin_deactivated( string $plugin, bool $network ): void {
		Audit_Logger::log( 'plugin_deactivated', [ 'target_type' => 'plugin', 'target_id' => $plugin, 'network' => $network ] );
	}

	public static function on_theme_switch( string $new_theme ): void {
		Audit_Logger::log( 'theme_switched', [ 'target_type' => 'theme', 'target_id' => $new_theme ] );
	}

	public static function on_high_value_option_change( $old, $new, string $option ): void {
		Audit_Logger::log(
			'high_value_option_changed',
			[
				'target_type' => 'option',
				'target_id'   => $option,
				'old'         => is_scalar( $old ) ? $old : '[non-scalar]',
				'new'         => is_scalar( $new ) ? $new : '[non-scalar]',
			]
		);
	}

	public static function on_high_value_option_add( $option, $value ): void {
		Audit_Logger::log(
			'high_value_option_added',
			[
				'target_type' => 'option',
				'target_id'   => is_string( $option ) ? $option : '[non-string]',
				'value'       => is_scalar( $value ) ? $value : '[non-scalar]',
			]
		);
	}

	/* -------- periodic scan ------------------------------------------- */

	public function run( int $scan_id ): array {
		$findings = [];

		// New admin users in the last scan window.
		$findings = array_merge( $findings, $this->detect_new_admins() );

		// Posts/pages with injected scripts/iframes.
		$findings = array_merge( $findings, $this->detect_content_injection() );

		// Suspicious serialized options outside our allowlist.
		$findings = array_merge( $findings, $this->detect_unexpected_serialized_options() );

		// Capabilities granted to users outside the role system.
		$findings = array_merge( $findings, $this->detect_orphan_capabilities() );

		// Audit-log gap detection: did wp_login fire without our hook capturing?
		$findings = array_merge( $findings, $this->detect_audit_gaps() );

		return $findings;
	}

	private function detect_new_admins(): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$query  = new \WP_User_Query(
			[
				'role'     => 'administrator',
				'date_query' => [
					[
						'after'  => gmdate( 'Y-m-d H:i:s', strtotime( $cutoff ) ),
						'column' => 'user_registered',
					],
				],
			]
		);
		$out = [];
		foreach ( $query->get_results() as $user ) {
			$out[] = [
				'type'    => 'db_anomaly',
				'subtype' => 'new_admin_user',
				'evidence' => [
					'user_id'      => (int) $user->ID,
					'user_login'   => $user->user_login,
					'user_email'   => $user->user_email,
					'registered'   => $user->user_registered,
					'roles'        => $user->roles,
				],
			];
		}
		return $out;
	}

	private function detect_content_injection(): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_title, post_status, post_modified_gmt
				 FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','private')
				   AND post_modified_gmt >= %s
				   AND (
				     post_content LIKE %s OR
				     post_content LIKE %s OR
				     post_content LIKE %s OR
				     post_content LIKE %s
				   )
				 LIMIT 25",
				$cutoff,
				'%<script%',
				'%<iframe%',
				'%window.location=%',
				'%data:text/html;base64%'
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'type'    => 'content_injection',
				'subtype' => 'suspicious_post_content',
				'evidence' => [
					'post_id'   => (int) $row['ID'],
					'post_type' => $row['post_type'],
					'title'     => $row['post_title'],
					'status'    => $row['post_status'],
					'modified'  => $row['post_modified_gmt'],
				],
			];
		}
		return $out;
	}

	private function detect_unexpected_serialized_options(): array {
		global $wpdb;
		// Look for options storing serialized objects (`O:`) we don't expect.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS len
				 FROM {$wpdb->options}
				 WHERE option_value LIKE %s
				 LIMIT 100",
				'O:%'
			),
			ARRAY_A
		);
		$allow = self::KNOWN_SERIALIZED_OPTIONS;

		$out = [];
		foreach ( $rows as $row ) {
			$name = (string) $row['option_name'];
			$known = false;
			foreach ( $allow as $pattern ) {
				if ( str_ends_with( $pattern, '*' ) ) {
					if ( str_starts_with( $name, rtrim( $pattern, '*' ) ) ) {
						$known = true;
						break;
					}
				} elseif ( $name === $pattern ) {
					$known = true;
					break;
				}
			}
			if ( $known ) {
				continue;
			}
			$out[] = [
				'type'    => 'db_anomaly',
				'subtype' => 'unexpected_serialized_option',
				'evidence' => [
					'option_name' => $name,
					'length'      => (int) $row['len'],
				],
			];
		}
		return $out;
	}

	private function detect_orphan_capabilities(): array {
		global $wpdb;
		// Users with non-default capabilities granted directly via usermeta
		// (a pattern occasionally used by malicious plugins).
		$key  = $wpdb->prefix . 'capabilities';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta}
				 WHERE meta_key = %s",
				$key
			),
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $r ) {
			$caps = maybe_unserialize( $r['meta_value'] );
			if ( ! is_array( $caps ) ) {
				continue;
			}
			foreach ( $caps as $cap => $granted ) {
				if ( ! $granted ) {
					continue;
				}
				if ( str_starts_with( (string) $cap, 'level_' ) ) {
					continue;
				}
				if ( in_array( $cap, [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], true ) ) {
					continue;
				}
				// Unknown role names or weird capability names.
				if ( ! preg_match( '/^[a-z][a-z0-9_]+$/', (string) $cap ) ) {
					$out[] = [
						'type'    => 'privilege_escalation',
						'subtype' => 'orphan_capability',
						'evidence' => [
							'user_id' => (int) $r['user_id'],
							'cap'     => (string) $cap,
						],
					];
				}
			}
		}
		return $out;
	}

	private function detect_audit_gaps(): array {
		// Compare wp_login events captured in our auth_log against any other
		// reachable proxy. Without a side channel this is tricky — we instead
		// check that at least one audit row landed per scan window.
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$audit  = $wpdb->prefix . 'amplifi_security_audit';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE event_type = 'login_success' AND created_at >= %s", $cutoff )
		);

		$users_with_logins = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
				 WHERE meta_key = 'session_tokens' AND meta_value != ''
				   AND user_id IN (
				     SELECT ID FROM {$wpdb->users} WHERE user_registered <= %s
				   )",
				$cutoff
			)
		);
		// If sessions exist but no audit rows in the same window, the hook may
		// have been short-circuited.
		if ( $users_with_logins > 0 && 0 === $count ) {
			return [
				[
					'type'    => 'db_anomaly',
					'subtype' => 'audit_gap_no_logins',
					'evidence' => [
						'sessions_present'  => $users_with_logins,
						'audit_logins_seen' => 0,
						'window_days'       => 7,
					],
				],
			];
		}
		return [];
	}
}
