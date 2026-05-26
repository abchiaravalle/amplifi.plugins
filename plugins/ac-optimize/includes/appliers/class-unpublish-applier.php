<?php
/**
 * Applier: executes delete / redirect / noindex / keep for an unpublish suggestion.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes by action stored in proposed_value.
 *
 * - delete   → wp_trash_post()
 * - redirect → Redirection plugin row if available; otherwise an option-backed
 *              map that the plugin serves via template_redirect.
 * - noindex  → seo_detector->set_noindex(true)
 * - keep     → marks dismissed (no-op).
 */
class Amplifi_Optimize_Unpublish_Applier implements Amplifi_Optimize_Applier_Interface {

	const REDIRECT_MAP_OPTION = 'amplifi_optimize_redirect_map';

	/**
	 * Plugin instance.
	 *
	 * @var Amplifi_Optimize_Plugin
	 */
	private $plugin;

	/**
	 * Constructor. Also wires the template_redirect fallback.
	 *
	 * @param Amplifi_Optimize_Plugin $plugin Plugin singleton.
	 */
	public function __construct( Amplifi_Optimize_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
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
	public function apply( array $suggestion ): array {
		$post_id = (int) $suggestion['target_id'];
		$action  = (string) $suggestion['proposed_value'];
		$meta    = is_array( $suggestion['proposed_metadata'] ?? null ) ? $suggestion['proposed_metadata'] : array();

		$post = get_post( $post_id );
		if ( ! $post && 'delete' !== $action ) {
			return array(
				'ok'    => false,
				'error' => __( 'Post no longer exists.', 'amplifi-optimize' ),
			);
		}

		$snapshot = array(
			'action'    => $action,
			'status'    => $post ? $post->post_status : 'unknown',
			'permalink' => $post ? get_permalink( $post ) : '',
			'noindex'   => (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
		);

		switch ( $action ) {
			case 'delete':
				$res = wp_trash_post( $post_id );
				if ( ! $res ) {
					return array(
						'ok'    => false,
						'error' => __( 'Failed to trash post.', 'amplifi-optimize' ),
					);
				}
				return array(
					'ok'       => true,
					'snapshot' => wp_json_encode( $snapshot ),
				);

			case 'redirect':
				$target = (string) ( $meta['redirect_target'] ?? '' );
				if ( '' === $target ) {
					return array(
						'ok'    => false,
						'error' => __( 'No redirect target provided.', 'amplifi-optimize' ),
					);
				}
				$source = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
				$ok     = $this->register_redirect( $source, $target );
				if ( ! $ok ) {
					return array(
						'ok'    => false,
						'error' => __( 'Failed to register redirect.', 'amplifi-optimize' ),
					);
				}
				$this->plugin->seo->set_noindex( $post_id, true );
				return array(
					'ok'       => true,
					'snapshot' => wp_json_encode( array_merge( $snapshot, array( 'source' => $source ) ) ),
				);

			case 'noindex':
				$this->plugin->seo->set_noindex( $post_id, true );
				return array(
					'ok'       => true,
					'snapshot' => wp_json_encode( $snapshot ),
				);

			case 'keep':
			default:
				return array(
					'ok'       => true,
					'snapshot' => wp_json_encode( $snapshot ),
				);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function undo( array $suggestion ): array {
		$post_id  = (int) $suggestion['target_id'];
		$snapshot = json_decode( (string) ( $suggestion['previous_snapshot'] ?? '' ), true );
		if ( ! is_array( $snapshot ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No undo snapshot available.', 'amplifi-optimize' ),
			);
		}
		switch ( $snapshot['action'] ?? '' ) {
			case 'delete':
				wp_untrash_post( $post_id );
				break;
			case 'redirect':
				$source = (string) ( $snapshot['source'] ?? '' );
				if ( $source ) {
					$this->unregister_redirect( $source );
				}
				$this->plugin->seo->set_noindex( $post_id, false );
				break;
			case 'noindex':
				$this->plugin->seo->set_noindex( $post_id, false );
				break;
		}
		return array( 'ok' => true );
	}

	/**
	 * Registers a redirect — uses Redirection plugin's table if active,
	 * otherwise stores in our own option-backed map.
	 *
	 * @param string $source Source path, leading slash.
	 * @param string $target Target path or URL.
	 */
	private function register_redirect( string $source, string $target ): bool {
		global $wpdb;
		$redirection_table = $wpdb->prefix . 'redirection_items';
		$table_exists      = (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $redirection_table ) )
		);

		if ( $table_exists === $redirection_table ) {
			$ok = $wpdb->insert(
				$redirection_table,
				array(
					'url'         => $source,
					'action_type' => 'url',
					'action_data' => $target,
					'action_code' => 301,
					'match_type'  => 'url',
					'regex'       => 0,
					'status'      => 'enabled',
					'group_id'    => 1,
				)
			);
			return false !== $ok;
		}

		$map = get_option( self::REDIRECT_MAP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ $source ] = $target;
		return update_option( self::REDIRECT_MAP_OPTION, $map );
	}

	/**
	 * Removes a redirect from our option-backed map (Redirection plugin
	 * rows are left intact to avoid clobbering manual edits).
	 *
	 * @param string $source Source path.
	 */
	private function unregister_redirect( string $source ): void {
		$map = get_option( self::REDIRECT_MAP_OPTION, array() );
		if ( is_array( $map ) && isset( $map[ $source ] ) ) {
			unset( $map[ $source ] );
			update_option( self::REDIRECT_MAP_OPTION, $map );
		}
	}

	/**
	 * Serves redirects from our option-backed map at template_redirect time.
	 * Only fires when the path matches exactly.
	 */
	public function maybe_redirect(): void {
		$map = get_option( self::REDIRECT_MAP_OPTION, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return;
		}
		$path = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = wp_parse_url( $path, PHP_URL_PATH );
		if ( ! $path ) {
			return;
		}
		$path = rtrim( $path, '/' ) ?: '/';
		foreach ( $map as $src => $dst ) {
			if ( rtrim( $src, '/' ) === $path ) {
				wp_safe_redirect( $dst, 301 );
				exit;
			}
		}
	}
}
