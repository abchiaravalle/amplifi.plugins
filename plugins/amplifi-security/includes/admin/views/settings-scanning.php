<?php
/**
 * @package Amplifi\Security\Admin\Views
 *
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$intervals = [
	'two_hours'    => __( 'Every 2 hours', 'amplifi-security' ),
	'four_hours'   => __( 'Every 4 hours (default)', 'amplifi-security' ),
	'eight_hours'  => __( 'Every 8 hours', 'amplifi-security' ),
	'twelve_hours' => __( 'Every 12 hours', 'amplifi-security' ),
	'daily'        => __( 'Daily', 'amplifi-security' ),
];

$scanners = [
	'shell'         => __( 'Shell / backdoor scanner', 'amplifi-security' ),
	'integrity'     => __( 'File integrity (core + plugins + themes)', 'amplifi-security' ),
	'critical_file' => __( 'Critical files (.htaccess, wp-config, mu-plugins, dropins)', 'amplifi-security' ),
	'db_anomaly'    => __( 'DB / user lifecycle anomaly', 'amplifi-security' ),
	'auth'          => __( 'Auth anomaly (logins, brute force)', 'amplifi-security' ),
	'vuln'          => __( 'Vulnerability scanner (Wordfence Intelligence)', 'amplifi-security' ),
	'cron'          => __( 'Cron anomaly', 'amplifi-security' ),
	'rest_xmlrpc'   => __( 'REST / XML-RPC abuse', 'amplifi-security' ),
];

$current_interval = (string) ( $settings['scan_interval'] ?? 'four_hours' );
$enabled          = (array) ( $settings['enabled_scanners'] ?? [] );
$exclusions       = implode( "\n", (array) ( $settings['file_exclusions'] ?? [] ) );
$ip_allowlist     = implode( "\n", (array) ( $settings['ip_allowlist'] ?? [] ) );
?>

<table class="form-table">
	<tr>
		<th><label><?php esc_html_e( 'Scan frequency', 'amplifi-security' ); ?></label></th>
		<td>
			<select name="scan_interval">
				<?php foreach ( $intervals as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_interval, $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Enabled scanners', 'amplifi-security' ); ?></label></th>
		<td>
			<?php foreach ( $scanners as $key => $label ) : ?>
				<label style="display:block">
					<input type="checkbox" name="enabled_scanners[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled, true ) ); ?>/>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'File-scan exclusions', 'amplifi-security' ); ?></label></th>
		<td>
			<textarea name="file_exclusions" rows="4" class="large-text code"><?php echo esc_textarea( $exclusions ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Glob patterns, one per line. Default excludes wp-content/cache and common backup dirs.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'IP allowlist', 'amplifi-security' ); ?></label></th>
		<td>
			<textarea name="ip_allowlist" rows="3" class="large-text code"><?php echo esc_textarea( $ip_allowlist ); ?></textarea>
			<p class="description"><?php esc_html_e( 'IPs that won\'t fire auth anomaly findings. One per line.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
</table>
