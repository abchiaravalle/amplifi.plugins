<?php
/**
 * Scanner contract.
 *
 * Each scanner module implements this interface and is registered with
 * `Scan_Runner` (which orchestrates them on the WP-Cron schedule).
 *
 * Scanners must:
 *   - return findings as plain associative arrays from `run()`,
 *   - not write directly to the findings table (the runner does that),
 *   - keep individual `run()` calls under ~30s wall time on a typical host
 *     (anything bigger should use `WP_Background_Process`-style batching),
 *   - never block on external network calls without a timeout.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Scanner {

	/**
	 * Stable scanner key (snake_case). Used in settings, audit, and the
	 * `scans.scanners_run` JSON column.
	 */
	public function name(): string;

	/**
	 * Whether this scanner is enabled in settings. The runner respects this.
	 */
	public function enabled(): bool;

	/**
	 * Produce findings.
	 *
	 * @param int $scan_id Current scan run id.
	 * @return array<int,array<string,mixed>> List of finding rows.
	 *         Each row must contain `type` (string), `evidence` (array),
	 *         and may contain `subtype` (string).
	 */
	public function run( int $scan_id ): array;
}
