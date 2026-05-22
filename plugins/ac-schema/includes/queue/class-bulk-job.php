<?php
declare(strict_types=1);
namespace Amplifi\Schema\Queue;

use Amplifi\Schema\AI\Anthropic_Client;
use Amplifi\Schema\AI\Prompt_Builder;
use Amplifi\Schema\AI\Spend_Tracker;
use Amplifi\Schema\Crypto\Secret_Store;
use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Schema\Registry;
use Amplifi\Schema\Schema\Validator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bulk_Job {
	private const BATCH_SIZE             = 5;
	private const RESCHEDULE_DELAY       = 30;
	private const ESTIMATED_PER_POST_USD = 0.05;
	private const CRON_HOOK              = 'ac_schema_run_bulk_batch';

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_batch' ], 10, 1 );
	}

	public function run_batch( int $job_id ): void {
		$store = new Job_Store();
		$job   = $store->find( $job_id );
		if ( ! $job ) { return; }
		if ( ! in_array( $job['status'], [ Job_Store::STATUS_QUEUED, Job_Store::STATUS_RUNNING ], true ) ) {
			return;
		}
		$store->set_status( $job_id, Job_Store::STATUS_RUNNING );

		$api_key = Secret_Store::get( 'anthropic_api_key' );
		if ( ! is_string( $api_key ) || $api_key === '' ) {
			$store->set_status( $job_id, Job_Store::STATUS_FAILED );
			return;
		}

		$scope = json_decode( (string) $job['scope'], true );
		if ( ! is_array( $scope ) ) {
			$scope = [];
		}
		$ids = $this->next_post_ids( $scope, self::BATCH_SIZE );
		if ( empty( $ids ) ) {
			$store->set_status( $job_id, Job_Store::STATUS_COMPLETED );
			return;
		}

		$client    = new Anthropic_Client( $api_key, (string) $job['model'] );
		$validator = new Validator( new Registry() );
		$entries   = new Entry_Store();

		$processed = 0;
		$failed    = 0;
		$cost_sum  = 0.0;

		foreach ( $ids as $post_id ) {
			if ( ! Spend_Tracker::can_spend( self::ESTIMATED_PER_POST_USD ) ) {
				$store->set_status( $job_id, Job_Store::STATUS_PAUSED );
				break;
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				$failed++;
				continue;
			}
			$prompt = Prompt_Builder::build_for_post( [
				'title'     => (string) $post->post_title,
				'url'       => (string) get_permalink( $post ),
				'post_type' => (string) $post->post_type,
				'content'   => wp_strip_all_tags( (string) $post->post_content ),
				'existing'  => null,
			] );
			$r = $client->generate_jsonld( $prompt['system'], $prompt['user'] );
			if ( ! empty( $r['error'] ) ) {
				$failed++;
				continue;
			}
			Spend_Tracker::record( (string) $job['model'], (int) $r['input_tokens'], (int) $r['output_tokens'] );
			$cost_sum += Spend_Tracker::estimate_cost( (string) $job['model'], (int) $r['input_tokens'], (int) $r['output_tokens'] );

			$json_str = (string) wp_json_encode( $r['jsonld'] );
			$v        = $validator->validate( $json_str );
			if ( ! $v['ok'] ) {
				$failed++;
				continue;
			}
			$entries->save( [
				'scope_type'  => 'post',
				'scope_id'    => (string) $post_id,
				'schema_type' => (string) ( $r['jsonld']['@type'] ?? 'Thing' ),
				'source'      => 'ai',
				'json_ld'     => $json_str,
			] );
			$processed++;
		}

		$store->record_progress( $job_id, $processed, $failed, $cost_sum );

		// Re-arm if still running.
		$refreshed = $store->find( $job_id );
		if ( $refreshed && $refreshed['status'] === Job_Store::STATUS_RUNNING ) {
			wp_schedule_single_event( time() + self::RESCHEDULE_DELAY, self::CRON_HOOK, [ $job_id ] );
		}
	}

	/**
	 * Pick the next N post IDs matching the job's scope that don't already have an AI-generated entry.
	 *
	 * @param array $scope
	 * @param int   $limit
	 * @return int[]
	 */
	private function next_post_ids( array $scope, int $limit ): array {
		$args = [
			'post_type'      => $scope['post_types'] ?? [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];
		if ( ! empty( $scope['ids'] ) ) {
			$args['post__in'] = array_map( 'intval', (array) $scope['ids'] );
		}
		if ( ! empty( $scope['after'] ) ) {
			$args['date_query'] = [ [ 'after' => (string) $scope['after'] ] ];
		}
		global $wpdb;
		$already = $wpdb->get_col( "SELECT scope_id FROM {$wpdb->prefix}ac_schema_entries WHERE scope_type='post' AND source='ai'" ); // phpcs:ignore
		if ( $already ) {
			$args['post__not_in'] = array_map( 'intval', $already );
		}
		return get_posts( $args );
	}
}
