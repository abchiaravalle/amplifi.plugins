<?php
declare(strict_types=1);
namespace Amplifi\Schema\Tests\AI;

use PHPUnit\Framework\TestCase;
use Amplifi\Schema\AI\Spend_Tracker;

final class SpendTrackerTest extends TestCase {
	public function test_cost_for_haiku(): void {
		// Haiku 4.5: $1/M input, $5/M output → 1M in + 100K out = $1 + $0.50 = $1.50
		$cost = Spend_Tracker::estimate_cost( 'claude-haiku-4-5-20251001', 1_000_000, 100_000 );
		$this->assertEqualsWithDelta( 1.5, $cost, 0.001 );
	}

	public function test_cost_for_sonnet(): void {
		// Sonnet 4.6: $3/M input, $15/M output → $3 + $1.50 = $4.50
		$cost = Spend_Tracker::estimate_cost( 'claude-sonnet-4-6', 1_000_000, 100_000 );
		$this->assertEqualsWithDelta( 4.5, $cost, 0.001 );
	}

	public function test_cost_for_opus(): void {
		// Opus 4.7: $15/M input, $75/M output → $15 + $7.50 = $22.50
		$cost = Spend_Tracker::estimate_cost( 'claude-opus-4-7', 1_000_000, 100_000 );
		$this->assertEqualsWithDelta( 22.5, $cost, 0.001 );
	}

	public function test_unknown_model_falls_back_to_sonnet(): void {
		$cost = Spend_Tracker::estimate_cost( 'unknown-model', 1_000_000, 0 );
		$this->assertEqualsWithDelta( 3.0, $cost, 0.001 );
	}

	public function test_zero_tokens_is_zero_cost(): void {
		$this->assertSame( 0.0, Spend_Tracker::estimate_cost( 'claude-haiku-4-5-20251001', 0, 0 ) );
	}
}
