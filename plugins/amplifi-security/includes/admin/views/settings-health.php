<?php
/**
 * Health tab — link to dashboard.
 *
 * @package Amplifi\Security\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-security' ) ); ?>"><?php esc_html_e( 'Open health dashboard', 'amplifi-security' ); ?></a>
</p>
