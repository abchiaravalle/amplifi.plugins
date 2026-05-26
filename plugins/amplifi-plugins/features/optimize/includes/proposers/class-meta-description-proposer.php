<?php
/**
 * Proposer: meta descriptions, batched 10 per Claude call.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends batches of pending meta-description rows to Claude and stores the
 * proposed values back.
 */
class Amplifi_Optimize_Meta_Description_Proposer implements Amplifi_Optimize_Proposer_Interface {

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
		return 'meta_description';
	}

	/**
	 * {@inheritdoc}
	 */
	public function propose( array $args = array() ): array {
		$limit    = max( 1, (int) ( $args['limit'] ?? 50 ) );
		$settings = $this->plugin->get_settings();
		$batch    = max( 1, (int) $settings['batch_size_meta'] );

		$pending = $this->plugin->db->list(
			array(
				'type'     => $this->fix_type(),
				'status'   => 'pending',
				'per_page' => $limit,
				'page'     => 1,
			)
		);

		$rows      = $pending['items'];
		$processed = 0;
		$failed    = 0;

		// Filter out rows that already have a proposed value.
		$rows = array_values( array_filter( $rows, fn( $r ) => empty( $r['proposed_value'] ) ) );

		foreach ( array_chunk( $rows, $batch ) as $chunk ) {
			$payload = array();
			$by_id   = array();
			foreach ( $chunk as $row ) {
				$post = get_post( (int) $row['target_id'] );
				if ( ! $post ) {
					$this->plugin->db->update(
						(int) $row['id'],
						array(
							'status'        => 'failed',
							'error_message' => 'Post not found.',
						)
					);
					$failed++;
					continue;
				}
				$payload[] = array(
					'id'      => (string) $row['id'],
					'title'   => $post->post_title,
					'url'     => get_permalink( $post ),
					'excerpt' => $this->truncate_clean( $post->post_content, 800 ),
				);
				$by_id[ (string) $row['id'] ] = $row;
			}

			if ( ! $payload ) {
				continue;
			}

			$system = require AMPLIFI_OPTIMIZE_DIR . 'includes/prompts/meta-description.php';
			$user   = wp_json_encode( array( 'posts' => $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			$resp = $this->plugin->claude->send_text( $system, $user, array( 'max_tokens' => 2048 ) );

			if ( ! $resp['ok'] || ! is_array( $resp['json'] ) || empty( $resp['json']['results'] ) ) {
				foreach ( $chunk as $row ) {
					$this->plugin->db->update(
						(int) $row['id'],
						array(
							'status'              => 'failed',
							'error_message'       => $resp['error'] ?? 'Invalid response shape.',
							'claude_response_raw' => $resp['raw'] ?? '',
						)
					);
					$failed++;
				}
				continue;
			}

			foreach ( $resp['json']['results'] as $r ) {
				$id = isset( $r['id'] ) ? (string) $r['id'] : '';
				if ( ! isset( $by_id[ $id ] ) ) {
					continue;
				}
				$proposed = isset( $r['meta_description'] ) ? (string) $r['meta_description'] : '';
				$reason   = isset( $r['reasoning'] ) ? (string) $r['reasoning'] : '';

				if ( '' === trim( $proposed ) ) {
					$this->plugin->db->update(
						(int) $id,
						array(
							'status'              => 'failed',
							'error_message'       => 'Claude returned empty description.',
							'claude_response_raw' => $resp['raw'],
							'proposed_metadata'   => array( 'reasoning' => $reason ),
						)
					);
					$failed++;
					continue;
				}

				$this->plugin->db->update(
					(int) $id,
					array(
						'proposed_value'      => $proposed,
						'proposed_metadata'   => array(
							'reasoning'  => $reason,
							'char_count' => mb_strlen( $proposed ),
						),
						'claude_response_raw' => $resp['raw'],
						'error_message'       => null,
					)
				);
				$processed++;
			}
		}

		return compact( 'processed', 'failed' );
	}

	/**
	 * Strips HTML/shortcodes and truncates to a reasonable character window.
	 *
	 * @param string $content Raw post content.
	 * @param int    $length  Max characters.
	 */
	private function truncate_clean( string $content, int $length ): string {
		$clean = wp_strip_all_tags( strip_shortcodes( $content ) );
		$clean = preg_replace( '/\s+/', ' ', (string) $clean );
		$clean = trim( (string) $clean );
		if ( mb_strlen( $clean ) <= $length ) {
			return $clean;
		}
		return mb_substr( $clean, 0, $length );
	}
}
