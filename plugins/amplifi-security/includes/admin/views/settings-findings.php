<?php
/**
 * Findings tab — link to the dedicated findings page.
 *
 * @package Amplifi\Security\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p>
	<?php esc_html_e( 'The findings list lives on its own page for performance.', 'amplifi-security' ); ?>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-security-findings' ) ); ?>"><?php esc_html_e( 'Open findings', 'amplifi-security' ); ?></a>
</p>
