<?php
/**
 * Proposer: classifies unpublish candidates as delete/redirect/noindex/keep.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends each candidate to Claude with the heuristic reasons attached.
 */
class Amplifi_Optimize_Unpublish_Proposer implements Amplifi_Optimize_Proposer_Interface {

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
	}

	/**
	 * {@inheritdoc}
	 */
	public function fix_type(): string {
		return 'unpublish';
	}

	/**
	 * {@inheritdoc}
	 */
	public function propose( array $args = array() ): array {
		$limit   = max( 1, (int) ( $args['limit'] ?? 25 ) );
		$pending = $this->plugin->db->list(
			array(
				'type'     => $this->fix_type(),
				'status'   => 'pending',
				'per_page' => $limit,
				'page'     => 1,
			)
		);
		$rows      = array_values( array_filter( $pending['items'], fn( $r ) => empty( $r['proposed_value'] ) ) );
		$processed = 0;
		$failed    = 0;
		$system    = require AMPLIFI_OPTIMIZE_DIR . 'includes/prompts/unpublish.php';

		foreach ( $rows as $row ) {
			$post = get_post( (int) $row['target_id'] );
			if ( ! $post ) {
				$this->plugin->db->update( (int) $row['id'], array(
					'status'        => 'failed',
					'error_message' => 'Post not found.',
				) );
				$failed++;
				continue;
			}

			$meta = is_array( $row['proposed_metadata'] ) ? $row['proposed_metadata'] : array();
			$user = wp_json_encode(
				array(
					'title'      => $post->post_title,
					'url'        => get_permalink( $post ),
					'modified'   => $post->post_modified_gmt,
					'excerpt'    => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 80 ),
					'flag_reasons' => $meta['reasons'] ?? array(),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			$resp = $this->plugin->claude->send_text( $system, $user, array( 'max_tokens' => 400 ) );

			if ( ! $resp['ok'] || ! is_array( $resp['json'] ) || empty( $resp['json']['action'] ) ) {
				$this->plugin->db->update( (int) $row['id'], array(
					'status'              => 'failed',
					'error_message'       => $resp['error'] ?? 'Invalid response shape.',
					'claude_response_raw' => $resp['raw'] ?? '',
				) );
				$failed++;
				continue;
			}

			$action = sanitize_key( (string) $resp['json']['action'] );
			if ( ! in_array( $action, array( 'delete', 'redirect', 'noindex', 'keep' ), true ) ) {
				$action = 'keep';
			}
			$redirect_target = isset( $resp['json']['redirect_target'] ) ? (string) $resp['json']['redirect_target'] : '';
			$reasoning       = isset( $resp['json']['reasoning'] ) ? (string) $resp['json']['reasoning'] : '';

			$merged = array_merge(
				$meta,
				array(
					'action'          => $action,
					'redirect_target' => $redirect_target,
					'reasoning'       => $reasoning,
				)
			);

			$this->plugin->db->update( (int) $row['id'], array(
				'proposed_value'      => $action,
				'proposed_metadata'   => $merged,
				'claude_response_raw' => $resp['raw'],
				'error_message'       => null,
			) );
			$processed++;
		}

		return compact( 'processed', 'failed' );
	}
}
