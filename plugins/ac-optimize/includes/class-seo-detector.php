<?php
/**
 * Detects which SEO plugin is active (Yoast / RankMath / AIOSEO) and exposes
 * the right meta key for description/title/noindex per plugin.
 *
 * Detection runs once on first access and is cached on the instance.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO plugin detector.
 */
class Amplifi_Optimize_SEO_Detector {

	const PROVIDER_NONE      = 'none';
	const PROVIDER_YOAST     = 'yoast';
	const PROVIDER_RANKMATH  = 'rankmath';
	const PROVIDER_AIOSEO    = 'aioseo';

	/**
	 * Cached provider id.
	 *
	 * @var string|null
	 */
	private $provider = null;

	/**
	 * Returns the active SEO provider id.
	 */
	public function provider(): string {
		if ( null !== $this->provider ) {
			return $this->provider;
		}

		$settings = Amplifi_Optimize_Plugin::instance()->get_settings();
		$override = (string) ( $settings['detector_override'] ?? 'auto' );
		if ( 'auto' !== $override ) {
			$this->provider = $override;
			return $this->provider;
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$this->provider = self::PROVIDER_YOAST;
		} elseif ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$this->provider = self::PROVIDER_RANKMATH;
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$this->provider = self::PROVIDER_AIOSEO;
		} else {
			$this->provider = self::PROVIDER_NONE;
		}
		return $this->provider;
	}

	/**
	 * Returns the post-meta key used by the active SEO plugin to store the
	 * meta description. AIOSEO stores in a custom table; we return an
	 * empty string and callers should route through aioseo_post_data().
	 */
	public function meta_description_key(): string {
		switch ( $this->provider() ) {
			case self::PROVIDER_YOAST:
				return '_yoast_wpseo_metadesc';
			case self::PROVIDER_RANKMATH:
				return 'rank_math_description';
			case self::PROVIDER_AIOSEO:
				// AIOSEO v4 stores in its own table.
				return '';
			default:
				return '_amplifi_optimize_metadesc';
		}
	}

	/**
	 * Meta key for SEO title (where supported via post meta).
	 */
	public function title_key(): string {
		switch ( $this->provider() ) {
			case self::PROVIDER_YOAST:
				return '_yoast_wpseo_title';
			case self::PROVIDER_RANKMATH:
				return 'rank_math_title';
			case self::PROVIDER_AIOSEO:
				return '';
			default:
				return '_amplifi_optimize_title';
		}
	}

	/**
	 * Meta key for noindex flag. Yoast/RankMath use post-meta keys; AIOSEO
	 * uses its own table.
	 */
	public function noindex_key(): string {
		switch ( $this->provider() ) {
			case self::PROVIDER_YOAST:
				return '_yoast_wpseo_meta-robots-noindex';
			case self::PROVIDER_RANKMATH:
				return 'rank_math_robots';
			default:
				return '_amplifi_optimize_noindex';
		}
	}

	/**
	 * Returns the current stored meta description for a post.
	 *
	 * @param int $post_id Post id.
	 */
	public function get_meta_description( int $post_id ): string {
		$key = $this->meta_description_key();
		if ( $key ) {
			return (string) get_post_meta( $post_id, $key, true );
		}
		// AIOSEO fallback via its own data layer if present.
		if ( self::PROVIDER_AIOSEO === $this->provider() && function_exists( 'aioseo' ) ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ), ARRAY_A );
			return is_array( $row ) ? (string) ( $row['description'] ?? '' ) : '';
		}
		return '';
	}

	/**
	 * Saves the meta description using whichever storage the active SEO
	 * plugin expects.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   New value.
	 */
	public function set_meta_description( int $post_id, string $value ): bool {
		$key = $this->meta_description_key();
		if ( $key ) {
			return false !== update_post_meta( $post_id, $key, $value );
		}
		if ( self::PROVIDER_AIOSEO === $this->provider() ) {
			global $wpdb;
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ) );
			if ( $exists ) {
				return false !== $wpdb->update( "{$wpdb->prefix}aioseo_posts", array( 'description' => $value ), array( 'post_id' => $post_id ) );
			}
			return false !== $wpdb->insert(
				"{$wpdb->prefix}aioseo_posts",
				array(
					'post_id'     => $post_id,
					'description' => $value,
				)
			);
		}
		return false;
	}

	/**
	 * Returns the rendered SEO title (template applied where possible).
	 *
	 * @param int $post_id Post id.
	 */
	public function rendered_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$key   = $this->title_key();
		$stored = $key ? (string) get_post_meta( $post_id, $key, true ) : '';
		if ( $stored ) {
			return $this->apply_title_template( $stored, $post );
		}
		return $post->post_title;
	}

	/**
	 * Replaces a handful of common Yoast/RankMath placeholders in a title
	 * template. Best-effort — not exhaustive.
	 *
	 * @param string  $template Template containing %%placeholders%%.
	 * @param WP_Post $post     Post object.
	 */
	private function apply_title_template( string $template, WP_Post $post ): string {
		$replacements = array(
			'%%title%%'    => $post->post_title,
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%%sep%%'      => '|',
			'%%page%%'     => '',
		);
		return trim( strtr( $template, $replacements ) );
	}

	/**
	 * Sets the SEO title meta.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   Title.
	 */
	public function set_title( int $post_id, string $value ): bool {
		$key = $this->title_key();
		if ( ! $key ) {
			return false;
		}
		return false !== update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Sets the noindex flag for a post.
	 *
	 * @param int  $post_id  Post id.
	 * @param bool $noindex  Whether to noindex.
	 */
	public function set_noindex( int $post_id, bool $noindex ): bool {
		switch ( $this->provider() ) {
			case self::PROVIDER_YOAST:
				return false !== update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '0' );
			case self::PROVIDER_RANKMATH:
				$robots = (array) get_post_meta( $post_id, 'rank_math_robots', true );
				$robots = array_values( array_unique( array_filter( $robots, fn( $r ) => 'noindex' !== $r ) ) );
				if ( $noindex ) {
					$robots[] = 'noindex';
				}
				return false !== update_post_meta( $post_id, 'rank_math_robots', $robots );
			default:
				return false !== update_post_meta( $post_id, '_amplifi_optimize_noindex', $noindex ? '1' : '0' );
		}
	}

	/**
	 * Brand suffix style based on site name. Used to preview titles in UI.
	 */
	public function brand_suffix(): string {
		return ' | ' . get_bloginfo( 'name' );
	}
}
