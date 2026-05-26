<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-database.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-encryption.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-claude-client.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-seo-detector.php';

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/scanners/interface-scanner.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/scanners/class-meta-description-scanner.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/scanners/class-alt-text-scanner.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/scanners/class-title-scanner.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/scanners/class-unpublish-scanner.php';

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/proposers/interface-proposer.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/proposers/class-meta-description-proposer.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/proposers/class-alt-text-proposer.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/proposers/class-title-proposer.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/proposers/class-unpublish-proposer.php';

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/appliers/interface-applier.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/appliers/class-meta-description-applier.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/appliers/class-alt-text-applier.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/appliers/class-title-applier.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/appliers/class-unpublish-applier.php';

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/admin/class-admin-menu.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/admin/class-rest-api.php';
require_once AMPLIFI_OPTIMIZE_DIR . 'includes/admin/class-assets.php';

/**
 * Main plugin class. Acts as a registry for the four fix types and wires
 * everything together at runtime.
 */
class Amplifi_Optimize_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Amplifi_Optimize_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Map of fix_type => array{scanner, proposer, applier, label}.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $fix_types = array();

	/**
	 * Database wrapper.
	 *
	 * @var Amplifi_Optimize_Database
	 */
	public $db;

	/**
	 * SEO plugin detector.
	 *
	 * @var Amplifi_Optimize_SEO_Detector
	 */
	public $seo;

	/**
	 * Claude API client.
	 *
	 * @var Amplifi_Optimize_Claude_Client
	 */
	public $claude;

	/**
	 * Returns the singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wires up subsystems.
	 */
	private function boot(): void {
		$this->db     = new Amplifi_Optimize_Database();
		$this->seo    = new Amplifi_Optimize_SEO_Detector();
		$this->claude = new Amplifi_Optimize_Claude_Client();

		$this->register_fix_types();

		load_plugin_textdomain( AMPLIFI_OPTIMIZE_TEXT_DOMAIN, false, dirname( AMPLIFI_OPTIMIZE_BASENAME ) . '/languages' );

		new Amplifi_Optimize_Admin_Menu( $this );
		new Amplifi_Optimize_REST_API( $this );
		new Amplifi_Optimize_Assets( $this );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AMPLIFI_OPTIMIZE_DIR . 'includes/cli/class-cli-commands.php';
			\WP_CLI::add_command( 'amplifi-optimize', 'Amplifi_Optimize_CLI_Commands' );
		}
	}

	/**
	 * Registers the four v1 fix types.
	 */
	private function register_fix_types(): void {
		$this->fix_types = array(
			'meta_description' => array(
				'label'    => __( 'Meta descriptions', 'amplifi-optimize' ),
				'scanner'  => new Amplifi_Optimize_Meta_Description_Scanner( $this ),
				'proposer' => new Amplifi_Optimize_Meta_Description_Proposer( $this ),
				'applier'  => new Amplifi_Optimize_Meta_Description_Applier( $this ),
			),
			'alt_text'         => array(
				'label'    => __( 'Image alt text', 'amplifi-optimize' ),
				'scanner'  => new Amplifi_Optimize_Alt_Text_Scanner( $this ),
				'proposer' => new Amplifi_Optimize_Alt_Text_Proposer( $this ),
				'applier'  => new Amplifi_Optimize_Alt_Text_Applier( $this ),
			),
			'title'            => array(
				'label'    => __( 'Long title rewrites', 'amplifi-optimize' ),
				'scanner'  => new Amplifi_Optimize_Title_Scanner( $this ),
				'proposer' => new Amplifi_Optimize_Title_Proposer( $this ),
				'applier'  => new Amplifi_Optimize_Title_Applier( $this ),
			),
			'unpublish'        => array(
				'label'    => __( 'Unpublish candidates', 'amplifi-optimize' ),
				'scanner'  => new Amplifi_Optimize_Unpublish_Scanner( $this ),
				'proposer' => new Amplifi_Optimize_Unpublish_Proposer( $this ),
				'applier'  => new Amplifi_Optimize_Unpublish_Applier( $this ),
			),
		);
	}

	/**
	 * Returns the registered fix types.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_fix_types(): array {
		return $this->fix_types;
	}

	/**
	 * Returns a single fix type bundle, or null.
	 *
	 * @param string $type Fix type slug.
	 */
	public function get_fix_type( string $type ): ?array {
		return $this->fix_types[ $type ] ?? null;
	}

	/**
	 * Returns plugin settings with defaults applied.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		$defaults = array(
			'model'                          => AMPLIFI_OPTIMIZE_DEFAULT_MODEL,
			'batch_size_meta'                => 10,
			'batch_size_alt'                 => 5,
			'rate_limit_per_minute'          => 50,
			'included_post_types'            => array( 'post', 'page' ),
			'min_image_dimension'            => 100,
			'include_svg'                    => false,
			'date_range_days'                => 0,
			'detector_override'              => 'auto',
			'delete_data_on_uninstall'       => false,
			'undo_window'                    => 50,
		);
		$saved = get_option( 'amplifi_optimize_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	/**
	 * Persists settings.
	 *
	 * @param array<string,mixed> $values Settings to merge over current.
	 */
	public function update_settings( array $values ): void {
		$current = $this->get_settings();
		update_option( 'amplifi_optimize_settings', array_merge( $current, $values ) );
	}

	/**
	 * Activation hook: create the suggestions table.
	 */
	public static function activate(): void {
		require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-database.php';
		( new Amplifi_Optimize_Database() )->install();
	}

	/**
	 * Deactivation hook: drop transients but keep data.
	 */
	public static function deactivate(): void {
		delete_transient( 'amplifi_optimize_scan_progress' );
	}
}
