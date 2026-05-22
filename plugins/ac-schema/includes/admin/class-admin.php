<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin {
	private const PARENT_SLUG = 'amplifi-studio';
	private const PAGE_DASHBOARD = 'amplifi-ac-schema';
	private const PAGE_GLOBAL    = 'amplifi-ac-schema-global';
	private const PAGE_RULES     = 'amplifi-ac-schema-rules';
	private const PAGE_BULK      = 'amplifi-ac-schema-bulk';

	public function register(): void {
		add_action( 'init', [ $this, 'register_with_framework' ], 5 );
		add_action( 'admin_menu', [ $this, 'register_extra_submenus' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_with_framework(): void {
		if ( ! function_exists( 'amplifi_register_plugin' ) ) { return; }
		$dashboard = new Dashboard_Page();
		amplifi_register_plugin(
			'ac-schema',
			'Schema',
			'AI schema.org generation and editor.',
			AMPLIFI_SCHEMA_VERSION,
			AMPLIFI_SCHEMA_FILE,
			[ $dashboard, 'render' ]
		);
	}

	public function register_extra_submenus(): void {
		$global = new Global_Page();
		$rules  = new Rules_Page();
		$bulk   = new Bulk_Page();
		add_submenu_page( self::PARENT_SLUG, 'Schema: Global', 'Schema: Global', 'manage_options', self::PAGE_GLOBAL, [ $global, 'render' ] );
		add_submenu_page( self::PARENT_SLUG, 'Schema: URL Rules', 'Schema: URL Rules', 'manage_options', self::PAGE_RULES, [ $rules, 'render' ] );
		add_submenu_page( self::PARENT_SLUG, 'Schema: Bulk', 'Schema: Bulk', 'manage_options', self::PAGE_BULK, [ $bulk, 'render' ] );
	}

	public function enqueue_assets( string $hook ): void {
		// Only on our pages.
		$ours = [
			'amplifi-studio_page_' . self::PAGE_DASHBOARD,
			'amplifi-studio_page_' . self::PAGE_GLOBAL,
			'amplifi-studio_page_' . self::PAGE_RULES,
			'amplifi-studio_page_' . self::PAGE_BULK,
		];
		if ( ! in_array( $hook, $ours, true ) ) { return; }
		// All admin pages share a small bridge that exposes REST nonce + URL to inline page scripts.
		wp_register_script( 'ac-schema-admin-bridge', false, [], AMPLIFI_SCHEMA_VERSION, true );
		wp_enqueue_script( 'ac-schema-admin-bridge' );
		wp_localize_script( 'ac-schema-admin-bridge', 'AcSchemaAdmin', [
			'restUrl'  => esc_url_raw( rest_url( 'amplifi-schema/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		] );
	}
}
