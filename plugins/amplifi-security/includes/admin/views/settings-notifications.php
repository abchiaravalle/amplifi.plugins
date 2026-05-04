<?php
/**
 * @package Amplifi\Security\Admin\Views
 *
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recipients      = implode( ', ', (array) ( $settings['notification_recipients'] ?? [] ) );
$digest_hour     = (int)    ( $settings['digest_hour_utc']      ?? 13 );
$sms_quota       = (int)    ( $settings['sms_quota_per_day']    ?? 3 );
$qh              = (array)  ( $settings['quiet_hours']          ?? [] );
$matrix          = (array)  ( $settings['routing_matrix']       ?? [] );

$categories = [ 'malware', 'core_tampering', 'plugin_theme_tampering', 'privilege_escalation', 'content_injection', 'auth_anomaly', 'vulnerability', 'cron_anomaly', 'config_change', 'other' ];
$verdicts   = [ 'confirmed', 'likely', 'worth_reviewing', 'benign' ];
$channels   = [
	'email_sms' => 'email + sms',
	'email'     => 'email',
	'digest'    => 'digest',
	'log'       => 'log',
	'mute'      => 'mute',
];
?>

<table class="form-table">
	<tr>
		<th><label><?php esc_html_e( 'Recipients', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="text" name="notification_recipients" value="<?php echo esc_attr( $recipients ); ?>" class="large-text" placeholder="ops@example.com, dev@example.com" />
			<p class="description"><?php esc_html_e( 'Comma-separated list. Defaults to admin_email if empty.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Digest hour (UTC)', 'amplifi-security' ); ?></label></th>
		<td><input type="number" min="0" max="23" name="digest_hour_utc" value="<?php echo esc_attr( (string) $digest_hour ); ?>" /></td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Quiet hours (UTC)', 'amplifi-security' ); ?></label></th>
		<td>
			<label><input type="checkbox" name="quiet_hours_enabled" <?php checked( ! empty( $qh['enabled'] ) ); ?> /> <?php esc_html_e( 'Enabled', 'amplifi-security' ); ?></label>
			<?php esc_html_e( 'from', 'amplifi-security' ); ?>
			<input type="number" min="0" max="23" name="quiet_hours_start" value="<?php echo esc_attr( (string) ( $qh['start'] ?? 22 ) ); ?>" style="width:60px" />
			<?php esc_html_e( 'to', 'amplifi-security' ); ?>
			<input type="number" min="0" max="23" name="quiet_hours_end" value="<?php echo esc_attr( (string) ( $qh['end'] ?? 7 ) ); ?>" style="width:60px" />
			<p class="description"><?php esc_html_e( 'Confirmed-tier alerts pierce quiet hours.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'SMS quota per day', 'amplifi-security' ); ?></label></th>
		<td><input type="number" min="0" max="3" name="sms_quota_per_day" value="<?php echo esc_attr( (string) $sms_quota ); ?>" />
			<p class="description"><?php esc_html_e( 'Hardcoded ceiling: 3.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
</table>

<h2><?php esc_html_e( 'Routing matrix', 'amplifi-security' ); ?></h2>
<p class="description"><?php esc_html_e( 'For each (category × verdict), pick the channel. Confirmed malware cannot be muted.', 'amplifi-security' ); ?></p>
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Category', 'amplifi-security' ); ?></th>
			<?php foreach ( $verdicts as $v ) : ?>
				<th><?php echo esc_html( $v ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $categories as $cat ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html( $cat ); ?></th>
			<?php foreach ( $verdicts as $verd ) :
				$current = (string) ( $matrix[ $cat ][ $verd ] ?? 'log' );
				$disabled = ( 'malware' === $cat && 'confirmed' === $verd );
				?>
				<td>
					<select name="routing_matrix[<?php echo esc_attr( $cat ); ?>][<?php echo esc_attr( $verd ); ?>]">
						<?php foreach ( $channels as $ch_key => $ch_label ) :
							if ( $disabled && in_array( $ch_key, [ 'mute', 'log', 'digest' ], true ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $ch_key ); ?>" <?php selected( $current, $ch_key ); ?>><?php echo esc_html( $ch_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			<?php endforeach; ?>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
