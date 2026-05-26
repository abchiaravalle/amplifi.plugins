<?php
/*
Plugin Name: amplifi.lockcache
Plugin URI: https://github.com/abchiaravalle/amplifi.plugins
Description: Static HTML cache for password-protected posts. Caches unlocked content for non-admin visitors, denies direct cache access, provides admin panel for cache management and debug logs.
Version: 3.0.0
Author: amplifi.studio
Author URI: https://amplifi.studio
License: MIT
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ACSC_VERSION' ) ) {
	return;
}
define( 'ACSC_VERSION', '3.0.0' );
define( 'ACSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACSC_PLUGIN_FILE', __FILE__ );

// Load the amplifi.studio shared framework.
require_once ACSC_PLUGIN_DIR . 'includes/amplifi-framework.php';

class Amplifi_Static_Cache {

	private $cache_dir;
	private $debug_logs = array();
	private $log_file_path;

	public function __construct() {
		// Directory setup
		$this->cache_dir     = WP_CONTENT_DIR . '/pp-static-cache/';
		$this->log_file_path = $this->cache_dir . 'ppsc-debug.log';

		// Register with the amplifi.studio framework (adds submenu under amplifi.studio).
		amplifi_register_plugin(
			'ac-static-cache',
			'LockCache',
			'Static HTML cache for password-protected posts with admin management and debug logging.',
			ACSC_VERSION,
			ACSC_PLUGIN_FILE,
			array( $this, 'render_admin_page' )
		);

		// Set no-cache headers if the post is still locked
		add_action( 'template_redirect', array( $this, 'maybe_set_no_cache_headers' ), 1 );

		// Serve from cache at a later priority so WP handles password cookies first
		add_action( 'template_redirect', array( $this, 'maybe_serve_cached_content' ), 100 );

		// Capture output if unlocked (skip admin), also at a very late priority
		add_filter( 'template_include', array( $this, 'capture_template_output' ), 9999 );

		// On activation, create dir, .htaccess, set perms
		register_activation_hook( ACSC_PLUGIN_FILE, array( $this, 'activate_plugin' ) );
	}

	/**
	 * On plugin activation, ensure cache directory, .htaccess, etc.
	 */
	public function activate_plugin() {
		$this->create_cache_dir();
		$this->write_htaccess();
		$this->init_log_file();
	}

	/**
	 * Creates the cache directory with 0700. Also corrects perms if it already exists.
	 */
	private function create_cache_dir() {
		if ( ! is_dir( $this->cache_dir ) ) {
			mkdir( $this->cache_dir, 0700, true );
		}
		chmod( $this->cache_dir, 0700 );
	}

	/**
	 * Writes a .htaccess file to deny all direct access in the cache directory.
	 */
	private function write_htaccess() {
		$htaccess_path = $this->cache_dir . '.htaccess';
		$htaccess_rule = "Order allow,deny\nDeny from all\n";
		file_put_contents( $htaccess_path, $htaccess_rule );
		chmod( $htaccess_path, 0600 );
	}

	/**
	 * Ensures our log file exists (touch) and sets perms 0600.
	 */
	private function init_log_file() {
		if ( ! file_exists( $this->log_file_path ) ) {
			touch( $this->log_file_path );
		}
		chmod( $this->log_file_path, 0600 );
	}

	/**
	 * Decide if the request is for Search & Filter Pro (skip caching).
	 */
	private function is_searchandfilter_request() {
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return true;
		}
		if (
			! empty( $_REQUEST['sfid'] )
			|| ! empty( $_REQUEST['sf_data'] )
			|| ! empty( $_REQUEST['sf_action'] )
		) {
			return true;
		}
		return false;
	}

	/**
	 * If post is password-protected but not unlocked, set no-cache headers.
	 */
	public function maybe_set_no_cache_headers() {
		if ( $this->is_searchandfilter_request() ) {
			$this->add_log( "Skipping no-cache headers because this is an S&F or AJAX request." );
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( $post && ! empty( $post->post_password ) && post_password_required( $post ) ) {
			$this->add_log( "Setting no-cache headers for post {$post->ID}" );
			nocache_headers();
		}
	}

	/**
	 * Serve from cache if post is unlocked, file exists, and skip if admin user or S&F request.
	 */
	public function maybe_serve_cached_content() {
		if ( $this->is_searchandfilter_request() ) {
			$this->add_log( "Skipping serve cache because this is an S&F or AJAX request." );
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post || empty( $post->post_password ) ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			$this->add_log( "Post {$post->ID} locked => password form." );
			return;
		}

		// If admin user visits, skip serving file (avoid admin bar).
		if ( current_user_can( 'manage_options' ) ) {
			$this->add_log( "Admin user => ignoring cache for post {$post->ID}." );
			return;
		}

		$cache_file = $this->get_cache_file_path( $post->ID );
		if ( file_exists( $cache_file ) ) {
			$this->add_log( "Serving cache => {$cache_file}" );
			echo "<!-- Cached by amplifi.lockcache -->\n";
			readfile( $cache_file );
			exit;
		}

		$this->add_log( "No cache file for unlocked post {$post->ID} => normal render." );
	}

	/**
	 * Late hook: capture output if unlocked, skip caching if admin, store file with 0600 perms
	 */
	public function capture_template_output( $template ) {
		if ( $this->is_searchandfilter_request() ) {
			$this->add_log( "Skipping capture because this is an S&F or AJAX request." );
			return $template;
		}

		if ( ! is_singular() ) {
			return $template;
		}

		global $post;
		if ( ! $post || empty( $post->post_password ) ) {
			return $template;
		}

		if ( post_password_required( $post ) ) {
			$this->add_log( "capture_template_output => Post still locked, skipping cache." );
			return $template;
		}

		$cache_file = $this->get_cache_file_path( $post->ID );
		if ( file_exists( $cache_file ) ) {
			return $template;
		}

		// If user is admin => skip caching
		if ( current_user_can( 'manage_options' ) ) {
			$this->add_log( "Admin user => skip caching for post {$post->ID}." );
			return $template;
		}

		// Capture
		$this->add_log( "Capturing output for post {$post->ID} => no cache yet." );
		ob_start();
		include $template;
		$html = ob_get_clean();

		// Check if password form is still there
		if ( strpos( $html, 'post-password-form' ) !== false || strpos( $html, 'name="post_password_form"' ) !== false ) {
			$this->add_log( "Password form found => skip cache for post {$post->ID}." );
			echo $html;
			exit;
		}

		// Write cache file with 0600 perms
		$this->create_cache_dir();
		if ( false === file_put_contents( $cache_file, $html ) ) {
			$this->add_log( "Failed to write => {$cache_file}" );
			echo $html;
			exit;
		}
		chmod( $cache_file, 0600 );
		$this->add_log( "Cache file created => {$cache_file}" );

		// Serve
		echo "<!-- Cached by amplifi.lockcache -->\n";
		echo $html;
		exit;
	}

	/**
	 * Build path to the cache file
	 */
	private function get_cache_file_path( $post_id ) {
		return $this->cache_dir . 'cache-' . $post_id . '.html';
	}

	/**
	 * Renders admin page: cache manager + debug logs.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle posted actions
		if ( isset( $_POST['ppsc_action'] ) && check_admin_referer( 'ppsc_cache_action', 'ppsc_nonce' ) ) {
			if ( $_POST['ppsc_action'] === 'clear_all' ) {
				$this->clear_all_cache();
			} elseif ( $_POST['ppsc_action'] === 'clear_one' && isset( $_POST['ppsc_post_id'] ) ) {
				$this->clear_single_cache( intval( $_POST['ppsc_post_id'] ) );
			} elseif ( $_POST['ppsc_action'] === 'preload_all' ) {
				$this->preload_all_cache();
			}
		}

		echo '<div class="wrap">';
		echo '<h1>amplifi.lockcache</h1>';
		echo '<p>Static HTML cache for password-protected posts. Log file: <code>' . esc_html( $this->log_file_path ) . '</code></p>';

		// Cache Manager
		echo '<h2>Cache Manager</h2>';

		// Clear all + Preload all buttons
		echo '<div style="margin-bottom:20px;">';
		echo '<form method="post" style="display:inline-block; margin-right:10px;">';
		wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
		echo '<input type="hidden" name="ppsc_action" value="clear_all" />';
		echo '<button type="submit" class="button button-secondary">Clear All Cache</button>';
		echo '</form>';

		echo '<form method="post" style="display:inline-block;">';
		wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
		echo '<input type="hidden" name="ppsc_action" value="preload_all" />';
		echo '<button type="submit" class="button button-secondary">Preload All</button>';
		echo '</form>';
		echo '</div>';

		// List all password-protected posts (ALL post types, all statuses)
		$all_post_types = get_post_types( array(), 'names' );
		$pw_posts = get_posts( array(
			'post_type'      => $all_post_types,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'has_password'   => true,
		) );

		if ( empty( $pw_posts ) ) {
			echo '<p>No password-protected posts found (across all post types).</p>';
		} else {
			echo '<table class="widefat"><thead><tr>';
			echo '<th>Post ID</th>';
			echo '<th>Title</th>';
			echo '<th>Post Type</th>';
			echo '<th>Status</th>';
			echo '<th>Cached?</th>';
			echo '<th>Cache File</th>';
			echo '<th>Created/Modified</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $pw_posts as $p ) {
				$cache_file    = $this->get_cache_file_path( $p->ID );
				$is_cached     = file_exists( $cache_file );
				$cache_status  = $is_cached ? 'Yes' : 'No';
				$cache_path    = $is_cached ? $cache_file : '';
				$modified_time = $is_cached ? date( 'Y-m-d H:i:s', filemtime( $cache_file ) ) : '';

				echo '<tr>';
				echo '<td>' . esc_html( $p->ID ) . '</td>';
				echo '<td>' . esc_html( get_the_title( $p ) ) . '</td>';
				echo '<td>' . esc_html( $p->post_type ) . '</td>';
				echo '<td>' . esc_html( $p->post_status ) . '</td>';
				echo '<td>' . esc_html( $cache_status ) . '</td>';
				echo '<td>' . esc_html( $cache_path ) . '</td>';
				echo '<td>' . esc_html( $modified_time ) . '</td>';
				echo '<td>';
				if ( $is_cached ) {
					echo '<form method="post" style="display:inline;">';
					wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
					echo '<input type="hidden" name="ppsc_action" value="clear_one" />';
					echo '<input type="hidden" name="ppsc_post_id" value="' . esc_attr( $p->ID ) . '" />';
					echo '<button type="submit" class="button button-secondary">Clear This Cache</button>';
					echo '</form>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Debug Logs (newest to oldest)
		echo '<h2>Debug Log (Newest First)</h2>';
		echo '<pre style="height:400px;overflow:auto;">';
		$logs = $this->read_log_file_newest_first();
		if ( empty( $logs ) ) {
			echo 'No log entries found.';
		} else {
			foreach ( $logs as $line ) {
				echo esc_html( $line ) . "\n";
			}
		}
		echo '</pre>';

		echo '</div>';
	}

	/**
	 * Attempt to preload all password-protected posts
	 */
	private function preload_all_cache() {
		$all_post_types = get_post_types( array(), 'names' );
		$all_posts = get_posts( array(
			'post_type'      => $all_post_types,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'has_password'   => true,
		) );

		foreach ( $all_posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! empty( $post->post_password ) ) {
				$url = get_permalink( $post_id );
				wp_remote_get( $url );
			}
		}

		$this->add_log( "Preload all triggered by admin." );
	}

	/**
	 * Read ppsc-debug.log from newest to oldest lines
	 */
	private function read_log_file_newest_first() {
		if ( ! file_exists( $this->log_file_path ) ) {
			return array();
		}
		$lines = file( $this->log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines ) {
			return array();
		}
		return array_reverse( $lines );
	}

	/**
	 * Clear all HTML caches
	 */
	private function clear_all_cache() {
		$files = glob( $this->cache_dir . 'cache-*.html' );
		if ( $files ) {
			foreach ( $files as $f ) {
				@unlink( $f );
			}
		}
		$this->add_log( "All cache cleared by admin." );
	}

	/**
	 * Clear cache for a specific post ID
	 */
	private function clear_single_cache( $post_id ) {
		$file_path = $this->get_cache_file_path( $post_id );
		if ( file_exists( $file_path ) ) {
			@unlink( $file_path );
			$this->add_log( "Cache file removed for post {$post_id}." );
		} else {
			$this->add_log( "Cache file not found for post {$post_id}." );
		}
	}

	/**
	 * Add a log entry to memory, error_log, and ppsc-debug.log (0600).
	 */
	private function add_log( $message ) {
		$this->debug_logs[] = $message;

		if ( ! file_exists( $this->log_file_path ) ) {
			$this->init_log_file();
		}
		$timestamp = date( 'Y-m-d H:i:s' );
		$entry     = "[{$timestamp}] {$message}\n";
		file_put_contents( $this->log_file_path, $entry, FILE_APPEND );
		@chmod( $this->log_file_path, 0600 );
	}
}

new Amplifi_Static_Cache();
