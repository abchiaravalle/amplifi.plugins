<?php
/**
 * Triage engine.
 *
 * Pulls `pending_triage` findings, batches them (max 50 / 100 KB), runs the
 * prompt-injection honeypot, calls `Anthropic_Client`, parses verdicts, and
 * writes them back to the findings table. On API failure or invalid output,
 * falls back to "naive mode" — high-confidence local signatures get
 * `confirmed`, everything else stays pending for the next batch.
 *
 * @package Amplifi\Security\Triage
 */

declare(strict_types=1);

namespace Amplifi\Security\Triage;

use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Log_Sources\Log_Fetcher;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Triage_Engine {

	public const MAX_BATCH         = 50;
	public const MAX_PAYLOAD_BYTES = 100_000;
	public const FAILURE_THRESHOLD = 3;

	/**
	 * Triage all pending findings. Optionally scoped to a scan.
	 */
	public static function triage_pending( ?int $scan_id = null ): void {
		$pending = self::load_pending( $scan_id );
		if ( empty( $pending ) ) {
			return;
		}

		// Cache lookup pass — short-circuit benign cache hits.
		$to_send = [];
		foreach ( $pending as $f ) {
			$evidence = self::decode_evidence( $f );
			$key      = Verdict_Cache::key_for( $f['type'], $evidence );
			$hit      = Verdict_Cache::lookup( $key );
			if ( $hit && in_array( $hit['verdict'], [ 'benign' ], true ) ) {
				self::apply_verdict(
					(int) $f['id'],
					[
						'category'   => 'other',
						'verdict'    => 'benign',
						'confidence' => 0.9,
						'rationale'  => 'cached: ' . $hit['rationale'],
						'recommended_first_action' => 'no action — previously triaged benign',
						'evidence_cited' => [ 'cache_hit' ],
					]
				);
				continue;
			}
			$to_send[] = [ 'row' => $f, 'evidence' => $evidence, 'cache_key' => $key ];
		}

		if ( empty( $to_send ) ) {
			return;
		}

		// Honeypot pre-check — short-circuit obvious prompt injection.
		$still_to_send = [];
		foreach ( $to_send as $entry ) {
			$blob = wp_json_encode( $entry['evidence'] );
			if ( Prompt_Builder::detect_prompt_injection( (string) $blob ) ) {
				self::apply_verdict(
					(int) $entry['row']['id'],
					[
						'category'       => 'other',
						'category_label' => 'prompt_injection_attempt',
						'verdict'        => 'confirmed',
						'confidence'     => 0.99,
						'rationale'      => 'Evidence contains prompt-injection canary phrases — not sent to LLM. Treat as adversarial.',
						'recommended_first_action' => 'Quarantine the source file/log range and investigate as confirmed compromise.',
						'evidence_cited' => [ 'honeypot_canary' ],
					]
				);
				continue;
			}
			$still_to_send[] = $entry;
		}

		if ( empty( $still_to_send ) ) {
			return;
		}

		// Batch up to MAX_BATCH / MAX_PAYLOAD_BYTES.
		$batches = self::batch_for_payload( $still_to_send );

		// Build the heavy context ONCE per scan and reuse across all batches —
		// the plugin list and logs are identical for every batch in a run.
		$site_context = self::site_context();
		$logs         = Log_Fetcher::fetch_all( (int) ( self::MAX_PAYLOAD_BYTES / 4 ) );

		foreach ( $batches as $batch ) {
			$emergency = self::has_emergency( $batch );
			$cap_check = Spend_Tracker::check_caps( $emergency );
			if ( ! $cap_check['allowed'] ) {
				// Leave findings pending; next run after cap reset will pick them up.
				return;
			}

			try {
				self::dispatch_batch( $batch, $site_context, $logs );
				self::reset_failure_count();
			} catch ( \Throwable $e ) {
				Audit_Logger::log(
					'triage_call_failed',
					[ 'message' => $e->getMessage(), 'batch_size' => count( $batch ) ]
				);
				$count = self::increment_failure_count();
				if ( $count >= self::FAILURE_THRESHOLD ) {
					self::naive_fallback( $batch );
				}
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function load_pending( ?int $scan_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';
		if ( null !== $scan_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, scan_id, type, subtype, evidence FROM {$table}
					 WHERE status = 'pending_triage' AND scan_id = %d
					 ORDER BY id ASC LIMIT %d",
					$scan_id,
					self::MAX_BATCH * 4
				),
				ARRAY_A
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, scan_id, type, subtype, evidence FROM {$table}
				 WHERE status = 'pending_triage'
				 ORDER BY id ASC LIMIT %d",
				self::MAX_BATCH * 4
			),
			ARRAY_A
		);
	}

	private static function decode_evidence( array $row ): array {
		$decoded = json_decode( (string) ( $row['evidence'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private static function batch_for_payload( array $entries ): array {
		$batches = [];
		$current = [];
		$bytes   = 0;
		foreach ( $entries as $entry ) {
			$size = strlen( (string) wp_json_encode( $entry['evidence'] ) );
			if ( count( $current ) >= self::MAX_BATCH || ( $bytes + $size ) > self::MAX_PAYLOAD_BYTES ) {
				$batches[] = $current;
				$current   = [];
				$bytes     = 0;
			}
			$current[] = $entry;
			$bytes    += $size;
		}
		if ( ! empty( $current ) ) {
			$batches[] = $current;
		}
		return $batches;
	}

	private static function has_emergency( array $batch ): bool {
		// Heuristic: any finding with combined_score >= 10 OR shell_in_uploads.
		foreach ( $batch as $entry ) {
			$ev = $entry['evidence'];
			if ( ( $entry['row']['type'] ?? '' ) === 'shell_in_uploads' ) {
				return true;
			}
			if ( (int) ( $ev['combined_score'] ?? 0 ) >= 10 ) {
				return true;
			}
		}
		return false;
	}

	private static function dispatch_batch( array $batch, array $site_context, string $logs ): void {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		$model    = (string) ( $settings['model']       ?? Anthropic_Client::DEFAULT_MODEL );
		$tone     = (string) ( $settings['sensitivity'] ?? 'balanced' );

		$findings_for_prompt = array_map(
			static fn( array $entry ): array => [
				'id'       => (int) $entry['row']['id'],
				'type'     => (string) $entry['row']['type'],
				'subtype'  => (string) ( $entry['row']['subtype'] ?? '' ),
				'evidence' => $entry['evidence'],
			],
			$batch
		);

		$user_msg = Prompt_Builder::user_message( $site_context, $findings_for_prompt, $logs );
		$system   = Prompt_Builder::system_prompt( $tone );

		// Persist the redacted last-payload for the debug viewer.
		update_option(
			'amplifi_security_last_triage_payload',
			[
				'when'    => current_time( 'mysql', true ),
				'system'  => $system,
				'user'    => $user_msg,
				'model'   => $model,
			],
			false
		);

		$result = Anthropic_Client::call( $system, $user_msg, Prompt_Builder::tool_schema(), $model );

		Spend_Tracker::record( $result['model'], $result['usage']['input_tokens'], $result['usage']['output_tokens'] );

		Audit_Logger::log(
			'triage_call_succeeded',
			[
				'model'        => $result['model'],
				'tokens_in'    => $result['usage']['input_tokens'],
				'tokens_out'   => $result['usage']['output_tokens'],
				'batch_size'   => count( $batch ),
			]
		);

		$verdicts = (array) ( $result['content']['verdicts'] ?? [] );
		$summary  = (string) ( $result['content']['scan_summary'] ?? '' );

		// Map by finding_id for stable lookup.
		$by_id = [];
		foreach ( $verdicts as $v ) {
			if ( isset( $v['finding_id'] ) ) {
				$by_id[ (int) $v['finding_id'] ] = $v;
			}
		}

		foreach ( $batch as $entry ) {
			$id   = (int) $entry['row']['id'];
			$verd = $by_id[ $id ] ?? null;
			if ( null === $verd ) {
				continue; // model didn't return for this id; leave pending
			}
			self::apply_verdict( $id, $verd );
			if ( ( $verd['verdict'] ?? '' ) === 'benign' ) {
				Verdict_Cache::store( $entry['cache_key'], 'benign', (string) ( $verd['rationale'] ?? '' ) );
			}
		}

		if ( '' !== $summary ) {
			update_option( 'amplifi_security_last_scan_summary', $summary, false );
		}
	}

	private static function apply_verdict( int $finding_id, array $verdict ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'amplifi_security_findings';

		$category = (string) ( $verdict['category'] ?? 'other' );
		if ( ! in_array( $category, Prompt_Builder::CATEGORIES, true ) ) {
			$category = 'other';
		}
		$verd = (string) ( $verdict['verdict'] ?? 'worth_reviewing' );
		if ( ! in_array( $verd, Prompt_Builder::VERDICTS, true ) ) {
			$verd = 'worth_reviewing';
		}

		$wpdb->update(
			$table,
			[
				'category'           => $category,
				'category_label'     => isset( $verdict['category_label'] ) && '' !== $verdict['category_label']
					? (string) $verdict['category_label']
					: null,
				'verdict'            => $verd,
				'confidence'         => isset( $verdict['confidence'] ) ? (float) $verdict['confidence'] : null,
				'rationale'          => isset( $verdict['rationale'] ) ? (string) $verdict['rationale'] : null,
				'recommended_action' => isset( $verdict['recommended_first_action'] ) ? (string) $verdict['recommended_first_action'] : null,
				'status'             => 'triaged',
				'triaged_at'         => current_time( 'mysql', true ),
			],
			[ 'id' => $finding_id ]
		);
	}

	private static function naive_fallback( array $batch ): void {
		foreach ( $batch as $entry ) {
			$type = (string) $entry['row']['type'];
			$ev   = $entry['evidence'];
			$verd = 'worth_reviewing';
			$cat  = 'other';
			$rat  = 'AI triage unavailable; promoted by local heuristics.';
			$act  = 'AI triage is offline. Review the finding manually and check the dashboard for the API status.';

			$has_shell_match = false;
			foreach ( (array) ( $ev['matches'] ?? [] ) as $m ) {
				if ( ( $m['category'] ?? '' ) === 'shell' ) {
					$has_shell_match = true;
					break;
				}
			}

			if ( 'shell_in_uploads' === $type || $has_shell_match || (int) ( $ev['combined_score'] ?? 0 ) >= 10 ) {
				$verd = 'confirmed';
				$cat  = 'malware';
			} elseif ( 'file_integrity' === $type && ( $ev['context'] ?? '' ) === 'wp_core_file' ) {
				$verd = 'likely';
				$cat  = 'core_tampering';
			}

			self::apply_verdict(
				(int) $entry['row']['id'],
				[
					'category'   => $cat,
					'verdict'    => $verd,
					'confidence' => 0.6,
					'rationale'  => $rat,
					'recommended_first_action' => $act,
					'evidence_cited' => [ 'naive_mode' ],
				]
			);
		}
		Audit_Logger::log( 'triage_naive_fallback', [ 'batch_size' => count( $batch ) ] );
	}

	private static function increment_failure_count(): int {
		$n = (int) get_option( 'amplifi_security_triage_failures', 0 ) + 1;
		update_option( 'amplifi_security_triage_failures', $n, false );
		return $n;
	}

	private static function reset_failure_count(): void {
		update_option( 'amplifi_security_triage_failures', 0, false );
	}

	private static function site_context(): array {
		global $wp_version;

		$active = (array) get_option( 'active_plugins', [] );
		$plugins = [];
		foreach ( $active as $basename ) {
			$file = WP_PLUGIN_DIR . '/' . $basename;
			if ( ! is_file( $file ) ) {
				continue;
			}
			$data = @get_file_data( $file, [ 'Name' => 'Plugin Name', 'Version' => 'Version' ] );
			$plugins[] = [
				'slug'    => dirname( $basename ) ?: basename( $basename, '.php' ),
				'name'    => $data['Name']    ?? '',
				'version' => $data['Version'] ?? '',
			];
		}

		return [
			'wp_version'      => $wp_version ?? get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'site_url'        => get_site_url(),
			'home_url'        => home_url(),
			'multisite'       => is_multisite(),
			'is_woocommerce'  => class_exists( 'WooCommerce' ),
			'active_plugins'  => array_slice( $plugins, 0, 60 ),
			'active_theme'    => wp_get_theme()->get_stylesheet(),
			'admin_count'     => count_users()['avail_roles']['administrator'] ?? 0,
		];
	}
}
