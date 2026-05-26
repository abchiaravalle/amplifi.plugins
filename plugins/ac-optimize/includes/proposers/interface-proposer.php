<?php
/**
 * Proposer contract.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Proposer takes pending suggestions for its fix type, calls Claude, and
 * writes proposed_value / proposed_metadata back to each row.
 */
interface Amplifi_Optimize_Proposer_Interface {

	/**
	 * Returns the fix_type slug this proposer handles.
	 */
	public function fix_type(): string;

	/**
	 * Runs proposals for pending rows.
	 *
	 * @param array{limit?:int} $args Args.
	 * @return array{processed:int,failed:int}
	 */
	public function propose( array $args = array() ): array;
}
