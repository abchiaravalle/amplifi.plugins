<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Data;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Data\Entry_Store;

/**
 * Unit tests for Entry_Store using wpdb stubs.
 *
 * These tests do not require a real database; all wpdb calls are intercepted
 * via anonymous-class stubs injected into $GLOBALS['wpdb'].
 */
final class EntryStoreTest extends TestCase {

	public function test_class_can_be_required(): void {
		// Define a minimal $wpdb stub so constructor succeeds.
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
		};

		// Should not throw.
		$this->assertInstanceOf( Entry_Store::class, new Entry_Store() );
	}

	public function test_save_returns_int_via_stubbed_wpdb(): void {
		// Build a stub wpdb that records the prepared SQL and returns a fixed insert_id.
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public int    $insert_id = 42;
			public array  $queries   = [];

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
			public function get_var( string $sql ) {
				return null;
			}
		};

		$store = new Entry_Store();
		$id    = $store->save( [
			'scope_type'  => 'post',
			'scope_id'    => '42',
			'schema_type' => 'Article',
			'source'      => 'ai',
			'json_ld'     => '{"@type":"Article"}',
		] );

		$this->assertSame( 42, $id );
		$this->assertNotEmpty( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'INSERT INTO', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_save_falls_back_to_find_id_when_no_insert_id(): void {
		$GLOBALS['wpdb'] = new class {
			public string $prefix    = 'wp_';
			public int    $insert_id = 0;

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function query( string $sql ): int {
				return 1;
			}
			public function get_var( string $sql ) {
				return '99';
			}
		};

		$store = new Entry_Store();
		$id    = $store->save( [
			'scope_type'  => 'post',
			'scope_id'    => '42',
			'schema_type' => 'Article',
			'source'      => 'manual',
			'json_ld'     => '{}',
		] );

		$this->assertSame( 99, $id );
	}

	public function test_find_one_returns_row_via_stub(): void {
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_row( string $sql, $output = ARRAY_A ): ?array {
				return [
					'id'          => '7',
					'scope_type'  => 'post',
					'scope_id'    => '42',
					'schema_type' => 'Article',
					'json_ld'     => '{"@type":"Article"}',
				];
			}
		};

		$store = new Entry_Store();
		$row   = $store->find_one( 'post', '42', 'Article' );

		$this->assertNotNull( $row );
		$this->assertSame( '{"@type":"Article"}', $row['json_ld'] );
	}

	public function test_find_one_returns_null_on_no_row(): void {
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_row( string $sql, $output = ARRAY_A ) {
				return null;
			}
		};

		$store = new Entry_Store();
		$this->assertNull( $store->find_one( 'post', '42', 'Article' ) );
	}

	public function test_find_all_for_scope_returns_array(): void {
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_results( string $sql, $output = ARRAY_A ): array {
				return [
					[ 'schema_type' => 'Article', 'json_ld' => '{}' ],
					[ 'schema_type' => 'BreadcrumbList', 'json_ld' => '{}' ],
				];
			}
		};

		$store = new Entry_Store();
		$rows  = $store->find_all_for_scope( 'post', '42' );

		$this->assertCount( 2, $rows );
	}
}
