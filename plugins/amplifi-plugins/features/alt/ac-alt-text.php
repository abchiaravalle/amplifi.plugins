<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACALT_VERSION' ) ) {
	return;
}
define( 'ACALT_VERSION', '3.0.6' );
define( 'ACALT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACALT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACALT_PLUGIN_FILE', __FILE__ );

require_once ACALT_PLUGIN_DIR . 'includes/amplifi-framework.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-queue.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-reachability.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-generator.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-cron.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-uploader-hook.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-report.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-admin.php';
require_once ACALT_PLUGIN_DIR . 'includes/class-acalt-media-ui.php';

amplifi_register_plugin(
	'ac-alt-text',
	'Alt',
	'AI-powered alt text generation for WordPress images. Bulk + auto-on-upload, with cost caps and daily email reports.',
	ACALT_VERSION,
	__FILE__,
	array( ACALT_Admin::instance(), 'render_dashboard' )
);

add_action( 'plugins_loaded', 'acalt_init', 1 );

function acalt_init() {
	ACALT_Cron::register();
	ACALT_Uploader_Hook::register();
	ACALT_Media_UI::register();

	if ( is_admin() ) {
		ACALT_Admin::instance()->init();
	}
}

register_activation_hook( __FILE__, 'acalt_activate' );

function acalt_activate() {
	ACALT_Queue::create_table();

	$defaults = array(
		'api_key'             => '',
		'model'               => 'gpt-4o-mini',
		'auto_on_upload'      => false,
		'daily_spend_cap_usd' => 5.0,
		'report_email'        => '',
		'report_enabled'      => true,
		'prompt_style'        => 'concise',
		'language'            => get_locale(),
		'site_context'        => '',
	);

	if ( ! get_option( 'acalt_settings' ) ) {
		update_option( 'acalt_settings', $defaults );
	}
	if ( ! get_option( 'acalt_daily_stats' ) ) {
		update_option( 'acalt_daily_stats', array() );
	}

	if ( ! wp_next_scheduled( 'acalt_cron_drain' ) ) {
		wp_schedule_event( time() + 60, 'minute', 'acalt_cron_drain' );
	}
	if ( ! wp_next_scheduled( 'acalt_daily_report' ) ) {
		$next_9utc = strtotime( 'tomorrow 09:00 UTC' );
		wp_schedule_event( $next_9utc, 'daily', 'acalt_daily_report' );
	}
}

register_deactivation_hook( __FILE__, 'acalt_deactivate' );

function acalt_deactivate() {
	wp_clear_scheduled_hook( 'acalt_cron_drain' );
	wp_clear_scheduled_hook( 'acalt_daily_report' );
}

// Register a "minute" cron schedule used by the worker.
add_filter( 'cron_schedules', function( $schedules ) {
	if ( ! isset( $schedules['minute'] ) ) {
		$schedules['minute'] = array(
			'interval' => 60,
			'display'  => 'Every Minute',
		);
	}
	return $schedules;
} );
