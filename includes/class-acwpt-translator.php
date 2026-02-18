<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Translator {

	/**
	 * Translate a post's title, content, and excerpt via OpenAI.
	 *
	 * @param string $title    Raw post title.
	 * @param string $content  Raw post content.
	 * @param string $excerpt  Raw post excerpt (may be empty).
	 * @param string $language Target language code.
	 * @return array|WP_Error  Array with 'title', 'content', 'excerpt' keys or WP_Error.
	 */
	public static function translate( $title, $content, $excerpt, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenAI API key is not configured.' );
		}

		$lang_info   = ACWPT_Languages::get( $language );
		$target_name = $lang_info ? $lang_info['name'] : $language;

		$system_prompt = "You are a professional translator. Translate the provided content to {$target_name}.\n\n"
			. "RULES:\n"
			. "- Preserve ALL HTML tags exactly as they are.\n"
			. "- Preserve ALL WordPress shortcodes (anything inside [ ] brackets) exactly as they are.\n"
			. "- Preserve ALL WordPress block comments (<!-- wp:... --> and <!-- /wp:... -->) exactly.\n"
			. "- Only translate the human-readable text.\n"
			. "- Maintain the exact same formatting and structure.\n"
			. "- Do not add or remove any HTML tags, shortcodes, or block comments.\n"
			. "- Translate naturally and fluently, not word-for-word.\n\n"
			. "Return your translation using the EXACT same delimiter format as the input.";

		$has_excerpt = ! empty( trim( $excerpt ) );

		$user_message = "===TITLE===\n{$title}\n\n===CONTENT===\n{$content}";
		if ( $has_excerpt ) {
			$user_message .= "\n\n===EXCERPT===\n{$excerpt}";
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system_prompt ),
							array( 'role' => 'user',   'content' => $user_message ),
						),
						'temperature' => 0.3,
						'max_tokens'  => 16000,
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
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'openai_error', 'OpenAI API error: ' . $error_msg );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'empty_response', 'OpenAI returned an empty response.' );
		}

		$translated_text = $data['choices'][0]['message']['content'];

		return self::parse_response( $translated_text, $has_excerpt );
	}

	/**
	 * Batch translate an array of short strings via OpenAI.
	 *
	 * @param array  $strings  Indexed array of strings: ['Hello', 'About Us', ...].
	 * @param string $language Target language code.
	 * @return array|WP_Error  Assoc array ['Hello' => 'Hola', ...] or WP_Error.
	 */
	public static function translate_strings( $strings, $language ) {
		$settings = get_option( 'acwpt_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$model    = isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenAI API key is not configured.' );
		}

		if ( empty( $strings ) ) {
			return array();
		}

		$lang_info   = ACWPT_Languages::get( $language );
		$target_name = $lang_info ? $lang_info['name'] : $language;

		// Build numbered mapping for reliable parsing.
		$indexed = array();
		$originals = array_values( $strings );
		foreach ( $originals as $i => $s ) {
			$indexed[ (string) $i ] = $s;
		}

		$system = "You are a translator. Translate each string to {$target_name}. "
			. "Return a JSON object where the keys are the numeric indices (as strings) and the values are the translations. "
			. "Translate naturally. Keep proper nouns and brand names as appropriate. Be concise.";

		$user = wp_json_encode( $indexed, JSON_UNESCAPED_UNICODE );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => $model,
						'messages'        => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user',   'content' => $user ),
						),
						'temperature'     => 0.3,
						'response_format' => array( 'type' => 'json_object' ),
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
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'openai_error', 'OpenAI API error: ' . $error_msg );
		}

		$content    = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		$translated = json_decode( $content, true );

		if ( ! is_array( $translated ) ) {
			return new WP_Error( 'parse_error', 'Could not parse string translation response.' );
		}

		// Map back to original => translated.
		$result = array();
		foreach ( $indexed as $i => $original ) {
			$result[ $original ] = isset( $translated[ $i ] ) ? $translated[ $i ] : $original;
		}

		return $result;
	}

	/**
	 * Parse the delimiter-formatted response from OpenAI.
	 */
	private static function parse_response( $text, $has_excerpt ) {
		$result = array(
			'title'   => '',
			'content' => '',
			'excerpt' => '',
		);

		if ( preg_match( '/===TITLE===\s*(.*?)(?=\s*===CONTENT===)/s', $text, $m ) ) {
			$result['title'] = trim( $m[1] );
		}

		if ( $has_excerpt ) {
			if ( preg_match( '/===CONTENT===\s*(.*?)(?=\s*===EXCERPT===)/s', $text, $m ) ) {
				$result['content'] = trim( $m[1] );
			}
			if ( preg_match( '/===EXCERPT===\s*(.*)/s', $text, $m ) ) {
				$result['excerpt'] = trim( $m[1] );
			}
		} else {
			if ( preg_match( '/===CONTENT===\s*(.*)/s', $text, $m ) ) {
				$result['content'] = trim( $m[1] );
			}
		}

		if ( empty( $result['title'] ) && empty( $result['content'] ) ) {
			$result['content'] = trim( $text );
		}

		return $result;
	}

	/**
	 * Test the API key by making a minimal request.
	 */
	public static function test_api_key( $api_key ) {
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'gpt-4o-mini',
						'messages'   => array(
							array( 'role' => 'user', 'content' => 'Say "ok"' ),
						),
						'max_tokens' => 5,
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
