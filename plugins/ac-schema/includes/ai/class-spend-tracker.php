<?php
declare(strict_types=1);
namespace Amplifi\Schema\AI;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Spend_Tracker {
	/** Per-million-token USD pricing. */
	public const PRICING = [
		'claude-haiku-4-5-20251001' => [ 'in' => 1.00,  'out' => 5.00 ],
		'claude-sonnet-4-6'         => [ 'in' => 3.00,  'out' => 15.00 ],
		'claude-opus-4-7'           => [ 'in' => 15.00, 'out' => 75.00 ],
	];

	public static function estimate_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$price = self::PRICING[ $model ] ?? self::PRICING['claude-sonnet-4-6'];
		return ( $input_tokens / 1_000_000 ) * $price['in']
			+ ( $output_tokens / 1_000_000 ) * $price['out'];
	}

	public static function record( string $model, int $input_tokens, int $output_tokens ): void {
		global $wpdb;
		$cost  = self::estimate_cost( $model, $input_tokens, $output_tokens );
		$day   = gmdate( 'Y-m-d' );
		$table = $wpdb->prefix . 'ac_schema_spend';
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (day, input_tokens, output_tokens, cost_usd)
			 VALUES (%s, %d, %d, %f)
			 ON DUPLICATE KEY UPDATE
				input_tokens = input_tokens + VALUES(input_tokens),
				output_tokens = output_tokens + VALUES(output_tokens),
				cost_usd = cost_usd + VALUES(cost_usd)",
			$day,
			$input_tokens,
			$output_tokens,
			$cost
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function spend_today_usd(): float {
		global $wpdb;
		$table = $wpdb->prefix . 'ac_schema_spend';
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT cost_usd FROM {$table} WHERE day = %s", gmdate( 'Y-m-d' ) ) ); // phpcs:ignore
		return (float) ( $row ?? 0.0 );
	}

	public static function spend_month_usd(): float {
		global $wpdb;
		$table = $wpdb->prefix . 'ac_schema_spend';
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_usd),0) FROM {$table} WHERE day >= %s", gmdate( 'Y-m-01' ) ) ); // phpcs:ignore
		return (float) ( $row ?? 0.0 );
	}

	public static function can_spend( float $estimated_usd ): bool {
		$settings = get_option( 'ac_schema_settings', [] );
		$daily    = (float) ( $settings['daily_spend_cap_usd'] ?? 5.0 );
		$monthly  = (float) ( $settings['monthly_spend_cap_usd'] ?? 50.0 );
		return ( self::spend_today_usd() + $estimated_usd ) <= $daily
			&& ( self::spend_month_usd() + $estimated_usd ) <= $monthly;
	}
}
