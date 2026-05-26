<?php
/**
 * Enqueues the React admin bundle and styles on amplifi.optimize screens only.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset enqueuer.
 */
class Amplifi_Optimize_Assets {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues script and style on our pages only.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'amplifi-optimize' ) ) {
			return;
		}

		$build_dir = AMPLIFI_OPTIMIZE_DIR . 'assets/build/';
		$build_url = AMPLIFI_OPTIMIZE_URL . 'assets/build/';

		$asset_file = $build_dir . 'index.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => AMPLIFI_OPTIMIZE_VERSION,
			);

		wp_enqueue_script(
			'amplifi-optimize-admin',
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'amplifi-optimize-admin',
			$build_url . 'index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		$fix_types = array();
		foreach ( $this->plugin->get_fix_types() as $slug => $bundle ) {
			$fix_types[ $slug ] = array( 'label' => (string) $bundle['label'] );
		}

		wp_localize_script(
			'amplifi-optimize-admin',
			'AmplifiOptimize',
			array(
				'restUrl'   => esc_url_raw( rest_url( AMPLIFI_OPTIMIZE_REST_NAMESPACE . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'fixTypes'  => $fix_types,
				'adminUrl'  => admin_url( 'admin.php' ),
				'siteName'  => get_bloginfo( 'name' ),
				'version'   => AMPLIFI_OPTIMIZE_VERSION,
				'provider'  => $this->plugin->seo->provider(),
			)
		);

		wp_set_script_translations( 'amplifi-optimize-admin', AMPLIFI_OPTIMIZE_TEXT_DOMAIN );
	}
}
