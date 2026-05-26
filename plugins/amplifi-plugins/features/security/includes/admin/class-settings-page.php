<?php
/**
 * Settings page — eight tabs, each its own template partial.
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Alerts\Smtp2Go_Client;
use Amplifi\Security\Alerts\Textbelt_Client;
use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Canary\Canary;
use Amplifi\Security\Data\AbuseIPDB_Client;
use Amplifi\Security\Data\Vuln_Feed;
use Amplifi\Security\Stealth\Stealth_Mode;
use Amplifi\Security\Triage\Anthropic_Client;
use Amplifi\Security\Triage\Spend_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {

	public const TABS = [
		'connections'  => 'Connections',
		'scanning'     => 'Scanning',
		'triage'       => 'Triage',
		'notifications'=> 'Notifications',
		'stealth'      => 'Stealth & Defense',
		'findings'     => 'Findings',
		'audit'        => 'Audit',
		'health'       => 'Health',
	];

	public static function register(): void {
		add_action( 'admin_post_amplifi_security_save_settings', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_amplifi_security_test_anthropic', [ self::class, 'handle_test_anthropic' ] );
		add_action( 'admin_post_amplifi_security_test_smtp2go',   [ self::class, 'handle_test_smtp2go' ] );
		add_action( 'admin_post_amplifi_security_rotate_canary',  [ self::class, 'handle_rotate_canary' ] );
		add_action( 'admin_post_amplifi_security_rotate_unhide',  [ self::class, 'handle_rotate_unhide' ] );
		add_action( 'admin_post_amplifi_security_toggle_stealth', [ self::class, 'handle_toggle_stealth' ] );
		add_action( 'admin_post_amplifi_security_mark_fp',        [ self::class, 'handle_mark_fp' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}

		// Wizard mode short-circuits the tabbed UI.
		if ( ! empty( $_GET['wizard'] ) ) {
			$step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;
			Onboarding_Wizard::render( $step, self::settings() );
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connections';
		if ( ! array_key_exists( $tab, self::TABS ) ) {
			$tab = 'connections';
		}
		$settings = self::settings();

		echo '<div class="wrap amplifi-security">';
		echo '<h1>' . esc_html__( 'amplifi.security — Settings', 'amplifi-security' ) . '</h1>';
		self::render_tab_nav( $tab );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'amplifi_security_save_settings' );
		echo '<input type="hidden" name="action" value="amplifi_security_save_settings" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';

		$view = AMPLIFI_SECURITY_PATH . 'includes/admin/views/settings-' . $tab . '.php';
		if ( is_file( $view ) ) {
			include $view; // @phpstan-ignore-line
		} else {
			echo '<p>' . esc_html__( 'Tab not found.', 'amplifi-security' ) . '</p>';
		}

		submit_button( __( 'Save settings', 'amplifi-security' ) );
		echo '</form></div>';
	}

	private static function render_tab_nav( string $current ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $slug => $label ) {
			$url = admin_url( 'admin.php?page=amplifi-security-settings&tab=' . $slug );
			$cls = 'nav-tab' . ( $current === $slug ? ' nav-tab-active' : '' );
			printf(
				'<a class="%1$s" href="%2$s">%3$s</a>',
				esc_attr( $cls ),
				esc_url( $url ),
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	public static function settings(): array {
		$raw = (string) get_option( 'amplifi_security_settings', '' );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function update_settings( array $patch ): void {
		$current = self::settings();
		$next    = array_replace_recursive( $current, $patch );
		update_option( 'amplifi_security_settings', wp_json_encode( $next ), false );
	}

	public static function sanitize( $value ): string {
		// `register_setting` callback — the actual save flow goes through
		// `handle_save()`, so this just round-trips the JSON.
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return is_string( $value ) ? $value : '';
	}

	/* ---------- form handlers ---------- */

	public static function handle_save(): void {
		check_admin_referer( 'amplifi_security_save_settings' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : 'connections';

		$current = self::settings();
		$patch   = [];

		switch ( $tab ) {
			case 'connections':
				if ( isset( $_POST['anthropic_key'] ) ) {
					$key = sanitize_text_field( wp_unslash( (string) $_POST['anthropic_key'] ) );
					if ( '' !== $key && ! str_contains( $key, '•' ) ) {
						Anthropic_Client::set_api_key( $key );
					}
				}
				if ( isset( $_POST['smtp2go_key'] ) ) {
					$key = sanitize_text_field( wp_unslash( (string) $_POST['smtp2go_key'] ) );
					if ( '' !== $key && ! str_contains( $key, '•' ) ) {
						Smtp2Go_Client::set_api_key( $key );
					}
				}
				if ( isset( $_POST['smtp2go_sender'] ) ) {
					Smtp2Go_Client::set_sender( sanitize_email( wp_unslash( (string) $_POST['smtp2go_sender'] ) ) );
				}
				if ( isset( $_POST['abuseipdb_key'] ) ) {
					$key = sanitize_text_field( wp_unslash( (string) $_POST['abuseipdb_key'] ) );
					if ( '' !== $key && ! str_contains( $key, '•' ) ) {
						AbuseIPDB_Client::set_api_key( $key );
					}
				}
				if ( isset( $_POST['textbelt_key'] ) ) {
					$key = sanitize_text_field( wp_unslash( (string) $_POST['textbelt_key'] ) );
					if ( '' !== $key && ! str_contains( $key, '•' ) ) {
						Textbelt_Client::set_api_key( $key );
					}
				}
				if ( isset( $_POST['textbelt_phone'] ) ) {
					Textbelt_Client::set_phone( sanitize_text_field( wp_unslash( (string) $_POST['textbelt_phone'] ) ) );
				}
				if ( isset( $_POST['wordfence_token'] ) ) {
					$tok = sanitize_text_field( wp_unslash( (string) $_POST['wordfence_token'] ) );
					if ( '' !== $tok && ! str_contains( $tok, '•' ) ) {
						Vuln_Feed::set_auth_token( $tok );
					}
				}
				break;

			case 'scanning':
				$patch['scan_interval'] = sanitize_key( (string) ( $_POST['scan_interval'] ?? 'four_hours' ) );
				$patch['enabled_scanners'] = array_values( array_filter(
					array_map(
						'sanitize_key',
						(array) ( $_POST['enabled_scanners'] ?? [] )
					)
				) );
				$patch['file_exclusions'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					preg_split( '/\R/', wp_unslash( (string) ( $_POST['file_exclusions'] ?? '' ) ) ) ?: []
				) ) );
				$patch['ip_allowlist'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					preg_split( '/[\s,]+/', wp_unslash( (string) ( $_POST['ip_allowlist'] ?? '' ) ) ) ?: []
				) ) );
				break;

			case 'triage':
				$model = sanitize_text_field( wp_unslash( (string) ( $_POST['model'] ?? Anthropic_Client::DEFAULT_MODEL ) ) );
				$patch['model']                 = in_array( $model, Anthropic_Client::ALLOWED_MODELS, true ) ? $model : Anthropic_Client::DEFAULT_MODEL;
				$patch['sensitivity']           = in_array( $_POST['sensitivity'] ?? '', [ 'conservative', 'balanced', 'aggressive' ], true ) ? (string) $_POST['sensitivity'] : 'balanced';
				$patch['daily_spend_cap_usd']   = (float) ( $_POST['daily_spend_cap_usd']   ?? 2.0 );
				$patch['monthly_spend_cap_usd'] = (float) ( $_POST['monthly_spend_cap_usd'] ?? 30.0 );
				break;

			case 'notifications':
				$patch['notification_recipients'] = array_values( array_filter( array_map(
					'sanitize_email',
					preg_split( '/[\s,]+/', wp_unslash( (string) ( $_POST['notification_recipients'] ?? '' ) ) ) ?: []
				) ) );
				$patch['digest_hour_utc']   = max( 0, min( 23, (int) ( $_POST['digest_hour_utc']   ?? 13 ) ) );
				$patch['sms_quota_per_day'] = max( 0, min( 3,  (int) ( $_POST['sms_quota_per_day'] ?? 3 ) ) );
				$patch['quiet_hours']       = [
					'enabled' => ! empty( $_POST['quiet_hours_enabled'] ),
					'start'   => max( 0, min( 23, (int) ( $_POST['quiet_hours_start'] ?? 22 ) ) ),
					'end'     => max( 0, min( 23, (int) ( $_POST['quiet_hours_end']   ?? 7 ) ) ),
				];
				$patch['routing_matrix'] = self::sanitize_routing_matrix( (array) ( $_POST['routing_matrix'] ?? [] ) );
				break;

			case 'stealth':
				$patch['preserve_data_on_uninstall'] = ! empty( $_POST['preserve_data_on_uninstall'] );
				update_option( 'amplifi_security_preserve_data_on_uninstall', $patch['preserve_data_on_uninstall'] ? 1 : 0, false );
				break;
		}

		if ( ! empty( $patch ) ) {
			self::update_settings( $patch );
		}
		Audit_Logger::log( 'settings_changed', [ 'tab' => $tab ] );

		// Wizard flow: redirect to the next step instead of back to the tab.
		$next_step = isset( $_POST['wizard_next_step'] ) ? (int) $_POST['wizard_next_step'] : 0;
		if ( $next_step > 0 ) {
			wp_safe_redirect( Onboarding_Wizard::step_url( $next_step ) );
			exit;
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => $tab, 'saved' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_test_anthropic(): void {
		check_admin_referer( 'amplifi_security_test_anthropic' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		$ok = Anthropic_Client::ping( Anthropic_Client::api_key() );
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => 'connections', 'anthropic_test' => $ok ? 'ok' : 'fail' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_test_smtp2go(): void {
		check_admin_referer( 'amplifi_security_test_smtp2go' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		$ok = Smtp2Go_Client::ping( Smtp2Go_Client::api_key(), Smtp2Go_Client::sender() );
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => 'connections', 'smtp2go_test' => $ok ? 'ok' : 'fail' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_rotate_canary(): void {
		check_admin_referer( 'amplifi_security_rotate_canary' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		Canary::rotate_slug();
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => 'stealth', 'canary_rotated' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_rotate_unhide(): void {
		check_admin_referer( 'amplifi_security_rotate_unhide' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		$tok = Stealth_Mode::rotate_unhide_token();
		set_transient( 'amplifi_security_unhide_token_display', $tok, 60 );
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => 'stealth' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_toggle_stealth(): void {
		check_admin_referer( 'amplifi_security_toggle_stealth' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		$installer = (int) get_option( 'amplifi_security_installer_id', 0 );
		if ( $installer && get_current_user_id() !== $installer ) {
			wp_die( esc_html__( 'Only the installer may change Stealth Mode.', 'amplifi-security' ) );
		}
		if ( Stealth_Mode::is_enabled() ) {
			Stealth_Mode::disable();
		} else {
			Stealth_Mode::enable();
		}
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'amplifi-security-settings', 'tab' => 'stealth' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_mark_fp(): void {
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'amplifi_security_mark_fp_' . $id );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'amplifi_security_findings',
			[ 'verdict' => 'benign', 'user_marked_fp' => 1, 'status' => 'dismissed' ],
			[ 'id' => $id ]
		);
		Audit_Logger::log( 'finding_marked_fp', [ 'finding_id' => $id ] );
		wp_safe_redirect( admin_url( 'admin.php?page=amplifi-security-findings&fp=1' ) );
		exit;
	}

	private static function sanitize_routing_matrix( array $raw ): array {
		$valid_channels = [ 'email_sms', 'email', 'digest', 'log', 'mute' ];
		$out = [];
		foreach ( [ 'malware', 'core_tampering', 'plugin_theme_tampering', 'privilege_escalation', 'content_injection', 'auth_anomaly', 'vulnerability', 'cron_anomaly', 'config_change', 'other' ] as $cat ) {
			foreach ( [ 'confirmed', 'likely', 'worth_reviewing', 'benign' ] as $verd ) {
				$ch = sanitize_key( (string) ( $raw[ $cat ][ $verd ] ?? 'log' ) );
				if ( ! in_array( $ch, $valid_channels, true ) ) {
					$ch = 'log';
				}
				$out[ $cat ][ $verd ] = $ch;
			}
		}
		// Hardcoded floor.
		if ( in_array( $out['malware']['confirmed'], [ 'mute', 'log' ], true ) ) {
			$out['malware']['confirmed'] = 'email_sms';
		}
		return $out;
	}

	public static function spend_summary(): array {
		return Spend_Tracker::summary();
	}
}
