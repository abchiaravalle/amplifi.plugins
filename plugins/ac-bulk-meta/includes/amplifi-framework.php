<?php
/**
 * amplifi.studio Framework
 *
 * Shared code for all amplifi.studio WordPress plugins.
 * Provides: top-level admin menu, plugin hub, GitHub auto-updates.
 *
 * Each plugin includes this file. The first to load registers the menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent double-loading if multiple amplifi plugins are active.
if ( defined( 'AMPLIFI_FRAMEWORK_LOADED' ) ) {
	return;
}
define( 'AMPLIFI_FRAMEWORK_LOADED', true );
define( 'AMPLIFI_GITHUB_REPO', 'abchiaravalle/amplifi.plugins' );

/**
 * Registry of amplifi plugins. Each plugin registers itself here.
 */
global $amplifi_plugins;
$amplifi_plugins = array();

/**
 * Register an amplifi plugin.
 *
 * @param string $slug        Plugin slug (e.g. 'ac-wp-translator').
 * @param string $name        Display name (e.g. 'Translate').
 * @param string $description Short description.
 * @param string $version     Current version.
 * @param string $file        Main plugin file path.
 * @param callable $render    Admin page render callback.
 */
function amplifi_register_plugin( $slug, $name, $description, $version, $file, $render ) {
	global $amplifi_plugins;
	$amplifi_plugins[ $slug ] = array(
		'slug'        => $slug,
		'name'        => $name,
		'description' => $description,
		'version'     => $version,
		'file'        => $file,
		'render'      => $render,
	);
}

/**
 * Initialize the framework: admin menu, auto-updates.
 */
add_action( 'admin_menu', 'amplifi_admin_menu', 5 );
add_action( 'admin_enqueue_scripts', 'amplifi_hub_assets' );

// Auto-updates.
add_filter( 'pre_set_site_transient_update_plugins', 'amplifi_check_for_updates' );
add_filter( 'plugins_api', 'amplifi_plugin_info', 20, 3 );

/**
 * Register the top-level amplifi.studio menu and submenus for each plugin.
 */
function amplifi_admin_menu() {
	global $amplifi_plugins;

	// SVG icon for the admin menu (amplifi mark - simplified).
	$icon = 'data:image/svg+xml;base64,' . base64_encode(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
		. '<polygon fill="#78ea78" points="57 72 57 44 35 44 57 72"/>'
		. '<path fill="#a0a5aa" d="M20 48v20c0 4 3 8 8 8h20L20 48z"/>'
		. '<path fill="#a0a5aa" d="M68 20H20v12h42c1 0 1 0 1 1v41h12V28c0-4-3-8-8-8z"/>'
		. '</svg>'
	);

	// Top-level menu.
	add_menu_page(
		'amplifi.studio',
		'amplifi.studio',
		'manage_options',
		'amplifi-studio',
		'amplifi_render_hub',
		$icon,
		3
	);

	// Hub submenu (replaces the duplicate top-level link).
	add_submenu_page(
		'amplifi-studio',
		'Plugin Hub',
		'Plugin Hub',
		'manage_options',
		'amplifi-studio',
		'amplifi_render_hub'
	);

	// Register each plugin as a submenu.
	foreach ( $amplifi_plugins as $slug => $plugin ) {
		add_submenu_page(
			'amplifi-studio',
			$plugin['name'],
			$plugin['name'],
			'manage_options',
			'amplifi-' . $slug,
			$plugin['render']
		);
	}
}

/**
 * Hub page assets.
 */
function amplifi_hub_assets( $hook ) {
	if ( 'toplevel_page_amplifi-studio' !== $hook ) {
		return;
	}

	wp_add_inline_style( 'wp-admin', '
		.amplifi-hub { max-width: 900px; }
		.amplifi-hub h1 { font-size: 28px; font-weight: 300; margin-bottom: 4px; }
		.amplifi-hub .amplifi-tagline { color: #666; margin-bottom: 24px; }
		.amplifi-plugin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 16px; }
		.amplifi-plugin-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; }
		.amplifi-plugin-card h3 { margin: 0 0 8px; font-size: 16px; }
		.amplifi-plugin-card .description { color: #666; margin-bottom: 12px; }
		.amplifi-plugin-card .meta { font-size: 12px; color: #999; }
		.amplifi-plugin-card .status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
		.amplifi-plugin-card .status.installed { background: #d4edda; color: #155724; }
		.amplifi-plugin-card .status.available { background: #e2e3e5; color: #383d41; }
	' );
}

/**
 * Render the Plugin Hub page.
 */
function amplifi_render_hub() {
	global $amplifi_plugins;

	// Known plugins in the amplifi.studio suite.
	$catalog = array(
		'ac-wp-translator' => array(
			'name'        => 'Translate',
			'description' => 'AI-powered real-time translation using OpenAI with URL-based language prefixes and smart caching.',
		),
		'ac-bulk-meta' => array(
			'name'        => 'Meta',
			'description' => 'AI-powered bulk SEO meta editor with FAQ generation and JSON-LD structured data.',
		),
	);

	?>
	<div class="wrap amplifi-hub">
		<h1>amplifi.studio</h1>
		<p class="amplifi-tagline">WordPress plugins by <a href="https://amplifi.studio" target="_blank">amplifi.studio</a></p>

		<div class="amplifi-plugin-grid">
			<?php foreach ( $catalog as $slug => $info ) :
				$installed = isset( $amplifi_plugins[ $slug ] );
				$version   = $installed ? $amplifi_plugins[ $slug ]['version'] : '';
			?>
				<div class="amplifi-plugin-card">
					<h3><?php echo esc_html( $info['name'] ); ?></h3>
					<p class="description"><?php echo esc_html( $info['description'] ); ?></p>
					<p>
						<?php if ( $installed ) : ?>
							<span class="status installed">Installed <?php echo esc_html( $version ); ?></span>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-' . $slug ) ); ?>" class="button button-primary" style="margin-left: 8px;">Settings</a>
						<?php else : ?>
							<span class="status available">Available</span>
							<a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/releases/latest" target="_blank" class="button" style="margin-left: 8px;">Download</a>
						<?php endif; ?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>

		<div style="margin-top: 32px; padding: 16px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 2px;">
			<strong>Auto-Updates Enabled</strong><br>
			Plugins check for new releases from <a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/releases" target="_blank">GitHub</a> automatically.
			Updates appear in <strong>Dashboard > Updates</strong> like any other plugin.
		</div>

		<p style="margin-top: 24px; color: #999; font-size: 12px;">
			This software is provided "AS IS" without warranty of any kind.
			See <a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/blob/main/LICENSE" target="_blank">LICENSE</a> for details.
		</p>
	</div>
	<?php
}

// =============================================================================
// GitHub Auto-Updates
// =============================================================================

/**
 * Check GitHub releases for plugin updates.
 */
function amplifi_check_for_updates( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	global $amplifi_plugins;

	$release = amplifi_get_latest_release();
	if ( ! $release ) {
		return $transient;
	}

	$latest_version = ltrim( $release['tag_name'], 'v' );

	foreach ( $amplifi_plugins as $slug => $plugin ) {
		$plugin_basename = plugin_basename( $plugin['file'] );

		if ( version_compare( $latest_version, $plugin['version'], '>' ) ) {
			// Find the zip asset for this plugin.
			$download_url = '';
			foreach ( $release['assets'] as $asset ) {
				if ( strpos( $asset['name'], $slug ) !== false && substr( $asset['name'], -4 ) === '.zip' ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}

			if ( $download_url ) {
				$transient->response[ $plugin_basename ] = (object) array(
					'slug'        => $slug,
					'plugin'      => $plugin_basename,
					'new_version' => $latest_version,
					'url'         => 'https://github.com/' . AMPLIFI_GITHUB_REPO,
					'package'     => $download_url,
				);
			}
		}
	}

	return $transient;
}

/**
 * Provide plugin info for the WordPress updates UI.
 */
function amplifi_plugin_info( $result, $action, $args ) {
	if ( $action !== 'plugin_information' ) {
		return $result;
	}

	global $amplifi_plugins;

	if ( ! isset( $amplifi_plugins[ $args->slug ] ) ) {
		return $result;
	}

	$plugin  = $amplifi_plugins[ $args->slug ];
	$release = amplifi_get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	return (object) array(
		'name'          => 'amplifi.studio - ' . $plugin['name'],
		'slug'          => $args->slug,
		'version'       => ltrim( $release['tag_name'], 'v' ),
		'author'        => '<a href="https://amplifi.studio">amplifi.studio</a>',
		'homepage'      => 'https://github.com/' . AMPLIFI_GITHUB_REPO,
		'sections'      => array(
			'description' => $plugin['description'],
			'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
		),
		'download_link' => '',
	);
}

/**
 * Fetch latest GitHub release (cached 6 hours).
 */
function amplifi_get_latest_release() {
	$cached = get_transient( 'amplifi_latest_release' );
	if ( $cached !== false ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . AMPLIFI_GITHUB_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'amplifi-studio-wp-plugin',
			),
		)
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		// Cache failure for 1 hour to avoid hammering GitHub.
		set_transient( 'amplifi_latest_release', null, HOUR_IN_SECONDS );
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $data['tag_name'] ) ) {
		return null;
	}

	set_transient( 'amplifi_latest_release', $data, 6 * HOUR_IN_SECONDS );
	return $data;
}
