<?php
/**
 * @package Amplifi\Security\Admin\Views
 *
 * @var array $settings
 */

use Amplifi\Security\Alerts\Smtp2Go_Client;
use Amplifi\Security\Alerts\Textbelt_Client;
use Amplifi\Security\Crypto\Secret_Store;
use Amplifi\Security\Data\AbuseIPDB_Client;
use Amplifi\Security\Data\Vuln_Feed;
use Amplifi\Security\Triage\Anthropic_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ant_set   = '' !== Anthropic_Client::api_key();
$smtp_set  = '' !== Smtp2Go_Client::api_key();
$abuse_set = '' !== AbuseIPDB_Client::api_key();
$tb_set    = '' !== Textbelt_Client::api_key();
$wf_set    = '' !== Vuln_Feed::auth_token();

$ant_test  = $_GET['anthropic_test'] ?? '';
$smtp_test = $_GET['smtp2go_test']  ?? '';
?>

<h2><?php esc_html_e( 'Required', 'amplifi-security' ); ?></h2>

<table class="form-table">
	<tr>
		<th><label><?php esc_html_e( 'Anthropic API key', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="password" name="anthropic_key" value="<?php echo $ant_set ? esc_attr( Secret_Store::mask( Anthropic_Client::api_key() ) ) : ''; ?>" placeholder="sk-ant-..." class="regular-text" autocomplete="off"/>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=amplifi_security_test_anthropic' ), 'amplifi_security_test_anthropic' ) ); ?>"><?php esc_html_e( 'Test', 'amplifi-security' ); ?></a>
			<?php if ( 'ok' === $ant_test ) : ?><span style="color:#2c7a2c">✓ key works</span><?php elseif ( 'fail' === $ant_test ) : ?><span style="color:#a00">✗ key did not work</span><?php endif; ?>
			<p class="description"><?php esc_html_e( 'Required for AI triage. Sign up at console.anthropic.com.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'SMTP2Go API key', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="password" name="smtp2go_key" value="<?php echo $smtp_set ? esc_attr( Secret_Store::mask( Smtp2Go_Client::api_key() ) ) : ''; ?>" placeholder="api-..." class="regular-text" autocomplete="off"/>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=amplifi_security_test_smtp2go' ), 'amplifi_security_test_smtp2go' ) ); ?>"><?php esc_html_e( 'Test', 'amplifi-security' ); ?></a>
			<?php if ( 'ok' === $smtp_test ) : ?><span style="color:#2c7a2c">✓ test email sent</span><?php elseif ( 'fail' === $smtp_test ) : ?><span style="color:#a00">✗ failed</span><?php endif; ?>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'SMTP2Go sender (verified)', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="email" name="smtp2go_sender" value="<?php echo esc_attr( Smtp2Go_Client::sender() ); ?>" class="regular-text" />
			<p class="description"><?php esc_html_e( 'Must be a verified sender on your SMTP2Go account.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
</table>

<h2><?php esc_html_e( 'Optional', 'amplifi-security' ); ?></h2>

<table class="form-table">
	<tr>
		<th><label><?php esc_html_e( 'Wordfence Intelligence token', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="password" name="wordfence_token" value="<?php echo $wf_set ? esc_attr( Secret_Store::mask( Vuln_Feed::auth_token() ) ) : ''; ?>" placeholder="wf-..." class="regular-text" autocomplete="off"/>
			<p class="description"><?php esc_html_e( 'Free with a Wordfence account. Required for the vulnerability scanner.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'AbuseIPDB API key', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="password" name="abuseipdb_key" value="<?php echo $abuse_set ? esc_attr( Secret_Store::mask( AbuseIPDB_Client::api_key() ) ) : ''; ?>" class="regular-text" autocomplete="off"/>
			<p class="description"><?php esc_html_e( 'Adds IP-reputation context to auth findings. Free tier: 1,000 lookups/day.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Textbelt SMS API key', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="password" name="textbelt_key" value="<?php echo $tb_set ? esc_attr( Secret_Store::mask( Textbelt_Client::api_key() ) ) : ''; ?>" class="regular-text" autocomplete="off"/>
			<p class="description"><?php esc_html_e( 'Sends SMS for confirmed-only alerts. Hard cap at 3/day in code.', 'amplifi-security' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label><?php esc_html_e( 'Textbelt phone number', 'amplifi-security' ); ?></label></th>
		<td>
			<input type="tel" name="textbelt_phone" value="<?php echo esc_attr( Textbelt_Client::phone() ); ?>" class="regular-text" />
		</td>
	</tr>
</table>
