<?php
/**
 * Main plugin bootstrap.
 *
 * Single entry point. Loads i18n, audit logger, hooks the cron jobs, attaches
 * real-time hooks for the auth + db-anomaly scanners, and registers the admin
 * shell, canary, stealth, tamper detector, vulnerability feed sync, and CLI
 * commands.
 *
 * @package Amplifi\Security
 */

declare(strict_types=1);

namespace Amplifi\Security;

use Amplifi\Security\Admin\Admin;
use Amplifi\Security\Alerts\Alert_Router;
use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Canary\Canary;
use Amplifi\Security\Cli\Cli_Commands;
use Amplifi\Security\Data\AbuseIPDB_Client;
use Amplifi\Security\Data\Vuln_Feed;
use Amplifi\Security\Honeypot\Login_Honeypot;
use Amplifi\Security\Scanners\Auth_Scanner;
use Amplifi\Security\Scanners\Db_Anomaly_Scanner;
use Amplifi\Security\Scanners\Integrity_Scanner;
use Amplifi\Security\Scanners\Scan_Runner;
use Amplifi\Security\Self_Defense\Tamper_Detector;
use Amplifi\Security\Stealth\Stealth_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $initialized        = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		I18n::register();
		Installer::maybe_upgrade();

		// Pre-deactivation alert (priority 1).
		add_action( 'deactivate_plugin', [ Deactivator::class, 'on_pre_deactivate' ], 1, 2 );

		// Subsystems with their own register() entry points.
		Canary::register();
		Stealth_Mode::register();
		Tamper_Detector::register();
		Scan_Runner::register();
		Vuln_Feed::register();
		Alert_Router::register();
		Login_Honeypot::register();

		// Real-time hooks for scanners that listen between scans.
		Auth_Scanner::register_hooks();
		Db_Anomaly_Scanner::register_hooks();
		Integrity_Scanner::register_hooks();

		// Track the current admin's IP for AbuseIPDB allowlisting.
		add_action( 'wp_login', static function ( $login, $user ) {
			if ( $user instanceof \WP_User && in_array( 'administrator', (array) $user->roles, true ) ) {
				$ip = Audit_Logger::client_ip();
				if ( $ip ) {
					AbuseIPDB_Client::note_admin_ip( $ip );
				}
			}
		}, 10, 2 );

		// Daily prune.
		add_action( 'amplifi_security_audit_prune', static function () {
			$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
			$days     = (int) ( $settings['audit_retention_days'] ?? 90 );
			Audit_Logger::prune( max( 7, min( 365, $days ) ) );
		} );

		if ( is_admin() ) {
			Admin::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli_Commands::register();
		}

		do_action( 'amplifi_security_loaded' );
	}
}
