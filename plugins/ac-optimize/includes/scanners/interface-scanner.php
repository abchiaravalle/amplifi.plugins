<?php
/**
 * Scanner contract.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Scanner finds candidates for a single fix type and writes pending
 * suggestion rows. It does not call Claude.
 */
interface Amplifi_Optimize_Scanner_Interface {

	/**
	 * Returns the fix_type slug this scanner produces.
	 */
	public function fix_type(): string;

	/**
	 * Scans the site for candidates and inserts pending rows.
	 *
	 * @param array{limit?:int,offset?:int} $args Scan args.
	 * @return array{inserted:int,examined:int,skipped:int}
	 */
	public function scan( array $args = array() ): array;
}
