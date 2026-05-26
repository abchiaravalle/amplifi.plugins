<?php
/**
 * Proposer: long title rewrites (≤58 chars).
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Claude call per title. Cheap enough that batching adds complexity
 * without meaningful savings.
 */
class Amplifi_Optimize_Title_Proposer implements Amplifi_Optimize_Proposer_Interface {

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
		return 'title';
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

		$system = require AMPLIFI_OPTIMIZE_DIR . 'includes/prompts/title.php';

		foreach ( $rows as $row ) {
			$post_id = (int) $row['target_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				$this->plugin->db->update( (int) $row['id'], array(
					'status'        => 'failed',
					'error_message' => 'Post not found.',
				) );
				$failed++;
				continue;
			}

			$user = wp_json_encode(
				array(
					'current_title' => (string) $row['current_value'],
					'post_title'    => $post->post_title,
					'excerpt'       => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 60 ),
					'url'           => get_permalink( $post ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			$resp = $this->plugin->claude->send_text( $system, $user, array( 'max_tokens' => 400 ) );

			if ( ! $resp['ok'] || ! is_array( $resp['json'] ) || empty( $resp['json']['title'] ) ) {
				$this->plugin->db->update( (int) $row['id'], array(
					'status'              => 'failed',
					'error_message'       => $resp['error'] ?? 'Invalid response shape.',
					'claude_response_raw' => $resp['raw'] ?? '',
				) );
				$failed++;
				continue;
			}

			$title  = (string) $resp['json']['title'];
			$reason = (string) ( $resp['json']['reasoning'] ?? '' );

			$this->plugin->db->update( (int) $row['id'], array(
				'proposed_value'      => $title,
				'proposed_metadata'   => array(
					'reasoning'      => $reason,
					'char_count'     => mb_strlen( $title ),
					'previous_chars' => mb_strlen( (string) $row['current_value'] ),
					'brand_preview'  => $title . $this->plugin->seo->brand_suffix(),
				),
				'claude_response_raw' => $resp['raw'],
				'error_message'       => null,
			) );
			$processed++;
		}

		return compact( 'processed', 'failed' );
	}
}
