<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACWPT_VERSION' ) ) {
	return;
}
define( 'ACWPT_VERSION', '3.1.8' );
define( 'ACWPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACWPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACWPT_PLUGIN_FILE', __FILE__ );

/**
 * Cache-busting version string for an asset (uses file mtime when available).
 *
 * @param string $relative_path Path relative to plugin dir, e.g. 'assets/css/frontend.css'.
 * @return string Version query arg for enqueue.
 */
function acwpt_asset_version( $relative_path ) {
	$path = ACWPT_PLUGIN_DIR . $relative_path;
	if ( file_exists( $path ) ) {
		return ACWPT_VERSION . '.' . filemtime( $path );
	}
	return ACWPT_VERSION;
}

/**
 * One-shot upgrade routine. Runs when stored db version is older than current.
 * v2.0.0: provider switched from OpenAI to Anthropic. Clear stale model
 * selection and the cached models list so the user re-picks a Claude model.
 */
function acwpt_maybe_upgrade() {
	$stored = get_option( 'acwpt_db_version', '1.0' );
	if ( version_compare( $stored, '2.0.0', '<' ) ) {
		$settings = get_option( 'acwpt_settings', array() );
		if ( isset( $settings['model'] ) ) {
			$settings['model'] = '';
		}
		if ( ! isset( $settings['custom_version'] ) ) {
			$settings['custom_version'] = 0;
		}
		update_option( 'acwpt_settings', $settings );
		delete_transient( 'acwpt_models_list' );
		update_option( 'acwpt_db_version', '2.0.0' );
		update_option( 'acwpt_show_v2_notice', 1 );
	}

	// 2.0.0-beta.3: parse_response() no longer leaks trailing ===EXCERPT===
	// delimiters into content. Bump custom_version once so any translation
	// cached by earlier betas is re-generated on next view.
	if ( ! get_option( 'acwpt_v2b3_migrated' ) ) {
		$settings = get_option( 'acwpt_settings', array() );
		$settings['custom_version'] = ( isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0 ) + 1;
		update_option( 'acwpt_settings', $settings );
		update_option( 'acwpt_v2b3_migrated', 1 );
	}

	// 2.0.0-beta.5: &nbsp; entities in translated output could double-escape
	// in some themes and render as "&NBSP;" inside uppercased headings.
	// Language packs now request Unicode U+00A0 directly, and parse_response
	// normalises any residual entity. Invalidate cache once.
	if ( ! get_option( 'acwpt_v2b5_migrated' ) ) {
		$settings = get_option( 'acwpt_settings', array() );
		$settings['custom_version'] = ( isset( $settings['custom_version'] ) ? (int) $settings['custom_version'] : 0 ) + 1;
		update_option( 'acwpt_settings', $settings );
		update_option( 'acwpt_v2b5_migrated', 1 );
	}
}
add_action( 'admin_init', 'acwpt_maybe_upgrade' );

/**
 * One-time admin notice after upgrading to v2.0.0.
 */
function acwpt_v2_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! get_option( 'acwpt_show_v2_notice' ) ) {
		return;
	}
	$url = admin_url( 'admin.php?page=amplifi-ac-wp-translator' );
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong>amplifi.translate v2.0.0:</strong> This release switches from OpenAI to <strong>Anthropic Claude</strong>.
			Your existing OpenAI key will not work. Please <a href="<?php echo esc_url( $url ); ?>">enter your Anthropic API key and pick a Claude model</a> to resume translations.
		</p>
	</div>
	<?php
	delete_option( 'acwpt_show_v2_notice' );
}
add_action( 'admin_notices', 'acwpt_v2_admin_notice' );

// Load amplifi.studio shared framework.
require_once ACWPT_PLUGIN_DIR . 'includes/amplifi-framework.php';

require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-languages.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-cache.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-glossary.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-prompts.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-translator.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-preloader.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-admin.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-frontend.php';

// Register with the amplifi.studio framework.
amplifi_register_plugin(
	'ac-wp-translator',
	'Translate',
	'AI-powered real-time translation using Anthropic Claude with URL-based language prefixes, native-speaker B2B prompts, custom glossary, and smart caching.',
	ACWPT_VERSION,
	__FILE__,
	array( ACWPT_Admin::instance(), 'render_page' )
);

// Bootstrap.
add_action( 'plugins_loaded', 'acwpt_init', 1 );

function acwpt_init() {
	ACWPT_Preloader::register();
	ACWPT_Frontend::instance()->init();

	if ( is_admin() ) {
		ACWPT_Admin::instance()->init();
	}
}

// Register a nav menu location so Appearance > Menus is available (even in block themes).
add_action( 'after_setup_theme', 'acwpt_register_nav_menus', 20 );

function acwpt_register_nav_menus() {
	register_nav_menus( array(
		'acwpt_languages' => 'Language Switcher (amplifi.translate)',
	) );
}

// Activation.
register_activation_hook( __FILE__, 'acwpt_activate' );

function acwpt_activate() {
	ACWPT_Cache::create_table();

	$defaults = array(
		'api_key'            => '',
		'source_language'    => 'en',
		'enabled_languages'  => array(),
		'show_flags'         => true,
		'show_suggestion'    => true,
		'model'              => '',
		'preload_auto'       => false,
	);

	if ( ! get_option( 'acwpt_settings' ) ) {
		update_option( 'acwpt_settings', $defaults );
	}

	// Flush rewrite rules so language prefixes work.
	flush_rewrite_rules();
}

// Deactivation.
register_deactivation_hook( __FILE__, 'acwpt_deactivate' );

function acwpt_deactivate() {
	flush_rewrite_rules();
}
