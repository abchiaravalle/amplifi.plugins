<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Global_Page {
	private const KEYS = [
		'organization'  => 'Organization',
		'website'       => 'WebSite',
		'localbusiness' => 'LocalBusiness',
	];

	public function render(): void {
		?>
		<div class="wrap">
			<h1>Global Schema</h1>
			<p>Site-wide schema entities, emitted on every page in the same <code>@graph</code> as per-post entries.</p>
			<?php foreach ( self::KEYS as $key => $label ) :
				$value = get_option( 'ac_schema_global_' . $key, [] );
				$json  = $value ? (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : ''; ?>
				<h2 style="margin-top:30px;"><?php echo esc_html( $label ); ?></h2>
				<form class="ac-global-form" data-key="<?php echo esc_attr( $key ); ?>" style="max-width:800px;">
					<textarea class="ac-global-json" name="json" rows="14" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $json ); ?></textarea>
					<p>
						<button type="button" class="button ac-prefill">Prefill with AI</button>
						<button type="submit" class="button button-primary">Save</button>
						<span class="ac-status" style="margin-left:10px;"></span>
					</p>
				</form>
			<?php endforeach; ?>
		</div>
		<script>
		(function(){
			document.querySelectorAll('.ac-global-form').forEach(function(form){
				const key    = form.dataset.key;
				const ta     = form.querySelector('.ac-global-json');
				const status = form.querySelector('.ac-status');
				form.querySelector('.ac-prefill').addEventListener('click', async function(){
					status.textContent = 'Generating…';
					const r = await fetch(AcSchemaAdmin.restUrl + 'global/' + key + '/ai-prefill', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
					});
					const data = await r.json();
					if (data.jsonld) {
						ta.value = JSON.stringify(data.jsonld, null, 2);
						status.textContent = 'Prefilled. Review and Save.';
					} else {
						status.textContent = 'AI error: ' + (data.message || data.error || 'unknown');
					}
				});
				form.addEventListener('submit', async function(e){
					e.preventDefault();
					let parsed;
					try { parsed = JSON.parse(ta.value); }
					catch(err) { status.textContent = 'Invalid JSON: ' + err.message; return; }
					status.textContent = 'Saving…';
					const r = await fetch(AcSchemaAdmin.restUrl + 'global/' + key, {
						method: 'PUT',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AcSchemaAdmin.nonce },
						body: JSON.stringify(parsed),
					});
					const data = await r.json();
					if (r.ok) status.textContent = 'Saved.';
					else status.textContent = 'Save failed: ' + (data.message || 'unknown') + (data.data && data.data.errors ? ' — ' + JSON.stringify(data.data.errors) : '');
				});
			});
		})();
		</script>
		<?php
	}
}
