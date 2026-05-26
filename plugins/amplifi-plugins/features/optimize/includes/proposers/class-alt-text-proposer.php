<?php
/**
 * Proposer: alt text via Claude vision, one image per call, batches of 5.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls Claude vision per image. The batch size from settings controls how
 * many images are processed per invocation; each call still sends one image.
 */
class Amplifi_Optimize_Alt_Text_Proposer implements Amplifi_Optimize_Proposer_Interface {

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
		return 'alt_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function propose( array $args = array() ): array {
		$settings = $this->plugin->get_settings();
		$batch    = max( 1, (int) $settings['batch_size_alt'] );
		$limit    = max( 1, (int) ( $args['limit'] ?? $batch ) );

		$pending = $this->plugin->db->list(
			array(
				'type'     => $this->fix_type(),
				'status'   => 'pending',
				'per_page' => min( $limit, $batch ),
				'page'     => 1,
			)
		);

		$rows      = array_values( array_filter( $pending['items'], fn( $r ) => empty( $r['proposed_value'] ) ) );
		$processed = 0;
		$failed    = 0;

		$system = require AMPLIFI_OPTIMIZE_DIR . 'includes/prompts/alt-text.php';

		set_transient(
			'amplifi_optimize_scan_progress',
			array(
				'fix_type' => $this->fix_type(),
				'total'    => count( $rows ),
				'done'     => 0,
			),
			HOUR_IN_SECONDS
		);

		foreach ( $rows as $i => $row ) {
			$attachment_id = (int) $row['target_id'];
			$url           = wp_get_attachment_url( $attachment_id );
			if ( ! $url ) {
				$this->plugin->db->update(
					(int) $row['id'],
					array(
						'status'        => 'failed',
						'error_message' => 'Attachment URL unavailable.',
					)
				);
				$failed++;
				continue;
			}

			$filename = wp_basename( get_attached_file( $attachment_id ) ?: $url );
			$user     = sprintf( 'Write alt text for the attached image. Filename: %s', $filename );

			$resp = $this->plugin->claude->send_vision( $system, $user, $url, array( 'max_tokens' => 400 ) );

			if ( ! $resp['ok'] || ! is_array( $resp['json'] ) ) {
				$this->plugin->db->update(
					(int) $row['id'],
					array(
						'status'              => 'failed',
						'error_message'       => $resp['error'] ?? 'Invalid response shape.',
						'claude_response_raw' => $resp['raw'] ?? '',
					)
				);
				$failed++;
			} else {
				$alt         = isset( $resp['json']['alt_text'] ) ? (string) $resp['json']['alt_text'] : '';
				$decorative  = ! empty( $resp['json']['is_decorative'] );
				$reasoning   = isset( $resp['json']['reasoning'] ) ? (string) $resp['json']['reasoning'] : '';

				$this->plugin->db->update(
					(int) $row['id'],
					array(
						'proposed_value'      => $alt,
						'proposed_metadata'   => array(
							'reasoning'      => $reasoning,
							'is_decorative'  => $decorative,
							'char_count'     => mb_strlen( $alt ),
							'filename'       => $filename,
							'url'            => $url,
							'used_in'        => $this->find_usage( $attachment_id ),
						),
						'claude_response_raw' => $resp['raw'],
						'error_message'       => null,
					)
				);
				$processed++;
			}

			set_transient(
				'amplifi_optimize_scan_progress',
				array(
					'fix_type' => $this->fix_type(),
					'total'    => count( $rows ),
					'done'     => $i + 1,
				),
				HOUR_IN_SECONDS
			);
		}

		return compact( 'processed', 'failed' );
	}

	/**
	 * Returns a small list of post titles where the attachment is referenced.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<int,array{id:int,title:string}>
	 */
	private function find_usage( int $attachment_id ): array {
		global $wpdb;

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return array();
		}
		$basename = wp_basename( $url );

		$posts_by_thumb = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d
				LIMIT 5",
				$attachment_id
			),
			ARRAY_A
		);

		$posts_by_content = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_content LIKE %s
				LIMIT 5",
				'%' . $wpdb->esc_like( $basename ) . '%'
			),
			ARRAY_A
		);

		$rows  = array_merge( (array) $posts_by_thumb, (array) $posts_by_content );
		$seen  = array();
		$usage = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['ID'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$usage[]     = array(
				'id'    => $id,
				'title' => (string) $r['post_title'],
			);
		}
		return $usage;
	}
}
