<?php
/**
 * @package Amplifi\Security\Admin\Views
 *
 * @var array $settings
 */

use Amplifi\Security\Canary\Canary;
use Amplifi\Security\Stealth\Stealth_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$canary_url     = Canary::url();
$state          = Canary::current_state();
$installer_id   = (int) get_option( 'amplifi_security_installer_id', 0 );
$you_installed  = $installer_id && get_current_user_id() === $installer_id;
$preserve       = (bool) get_option( 'amplifi_security_preserve_data_on_uninstall', false );
$rotated_token  = get_transient( 'amplifi_security_unhide_token_display' );
$current_token  = (string) get_option( 'amplifi_security_unhide_token', '' );
$is_stealth     = Stealth_Mode::is_enabled();
?>

<h2><?php esc_html_e( 'Canary', 'amplifi-security' ); ?></h2>
<table class="form-table">
	<tr>
		<th><?php esc_html_e( 'Canary URL', 'amplifi-security' ); ?></th>
		<td>
			<?php if ( $canary_url ) : ?>
				<input type="text" readonly value="<?php echo esc_attr( $canary_url ); ?>" class="large-text"/>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=amplifi_security_rotate_canary' ), 'amplifi_security_rotate_canary' ) ); ?>"><?php esc_html_e( 'Rotate slug', 'amplifi-security' ); ?></a>
				<p class="description"><?php esc_html_e( 'Point an external uptime monitor (UptimeRobot, BetterStack, Uptime Kuma) at this URL. Configure it to alert if the response body changes or the URL stops responding.', 'amplifi-security' ); ?></p>
				<p>
					<strong><?php esc_html_e( 'Current state:', 'amplifi-security' ); ?></strong>
					last_scan=<?php echo esc_html( $state['last_scan'] ); ?>,
					triage_ok=<?php echo $state['triage_ok'] ? '✓' : '✗'; ?>,
					self_integrity_ok=<?php echo $state['self_integrity_ok'] ? '✓' : '✗'; ?>
				</p>
			<?php else : ?>
				<p>—</p>
			<?php endif; ?>
		</td>
	</tr>
</table>

<h2><?php esc_html_e( 'Stealth Mode', 'amplifi-security' ); ?></h2>
<p>
	<?php esc_html_e( 'Stealth Mode hides amplifi.security from the WP admin for everyone except the original installer. Files on disk and DB tables remain visible — that\'s a host concern.', 'amplifi-security' ); ?>
</p>
<?php if ( ! $you_installed ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Only the original installer can change Stealth Mode.', 'amplifi-security' ); ?></p></div>
<?php endif; ?>
<p>
	<a class="button button-primary <?php echo $you_installed ? '' : 'disabled'; ?>" href="<?php echo esc_url( $you_installed ? wp_nonce_url( admin_url( 'admin-post.php?action=amplifi_security_toggle_stealth' ), 'amplifi_security_toggle_stealth' ) : '#' ); ?>" onclick="return <?php echo $you_installed ? "confirm('" . esc_js( __( 'Stealth Mode hides this plugin from non-installer admins. Make sure you have your recovery token saved before continuing.', 'amplifi-security' ) ) . "')" : 'false'; ?>">
		<?php echo $is_stealth ? esc_html__( 'Disable Stealth', 'amplifi-security' ) : esc_html__( 'Enable Stealth', 'amplifi-security' ); ?>
	</a>
</p>

<h3><?php esc_html_e( 'Recovery token', 'amplifi-security' ); ?></h3>
<?php if ( $rotated_token ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'Save this URL — it grants emergency unhide access. It is shown only once.', 'amplifi-security' ); ?></p>
		<p><code><?php echo esc_html( add_query_arg( 'amplifi_unhide', $rotated_token, admin_url() ) ); ?></code></p>
	</div>
<?php else : ?>
	<p>
		<?php esc_html_e( 'A recovery URL exists. Rotate it to display a new value.', 'amplifi-security' ); ?>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=amplifi_security_rotate_unhide' ), 'amplifi_security_rotate_unhide' ) ); ?>"><?php esc_html_e( 'Rotate recovery token', 'amplifi-security' ); ?></a>
	</p>
<?php endif; ?>

<h2><?php esc_html_e( 'Uninstall behaviour', 'amplifi-security' ); ?></h2>
<p>
	<label><input type="checkbox" name="preserve_data_on_uninstall" <?php checked( $preserve ); ?>/>
		<?php esc_html_e( 'Preserve data on uninstall (keep findings, audit log, baseline if I delete the plugin).', 'amplifi-security' ); ?>
	</label>
</p>
