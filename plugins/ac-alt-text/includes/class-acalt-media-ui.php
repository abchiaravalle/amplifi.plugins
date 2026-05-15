<?php
/**
 * Per-attachment "Generate alt" buttons in three places:
 *   1. The classic attachment edit screen (post.php?post=N&action=edit)
 *   2. The media library list view row actions
 *   3. The wp.media JS modal (used by Gutenberg, Classic Editor, etc.)
 *
 * All three call the same synchronous AJAX endpoint that runs the generator
 * directly (no queue wait).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACALT_Media_UI {

	public static function register() {
		// Synchronous generation endpoint.
		add_action( 'wp_ajax_acalt_generate_now', array( __CLASS__, 'ajax_generate_now' ) );

		// (1) Classic attachment edit screen — inject "Generate alt" button next to alt input.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'inject_into_attachment_fields' ), 20, 2 );

		// (2) Media library list view row actions.
		add_filter( 'media_row_actions', array( __CLASS__, 'inject_row_action' ), 10, 2 );

		// (3) wp.media modal: enqueue JS that adds a button + handler to Attachment.Details view.
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_modal_assets' ) );
	}

	public static function ajax_generate_now() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'acalt_generate_now' );

		$id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Missing attachment_id' ), 400 );
		}

		$overwrite = ! empty( $_POST['overwrite'] );

		if ( $overwrite ) {
			delete_post_meta( $id, '_wp_attachment_image_alt' );
		}

		// Synthesize a job-like object (id=0 so we don't touch the queue).
		$job = (object) array(
			'id'            => 0,
			'attachment_id' => $id,
			'attempts'      => 0,
		);

		$result = ACALT_Generator::generate( $job );

		if ( ! empty( $result['ok'] ) ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			wp_send_json_success( array(
				'alt'        => $alt,
				'display'    => (string) $result['alt'],
				'tokens_in'  => (int) $result['tokens_in'],
				'tokens_out' => (int) $result['tokens_out'],
				'cost'       => (float) $result['cost'],
			) );
		}

		if ( ! empty( $result['skip'] ) ) {
			wp_send_json_error( array(
				'message' => 'Skipped: ' . ( $result['reason'] ?? 'unknown' ),
				'skipped' => true,
			), 200 );
		}

		wp_send_json_error( array( 'message' => $result['reason'] ?? 'generation failed' ), 500 );
	}

	public static function inject_into_attachment_fields( $form_fields, $post ) {
		if ( ! $post || strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return $form_fields;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $form_fields;
		}

		$nonce = wp_create_nonce( 'acalt_generate_now' );
		$existing_alt = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );

		$html  = '<button type="button" class="button acalt-generate-now-btn" ';
		$html .= 'data-attachment-id="' . (int) $post->ID . '" ';
		$html .= 'data-nonce="' . esc_attr( $nonce ) . '" ';
		$html .= 'style="margin-top:4px;">';
		$html .= $existing_alt !== '' ? 'Regenerate alt with AI' : 'Generate alt with AI';
		$html .= '</button>';
		$html .= ' <span class="acalt-generate-status" style="margin-left:8px;color:#666;"></span>';
		$html .= '<script>
			(function(){
				var sel = "button.acalt-generate-now-btn";
				if (window.__acaltBound) return; window.__acaltBound = true;
				document.addEventListener("click", function(e) {
					var btn = e.target.closest(sel);
					if (!btn) return;
					e.preventDefault();
					var id = btn.dataset.attachmentId;
					var status = btn.parentNode.querySelector(".acalt-generate-status");
					btn.disabled = true;
					status.textContent = "Generating…";
					var fd = new FormData();
					fd.append("action", "acalt_generate_now");
					fd.append("_ajax_nonce", btn.dataset.nonce);
					fd.append("attachment_id", id);
					fd.append("overwrite", "1");
					fetch(ajaxurl, { method: "POST", body: fd, credentials: "same-origin" })
						.then(function(r){ return r.json(); })
						.then(function(j){
							btn.disabled = false;
							if (j.success) {
								status.textContent = "Done.";
								// Update the alt input above us.
								var altField = document.querySelector("input[name=\\"attachments[" + id + "][post_excerpt]\\"], textarea[name=\\"attachments[" + id + "][post_excerpt]\\"], input#attachments-" + id + "-post_excerpt, textarea#attachments-" + id + "-post_excerpt, input[name=\\"_wp_attachment_image_alt\\"]");
								if (altField) { altField.value = j.data.alt || j.data.display || ""; }
							} else {
								status.textContent = "Error: " + (j.data && j.data.message || "unknown");
							}
						})
						.catch(function(e){
							btn.disabled = false;
							status.textContent = "Failed: " + e.message;
						});
				});
			})();
		</script>';

		$form_fields['acalt_generate_now'] = array(
			'label' => '',
			'input' => 'html',
			'html'  => $html,
		);
		return $form_fields;
	}

	public static function inject_row_action( $actions, $post ) {
		if ( ! $post || strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return $actions;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}

		$nonce = wp_create_nonce( 'acalt_generate_now' );
		$existing_alt = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		$label = $existing_alt !== '' ? 'Regenerate alt (AI)' : 'Generate alt (AI)';

		$actions['acalt_generate_now'] = sprintf(
			'<a href="#" class="acalt-row-generate" data-attachment-id="%d" data-nonce="%s">%s</a> <span class="acalt-row-status" style="color:#666;"></span>',
			(int) $post->ID,
			esc_attr( $nonce ),
			esc_html( $label )
		);

		// Inline the click handler once (we use a global flag).
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'row_action_script' ), 99 );

		return $actions;
	}

	public static function row_action_script() {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<script>
		(function(){
			if (window.__acaltRowBound) return; window.__acaltRowBound = true;
			document.addEventListener("click", function(e) {
				var a = e.target.closest("a.acalt-row-generate");
				if (!a) return;
				e.preventDefault();
				var id = a.dataset.attachmentId;
				var status = a.parentNode.querySelector(".acalt-row-status");
				a.style.pointerEvents = "none";
				status.textContent = "Generating…";
				var fd = new FormData();
				fd.append("action", "acalt_generate_now");
				fd.append("_ajax_nonce", a.dataset.nonce);
				fd.append("attachment_id", id);
				fd.append("overwrite", "1");
				fetch(ajaxurl, { method: "POST", body: fd, credentials: "same-origin" })
					.then(function(r){ return r.json(); })
					.then(function(j){
						a.style.pointerEvents = "";
						if (j.success) {
							status.textContent = "Done.";
						} else {
							status.textContent = "Error: " + (j.data && j.data.message || "unknown");
						}
					})
					.catch(function(e){
						a.style.pointerEvents = "";
						status.textContent = "Failed: " + e.message;
					});
			});
		})();
		</script>
		<?php
	}

	public static function enqueue_modal_assets() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$nonce = wp_create_nonce( 'acalt_generate_now' );
		$inline = "
			(function(\$){
				if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.Attachment || !wp.media.view.Attachment.Details) return;
				if (wp.media.view.Attachment.Details.__acaltExtended) return;
				wp.media.view.Attachment.Details.__acaltExtended = true;

				var Orig = wp.media.view.Attachment.Details;
				wp.media.view.Attachment.Details = Orig.extend({
					render: function() {
						Orig.prototype.render.apply(this, arguments);
						this.injectAcaltButton();
						return this;
					},
					injectAcaltButton: function() {
						var view = this;
						var \$el = this.\$el;
						if (!\$el || \$el.find('.acalt-modal-generate').length) return;
						// Find the alt-text input row.
						var \$alt = \$el.find('[data-setting=\"alt\"] input, [data-setting=\"alt\"] textarea').first();
						if (!\$alt.length) return;
						var attId = this.model.get('id');
						if (!attId) return;
						if ((this.model.get('type') || '').indexOf('image') !== 0) return;

						var \$btn = \$('<button type=\"button\" class=\"button button-secondary acalt-modal-generate\" style=\"margin-top:6px;\">Generate alt with AI</button>');
						var \$status = \$('<span class=\"acalt-modal-status\" style=\"margin-left:8px;color:#666;\"></span>');
						\$alt.closest('.setting').append(\$('<div></div>').append(\$btn, \$status));

						\$btn.on('click', function(e) {
							e.preventDefault();
							\$btn.prop('disabled', true);
							\$status.text('Generating…');
							\$.post(ajaxurl, {
								action: 'acalt_generate_now',
								_ajax_nonce: '" . esc_js( $nonce ) . "',
								attachment_id: attId,
								overwrite: '1'
							}).done(function(resp) {
								\$btn.prop('disabled', false);
								if (resp.success) {
									var alt = (resp.data && (resp.data.alt || resp.data.display)) || '';
									view.model.set('alt', alt);
									\$alt.val(alt).trigger('input').trigger('change');
									\$status.text('Done.');
								} else {
									\$status.text('Error: ' + (resp.data && resp.data.message || 'unknown'));
								}
							}).fail(function(jq) {
								\$btn.prop('disabled', false);
								\$status.text('Request failed (' + jq.status + ').');
							});
						});
					}
				});
			})(jQuery);
		";
		wp_add_inline_script( 'media-views', $inline );
	}
}
