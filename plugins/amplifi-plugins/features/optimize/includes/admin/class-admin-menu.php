<?php
/**
 * Admin menu: one top-level menu with five submenus.
 *
 * All screens render a single React root keyed off the current submenu slug.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu pages.
 */
class Amplifi_Optimize_Admin_Menu {

	const MENU_SLUG = 'amplifi-optimize';

	/**
	 * Plugin instance.
	 *
	 * @var Amplifi_Optimize_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Amplifi_Optimize_Plugin $plugin Plugin singleton.
	 */
	public function __construct( Amplifi_Optimize_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Registers the menu items.
	 */
	public function register(): void {
		$cap = 'manage_options';

		if ( function_exists( 'amplifi_register_plugin' ) ) {
			amplifi_register_plugin(
				'amplifi-optimize',
				'Optimize',
				'AI-powered SEO triage.',
				defined( 'AMPLIFI_OPTIMIZE_VERSION' ) ? AMPLIFI_OPTIMIZE_VERSION : '1.0.0',
				defined( 'AMPLIFI_OPTIMIZE_FILE' ) ? AMPLIFI_OPTIMIZE_FILE : __FILE__,
				array( $this, 'render' )
			);
		}

		$parent = 'amplifi-studio';
		$submenus = array(
			array( 'scans', __( 'Optimize: Scans', 'amplifi-optimize' ), 'amplifi-optimize-scans' ),
			array( 'queue', __( 'Optimize: Queue', 'amplifi-optimize' ), 'amplifi-optimize-queue' ),
			array( 'history', __( 'Optimize: History', 'amplifi-optimize' ), 'amplifi-optimize-history' ),
			array( 'settings', __( 'Optimize: Settings', 'amplifi-optimize' ), 'amplifi-optimize-settings' ),
		);

		foreach ( $submenus as $sub ) {
			list( , $label, $slug ) = $sub;
			add_submenu_page(
				$parent,
				$label,
				$label,
				$cap,
				$slug,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Renders a React mount point. The View determines which screen based on
	 * the current page slug (passed via window.AmplifiOptimize).
	 */
	public function render(): void {
		$slug   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : self::MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$screen = str_replace( self::MENU_SLUG . '-', '', $slug );
		if ( $screen === self::MENU_SLUG ) {
			$screen = 'dashboard';
		}
		echo '<div class="wrap amplifi-optimize-wrap">';
		echo '<div id="amplifi-optimize-root" data-screen="' . esc_attr( $screen ) . '"></div>';
		echo '<footer class="amplifi-optimize-footer">amplifi.optimize by <a href="https://amplifi.studio/" target="_blank" rel="noopener noreferrer">Amplifi Studio</a></footer>';
		echo '</div>';
	}
}
