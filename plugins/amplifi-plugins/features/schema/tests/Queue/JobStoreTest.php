<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\Queue\Job_Store;

final class JobStoreTest extends TestCase {
	private function stub_wpdb( array $opts = [] ): object {
		$stub = new class {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			public $row_return = null;
			public $results_return = [];
			public array $queries = [];
			public function prepare( string $sql, ...$args ): string {
				// Naive interpolation just for assertion-readability in tests.
				foreach ( $args as $a ) {
					$sql = preg_replace( '/%[dsf]/', is_string( $a ) ? "'$a'" : (string) $a, $sql, 1 );
				}
				return $sql;
			}
			public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
			public function get_row( string $sql, $output = ARRAY_A ) { return $this->row_return; }
			public function get_results( string $sql, $output = ARRAY_A ): array { return $this->results_return; }
		};
		foreach ( $opts as $k => $v ) { $stub->$k = $v; }
		$GLOBALS['wpdb'] = $stub;
		return $stub;
	}

	protected function setUp(): void {
		if ( ! function_exists( 'wp_json_encode' ) ) {
			eval( 'function wp_json_encode( $v ) { return json_encode( $v ); }' );
		}
	}

	public function test_create_returns_insert_id(): void {
		$db = $this->stub_wpdb( [ 'insert_id' => 17 ] );
		$id = ( new Job_Store() )->create( [ 'post_types' => [ 'post' ] ], 'claude-haiku-4-5-20251001', 50 );
		$this->assertSame( 17, $id );
		$this->assertStringContainsString( 'INSERT INTO', $db->queries[0] );
		$this->assertStringContainsString( 'queued', $db->queries[0] );
	}

	public function test_set_status_running_uses_coalesce_started_at(): void {
		$db = $this->stub_wpdb();
		( new Job_Store() )->set_status( 1, Job_Store::STATUS_RUNNING );
		$this->assertStringContainsString( 'COALESCE(started_at', $db->queries[0] );
		$this->assertStringContainsString( "status='running'", $db->queries[0] );
	}

	public function test_set_status_completed_sets_finished_at(): void {
		$db = $this->stub_wpdb();
		( new Job_Store() )->set_status( 1, Job_Store::STATUS_COMPLETED );
		$this->assertStringContainsString( 'finished_at', $db->queries[0] );
	}

	public function test_set_status_paused_does_not_touch_timestamps(): void {
		$db = $this->stub_wpdb();
		( new Job_Store() )->set_status( 1, Job_Store::STATUS_PAUSED );
		$this->assertStringNotContainsString( 'started_at', $db->queries[0] );
		$this->assertStringNotContainsString( 'finished_at', $db->queries[0] );
	}

	public function test_record_progress_increments(): void {
		$db = $this->stub_wpdb();
		( new Job_Store() )->record_progress( 1, 5, 1, 0.42 );
		$this->assertStringContainsString( 'processed = processed + 5', $db->queries[0] );
		$this->assertStringContainsString( 'failed    = failed + 1', $db->queries[0] );
		$this->assertStringContainsString( 'cost_usd  = cost_usd + 0.42', $db->queries[0] );
	}

	public function test_find_returns_row(): void {
		$this->stub_wpdb( [ 'row_return' => [ 'id' => '1', 'status' => 'queued' ] ] );
		$job = ( new Job_Store() )->find( 1 );
		$this->assertSame( 'queued', $job['status'] );
	}

	public function test_find_returns_null_when_missing(): void {
		$this->stub_wpdb();
		$this->assertNull( ( new Job_Store() )->find( 999 ) );
	}

	public function test_list_recent_returns_array(): void {
		$this->stub_wpdb( [ 'results_return' => [ [ 'id' => '1' ], [ 'id' => '2' ] ] ] );
		$rows = ( new Job_Store() )->list_recent( 5 );
		$this->assertCount( 2, $rows );
	}
}
