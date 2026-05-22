<?php
declare(strict_types=1);
namespace Amplifi\Schema\Rest;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest_Controller {
	public const NS = 'amplifi-schema/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$perm = static fn() => current_user_can( 'manage_options' );

		register_rest_route( self::NS, '/entries', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list_entries' ],  'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_entry' ],  'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/entries/validate', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'validate_entry' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/entries/generate', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'generate_entry' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/entries/(?P<id>\d+)', [
			[ 'methods' => 'GET',    'callback' => [ $this, 'get_entry' ],    'permission_callback' => $perm ],
			[ 'methods' => 'PUT',    'callback' => [ $this, 'update_entry' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_entry' ], 'permission_callback' => $perm ],
		] );

		register_rest_route( self::NS, '/detect', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'detect' ], 'permission_callback' => $perm ],
		] );

		register_rest_route( self::NS, '/global/(?P<key>[a-z_-]+)', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_global' ], 'permission_callback' => $perm ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'put_global' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/global/(?P<key>[a-z_-]+)/ai-prefill', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'prefill_global' ], 'permission_callback' => $perm ],
		] );

		register_rest_route( self::NS, '/rules', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list_rules' ],  'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_rule' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/rules/test', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'test_rule' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/rules/(?P<id>[a-z0-9_-]+)', [
			[ 'methods' => 'PUT',    'callback' => [ $this, 'update_rule' ], 'permission_callback' => $perm ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_rule' ], 'permission_callback' => $perm ],
		] );

		register_rest_route( self::NS, '/jobs', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list_jobs' ],  'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_job' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/jobs/preview-cost', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'preview_cost' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_job' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel)', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'control_job' ], 'permission_callback' => $perm ],
		] );

		register_rest_route( self::NS, '/spend', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'spend' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/migrate-from-meta', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'migrate_from_meta' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( self::NS, '/settings', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_settings' ], 'permission_callback' => $perm ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'put_settings' ], 'permission_callback' => $perm ],
		] );
	}

	// ---- Stub handlers (filled in by Tasks 7.2-7.4 and 9.x) ----

	private function ok( $data = [] ): WP_REST_Response {
		return new WP_REST_Response( $data );
	}

	public function list_entries( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function create_entry( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function validate_entry( WP_REST_Request $req ): WP_REST_Response { return $this->ok(); }
	public function generate_entry( WP_REST_Request $req ): WP_REST_Response { return $this->ok(); }
	public function get_entry( WP_REST_Request $req ): WP_REST_Response      { return $this->ok(); }
	public function update_entry( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function delete_entry( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function detect( WP_REST_Request $req ): WP_REST_Response         { return $this->ok(); }
	public function get_global( WP_REST_Request $req ): WP_REST_Response     { return $this->ok(); }
	public function put_global( WP_REST_Request $req ): WP_REST_Response     { return $this->ok(); }
	public function prefill_global( WP_REST_Request $req ): WP_REST_Response { return $this->ok(); }
	public function list_rules( WP_REST_Request $req ): WP_REST_Response     { return $this->ok(); }
	public function create_rule( WP_REST_Request $req ): WP_REST_Response    { return $this->ok(); }
	public function update_rule( WP_REST_Request $req ): WP_REST_Response    { return $this->ok(); }
	public function delete_rule( WP_REST_Request $req ): WP_REST_Response    { return $this->ok(); }
	public function test_rule( WP_REST_Request $req ): WP_REST_Response      { return $this->ok(); }
	public function list_jobs( WP_REST_Request $req ): WP_REST_Response      { return $this->ok(); }
	public function create_job( WP_REST_Request $req ): WP_REST_Response     { return $this->ok(); }
	public function preview_cost( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function get_job( WP_REST_Request $req ): WP_REST_Response        { return $this->ok(); }
	public function control_job( WP_REST_Request $req ): WP_REST_Response    { return $this->ok(); }
	public function spend( WP_REST_Request $req ): WP_REST_Response          { return $this->ok(); }
	public function migrate_from_meta( WP_REST_Request $req ): WP_REST_Response { return $this->ok(); }
	public function get_settings( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
	public function put_settings( WP_REST_Request $req ): WP_REST_Response   { return $this->ok(); }
}
