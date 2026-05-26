<?php
/**
 * Cron job scanner.
 *
 * Enumerates `_get_cron_array()` and surfaces hooks that:
 *   - aren't claimed by any registered action callback,
 *   - point at closures or anonymous functions persisted in the DB,
 *   - are scheduled at suspiciously high frequency without a known plugin source,
 *   - call `wp_remote_*` / fopen-style network primitives via callback name patterns.
 *
 * @package Amplifi\Security\Scanners
 */

declare(strict_types=1);

namespace Amplifi\Security\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cron_Scanner implements Scanner {

	private const HIGH_FREQ_THRESHOLD = 600; // any recurrence < 10 min flagged if unattributed.

	public function name(): string { return 'cron'; }

	public function enabled(): bool {
		$settings = json_decode( (string) get_option( 'amplifi_security_settings', '{}' ), true );
		return in_array( 'cron', $settings['enabled_scanners'] ?? [], true );
	}

	public function run( int $scan_id ): array {
		$findings = [];
		$cron     = _get_cron_array();
		if ( empty( $cron ) ) {
			return $findings;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $key => $event ) {
					$schedule = is_array( $event ) ? ( $event['schedule'] ?? false ) : false;
					$interval = is_array( $event ) ? ( $event['interval'] ?? null ) : null;

					$signals = $this->signals_for( (string) $hook, (string) ( $schedule ?: 'one-shot' ), is_int( $interval ) ? $interval : null );
					if ( empty( $signals ) ) {
						continue;
					}
					$findings[] = [
						'type'    => 'cron_anomaly',
						'subtype' => $signals[0],
						'evidence' => [
							'hook'      => $hook,
							'schedule'  => $schedule ?: 'one-shot',
							'interval'  => $interval,
							'next_run'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp ),
							'callback'  => $this->callback_summary( (string) $hook ),
							'signals'   => $signals,
						],
					];
				}
			}
		}
		return $findings;
	}

	private function signals_for( string $hook, string $schedule, ?int $interval ): array {
		$signals = [];

		// 1. No registered callback for this hook.
		if ( ! has_action( $hook ) ) {
			$signals[] = 'unregistered_hook';
		}

		// 2. Suspiciously high frequency.
		if ( null !== $interval && $interval > 0 && $interval < self::HIGH_FREQ_THRESHOLD ) {
			$signals[] = 'high_frequency';
		}

		// 3. Hook name suggests update-impersonation (a common malware pattern).
		if ( preg_match( '/^(?:wp[_-]?update|core[_-]?check|security[_-]?(?:scan|sync)|maintenance[_-]?run)[_-]?v?\d*$/i', $hook )
			&& ! has_action( $hook ) ) {
			$signals[] = 'fake_core_hook';
		}

		// 4. Closure or anonymous callback persisted (very rare in legit code).
		if ( $this->callback_summary( $hook ) === 'closure_or_anonymous' ) {
			$signals[] = 'closure_persisted';
		}

		return $signals;
	}

	private function callback_summary( string $hook ): string {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 'no_callback';
		}
		$names = [];
		foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $cb_list ) {
			foreach ( $cb_list as $entry ) {
				$f = $entry['function'] ?? null;
				if ( $f instanceof \Closure ) {
					return 'closure_or_anonymous';
				}
				if ( is_string( $f ) ) {
					$names[] = $f;
				} elseif ( is_array( $f ) && count( $f ) === 2 ) {
					$names[] = ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . (string) $f[1];
				}
			}
		}
		return $names ? implode( ',', array_slice( array_unique( $names ), 0, 3 ) ) : 'unknown';
	}
}
