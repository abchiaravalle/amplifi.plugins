<?php
/**
 * amplifi.consent — admin.
 *
 * One page under amplifi.studio with three tabs:
 *   Settings  — banner copy, colors, consent duration.
 *   Scripts   — add/edit/remove the tracking scripts to gate, each with a category.
 *   Cookies   — the categorized cookie catalog, populated by the iframe scanner.
 *
 * The scanner loads each managed script inside a same-origin admin harness
 * iframe (admin-ajax) and reads document.cookie to discover the cookies it
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
		// C4: alert admins (not just silently fail) when the consent-log
		// self-heal migration can't complete and writes are failing — the
		// banner still looks fully functional while nothing is being
		// recorded, which is worse than an obviously broken feature.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_failure_notice' ) );
	}

	public static function assets( $hook ) {
		if ( 'amplifi-studio_page_amplifi-ac-consent' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'acconsent-admin', ACCONSENT_PLUGIN_URL . 'assets/js/admin.js', array(), ACCONSENT_VERSION, true );
		wp_localize_script( 'acconsent-admin', 'ACCONSENT_ADMIN', array(
			'harness_url' => admin_url( 'admin-ajax.php?action=acconsent_harness&_ajax_nonce=' . wp_create_nonce( 'acconsent_harness' ) ),
			'i18n'        => array(
				'confirm_scan' => __( 'This runs the script once to detect its cookies and may contact the third party. Continue?', 'amplifi-consent' ),
				'scanning'     => __( 'Scanning…', 'amplifi-consent' ),
				/* translators: %1$d: number of cookies, %2$s: comma-separated cookie names. */
				'found'        => __( 'Found %1$d cookie(s): %2$s — saving…', 'amplifi-consent' ),
				'none'         => __( 'No new first-party cookies detected.', 'amplifi-consent' ),
				'timed_out'    => __( 'Scan timed out (no cookies reported).', 'amplifi-consent' ),
			),
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
		.acconsent-status { max-width:920px; padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:13px; border:1px solid; }
		.acconsent-status-ok { background:#edfaef; border-color:#a7d8b0; color:#1a5e2a; }
		.acconsent-status-warn { background:#fcf3e6; border-color:#e0bd7a; color:#8a5a00; }
		';
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		return in_array( $tab, array( 'settings', 'scripts', 'cookies', 'legal', 'log' ), true ) ? $tab : 'settings';
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
		echo '<p class="acconsent-muted">' . esc_html__( 'Managed scripts are', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'withheld', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'until the visitor consents to their category. Every consent choice is recorded server-side (Consent Log) and can be mirrored to a webhook. Enable', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'Auto-block', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'in Settings to also gate trackers added by other plugins/the theme.', 'amplifi-consent' ) . '</p>';

		echo '<h2 class="nav-tab-wrapper">';
		printf( '<a href="%s" class="nav-tab %s">' . esc_html__( 'Settings', 'amplifi-consent' ) . '</a>', self::tab_url( 'settings' ), 'settings' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">' . esc_html__( 'Scripts', 'amplifi-consent' ) . '</a>', self::tab_url( 'scripts' ), 'scripts' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">' . esc_html__( 'Cookies', 'amplifi-consent' ) . '</a>', self::tab_url( 'cookies' ), 'cookies' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">' . esc_html__( 'Legal Docs', 'amplifi-consent' ) . '</a>', self::tab_url( 'legal' ), 'legal' === $tab ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">' . esc_html__( 'Consent Log', 'amplifi-consent' ) . '</a>', self::tab_url( 'log' ), 'log' === $tab ? 'nav-tab-active' : '' );
		echo '</h2>';

		if ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'scripts' === $tab ) {
			self::render_scripts();
		} elseif ( 'cookies' === $tab ) {
			self::render_cookies();
		} elseif ( 'legal' === $tab ) {
			self::render_legal();
		} else {
			self::render_log();
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
			// Checkboxes don't post when unchecked — normalize each to 0/1.
			foreach ( array( 'enabled', 'floating_button', 'webhook_enabled', 'gpc_enabled', 'consent_mode', 'autoblock', 'do_not_sell', 'trust_proxy', 'limit_spi_enabled' ) as $cb ) {
				$raw[ $cb ] = isset( $_POST['settings'][ $cb ] ) ? 1 : 0;
			}
			Amplifi_Consent_Store::save_settings( $raw );
			self::notice( __( 'Settings saved.', 'amplifi-consent' ) );
		} elseif ( 'test_webhook' === $action ) {
			$res = Amplifi_Consent_Webhook::test();
			/* translators: %s: webhook test result message returned by the receiver */
			self::notice( sprintf( __( 'Webhook test: %s', 'amplifi-consent' ), $res['message'] ) );
		} elseif ( 'save_legal_doc' === $action ) {
			$doc = Amplifi_Consent_Store::save_legal_doc( array(
				'id'    => isset( $_POST['doc_id'] ) ? sanitize_key( $_POST['doc_id'] ) : '',
				'title' => isset( $_POST['doc_title'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_title'] ) ) : '',
				'slug'  => isset( $_POST['doc_slug'] ) ? sanitize_title( wp_unslash( $_POST['doc_slug'] ) ) : '',
				'type'  => isset( $_POST['doc_type'] ) ? sanitize_key( $_POST['doc_type'] ) : 'custom',
			) );
			// If content was provided, publish a version immediately.
			if ( ! empty( $_POST['doc_content'] ) ) {
				Amplifi_Consent_Store::publish_legal_version(
					$doc['id'],
					isset( $_POST['doc_version'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_version'] ) ) : '',
					wp_unslash( $_POST['doc_content'] )
				);
			}
			self::notice( __( 'Legal document saved.', 'amplifi-consent' ) );
		} elseif ( 'publish_legal_version' === $action ) {
			$id = isset( $_POST['doc_id'] ) ? sanitize_key( $_POST['doc_id'] ) : '';
			Amplifi_Consent_Store::publish_legal_version(
				$id,
				isset( $_POST['doc_version'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_version'] ) ) : '',
				isset( $_POST['doc_content'] ) ? wp_unslash( $_POST['doc_content'] ) : ''
			);
			self::notice( __( 'New version published. Returning visitors will be re-prompted to consent against the updated text.', 'amplifi-consent' ) );
		} elseif ( 'delete_legal_doc' === $action ) {
			Amplifi_Consent_Store::delete_legal_doc( isset( $_POST['doc_id'] ) ? sanitize_key( $_POST['doc_id'] ) : '' );
			self::notice( __( 'Legal document deleted.', 'amplifi-consent' ) );
		} elseif ( 'save_scripts' === $action ) {
			$rows = isset( $_POST['scripts'] ) ? wp_unslash( $_POST['scripts'] ) : array();
			// Normalize enabled/sale_share/sensitive_pi/consent_mode checkboxes (unchecked boxes don't post).
			foreach ( $rows as $i => $r ) {
				$rows[ $i ]['enabled']      = isset( $r['enabled'] ) ? 1 : 0;
				$rows[ $i ]['sale_share']   = isset( $r['sale_share'] ) ? 1 : 0;
				$rows[ $i ]['sensitive_pi'] = isset( $r['sensitive_pi'] ) ? 1 : 0;
				$rows[ $i ]['consent_mode'] = isset( $r['consent_mode'] ) ? 1 : 0;
			}
			Amplifi_Consent_Store::save_scripts( $rows );
			self::notice( __( 'Scripts saved.', 'amplifi-consent' ) );
		} elseif ( 'add_script' === $action ) {
			$scripts   = Amplifi_Consent_Store::get_scripts();
			$scripts[] = array(
				'label'        => isset( $_POST['new_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_label'] ) ) : 'New script',
				'category'     => isset( $_POST['new_category'] ) ? sanitize_key( $_POST['new_category'] ) : 'analytics',
				'placement'    => isset( $_POST['new_placement'] ) ? sanitize_key( $_POST['new_placement'] ) : 'head',
				'code'         => isset( $_POST['new_code'] ) ? wp_unslash( $_POST['new_code'] ) : '',
				'enabled'      => 1,
				'sale_share'   => isset( $_POST['new_sale_share'] ) ? 1 : 0,
				'sensitive_pi' => isset( $_POST['new_sensitive_pi'] ) ? 1 : 0,
				'consent_mode' => isset( $_POST['new_consent_mode'] ) ? 1 : 0,
			);
			Amplifi_Consent_Store::save_scripts( $scripts );
			self::notice( __( 'Script added.', 'amplifi-consent' ) );
		} elseif ( 'delete_script' === $action ) {
			$id      = isset( $_POST['script_id'] ) ? sanitize_key( $_POST['script_id'] ) : '';
			$scripts = array_filter( Amplifi_Consent_Store::get_scripts(), function ( $s ) use ( $id ) {
				return $s['id'] !== $id;
			} );
			Amplifi_Consent_Store::save_scripts( $scripts );
			self::notice( __( 'Script deleted.', 'amplifi-consent' ) );
		} elseif ( 'save_cookies' === $action ) {
			$rows = isset( $_POST['cookies'] ) ? wp_unslash( $_POST['cookies'] ) : array();
			Amplifi_Consent_Store::save_cookies( $rows );
			self::notice( __( 'Cookie catalog saved.', 'amplifi-consent' ) );
		} elseif ( 'merge_cookies' === $action ) {
			$detected  = isset( $_POST['detected'] ) ? json_decode( wp_unslash( $_POST['detected'] ), true ) : array();
			$script_id = isset( $_POST['script_id'] ) ? sanitize_key( $_POST['script_id'] ) : '';
			if ( is_array( $detected ) ) {
				Amplifi_Consent_Store::merge_detected_cookies( $detected, $script_id );
				/* translators: %d: number of detected cookies merged into the catalog */
				self::notice( sprintf( __( '%d detected cookie(s) merged into the catalog. Categorize them below.', 'amplifi-consent' ), count( $detected ) ) );
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

	/**
	 * C4: surface a persistent admin notice when the consent-log write path
	 * has been failing (5+ times, alerted via email too — see
	 * Amplifi_Consent_Log::note_write_failure()). Shown once 3+ failures have
	 * accumulated so a single transient blip doesn't nag; cleared
	 * automatically on the next successful write.
	 */
	public static function maybe_render_failure_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$alert = Amplifi_Consent_Log::get_alert();
		if ( ! $alert || empty( $alert['count'] ) || $alert['count'] < 3 ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'amplifi.consent:', 'amplifi-consent' ),
			esc_html(
				sprintf(
					/* translators: 1: number of failed writes, 2: last database error message */
					__( 'The consent banner is showing, but %1$d recent server-side consent-log write(s) have failed — consent is not being recorded. Last error: %2$s. Check the Consent Log tab and your database permissions.', 'amplifi-consent' ),
					(int) $alert['count'],
					isset( $alert['last_error'] ) ? $alert['last_error'] : ''
				)
			)
		);
	}

	/* ---------------- Settings tab ---------------- */

	private static function render_settings() {
		$s = Amplifi_Consent_Store::get_settings();

		// Coverage status: tell the operator exactly how many trackers are
		// actually being gated, so a non-technical user can't unknowingly ship
		// a banner that withholds nothing.
		// Count only ENABLED managed scripts — a disabled script is neither
		// emitted nor gated, so it must not inflate the coverage badge.
		$managed_count = 0;
		foreach ( Amplifi_Consent_Store::get_scripts() as $sc ) {
			if ( ! empty( $sc['enabled'] ) ) {
				$managed_count++;
			}
		}
		$autoblock_on  = ! empty( $s['autoblock'] );
		$enabled_on    = ! empty( $s['enabled'] );
		if ( ! $enabled_on ) {
			$status_class = 'acconsent-status-warn';
			$status_msg   = esc_html__( 'The consent banner is currently', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'disabled', 'amplifi-consent' ) . '</strong> ' . esc_html__( '— no scripts are being gated.', 'amplifi-consent' );
		} elseif ( 0 === $managed_count && ! $autoblock_on ) {
			$status_class = 'acconsent-status-warn';
			$status_msg   = esc_html__( 'You are gating', 'amplifi-consent' ) . ' <strong>' . esc_html__( '0 trackers', 'amplifi-consent' ) . '</strong>' . esc_html__( ': no managed scripts are configured and auto-block is OFF. Trackers loaded by your theme or other plugins will fire before consent. Add your tracking scripts on the Scripts tab, or turn on auto-block below.', 'amplifi-consent' );
		} else {
			$status_class = 'acconsent-status-ok';
			$status_msg   = esc_html__( 'Gating', 'amplifi-consent' ) . ' <strong>' . sprintf(
				/* translators: 1: number of managed scripts being gated, 2: plural suffix ('s' or empty string) */
				esc_html__( '%1$d managed script%2$s', 'amplifi-consent' ),
				$managed_count,
				1 === $managed_count ? '' : 's'
			) . '</strong>; ' . sprintf(
				/* translators: %s: auto-block state ('ON' or 'OFF'), wrapped in <strong> markup */
				esc_html__( 'auto-block for unmanaged trackers is %s', 'amplifi-consent' ),
				'<strong>' . ( $autoblock_on ? esc_html__( 'ON', 'amplifi-consent' ) : esc_html__( 'OFF', 'amplifi-consent' ) ) . '</strong>'
			) . '.';
		}
		printf( '<div class="acconsent-status %s">%s</div>', esc_attr( $status_class ), wp_kses_post( $status_msg ) );

		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_settings">';

		echo '<h2>' . esc_html__( 'Banner', 'amplifi-consent' ) . '</h2>';
		self::field_checkbox( 'settings[enabled]', __( 'Consent banner enabled', 'amplifi-consent' ), $s['enabled'] );
		self::field_text( 'settings[banner_title]', __( 'Title', 'amplifi-consent' ), $s['banner_title'] );
		self::field_textarea( 'settings[banner_message]', __( 'Message', 'amplifi-consent' ), $s['banner_message'] );

		echo '<h2>' . esc_html__( 'Buttons & toasts', 'amplifi-consent' ) . '</h2>';
		self::field_text( 'settings[accept_label]', __( 'Accept button', 'amplifi-consent' ), $s['accept_label'] );
		self::field_text( 'settings[reject_label]', __( 'Reject button', 'amplifi-consent' ), $s['reject_label'] );
		self::field_text( 'settings[manage_label]', __( 'Manage button', 'amplifi-consent' ), $s['manage_label'] );
		self::field_text( 'settings[save_label]', __( 'Save-choices button', 'amplifi-consent' ), $s['save_label'] );
		self::field_text( 'settings[toast_accepted]', __( 'Toast on accept/save', 'amplifi-consent' ), $s['toast_accepted'] );
		self::field_text( 'settings[toast_rejected]', __( 'Toast on reject', 'amplifi-consent' ), $s['toast_rejected'] );

		echo '<h2>' . esc_html__( 'Behavior', 'amplifi-consent' ) . '</h2>';
		self::field_number( 'settings[consent_days]', __( 'Consent remembered for (days)', 'amplifi-consent' ), $s['consent_days'], 1, 365 );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Banner position', 'amplifi-consent' ) . '</label><select name="settings[position]">';
		foreach ( array( 'bottom' => __( 'Bottom bar', 'amplifi-consent' ), 'center' => __( 'Centered modal', 'amplifi-consent' ) ) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['position'], $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Accent color', 'amplifi-consent' ) . '</label><input type="text" name="settings[accent_color]" value="' . esc_attr( $s['accent_color'] ) . '" placeholder="#055c5f"></div>';

		echo '<h2>' . esc_html__( 'Disclosure & withdrawal', 'amplifi-consent' ) . '</h2>';
		self::field_text( 'settings[privacy_url]', __( 'Privacy Policy URL (shown on the banner before any choice)', 'amplifi-consent' ), $s['privacy_url'], 'https://…/privacy-policy/' );
		self::field_text( 'settings[prefs_label]', __( 'Preferences trigger label', 'amplifi-consent' ), $s['prefs_label'] );
		self::field_checkbox( 'settings[floating_button]', __( 'Show a persistent floating "preferences" button (always-available withdrawal path)', 'amplifi-consent' ), $s['floating_button'] );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Floating button side', 'amplifi-consent' ) . '</label><select name="settings[fab_position]">';
		foreach ( array( 'left' => __( 'Bottom left', 'amplifi-consent' ), 'right' => __( 'Bottom right', 'amplifi-consent' ) ) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['fab_position'], $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';

		echo '<h2>' . esc_html__( 'Consent record & proof', 'amplifi-consent' ) . '</h2>';
		self::field_text( 'settings[policy_version]', __( 'Policy version (bump to force everyone to re-consent)', 'amplifi-consent' ), $s['policy_version'] );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'IP handling in the consent log', 'amplifi-consent' ) . '</label><select name="settings[ip_mode]">';
		foreach ( array( 'hash' => __( 'Hashed (salted, non-reversible) — recommended', 'amplifi-consent' ), 'truncate' => __( 'Truncated (drop last octet)', 'amplifi-consent' ), 'none' => __( 'Do not store IP', 'amplifi-consent' ) ) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['ip_mode'], $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		self::field_number( 'settings[retention_days]', __( 'Delete consent-log rows older than (days) — 0 keeps them forever. New installs default to 1095 (3 years); existing sites keep their saved value. If you set a limit, keep it at or above ~730 to satisfy the CCPA 24-month record minimum.', 'amplifi-consent' ), isset( $s['retention_days'] ) ? $s['retention_days'] : 0, 0, 36500 );
		self::field_checkbox( 'settings[trust_proxy]', __( 'Behind Cloudflare / a reverse proxy: use the forwarded client IP for rate-limiting (CF-Connecting-IP / X-Forwarded-For). Leave OFF on a direct-connect origin — the forwarded header is client-spoofable there.', 'amplifi-consent' ), isset( $s['trust_proxy'] ) ? $s['trust_proxy'] : false );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'User-Agent recorded in the consent log', 'amplifi-consent' ) . '</label><select name="settings[ua_mode]">';
		foreach ( array(
			'minimal' => __( 'Minimal (browser + OS only) — recommended, default', 'amplifi-consent' ),
			'full'    => __( 'Full raw string', 'amplifi-consent' ),
			'none'    => __( 'Do not store', 'amplifi-consent' ),
		) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( isset( $s['ua_mode'] ) ? $s['ua_mode'] : 'minimal', $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		self::field_checkbox( 'settings[webhook_enabled]', __( 'Send each consent event to a webhook', 'amplifi-consent' ), $s['webhook_enabled'] );
		self::field_text( 'settings[webhook_url]', __( 'Webhook URL', 'amplifi-consent' ), $s['webhook_url'], 'https://…' );
		self::field_text( 'settings[webhook_secret]', __( 'Webhook secret (HMAC-SHA256 signs each payload)', 'amplifi-consent' ), $s['webhook_secret'] );
		echo '<p class="acconsent-muted">' . esc_html__( 'The receiver verifies the', 'amplifi-consent' ) . ' <code>X-Amplifi-Consent-Signature: sha256=…</code> ' . esc_html__( 'header. Delivery is non-blocking and fires after the database record is written. M5: when enabled, the front-end discloses to visitors that consent records may be sent to a data processor that may be located in a different country — configure the endpoint\'s hosting location/DPA accordingly.', 'amplifi-consent' ) . '</p>';

		echo '<h2>' . esc_html__( 'US / CCPA', 'amplifi-consent' ) . '</h2>';
		self::field_checkbox( 'settings[gpc_enabled]', __( 'Honor the Global Privacy Control (GPC) browser signal as an opt-out', 'amplifi-consent' ), $s['gpc_enabled'] );
		echo '<p class="acconsent-muted">' . esc_html__( 'Note: even when this is off, an incoming Global Privacy Control signal is still recorded on each consent event for audit purposes — it just no longer forces a deny.', 'amplifi-consent' ) . '</p>';
		self::field_checkbox( 'settings[do_not_sell]', __( 'Show a one-click "Do Not Sell or Share My Personal Information" opt-out button (CCPA/CPRA)', 'amplifi-consent' ), isset( $s['do_not_sell'] ) ? $s['do_not_sell'] : true );
		self::field_text( 'settings[dns_label]', __( '"Do Not Sell or Share" button label', 'amplifi-consent' ), isset( $s['dns_label'] ) ? $s['dns_label'] : 'Do Not Sell or Share My Personal Information' );
		echo '<p class="acconsent-muted">' . esc_html__( 'H1: GPC / "Do Not Sell" also withholds any tracking script or blocklist host explicitly flagged as constituting a "sale/share" (see the "Sale/share" checkbox on the Scripts tab, and the optional third |sale segment on blocklist lines below) — not just the Marketing category. This closes the gap where a site\'s own analytics bucket (GA4, Clarity, Hotjar, Segment, …) still discloses data to a third party even though it isn\'t labeled "marketing."', 'amplifi-consent' ) . '</p>';
		self::field_checkbox( 'settings[limit_spi_enabled]', __( 'Show a one-click "Limit the Use of My Sensitive Personal Information" opt-out button (CCPA §1798.121)', 'amplifi-consent' ), isset( $s['limit_spi_enabled'] ) ? $s['limit_spi_enabled'] : true );
		self::field_text( 'settings[limit_spi_label]', __( '"Limit the Use of Sensitive PI" button label', 'amplifi-consent' ), isset( $s['limit_spi_label'] ) ? $s['limit_spi_label'] : 'Limit the Use of My Sensitive Personal Information' );
		echo '<p class="acconsent-muted">' . esc_html__( 'H2: flag a script/host as handling Sensitive Personal Information ("Sensitive PI" checkbox on the Scripts tab, or the fourth |spi segment on a blocklist line) to have it withheld UNCONDITIONALLY while this is on — independent of any category grant, including "Accept all." This right was removed in v1.4.0 for being cosmetic (a category no script could actually use); it is now wired to real, unconditional blocking.', 'amplifi-consent' ) . '</p>';

		$placement = isset( $s['optout_placement'] ) ? $s['optout_placement'] : 'footer';
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Where the two opt-out controls appear', 'amplifi-consent' ) . '</label>';
		echo '<select name="settings[optout_placement]">';
		foreach ( array(
			'footer' => __( 'Page footer (default — a location the CCPA regulations name)', 'amplifi-consent' ),
			'banner' => __( 'Consent banner and preferences modal only (NOT compliant on its own — see below)', 'amplifi-consent' ),
			'both'   => __( 'Both the footer and the banner', 'amplifi-consent' ),
		) as $val => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $placement, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select></div>';
		echo '<p class="acconsent-muted">' . esc_html__( 'CCR tit.11 §7013(c) and §7014(c) each require a conspicuous link "located at either the header or footer of the business\'s internet homepage(s)". The consent banner is not one of the two named locations, and it disappears after the visitor\'s first choice — so "banner only" leaves the site with no compliant link at all. Place', 'amplifi-consent' ) . ' <code>[amplifi-do-not-sell]</code> ' . esc_html__( 'and', 'amplifi-consent' ) . ' <code>[amplifi-limit-spi]</code> ' . esc_html__( '(or', 'amplifi-consent' ) . ' <code>[amplifi-optout-links]</code> ' . esc_html__( 'for both at once) directly into your theme\'s footer link row: §7003(c) asks that the link "appear in a similar manner as other similarly-posted links", and these inherit type from whatever surrounds them. Any of those shortcodes suppresses the auto-rendered row, so the controls never appear twice. The auto-rendered row is only a fallback — wp_footer() usually fires AFTER the theme\'s footer element, so it lands at the very bottom of the page with no adjacent links to be similar to. CHECK IT ON YOUR THEME. This plugin renders the two separate links; the single combined "Your Privacy Choices" link under §7015 is a different arrangement and requires the CPPA opt-out icon.', 'amplifi-consent' ) . '</p>';

		echo '<div class="acconsent-field"><p class="acconsent-muted"><strong>' . esc_html__( 'Two things this plugin does NOT do for you — both are required and neither is automatic:', 'amplifi-consent' ) . '</strong></p><ol class="acconsent-muted">';
		echo '<li>' . esc_html__( 'Because clicking these controls takes effect immediately rather than opening a dedicated page, §7013(e)(1) and §7014(e)(1) require the Notice of Right to Opt-out and the Notice of Right to Limit to live IN YOUR PRIVACY POLICY. Each must describe the right and give instructions for every method of exercising it (§7013(f), §7014(f)). §7013(h) bars a business from selling or sharing personal information collected while no such notice was posted.', 'amplifi-consent' ) . '</li>';
		echo '<li>' . esc_html__( '§7026(a) requires TWO OR MORE methods for opt-out-of-sale/sharing requests, and §7027(b) requires two or more for limit requests. This link plus a Global Privacy Control signal satisfies the sale/sharing side when GPC honoring is on above. GPC is a sale/share signal only — it does NOT count toward the limit right, so a second method for that (an email address, a form, a phone number) must be offered and documented in your privacy policy.', 'amplifi-consent' ) . '</li>';
		echo '</ol></div>';

		echo '<h2>' . esc_html__( 'Google Consent Mode v2', 'amplifi-consent' ) . '</h2>';
		self::field_checkbox( 'settings[consent_mode]', __( 'Push Consent Mode v2 defaults (all denied) before tags, and update on choice', 'amplifi-consent' ), $s['consent_mode'] );

		echo '<h2>' . esc_html__( 'Auto-block unmanaged trackers', 'amplifi-consent' ) . '</h2>';
		self::field_checkbox( 'settings[autoblock]', __( 'Also gate third-party trackers added by OTHER plugins / the theme (by domain) until consent', 'amplifi-consent' ), $s['autoblock'] );
		self::field_textarea( 'settings[blocklist]', __( 'Blocked tracker domains (one per line)', 'amplifi-consent' ), $s['blocklist'] );
		echo '<p class="acconsent-muted">' . esc_html__( 'When on, any', 'amplifi-consent' ) . ' <code>&lt;script src&gt;</code>, <code>&lt;img&gt;</code>, ' . esc_html__( 'or', 'amplifi-consent' ) . ' <code>&lt;iframe&gt;</code> ' . esc_html__( 'pointing at one of these hosts is neutralized until the visitor consents. Each line is', 'amplifi-consent' ) . ' <code>host|category|sale|spi</code> ' . esc_html__( '— only', 'amplifi-consent' ) . ' <code>host</code> ' . esc_html__( 'is required; category defaults to', 'amplifi-consent' ) . ' <code>marketing</code>' . esc_html__( ' (the strictest opt-in) if omitted. Set', 'amplifi-consent' ) . ' <code>sale=1</code> ' . esc_html__( 'to also withhold this host under GPC/"Do Not Sell" even when its category is otherwise granted (see H1 above), and', 'amplifi-consent' ) . ' <code>spi=1</code> ' . esc_html__( 'to withhold it unconditionally while "Limit Sensitive PI" is on (H2). Example:', 'amplifi-consent' ) . ' <code>google-analytics.com|analytics|1</code>' . esc_html__( '. This catches trackers you did not paste into the Scripts tab, but only those present in the page HTML — trackers injected by JavaScript after load are caught by the network shim where possible.', 'amplifi-consent' ) . '</p>';

		submit_button( __( 'Save settings', 'amplifi-consent' ) );
		echo '</form>';

		// Webhook test (separate form).
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="test_webhook">';
		echo '<h2>' . esc_html__( 'Test webhook', 'amplifi-consent' ) . '</h2>';
		echo '<p class="acconsent-muted">' . esc_html__( 'Sends a signed', 'amplifi-consent' ) . ' <code>consent.test</code> ' . esc_html__( 'payload to the configured URL and reports the HTTP status.', 'amplifi-consent' ) . '</p>';
		submit_button( __( 'Send test', 'amplifi-consent' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/* ---------------- Legal Docs tab ---------------- */

	private static function render_legal() {
		$docs = Amplifi_Consent_Store::get_legal_docs();

		echo '<div class="acconsent-card">';
		echo '<h2>' . esc_html__( 'Versioned legal documents', 'amplifi-consent' ) . '</h2>';
		echo '<p class="acconsent-muted">' . esc_html__( 'Manage your Privacy Policy, Terms, and Cookie Policy here as', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'versioned', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'documents. Place each on a page with', 'amplifi-consent' ) . ' <code>[amplifi-legal-doc slug="&lt;slug&gt;"]</code>. ' . esc_html__( 'The', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'current version', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'of every published doc is stamped into every consent record, so the log proves exactly which policy texts a visitor agreed to. Publishing a new version re-prompts returning visitors.', 'amplifi-consent' ) . '</p>';
		echo '</div>';

		// Existing docs.
		foreach ( $docs as $id => $doc ) {
			$cur = Amplifi_Consent_Store::current_version( $doc );
			echo '<div class="acconsent-card">';
			echo '<h2>' . esc_html( $doc['title'] ) . ' <span class="acconsent-cat-pill">' . esc_html( $doc['type'] ) . '</span></h2>';
			echo '<p class="acconsent-muted">' . esc_html__( 'Shortcode:', 'amplifi-consent' ) . ' <code>[amplifi-legal-doc slug="' . esc_attr( $doc['slug'] ) . '"]</code>';
			if ( $cur ) {
				echo ' &nbsp;·&nbsp; ' . esc_html__( 'Current:', 'amplifi-consent' ) . ' <strong>' . esc_html( $cur['version'] ) . '</strong> (' . esc_html( $cur['published_at'] ) . ' ' . esc_html__( 'UTC', 'amplifi-consent' ) . ')';
			} else {
				echo ' &nbsp;·&nbsp; <em>' . esc_html__( 'No version published yet.', 'amplifi-consent' ) . '</em>';
			}
			/* translators: %d: number of saved versions of this legal document */
			echo ' &nbsp;·&nbsp; ' . sprintf( esc_html__( '%d version(s)', 'amplifi-consent' ), count( $doc['versions'] ) ) . '</p>';

			// Publish a new version.
			echo '<form method="post">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="acconsent_action" value="publish_legal_version">';
			echo '<input type="hidden" name="doc_id" value="' . esc_attr( $id ) . '">';
			/* translators: %d: next auto-generated version number */
			self::field_text( 'doc_version', sprintf( __( 'New version label (blank = auto v%d)', 'amplifi-consent' ), count( $doc['versions'] ) + 1 ), '' );
			self::field_textarea( 'doc_content', __( 'Document content (HTML allowed)', 'amplifi-consent' ), $cur ? $cur['content'] : '' );
			submit_button( __( 'Publish new version', 'amplifi-consent' ), 'primary', 'submit', false );
			echo ' ';
			echo '</form>';

			// Delete.
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="acconsent_action" value="delete_legal_doc">';
			echo '<input type="hidden" name="doc_id" value="' . esc_attr( $id ) . '">';
			echo '<button type="submit" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this document and all its versions?', 'amplifi-consent' ) ) . '\')">' . esc_html__( 'Delete document', 'amplifi-consent' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		// New doc.
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_legal_doc">';
		echo '<h2>' . esc_html__( 'Add a legal document', 'amplifi-consent' ) . '</h2>';
		self::field_text( 'doc_title', __( 'Title', 'amplifi-consent' ), '', 'e.g. Privacy Policy' );
		self::field_text( 'doc_slug', __( 'Slug (used in the shortcode)', 'amplifi-consent' ), '', 'privacy-policy' );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Type', 'amplifi-consent' ) . '</label><select name="doc_type">';
		foreach ( array( 'privacy' => __( 'Privacy Policy', 'amplifi-consent' ), 'terms' => __( 'Terms & Conditions', 'amplifi-consent' ), 'cookie' => __( 'Cookie Policy', 'amplifi-consent' ), 'custom' => __( 'Custom', 'amplifi-consent' ) ) as $val => $lbl ) {
			printf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		self::field_text( 'doc_version', __( 'Initial version label (blank = v1)', 'amplifi-consent' ), '' );
		self::field_textarea( 'doc_content', __( 'Document content (HTML allowed)', 'amplifi-consent' ), '' );
		submit_button( __( 'Create document', 'amplifi-consent' ) );
		echo '</form>';
	}

	/* ---------------- Consent Log tab ---------------- */

	private static function render_log() {
		$total = Amplifi_Consent_Log::count();
		$rows  = Amplifi_Consent_Log::query( array( 'limit' => 100 ) );
		$export_csv  = esc_url( rest_url( Amplifi_Consent_Rest::NS . '/export?format=csv' ) );
		$export_json = esc_url( rest_url( Amplifi_Consent_Rest::NS . '/export?format=json' ) );

		echo '<div class="acconsent-card">';
		echo '<h2>' . esc_html__( 'Consent log', 'amplifi-consent' ) . ' <span class="acconsent-cat-pill">' . esc_html( $total ) . ' ' . esc_html__( 'record(s)', 'amplifi-consent' ) . '</span></h2>';
		echo '<p class="acconsent-muted">' . esc_html__( 'A best-effort, server-side record designed to help you demonstrate consent (GDPR Art. 7(1)). Each row is stamped server-side with the time, the policy/catalog versions live when the visitor saw the banner, legal-doc versions, GPC signal, and a privacy-preserving IP. Records are written by a client request, so an event can be missed if the visitor is offline or blocks the request — this is a tool, not a legal guarantee. Export for DSAR / DPA / audit:', 'amplifi-consent' ) . '</p>';
		echo '<p><a class="button" href="' . $export_csv . '">' . esc_html__( 'Download CSV', 'amplifi-consent' ) . '</a> <a class="button" href="' . $export_json . '">' . esc_html__( 'Download JSON', 'amplifi-consent' ) . '</a></p>';
		echo '</div>';

		echo '<div class="acconsent-card">';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When (UTC)', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Event', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Categories', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Policy', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Catalog', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'GPC', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Source', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Visitor', 'amplifi-consent' ) . '</th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="8"><span class="acconsent-muted">' . esc_html__( 'No consent recorded yet.', 'amplifi-consent' ) . '</span></td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$cats = json_decode( $r['categories'], true );
			// New rows store a flat category map; legacy (db v1) rows nested it
			// under `_categories`. Support both.
			if ( isset( $cats['_categories'] ) ) {
				$cats = $cats['_categories'];
			}
			$show = array();
			if ( is_array( $cats ) ) {
				foreach ( $cats as $k => $v ) {
					if ( $v ) { $show[] = $k; }
				}
			}
			echo '<tr>';
			echo '<td>' . esc_html( $r['created_gmt'] ) . '</td>';
			echo '<td>' . esc_html( $r['event'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $show ) ) . '</td>';
			echo '<td>' . esc_html( $r['policy_version'] ) . '</td>';
			echo '<td><code>' . esc_html( $r['catalog_hash'] ) . '</code></td>';
			echo '<td>' . ( $r['gpc'] ? '✓' : '' ) . '</td>';
			echo '<td>' . esc_html( $r['source'] ) . '</td>';
			echo '<td><code>' . esc_html( substr( $r['visitor_id'], 0, 12 ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/* ---------------- Scripts tab ---------------- */

	private static function render_scripts() {
		$scripts    = Amplifi_Consent_Store::get_scripts();
		$categories = Amplifi_Consent_Store::categories();

		// Add new.
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="add_script">';
		echo '<h2>' . esc_html__( 'Add a tracking script', 'amplifi-consent' ) . '</h2>';
		echo '<p class="acconsent-muted">' . esc_html__( 'Paste the full snippet (the whole', 'amplifi-consent' ) . ' <code>&lt;script&gt;...&lt;/script&gt;</code>, ' . esc_html__( 'including external', 'amplifi-consent' ) . ' <code>src</code> ' . esc_html__( 'loaders). It will be withheld until the visitor consents to its category.', 'amplifi-consent' ) . '</p>';
		self::field_text( 'new_label', __( 'Label', 'amplifi-consent' ), '', 'e.g. Google Analytics 4' );
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Category', 'amplifi-consent' ) . '</label><select name="new_category">';
		foreach ( $categories as $key => $cat ) {
			if ( ! empty( $cat['locked'] ) ) { continue; }
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $cat['label'] ) );
		}
		echo '</select></div>';
		echo '<div class="acconsent-field"><label>' . esc_html__( 'Placement', 'amplifi-consent' ) . '</label><select name="new_placement">';
		foreach ( array( 'head' => __( '<head>', 'amplifi-consent' ), 'body_open' => __( 'After <body>', 'amplifi-consent' ), 'footer' => __( 'Footer', 'amplifi-consent' ) ) as $val => $lbl ) {
			printf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $lbl ) );
		}
		echo '</select></div>';
		self::field_textarea( 'new_code', __( 'Script code', 'amplifi-consent' ), '', '<script>...</script>' );
		echo '<div class="acconsent-field"><label><input type="checkbox" name="new_sale_share" value="1"> ' . esc_html__( 'Constitutes sale/sharing of personal info (CCPA) — withheld under GPC/"Do Not Sell" even if its category is granted', 'amplifi-consent' ) . '</label></div>';
		echo '<div class="acconsent-field"><label><input type="checkbox" name="new_sensitive_pi" value="1"> ' . esc_html__( 'Handles sensitive personal information (CCPA §1798.121) — withheld unconditionally while "Limit Sensitive PI" is on', 'amplifi-consent' ) . '</label></div>';
		echo '<div class="acconsent-field"><label><input type="checkbox" name="new_consent_mode" value="1"> ' . esc_html__( 'Google Advanced Consent Mode — load this Google tag (GTM/GA4) live but cookieless before consent, then upgrade on accept (requires the global Consent Mode v2 setting on; Google tags only)', 'amplifi-consent' ) . '</label></div>';
		submit_button( __( 'Add script', 'amplifi-consent' ), 'primary', 'submit', false );
		echo '</form>';

		if ( empty( $scripts ) ) {
			echo '<div class="acconsent-card"><p class="acconsent-muted">' . esc_html__( 'No scripts yet. Add one above.', 'amplifi-consent' ) . '</p></div>';
			return;
		}

		// Edit existing.
		echo '<form method="post" class="acconsent-card">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="acconsent_action" value="save_scripts">';
		echo '<h2>' . esc_html__( 'Managed scripts', 'amplifi-consent' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'On', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Label', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Category', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Placement', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Code', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Sale/share', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Sensitive PI', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Consent Mode', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Scan', 'amplifi-consent' ) . '</th><th></th></tr></thead><tbody>';
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
			foreach ( array( 'head' => __( 'head', 'amplifi-consent' ), 'body_open' => __( 'body', 'amplifi-consent' ), 'footer' => __( 'footer', 'amplifi-consent' ) ) as $val => $lbl ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['placement'], $val, false ), esc_html( $lbl ) );
			}
			echo '</select></td>';
			echo '<td class="acconsent-code-cell"><textarea name="scripts[' . $i . '][code]">' . esc_textarea( $s['code'] ) . '</textarea></td>';
			echo '<td><label title="' . esc_attr__( 'Constitutes sale/sharing of personal info (CCPA)', 'amplifi-consent' ) . '"><input type="checkbox" name="scripts[' . $i . '][sale_share]" value="1" ' . checked( ! empty( $s['sale_share'] ), true, false ) . '></label></td>';
			echo '<td><label title="' . esc_attr__( 'Handles sensitive personal information (CCPA §1798.121)', 'amplifi-consent' ) . '"><input type="checkbox" name="scripts[' . $i . '][sensitive_pi]" value="1" ' . checked( ! empty( $s['sensitive_pi'] ), true, false ) . '></label></td>';
			echo '<td><label title="' . esc_attr__( 'Google Advanced Consent Mode: load this Google tag (GTM/GA4) LIVE but cookieless before consent, then upgrade on accept. Only for Consent-Mode-aware Google tags; requires the global Consent Mode v2 setting to be on.', 'amplifi-consent' ) . '"><input type="checkbox" name="scripts[' . $i . '][consent_mode]" value="1" ' . checked( ! empty( $s['consent_mode'] ), true, false ) . '></label></td>';
			echo '<td><button type="button" class="button acconsent-scan-btn" data-script-id="' . esc_attr( $s['id'] ) . '">' . esc_html__( 'Scan cookies', 'amplifi-consent' ) . '</button><div class="acconsent-scan-result" data-for="' . esc_attr( $s['id'] ) . '"></div></td>';
			echo '<td><button type="submit" class="button-link-delete" name="acconsent_inline_delete" value="' . esc_attr( $s['id'] ) . '" formaction="" onclick="return false;" style="display:none"></button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'amplifi-consent' ) );
		echo '</form>';

		// Per-script delete forms + merge-cookies form (used by scanner JS).
		foreach ( $scripts as $s ) {
			echo '<form method="post" style="display:inline" class="acconsent-del-form">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="acconsent_action" value="delete_script">';
			echo '<input type="hidden" name="script_id" value="' . esc_attr( $s['id'] ) . '">';
			echo '<button type="submit" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this script?', 'amplifi-consent' ) ) . '\')">' . esc_html__( 'Delete:', 'amplifi-consent' ) . ' ' . esc_html( $s['label'] ? $s['label'] : $s['id'] ) . '</button> &nbsp;';
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
		echo '<h2>' . esc_html__( 'Cookie catalog', 'amplifi-consent' ) . '</h2>';
		echo '<p class="acconsent-muted">' . esc_html__( 'Detected cookies appear here after a scan. Assign each to a category — this is exactly what visitors see in the Manage panel. Newly-detected cookies start as', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'Unclassified', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'and are NOT disclosed to visitors until you categorize them.', 'amplifi-consent' ) . '</p>';
		echo '<p class="acconsent-muted" style="border-left:3px solid #d6a100;padding-left:10px"><strong>' . esc_html__( 'Scanner note:', 'amplifi-consent' ) . '</strong> ' . esc_html__( '“Scan cookies” executes the script once in this admin context to observe the cookies it sets. That run is a real execution and may contact the third party. Use it only on scripts you trust; it is optional — you can categorize cookies by hand instead.', 'amplifi-consent' ) . '</p>';

		if ( empty( $cookies ) ) {
			echo '<p class="acconsent-muted">' . esc_html__( 'No cookies catalogued yet. Go to the', 'amplifi-consent' ) . ' <strong>' . esc_html__( 'Scripts', 'amplifi-consent' ) . '</strong> ' . esc_html__( 'tab and click “Scan cookies” on a script.', 'amplifi-consent' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Category', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Set by', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Domain', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Duration', 'amplifi-consent' ) . '</th><th>' . esc_html__( 'Description', 'amplifi-consent' ) . '</th></tr></thead><tbody>';
			foreach ( $cookies as $i => $c ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $c['name'] ) . '</strong><input type="hidden" name="cookies[' . $i . '][name]" value="' . esc_attr( $c['name'] ) . '"></td>';
				echo '<td><select name="cookies[' . $i . '][category]">';
				if ( 'unclassified' === $c['category'] ) {
					echo '<option value="unclassified" selected>' . esc_html__( '— Unclassified (review) —', 'amplifi-consent' ) . '</option>';
				}
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
			submit_button( __( 'Save catalog', 'amplifi-consent' ) );
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
<p style="font:13px sans-serif;color:#555">Running script in an isolated harness to detect cookies…</p>
<script>
(function(){
  var before = {};
  document.cookie.split(';').forEach(function(p){ var n=p.split('=')[0].trim(); if(n) before[n]=1; });
  window.__acconsentBefore = before;
})();
</script>
<?php
		// Inject the actual managed script verbatim. This DOES run here — that's
		// the point of the harness: observe what it sets, in isolation.
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
      // Same-origin admin harness → scope the target origin instead of '*' so
      // the detected-cookie message can't leak to an unexpected framing origin.
      (window.opener || window.parent).postMessage({ acconsent: true, scriptId: <?php echo wp_json_encode( $id ); ?>, cookies: found }, location.origin);
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
