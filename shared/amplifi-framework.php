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
//
// IMPORTANT: All function declarations must be inside this if-block (not at
// the top level of the file). PHP pre-scans top-level function declarations
// at compile time before executing any line. If a second plugin includes this
// file while the first has already loaded it, PHP would throw "Cannot redeclare"
// during compilation — before the runtime `return` guard could fire.
// Placing declarations inside a conditional makes them runtime-only.
if ( ! defined( 'AMPLIFI_FRAMEWORK_LOADED' ) ) {

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
	 * @param string   $slug        Plugin slug (e.g. 'ac-wp-translator').
	 * @param string   $name        Display name (e.g. 'Translate').
	 * @param string   $description Short description.
	 * @param string   $version     Current version.
	 * @param string   $file        Main plugin file path.
	 * @param callable $render      Admin page render callback.
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
	 * Initialize the framework: admin menu, auto-updates, AJAX handlers.
	 */
	add_action( 'admin_menu', 'amplifi_admin_menu', 5 );
	add_action( 'admin_enqueue_scripts', 'amplifi_hub_assets' );
	add_action( 'wp_ajax_amplifi_install_plugin', 'amplifi_ajax_install_plugin' );
	add_action( 'wp_ajax_amplifi_check_updates', 'amplifi_ajax_check_updates' );

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
			.amplifi-plugin-card h3 .dashicons { margin-right: 6px; color: #999; }
			.amplifi-plugin-card .description { color: #666; margin-bottom: 12px; }
			.amplifi-plugin-card .meta { font-size: 12px; color: #999; }
			.amplifi-plugin-card .status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
			.amplifi-plugin-card .status.installed { background: #d4edda; color: #155724; }
			.amplifi-plugin-card .status.available { background: #e2e3e5; color: #383d41; }
			.amplifi-plugin-card .status.update-available { background: #fff3cd; color: #856404; }
			.amplifi-plugin-card .actions { margin-top: 4px; }
			.amplifi-plugin-card .actions .button { margin-right: 6px; }
			.amplifi-install-btn.installing { opacity: 0.6; pointer-events: none; }
		' );

		wp_add_inline_script( 'jquery-core', '
			jQuery(function($) {
				$(".amplifi-hub").on("click", ".amplifi-install-btn", function(e) {
					e.preventDefault();
					var $btn = $(this);
					if ($btn.hasClass("installing")) return;

					var slug = $btn.data("slug");
					$btn.addClass("installing").text("Installing\u2026");

					$.post(ajaxurl, {
						action: "amplifi_install_plugin",
						slug: slug,
						_ajax_nonce: $btn.data("nonce")
					}, function(response) {
						if (response.success) {
							location.reload();
						} else {
							$btn.removeClass("installing").text("Install");
							alert(response.data || "Installation failed.");
						}
					}).fail(function() {
						$btn.removeClass("installing").text("Install");
						alert("Request failed. Please try again.");
					});
				});

				$("#amplifi-check-updates").on("click", function() {
					var $btn = $(this);
					var $status = $("#amplifi-check-status");
					$btn.prop("disabled", true);
					$status.text("Checking\u2026");
					$.post(ajaxurl, {
						action: "amplifi_check_updates",
						_ajax_nonce: "<?php echo esc_js( wp_create_nonce( 'amplifi_check_updates' ) ); ?>"
					}, function(response) {
						$btn.prop("disabled", false);
						if (response.success) {
							$status.text("Done \u2014 reloading\u2026");
							setTimeout(function() { location.reload(); }, 800);
						} else {
							$status.text(response.data || "Failed.");
						}
					}).fail(function() {
						$btn.prop("disabled", false);
						$status.text("Request failed.");
					});
				});
			});
		' );
	}

	/**
	 * Render the Plugin Hub page.
	 */
	function amplifi_render_hub() {
		global $amplifi_plugins;

		$catalog = amplifi_get_manifest();
		$release = amplifi_get_latest_release();
		$latest_version = $release ? ltrim( $release['tag_name'], 'v' ) : '';
		$install_nonce  = wp_create_nonce( 'amplifi_install_plugin' );

		?>
		<div class="wrap amplifi-hub">
			<h1>amplifi.studio</h1>
			<p class="amplifi-tagline">WordPress plugins by <a href="https://amplifi.studio" target="_blank">amplifi.studio</a></p>

			<div class="amplifi-plugin-grid">
				<?php foreach ( $catalog as $slug => $info ) :
					$installed    = isset( $amplifi_plugins[ $slug ] );
					$version      = $installed ? $amplifi_plugins[ $slug ]['version'] : '';
					$has_update   = $installed && $latest_version && version_compare( $latest_version, $version, '>' );
					$download_url = amplifi_get_download_url( $slug );
					$icon_class   = ! empty( $info['icon'] ) ? $info['icon'] : 'dashicons-admin-plugins';
				?>
					<div class="amplifi-plugin-card">
						<h3><span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span><?php echo esc_html( $info['name'] ); ?></h3>
						<p class="description"><?php echo esc_html( $info['description'] ); ?></p>
						<p>
							<?php if ( $installed && $has_update ) : ?>
								<span class="status update-available">Update available: <?php echo esc_html( $latest_version ); ?></span>
							<?php elseif ( $installed ) : ?>
								<span class="status installed">Installed <?php echo esc_html( $version ); ?></span>
							<?php else : ?>
								<span class="status available">Available</span>
							<?php endif; ?>
						</p>
						<p class="actions">
							<?php if ( $installed && $has_update ) : ?>
								<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-primary">Update</a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-' . $slug ) ); ?>" class="button">Settings</a>
							<?php elseif ( $installed ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-' . $slug ) ); ?>" class="button button-primary">Settings</a>
							<?php elseif ( $download_url && current_user_can( 'install_plugins' ) ) : ?>
								<button type="button" class="button button-primary amplifi-install-btn" data-slug="<?php echo esc_attr( $slug ); ?>" data-nonce="<?php echo esc_attr( $install_nonce ); ?>">Install</button>
							<?php else : ?>
								<a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/releases/latest" target="_blank" class="button">Download</a>
							<?php endif; ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<div style="margin-top: 32px; padding: 16px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 2px;">
				<strong>Auto-Updates Enabled</strong><br>
				Plugins check for new releases from <a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/releases" target="_blank">GitHub</a> automatically.
				Updates appear in <strong>Dashboard &gt; Updates</strong> like any other plugin.
				Release data is cached for 6 hours.
				<button type="button" id="amplifi-check-updates" class="button button-secondary" style="margin-left:12px; vertical-align:middle;">Check Now</button>
				<span id="amplifi-check-status" style="margin-left:8px; font-size:13px;"></span>
			</div>

			<p style="margin-top: 24px; color: #999; font-size: 12px;">
				This software is provided "AS IS" without warranty of any kind.
				See <a href="https://github.com/<?php echo esc_attr( AMPLIFI_GITHUB_REPO ); ?>/blob/main/LICENSE" target="_blank">LICENSE</a> for details.
			</p>
		</div>
		<?php
	}

	// =============================================================================
	// Plugin Manifest & Install
	// =============================================================================

	/**
	 * Get the plugin catalog from the remote manifest, with hardcoded fallback.
	 *
	 * Looks for plugins-manifest.json in the latest GitHub release assets.
	 * Falls back to a hardcoded catalog if the manifest is unavailable.
	 *
	 * @return array Associative array of slug => plugin info.
	 */
	function amplifi_get_manifest() {
		$cached = get_transient( 'amplifi_plugin_manifest' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$catalog = null;
		$release = amplifi_get_latest_release();

		if ( $release && ! empty( $release['assets'] ) ) {
			// Find the manifest asset.
			$manifest_url = '';
			foreach ( $release['assets'] as $asset ) {
				if ( $asset['name'] === 'plugins-manifest.json' ) {
					$manifest_url = $asset['browser_download_url'];
					break;
				}
			}

			if ( $manifest_url ) {
				$response = wp_remote_get( $manifest_url, array( 'timeout' => 10 ) );

				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
					$data = json_decode( wp_remote_retrieve_body( $response ), true );

					if ( ! empty( $data['plugins'] ) && ( ! isset( $data['schema_version'] ) || $data['schema_version'] === 1 ) ) {
						$catalog = $data['plugins'];
					}
				}
			}
		}

		// Fallback: hardcoded catalog for pre-manifest releases or when GitHub is unreachable.
		if ( ! $catalog ) {
			$catalog = array(
				'ac-wp-translator' => array(
					'name'        => 'Translate',
					'description' => 'AI-powered real-time translation using OpenAI with URL-based language prefixes and smart caching.',
					'icon'        => 'dashicons-translation',
				),
				'ac-bulk-meta' => array(
					'name'        => 'Meta',
					'description' => 'AI-powered bulk SEO meta editor with FAQ generation and JSON-LD structured data.',
					'icon'        => 'dashicons-editor-code',
				),
				'ac-magic-links' => array(
					'name'        => 'Magic',
					'description' => 'One-click magic links for password-protected pages with usage logging and IP geolocation.',
					'icon'        => 'dashicons-admin-links',
				),
				'ac-static-cache' => array(
					'name'        => 'LockCache',
					'description' => 'Static HTML cache for password-protected posts with admin management and debug logging.',
					'icon'        => 'dashicons-performance',
				),
				'ac-pods' => array(
					'name'        => 'Pods',
					'description' => 'Podcast carousel and floating player via Apple Podcasts RSS feed or built-in custom post type.',
					'icon'        => 'dashicons-microphone',
				),
			);
		}

		set_transient( 'amplifi_plugin_manifest', $catalog, 6 * HOUR_IN_SECONDS );
		return $catalog;
	}

	/**
	 * Get the download URL for a plugin from the latest release assets.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Download URL, or empty string if not found.
	 */
	function amplifi_get_download_url( $slug ) {
		$release = amplifi_get_latest_release();
		if ( ! $release || empty( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( strpos( $asset['name'], $slug ) !== false && substr( $asset['name'], -4 ) === '.zip' ) {
				return $asset['browser_download_url'];
			}
		}

		return '';
	}

	/**
	 * AJAX handler: clear the cached release data and force a fresh GitHub check.
	 */
	function amplifi_ajax_check_updates() {
		check_ajax_referer( 'amplifi_check_updates' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		delete_transient( 'amplifi_latest_release' );
		delete_transient( 'amplifi_plugin_manifest' );

		// Force WP to re-run its update check on next load.
		delete_site_transient( 'update_plugins' );

		wp_send_json_success();
	}

	/**
	 * AJAX handler: install and activate a plugin from the latest release.
	 */
	function amplifi_ajax_install_plugin() {
		check_ajax_referer( 'amplifi_install_plugin' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( 'You do not have permission to install plugins.' );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		if ( empty( $slug ) ) {
			wp_send_json_error( 'No plugin specified.' );
		}

		$download_url = amplifi_get_download_url( $slug );
		if ( empty( $download_url ) ) {
			wp_send_json_error( 'Download URL not found for this plugin.' );
		}

		// Verify the URL points to our GitHub repo.
		if ( strpos( $download_url, 'github.com/' . AMPLIFI_GITHUB_REPO ) === false
			&& strpos( $download_url, 'objects.githubusercontent.com' ) === false ) {
			wp_send_json_error( 'Invalid download source.' );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $download_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( is_wp_error( $skin->result ) ) {
			wp_send_json_error( $skin->result->get_error_message() );
		}

		if ( ! $result ) {
			wp_send_json_error( 'Installation failed. Check filesystem permissions.' );
		}

		// Activate the plugin.
		$plugin_file = $slug . '/' . $slug . '.php';
		$activated   = activate_plugin( $plugin_file );

		if ( is_wp_error( $activated ) ) {
			// Installed but activation failed — still a partial success.
			wp_send_json_error( 'Installed but activation failed: ' . $activated->get_error_message() );
		}

		// Clear transients so the hub re-evaluates state.
		delete_transient( 'amplifi_plugin_manifest' );
		delete_transient( 'amplifi_latest_release' );

		wp_send_json_success( array( 'slug' => $slug ) );
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

		$download_url = amplifi_get_download_url( $args->slug );

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
			'download_link' => $download_url,
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

} // end if ( ! defined( 'AMPLIFI_FRAMEWORK_LOADED' ) )
