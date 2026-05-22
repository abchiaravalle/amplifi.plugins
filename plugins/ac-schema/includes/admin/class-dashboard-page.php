<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;

use Amplifi\Schema\AI\Spend_Tracker;
use Amplifi\Schema\Queue\Job_Store;
use Amplifi\Schema\Crypto\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dashboard_Page {
	public function render(): void {
		$settings  = get_option( 'ac_schema_settings', [] );
		$has_key   = (bool) Secret_Store::get( 'anthropic_api_key' );
		$today     = Spend_Tracker::spend_today_usd();
		$month     = Spend_Tracker::spend_month_usd();
		$daily_cap = (float) ( $settings['daily_spend_cap_usd'] ?? 5.0 );
		$month_cap = (float) ( $settings['monthly_spend_cap_usd'] ?? 50.0 );
		$jobs      = ( new Job_Store() )->list_recent( 5 );
		global $wpdb;
		$counts = $wpdb->get_results(
			"SELECT schema_type, COUNT(*) AS n FROM {$wpdb->prefix}ac_schema_entries GROUP BY schema_type ORDER BY n DESC LIMIT 20",
			ARRAY_A
		); // phpcs:ignore
		?>
		<div class="wrap">
			<h1>amplifi.schema</h1>
			<p>Bulk-generate, edit, and deploy schema.org JSON-LD with Claude.</p>

			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:20px 0;">
				<div class="card"><h3>Spend today</h3><p style="font-size:1.8em;margin:0;">$<?php echo esc_html( number_format( $today, 2 ) ); ?> <small style="color:#888;">/ $<?php echo esc_html( number_format( $daily_cap, 2 ) ); ?></small></p></div>
				<div class="card"><h3>Spend this month</h3><p style="font-size:1.8em;margin:0;">$<?php echo esc_html( number_format( $month, 2 ) ); ?> <small style="color:#888;">/ $<?php echo esc_html( number_format( $month_cap, 2 ) ); ?></small></p></div>
				<div class="card"><h3>API key</h3><p><?php echo $has_key ? '<span style="color:#1c8a3a;">Configured</span>' : '<span style="color:#a00;">Not set</span>'; ?></p></div>
			</div>

			<h2>Entries by type</h2>
			<?php if ( $counts ) : ?>
				<table class="widefat" style="max-width:600px;">
					<thead><tr><th>Schema type</th><th>Count</th></tr></thead>
					<tbody>
					<?php foreach ( $counts as $row ) : ?>
						<tr><td><?php echo esc_html( $row['schema_type'] ); ?></td><td><?php echo esc_html( $row['n'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em>No entries yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-ac-schema-bulk' ) ); ?>">Run a bulk generation</a> or edit a post.</em></p>
			<?php endif; ?>

			<h2 style="margin-top:30px;">Recent jobs</h2>
			<?php if ( $jobs ) : ?>
				<table class="widefat" style="max-width:800px;">
					<thead><tr><th>ID</th><th>Status</th><th>Processed</th><th>Failed</th><th>Cost</th><th>Model</th><th>Started</th></tr></thead>
					<tbody>
					<?php foreach ( $jobs as $j ) : ?>
						<tr>
							<td><?php echo esc_html( $j['id'] ); ?></td>
							<td><?php echo esc_html( $j['status'] ); ?></td>
							<td><?php echo esc_html( $j['processed'] ); ?> / <?php echo esc_html( $j['total'] ); ?></td>
							<td><?php echo esc_html( $j['failed'] ); ?></td>
							<td>$<?php echo esc_html( number_format( (float) $j['cost_usd'], 4 ) ); ?></td>
							<td><?php echo esc_html( $j['model'] ); ?></td>
							<td><?php echo esc_html( $j['started_at'] ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em>No bulk jobs yet.</em></p>
			<?php endif; ?>

			<h2 style="margin-top:30px;">Settings</h2>
			<form id="ac-schema-settings-form" style="max-width:600px;">
				<table class="form-table">
					<tr>
						<th><label for="ac-api-key">Anthropic API key</label></th>
						<td>
							<input type="password" id="ac-api-key" name="api_key" autocomplete="off" placeholder="<?php echo $has_key ? '(stored — leave blank to keep)' : 'sk-ant-…'; ?>" style="width:100%;" />
							<p class="description">Stored encrypted at rest. Scope it to Messages API only.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ac-model">Default model</label></th>
						<td>
							<select id="ac-model" name="default_model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $settings['default_model'] ?? '', 'claude-haiku-4-5-20251001' ); ?>>Claude Haiku 4.5 (cheap)</option>
								<option value="claude-sonnet-4-6" <?php selected( $settings['default_model'] ?? '', 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6</option>
								<option value="claude-opus-4-7" <?php selected( $settings['default_model'] ?? '', 'claude-opus-4-7' ); ?>>Claude Opus 4.7</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ac-daily-cap">Daily spend cap (USD)</label></th>
						<td><input type="number" step="0.01" id="ac-daily-cap" name="daily_spend_cap_usd" value="<?php echo esc_attr( (string) $daily_cap ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ac-month-cap">Monthly spend cap (USD)</label></th>
						<td><input type="number" step="0.01" id="ac-month-cap" name="monthly_spend_cap_usd" value="<?php echo esc_attr( (string) $month_cap ); ?>" /></td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary">Save</button> <span id="ac-save-status" style="margin-left:10px;"></span></p>
			</form>
		</div>
		<script>
		(function(){
			const form = document.getElementById('ac-schema-settings-form');
			form.addEventListener('submit', async function(e){
				e.preventDefault();
				const body = {
					api_key:                form.api_key.value || undefined,
					default_model:          form.default_model.value,
					daily_spend_cap_usd:    parseFloat(form.daily_spend_cap_usd.value),
					monthly_spend_cap_usd:  parseFloat(form.monthly_spend_cap_usd.value),
				};
				const status = document.getElementById('ac-save-status');
				status.textContent = 'Saving…';
				const r = await fetch(AcSchemaAdmin.restUrl + 'settings', {
					method: 'PUT',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					body: JSON.stringify(body),
				});
				status.textContent = r.ok ? 'Saved.' : 'Save failed.';
				form.api_key.value = '';
				if (r.ok) setTimeout(() => { status.textContent = ''; location.reload(); }, 800);
			});
		})();
		</script>
		<?php
	}
}
