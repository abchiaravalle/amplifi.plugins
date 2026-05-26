<?php
/**
 * WP-CLI commands for amplifi.optimize.
 *
 * @package Amplifi_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp amplifi-optimize <subcommand>`
 */
class Amplifi_Optimize_CLI_Commands {

	/**
	 * Runs a scanner.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Fix type (meta_description, alt_text, title, unpublish).
	 *
	 * [--limit=<n>]
	 * : Max candidates to examine. Default 200.
	 *
	 * [--offset=<n>]
	 * : Offset. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amplifi-optimize scan meta_description --limit=500
	 *
	 * @param string[]            $args       Positional args.
	 * @param array<string,mixed> $assoc_args Associative args.
	 */
	public function scan( array $args, array $assoc_args ): void {
		list( $type ) = $args + array( '' );
		$bundle       = Amplifi_Optimize_Plugin::instance()->get_fix_type( $type );
		if ( ! $bundle ) {
			\WP_CLI::error( "Unknown fix type: {$type}" );
		}
		$opts = array(
			'limit'  => (int) ( $assoc_args['limit'] ?? 200 ),
			'offset' => (int) ( $assoc_args['offset'] ?? 0 ),
		);
		$result = $bundle['scanner']->scan( $opts );
		\WP_CLI::success(
			sprintf(
				'Examined %d, inserted %d pending, skipped %d.',
				$result['examined'],
				$result['inserted'],
				$result['skipped']
			)
		);
	}

	/**
	 * Generates Claude proposals for pending findings.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Fix type.
	 *
	 * [--limit=<n>]
	 * : Max suggestions to propose this run. Default 25.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amplifi-optimize propose meta_description --limit=50
	 *
	 * @param string[]            $args       Positional args.
	 * @param array<string,mixed> $assoc_args Associative args.
	 */
	public function propose( array $args, array $assoc_args ): void {
		list( $type ) = $args + array( '' );
		$bundle       = Amplifi_Optimize_Plugin::instance()->get_fix_type( $type );
		if ( ! $bundle ) {
			\WP_CLI::error( "Unknown fix type: {$type}" );
		}
		$opts   = array( 'limit' => (int) ( $assoc_args['limit'] ?? 25 ) );
		$result = $bundle['proposer']->propose( $opts );
		\WP_CLI::success( sprintf( 'Proposed %d, failed %d.', $result['processed'], $result['failed'] ) );
	}

	/**
	 * Applies approved suggestions.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Fix type.
	 *
	 * [--auto]
	 * : Also apply pending (proposed but not yet approved) suggestions.
	 *
	 * [--limit=<n>]
	 * : Max to apply. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amplifi-optimize apply meta_description --auto --limit=200
	 *
	 * @param string[]            $args       Positional args.
	 * @param array<string,mixed> $assoc_args Associative args.
	 */
	public function apply( array $args, array $assoc_args ): void {
		list( $type ) = $args + array( '' );
		$plugin       = Amplifi_Optimize_Plugin::instance();
		$bundle       = $plugin->get_fix_type( $type );
		if ( ! $bundle ) {
			\WP_CLI::error( "Unknown fix type: {$type}" );
		}
		$limit  = (int) ( $assoc_args['limit'] ?? 50 );
		$auto   = ! empty( $assoc_args['auto'] );
		$status = $auto ? 'pending' : 'approved';

		$pending = $plugin->db->list( array(
			'type'     => $type,
			'status'   => $status,
			'per_page' => $limit,
			'page'     => 1,
		) );

		$applied = 0;
		$failed  = 0;
		foreach ( $pending['items'] as $row ) {
			if ( empty( $row['proposed_value'] ) && 'unpublish' !== $type ) {
				continue;
			}
			$res = $bundle['applier']->apply( $row );
			if ( empty( $res['ok'] ) ) {
				$plugin->db->update( (int) $row['id'], array(
					'status'        => 'failed',
					'error_message' => (string) ( $res['error'] ?? 'Unknown error.' ),
				) );
				$failed++;
				\WP_CLI::warning( "#{$row['id']}: " . ( $res['error'] ?? 'failed' ) );
				continue;
			}
			$plugin->db->update( (int) $row['id'], array(
				'status'            => 'applied',
				'applied_at'        => current_time( 'mysql' ),
				'previous_snapshot' => isset( $res['snapshot'] ) ? (string) $res['snapshot'] : null,
				'error_message'     => null,
			) );
			$applied++;
		}
		\WP_CLI::success( sprintf( 'Applied %d, failed %d.', $applied, $failed ) );
	}

	/**
	 * Prints a summary report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amplifi-optimize report
	 */
	public function report(): void {
		$plugin = Amplifi_Optimize_Plugin::instance();
		$rows   = array();
		foreach ( $plugin->get_fix_types() as $slug => $bundle ) {
			$counts = $plugin->db->counts_by_status( $slug );
			$rows[] = array(
				'fix_type' => $slug,
				'pending'  => (int) ( $counts['pending'] ?? 0 ),
				'approved' => (int) ( $counts['approved'] ?? 0 ),
				'applied'  => (int) ( $counts['applied'] ?? 0 ),
				'rejected' => (int) ( $counts['rejected'] ?? 0 ),
				'failed'   => (int) ( $counts['failed'] ?? 0 ),
			);
		}
		\WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'fix_type', 'pending', 'approved', 'applied', 'rejected', 'failed' )
		);

		$usage = $plugin->claude->get_usage();
		if ( $usage ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Token usage:' );
			foreach ( $usage as $model => $u ) {
				\WP_CLI::log( sprintf( '  %s — %d calls, %d input, %d output', $model, $u['calls'], $u['input'], $u['output'] ) );
			}
		}
	}
}
