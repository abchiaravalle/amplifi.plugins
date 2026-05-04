<?php
/**
 * @package Amplifi\Security\Admin\Views
 *
 * @var array $settings
 */

use Amplifi\Security\Triage\Anthropic_Client;
use Amplifi\Security\Triage\Spend_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$model     = (string) ( $settings['model']             ?? Anthropic_Client::DEFAULT_MODEL );
$tone      = (string) ( $settings['sensitivity']       ?? 'balanced' );
$daily_cap = (float)  ( $settings['daily_spend_cap_usd']   ?? 2.0 );
$month_cap = (float)  ( $settings['monthly_spend_cap_usd'] ?? 30.0 );
$summary   = Spend_Tracker::summary();
$last_payload = (array) get_option( 'amplifi_security_last_triage_payload', [] );
?>

<table class="form-table">
	<tr>
		<th><label><?php esc_html_e( 'Model', 'amplifi-security' ); ?></label></th>
		<td>
			<select name="model">
				<option value="claude-haiku-4-5-20251001" <?php selected( $model, 'claude-haiku-4-5-20251001' ); ?>>Claude Haiku 4.5 — recommended</option>
				<option value="claude-sonnet-4-6"        <?php selected( $model, 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6 — advanced</option>
			</select>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Sensitivity', 'amplifi-security' ); ?></label></th>
		<td>
			<select name="sensitivity">
				<?php foreach ( [ 'conservative', 'balanced', 'aggressive' ] as $key ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tone, $key ); ?>><?php echo esc_html( ucfirst( $key ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Adjusts the rubric language sent to Claude — biases verdicts up or down on borderline cases.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Daily spend cap (USD)', 'amplifi-security' ); ?></label></th>
		<td><input type="number" step="0.01" min="0" max="50" name="daily_spend_cap_usd" value="<?php echo esc_attr( (string) $daily_cap ); ?>" /></td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Monthly spend cap (USD)', 'amplifi-security' ); ?></label></th>
		<td><input type="number" step="0.01" min="0" max="500" name="monthly_spend_cap_usd" value="<?php echo esc_attr( (string) $month_cap ); ?>" /></td>
	</tr>
</table>

<h2><?php esc_html_e( 'Spend so far', 'amplifi-security' ); ?></h2>
<p>
	Today: <strong>$<?php echo esc_html( number_format( $summary['today'], 4 ) ); ?></strong> /
	cap $<?php echo esc_html( number_format( $summary['daily_cap'], 2 ) ); ?>.
	This month: <strong>$<?php echo esc_html( number_format( $summary['month'], 4 ) ); ?></strong> /
	cap $<?php echo esc_html( number_format( $summary['monthly_cap'], 2 ) ); ?>.
	Projected month-end: $<?php echo esc_html( number_format( $summary['projected_month_end'], 2 ) ); ?>.
</p>

<h2><?php esc_html_e( 'Last triage payload (debug)', 'amplifi-security' ); ?></h2>
<?php if ( empty( $last_payload ) ) : ?>
	<p>—</p>
<?php else : ?>
	<p><strong><?php esc_html_e( 'When (UTC):', 'amplifi-security' ); ?></strong> <?php echo esc_html( (string) ( $last_payload['when'] ?? '' ) ); ?> &nbsp;
		<strong><?php esc_html_e( 'Model:', 'amplifi-security' ); ?></strong> <?php echo esc_html( (string) ( $last_payload['model'] ?? '' ) ); ?></p>
	<details>
		<summary><?php esc_html_e( 'Show system prompt', 'amplifi-security' ); ?></summary>
		<pre style="white-space:pre-wrap;max-height:300px;overflow:auto"><?php echo esc_html( (string) ( $last_payload['system'] ?? '' ) ); ?></pre>
	</details>
	<details>
		<summary><?php esc_html_e( 'Show user message', 'amplifi-security' ); ?></summary>
		<pre style="white-space:pre-wrap;max-height:400px;overflow:auto"><?php echo esc_html( (string) ( $last_payload['user'] ?? '' ) ); ?></pre>
	</details>
<?php endif; ?>
