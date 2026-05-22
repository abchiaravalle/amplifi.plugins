<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Migration\Meta_Importer;

final class MetaImporterTest extends TestCase {
	private array $options = [];
	private array $saved_entries = [];

	protected function setUp(): void {
		// Stub WP option functions if not already defined.
		if ( ! function_exists( 'get_option' ) ) {
			eval( '
				$GLOBALS["ac_opts"] = [];
				function get_option( $key, $default = false ) { return $GLOBALS["ac_opts"][$key] ?? $default; }
				function update_option( $key, $value, $autoload = null ) { $GLOBALS["ac_opts"][$key] = $value; return true; }
				function maybe_unserialize( $v ) { if ( ! is_string( $v ) ) return $v; if ( str_starts_with( $v, "a:" ) || str_starts_with( $v, "s:" ) || str_starts_with( $v, "i:" ) || str_starts_with( $v, "b:" ) ) { $r = @unserialize( $v ); return $r === false && $v !== "b:0;" ? $v : $r; } return $v; }
				function wp_json_encode( $v ) { return json_encode( $v ); }
			' );
		}
		$GLOBALS['ac_opts'] = [];

		// Stub wpdb that returns one row for the postmeta query and acts as Entry_Store target.
		$GLOBALS['wpdb'] = new class {
			public string $prefix   = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public int $insert_id   = 1;
			public array $entries_saved = [];
			public array $prepare_args = [];
			public function prepare( string $sql, ...$args ): string {
				// Record args so tests can assert on them.
				$this->prepare_args[] = $args;
				return $sql;
			}
			public function query( string $sql ): int {
				// Capture INSERT INTO wp_ac_schema_entries calls.
				if ( str_contains( $sql, 'ac_schema_entries' ) ) {
					$this->entries_saved[] = $sql;
				}
				return 1;
			}
			public function get_var( string $sql ) { return null; }
			public function get_row( string $sql, $output = ARRAY_A ) { return null; }
			public function get_results( string $sql, $output = ARRAY_A ): array {
				if ( str_contains( $sql, 'postmeta' ) ) {
					return [ [
						'post_id'    => 100,
						'meta_value' => json_encode( [
							'@context' => 'https://schema.org',
							'@type'    => 'Article',
							'headline' => 'Test',
						] ),
					] ];
				}
				return [];
			}
		};
	}

	public function test_import_writes_an_entry_and_sets_status(): void {
		$result = Meta_Importer::import_all();
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'done', get_option( 'ac_schema_meta_import_status' ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->entries_saved );
		// The SQL template goes to query(); the bound args (including 'imported') go to prepare().
		$all_args = array_merge( ...$GLOBALS['wpdb']->prepare_args );
		$this->assertContains( 'imported', $all_args );
	}

	public function test_skip_sets_status(): void {
		Meta_Importer::skip();
		$this->assertSame( 'skipped', get_option( 'ac_schema_meta_import_status' ) );
	}
}
