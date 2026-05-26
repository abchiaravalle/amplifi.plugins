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

		add_menu_page(
			'amplifi.optimize',
			'amplifi.optimize',
			$cap,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-chart-line',
			81
		);

		$submenus = array(
			array( 'dashboard', __( 'Dashboard', 'amplifi-optimize' ), self::MENU_SLUG ),
			array( 'scans', __( 'Scans', 'amplifi-optimize' ), self::MENU_SLUG . '-scans' ),
			array( 'queue', __( 'Review Queue', 'amplifi-optimize' ), self::MENU_SLUG . '-queue' ),
			array( 'history', __( 'History', 'amplifi-optimize' ), self::MENU_SLUG . '-history' ),
			array( 'settings', __( 'Settings', 'amplifi-optimize' ), self::MENU_SLUG . '-settings' ),
		);

		foreach ( $submenus as $i => $sub ) {
			list( , $label, $slug ) = $sub;
			add_submenu_page(
				self::MENU_SLUG,
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
