<?php
/**
 * Admin screen: translate and hand-edit llms.txt per language.
 *
 * Machine translation is the starting point, not the deliverable. This screen
 * exists so a human can correct the output per language and have that edit
 * survive — a re-translate overwrites, so the UI warns before it does.
 *
 * @package amplifi-translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Llms_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu() {
		add_submenu_page(
			'amplifi-studio',
			'llms.txt',
			'llms.txt',
			'manage_options',
			'amplifi-translate-llms',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		ACWPT_Llms::maybe_seed_source();

		$source  = ACWPT_Languages::get_source();
		$enabled = ACWPT_Languages::get_enabled_codes();
		$langs   = array_merge( array( $source ), $enabled );
		$nonce   = wp_create_nonce( 'acwpt_llms' );
		$current = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : $source;
		if ( ! in_array( $current, $langs, true ) ) {
			$current = $source;
		}
		?>
		<div class="wrap">
			<h1>llms.txt</h1>
			<p class="description" style="max-width:820px">
				Served at <code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code> and, for each
				enabled language, at <code>/llms/&lt;lang&gt;</code> &mdash; extensionless, because WP Engine's nginx
				claims any <code>.txt</code> path as a static file before WordPress sees it. This is what LLM answer engines
				read to decide what this site is and which pages to cite. Translating rewrites same-site
				URLs to that language's prefix, so a crawler following a link from the German document
				lands on the German page.
			</p>

			<h2 class="nav-tab-wrapper" style="margin-bottom:0">
				<?php foreach ( $langs as $code ) :
					$meta  = ACWPT_Llms::meta( $code );
					$bytes = isset( $meta['bytes'] ) ? (int) $meta['bytes'] : strlen( ACWPT_Llms::get( $code ) );
					$label = ACWPT_Languages::label( $code );
					?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'amplifi-translate-llms', 'lang' => $code ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $code === $current ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( $bytes ) : ?>
							<span style="opacity:.55">· <?php echo esc_html( size_format( $bytes ) ); ?></span>
						<?php else : ?>
							<span style="color:#b32d2e">· empty</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:16px">
				<?php
				$meta = ACWPT_Llms::meta( $current );
				if ( ! empty( $meta['updated'] ) ) {
					printf(
						'<p style="margin-top:0;color:#646970">Last updated %s ago — %s</p>',
						esc_html( human_time_diff( (int) $meta['updated'], time() ) ),
						esc_html( 'manual' === ( $meta['by'] ?? '' ) ? 'edited by hand' : 'machine translated' )
					);
				}
				?>

				<p>
					<?php if ( $current === $source ) : ?>
						<button class="button button-primary" id="acwpt-llms-all">Translate to all <?php echo count( $enabled ); ?> languages</button>
						<span class="description" style="margin-left:8px">Overwrites every translated document, including hand edits.</span>
					<?php else : ?>
						<button class="button button-primary" id="acwpt-llms-one" data-lang="<?php echo esc_attr( $current ); ?>">
							Translate <?php echo esc_html( ACWPT_Languages::label( $current ) ); ?> from <?php echo esc_html( ACWPT_Languages::label( $source ) ); ?>
						</button>
						<span class="description" style="margin-left:8px">Overwrites this document, including hand edits.</span>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( ACWPT_Llms::url( $current ) ); ?>" target="_blank">View live</a>
				</p>

				<textarea id="acwpt-llms-text" class="large-text code" rows="26"
					style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.5"
				><?php echo esc_textarea( ACWPT_Llms::get( $current ) ); ?></textarea>

				<p>
					<button class="button button-primary" id="acwpt-llms-save" data-lang="<?php echo esc_attr( $current ); ?>">Save changes</button>
					<span id="acwpt-llms-status" style="margin-left:10px"></span>
				</p>
			</div>
		</div>

		<script>
		(function(){
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;
			const ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const status = document.getElementById('acwpt-llms-status');

			function post(action, data, btn, label) {
				const original = btn ? btn.textContent : '';
				if (btn) { btn.disabled = true; btn.textContent = label; }
				status.textContent = '';
				const body = new URLSearchParams(Object.assign({action, nonce}, data));
				return fetch(ajax, {method:'POST', credentials:'same-origin', body})
					.then(r => r.json())
					.then(j => {
						if (btn) { btn.disabled = false; btn.textContent = original; }
						return j;
					})
					.catch(e => {
						if (btn) { btn.disabled = false; btn.textContent = original; }
						status.innerHTML = '<span style="color:#b32d2e">Request failed: ' + e + '</span>';
					});
			}

			const one = document.getElementById('acwpt-llms-one');
			if (one) one.addEventListener('click', function(){
				if (!confirm('Re-translate this document? Any hand edits will be replaced.')) return;
				post('acwpt_translate_llms', {lang: this.dataset.lang}, this, 'Translating…').then(j => {
					if (j && j.success) { status.innerHTML = '<span style="color:#008a20">Translated. Reloading…</span>'; setTimeout(()=>location.reload(), 700); }
					else if (j) { status.innerHTML = '<span style="color:#b32d2e">' + ((j.data && j.data.message) || 'Failed') + '</span>'; }
				});
			});

			const all = document.getElementById('acwpt-llms-all');
			if (all) all.addEventListener('click', function(){
				if (!confirm('Translate into every enabled language? This replaces all translated documents, including hand edits.')) return;
				post('acwpt_translate_llms', {lang: 'all'}, this, 'Translating all…').then(j => {
					if (j && j.success) {
						const n = (j.data.translated || []).length;
						const e = Object.keys(j.data.errors || {});
						status.innerHTML = '<span style="color:#008a20">Translated ' + n + ' language(s).</span>' +
							(e.length ? ' <span style="color:#b32d2e">Failed: ' + e.join(', ') + '</span>' : '');
						setTimeout(()=>location.reload(), 1200);
					}
				});
			});

			document.getElementById('acwpt-llms-save').addEventListener('click', function(){
				const text = document.getElementById('acwpt-llms-text').value;
				post('acwpt_save_llms', {lang: this.dataset.lang, text}, this, 'Saving…').then(j => {
					if (j && j.success) status.innerHTML = '<span style="color:#008a20">Saved (' + j.data.bytes + ' bytes).</span>';
					else if (j) status.innerHTML = '<span style="color:#b32d2e">' + ((j.data && j.data.message) || 'Failed') + '</span>';
				});
			});
		})();
		</script>
		<?php
	}
}
