<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Bulk_Page {
	public function render(): void {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$settings   = get_option( 'ac_schema_settings', [] );
		?>
		<div class="wrap">
			<h1>Bulk Generate Schema</h1>
			<p>Generate JSON-LD for many posts at once. Runs in the background via WP-Cron.</p>

			<form id="ac-bulk-form" style="max-width:700px;">
				<h2>Scope</h2>
				<table class="form-table">
					<tr>
						<th>Post types</th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, [ 'post', 'page' ], true ) ); ?> /> <?php echo esc_html( $pt->label ); ?> (<code><?php echo esc_html( $pt->name ); ?></code>)</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th>Published after</th>
						<td><input type="date" name="after" /> <span class="description">Optional</span></td>
					</tr>
					<tr>
						<th>Model</th>
						<td>
							<select name="model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $settings['default_model'] ?? '', 'claude-haiku-4-5-20251001' ); ?>>Claude Haiku 4.5</option>
								<option value="claude-sonnet-4-6" <?php selected( $settings['default_model'] ?? '', 'claude-sonnet-4-6' ); ?>>Claude Sonnet 4.6</option>
								<option value="claude-opus-4-7" <?php selected( $settings['default_model'] ?? '', 'claude-opus-4-7' ); ?>>Claude Opus 4.7</option>
							</select>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" id="ac-preview" class="button">Preview cost</button>
					<button type="submit" class="button button-primary">Start generating</button>
				</p>
				<div id="ac-preview-result" style="margin:10px 0;font-weight:bold;"></div>
			</form>

			<div id="ac-job-status" style="margin-top:30px;display:none;">
				<h2>Running job: <span id="ac-job-id"></span></h2>
				<p>Status: <strong id="ac-job-state"></strong></p>
				<p>Processed: <span id="ac-job-processed"></span> / <span id="ac-job-total"></span> (failed: <span id="ac-job-failed"></span>)</p>
				<p>Cost so far: $<span id="ac-job-cost"></span></p>
				<p>
					<button type="button" class="button ac-job-action" data-action="pause">Pause</button>
					<button type="button" class="button ac-job-action" data-action="resume">Resume</button>
					<button type="button" class="button ac-job-action" data-action="cancel">Cancel</button>
				</p>
			</div>
		</div>
		<script>
		(function(){
			let pollHandle = null;

			function readScope(form){
				return {
					post_types: Array.from(form.querySelectorAll('input[name="post_types[]"]:checked')).map(c => c.value),
					after:      form.after.value || undefined,
				};
			}

			document.getElementById('ac-preview').addEventListener('click', async function(){
				const form  = document.getElementById('ac-bulk-form');
				const body  = { scope: readScope(form), model: form.model.value };
				const r = await fetch(AcSchemaAdmin.restUrl + 'jobs/preview-cost', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					body: JSON.stringify(body),
				});
				const data = await r.json();
				document.getElementById('ac-preview-result').textContent =
					data.count + ' posts · estimated $' + (data.estimated_cost_usd ?? 0).toFixed(4);
			});

			document.getElementById('ac-bulk-form').addEventListener('submit', async function(e){
				e.preventDefault();
				const form = e.target;
				if (!confirm('Start AI generation for this scope?')) return;
				const body = { scope: readScope(form), model: form.model.value };
				const r = await fetch(AcSchemaAdmin.restUrl + 'jobs', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					body: JSON.stringify(body),
				});
				const job = await r.json();
				if (job.id) startPolling(job.id);
			});

			function startPolling(id){
				document.getElementById('ac-job-status').style.display = '';
				document.getElementById('ac-job-id').textContent = id;
				document.querySelectorAll('.ac-job-action').forEach(b => b.dataset.id = id);
				if (pollHandle) clearInterval(pollHandle);
				pollHandle = setInterval(() => pollJob(id), 3000);
				pollJob(id);
			}

			async function pollJob(id){
				const r = await fetch(AcSchemaAdmin.restUrl + 'jobs/' + id, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': AcSchemaAdmin.nonce },
				});
				if (!r.ok) return;
				const j = await r.json();
				document.getElementById('ac-job-state').textContent     = j.status;
				document.getElementById('ac-job-processed').textContent = j.processed;
				document.getElementById('ac-job-total').textContent     = j.total;
				document.getElementById('ac-job-failed').textContent    = j.failed;
				document.getElementById('ac-job-cost').textContent      = parseFloat(j.cost_usd).toFixed(4);
				if (['completed','failed','paused'].includes(j.status) && j.status !== 'paused') {
					clearInterval(pollHandle);
				}
			}

			document.querySelectorAll('.ac-job-action').forEach(function(btn){
				btn.addEventListener('click', async function(){
					const id = btn.dataset.id;
					const action = btn.dataset.action;
					if (!id) return;
					await fetch(AcSchemaAdmin.restUrl + 'jobs/' + id + '/' + action, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': AcSchemaAdmin.nonce },
					});
					pollJob(id);
				});
			});
		})();
		</script>
		<?php
	}
}
