<?php
/**
 * Audit tab — link to the dedicated audit page.
 *
 * @package Amplifi\Security\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-security-audit' ) ); ?>"><?php esc_html_e( 'Open audit log', 'amplifi-security' ); ?></a>
</p>
