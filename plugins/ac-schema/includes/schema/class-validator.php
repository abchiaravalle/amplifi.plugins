<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Validator {
	private const CONTEXT_OK = [ 'https://schema.org', 'http://schema.org' ];

	public function __construct( private Registry $registry ) {}

	public function validate( string $json_ld ): array {
		$errors = [];
		$data   = json_decode( $json_ld, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return [
				'ok'     => false,
				'errors' => [ [
					'path'    => '$',
					'code'    => 'invalid_json',
					'message' => json_last_error_msg(),
				] ],
			];
		}
		if ( ! is_array( $data ) ) {
			return [
				'ok'     => false,
				'errors' => [ [
					'path'    => '$',
					'code'    => 'not_object',
					'message' => 'JSON-LD must be an object.',
				] ],
			];
		}

		$context = $data['@context'] ?? null;
		if ( ! is_string( $context ) || ! in_array( rtrim( $context, '/' ), self::CONTEXT_OK, true ) ) {
			$errors[] = [
				'path'    => '$.@context',
				'code'    => 'missing_context',
				'message' => '@context must be https://schema.org',
			];
		}

		$type = $data['@type'] ?? null;
		if ( ! is_string( $type ) ) {
			$errors[] = [
				'path'    => '$.@type',
				'code'    => 'missing_type',
				'message' => '@type is required',
			];
			return [ 'ok' => false, 'errors' => $errors ];
		}
		if ( ! $this->registry->has_type( $type ) ) {
			$errors[] = [
				'path'    => '$.@type',
				'code'    => 'unknown_type',
				'message' => "Unknown @type: $type",
			];
			return [ 'ok' => false, 'errors' => $errors ];
		}

		$allowed_props = array_flip( array_merge(
			$this->registry->properties_for( $type ),
			[ '@context', '@type', '@id', '@graph' ]
		) );
		foreach ( array_keys( $data ) as $prop ) {
			if ( ! isset( $allowed_props[ $prop ] ) ) {
				$errors[] = [
					'path'    => '$.' . $prop,
					'code'    => 'unknown_property',
					'message' => "Unknown property '$prop' for $type",
				];
			}
		}

		$required = $this->registry->required_for_rich_results( $type );
		$missing  = array_values( array_filter( $required, static fn( $p ) => ! array_key_exists( $p, $data ) ) );
		if ( $missing ) {
			$errors[] = [
				'path'    => '$',
				'code'    => 'missing_required_for_rich_results',
				'message' => 'Missing for rich results: ' . implode( ', ', $missing ),
			];
		}

		return [ 'ok' => empty( $errors ), 'errors' => $errors ];
	}
}
