<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACWPT_VERSION' ) ) {
	return;
}
define( 'ACWPT_VERSION', '3.3.7' );
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
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-string-store.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-string-queue.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-glossary.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-prompts.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-translator.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-preloader.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-admin.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-status.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-schema.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-llms.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-llms-admin.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-frontend.php';
require_once ACWPT_PLUGIN_DIR . 'includes/class-acwpt-cli.php';

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
	// Self-heal the string-store schema and back-fill from the legacy capped
	// options. Cheap: short-circuits on a single option read once installed.
	ACWPT_String_Store::maybe_install();

	ACWPT_Preloader::register();
	ACWPT_String_Queue::register();
	ACWPT_Frontend::instance()->init();

	// JSON-LD translation and the per-language llms.txt endpoints.
	ACWPT_Schema::init();
	ACWPT_Llms::init();

	if ( is_admin() ) {
		ACWPT_Admin::instance()->init();
		ACWPT_Llms_Admin::init();
		ACWPT_Status::register();
	}
}

/**
 * Custom cron interval for the preload watchdog.
 *
 * WordPress ships hourly/twicedaily/daily. An hour is far too coarse for
 * resuming a stalled preload — a broken chain would idle for up to an hour
 * before anything noticed.
 */
add_filter( 'cron_schedules', 'acwpt_cron_schedules' );

function acwpt_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['acwpt_five_minutes'] ) ) {
		$schedules['acwpt_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every 5 minutes (amplifi.translate)',
		);
	}
	return $schedules;
}

// Register a nav menu location so Appearance > Menus is available (even in block themes).
add_action( 'after_setup_theme', 'acwpt_register_nav_menus', 20 );

/**
 * Post types that should be translated, preloaded, and listed in the sitemap.
 *
 * Previously the preloader and the sitemap both hardcoded array('page','post'),
 * which silently excluded every custom post type. On a site whose content lives
 * in CPTs that meant most pages were never preloaded and never listed — 89 of
 * 146 published items on the first site this was measured against.
 *
 * Defaults to every public, publicly-queryable post type (minus attachments).
 * Override per-site via the `acwpt_post_types` filter, or via the
 * `post_types` key in acwpt_settings.
 *
 * @return string[]
 */
function acwpt_post_types() {
	$settings = get_option( 'acwpt_settings', array() );

	if ( ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
		$types = array_values( array_filter( $settings['post_types'], 'post_type_exists' ) );
	} else {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			// Skip types with no single view — nothing to translate at a URL.
			if ( empty( $pt->publicly_queryable ) && empty( $pt->_builtin ) ) {
				continue;
			}
			$types[] = $pt->name;
		}
	}

	if ( empty( $types ) ) {
		$types = array( 'page', 'post' );
	}

	/**
	 * Filter the post types amplifi.translate operates on.
	 *
	 * @param string[] $types
	 */
	return apply_filters( 'acwpt_post_types', $types );
}

/**
 * Should this post appear in the translated sitemap?
 *
 * Excludes content that must not be indexed regardless of language: password
 * protected posts, anything flagged noindex by Yoast/RankMath/AIOSEO, and any
 * post excluded via the `acwpt_sitemap_include_post` filter.
 *
 * @param WP_Post $post
 * @return bool
 */
function acwpt_include_in_sitemap( $post ) {
	$include = true;

	if ( ! empty( $post->post_password ) ) {
		$include = false;
	}

	// Yoast / AIOSEO store 1 for noindex; RankMath stores the robots array.
	if ( $include ) {
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === (string) $yoast ) {
			$include = false;
		}
	}

	if ( $include ) {
		$rankmath = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( is_array( $rankmath ) && in_array( 'noindex', $rankmath, true ) ) {
			$include = false;
		}
	}

	if ( $include ) {
		$aioseo = get_post_meta( $post->ID, '_aioseo_robots_noindex', true );
		if ( $aioseo ) {
			$include = false;
		}
	}

	// Per-site slug exclusions, editable in wp-admin.
	//
	// An SEO plugin's noindex flag is the right signal, but it is only set when
	// somebody remembers to set it. In-progress rebuilds routinely sit at a
	// public URL with no flag at all — on this site 'care-predict-revamp' was
	// the FIRST entry in a sitemap advertising it in eleven languages. A slug
	// list gives the editor a way to exclude a page without depending on
	// another plugin's metadata.
	if ( $include ) {
		$settings = get_option( 'acwpt_settings', array() );
		$excluded = isset( $settings['sitemap_exclude'] ) ? (array) $settings['sitemap_exclude'] : array();
		foreach ( $excluded as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			// Trailing * makes it a prefix match, so 'wip-*' covers a whole set.
			if ( '*' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $post->post_name, rtrim( $pattern, '*' ) ) ) {
					$include = false;
					break;
				}
			} elseif ( $post->post_name === $pattern ) {
				$include = false;
				break;
			}
		}
	}

	/**
	 * Filter whether a post is listed in the translated sitemap.
	 *
	 * @param bool    $include
	 * @param WP_Post $post
	 */
	return (bool) apply_filters( 'acwpt_sitemap_include_post', $include, $post );
}

function acwpt_register_nav_menus() {
	register_nav_menus( array(
		'acwpt_languages' => 'Language Switcher (amplifi.translate)',
	) );
}

// Activation.
register_activation_hook( __FILE__, 'acwpt_activate' );

function acwpt_activate() {
	ACWPT_Cache::create_table();
	ACWPT_String_Store::create_table();
	ACWPT_String_Store::migrate_from_options();

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
	// Leave no orphaned schedules behind: a recurring watchdog that outlives the
	// feature would keep firing against classes that are no longer loaded.
	wp_clear_scheduled_hook( 'acwpt_preload_watchdog' );
	wp_clear_scheduled_hook( 'acwpt_process_preload_batch' );
	wp_clear_scheduled_hook( 'acwpt_process_string_queue' );

	flush_rewrite_rules();
}
