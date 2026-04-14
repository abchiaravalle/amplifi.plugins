<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Translator {

	/**
	 * Model pricing per token. Verify against Anthropic's published rates
	 * before each release (https://www.anthropic.com/pricing).
	 * Unknown models fall back to Sonnet pricing.
	 */
	private static $pricing = array(
		'claude-haiku-4-5'  => array( 'input' => 0.000001,  'output' => 0.000005 ),
		'claude-sonnet-4-5' => array( 'input' => 0.000003,  'output' => 0.000015 ),
		'claude-sonnet-4-6' => array( 'input' => 0.000003,  'output' => 0.000015 ),
		'claude-opus-4-5'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
		'claude-opus-4-6'   => array( 'input' => 0.000015,  'output' => 0.000075 ),
	);

	private static $default_model = 'claude-haiku-4-5';

	/**
	 * Translate a post's title, content, and excerpt via Anthropic Claude.
	 */
	public static function translate( $title, $content, $excerpt, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
		}

		$custom = array(
			'never_translate'     => isset( $settings['never_translate'] )     ? (array) $settings['never_translate'] : array(),
			'glossary'            => isset( $settings['glossary'] )            ? (array) $settings['glossary']        : array(),
			'custom_instructions' => isset( $settings['custom_instructions'] ) ? (array) $settings['custom_instructions'] : array(),
		);

		// Apply sentinels to the source text before assembling the user message.
		$glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

		$title   = ACWPT_Glossary::apply_keep_sentinels( $title,   $custom['never_translate'] );
		$content = ACWPT_Glossary::apply_keep_sentinels( $content, $custom['never_translate'] );
		$excerpt = ACWPT_Glossary::apply_keep_sentinels( $excerpt, $custom['never_translate'] );

		$title   = ACWPT_Glossary::apply_glossary_sentinels( $title,   $glossary_entries );
		$content = ACWPT_Glossary::apply_glossary_sentinels( $content, $glossary_entries );
		$excerpt = ACWPT_Glossary::apply_glossary_sentinels( $excerpt, $glossary_entries );

		$system_prompt = ACWPT_Prompts::build_content_prompt( $language, $custom );

		$has_excerpt  = ! empty( trim( $excerpt ) );
		$user_message = "===TITLE===\n{$title}\n\n===CONTENT===\n{$content}";
		if ( $has_excerpt ) {
			$user_message .= "\n\n===EXCERPT===\n{$excerpt}";
		}

		$data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 16000, 60 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		self::record_usage( $data, $model, 'content' );

		$translated_text = $data['content'][0]['text'];

		$parsed = self::parse_response( $translated_text, $has_excerpt );

		// Strip glossary wrappers (replace with mandated translation), then strip never-translate wrappers.
		foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
			$parsed[ $k ] = ACWPT_Glossary::strip_glossary_sentinels( $parsed[ $k ] );
			$parsed[ $k ] = ACWPT_Glossary::strip_keep_sentinels( $parsed[ $k ] );
		}

		return $parsed;
	}

	/**
	 * Batch translate an array of short strings via Anthropic Claude.
	 */
	public static function translate_strings( $strings, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) && $settings['model'] !== '' ? $settings['model'] : self::$default_model;

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
		}
		if ( empty( $strings ) ) {
			return array();
		}

		$custom = array(
			'never_translate'     => isset( $settings['never_translate'] )     ? (array) $settings['never_translate'] : array(),
			'glossary'            => isset( $settings['glossary'] )            ? (array) $settings['glossary']        : array(),
			'custom_instructions' => isset( $settings['custom_instructions'] ) ? (array) $settings['custom_instructions'] : array(),
		);
		$glossary_entries = ACWPT_Glossary::entries_for_language( $custom['glossary'], $language );

		$originals = array_values( $strings );
		$indexed   = array();
		foreach ( $originals as $i => $s ) {
			$wrapped = ACWPT_Glossary::apply_keep_sentinels( $s, $custom['never_translate'] );
			$wrapped = ACWPT_Glossary::apply_glossary_sentinels( $wrapped, $glossary_entries );
			$indexed[ (string) $i ] = $wrapped;
		}

		$system_prompt = ACWPT_Prompts::build_strings_prompt( $language, $custom );
		$user_message  = wp_json_encode( $indexed, JSON_UNESCAPED_UNICODE );

		$data = self::call_anthropic( $api_key, $model, $system_prompt, $user_message, 8192, 30 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		self::record_usage( $data, $model, 'strings' );

		$text       = $data['content'][0]['text'];
		$translated = ACWPT_Glossary::extract_first_json_object( $text );
		if ( ! is_array( $translated ) ) {
			return new WP_Error( 'parse_error', 'Could not parse string translation response.' );
		}

		$result = array();
		foreach ( $originals as $i => $original ) {
			$key = (string) $i;
			$val = isset( $translated[ $key ] ) ? (string) $translated[ $key ] : $original;
			$val = ACWPT_Glossary::strip_glossary_sentinels( $val );
			$val = ACWPT_Glossary::strip_keep_sentinels( $val );
			$result[ $original ] = $val;
		}

		return $result;
	}

	// =========================================================================
	// Anthropic API
	// =========================================================================

	/**
	 * Make a Messages API call to Anthropic. Returns array on success,
	 * WP_Error on failure. Caller is responsible for prompt assembly and
	 * response parsing.
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $system      Top-level system prompt.
	 * @param string $user        User message body.
	 * @param int    $max_tokens
	 * @param int    $timeout
	 * @return array|WP_Error     Decoded response body on success.
	 */
	private static function call_anthropic( $api_key, $model, $system, $user, $max_tokens = 8192, $timeout = 30 ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'max_tokens'  => $max_tokens,
						'temperature' => 0.3,
						'system'      => $system,
						'messages'    => array(
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'anthropic_error', 'Anthropic API error: ' . $msg );
		}

		if ( empty( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'empty_response', 'Anthropic returned an empty response.' );
		}

		return $data;
	}

	// =========================================================================
	// Usage Tracking
	// =========================================================================

	/**
	 * Record API usage from a response.
	 *
	 * @param array  $data     Decoded API response body.
	 * @param string $model    Model used.
	 * @param string $type     'content' or 'strings'.
	 */
	private static function record_usage( $data, $model, $type ) {
		if ( empty( $data['usage'] ) ) {
			return;
		}

		$input_tokens  = (int) ( $data['usage']['input_tokens']  ?? 0 );
		$output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );

		$pricing = isset( self::$pricing[ $model ] ) ? self::$pricing[ $model ] : self::$pricing['claude-sonnet-4-5'];
		$cost    = ( $input_tokens * $pricing['input'] ) + ( $output_tokens * $pricing['output'] );

		$month = gmdate( 'Y-m' );
		$usage = get_option( 'acwpt_usage', array() );

		if ( ! isset( $usage[ $month ] ) ) {
			$usage[ $month ] = array(
				'requests'             => 0,
				'prompt_tokens'        => 0,
				'completion_tokens'    => 0,
				'total_tokens'         => 0,
				'estimated_cost'       => 0.0,
				'content_translations' => 0,
				'string_translations'  => 0,
			);
		}

		$usage[ $month ]['requests']          += 1;
		$usage[ $month ]['prompt_tokens']     += $input_tokens;   // schema kept for back-compat
		$usage[ $month ]['completion_tokens'] += $output_tokens;
		$usage[ $month ]['total_tokens']      += $input_tokens + $output_tokens;
		$usage[ $month ]['estimated_cost']    += $cost;

		if ( $type === 'content' ) {
			$usage[ $month ]['content_translations'] += 1;
		} else {
			$usage[ $month ]['string_translations'] += 1;
		}

		update_option( 'acwpt_usage', $usage, false );
	}

	/**
	 * Get usage stats. Returns array keyed by 'YYYY-MM' with most recent first.
	 */
	public static function get_usage() {
		$usage = get_option( 'acwpt_usage', array() );
		krsort( $usage );
		return $usage;
	}

	/**
	 * Get usage for the current month.
	 */
	public static function get_current_month_usage() {
		$usage = get_option( 'acwpt_usage', array() );
		$month = gmdate( 'Y-m' );
		return isset( $usage[ $month ] ) ? $usage[ $month ] : null;
	}

	/**
	 * Get all-time totals.
	 */
	public static function get_total_usage() {
		$usage  = get_option( 'acwpt_usage', array() );
		$totals = array(
			'requests'             => 0,
			'prompt_tokens'        => 0,
			'completion_tokens'    => 0,
			'total_tokens'         => 0,
			'estimated_cost'       => 0.0,
			'content_translations' => 0,
			'string_translations'  => 0,
			'months'               => count( $usage ),
		);

		foreach ( $usage as $month_data ) {
			$totals['requests']             += $month_data['requests'] ?? 0;
			$totals['prompt_tokens']        += $month_data['prompt_tokens'] ?? 0;
			$totals['completion_tokens']    += $month_data['completion_tokens'] ?? 0;
			$totals['total_tokens']         += $month_data['total_tokens'] ?? 0;
			$totals['estimated_cost']       += $month_data['estimated_cost'] ?? 0;
			$totals['content_translations'] += $month_data['content_translations'] ?? 0;
			$totals['string_translations']  += $month_data['string_translations'] ?? 0;
		}

		return $totals;
	}

	// =========================================================================
	// Response Parsing
	// =========================================================================

	private static function parse_response( $text, $has_excerpt ) {
		$result = array(
			'title'   => '',
			'content' => '',
			'excerpt' => '',
		);

		if ( preg_match( '/===TITLE===\s*(.*?)(?=\s*===CONTENT===)/s', $text, $m ) ) {
			$result['title'] = trim( $m[1] );
		}

		// Always look for EXCERPT delimiter in the response — Claude sometimes
		// emits it even when the source had no excerpt. Split there if found,
		// then discard the excerpt if the caller didn't expect one.
		if ( preg_match( '/===CONTENT===\s*(.*?)(?=\s*===EXCERPT===)/s', $text, $m ) ) {
			$result['content'] = trim( $m[1] );
			if ( $has_excerpt && preg_match( '/===EXCERPT===\s*(.*)/s', $text, $em ) ) {
				$result['excerpt'] = trim( $em[1] );
			}
		} elseif ( preg_match( '/===CONTENT===\s*(.*)/s', $text, $m ) ) {
			$result['content'] = trim( $m[1] );
		}

		// Defensive: scrub any stray delimiter tokens that slipped through
		// (e.g., Claude echoing the section headers verbatim in content).
		foreach ( array( 'title', 'content', 'excerpt' ) as $k ) {
			$result[ $k ] = preg_replace( '/={3,}\s*(?:TITLE|CONTENT|EXCERPT)\s*={3,}/', '', $result[ $k ] );
			$result[ $k ] = trim( $result[ $k ] );
		}

		if ( empty( $result['title'] ) && empty( $result['content'] ) ) {
			$result['content'] = trim( $text );
		}

		return $result;
	}

	/**
	 * Test the API key by making a minimal Messages call.
	 */
	public static function test_api_key( $api_key ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::$default_model,
						'max_tokens' => 16,
						'messages'   => array(
							array( 'role' => 'user', 'content' => 'Reply with the single word: ok' ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'api_test_failed', $msg );
		}

		return true;
	}
}
