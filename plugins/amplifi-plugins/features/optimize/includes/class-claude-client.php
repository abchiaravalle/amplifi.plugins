<?php
/**
 * Anthropic Claude API client.
 *
 * Thin wrapper around wp_remote_post against /v1/messages. No Composer
 * dependency, no SDK. Handles rate limiting, token accounting, structured
 * JSON parsing with markdown-fence fallback, and 429 retry-after.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude API client.
 */
class Amplifi_Optimize_Claude_Client {

	const RATE_LIMIT_OPTION = 'amplifi_optimize_rate_window';
	const TOKEN_OPTION      = 'amplifi_optimize_token_usage';

	/**
	 * Returns the configured (decrypted) API key.
	 */
	public function get_api_key(): string {
		$stored = (string) get_option( 'amplifi_optimize_api_key', '' );
		return Amplifi_Optimize_Encryption::decrypt( $stored );
	}

	/**
	 * Persists the API key encrypted.
	 *
	 * @param string $api_key Raw key.
	 */
	public function set_api_key( string $api_key ): void {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			delete_option( 'amplifi_optimize_api_key' );
			return;
		}
		update_option( 'amplifi_optimize_api_key', Amplifi_Optimize_Encryption::encrypt( $api_key ) );
	}

	/**
	 * Sends a non-vision message and returns the parsed first-content block.
	 *
	 * @param string                                          $system  System prompt.
	 * @param string                                          $user    User prompt (string).
	 * @param array{model?:string,max_tokens?:int,timeout?:int} $opts  Options.
	 * @return array{ok:bool,text:string,json:array|null,raw:string,error?:string,usage?:array}
	 */
	public function send_text( string $system, string $user, array $opts = array() ): array {
		return $this->send(
			$system,
			array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			$opts
		);
	}

	/**
	 * Sends a vision message with a remote image URL.
	 *
	 * @param string                                          $system    System prompt.
	 * @param string                                          $user_text Instruction text.
	 * @param string                                          $image_url Public image URL.
	 * @param array{model?:string,max_tokens?:int,timeout?:int} $opts     Options.
	 */
	public function send_vision( string $system, string $user_text, string $image_url, array $opts = array() ): array {
		$content = array(
			array(
				'type'   => 'image',
				'source' => array(
					'type' => 'url',
					'url'  => $image_url,
				),
			),
			array(
				'type' => 'text',
				'text' => $user_text,
			),
		);
		return $this->send(
			$system,
			array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			$opts
		);
	}

	/**
	 * Core request method.
	 *
	 * @param string                                          $system   System prompt.
	 * @param array<int,array<string,mixed>>                  $messages Messages array.
	 * @param array{model?:string,max_tokens?:int,timeout?:int} $opts   Options.
	 */
	public function send( string $system, array $messages, array $opts = array() ): array {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => '',
				'error' => __( 'No Anthropic API key configured.', 'amplifi-optimize' ),
			);
		}

		$settings = Amplifi_Optimize_Plugin::instance()->get_settings();
		$model    = (string) ( $opts['model'] ?? $settings['model'] ?? AMPLIFI_OPTIMIZE_DEFAULT_MODEL );
		$max      = (int) ( $opts['max_tokens'] ?? 1024 );
		$timeout  = (int) ( $opts['timeout'] ?? 60 );

		$gate = $this->rate_limit_gate( (int) $settings['rate_limit_per_minute'] );
		if ( ! $gate['ok'] ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => '',
				'error' => sprintf(
					/* translators: %d seconds to wait. */
					__( 'Local rate limit hit; retry in %d seconds.', 'amplifi-optimize' ),
					$gate['retry_after']
				),
			);
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max,
			'system'     => $system,
			'messages'   => $messages,
		);

		$response = wp_remote_post(
			AMPLIFI_OPTIMIZE_API_BASE,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => AMPLIFI_OPTIMIZE_ANTHROPIC_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => '',
				'error' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 429 === $status ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => $raw,
				'error' => sprintf(
					/* translators: %d seconds to wait. */
					__( 'Anthropic API rate-limited; retry in %d seconds.', 'amplifi-optimize' ),
					max( 1, $retry_after )
				),
			);
		}
		if ( $status < 200 || $status >= 300 ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => $raw,
				'error' => sprintf(
					/* translators: 1: HTTP status, 2: response body. */
					__( 'Anthropic API error (%1$d): %2$s', 'amplifi-optimize' ),
					$status,
					mb_substr( $raw, 0, 500 )
				),
			);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'    => false,
				'text'  => '',
				'json'  => null,
				'raw'   => $raw,
				'error' => __( 'Could not decode Anthropic response.', 'amplifi-optimize' ),
			);
		}

		$text = '';
		if ( ! empty( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$usage = isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ? $decoded['usage'] : array();
		if ( $usage ) {
			$this->record_usage( $model, (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );
		}

		$json = $this->extract_json( $text );

		return array(
			'ok'    => true,
			'text'  => $text,
			'json'  => $json,
			'raw'   => $raw,
			'usage' => $usage,
		);
	}

	/**
	 * Defensive JSON extractor: strict-parses, falls back to fenced ```json
	 * blocks, then to the first balanced {...} or [...] block in the text.
	 *
	 * @param string $text Response text.
	 */
	public function extract_json( string $text ) {
		$trimmed = trim( $text );
		if ( '' === $trimmed ) {
			return null;
		}

		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*(.+?)```/is', $trimmed, $m ) ) {
			$decoded = json_decode( trim( $m[1] ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Try to grab the first object or array.
		if ( preg_match( '/(\{.*\}|\[.*\])/s', $trimmed, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Records token usage in a rolling option.
	 *
	 * @param string $model        Model id.
	 * @param int    $input_tokens Input token count.
	 * @param int    $output_tokens Output token count.
	 */
	private function record_usage( string $model, int $input_tokens, int $output_tokens ): void {
		$usage = get_option( self::TOKEN_OPTION, array() );
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}
		if ( ! isset( $usage[ $model ] ) ) {
			$usage[ $model ] = array(
				'input'  => 0,
				'output' => 0,
				'calls'  => 0,
			);
		}
		$usage[ $model ]['input']  += $input_tokens;
		$usage[ $model ]['output'] += $output_tokens;
		$usage[ $model ]['calls']  += 1;
		update_option( self::TOKEN_OPTION, $usage, false );
	}

	/**
	 * Returns aggregate token usage.
	 *
	 * @return array<string,array{input:int,output:int,calls:int}>
	 */
	public function get_usage(): array {
		$usage = get_option( self::TOKEN_OPTION, array() );
		return is_array( $usage ) ? $usage : array();
	}

	/**
	 * Sliding-window rate limiter. Returns ok=true and increments the
	 * counter, or ok=false with retry_after seconds.
	 *
	 * @param int $rpm Requests per minute.
	 * @return array{ok:bool,retry_after:int}
	 */
	private function rate_limit_gate( int $rpm ): array {
		$rpm   = max( 1, $rpm );
		$now   = time();
		$state = get_transient( self::RATE_LIMIT_OPTION );
		if ( ! is_array( $state ) ) {
			$state = array(
				'window_start' => $now,
				'count'        => 0,
			);
		}
		if ( $now - $state['window_start'] >= 60 ) {
			$state = array(
				'window_start' => $now,
				'count'        => 0,
			);
		}
		if ( $state['count'] >= $rpm ) {
			$retry = 60 - ( $now - $state['window_start'] );
			return array(
				'ok'          => false,
				'retry_after' => max( 1, $retry ),
			);
		}
		$state['count']++;
		set_transient( self::RATE_LIMIT_OPTION, $state, 120 );
		return array(
			'ok'          => true,
			'retry_after' => 0,
		);
	}
}
