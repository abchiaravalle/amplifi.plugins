<?php
/**
 * Reachability probe: decide whether OpenAI can fetch image URLs from this
 * site, or whether we should always inline as base64.
 *
 * Many real-world sites are behind Cloudflare Bot Fight / Hostname Bypass
 * rules that return 403/503/HTML challenge pages to non-browser user agents.
 * OpenAI's fetcher then silently fails. We detect this once with a HEAD
 * request from our own server (which talks to the public URL just like
 * OpenAI would) and cache the verdict.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Reachability {

	const OPTION = 'acalt_reachability';
	const TTL    = DAY_IN_SECONDS; // re-probe daily if mode=url

	/**
	 * Return current mode without forcing a probe.
	 *
	 * @return string 'url' | 'base64' | 'unknown'
	 */
	public static function current_mode() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['mode'] ) ) {
			return 'unknown';
		}
		// Auto-refresh stale url-mode verdicts so a CF rule change is detected.
		if ( $state['mode'] === 'url'
			&& ! empty( $state['probed_at'] )
			&& ( time() - (int) $state['probed_at'] ) > self::TTL ) {
			return 'unknown';
		}
		return (string) $state['mode'];
	}

	public static function info() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Run the probe synchronously. Returns the resulting mode + details.
	 *
	 * @return array { mode, status, probed_at, url, reason }
	 */
	public static function probe() {
		$url = self::sample_image_url();
		if ( ! $url ) {
			$state = array(
				'mode'      => 'unknown',
				'reason'    => 'no medium-size image in library to probe',
				'probed_at' => time(),
			);
			update_option( self::OPTION, $state );
			return $state;
		}

		// If the URL is local-only we'll always inline anyway — record that.
		if ( ACALT_Generator::is_locally_reachable_only( $url ) ) {
			$state = array(
				'mode'      => 'base64',
				'reason'    => 'site URL is not publicly reachable (localhost / private network) — inline mode',
				'url'       => $url,
				'probed_at' => time(),
			);
			update_option( self::OPTION, $state );
			return $state;
		}

		// HEAD with a stock UA that's not too aggressive but not "Wget" either.
		$response = wp_remote_head(
			$url,
			array(
				'timeout'    => 8,
				'redirection'=> 3,
				'user-agent' => 'Mozilla/5.0 (compatible; OpenAI-ImageFetcher/1.0; +https://openai.com)',
			)
		);

		if ( is_wp_error( $response ) ) {
			$state = array(
				'mode'      => 'base64',
				'reason'    => 'probe transport error: ' . $response->get_error_message(),
				'url'       => $url,
				'probed_at' => time(),
			);
			update_option( self::OPTION, $state );
			return $state;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ctype  = (string) wp_remote_retrieve_header( $response, 'content-type' );

		// Image MIME → publicly reachable.
		if ( $status === 200 && strpos( $ctype, 'image/' ) === 0 ) {
			$state = array(
				'mode'      => 'url',
				'reason'    => sprintf( '200 OK, content-type=%s', $ctype ),
				'status'    => $status,
				'url'       => $url,
				'probed_at' => time(),
			);
			update_option( self::OPTION, $state );
			return $state;
		}

		// Anything else — challenge page, HTML, redirect to login, 403, 503 — inline mode.
		$state = array(
			'mode'      => 'base64',
			'reason'    => sprintf( 'public fetch failed: HTTP %d, content-type=%s', $status, $ctype ?: '(none)' ),
			'status'    => $status,
			'url'       => $url,
			'probed_at' => time(),
		);
		update_option( self::OPTION, $state );
		return $state;
	}

	/**
	 * Pick a representative image to probe. First attachment with a medium size.
	 */
	private static function sample_image_url() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'
			 ORDER BY ID ASC LIMIT 50"
		);
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_src( (int) $id, 'medium' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return $src[0];
			}
		}
		return '';
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
