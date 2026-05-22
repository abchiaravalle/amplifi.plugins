<?php
declare(strict_types=1);
namespace Amplifi\Schema\Schema;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Registry {
	private array $index;

	public function __construct( ?string $index_path = null ) {
		$path = $index_path ?? AMPLIFI_SCHEMA_PATH . 'includes/schema/data/schema-org-types.json';
		$json = is_file( $path ) ? (string) file_get_contents( $path ) : '{}';
		$this->index = json_decode( $json, true ) ?: [];
	}

	public function has_type( string $type ): bool {
		return isset( $this->index[ $type ] );
	}

	public function properties_for( string $type ): array {
		return $this->index[ $type ]['properties'] ?? [];
	}

	public function required_for_rich_results( string $type ): array {
		return $this->index[ $type ]['required_for_rich_results'] ?? [];
	}

	public function all_types(): array {
		return array_keys( $this->index );
	}
}
