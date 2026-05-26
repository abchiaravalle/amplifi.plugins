<?php
declare(strict_types=1);
namespace Amplifi\Schema\Admin;

use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Schema\Detector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Post_Editor {
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_meta_box(): void {
		$post_types = array_keys( get_post_types( [ 'public' => true ] ) );
		foreach ( $post_types as $type ) {
			add_meta_box(
				'ac-schema-editor',
				'amplifi.schema',
				[ $this, 'render' ],
				$type,
				'normal',
				'high'
			);
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		// The metabox body emits inline JS that uses window.AcSchemaEditor.
		// We just need to make sure jQuery is not assumed.
	}

	public function render( \WP_Post $post ): void {
		$store   = new Entry_Store();
		$entries = $store->find_all_for_scope( 'post', (string) $post->ID );

		// Foreign detection (cached 1h by Detector).
		$detected = [];
		if ( $post->post_status === 'publish' ) {
			$detected = ( new Detector() )->detect_for_url( (string) get_permalink( $post ) );
			// Filter out our own output, to avoid showing ourselves as foreign.
			$detected = array_values( array_filter( $detected, fn( $d ) => $d['source'] !== 'amplifi-schema' ) );
		}

		$overrides = get_post_meta( $post->ID, '_ac_schema_overrides', true );
		$overrides = is_array( $overrides ) ? $overrides : [];

		$nonce      = wp_create_nonce( 'wp_rest' );
		$rest_url   = esc_url_raw( rest_url( 'amplifi-schema/v1/' ) );
		$permalink  = (string) get_permalink( $post );
		$rich_url   = 'https://search.google.com/test/rich-results?url=' . rawurlencode( $permalink );
		?>
		<div id="ac-schema-editor" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-rest="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<style>
				#ac-schema-editor .ac-entry { border:1px solid #ddd; padding:10px; margin-bottom:12px; background:#fafafa; }
				#ac-schema-editor .ac-entry h4 { margin:0 0 6px 0; font-size:13px; }
				#ac-schema-editor textarea { width:100%; min-height:180px; font-family: ui-monospace, Menlo, monospace; font-size:12px; }
				#ac-schema-editor .ac-status { margin-left:10px; }
				#ac-schema-editor .ac-error { color:#a00; }
				#ac-schema-editor .ac-detected { background:#fff8e1; border:1px solid #f0c36d; padding:10px; margin-bottom:12px; }
				#ac-schema-editor .ac-detected button { margin-right:6px; }
			</style>

			<?php if ( $detected ) : ?>
				<div class="ac-detected">
					<strong>Detected schema from other sources:</strong>
					<ul style="margin:6px 0 6px 18px;">
						<?php foreach ( $detected as $i => $d ) :
							$is_overridden = in_array( $d['schema_type'], $overrides, true ); ?>
							<li>
								<code><?php echo esc_html( $d['schema_type'] ); ?></code> from <strong><?php echo esc_html( $d['source'] ); ?></strong>
								<button type="button" class="button button-small ac-import" data-json="<?php echo esc_attr( $d['json_string'] ); ?>" data-type="<?php echo esc_attr( $d['schema_type'] ); ?>">Import a copy</button>
								<?php if ( $is_overridden ) : ?>
									<button type="button" class="button button-small ac-unoverride" data-type="<?php echo esc_attr( $d['schema_type'] ); ?>">Un-override</button>
								<?php else : ?>
									<button type="button" class="button button-small ac-override" data-type="<?php echo esc_attr( $d['schema_type'] ); ?>">Override (suppress theirs)</button>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div id="ac-entries">
				<?php if ( $entries ) : foreach ( $entries as $entry ) : ?>
					<div class="ac-entry" data-id="<?php echo esc_attr( $entry['id'] ); ?>" data-type="<?php echo esc_attr( $entry['schema_type'] ); ?>">
						<h4><?php echo esc_html( $entry['schema_type'] ); ?> <small style="color:#888;">(<?php echo esc_html( $entry['source'] ); ?>)</small></h4>
						<textarea class="ac-json"><?php echo esc_textarea( (string) wp_json_encode( json_decode( (string) $entry['json_ld'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
						<p>
							<button type="button" class="button button-primary ac-save">Save</button>
							<button type="button" class="button ac-delete">Delete</button>
							<span class="ac-status"></span>
						</p>
					</div>
				<?php endforeach; else : ?>
					<p><em>No schema yet for this post.</em></p>
				<?php endif; ?>
			</div>

			<p>
				<button type="button" class="button button-primary" id="ac-generate">Generate with AI</button>
				<button type="button" class="button" id="ac-add-blank">Add blank entry</button>
				<a class="button" href="<?php echo esc_url( $rich_url ); ?>" target="_blank" rel="noopener">Test in Google Rich Results</a>
				<span id="ac-toplevel-status" class="ac-status"></span>
			</p>
		</div>
		<script>
		(function(){
			var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
			var REST_NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var POST_ID = <?php echo wp_json_encode( (string) $post->ID ); ?>;

			function init() {
				const root = document.getElementById('ac-schema-editor');
				if (!root) { return; }
				const postId = POST_ID;
				const rest   = REST_URL;
				const nonce  = REST_NONCE;

			function api(path, opts){
				opts = opts || {};
				opts.credentials = 'same-origin';
				opts.headers = Object.assign({ 'X-WP-Nonce': nonce }, opts.headers || {});
				if (opts.body && typeof opts.body !== 'string') {
					opts.headers['Content-Type'] = 'application/json';
					opts.body = JSON.stringify(opts.body);
				}
				return fetch(rest + path, opts).then(async r => {
					const j = await r.json().catch(() => ({}));
					return { ok: r.ok, status: r.status, data: j };
				});
			}

			function entryNode(entry){
				const el = document.createElement('div');
				el.className = 'ac-entry';
				el.dataset.id   = entry.id;
				el.dataset.type = entry.schema_type;
				el.innerHTML = '<h4>' + entry.schema_type + ' <small style="color:#888;">(' + entry.source + ')</small></h4>'
					+ '<textarea class="ac-json"></textarea>'
					+ '<p><button type="button" class="button button-primary ac-save">Save</button> '
					+ '<button type="button" class="button ac-delete">Delete</button> '
					+ '<span class="ac-status"></span></p>';
				let json = entry.json_ld;
				try { json = JSON.stringify(JSON.parse(entry.json_ld), null, 2); } catch (e) {}
				el.querySelector('.ac-json').value = json;
				wire(el);
				return el;
			}

			function wire(el){
				el.querySelector('.ac-save').addEventListener('click', async function(){
					const ta = el.querySelector('.ac-json');
					const status = el.querySelector('.ac-status');
					let parsed;
					try { parsed = JSON.parse(ta.value); }
					catch(err){ status.innerHTML = '<span class="ac-error">Invalid JSON: ' + err.message + '</span>'; return; }
					status.textContent = 'Saving…';
					const type = parsed['@type'] || el.dataset.type || 'Thing';
					const id   = el.dataset.id;
					const body = {
						scope_type: 'post',
						scope_id:   String(postId),
						schema_type: type,
						source:     'manual',
						json_ld:    JSON.stringify(parsed),
					};
					const r = id
						? await api('entries/' + id, { method: 'PUT', body: body })
						: await api('entries', { method: 'POST', body: body });
					if (r.ok) {
						status.textContent = 'Saved.';
						if (!id && r.data.id) el.dataset.id = r.data.id;
					} else {
						const errs = (r.data && r.data.data && r.data.data.errors) || [];
						status.innerHTML = '<span class="ac-error">' + (r.data.message || 'Save failed') + (errs.length ? ': ' + errs.map(e => e.message).join('; ') : '') + '</span>';
					}
				});
				el.querySelector('.ac-delete').addEventListener('click', async function(){
					const id = el.dataset.id;
					if (id && !confirm('Delete this entry?')) return;
					if (id) await api('entries/' + id, { method: 'DELETE' });
					el.remove();
				});
			}

			document.querySelectorAll('#ac-entries .ac-entry').forEach(wire);

			document.getElementById('ac-generate').addEventListener('click', async function(){
				const status = document.getElementById('ac-toplevel-status');
				status.textContent = 'Generating with AI…';
				const r = await api('entries/generate', { method: 'POST', body: { post_id: parseInt(postId, 10) } });
				if (!r.ok) {
					var errMsg = r.data.message || r.data.error || (r.data.data && r.data.data.error) || JSON.stringify(r.data) || 'Unknown error';
					status.innerHTML = '<span class="ac-error">' + errMsg + '</span>';
					return;
				}
				status.textContent = 'Generated. Review and Save.';
				document.getElementById('ac-entries').appendChild(entryNode({
					id: '',
					schema_type: (r.data.jsonld && r.data.jsonld['@type']) || 'Thing',
					source: 'ai',
					json_ld: JSON.stringify(r.data.jsonld),
				}));
			});

			document.getElementById('ac-add-blank').addEventListener('click', function(){
				document.getElementById('ac-entries').appendChild(entryNode({
					id: '',
					schema_type: 'Thing',
					source: 'manual',
					json_ld: '{"@context":"https://schema.org","@type":"Thing","name":""}',
				}));
			});

			// Import / Override / Un-override buttons in the detected banner.
			document.querySelectorAll('.ac-import').forEach(function(b){
				b.addEventListener('click', function(){
					const json = b.dataset.json;
					const type = b.dataset.type;
					document.getElementById('ac-entries').appendChild(entryNode({
						id: '',
						schema_type: type,
						source: 'imported',
						json_ld: json,
					}));
				});
			});
			document.querySelectorAll('.ac-override').forEach(function(b){
				b.addEventListener('click', async function(){
					const type = b.dataset.type;
					const r = await api('post-overrides/' + postId, { method: 'PUT', body: { add: type } });
					if (r.ok) location.reload();
				});
			});
			document.querySelectorAll('.ac-unoverride').forEach(function(b){
				b.addEventListener('click', async function(){
					const type = b.dataset.type;
					const r = await api('post-overrides/' + postId, { method: 'PUT', body: { remove: type } });
					if (r.ok) location.reload();
				});
			});
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}
}
