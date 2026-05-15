<?php
/**
 * Generator: calls OpenAI Chat Completions with vision input and writes alt text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Generator {

	/**
	 * Per-1k-token USD pricing for supported vision models.
	 * Update at release time to current OpenAI list prices.
	 */
	const PRICING = array(
		'gpt-4o-mini' => array( 'in' => 0.000150, 'out' => 0.000600 ), // per 1k tokens
		'gpt-4o'      => array( 'in' => 0.002500, 'out' => 0.010000 ),
	);

	/**
	 * Generate alt text for one attachment and update post meta.
	 *
	 * @param object $job  Row from acalt_jobs.
	 * @return array { 'ok' => bool, 'reason' => string, 'alt' => string,
	 *                 'tokens_in' => int, 'tokens_out' => int, 'cost' => float }
	 */
	public static function generate( $job ) {
		$attachment_id = (int) $job->attachment_id;
		$settings      = get_option( 'acalt_settings', array() );
		$api_key       = isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
		$model         = isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini';
		$prompt_style  = isset( $settings['prompt_style'] ) ? $settings['prompt_style'] : 'concise';
		$language      = isset( $settings['language'] ) ? $settings['language'] : 'en_US';

		if ( empty( $api_key ) ) {
			return self::result_skip( 'no API key configured' );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return self::result_skip( 'attachment not found' );
		}
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return self::result_skip( 'not an image' );
		}

		$existing = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $existing !== '' ) {
			return self::result_skip( 'alt text already set' );
		}

		// Resolve a public image URL — prefer medium for speed/cost.
		$url = self::resolve_image_url( $attachment_id );
		if ( ! $url ) {
			return self::result_skip( 'no image URL resolvable' );
		}

		// Decide whether to send a URL or inline base64. Three signals:
		//   1. Local-only host (localhost / .local / RFC1918) — always inline.
		//   2. acalt_reachability mode === 'base64' (probe found CF/auth wall) — always inline.
		//   3. Otherwise — send URL.
		$reach_mode = ACALT_Reachability::current_mode();
		$force_inline = ( $reach_mode === 'base64' ) || self::is_locally_reachable_only( $url );

		$image_payload = $force_inline
			? self::file_as_data_url( $attachment_id )
			: $url;
		if ( ! $image_payload ) {
			return self::result_skip( 'could not read image file for inlining' );
		}

		// Daily spend cap check.
		$cap = isset( $settings['daily_spend_cap_usd'] ) ? (float) $settings['daily_spend_cap_usd'] : 5.0;
		if ( $cap > 0 ) {
			$today = self::today_key();
			$stats = get_option( 'acalt_daily_stats', array() );
			$spent = isset( $stats[ $today ]['cost_usd'] ) ? (float) $stats[ $today ]['cost_usd'] : 0.0;
			if ( $spent >= $cap ) {
				return array(
					'ok'     => false,
					'park'   => true,
					'reason' => sprintf( 'daily cap reached ($%.4f / $%.2f)', $spent, $cap ),
				);
			}
		}

		// Call OpenAI.
		$site_context = isset( $settings['site_context'] ) ? trim( (string) $settings['site_context'] ) : '';
		$response = self::call_openai( $api_key, $model, $image_payload, $prompt_style, $language, $site_context );
		if ( is_wp_error( $response ) ) {
			$code     = $response->get_error_code();
			$message  = $response->get_error_message();
			$http_code = (int) ( $response->get_error_data() ?: 0 );

			// 429 rate limit: park, no retry consumed. Tells the cron loop to
			// stop the rest of the batch so we don't burn 10 retries in 10s.
			if ( $http_code === 429 || strpos( $code, '_429' ) !== false ) {
				return array(
					'ok'        => false,
					'park'      => true,
					'rate_limit' => true,
					'reason'    => 'rate limited (HTTP 429): ' . $message,
				);
			}

			// 401/403: kill switch. Pause the whole queue and alert once.
			// Otherwise 17k jobs × 3 attempts = 51k pointless calls.
			if ( $http_code === 401 || $http_code === 403 ) {
				self::pause_queue( 'auth_fail', sprintf( 'OpenAI returned HTTP %d: %s', $http_code, $message ) );
				return array(
					'ok'     => false,
					'park'   => true,
					'reason' => sprintf( 'auth failure (HTTP %d) — queue paused: %s', $http_code, $message ),
				);
			}

			return array(
				'ok'     => false,
				'park'   => false,
				'reason' => 'api error: ' . $message,
			);
		}

		$alt        = (string) $response['alt'];
		$decorative = ! empty( $response['decorative'] );
		$tokens_in  = (int) ( $response['tokens_in'] ?? 0 );
		$tokens_out = (int) ( $response['tokens_out'] ?? 0 );
		$cost       = self::price( $model, $tokens_in, $tokens_out );

		// WCAG-correct value for decorative images is empty alt="".
		$alt_to_write = $decorative ? '' : self::sanitize_alt( $alt );

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_to_write );

		// Roll daily stats.
		self::record_usage( $tokens_in, $tokens_out, $cost, 'generated' );

		return array(
			'ok'         => true,
			'alt'        => $decorative ? '(decorative — empty alt)' : $alt_to_write,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'cost'       => $cost,
		);
	}

	/**
	 * Return true when the URL host is only reachable from this server
	 * (localhost, .local/.test/.internal, RFC1918, link-local) — OpenAI's
	 * fetcher cannot reach those, so we should inline the image instead.
	 */
	public static function is_locally_reachable_only( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return true;
		}
		$host = strtolower( $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.internal', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		// IPv4 RFC1918 + link-local.
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( preg_match( '/^10\./', $host ) ) return true;
			if ( preg_match( '/^192\.168\./', $host ) ) return true;
			if ( preg_match( '/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host ) ) return true;
			if ( preg_match( '/^169\.254\./', $host ) ) return true;
		}
		return false;
	}

	/**
	 * Read the medium (or fallback) image file from disk and return a base64
	 * data URL suitable for OpenAI's image_url input.
	 */
	public static function file_as_data_url( $attachment_id ) {
		foreach ( array( 'medium', 'large', 'full' ) as $size ) {
			$path = self::file_path_for_size( $attachment_id, $size );
			if ( $path && is_readable( $path ) ) {
				$mime = function_exists( 'mime_content_type' )
					? mime_content_type( $path )
					: get_post_mime_type( $attachment_id );
				if ( ! $mime ) {
					$mime = 'image/jpeg';
				}
				$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( $bytes !== false ) {
					return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
				}
			}
		}
		return '';
	}

	private static function file_path_for_size( $attachment_id, $size ) {
		if ( $size === 'full' ) {
			return get_attached_file( $attachment_id );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['sizes'][ $size ]['file'] ) ) {
			return '';
		}
		$full = get_attached_file( $attachment_id );
		if ( ! $full ) {
			return '';
		}
		return dirname( $full ) . '/' . $meta['sizes'][ $size ]['file'];
	}

	private static function resolve_image_url( $attachment_id ) {
		foreach ( array( 'medium', 'large', 'full' ) as $size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return $src[0];
			}
		}
		return '';
	}

	private static function call_openai( $api_key, $model, $image_url, $prompt_style, $language, $site_context = '' ) {
		$style_instruction = ( $prompt_style === 'descriptive' )
			? 'Be moderately descriptive (around 100 characters). Capture the subject, key context, and any text visible in the image.'
			: 'Be concise (under 80 characters when possible). Focus on the primary subject and one or two key details.';

		$context_block = '';
		if ( $site_context !== '' ) {
			$context_block = "Site context (use vocabulary and framing appropriate to this domain — do NOT copy these sentences into the alt text):\n"
				. trim( $site_context ) . "\n\n";
		}

		$system = $context_block
			. "You write WCAG-compliant alt text for website images.\n"
			. "Rules:\n"
			. "- Keep it under 125 characters.\n"
			. "- Be factual, not interpretive. Do not speculate about emotions, intent, function, or off-frame context.\n"
			. "- Do NOT begin with 'image of', 'picture of', 'photo of', 'graphic showing', or similar.\n"
			. "- Do NOT include a trailing period unless the alt is a full sentence.\n"
			. "- Never invent product names, model numbers, brand names, technical specifications, dates, or measurements. If you cannot identify a specific product, machine, or part with certainty, describe its general category and observable features (configuration, components, color, mount style, materials).\n"
			. "- Do not assume use-case or function (e.g. 'used for X') unless the image clearly shows it in use.\n"
			. "- If text or a label is visibly printed on the subject (logo, model plate, signage, headline), include it verbatim in quotes. This is the ONLY place you should write specific names or numbers.\n"
			. "- If the image is purely decorative (divider, ornament, pattern, flag icon used as UI element, generic background with no informational value), set \"decorative\": true and \"alt\": \"\".\n"
			. "- " . $style_instruction . "\n"
			. "- Output language: " . $language . ".\n"
			. "Respond with strict JSON only: {\"alt\": string, \"decorative\": boolean}.";

		$body = array(
			'model'           => $model,
			'response_format' => array( 'type' => 'json_object' ),
			'max_tokens'      => 200,
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'text', 'text' => 'Generate alt text for this image. Return JSON only.' ),
						array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url ) ),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code !== 200 ) {
			$message = self::extract_error( $raw, $code );
			// Store HTTP code as error data so callers can distinguish 429 / 401 / 403 / 5xx.
			return new WP_Error( 'openai_http_' . $code, $message, $code );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'openai_parse', 'unexpected response shape' );
		}

		$content = $data['choices'][0]['message']['content'];
		$parsed  = json_decode( $content, true );
		if ( ! is_array( $parsed ) || ! array_key_exists( 'alt', $parsed ) ) {
			return new WP_Error( 'openai_parse', 'model did not return alt JSON' );
		}

		return array(
			'alt'        => (string) $parsed['alt'],
			'decorative' => ! empty( $parsed['decorative'] ),
			'tokens_in'  => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			'tokens_out' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
		);
	}

	private static function extract_error( $raw, $code ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
			return $decoded['error']['message'];
		}
		return 'HTTP ' . $code;
	}

	public static function price( $model, $tokens_in, $tokens_out ) {
		$p = self::PRICING[ $model ] ?? self::PRICING['gpt-4o-mini'];
		return ( $tokens_in / 1000 ) * $p['in'] + ( $tokens_out / 1000 ) * $p['out'];
	}

	public static function sanitize_alt( $alt ) {
		$alt = wp_strip_all_tags( $alt, true );
		$alt = trim( $alt );
		// Strip wrapping quotes models sometimes add.
		if ( ( substr( $alt, 0, 1 ) === '"' && substr( $alt, -1 ) === '"' )
			|| ( substr( $alt, 0, 1 ) === "'" && substr( $alt, -1 ) === "'" ) ) {
			$alt = trim( substr( $alt, 1, -1 ) );
		}
		// Strip forbidden prefixes if the model slipped them in.
		$alt = preg_replace( '/^(image|picture|photo|photograph|graphic|illustration)\s+(of|showing)\s+/i', '', $alt );
		// Cap length defensively.
		if ( mb_strlen( $alt ) > 125 ) {
			$alt = rtrim( mb_substr( $alt, 0, 122 ) ) . '...';
		}
		return $alt;
	}

	public static function today_key() {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Increment today's bucket in acalt_daily_stats. Trims to last 60 days.
	 *
	 * @param string $event 'generated' | 'failed' | 'skipped'
	 */
	public static function record_usage( $tokens_in, $tokens_out, $cost_usd, $event ) {
		$stats = get_option( 'acalt_daily_stats', array() );
		$key   = self::today_key();
		if ( ! isset( $stats[ $key ] ) ) {
			$stats[ $key ] = array(
				'generated' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'cost_usd'  => 0.0,
				'tokens_in' => 0,
				'tokens_out'=> 0,
			);
		}
		$stats[ $key ][ $event ]    = ( $stats[ $key ][ $event ] ?? 0 ) + 1;
		$stats[ $key ]['cost_usd']  = (float) ( $stats[ $key ]['cost_usd'] ?? 0 ) + (float) $cost_usd;
		$stats[ $key ]['tokens_in']  = (int) ( $stats[ $key ]['tokens_in'] ?? 0 ) + (int) $tokens_in;
		$stats[ $key ]['tokens_out'] = (int) ( $stats[ $key ]['tokens_out'] ?? 0 ) + (int) $tokens_out;

		// Trim to last 60 days.
		if ( count( $stats ) > 60 ) {
			krsort( $stats );
			$stats = array_slice( $stats, 0, 60, true );
		}

		update_option( 'acalt_daily_stats', $stats );
	}

	private static function result_skip( $reason ) {
		return array(
			'ok'     => false,
			'skip'   => true,
			'reason' => $reason,
		);
	}

	/**
	 * Pause the whole queue. Used on auth failure so we don't burn thousands
	 * of doomed API calls. Sends a single admin email; subsequent calls are
	 * no-ops until the queue is resumed.
	 */
	public static function pause_queue( $reason_code, $message ) {
		$current = get_option( 'acalt_paused', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! empty( $current['paused'] ) ) {
			return; // already paused
		}
		update_option(
			'acalt_paused',
			array(
				'paused'      => true,
				'reason_code' => $reason_code,
				'message'     => $message,
				'paused_at'   => time(),
			)
		);

		$settings = get_option( 'acalt_settings', array() );
		$to = isset( $settings['report_email'] ) ? sanitize_email( $settings['report_email'] ) : '';
		if ( $to ) {
			$site = get_bloginfo( 'name' );
			wp_mail(
				$to,
				sprintf( '[amplifi.alt] Queue paused on %s — %s', $site, $reason_code ),
				sprintf( "The amplifi.alt queue was paused automatically.\n\nReason: %s\nDetail: %s\n\nResume it from Tools → Alt once the issue is resolved.", $reason_code, $message )
			);
		}
	}

	public static function is_paused() {
		$p = get_option( 'acalt_paused', array() );
		return is_array( $p ) && ! empty( $p['paused'] );
	}

	public static function paused_info() {
		$p = get_option( 'acalt_paused', array() );
		return is_array( $p ) ? $p : array();
	}

	public static function resume_queue() {
		delete_option( 'acalt_paused' );
	}
}
