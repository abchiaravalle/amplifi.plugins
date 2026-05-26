<?php
/**
 * Admin shell — registers the plugin with the amplifi.studio framework
 * and adds Findings/Audit/Settings as additional submenus under the shared
 * top-level amplifi.studio menu.
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Stealth\Stealth_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	public const CAPABILITY = 'manage_options';

	// Page slugs — kept the same as the pre-framework version so
	// every existing admin.php?page=... URL keeps working.
	public const PAGE_HEALTH    = 'amplifi-security';
	public const PAGE_FINDINGS  = 'amplifi-security-findings';
	public const PAGE_AUDIT     = 'amplifi-security-audit';
	public const PAGE_SETTINGS  = 'amplifi-security-settings';

	// Parent menu slug provided by the shared framework.
	public const PARENT_SLUG = 'amplifi-studio';

	public static function register(): void {
		// Framework hub registration — must run before the framework's own
		// admin_menu handler at priority 5.
		add_action( 'admin_menu', [ self::class, 'register_with_framework' ], 4 );

		// Additional submenus — must run after the framework has registered
		// the amplifi-studio parent menu (priority 5).
		add_action( 'admin_menu', [ self::class, 'register_extra_submenus' ], 11 );

		add_action( 'admin_init',           [ self::class, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts',[ self::class, 'enqueue_assets' ] );
		add_action( 'admin_notices',        [ self::class, 'render_notices' ] );
		add_filter( 'plugin_action_links_' . AMPLIFI_SECURITY_BASENAME, [ self::class, 'plugin_action_links' ] );

		// Stealth: hide from the framework's hub catalog.
		add_filter( 'amplifi_hub_catalog', [ self::class, 'maybe_hide_from_hub' ] );

		Settings_Page::register();
		Findings_Page::register();
		Audit_Page::register();
		Health_Page::register();
		Onboarding_Wizard::register();
	}

	public static function register_with_framework(): void {
		if ( Stealth_Mode::should_hide_for_current_user() ) {
			return;
		}
		if ( ! function_exists( 'amplifi_register_plugin' ) ) {
			return;
		}
		amplifi_register_plugin(
			self::PAGE_HEALTH,
			__( 'Security', 'amplifi-security' ),
			__( 'WordPress security with an AI brain. Less noise, more signal.', 'amplifi-security' ),
			AMPLIFI_SECURITY_VERSION,
			AMPLIFI_SECURITY_FILE,
			[ Health_Page::class, 'render' ]
		);
	}

	public static function register_extra_submenus(): void {
		if ( Stealth_Mode::should_hide_for_current_user() ) {
			return;
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Security: Findings', 'amplifi-security' ),
			__( 'Security: Findings', 'amplifi-security' ),
			self::CAPABILITY,
			self::PAGE_FINDINGS,
			[ Findings_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Security: Audit Log', 'amplifi-security' ),
			__( 'Security: Audit Log', 'amplifi-security' ),
			self::CAPABILITY,
			self::PAGE_AUDIT,
			[ Audit_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Security: Settings', 'amplifi-security' ),
			__( 'Security: Settings', 'amplifi-security' ),
			self::CAPABILITY,
			self::PAGE_SETTINGS,
			[ Settings_Page::class, 'render' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'amplifi_security_settings_group',
			'amplifi_security_settings',
			[
				'type'              => 'string',
				'sanitize_callback' => [ Settings_Page::class, 'sanitize' ],
				'show_in_rest'      => false,
				'default'           => wp_json_encode( [] ),
			]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		// All of our pages are submenus of amplifi-studio; their hook suffixes
		// are "amplifi-studio_page_amplifi-security[-X]".
		if ( ! str_contains( $hook, self::PAGE_HEALTH ) ) {
			return;
		}
		wp_enqueue_style(
			'amplifi-security-admin',
			AMPLIFI_SECURITY_URL . 'assets/admin.css',
			[],
			AMPLIFI_SECURITY_VERSION
		);
		wp_enqueue_script(
			'amplifi-security-admin',
			AMPLIFI_SECURITY_URL . 'assets/admin.js',
			[ 'jquery' ],
			AMPLIFI_SECURITY_VERSION,
			true
		);
		wp_localize_script(
			'amplifi-security-admin',
			'amplifiSecurity',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'amplifi_security' ),
			]
		);
	}

	public static function render_notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( Stealth_Mode::should_hide_for_current_user() ) {
			return;
		}
		if ( ! get_option( 'amplifi_security_onboarding_complete' ) ) {
			$url = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&wizard=1' ) );
			printf(
				'<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
				esc_html__( 'amplifi.security:', 'amplifi-security' ),
				esc_html__( 'Finish setup to start protecting this site.', 'amplifi-security' ),
				$url,
				esc_html__( 'Run setup', 'amplifi-security' )
			);
		}
	}

	public static function plugin_action_links( array $links ): array {
		if ( Stealth_Mode::should_hide_for_current_user() ) {
			return $links;
		}
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
				esc_html__( 'Settings', 'amplifi-security' )
			)
		);
		return $links;
	}

	/**
	 * Strip the plugin from the hub catalog when stealth is active.
	 */
	public static function maybe_hide_from_hub( $catalog ) {
		if ( ! is_array( $catalog ) ) {
			return $catalog;
		}
		if ( Stealth_Mode::should_hide_for_current_user() ) {
			unset( $catalog[ self::PAGE_HEALTH ] );
		}
		return $catalog;
	}
}
