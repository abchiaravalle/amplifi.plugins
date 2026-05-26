<?php
/**
 * Applier contract.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An Applier commits a single approved suggestion to WordPress and writes
 * a previous-value snapshot for undo.
 */
interface Amplifi_Optimize_Applier_Interface {

	/**
	 * Returns the fix_type slug this applier handles.
	 */
	public function fix_type(): string;

	/**
	 * Applies a single suggestion row.
	 *
	 * @param array<string,mixed> $suggestion Decoded suggestion row.
	 * @return array{ok:bool,error?:string,snapshot?:mixed}
	 */
	public function apply( array $suggestion ): array;

	/**
	 * Reverts a previously-applied suggestion using its snapshot.
	 *
	 * @param array<string,mixed> $suggestion Decoded suggestion row.
	 * @return array{ok:bool,error?:string}
	 */
	public function undo( array $suggestion ): array;
}
