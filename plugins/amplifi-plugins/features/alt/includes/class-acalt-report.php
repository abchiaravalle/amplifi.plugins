<?php
/**
 * Daily report email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Report {

	public static function send() {
		$settings = get_option( 'acalt_settings', array() );
		if ( empty( $settings['report_enabled'] ) ) {
			return;
		}
		$to = isset( $settings['report_email'] ) ? sanitize_email( $settings['report_email'] ) : '';
		if ( empty( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$date      = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$subject   = sprintf( '[amplifi.alt] Daily report — %s — %s', $site_name, $date );

		$body_text = self::build_body_text( $date );
		$body_html = self::build_body_html( $date );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		wp_mail( $to, $subject, $body_html, array(
			'X-AmpliFi-Plugin: amplifi.alt',
		) );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );

		// Also send plain text fallback note in headers? wp_mail does not
		// natively multipart, so we just rely on HTML. Plain text body is
		// retained for any future SMTP plugin that builds a multipart variant.
		unset( $body_text );
	}

	public static function html_content_type() {
		return 'text/html';
	}

	private static function build_body_text( $date ) {
		$stats    = get_option( 'acalt_daily_stats', array() );
		$y        = $stats[ $date ] ?? array();
		$counts   = ACALT_Queue::counts();
		$settings = get_option( 'acalt_settings', array() );
		$cap      = (float) ( $settings['daily_spend_cap_usd'] ?? 0 );
		$today    = ACALT_Generator::today_key();
		$today_sp = (float) ( $stats[ $today ]['cost_usd'] ?? 0 );

		$lines   = array();
		$lines[] = 'amplifi.alt — Daily Report';
		$lines[] = 'Date: ' . $date;
		$lines[] = '';
		$lines[] = sprintf( 'Generated: %d', $y['generated'] ?? 0 );
		$lines[] = sprintf( 'Failed:    %d', $y['failed'] ?? 0 );
		$lines[] = sprintf( 'Skipped:   %d', $y['skipped'] ?? 0 );
		$lines[] = sprintf( 'Cost USD:  $%.4f', $y['cost_usd'] ?? 0 );
		$lines[] = '';
		$lines[] = sprintf( 'Pending queue: %d', $counts['pending'] );
		$lines[] = sprintf( "Today's spend: $%.4f (cap $%.2f)", $today_sp, $cap );

		return implode( "\n", $lines );
	}

	private static function build_body_html( $date ) {
		$stats    = get_option( 'acalt_daily_stats', array() );
		$y        = $stats[ $date ] ?? array();
		$counts   = ACALT_Queue::counts();
		$settings = get_option( 'acalt_settings', array() );
		$cap      = (float) ( $settings['daily_spend_cap_usd'] ?? 0 );
		$today    = ACALT_Generator::today_key();
		$today_sp = (float) ( $stats[ $today ]['cost_usd'] ?? 0 );

		// Last 7 days table.
		$rows7 = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$d   = gmdate( 'Y-m-d', strtotime( "-{$i} day" ) );
			$s   = $stats[ $d ] ?? array();
			$rows7[] = sprintf(
				'<tr><td>%s</td><td style="text-align:right;">%d</td><td style="text-align:right;">%d</td><td style="text-align:right;">%d</td><td style="text-align:right;">$%.4f</td></tr>',
				esc_html( $d ),
				(int) ( $s['generated'] ?? 0 ),
				(int) ( $s['failed']    ?? 0 ),
				(int) ( $s['skipped']   ?? 0 ),
				(float) ( $s['cost_usd'] ?? 0 )
			);
		}

		// Top 5 failures in the last 24h.
		global $wpdb;
		$table = ACALT_Queue::table_name();
		$fails = $wpdb->get_results(
			"SELECT attachment_id, last_error, updated_at FROM {$table}
			 WHERE status='failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 ORDER BY updated_at DESC LIMIT 5"
		);
		$fail_rows = array();
		foreach ( $fails as $f ) {
			$edit_url    = admin_url( 'post.php?post=' . (int) $f->attachment_id . '&action=edit' );
			$fail_rows[] = sprintf(
				'<tr><td><a href="%s">#%d</a></td><td>%s</td></tr>',
				esc_url( $edit_url ),
				(int) $f->attachment_id,
				esc_html( wp_trim_words( (string) $f->last_error, 24, '…' ) )
			);
		}
		if ( empty( $fail_rows ) ) {
			$fail_rows[] = '<tr><td colspan="2"><em>No failures in the last 24h.</em></td></tr>';
		}

		$cap_status = ( $cap > 0 && $today_sp >= $cap )
			? '<span style="color:#a00;font-weight:bold;">Daily cap hit</span>'
			: 'Under cap';

		ob_start();
		?>
		<div style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:640px;margin:0 auto;color:#222;">
			<h2 style="margin:0 0 4px;">amplifi.alt — Daily Report</h2>
			<p style="color:#666;margin:0 0 16px;">Date: <?php echo esc_html( $date ); ?></p>

			<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #ddd;">
				<tr style="background:#f5f5f5;">
					<th style="text-align:left;">Yesterday</th>
					<th style="text-align:right;">Count</th>
				</tr>
				<tr><td>Generated</td><td style="text-align:right;"><?php echo (int) ( $y['generated'] ?? 0 ); ?></td></tr>
				<tr><td>Failed</td><td style="text-align:right;"><?php echo (int) ( $y['failed'] ?? 0 ); ?></td></tr>
				<tr><td>Skipped</td><td style="text-align:right;"><?php echo (int) ( $y['skipped'] ?? 0 ); ?></td></tr>
				<tr><td>Cost (USD)</td><td style="text-align:right;">$<?php echo number_format( (float) ( $y['cost_usd'] ?? 0 ), 4 ); ?></td></tr>
			</table>

			<h3 style="margin-top:24px;">Last 7 days</h3>
			<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #ddd;">
				<tr style="background:#f5f5f5;">
					<th style="text-align:left;">Date</th>
					<th style="text-align:right;">Generated</th>
					<th style="text-align:right;">Failed</th>
					<th style="text-align:right;">Skipped</th>
					<th style="text-align:right;">Cost</th>
				</tr>
				<?php echo implode( '', $rows7 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</table>

			<h3 style="margin-top:24px;">Recent failures (last 24h)</h3>
			<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #ddd;">
				<tr style="background:#f5f5f5;">
					<th style="text-align:left;width:80px;">Attachment</th>
					<th style="text-align:left;">Reason</th>
				</tr>
				<?php echo implode( '', $fail_rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</table>

			<h3 style="margin-top:24px;">Queue & spend</h3>
			<p>
				Pending: <strong><?php echo (int) $counts['pending']; ?></strong><br>
				Today's spend: <strong>$<?php echo number_format( $today_sp, 4 ); ?></strong> (cap $<?php echo number_format( $cap, 2 ); ?>) — <?php echo $cap_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</p>

			<p style="color:#999;font-size:12px;margin-top:32px;">
				Sent by amplifi.alt running on <?php echo esc_html( get_bloginfo( 'name' ) ); ?>.
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
