<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACSYNC_API {

	const NAMESPACE = 'amplifi-sync/v1';

	/** Max file size for read/write operations (50 MB). */
	const MAX_FILE_SIZE = 52428800;

	/** Max base64 content length (~50 MB decoded = ~67 MB encoded). */
	const MAX_BASE64_SIZE = 70254592;

	/** Max upload size for db/restore (50 MB). */
	const MAX_RESTORE_SIZE = 52428800;

	/** Max files returned by manifest. */
	const MAX_MANIFEST_FILES = 10000;

	/** Skip md5 for files larger than this (10 MB). */
	const MD5_SIZE_THRESHOLD = 10485760;

	/** Max number of backup files to retain. */
	const MAX_BACKUPS = 10;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		// Status.
		register_rest_route( self::NAMESPACE, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Files.
		register_rest_route( self::NAMESPACE, '/files/manifest', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_files_manifest' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/files/read', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'read_file' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/files/write', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'write_file' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/files/delete', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_file' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Database.
		register_rest_route( self::NAMESPACE, '/db/tables', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_db_tables' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_table' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'import_table' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// NOTE: /db/query and /db/execute endpoints have been removed.
		// Raw SQL execution is too dangerous even behind an API key.
		// Use /db/export for structured reads and /db/import for structured writes.

		register_rest_route( self::NAMESPACE, '/db/backup', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'db_backup' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'db_restore' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Media.
		register_rest_route( self::NAMESPACE, '/media/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_media_list' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		register_rest_route( self::NAMESPACE, '/media/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'import_media' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Elementor.
		register_rest_route( self::NAMESPACE, '/elementor/regenerate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'elementor_regenerate' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );
	}

	/**
	 * Authenticate via X-AmpliSync-Key header.
	 */
	public function check_auth( WP_REST_Request $request ) {
		$key = $request->get_header( 'X-AmpliSync-Key' );
		if ( empty( $key ) ) {
			return new WP_Error( 'missing_key', 'X-AmpliSync-Key header required.', array( 'status' => 401 ) );
		}

		$settings  = get_option( 'acsync_settings', array() );
		$stored    = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		if ( ! hash_equals( $stored, $key ) ) {
			return new WP_Error( 'invalid_key', 'Invalid API key.', array( 'status' => 403 ) );
		}

		$this->log_request( $request );
		return true;
	}

	/**
	 * Log API request to wp_options.
	 *
	 * Uses update_option with autoload=false for lightweight persistence.
	 * There is a theoretical race condition if two requests arrive simultaneously,
	 * but this is acceptable for a non-critical connection log.
	 */
	private function log_request( WP_REST_Request $request ) {
		$log   = get_option( 'acsync_connection_log', array() );
		$log[] = array(
			'time'     => current_time( 'mysql' ),
			'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
			'endpoint' => $request->get_route(),
			'status'   => 'ok',
		);
		// Keep last 50 entries.
		$log = array_slice( $log, -50 );
		update_option( 'acsync_connection_log', $log, false );
	}

	// =========================================================================
	// Status
	// =========================================================================

	public function get_status() {
		global $wp_version;

		$active_plugins = get_option( 'active_plugins', array() );
		$theme          = wp_get_theme();
		$elementor      = in_array( 'elementor/elementor.php', $active_plugins, true );
		$uploads        = wp_upload_dir();

		return rest_ensure_response( array(
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'wp_version'     => $wp_version,
			'php_version'    => phpversion(),
			'active_theme'   => $theme->get( 'Name' ),
			'child_theme'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'active_plugins' => $active_plugins,
			'plugin_count'   => count( $active_plugins ),
			'elementor'      => $elementor,
			'uploads_dir'    => $uploads['basedir'],
			'uploads_url'    => $uploads['baseurl'],
			'multisite'      => is_multisite(),
			'db_prefix'      => $GLOBALS['wpdb']->prefix,
			'sync_version'   => ACSYNC_VERSION,
		) );
	}

	// =========================================================================
	// Files
	// =========================================================================

	public function get_files_manifest( WP_REST_Request $request ) {
		$dir  = $request->get_param( 'dir' ) ?: 'wp-content';
		$base = ABSPATH . $dir;

		if ( ! $this->is_safe_path( $base, 'dir' ) ) {
			return new WP_Error( 'invalid_path', 'Path outside wp-content.', array( 'status' => 403 ) );
		}

		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'not_found', 'Directory not found.', array( 'status' => 404 ) );
		}

		$files     = array();
		$truncated = false;
		$iter      = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$count = 0;
		foreach ( $iter as $item ) {
			if ( $count >= self::MAX_MANIFEST_FILES ) {
				$truncated = true;
				break;
			}

			// Skip symlinks.
			if ( $item->isLink() ) {
				continue;
			}

			$rel = str_replace( ABSPATH, '', $item->getPathname() );
			if ( $item->isFile() ) {
				$size = $item->getSize();
				$entry = array(
					'path'     => $rel,
					'size'     => $size,
					'modified' => gmdate( 'Y-m-d H:i:s', $item->getMTime() ),
					'type'     => 'file',
				);

				// Skip md5 for files > 10 MB — use size+mtime as a cheaper comparison signal.
				if ( $size <= self::MD5_SIZE_THRESHOLD ) {
					$entry['md5'] = md5_file( $item->getPathname() );
				}

				$files[] = $entry;
			} else {
				$files[] = array(
					'path' => $rel,
					'type' => 'dir',
				);
			}
			$count++;
		}

		return rest_ensure_response( array(
			'base'      => $dir,
			'count'     => count( $files ),
			'truncated' => $truncated,
			'files'     => $files,
		) );
	}

	public function read_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( empty( $path ) ) {
			return new WP_Error( 'missing_path', 'path parameter required.', array( 'status' => 400 ) );
		}

		$full = ABSPATH . $path;
		if ( ! $this->is_safe_path( $full, 'read' ) ) {
			return new WP_Error( 'invalid_path', 'Path outside wp-content.', array( 'status' => 403 ) );
		}

		if ( ! is_file( $full ) ) {
			return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
		}

		// Check file size before reading.
		$size = filesize( $full );
		if ( $size > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', 'File exceeds 50 MB limit. Use SFTP for large files.', array(
				'status'    => 413,
				'file_size' => $size,
			) );
		}

		$contents = file_get_contents( $full );
		return rest_ensure_response( array(
			'path'    => $path,
			'size'    => strlen( $contents ),
			'content' => base64_encode( $contents ),
			'md5'     => md5( $contents ),
		) );
	}

	public function write_file( WP_REST_Request $request ) {
		$path    = $request->get_param( 'path' );
		$content = $request->get_param( 'content' );
		$mode    = $request->get_param( 'mode' );

		if ( empty( $path ) || $content === null ) {
			return new WP_Error( 'missing_params', 'path and content required.', array( 'status' => 400 ) );
		}

		// Validate mode parameter if provided.
		if ( $mode !== null ) {
			if ( ! is_numeric( $mode ) ) {
				return new WP_Error( 'invalid_mode', 'mode must be a numeric file permission (e.g. 0644).', array( 'status' => 400 ) );
			}
			$mode = intval( $mode, 8 );
			if ( $mode < 0 || $mode > 0777 ) {
				return new WP_Error( 'invalid_mode', 'mode must be between 0000 and 0777.', array( 'status' => 400 ) );
			}
		}

		// Check base64 content size before decoding (~67 MB encoded = ~50 MB decoded).
		if ( strlen( $content ) > self::MAX_BASE64_SIZE ) {
			return new WP_Error( 'content_too_large', 'Content exceeds 50 MB limit. Use SFTP for large files.', array(
				'status'       => 413,
				'encoded_size' => strlen( $content ),
			) );
		}

		$full = ABSPATH . $path;

		// For writes, validate the parent directory path.
		if ( ! $this->is_safe_path( $full, 'write' ) ) {
			return new WP_Error( 'invalid_path', 'Path outside wp-content.', array( 'status' => 403 ) );
		}

		$dir = dirname( $full );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$decoded = base64_decode( $content, true );
		if ( $decoded === false ) {
			return new WP_Error( 'invalid_content', 'Content must be base64 encoded.', array( 'status' => 400 ) );
		}

		$written = file_put_contents( $full, $decoded );
		if ( $written === false ) {
			return new WP_Error( 'write_failed', 'Could not write file.', array( 'status' => 500 ) );
		}

		// Apply file permissions if mode was specified.
		if ( $mode !== null ) {
			chmod( $full, $mode );
		}

		return rest_ensure_response( array(
			'path'    => $path,
			'size'    => $written,
			'md5'     => md5( $decoded ),
			'success' => true,
		) );
	}

	public function delete_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( empty( $path ) ) {
			return new WP_Error( 'missing_path', 'path parameter required.', array( 'status' => 400 ) );
		}

		$full = ABSPATH . $path;
		if ( ! $this->is_safe_path( $full, 'read' ) ) {
			return new WP_Error( 'invalid_path', 'Path outside wp-content.', array( 'status' => 403 ) );
		}

		if ( ! file_exists( $full ) ) {
			return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
		}

		$deleted = unlink( $full );
		return rest_ensure_response( array(
			'path'    => $path,
			'deleted' => $deleted,
		) );
	}

	/**
	 * Validate that a path is safe — within WP_CONTENT_DIR, not a symlink, no traversal.
	 *
	 * @param string $path The absolute path to validate.
	 * @param string $context One of 'read', 'write', or 'dir'.
	 *                        - 'read'/'dir': resolves the full path with realpath; rejects if it does not exist.
	 *                        - 'write': resolves only the parent directory (since the file may not exist yet).
	 * @return bool True if path is safe.
	 */
	private function is_safe_path( $path, $context = 'read' ) {
		$content_dir = realpath( WP_CONTENT_DIR );
		if ( $content_dir === false ) {
			return false;
		}

		// Block symlinks on the target itself (if it exists).
		if ( is_link( $path ) ) {
			return false;
		}

		if ( $context === 'write' ) {
			// For writes, the file may not exist yet. Resolve the parent directory.
			$parent = dirname( $path );

			// The parent dir itself could be a symlink.
			if ( is_link( $parent ) ) {
				return false;
			}

			// If parent doesn't exist yet, walk up to find the first real ancestor.
			while ( ! is_dir( $parent ) ) {
				$parent = dirname( $parent );
				if ( $parent === '/' || $parent === '.' ) {
					return false;
				}
			}

			$real_parent = realpath( $parent );
			if ( $real_parent === false ) {
				return false;
			}

			// The remaining path segments (below the resolved parent) must not contain traversal.
			$remaining = substr( $path, strlen( $parent ) );
			if ( strpos( $remaining, '..' ) !== false ) {
				return false;
			}

			return strpos( $real_parent, $content_dir ) === 0;
		}

		// For reads and dir lookups, resolve the full path.
		$real = realpath( $path );
		if ( $real === false ) {
			// Path doesn't exist or contains traversal.
			return false;
		}

		return strpos( $real, $content_dir ) === 0;
	}

	// =========================================================================
	// Database
	// =========================================================================

	public function get_db_tables() {
		global $wpdb;

		$tables  = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$result  = array();
		foreach ( $tables as $t ) {
			$result[] = array(
				'name'      => $t['Name'],
				'rows'      => (int) $t['Rows'],
				'size'      => (int) $t['Data_length'] + (int) $t['Index_length'],
				'engine'    => $t['Engine'],
				'collation' => $t['Collation'],
			);
		}

		// Generate unique confirmation tokens per operation type.
		$tokens = array();
		foreach ( array( 'import', 'restore' ) as $op ) {
			$token_id  = wp_generate_password( 16, false );
			$token_val = wp_generate_password( 32, false );
			set_transient( 'acsync_db_token_' . $token_id, array(
				'token'     => $token_val,
				'operation' => $op,
			), 300 );
			$tokens[ $op ] = array(
				'token_id' => $token_id,
				'token'    => $token_val,
			);
		}

		return rest_ensure_response( array(
			'prefix'              => $wpdb->prefix,
			'tables'              => $result,
			'confirmation_tokens' => $tokens,
		) );
	}

	/**
	 * Validate and consume a confirmation token for a specific operation.
	 *
	 * @param string $token_id The token ID from the client.
	 * @param string $token    The token value from the client.
	 * @param string $operation Expected operation type (e.g. 'import', 'restore').
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_confirmation_token( $token_id, $token, $operation ) {
		if ( empty( $token_id ) || empty( $token ) ) {
			return new WP_Error( 'missing_token', 'confirmation_token and token_id required. Call /db/tables first.', array( 'status' => 403 ) );
		}

		$stored = get_transient( 'acsync_db_token_' . $token_id );
		if ( ! $stored ) {
			return new WP_Error( 'invalid_token', 'Token expired or not found. Call /db/tables first.', array( 'status' => 403 ) );
		}

		if ( ! hash_equals( $stored['token'], $token ) ) {
			return new WP_Error( 'invalid_token', 'Invalid confirmation token.', array( 'status' => 403 ) );
		}

		if ( $stored['operation'] !== $operation ) {
			return new WP_Error( 'wrong_token_type', 'Token is for "' . $stored['operation'] . '" not "' . $operation . '".', array( 'status' => 403 ) );
		}

		// Consume the token immediately — single use only.
		delete_transient( 'acsync_db_token_' . $token_id );

		return true;
	}

	public function export_table( WP_REST_Request $request ) {
		global $wpdb;

		$table = $request->get_param( 'table' );
		$page  = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit = 1000;
		$offset = ( $page - 1 ) * $limit;

		if ( empty( $table ) ) {
			return new WP_Error( 'missing_table', 'table parameter required.', array( 'status' => 400 ) );
		}

		// Validate table name exists.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! in_array( $table, $tables, true ) ) {
			return new WP_Error( 'invalid_table', 'Table not found.', array( 'status' => 404 ) );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `%1s`', $table ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `%1s` LIMIT %d OFFSET %d", $table, $limit, $offset ), ARRAY_A );

		return rest_ensure_response( array(
			'table'      => $table,
			'page'       => $page,
			'per_page'   => $limit,
			'total_rows' => $total,
			'total_pages' => ceil( $total / $limit ),
			'rows'       => $rows,
		) );
	}

	public function import_table( WP_REST_Request $request ) {
		global $wpdb;

		$table    = $request->get_param( 'table' );
		$rows     = $request->get_param( 'rows' );
		$mode     = $request->get_param( 'mode' ) ?: 'truncate';
		$token    = $request->get_param( 'confirmation_token' );
		$token_id = $request->get_param( 'token_id' );

		if ( empty( $table ) || empty( $rows ) ) {
			return new WP_Error( 'missing_params', 'table and rows required.', array( 'status' => 400 ) );
		}

		// Validate mode — only 'truncate' and 'append' are allowed.
		$allowed_modes = array( 'truncate', 'append' );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			return new WP_Error( 'invalid_mode', 'mode must be "truncate" or "append".', array( 'status' => 400 ) );
		}

		$token_check = $this->validate_confirmation_token( $token_id, $token, 'import' );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! in_array( $table, $tables, true ) ) {
			return new WP_Error( 'invalid_table', 'Table not found.', array( 'status' => 404 ) );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( $mode === 'truncate' ) {
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE `%1s`', $table ) );
			}

			$inserted = 0;
			foreach ( $rows as $row ) {
				$wpdb->insert( $table, $row );
				if ( $wpdb->last_error ) {
					throw new Exception( $wpdb->last_error );
				}
				$inserted++;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'import_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'table'    => $table,
			'mode'     => $mode,
			'inserted' => $inserted,
			'success'  => true,
		) );
	}

	public function db_backup() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/acsync-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" );
			file_put_contents( $backup_dir . '/index.php', "<?php // Silence is golden.\n" );
		} else {
			// Ensure security files exist even if directory was already created.
			if ( ! file_exists( $backup_dir . '/.htaccess' ) ) {
				file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" );
			}
			if ( ! file_exists( $backup_dir . '/index.php' ) ) {
				file_put_contents( $backup_dir . '/index.php', "<?php // Silence is golden.\n" );
			}
		}

		// Rotate old backups — keep at most MAX_BACKUPS. Delete oldest first.
		$existing = glob( $backup_dir . '/backup-*.sql' );
		if ( is_array( $existing ) && count( $existing ) >= self::MAX_BACKUPS ) {
			// Sort by modification time ascending (oldest first).
			usort( $existing, function ( $a, $b ) {
				return filemtime( $a ) - filemtime( $b );
			} );
			$to_delete = count( $existing ) - self::MAX_BACKUPS + 1; // +1 to make room for the new one.
			for ( $i = 0; $i < $to_delete; $i++ ) {
				@unlink( $existing[ $i ] );
			}
		}

		// Add microseconds to filename to prevent collision.
		$micro    = sprintf( '%06d', (int) ( microtime( true ) * 1000000 ) % 1000000 );
		$filename = 'backup-' . gmdate( 'Y-m-d-His' ) . '-' . $micro . '.sql';
		$filepath = $backup_dir . '/' . $filename;
		$handle   = fopen( $filepath, 'w' );
		if ( ! $handle ) {
			return new WP_Error( 'write_failed', 'Could not create backup file.', array( 'status' => 500 ) );
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			// Table structure.
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create['Create Table'] . ";\n\n" );

			// Table data — paginated to avoid memory limits on large tables.
			$page_size = 500;
			$offset    = 0;
			while ( true ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `%1s` LIMIT %d OFFSET %d", $table, $page_size, $offset ),
					ARRAY_A
				);
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$values = array_map( function ( $v ) use ( $wpdb ) {
						return $v === null ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
					}, $row );
					fwrite( $handle, "INSERT INTO `{$table}` VALUES(" . implode( ',', $values ) . ");\n" );
				}
				$offset += $page_size;
				if ( count( $rows ) < $page_size ) {
					break;
				}
			}
			fwrite( $handle, "\n" );
		}

		fclose( $handle );
		$size = filesize( $filepath );

		return rest_ensure_response( array(
			'filename' => $filename,
			'size'     => $size,
			'tables'   => count( $tables ),
			'path'     => str_replace( ABSPATH, '', $filepath ),
			'success'  => true,
		) );
	}

	public function db_restore( WP_REST_Request $request ) {
		global $wpdb;

		$token    = $request->get_param( 'confirmation_token' );
		$token_id = $request->get_param( 'token_id' );

		// Validate confirmation token before doing anything.
		$token_check = $this->validate_confirmation_token( $token_id, $token, 'restore' );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		$sql = $request->get_param( 'sql' );
		if ( empty( $sql ) ) {
			// Check for file upload.
			$files = $request->get_file_params();
			if ( ! empty( $files['file']['tmp_name'] ) ) {
				// Check upload size.
				$upload_size = filesize( $files['file']['tmp_name'] );
				if ( $upload_size > self::MAX_RESTORE_SIZE ) {
					return new WP_Error( 'file_too_large', 'Restore file exceeds 50 MB limit.', array(
						'status'    => 413,
						'file_size' => $upload_size,
					) );
				}
				$sql = file_get_contents( $files['file']['tmp_name'] );
			}
		} else {
			// Check inline SQL size.
			if ( strlen( $sql ) > self::MAX_RESTORE_SIZE ) {
				return new WP_Error( 'payload_too_large', 'SQL payload exceeds 50 MB limit.', array( 'status' => 413 ) );
			}
		}

		if ( empty( $sql ) ) {
			return new WP_Error( 'missing_sql', 'sql parameter or file upload required.', array( 'status' => 400 ) );
		}

		// Whitelist: only allow DROP TABLE IF EXISTS, CREATE TABLE, and INSERT INTO statements.
		$statements = array_filter( array_map( 'trim', explode( ";\n", $sql ) ) );

		foreach ( $statements as $stmt ) {
			if ( empty( $stmt ) ) {
				continue;
			}
			$normalized = strtoupper( trim( preg_replace( '/\s+/', ' ', $stmt ) ) );
			$allowed = false;
			if ( strpos( $normalized, 'DROP TABLE IF EXISTS' ) === 0 ) {
				$allowed = true;
			} elseif ( strpos( $normalized, 'CREATE TABLE' ) === 0 ) {
				$allowed = true;
			} elseif ( strpos( $normalized, 'INSERT INTO' ) === 0 ) {
				$allowed = true;
			}
			if ( ! $allowed ) {
				return new WP_Error( 'blocked_statement', 'Only DROP TABLE IF EXISTS, CREATE TABLE, and INSERT INTO statements are allowed in restore.', array(
					'status'    => 403,
					'statement' => mb_substr( $stmt, 0, 200 ),
				) );
			}
		}

		// Wrap the entire restore in a transaction.
		// Note: DDL statements (CREATE TABLE, DROP TABLE) cause implicit commits in MySQL,
		// so the transaction primarily protects the INSERT sequence. If a failure occurs
		// mid-restore, the database may be in a partial state for DDL, but INSERT data
		// will be rolled back cleanly.
		$wpdb->query( 'START TRANSACTION' );
		$executed = 0;

		try {
			foreach ( $statements as $stmt ) {
				if ( empty( $stmt ) ) {
					continue;
				}
				$result = $wpdb->query( $stmt );
				if ( $wpdb->last_error ) {
					throw new Exception( 'Statement ' . ( $executed + 1 ) . ' failed: ' . $wpdb->last_error );
				}
				$executed++;
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'restore_failed', $e->getMessage(), array(
				'status'           => 500,
				'executed_before_error' => $executed,
			) );
		}

		return rest_ensure_response( array(
			'executed'    => $executed,
			'errors'      => array(),
			'error_count' => 0,
			'success'     => true,
		) );
	}

	// =========================================================================
	// Media
	// =========================================================================

	public function get_media_list( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );

		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$meta = wp_get_attachment_metadata( $post->ID );
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'url'      => wp_get_attachment_url( $post->ID ),
				'path'     => get_attached_file( $post->ID ),
				'mime'     => $post->post_mime_type,
				'date'     => $post->post_date,
				'filesize' => $meta && isset( $meta['filesize'] ) ? $meta['filesize'] : ( file_exists( get_attached_file( $post->ID ) ) ? filesize( get_attached_file( $post->ID ) ) : 0 ),
				'width'    => $meta && isset( $meta['width'] ) ? $meta['width'] : null,
				'height'   => $meta && isset( $meta['height'] ) ? $meta['height'] : null,
				'sizes'    => $meta && isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
			);
		}

		return rest_ensure_response( array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		) );
	}

	public function import_media( WP_REST_Request $request ) {
		$url   = $request->get_param( 'url' );
		$title = $request->get_param( 'title' );

		if ( empty( $url ) ) {
			return new WP_Error( 'missing_url', 'url parameter required.', array( 'status' => 400 ) );
		}

		// SSRF protection: validate URL scheme.
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_scheme', 'URL must use http or https scheme.', array( 'status' => 400 ) );
		}

		// SSRF protection: block private/internal IP ranges.
		if ( ! isset( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_url', 'URL must include a host.', array( 'status' => 400 ) );
		}

		$host = $parsed['host'];
		$ip   = gethostbyname( $host );
		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// DNS resolution failed (gethostbyname returns the hostname on failure).
			return new WP_Error( 'dns_failed', 'Could not resolve host.', array( 'status' => 400 ) );
		}

		if ( $this->is_private_ip( $ip ) ) {
			return new WP_Error( 'blocked_ip', 'URLs pointing to private/internal IP addresses are not allowed.', array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		$file     = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file, 0, $title ?: $filename );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		return rest_ensure_response( array(
			'id'      => $id,
			'url'     => wp_get_attachment_url( $id ),
			'path'    => get_attached_file( $id ),
			'success' => true,
		) );
	}

	/**
	 * Check if an IP address is in a private/internal range.
	 *
	 * Blocks: 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, ::1, fc00::/7
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if the IP is private/internal.
	 */
	private function is_private_ip( $ip ) {
		// filter_var with FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE
		// returns false for private and reserved IPs.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		// Additionally block link-local 169.254.0.0/16 (covered by FILTER_FLAG_NO_RES_RANGE
		// but being explicit for clarity).
		$long = ip2long( $ip );
		if ( $long !== false ) {
			// 169.254.0.0/16
			if ( ( $long & 0xFFFF0000 ) === 0xA9FE0000 ) {
				return true;
			}
		}

		return false;
	}

	// =========================================================================
	// Elementor
	// =========================================================================

	public function elementor_regenerate() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'no_elementor', 'Elementor is not active.', array( 'status' => 404 ) );
		}

		// Clear Elementor CSS cache.
		\Elementor\Plugin::$instance->files_manager->clear_cache();

		return rest_ensure_response( array(
			'regenerated' => true,
			'success'     => true,
		) );
	}
}
