<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rules_Page {
	public function render(): void {
		$rules = get_option( 'ac_schema_url_rules', [] );
		?>
		<div class="wrap">
			<h1>URL Rules</h1>
			<p>Attach schema to non-post URLs (archives, taxonomies, search, etc.) by pattern.</p>

			<h2>Add rule</h2>
			<form id="ac-rule-add" style="max-width:700px;">
				<table class="form-table">
					<tr><th>Pattern</th><td><input type="text" name="pattern" placeholder="/blog/*" style="width:100%;" required /></td></tr>
					<tr><th>Match type</th><td>
						<select name="match_type">
							<option value="glob">glob (* and ?)</option>
							<option value="regex">regex (PCRE)</option>
						</select>
					</td></tr>
				</table>
				<p><button type="submit" class="button button-primary">Add rule</button> <span id="ac-rule-add-status" style="margin-left:10px;"></span></p>
			</form>

			<h2 style="margin-top:30px;">Existing rules</h2>
			<table class="widefat">
				<thead><tr><th>Pattern</th><th>Type</th><th>ID</th><th>Actions</th></tr></thead>
				<tbody id="ac-rule-rows">
					<?php if ( ! $rules ) : ?>
						<tr><td colspan="4"><em>No rules yet.</em></td></tr>
					<?php else : foreach ( $rules as $r ) : ?>
						<tr data-id="<?php echo esc_attr( $r['id'] ?? '' ); ?>">
							<td><code><?php echo esc_html( $r['pattern'] ?? '' ); ?></code></td>
							<td><?php echo esc_html( $r['match_type'] ?? 'glob' ); ?></td>
							<td><?php echo esc_html( $r['id'] ?? '' ); ?></td>
							<td><button class="button ac-rule-delete" data-id="<?php echo esc_attr( $r['id'] ?? '' ); ?>">Delete</button></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:30px;">Test a pattern</h2>
			<form id="ac-rule-test" style="max-width:700px;">
				<table class="form-table">
					<tr><th>Pattern</th><td><input type="text" name="pattern" style="width:100%;" required /></td></tr>
					<tr><th>Match type</th><td>
						<select name="match_type"><option value="glob">glob</option><option value="regex">regex</option></select>
					</td></tr>
					<tr><th>URL</th><td><input type="text" name="url" placeholder="<?php echo esc_attr( home_url( '/blog/hello' ) ); ?>" style="width:100%;" required /></td></tr>
				</table>
				<p><button type="submit" class="button">Test</button> <span id="ac-rule-test-status" style="margin-left:10px;"></span></p>
			</form>
		</div>
		<script>
		(function(){
			document.getElementById('ac-rule-add').addEventListener('submit', async function(e){
				e.preventDefault();
				const fd = new FormData(e.target);
				const r = await fetch(AcSchemaAdmin.restUrl + 'rules', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					body: JSON.stringify({ pattern: fd.get('pattern'), match_type: fd.get('match_type') }),
				});
				document.getElementById('ac-rule-add-status').textContent = r.ok ? 'Added — reloading…' : 'Failed.';
				if (r.ok) setTimeout(() => location.reload(), 400);
			});
			document.querySelectorAll('.ac-rule-delete').forEach(function(btn){
				btn.addEventListener('click', async function(){
					const id = btn.dataset.id;
					if (!confirm('Delete rule ' + id + '?')) return;
					const r = await fetch(AcSchemaAdmin.restUrl + 'rules/' + id, {
						method: 'DELETE',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': AcSchemaAdmin.nonce },
					});
					if (r.ok) location.reload();
				});
			});
			document.getElementById('ac-rule-test').addEventListener('submit', async function(e){
				e.preventDefault();
				const fd = new FormData(e.target);
				const r = await fetch(AcSchemaAdmin.restUrl + 'rules/test', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					body: JSON.stringify({ pattern: fd.get('pattern'), match_type: fd.get('match_type'), url: fd.get('url') }),
				});
				const data = await r.json();
				document.getElementById('ac-rule-test-status').textContent = data.matches ? '✓ Matches' : '✗ No match';
			});
		})();
		</script>
		<?php
	}
}
