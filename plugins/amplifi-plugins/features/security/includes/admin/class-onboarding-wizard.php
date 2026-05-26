<?php
/**
 * Four-step onboarding wizard.
 *
 * Steps:
 *   1. API keys (Anthropic + SMTP2Go required)
 *   2. Notification recipients
 *   3. Log sources (optional, informational)
 *   4. First deep scan
 *
 * Reachable via `?wizard=1` (optionally `&step=N`) on the Settings page until
 * `amplifi_security_onboarding_complete` is set.
 *
 * Form steps reuse `Settings_Page::handle_save()` by posting to `admin-post.php`
 * with `wizard_next_step` set; the save handler redirects to the next wizard
 * step instead of back to the tab.
 *
 * @package Amplifi\Security\Admin
 */

declare(strict_types=1);

namespace Amplifi\Security\Admin;

use Amplifi\Security\Alerts\Smtp2Go_Client;
use Amplifi\Security\Audit\Audit_Logger;
use Amplifi\Security\Crypto\Secret_Store;
use Amplifi\Security\Scanners\Scan_Runner;
use Amplifi\Security\Triage\Anthropic_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Onboarding_Wizard {

	public const TOTAL_STEPS = 4;

	public static function register(): void {
		add_action( 'admin_post_amplifi_security_complete_onboarding', [ self::class, 'complete' ] );
		add_action( 'admin_post_amplifi_security_run_first_scan',      [ self::class, 'run_first_scan' ] );
	}

	public static function is_active(): bool {
		return ! get_option( 'amplifi_security_onboarding_complete' );
	}

	public static function complete(): void {
		check_admin_referer( 'amplifi_security_complete_onboarding' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		update_option( 'amplifi_security_onboarding_complete', 1, false );
		Audit_Logger::log( 'onboarding_complete', [] );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::PAGE_HEALTH ) );
		exit;
	}

	public static function run_first_scan(): void {
		check_admin_referer( 'amplifi_security_run_first_scan' );
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden.', 'amplifi-security' ) );
		}
		// Schedule for immediate run rather than blocking the request.
		wp_schedule_single_event( time() + 5, Scan_Runner::HOOK );
		// Mark onboarding complete; the scan runs in the background.
		update_option( 'amplifi_security_onboarding_complete', 1, false );
		Audit_Logger::log( 'first_scan_queued', [] );
		Audit_Logger::log( 'onboarding_complete', [] );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::PAGE_HEALTH . '&first_scan_queued=1' ) );
		exit;
	}

	/**
	 * Render the wizard chrome and current step body.
	 *
	 * Called from Settings_Page::render() when `wizard=1` is in the query.
	 */
	public static function render( int $step, array $settings ): void {
		$step = max( 1, min( self::TOTAL_STEPS, $step ) );

		echo '<div class="wrap amplifi-security amplifi-wizard">';
		echo '<h1>' . esc_html__( 'amplifi.security — Setup', 'amplifi-security' ) . '</h1>';
		self::render_progress( $step );

		switch ( $step ) {
			case 1:
				self::render_step_keys();
				break;
			case 2:
				self::render_step_recipients( $settings );
				break;
			case 3:
				self::render_step_log_sources();
				break;
			case 4:
				self::render_step_first_scan();
				break;
		}

		echo '</div>';
	}

	private static function render_progress( int $step ): void {
		$labels = [
			1 => __( 'API keys', 'amplifi-security' ),
			2 => __( 'Notifications', 'amplifi-security' ),
			3 => __( 'Log sources', 'amplifi-security' ),
			4 => __( 'First scan', 'amplifi-security' ),
		];
		echo '<ol class="amplifi-wizard-steps">';
		foreach ( $labels as $i => $label ) {
			$cls = $i === $step ? 'is-active' : ( $i < $step ? 'is-done' : '' );
			printf(
				'<li class="%1$s"><span class="step-num">%2$d</span> %3$s</li>',
				esc_attr( $cls ),
				(int) $i,
				esc_html( $label )
			);
		}
		echo '</ol>';
	}

	private static function render_wizard_form_open( int $next_step, string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="amplifi-wizard-form">';
		wp_nonce_field( 'amplifi_security_save_settings' );
		echo '<input type="hidden" name="action" value="amplifi_security_save_settings" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<input type="hidden" name="wizard_next_step" value="' . (int) $next_step . '" />';
	}

	private static function render_step_keys(): void {
		$ant_set  = '' !== Anthropic_Client::api_key();
		$smtp_set = '' !== Smtp2Go_Client::api_key();

		echo '<h2>' . esc_html__( 'Step 1 — API keys', 'amplifi-security' ) . '</h2>';
		echo '<p>' . esc_html__( 'Anthropic and SMTP2Go are required. Triage uses Anthropic; alert email goes through SMTP2Go.', 'amplifi-security' ) . '</p>';

		self::render_wizard_form_open( 2, 'connections' );
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Anthropic API key', 'amplifi-security' ); ?></label></th>
				<td>
					<input type="password" name="anthropic_key" value="<?php echo $ant_set ? esc_attr( Secret_Store::mask( Anthropic_Client::api_key() ) ) : ''; ?>" placeholder="sk-ant-..." class="regular-text" autocomplete="off" required />
					<p class="description"><?php esc_html_e( 'Get one at console.anthropic.com.', 'amplifi-security' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'SMTP2Go API key', 'amplifi-security' ); ?></label></th>
				<td>
					<input type="password" name="smtp2go_key" value="<?php echo $smtp_set ? esc_attr( Secret_Store::mask( Smtp2Go_Client::api_key() ) ) : ''; ?>" placeholder="api-..." class="regular-text" autocomplete="off" required />
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'SMTP2Go sender (verified)', 'amplifi-security' ); ?></label></th>
				<td>
					<input type="email" name="smtp2go_sender" value="<?php echo esc_attr( Smtp2Go_Client::sender() ); ?>" class="regular-text" required />
				</td>
			</tr>
		</table>
		<p class="amplifi-wizard-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'amplifi-security' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ) ); ?>" class="button-link"><?php esc_html_e( 'Skip wizard', 'amplifi-security' ); ?></a>
		</p>
		</form>
		<?php
	}

	private static function render_step_recipients( array $settings ): void {
		$recipients  = implode( ', ', (array) ( $settings['notification_recipients'] ?? [] ) );
		$digest_hour = (int) ( $settings['digest_hour_utc'] ?? 13 );

		echo '<h2>' . esc_html__( 'Step 2 — Notifications', 'amplifi-security' ) . '</h2>';
		echo '<p>' . esc_html__( 'Where should alerts and the daily digest go? You can fine-tune the routing matrix later under Settings → Notifications.', 'amplifi-security' ) . '</p>';

		self::render_wizard_form_open( 3, 'notifications' );
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Recipients', 'amplifi-security' ); ?></label></th>
				<td>
					<input type="text" name="notification_recipients" value="<?php echo esc_attr( $recipients ); ?>" class="large-text" placeholder="ops@example.com, dev@example.com" required />
					<p class="description"><?php esc_html_e( 'Comma-separated. At least one address.', 'amplifi-security' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Digest hour (UTC)', 'amplifi-security' ); ?></label></th>
				<td><input type="number" min="0" max="23" name="digest_hour_utc" value="<?php echo esc_attr( (string) $digest_hour ); ?>" /></td>
			</tr>
		</table>
		<p class="amplifi-wizard-actions">
			<a href="<?php echo esc_url( self::step_url( 1 ) ); ?>" class="button"><?php esc_html_e( 'Back', 'amplifi-security' ); ?></a>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'amplifi-security' ); ?></button>
		</p>
		</form>
		<?php
	}

	private static function render_step_log_sources(): void {
		echo '<h2>' . esc_html__( 'Step 3 — Log sources (optional)', 'amplifi-security' ) . '</h2>';
		echo '<p>' . esc_html__( 'Forensic correlation works without external logs, but adding access/error log URLs lets the triage prompt cross-reference IPs and timestamps when classifying findings.', 'amplifi-security' ) . '</p>';
		echo '<p>' . esc_html__( 'You can add log sources later under Settings → Logs. Skip this step for now.', 'amplifi-security' ) . '</p>';
		?>
		<p class="amplifi-wizard-actions">
			<a href="<?php echo esc_url( self::step_url( 2 ) ); ?>" class="button"><?php esc_html_e( 'Back', 'amplifi-security' ); ?></a>
			<a href="<?php echo esc_url( self::step_url( 4 ) ); ?>" class="button button-primary"><?php esc_html_e( 'Continue', 'amplifi-security' ); ?></a>
		</p>
		<?php
	}

	private static function render_step_first_scan(): void {
		echo '<h2>' . esc_html__( 'Step 4 — Run the first scan', 'amplifi-security' ) . '</h2>';
		echo '<p>' . esc_html__( 'A deep scan establishes a file-integrity baseline and triggers the first triage call. It runs in the background and typically finishes within a few minutes.', 'amplifi-security' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'amplifi_security_run_first_scan' ); ?>
			<input type="hidden" name="action" value="amplifi_security_run_first_scan" />
			<p class="amplifi-wizard-actions">
				<a href="<?php echo esc_url( self::step_url( 3 ) ); ?>" class="button"><?php esc_html_e( 'Back', 'amplifi-security' ); ?></a>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Queue first scan and finish', 'amplifi-security' ); ?></button>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
			<?php wp_nonce_field( 'amplifi_security_complete_onboarding' ); ?>
			<input type="hidden" name="action" value="amplifi_security_complete_onboarding" />
			<p>
				<button type="submit" class="button-link"><?php esc_html_e( 'Skip first scan and finish setup', 'amplifi-security' ); ?></button>
			</p>
		</form>
		<?php
	}

	public static function step_url( int $step ): string {
		return add_query_arg(
			[ 'page' => Admin::PAGE_SETTINGS, 'wizard' => 1, 'step' => $step ],
			admin_url( 'admin.php' )
		);
	}
}
