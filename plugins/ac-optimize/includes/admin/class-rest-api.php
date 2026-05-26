<?php
/**
 * REST API for the React admin UI.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all routes under /wp-json/amplifi-optimize/v1/.
 * All routes require `manage_options`.
 */
class Amplifi_Optimize_REST_API {

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Permission callback.
	 */
	public function permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Registers all REST routes.
	 */
	public function register_routes(): void {
		$ns = AMPLIFI_OPTIMIZE_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/scan/(?P<fix_type>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'fix_type' => array( 'type' => 'string' ),
					'limit'    => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/propose/(?P<fix_type>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'propose' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'fix_type' => array( 'type' => 'string' ),
					'limit'    => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/scan/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'progress' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_suggestions' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'type'     => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/(?P<id>\d+)/edit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'edit' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/(?P<id>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/(?P<id>\d+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/suggestions/batch-approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_approve' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$ns,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
	}

	/**
	 * POST /scan/{fix_type}
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function scan( WP_REST_Request $req ) {
		$type   = (string) $req->get_param( 'fix_type' );
		$bundle = $this->plugin->get_fix_type( $type );
		if ( ! $bundle ) {
			return new WP_Error( 'amplifi_optimize_invalid_type', __( 'Unknown fix type.', 'amplifi-optimize' ), array( 'status' => 400 ) );
		}
		$limit = $req->get_param( 'limit' );
		$args  = array();
		if ( null !== $limit ) {
			$args['limit'] = (int) $limit;
		}
		$result = $bundle['scanner']->scan( $args );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /propose/{fix_type}
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function propose( WP_REST_Request $req ) {
		$type   = (string) $req->get_param( 'fix_type' );
		$bundle = $this->plugin->get_fix_type( $type );
		if ( ! $bundle ) {
			return new WP_Error( 'amplifi_optimize_invalid_type', __( 'Unknown fix type.', 'amplifi-optimize' ), array( 'status' => 400 ) );
		}
		$limit = $req->get_param( 'limit' );
		$args  = array();
		if ( null !== $limit ) {
			$args['limit'] = (int) $limit;
		}
		$result = $bundle['proposer']->propose( $args );
		return rest_ensure_response( $result );
	}

	/**
	 * GET /scan/progress
	 */
	public function progress() {
		$p = get_transient( 'amplifi_optimize_scan_progress' );
		return rest_ensure_response( is_array( $p ) ? $p : null );
	}

	/**
	 * GET /suggestions
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function list_suggestions( WP_REST_Request $req ) {
		$result = $this->plugin->db->list(
			array(
				'type'     => (string) $req->get_param( 'type' ),
				'status'   => (string) ( $req->get_param( 'status' ) ?? 'pending' ),
				'page'     => (int) ( $req->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $req->get_param( 'per_page' ) ?: 20 ),
			)
		);
		$result['items'] = array_map( array( $this, 'enrich_suggestion' ), $result['items'] );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /suggestions/{id}/approve
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function approve( WP_REST_Request $req ) {
		$id          = (int) $req->get_param( 'id' );
		$suggestion  = $this->plugin->db->get( $id );
		if ( ! $suggestion ) {
			return new WP_Error( 'amplifi_optimize_not_found', __( 'Suggestion not found.', 'amplifi-optimize' ), array( 'status' => 404 ) );
		}
		$bundle = $this->plugin->get_fix_type( (string) $suggestion['fix_type'] );
		if ( ! $bundle ) {
			return new WP_Error( 'amplifi_optimize_invalid_type', __( 'Unknown fix type.', 'amplifi-optimize' ), array( 'status' => 400 ) );
		}
		$res = $bundle['applier']->apply( $suggestion );
		if ( empty( $res['ok'] ) ) {
			$this->plugin->db->update( $id, array(
				'status'        => 'failed',
				'error_message' => (string) ( $res['error'] ?? 'Unknown error.' ),
			) );
			return new WP_Error( 'amplifi_optimize_apply_failed', (string) ( $res['error'] ?? 'Apply failed.' ), array( 'status' => 500 ) );
		}
		$this->plugin->db->update( $id, array(
			'status'            => 'applied',
			'applied_at'        => current_time( 'mysql' ),
			'previous_snapshot' => isset( $res['snapshot'] ) ? (string) $res['snapshot'] : null,
			'error_message'     => null,
		) );
		return rest_ensure_response( $this->enrich_suggestion( $this->plugin->db->get( $id ) ) );
	}

	/**
	 * POST /suggestions/{id}/reject
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function reject( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$this->plugin->db->update( $id, array( 'status' => 'rejected' ) );
		return rest_ensure_response( $this->enrich_suggestion( $this->plugin->db->get( $id ) ) );
	}

	/**
	 * POST /suggestions/{id}/edit
	 *
	 * Body: { proposed_value, then_approve?:bool }
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function edit( WP_REST_Request $req ) {
		$id              = (int) $req->get_param( 'id' );
		$proposed_value  = (string) $req->get_param( 'proposed_value' );
		$then_approve    = (bool) $req->get_param( 'then_approve' );
		$this->plugin->db->update( $id, array(
			'proposed_value' => $proposed_value,
		) );
		if ( $then_approve ) {
			return $this->approve( $req );
		}
		return rest_ensure_response( $this->enrich_suggestion( $this->plugin->db->get( $id ) ) );
	}

	/**
	 * POST /suggestions/{id}/undo
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function undo( WP_REST_Request $req ) {
		$id         = (int) $req->get_param( 'id' );
		$suggestion = $this->plugin->db->get( $id );
		if ( ! $suggestion ) {
			return new WP_Error( 'amplifi_optimize_not_found', __( 'Suggestion not found.', 'amplifi-optimize' ), array( 'status' => 404 ) );
		}
		if ( 'applied' !== $suggestion['status'] ) {
			return new WP_Error( 'amplifi_optimize_not_applied', __( 'Only applied suggestions can be undone.', 'amplifi-optimize' ), array( 'status' => 400 ) );
		}
		$bundle = $this->plugin->get_fix_type( (string) $suggestion['fix_type'] );
		$res    = $bundle['applier']->undo( $suggestion );
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'amplifi_optimize_undo_failed', (string) ( $res['error'] ?? 'Undo failed.' ), array( 'status' => 500 ) );
		}
		$this->plugin->db->update( $id, array( 'status' => 'rejected' ) );
		return rest_ensure_response( $this->enrich_suggestion( $this->plugin->db->get( $id ) ) );
	}

	/**
	 * POST /suggestions/{id}/retry
	 *
	 * Re-queues a failed suggestion for a re-propose.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function retry( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$this->plugin->db->update( $id, array(
			'status'              => 'pending',
			'proposed_value'      => null,
			'claude_response_raw' => null,
			'error_message'       => null,
		) );
		return rest_ensure_response( $this->enrich_suggestion( $this->plugin->db->get( $id ) ) );
	}

	/**
	 * POST /suggestions/batch-approve
	 * Body: { ids: int[] }
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function batch_approve( WP_REST_Request $req ) {
		$ids = (array) $req->get_param( 'ids' );
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$sub_req = new WP_REST_Request( 'POST' );
			$sub_req->set_url_params( array( 'id' => $id ) );
			$res        = $this->approve( $sub_req );
			$out[ $id ] = is_wp_error( $res ) ? array( 'ok' => false, 'error' => $res->get_error_message() ) : array( 'ok' => true );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /stats
	 */
	public function stats() {
		$by_type   = array();
		$by_status = $this->plugin->db->counts_by_status();
		foreach ( $this->plugin->get_fix_types() as $slug => $bundle ) {
			$by_type[ $slug ] = $this->plugin->db->counts_by_status( $slug );
		}
		$usage = $this->plugin->claude->get_usage();
		return rest_ensure_response(
			array(
				'by_status' => $by_status,
				'by_type'   => $by_type,
				'pending'   => $this->plugin->db->counts_by_fix_type( 'pending' ),
				'usage'     => $usage,
			)
		);
	}

	/**
	 * GET /settings
	 */
	public function get_settings() {
		$settings = $this->plugin->get_settings();
		$settings['has_api_key'] = '' !== $this->plugin->claude->get_api_key();
		// Never return the key itself.
		return rest_ensure_response( $settings );
	}

	/**
	 * POST /settings
	 *
	 * Accepts: api_key (write-only), model, batch_size_meta, batch_size_alt,
	 * rate_limit_per_minute, included_post_types, min_image_dimension,
	 * include_svg, detector_override, delete_data_on_uninstall.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function update_settings( WP_REST_Request $req ) {
		$params = (array) $req->get_json_params();
		if ( isset( $params['api_key'] ) ) {
			$this->plugin->claude->set_api_key( (string) $params['api_key'] );
			unset( $params['api_key'] );
		}

		$allowed = array(
			'model'                    => 'sanitize_text_field',
			'batch_size_meta'          => 'intval',
			'batch_size_alt'           => 'intval',
			'rate_limit_per_minute'    => 'intval',
			'min_image_dimension'      => 'intval',
			'include_svg'              => 'rest_sanitize_boolean',
			'date_range_days'          => 'intval',
			'detector_override'        => 'sanitize_key',
			'delete_data_on_uninstall' => 'rest_sanitize_boolean',
			'undo_window'              => 'intval',
		);

		$update = array();
		foreach ( $allowed as $key => $san ) {
			if ( array_key_exists( $key, $params ) ) {
				$update[ $key ] = call_user_func( $san, $params[ $key ] );
			}
		}
		if ( isset( $params['included_post_types'] ) && is_array( $params['included_post_types'] ) ) {
			$update['included_post_types'] = array_values( array_filter( array_map( 'sanitize_key', $params['included_post_types'] ) ) );
		}
		if ( $update ) {
			$this->plugin->update_settings( $update );
			if ( isset( $update['delete_data_on_uninstall'] ) ) {
				update_option( 'amplifi_optimize_delete_data_on_uninstall', (bool) $update['delete_data_on_uninstall'] );
			}
		}
		return $this->get_settings();
	}

	/**
	 * Adds target-post denormalised fields to a suggestion row so the UI
	 * doesn't need a second round-trip.
	 *
	 * @param array<string,mixed>|null $row Row.
	 */
	private function enrich_suggestion( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		$id   = (int) $row['target_id'];
		$post = get_post( $id );
		if ( $post ) {
			$row['target'] = array(
				'id'        => $id,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post ),
				'edit_url'  => get_edit_post_link( $id, 'raw' ),
				'modified'  => $post->post_modified,
				'mime_type' => $post->post_mime_type,
			);
			if ( 'attachment' === $post->post_type ) {
				$row['target']['thumb'] = wp_get_attachment_image_url( $id, 'medium' );
				$row['target']['url']   = wp_get_attachment_url( $id );
			}
		} else {
			$row['target'] = array( 'id' => $id );
		}
		return $row;
	}
}
