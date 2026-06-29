<?php
/**
 * amplifi.consent — admin.
 *
 * One page under amplifi.studio with three tabs:
 *   Settings  — banner copy, colors, consent duration.
 *   Scripts   — add/edit/remove the tracking scripts to gate, each with a category.
 *   Cookies   — the categorized cookie catalog, populated by the iframe scanner.
 *
 * The scanner loads each managed script inside a same-origin sandbox iframe
 * (admin-ajax harness) and reads document.cookie to discover the cookies it
 * sets, so the admin can categorize them. Those categorizations drive the
 * Manage UI on the front end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Admin {

	const NONCE = 'acconsent_admin';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_acconsent_harness', array( __CLASS__, 'harness' ) );
	}

	public static function assets( $hook ) {
		if ( 'amplifi-studio_page_amplifi-ac-consent' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'acconsent-admin', ACCONSENT_PLUGIN_URL . 'assets/js/admin.js', array(), ACCONSENT_VERSION, true );
		wp_localize_script( 'acconsent-admin', 'ACCONSENT_ADMIN', array(
			'harness_url' => admin_url( 'admin-ajax.php?action=acconsent_harness&_ajax_nonce=' . wp_create_nonce( 'acconsent_harness' ) ),
		) );
		wp_add_inline_style( 'wp-admin', self::inline_css() );
	}

	private static function inline_css() {
		return '
		.acconsent-wrap .nav-tab-wrapper { margin-bottom: 18px; }
		.acconsent-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:18px 20px; margin-bottom:18px; max-width:920px; }
		.acconsent-card h2 { margin-top:0; }
		.acconsent-field { margin-bottom:14px; }
		.acconsent-field label { display:block; font-weight:600; margin-bottom:4px; }
		.acconsent-field input[type=text], .acconsent-field input[type=number], .acconsent-field textarea, .acconsent-field select { width:100%; max-width:560px; }
		.acconsent-field textarea { min-height:70px; font-family:Menlo,Consolas,monospace; font-size:12px; }
		.acconsent-script-row td { vertical-align:top; }
		.acconsent-cat-pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#eef1f3; color:#2c3a47; }
		.acconsent-code-cell textarea { width:100%; min-height:80px; font-family:Menlo,Consolas,monospace; font-size:11px; }
		.acconsent-scan-result { font-size:12px; color:#1d6f73; margin-top:6px; }
		.acconsent-muted { color:#646970; font-size:12px; }
		';
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		return in_array( $tab, array( 'settings', 'scripts', 'cookies' ), true ) ? $tab : 'settings';
	}

	private static function tab_url( $tab ) {
		return esc_url( admin_url( 'admin.php?page=amplifi-ac-consent&tab=' . $tab ) );
	}

	public static function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::handle_post();
		$tab = self::current_tab();
		echo '<div class="wrap acconsent-wrap">';
		echo '<h1>amplifi.consent</h1>';
		echo '<p class="acconsent-muted">Scripts you add here are <strong>withheld</strong> from the browser until the visitor accepts. Nothing tracking-related runs on reject.</p>';

		echo '<h2 class="nav-tab-wrapper">';
		printf( '<a href="%s" class="nav-tab %s">Settings</a>', self::tab_url( 'settings' ), 'settings' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">Scripts</a>', self::tab_url( 'scripts' ), 'scripts' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">Cookies</a>', self::tab_url( 'cookies' ), 'cookies' === $tab ? 'nav-tab-active' : '' );
		echo '</h2>';

		if ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'scripts' === $tab ) {
			self::render_scripts();
		} else {
			self::render_cookies();
		}
		echo '</div>';
	}

	/* ---------------- POST handling ---------------- */

	private static function handle_post() {
		if ( empty( $_POST['acconsent_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( $_POST['acconsent_action'] );

		if ( 'save_settings' === $action ) {
			$raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
			$raw['enabled'] = isset( $_POST['settings']['enabled'] ) ? 1 : 0;
			Amplifi_Consent_Store::save_settings( $raw );
			self::notice( 'Settings saved.' );
		} elseif ( 'save_scripts' === $action ) {
			$rows = isset( $_POST['scripts'] ) ? wp_unslash( $_POST['scripts'] ) : array();
			// Normalize enabled checkboxes (unchecked boxes don't post).
			foreach ( $rows as $i => $r ) {
				$rows[ $i ]['enabled'] = isset( $r['enabled'] ) ? 1 : 0;
			}
			Amplifi_Consent_Store::save_scripts( $rows );
			self::notice( 'Scripts saved.' );
		} elseif ( 'add_script' === $action ) {
			$scripts   = Amplifi_Consent_Store::get_scripts();
			$scripts[] = array(
				'label'     => isset( $_POST['new_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_label'] ) ) : 'New script',
				'category'  => isset( $_POST['new_category'] ) ? sanitize_key( $_POST['new_category'] ) : 'analytics',
				'placement' => isset( $_POST['new_placement'] ) ? sanitize_key( $_POST['new_placement'] ) : 'head',
				'code'      => isset( $_POST['new_code'] ) ? wp_unslash( $_POST['new_code'] ) : '',
				'enabled'   => 1,
			);
			Amplifi_Consent_Store::save_scripts( $scripts );
			self::notice( 'Script added.' );
		} elseif ( 'delete_script' === $action ) {
			$id      = isset( $_POST['script_id'] ) ? sanitize_key( $_POST['script_id'] ) : '';
			$scripts = array_filter( Amplifi_Consent_Store::get_scripts(), function ( $s ) use ( $id ) {
				return $s['id'] !== $id;
			} );
			Amplifi_Consent_Store::save_scripts( $scripts );
			self::notice( 'Script deleted.' );
		} elseif ( 'save_cookies' === $action ) {
			$rows = isset( $_POST['cookies'] ) ? wp_unslash( $_POST['cookies'] ) : array();
			Amplifi_Consent_Store::save_cookies( $rows );
			self::notice( 'Cookie catalog saved.' );
		} elseif ( 'merge_cookies' === $action ) {
			$detected  = isset( $_POST['detected'] ) ? json_decode( wp_unslash( $_POST['detected'] ), true ) : array();
			$script_id = isset( $_POST['script_id'] ) ? sanitize_key( $_POST['script_id'] ) : '';
			if ( is_array( $detected ) ) {
				Amplifi_Consent_Store::merge_detected_cookies( $detected, $script_id );
				self::notice( count( $detected ) . ' detected cookie(s) merged into the catalog. Categorize them below.' );
			}
		}
	}

	private static function notice( $msg ) {
		add_action( 'admin_notices', function () use ( $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		} );
		// add_action after admin_notices already fired on this hook → echo inline as fallback.
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/* ---------------- Settings tab ---------------- */

	private static function render_settings() {
		$s = Amplifi_Consent_Store::get_settings();
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_settings">';

		echo '<h2>Banner</h2>';
		self::field_checkbox( 'settings[enabled]', 'Consent banner enabled', $s['enabled'] );
		self::field_text( 'settings[banner_title]', 'Title', $s['banner_title'] );
		self::field_textarea( 'settings[banner_message]', 'Message', $s['banner_message'] );

		echo '<h2>Buttons & toasts</h2>';
		self::field_text( 'settings[accept_label]', 'Accept button', $s['accept_label'] );
		self::field_text( 'settings[reject_label]', 'Reject button', $s['reject_label'] );
		self::field_text( 'settings[manage_label]', 'Manage button', $s['manage_label'] );
		self::field_text( 'settings[save_label]', 'Save-choices button', $s['save_label'] );
		self::field_text( 'settings[toast_accepted]', 'Toast on accept/save', $s['toast_accepted'] );
		self::field_text( 'settings[toast_rejected]', 'Toast on reject', $s['toast_rejected'] );

		echo '<h2>Behavior</h2>';
		self::field_number( 'settings[consent_days]', 'Consent remembered for (days)', $s['consent_days'], 1, 365 );
		echo '<div class="acconsent-field"><label>Banner position</label><select name="settings[position]">';
		foreach ( array( 'bottom' => 'Bottom bar', 'center' => 'Centered modal' ) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['position'], $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		echo '<div class="acconsent-field"><label>Accent color</label><input type="text" name="settings[accent_color]" value="' . esc_attr( $s['accent_color'] ) . '" placeholder="#055c5f"></div>';

		submit_button( 'Save settings' );
		echo '</form>';
	}

	/* ---------------- Scripts tab ---------------- */

	private static function render_scripts() {
		$scripts    = Amplifi_Consent_Store::get_scripts();
		$categories = Amplifi_Consent_Store::categories();

		// Add new.
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="add_script">';
		echo '<h2>Add a tracking script</h2>';
		echo '<p class="acconsent-muted">Paste the full snippet (the whole <code>&lt;script&gt;...&lt;/script&gt;</code>, including external <code>src</code> loaders). It will be withheld until the visitor consents to its category.</p>';
		self::field_text( 'new_label', 'Label', '', 'e.g. Google Analytics 4' );
		echo '<div class="acconsent-field"><label>Category</label><select name="new_category">';
		foreach ( $categories as $key => $cat ) {
			if ( ! empty( $cat['locked'] ) ) { continue; }
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $cat['label'] ) );
		}
		echo '</select></div>';
		echo '<div class="acconsent-field"><label>Placement</label><select name="new_placement">';
		foreach ( array( 'head' => '<head>', 'body_open' => 'After <body>', 'footer' => 'Footer' ) as $val => $lbl ) {
			printf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		self::field_textarea( 'new_code', 'Script code', '', '<script>...</script>' );
		submit_button( 'Add script', 'primary', 'submit', false );
		echo '</form>';

		if ( empty( $scripts ) ) {
			echo '<div class="acconsent-card"><p class="acconsent-muted">No scripts yet. Add one above.</p></div>';
			return;
		}

		// Edit existing.
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_scripts">';
		echo '<h2>Managed scripts</h2>';
		echo '<table class="widefat striped"><thead><tr><th>On</th><th>Label</th><th>Category</th><th>Placement</th><th>Code</th><th>Scan</th><th></th></tr></thead><tbody>';
		foreach ( $scripts as $i => $s ) {
			echo '<tr class="acconsent-script-row">';
			echo '<td><input type="hidden" name="scripts[' . $i . '][id]" value="' . esc_attr( $s['id'] ) . '"><input type="checkbox" name="scripts[' . $i . '][enabled]" value="1" ' . checked( $s['enabled'], true, false ) . '></td>';
			echo '<td><input type="text" name="scripts[' . $i . '][label]" value="' . esc_attr( $s['label'] ) . '"></td>';
			echo '<td><select name="scripts[' . $i . '][category]">';
			foreach ( $categories as $key => $cat ) {
				if ( ! empty( $cat['locked'] ) ) { continue; }
				printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['category'], $key, false ), esc_html( $cat['label'] ) );
			}
			echo '</select></td>';
			echo '<td><select name="scripts[' . $i . '][placement]">';
			foreach ( array( 'head' => 'head', 'body_open' => 'body', 'footer' => 'footer' ) as $val => $lbl ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['placement'], $val, false ), esc_html( $lbl ) );
			}
			echo '</select></td>';
			echo '<td class="acconsent-code-cell"><textarea name="scripts[' . $i . '][code]">' . esc_textarea( $s['code'] ) . '</textarea></td>';
			echo '<td><button type="button" class="button acconsent-scan-btn" data-script-id="' . esc_attr( $s['id'] ) . '">Scan cookies</button><div class="acconsent-scan-result" data-for="' . esc_attr( $s['id'] ) . '"></div></td>';
			echo '<td><button type="submit" class="button-link-delete" name="acconsent_inline_delete" value="' . esc_attr( $s['id'] ) . '" formaction="" onclick="return false;" style="display:none"></button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( 'Save changes' );
		echo '</form>';

		// Per-script delete forms + merge-cookies form (used by scanner JS).
		foreach ( $scripts as $s ) {
			echo '<form method="post" style="display:inline" class="acconsent-del-form">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="acconsent_action" value="delete_script">';
			echo '<input type="hidden" name="script_id" value="' . esc_attr( $s['id'] ) . '">';
			echo '<button type="submit" class="button-link-delete" onclick="return confirm(\'Delete this script?\')">Delete: ' . esc_html( $s['label'] ? $s['label'] : $s['id'] ) . '</button> &nbsp;';
			echo '</form>';
		}

		// Hidden merge form posted by the scanner JS after it detects cookies.
		echo '<form method="post" id="acconsent-merge-form" style="display:none">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="merge_cookies">';
		echo '<input type="hidden" name="script_id" id="acconsent-merge-script-id" value="">';
		echo '<input type="hidden" name="detected" id="acconsent-merge-detected" value="">';
		echo '</form>';
	}

	/* ---------------- Cookies tab ---------------- */

	private static function render_cookies() {
		$cookies    = Amplifi_Consent_Store::get_cookies();
		$categories = Amplifi_Consent_Store::categories();
		$scripts    = array();
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			$scripts[ $s['id'] ] = $s['label'] ? $s['label'] : $s['id'];
		}

		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_cookies">';
		echo '<h2>Cookie catalog</h2>';
		echo '<p class="acconsent-muted">Detected cookies appear here after a scan. Assign each to a category — this is exactly what visitors see in the Manage panel.</p>';

		if ( empty( $cookies ) ) {
			echo '<p class="acconsent-muted">No cookies catalogued yet. Go to the <strong>Scripts</strong> tab and click “Scan cookies” on a script.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Category</th><th>Set by</th><th>Domain</th><th>Duration</th><th>Description</th></tr></thead><tbody>';
			foreach ( $cookies as $i => $c ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $c['name'] ) . '</strong><input type="hidden" name="cookies[' . $i . '][name]" value="' . esc_attr( $c['name'] ) . '"></td>';
				echo '<td><select name="cookies[' . $i . '][category]">';
				foreach ( $categories as $key => $cat ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $c['category'], $key, false ), esc_html( $cat['label'] ) );
				}
				echo '</select></td>';
				$set_by = isset( $scripts[ $c['script_id'] ] ) ? $scripts[ $c['script_id'] ] : '—';
				echo '<td>' . esc_html( $set_by ) . '<input type="hidden" name="cookies[' . $i . '][script_id]" value="' . esc_attr( $c['script_id'] ) . '"></td>';
				echo '<td><input type="text" name="cookies[' . $i . '][domain]" value="' . esc_attr( $c['domain'] ) . '" size="14"></td>';
				echo '<td><input type="text" name="cookies[' . $i . '][duration]" value="' . esc_attr( $c['duration'] ) . '" size="8"></td>';
				echo '<td><input type="text" name="cookies[' . $i . '][description]" value="' . esc_attr( $c['description'] ) . '"></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			submit_button( 'Save catalog' );
		}
		echo '</form>';
	}

	/* ---------------- Scanner harness (same-origin iframe) ---------------- */

	/**
	 * Outputs a minimal same-origin HTML page that injects a managed script,
	 * waits, reads document.cookie, and postMessages the discovered cookie
	 * names back to the opener. Because it is same-origin (admin-ajax on this
	 * site), document.cookie is readable for the first-party cookies the script
	 * sets (the analytics/marketing JS cookies we care about).
	 */
	public static function harness() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_ajax_referer( 'acconsent_harness' );
		$id     = isset( $_GET['script_id'] ) ? sanitize_key( $_GET['script_id'] ) : '';
		$script = Amplifi_Consent_Store::get_script( $id );
		if ( ! $script ) {
			wp_die( 'unknown script' );
		}

		// Snapshot cookies before injection so we can diff.
		header( 'Content-Type: text/html; charset=utf-8' );
		nocache_headers();
		?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>amplifi.consent scan</title></head>
<body>
<p style="font:13px sans-serif;color:#555">Running script in a sandbox to detect cookies…</p>
<script>
(function(){
  var before = {};
  document.cookie.split(';').forEach(function(p){ var n=p.split('=')[0].trim(); if(n) before[n]=1; });
  window.__acconsentBefore = before;
})();
</script>
<?php
		// Inject the actual managed script verbatim. This DOES run here — that's
		// the point of the sandbox: observe what it sets, in isolation.
		echo $script['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
<script>
(function(){
  function collect(){
    var found = [];
    var host = location.hostname;
    document.cookie.split(';').forEach(function(p){
      var n = p.split('=')[0].trim();
      if(!n) return;
      if(window.__acconsentBefore && window.__acconsentBefore[n]) return; // pre-existing
      found.push({ name: n, domain: host });
    });
    try {
      (window.opener || window.parent).postMessage({ acconsent: true, scriptId: <?php echo wp_json_encode( $id ); ?>, cookies: found }, '*');
    } catch(e){}
    var out = document.createElement('pre');
    out.style.cssText='font:12px monospace;color:#1d6f73';
    out.textContent = found.length ? ('Detected: ' + found.map(function(c){return c.name;}).join(', ')) : 'No new first-party cookies detected.';
    document.body.appendChild(out);
  }
  // Give the script time to set cookies (GA/Clarity set on load).
  setTimeout(collect, 2500);
})();
</script>
</body></html>
<?php
		exit;
	}

	/* ---------------- field helpers ---------------- */

	private static function field_text( $name, $label, $value, $placeholder = '' ) {
		echo '<div class="acconsent-field"><label>' . esc_html( $label ) . '</label>';
		echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"></div>';
	}

	private static function field_number( $name, $label, $value, $min, $max ) {
		echo '<div class="acconsent-field"><label>' . esc_html( $label ) . '</label>';
		echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '"></div>';
	}

	private static function field_textarea( $name, $label, $value, $placeholder = '' ) {
		echo '<div class="acconsent-field"><label>' . esc_html( $label ) . '</label>';
		echo '<textarea name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea></div>';
	}

	private static function field_checkbox( $name, $label, $checked ) {
		echo '<div class="acconsent-field"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label></div>';
	}
}
