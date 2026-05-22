<?php
declare(strict_types=1);
namespace Amplifi\Schema\Rest;

use Amplifi\Schema\AI\Anthropic_Client;
use Amplifi\Schema\AI\Prompt_Builder;
use Amplifi\Schema\AI\Spend_Tracker;
use Amplifi\Schema\Crypto\Secret_Store;
use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Queue\Job_Store;
use Amplifi\Schema\Schema\Detector;
use Amplifi\Schema\Schema\Registry;
use Amplifi\Schema\Schema\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rest_Controller {
	public const NS = 'amplifi-schema/v1';

	/** Valid keys for global schema entities. */
	private const GLOBAL_KEYS = [ 'organization', 'website', 'localbusiness' ];

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

		register_rest_route( self::NS, '/post-overrides/(?P<id>\d+)', [
			[ 'methods' => 'PUT', 'callback' => [ $this, 'put_post_overrides' ], 'permission_callback' => $perm ],
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function ok( $data = [] ): WP_REST_Response {
		return new WP_REST_Response( $data );
	}

	/**
	 * Retrieve the stored Anthropic API key or return a WP_Error.
	 *
	 * @return string|\WP_Error
	 */
	private function api_key_or_error(): string|\WP_Error {
		$key = Secret_Store::get( 'anthropic_api_key' );
		if ( ! is_string( $key ) || $key === '' ) {
			return new \WP_Error( 'no_api_key', 'No Anthropic API key configured.', [ 'status' => 400 ] );
		}
		return $key;
	}

	/**
	 * Build a Validator instance backed by the bundled type index.
	 */
	private function make_validator(): Validator {
		return new Validator( new Registry() );
	}

	/**
	 * Count posts that match a bulk-job scope definition.
	 *
	 * @param array $scope  Shape: { post_types: string[], include_with_schema?: bool }
	 */
	private function count_for_scope( array $scope ): int {
		$post_types = (array) ( $scope['post_types'] ?? [ 'post', 'page' ] );
		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		$ids = get_posts( $args );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Test whether a URL matches a single rule (glob or regex).
	 */
	private function url_matches_rule( string $pattern, string $match_type, string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $match_type === 'regex' ) {
			return @preg_match( $pattern, $path ) === 1;
		}
		// Default: glob
		return fnmatch( $pattern, $path );
	}

	// -------------------------------------------------------------------------
	// Entry handlers
	// -------------------------------------------------------------------------

	public function list_entries( WP_REST_Request $req ): WP_REST_Response {
		$scope_type = $req->get_param( 'scope_type' );
		$scope_id   = $req->get_param( 'scope_id' );

		if ( $scope_type !== null && $scope_id !== null ) {
			$store = new Entry_Store();
			$rows  = $store->find_all_for_scope( (string) $scope_type, (string) $scope_id );
			return $this->ok( $rows );
		}

		return $this->ok( [] );
	}

	public function create_entry( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$body       = (array) $req->get_json_params();
		$scope_type = (string) ( $body['scope_type'] ?? '' );
		$scope_id   = (string) ( $body['scope_id']   ?? '' );
		$schema_type = (string) ( $body['schema_type'] ?? '' );
		$source     = (string) ( $body['source']     ?? 'manual' );
		$json_ld    = (string) ( $body['json_ld']    ?? '' );

		// Validate the JSON-LD before saving.
		$result = $this->make_validator()->validate( $json_ld );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'invalid_schema',
				'JSON-LD validation failed.',
				[ 'status' => 400, 'errors' => $result['errors'] ]
			);
		}

		$store = new Entry_Store();
		$store->save( compact( 'scope_type', 'scope_id', 'schema_type', 'source', 'json_ld' ) );

		$row = $store->find_one( $scope_type, $scope_id, $schema_type );
		return $this->ok( $row );
	}

	public function get_entry( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id  = (int) $req->get_param( 'id' );
		$row = ( new Entry_Store() )->find_by_id( $id );
		if ( $row === null ) {
			return new \WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
		}
		return $this->ok( $row );
	}

	public function update_entry( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id    = (int) $req->get_param( 'id' );
		$store = new Entry_Store();
		$row   = $store->find_by_id( $id );
		if ( $row === null ) {
			return new \WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
		}

		$body = (array) $req->get_json_params();

		// Apply updatable fields from request body.
		if ( isset( $body['json_ld'] ) ) {
			$row['json_ld'] = (string) $body['json_ld'];
		}
		if ( isset( $body['source'] ) ) {
			$row['source'] = (string) $body['source'];
		}

		// Validate before saving.
		$result = $this->make_validator()->validate( (string) $row['json_ld'] );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'invalid_schema',
				'JSON-LD validation failed.',
				[ 'status' => 400, 'errors' => $result['errors'] ]
			);
		}

		$store->save( $row );

		$updated = $store->find_by_id( $id );
		return $this->ok( $updated );
	}

	public function delete_entry( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		( new Entry_Store() )->delete( $id );
		return $this->ok( [ 'deleted' => true ] );
	}

	public function validate_entry( WP_REST_Request $req ): WP_REST_Response {
		$body    = (array) $req->get_json_params();
		$json_ld = (string) ( $body['json_ld'] ?? '' );
		$result  = $this->make_validator()->validate( $json_ld );
		return $this->ok( $result );
	}

	public function generate_entry( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$key = $this->api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$body     = (array) $req->get_json_params();
		$settings = get_option( 'ac_schema_settings', [] );
		$model    = (string) ( $body['model'] ?? $settings['default_model'] ?? 'claude-sonnet-4-6' );

		// Build context — either from post_id or from raw fields.
		if ( isset( $body['post_id'] ) ) {
			$post_id = (int) $body['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
			}
			$ctx = [
				'title'     => get_the_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'post_type' => $post->post_type,
				'content'   => wp_strip_all_tags( (string) apply_filters( 'the_content', $post->post_content ) ),
				'existing'  => null,
			];
		} else {
			$ctx = [
				'title'     => (string) ( $body['title']     ?? '' ),
				'url'       => (string) ( $body['url']       ?? '' ),
				'post_type' => (string) ( $body['post_type'] ?? 'page' ),
				'content'   => (string) ( $body['content']   ?? '' ),
				'existing'  => null,
			];
		}

		if ( ! Spend_Tracker::can_spend( 0.05 ) ) {
			return new \WP_Error(
				'spend_cap_reached',
				'Spend cap reached. Adjust limits in amplifi.schema settings.',
				[ 'status' => 429 ]
			);
		}

		$prompt = Prompt_Builder::build_for_post( $ctx );
		$client = new Anthropic_Client( $key, $model );
		$r      = $client->generate_jsonld( $prompt['system'], $prompt['user'] );

		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'ai_error', $r['error'], [ 'status' => 502 ] );
		}

		Spend_Tracker::record( $model, $r['input_tokens'], $r['output_tokens'] );

		$json_ld   = (string) wp_json_encode( $r['jsonld'] );
		$validated = $this->make_validator()->validate( $json_ld );
		$cost_usd  = Spend_Tracker::estimate_cost( $model, $r['input_tokens'], $r['output_tokens'] );

		return $this->ok( [
			'jsonld'        => $r['jsonld'],
			'errors'        => $validated['errors'],
			'cost_usd'      => $cost_usd,
			'input_tokens'  => $r['input_tokens'],
			'output_tokens' => $r['output_tokens'],
		] );
	}

	// -------------------------------------------------------------------------
	// Detect handler
	// -------------------------------------------------------------------------

	public function detect( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$url = (string) $req->get_param( 'url' );
		if ( $url === '' ) {
			return new \WP_Error( 'missing_url', 'url parameter is required.', [ 'status' => 400 ] );
		}

		// Validate URL is on the same host as the site.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$req_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( $site_host !== $req_host ) {
			return new \WP_Error(
				'foreign_url',
				'URL must be on the same host as this site.',
				[ 'status' => 400 ]
			);
		}

		$found = ( new Detector() )->detect_for_url( $url );
		return $this->ok( $found );
	}

	// -------------------------------------------------------------------------
	// Global schema handlers
	// -------------------------------------------------------------------------

	public function get_global( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$key = (string) $req->get_param( 'key' );
		if ( ! in_array( $key, self::GLOBAL_KEYS, true ) ) {
			return new \WP_Error(
				'invalid_key',
				'Key must be one of: ' . implode( ', ', self::GLOBAL_KEYS ),
				[ 'status' => 400 ]
			);
		}
		$data = get_option( 'ac_schema_global_' . $key, [] );
		return $this->ok( $data );
	}

	public function put_global( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$key = (string) $req->get_param( 'key' );
		if ( ! in_array( $key, self::GLOBAL_KEYS, true ) ) {
			return new \WP_Error(
				'invalid_key',
				'Key must be one of: ' . implode( ', ', self::GLOBAL_KEYS ),
				[ 'status' => 400 ]
			);
		}

		$body    = $req->get_json_params();
		$json_ld = (string) wp_json_encode( $body );

		$result = $this->make_validator()->validate( $json_ld );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'invalid_schema',
				'JSON-LD validation failed.',
				[ 'status' => 400, 'errors' => $result['errors'] ]
			);
		}

		update_option( 'ac_schema_global_' . $key, $body );

		// Mirror into the entries table for unified querying.
		$store = new Entry_Store();
		$store->save( [
			'scope_type'  => 'global',
			'scope_id'    => $key,
			'schema_type' => (string) ( $body['@type'] ?? 'Thing' ),
			'source'      => 'manual',
			'json_ld'     => $json_ld,
		] );

		return $this->ok( $body );
	}

	public function prefill_global( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$key = (string) $req->get_param( 'key' );
		if ( ! in_array( $key, self::GLOBAL_KEYS, true ) ) {
			return new \WP_Error(
				'invalid_key',
				'Key must be one of: ' . implode( ', ', self::GLOBAL_KEYS ),
				[ 'status' => 400 ]
			);
		}

		$api_key = $this->api_key_or_error();
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$body     = (array) $req->get_json_params();
		$settings = get_option( 'ac_schema_settings', [] );
		$model    = (string) ( $body['model'] ?? $settings['default_model'] ?? 'claude-sonnet-4-6' );

		$site_ctx = [
			'name'        => get_bloginfo( 'name' ),
			'tagline'     => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_email' => get_bloginfo( 'admin_email' ),
			'icon'        => get_site_icon_url(),
		];

		if ( ! Spend_Tracker::can_spend( 0.05 ) ) {
			return new \WP_Error(
				'spend_cap_reached',
				'Spend cap reached. Adjust limits in amplifi.schema settings.',
				[ 'status' => 429 ]
			);
		}

		$prompt = Prompt_Builder::build_for_global( $key, $site_ctx );
		$client = new Anthropic_Client( $api_key, $model );
		$r      = $client->generate_jsonld( $prompt['system'], $prompt['user'] );

		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'ai_error', $r['error'], [ 'status' => 502 ] );
		}

		Spend_Tracker::record( $model, $r['input_tokens'], $r['output_tokens'] );

		$json_ld   = (string) wp_json_encode( $r['jsonld'] );
		$validated = $this->make_validator()->validate( $json_ld );
		$cost_usd  = Spend_Tracker::estimate_cost( $model, $r['input_tokens'], $r['output_tokens'] );

		// Return for user review — do NOT save automatically.
		return $this->ok( [
			'jsonld'   => $r['jsonld'],
			'errors'   => $validated['errors'],
			'cost_usd' => $cost_usd,
		] );
	}

	// -------------------------------------------------------------------------
	// URL rule handlers
	// -------------------------------------------------------------------------

	public function list_rules( WP_REST_Request $req ): WP_REST_Response {
		$rules = get_option( 'ac_schema_url_rules', [] );
		return $this->ok( is_array( $rules ) ? $rules : [] );
	}

	public function create_rule( WP_REST_Request $req ): WP_REST_Response {
		$body    = (array) $req->get_json_params();
		$rules   = get_option( 'ac_schema_url_rules', [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}

		$rule = [
			'id'             => uniqid( 'rule_', false ),
			'pattern'        => (string) ( $body['pattern']    ?? '' ),
			'match_type'     => (string) ( $body['match_type'] ?? 'glob' ),
			'schema_entries' => (array)  ( $body['schema_entries'] ?? [] ),
		];

		$rules[] = $rule;
		update_option( 'ac_schema_url_rules', $rules );

		return $this->ok( $rule );
	}

	public function update_rule( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id    = (string) $req->get_param( 'id' );
		$rules = get_option( 'ac_schema_url_rules', [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}

		$body    = (array) $req->get_json_params();
		$updated = null;

		foreach ( $rules as &$rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				foreach ( [ 'pattern', 'match_type', 'schema_entries' ] as $field ) {
					if ( isset( $body[ $field ] ) ) {
						$rule[ $field ] = $body[ $field ];
					}
				}
				$updated = $rule;
				break;
			}
		}
		unset( $rule );

		if ( $updated === null ) {
			return new \WP_Error( 'not_found', 'Rule not found.', [ 'status' => 404 ] );
		}

		update_option( 'ac_schema_url_rules', $rules );
		return $this->ok( $updated );
	}

	public function delete_rule( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id    = (string) $req->get_param( 'id' );
		$rules = get_option( 'ac_schema_url_rules', [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}

		$filtered = array_values( array_filter( $rules, static fn( $r ) => ( $r['id'] ?? '' ) !== $id ) );

		if ( count( $filtered ) === count( $rules ) ) {
			return new \WP_Error( 'not_found', 'Rule not found.', [ 'status' => 404 ] );
		}

		update_option( 'ac_schema_url_rules', $filtered );
		return $this->ok( [ 'deleted' => true ] );
	}

	public function test_rule( WP_REST_Request $req ): WP_REST_Response {
		$body       = (array) $req->get_json_params();
		$pattern    = (string) ( $body['pattern']    ?? '' );
		$match_type = (string) ( $body['match_type'] ?? 'glob' );
		$url        = (string) ( $body['url']        ?? '' );

		$matches = $this->url_matches_rule( $pattern, $match_type, $url );
		return $this->ok( [ 'matches' => $matches ] );
	}

	// -------------------------------------------------------------------------
	// Job handlers
	// -------------------------------------------------------------------------

	public function list_jobs( WP_REST_Request $req ): WP_REST_Response {
		$jobs = ( new Job_Store() )->list_recent( 20 );
		return $this->ok( $jobs );
	}

	public function create_job( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$body     = (array) $req->get_json_params();
		$scope    = (array) ( $body['scope'] ?? [] );
		$settings = get_option( 'ac_schema_settings', [] );
		$model    = (string) ( $body['model'] ?? $settings['default_model'] ?? 'claude-sonnet-4-6' );

		$total    = $this->count_for_scope( $scope );
		$store    = new Job_Store();
		$job_id   = $store->create( $scope, $model, $total );

		wp_schedule_single_event( time(), 'ac_schema_run_bulk_batch', [ $job_id ] );

		$job = $store->find( $job_id );
		return $this->ok( $job );
	}

	public function preview_cost( WP_REST_Request $req ): WP_REST_Response {
		$body     = (array) $req->get_json_params();
		$scope    = (array) ( $body['scope'] ?? [] );
		$settings = get_option( 'ac_schema_settings', [] );
		$model    = (string) ( $body['model'] ?? $settings['default_model'] ?? 'claude-sonnet-4-6' );

		$count   = $this->count_for_scope( $scope );
		$pricing = Spend_Tracker::PRICING[ $model ] ?? Spend_Tracker::PRICING['claude-sonnet-4-6'];

		// Estimate: 2000 input tokens + 500 output tokens per post.
		$estimated_cost_usd = $count * 2000 * $pricing['in']  / 1_000_000
		                    + $count * 500  * $pricing['out'] / 1_000_000;

		return $this->ok( compact( 'count', 'model', 'estimated_cost_usd' ) );
	}

	public function get_job( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id  = (int) $req->get_param( 'id' );
		$job = ( new Job_Store() )->find( $id );
		if ( $job === null ) {
			return new \WP_Error( 'not_found', 'Job not found.', [ 'status' => 404 ] );
		}
		return $this->ok( $job );
	}

	public function control_job( WP_REST_Request $req ): WP_REST_Response|\WP_Error {
		$id     = (int)    $req->get_param( 'id' );
		$action = (string) $req->get_param( 'action' );
		$store  = new Job_Store();

		$job = $store->find( $id );
		if ( $job === null ) {
			return new \WP_Error( 'not_found', 'Job not found.', [ 'status' => 404 ] );
		}

		switch ( $action ) {
			case 'pause':
				$store->set_status( $id, Job_Store::STATUS_PAUSED );
				break;
			case 'resume':
				$store->set_status( $id, Job_Store::STATUS_RUNNING );
				// Re-schedule the cron batch so processing continues.
				wp_schedule_single_event( time(), 'ac_schema_run_bulk_batch', [ $id ] );
				break;
			case 'cancel':
				$store->set_status( $id, Job_Store::STATUS_FAILED );
				break;
		}

		$updated = $store->find( $id );
		return $this->ok( $updated );
	}

	// -------------------------------------------------------------------------
	// Spend handler
	// -------------------------------------------------------------------------

	public function spend( WP_REST_Request $req ): WP_REST_Response {
		$settings    = get_option( 'ac_schema_settings', [] );
		$daily_cap   = (float) ( $settings['daily_spend_cap_usd']   ?? 5.0 );
		$monthly_cap = (float) ( $settings['monthly_spend_cap_usd'] ?? 50.0 );

		return $this->ok( [
			'today_usd'   => Spend_Tracker::spend_today_usd(),
			'month_usd'   => Spend_Tracker::spend_month_usd(),
			'daily_cap'   => $daily_cap,
			'monthly_cap' => $monthly_cap,
		] );
	}

	// -------------------------------------------------------------------------
	// Migration stub (Phase 11)
	// -------------------------------------------------------------------------

	public function migrate_from_meta( WP_REST_Request $req ): WP_REST_Response {
		return $this->ok( [
			'status'  => 'pending',
			'message' => 'Implementation in Task 11.1',
		] );
	}

	// -------------------------------------------------------------------------
	// Settings handlers
	// -------------------------------------------------------------------------

	public function get_settings( WP_REST_Request $req ): WP_REST_Response {
		$settings = get_option( 'ac_schema_settings', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Never return the API key in clear text; replace with a boolean flag.
		unset( $settings['api_key'] );
		$settings['api_key_set'] = (bool) Secret_Store::get( 'anthropic_api_key' );

		return $this->ok( $settings );
	}

	public function put_settings( WP_REST_Request $req ): WP_REST_Response {
		$body     = (array) $req->get_json_params();
		$settings = get_option( 'ac_schema_settings', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Store API key separately via Secret_Store — never in the plain settings blob.
		if ( isset( $body['api_key'] ) && is_string( $body['api_key'] ) && $body['api_key'] !== '' ) {
			Secret_Store::set( 'anthropic_api_key', $body['api_key'] );
		}
		unset( $body['api_key'] );

		// Apply recognised settings fields.
		$allowed = [
			'default_model',
			'daily_spend_cap_usd',
			'monthly_spend_cap_usd',
			'output_priority',
			'suppress_amplifi_meta_jsonld',
		];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$settings[ $field ] = $body[ $field ];
			}
		}

		update_option( 'ac_schema_settings', $settings );

		// Return in the same shape as get_settings.
		$settings['api_key_set'] = (bool) Secret_Store::get( 'anthropic_api_key' );

		return $this->ok( $settings );
	}

	// -------------------------------------------------------------------------
	// Post-level override handlers
	// -------------------------------------------------------------------------

	public function put_post_overrides( WP_REST_Request $req ): WP_REST_Response {
		$post_id = (int) $req['id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_REST_Response( [ 'message' => 'Post not found' ], 404 );
		}
		$body = $req->get_json_params();
		$list = get_post_meta( $post_id, '_ac_schema_overrides', true );
		$list = is_array( $list ) ? $list : [];
		if ( ! empty( $body['add'] ) && is_string( $body['add'] ) ) {
			if ( ! in_array( $body['add'], $list, true ) ) {
				$list[] = $body['add'];
			}
		}
		if ( ! empty( $body['remove'] ) && is_string( $body['remove'] ) ) {
			$list = array_values( array_filter( $list, fn( $t ) => $t !== $body['remove'] ) );
		}
		update_post_meta( $post_id, '_ac_schema_overrides', $list );
		return new WP_REST_Response( [ 'overrides' => $list ] );
	}
}
