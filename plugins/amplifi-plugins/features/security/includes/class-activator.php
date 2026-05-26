<?php
/**
 * Activation handler.
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Self_Defense\Self_Integrity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		// Re-check env at activation time (covers the case where activation is
		// triggered programmatically without going through the gate).
		if ( version_compare( PHP_VERSION, AMPLIFI_SECURITY_MIN_PHP, '<' ) ) {
			deactivate_plugins( AMPLIFI_SECURITY_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'amplifi.security requires PHP %1$s or newer (you are on %2$s).', 'amplifi-security' ),
						AMPLIFI_SECURITY_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}

		Installer::install();
		self::seed_options();
		Self_Integrity::record_baseline();
		self::schedule_cron();
		self::audit_activation();
	}

	/**
	 * Generate per-site secrets and singleton state. Idempotent: existing
	 * values are preserved across re-activations.
	 */
	private static function seed_options(): void {
		$installer_id = get_current_user_id();
		if ( $installer_id > 0 ) {
			// Stable across re-activations: first installer wins.
			add_option( 'amplifi_security_installer_id', $installer_id, '', false );
		}

		if ( ! get_option( 'amplifi_security_canary_slug' ) ) {
			update_option( 'amplifi_security_canary_slug', wp_generate_password( 32, false ), false );
		}

		if ( ! get_option( 'amplifi_security_canary_secret' ) ) {
			update_option( 'amplifi_security_canary_secret', bin2hex( random_bytes( 32 ) ), false );
		}

		if ( ! get_option( 'amplifi_security_unhide_token' ) ) {
			update_option( 'amplifi_security_unhide_token', wp_generate_password( 48, false ), false );
		}

		add_option( 'amplifi_security_stealth_enabled', 0, '', false );
		add_option( 'amplifi_security_preserve_data_on_uninstall', 0, '', false );
		add_option( 'amplifi_security_first_activation', current_time( 'mysql', true ), '', false );
		add_option( 'amplifi_security_learning_until', gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) ), '', false );
		add_option( 'amplifi_security_settings', wp_json_encode( self::default_settings() ), '', false );
	}

	private static function default_settings(): array {
		return [
			'scan_interval'        => 'four_hours',
			'enabled_scanners'     => [
				'shell',
				'integrity',
				'critical_file',
				'db_anomaly',
				'auth',
				'vuln',
				'cron',
				'rest_xmlrpc',
			],
			'file_exclusions'      => [
				'wp-content/cache/*',
				'wp-content/uploads/cache/*',
				'wp-content/backup*',
			],
			'ip_allowlist'         => [],
			'model'                => 'claude-haiku-4-5-20251001',
			'sensitivity'          => 'balanced',
			'daily_spend_cap_usd'  => 2.0,
			'monthly_spend_cap_usd' => 30.0,
			'digest_hour_utc'      => 13,
			'sms_quota_per_day'    => 3,
			'quiet_hours'          => [
				'enabled' => false,
				'start'   => 22,
				'end'     => 7,
			],
			'audit_retention_days' => 90,
			'redact_log_query_strings' => false,
			'routing_matrix'       => self::default_routing_matrix(),
		];
	}

	private static function default_routing_matrix(): array {
		// Matrix: category → [verdict => channel]
		// channels: email_sms | email | digest | log | mute
		return [
			'malware'                => [ 'confirmed' => 'email_sms', 'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
			'core_tampering'         => [ 'confirmed' => 'email_sms', 'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
			'privilege_escalation'   => [ 'confirmed' => 'email_sms', 'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
			'content_injection'      => [ 'confirmed' => 'email',     'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
			'plugin_theme_tampering' => [ 'confirmed' => 'email',     'likely' => 'digest','worth_reviewing' => 'digest', 'benign' => 'log' ],
			'auth_anomaly'           => [ 'confirmed' => 'email',     'likely' => 'digest','worth_reviewing' => 'digest', 'benign' => 'log' ],
			'vulnerability'          => [ 'confirmed' => 'email',     'likely' => 'digest','worth_reviewing' => 'digest', 'benign' => 'log' ],
			'cron_anomaly'           => [ 'confirmed' => 'email',     'likely' => 'digest','worth_reviewing' => 'digest', 'benign' => 'log' ],
			'config_change'          => [ 'confirmed' => 'email',     'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
			'other'                  => [ 'confirmed' => 'email',     'likely' => 'email', 'worth_reviewing' => 'digest', 'benign' => 'log' ],
		];
	}

	private static function schedule_cron(): void {
		// Custom interval registered in Plugin::init() via cron_schedules filter
		// (registered later) — for activation we use the WP built-ins.
		if ( ! wp_next_scheduled( 'amplifi_security_run_scan' ) ) {
			wp_schedule_event( time() + 300, 'amplifi_security_four_hours', 'amplifi_security_run_scan' );
		}
		if ( ! wp_next_scheduled( 'amplifi_security_audit_prune' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'amplifi_security_audit_prune' );
		}
		if ( ! wp_next_scheduled( 'amplifi_security_vuln_feed_refresh' ) ) {
			wp_schedule_event( time() + 900, 'daily', 'amplifi_security_vuln_feed_refresh' );
		}
		if ( ! wp_next_scheduled( 'amplifi_security_daily_digest' ) ) {
			wp_schedule_event( time() + 1200, 'daily', 'amplifi_security_daily_digest' );
		}
	}

	private static function audit_activation(): void {
		Audit_Logger::log(
			'plugin_activated',
			[
				'plugin'  => AMPLIFI_SECURITY_BASENAME,
				'version' => AMPLIFI_SECURITY_VERSION,
			]
		);
	}
}
