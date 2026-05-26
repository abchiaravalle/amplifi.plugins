<?php
/**
 * Plugin Name:       amplifi.optimize
 * Plugin URI:        https://amplifi.studio/
 * Description:       AI-powered SEO triage. Scans for fixable SEO issues, drafts fixes with Claude, lets a human approve each one.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Amplifi Studio
 * Author URI:        https://amplifi.studio/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       amplifi-optimize
 * Domain Path:       /languages
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'AMPLIFI_OPTIMIZE_VERSION' ) ) {
	return;
}
define( 'AMPLIFI_OPTIMIZE_VERSION', '1.0.0' );
define( 'AMPLIFI_OPTIMIZE_FILE', __FILE__ );
define( 'AMPLIFI_OPTIMIZE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMPLIFI_OPTIMIZE_URL', plugin_dir_url( __FILE__ ) );
define( 'AMPLIFI_OPTIMIZE_BASENAME', plugin_basename( __FILE__ ) );
define( 'AMPLIFI_OPTIMIZE_SLUG', 'amplifi-optimize' );
define( 'AMPLIFI_OPTIMIZE_TEXT_DOMAIN', 'amplifi-optimize' );
define( 'AMPLIFI_OPTIMIZE_REST_NAMESPACE', 'amplifi-optimize/v1' );
define( 'AMPLIFI_OPTIMIZE_DEFAULT_MODEL', 'claude-sonnet-4-5' );
define( 'AMPLIFI_OPTIMIZE_API_BASE', 'https://api.anthropic.com/v1/messages' );
define( 'AMPLIFI_OPTIMIZE_ANTHROPIC_VERSION', '2023-06-01' );

require_once AMPLIFI_OPTIMIZE_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Amplifi_Optimize_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Amplifi_Optimize_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Amplifi_Optimize_Plugin', 'instance' ) );
