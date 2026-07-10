<?php
/**
 * amplifi.consent — data store.
 *
 * All persistence lives here: settings, managed scripts, and the categorized
 * cookie catalog. Everything is kept in wp_options (no custom tables) so the
 * module stays dependency-free and uninstall is a clean option delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Store {

	const OPT_SETTINGS = 'acconsent_settings';
	const OPT_SCRIPTS  = 'acconsent_scripts';
	const OPT_COOKIES  = 'acconsent_cookies';
	const OPT_LEGAL    = 'acconsent_legal';

	/**
	 * The commonly-accepted consent categories. `necessary` is always granted
	 * and cannot be rejected; the rest are opt-in and gate their scripts.
	 */
	public static function categories() {
		return array(
			'necessary' => array(
				'label'       => __( 'Strictly Necessary', 'amplifi-consent' ),
				'description' => __( 'Required for the site to function. Always active.', 'amplifi-consent' ),
				'locked'      => true,
			),
			'functional' => array(
				'label'       => __( 'Functional', 'amplifi-consent' ),
				'description' => __( 'Remembers preferences and choices to personalize your experience.', 'amplifi-consent' ),
				'locked'      => false,
			),
			'analytics' => array(
				'label'       => __( 'Analytics', 'amplifi-consent' ),
				'description' => __( 'Helps us understand how visitors use the site so we can improve it.', 'amplifi-consent' ),
				'locked'      => false,
			),
			'marketing' => array(
				'label'       => __( 'Marketing', 'amplifi-consent' ),
				'description' => __( 'Used to track visitors and show relevant advertising.', 'amplifi-consent' ),
				'locked'      => false,
			),
		);
	}

	public static function default_settings() {
		return array(
			'banner_title'    => __( 'We value your privacy', 'amplifi-consent' ),
			'banner_message'  => __( 'We use cookies to improve your experience. Tracking scripts will not run until you accept. You can accept, reject, or manage your choices.', 'amplifi-consent' ),
			'accept_label'    => __( 'Accept all', 'amplifi-consent' ),
			'reject_label'    => __( 'Reject all', 'amplifi-consent' ),
			'manage_label'    => __( 'Manage', 'amplifi-consent' ),
			'save_label'      => __( 'Save choices', 'amplifi-consent' ),
			'toast_accepted'  => __( 'Preferences saved — thanks!', 'amplifi-consent' ),
			'toast_rejected'  => __( 'Tracking declined. Only essential cookies are active.', 'amplifi-consent' ),
			'consent_days'    => 180,
			'accent_color'    => '#4db6ac',
			'position'        => 'bottom', // bottom | center
			'enabled'         => true,
			// Disclosure (shown on the banner before any choice).
			'privacy_url'     => '',
			'prefs_label'     => __( 'Cookie preferences', 'amplifi-consent' ),
			'floating_button' => true, // always-available withdrawal trigger (GDPR Art. 7(3)).
			// Which bottom corner the persistent floating "preferences" FAB
			// docks to. Doesn't affect the banner (see `position` above,
			// which controls bottom-bar vs. centered-modal layout) — this is
			// specifically the small circular revisit button.
			'fab_position'    => 'left', // left | right
			// Consent record / proof.
			'policy_version'  => '1', // bump to force re-consent on policy change.
			'ip_mode'         => 'truncate', // truncate (data-min default) | hash | none.
			// Retention ceiling: 3 years (1095 days) by default for NEW installs, so
			// an operator doesn't accidentally ship "keep forever" out of the box.
			// 0 (keep forever) remains available as an explicit admin choice; any
			// other positive value is still floored at 730 days (CCPA 24-month
			// record-keeping minimum, §7101) in save_settings() below. Existing
			// sites keep whatever value they already saved — this default only
			// applies the first time the option is created.
			'retention_days'  => 1095,
			// Behind a trusted reverse proxy / CDN (Cloudflare): derive the real
			// client IP from CF-Connecting-IP / X-Forwarded-For for rate-limiting.
			// OFF by default — XFF is client-spoofable on a direct-connect origin.
			'trust_proxy'     => false,
			// Webhook mirror of the server consent log.
			'webhook_url'     => '',
			'webhook_secret'  => '',
			'webhook_enabled' => false,
			// US / CCPA.
			'gpc_enabled'     => true, // honor Global Privacy Control as an opt-out.
			// Google Consent Mode v2 defaults pushed before tags.
			'consent_mode'    => false,
			// Auto-block unmanaged third-party trackers by domain. ON by default
			// so an out-of-box install actually governs the trackers most sites
			// load via the theme / other plugins (not just hand-pasted scripts).
			'autoblock'       => true,
			'blocklist'       => self::default_blocklist(),
			// CCPA/CPRA one-click "Do Not Sell or Share" opt-out link.
			'do_not_sell'     => true,
			'dns_label'       => __( 'Do Not Sell or Share My Personal Information', 'amplifi-consent' ),
			// CCPA §1798.121 "Limit the Use of My Sensitive Personal Information".
			// Reinstated (v1.4.0 removed a half-wired version of this) — this time
			// it is ACTUALLY wired to unconditional blocking of any script/host an
			// admin flags as handling SPI (see sensitive_pi / spi_hosts), not just a
			// cosmetic toggle that opened the preferences modal.
			'limit_spi_enabled' => true,
			'limit_spi_label'   => __( 'Limit the Use of My Sensitive Personal Information', 'amplifi-consent' ),
			// User-Agent capture mode for the consent log: 'minimal' (default) stores
			// only browser name+major-version and OS family (enough to debug a
			// disputed consent without keeping the full fingerprintable UA string).
			'ua_mode'         => 'minimal', // full | minimal | none.
		);
	}

	/**
	 * Default tracker-domain blocklist for the auto-block engine. Any <script
	 * src>, <img>, or <iframe> pointing at one of these hosts that was NOT added
	 * through the managed-scripts store is neutralized until consent.
	 *
	 * Each line is `host|category|sale|spi` — only `host` is required; the
	 * remaining segments are optional and default to their safe value when
	 * omitted:
	 *   - category: the consent bucket the tracker is released under, so
	 *     granting Analytics does NOT release Marketing/ad pixels and vice-
	 *     versa. A bare `host` (no `|category`) defaults to `marketing`, the
	 *     strictest opt-in bucket, so an unclassified tracker fails safe.
	 *   - sale: `1` marks this host as constituting a "sale/share" of personal
	 *     information under CCPA/CPRA (e.g. third-party analytics/session-
	 *     replay tools that disclose data to a third party — see the Sephora
	 *     enforcement action) EVEN THOUGH it may be bucketed under a category
	 *     other than Marketing. GPC / "Do Not Sell" additionally blocks any
	 *     host flagged `sale=1` regardless of category grant. Defaults to `0`.
	 *   - spi: `1` marks this host as handling Sensitive Personal Information
	 *     (CCPA §1798.121) — permanently blocked whenever "Limit the Use of
	 *     Sensitive PI" is enabled, independent of any category grant.
	 *     Defaults to `0` (SPI use is business-specific; a generic plugin
	 *     shouldn't guess which vendor handles it for a given site).
	 */
	public static function default_blocklist() {
		return implode( "\n", array(
			// Tag managers can load anything → strictest bucket.
			'googletagmanager.com|marketing',
			// Analytics / product measurement / session replay. Flagged sale=1:
			// these tools disclose visitor data to a third party (the vendor),
			// which can constitute a "sale/share" under CCPA/CPRA regardless of
			// the site's internal "analytics" category label (Sephora action).
			'google-analytics.com|analytics|1',
			'analytics.google.com|analytics|1',
			'clarity.ms|analytics|1',
			'hotjar.com|analytics|1',
			'static.hotjar.com|analytics|1',
			'cdn.segment.com|analytics|1',
			'openreplay.com|analytics|1',
			'fullstory.com|analytics|1',
			'logr-ingest.io|analytics|1',       // LogRocket ingest
			'cdn.logr-ingest.io|analytics|1',
			'mouseflow.com|analytics|1',
			'smartlook.com|analytics|1',
			'rec.smartlook.com|analytics|1',
			'quantummetric.com|analytics|1',
			// Advertising / remarketing / B2B de-anonymization → marketing.
			'connect.facebook.net|marketing',
			'facebook.com/tr|marketing',
			// NOTE: snap.licdn.com is LinkedIn infrastructure (their insight/
			// conversion tag), NOT Snapchat, despite the "snap" prefix — do not
			// confuse with the real Snapchat pixel entries below (tr.snapchat.com
			// / sc-static.net).
			'snap.licdn.com|marketing',
			'px.ads.linkedin.com|marketing',
			'bat.bing.com|marketing',
			'doubleclick.net|marketing',
			'googleadservices.com|marketing',
			'snitcher.com|marketing',
			'rb2b.com|marketing',
			'analytics.tiktok.com|marketing',
			'static.ads-twitter.com|marketing',
			'analytics.twitter.com|marketing',
			't.co/i/adsct|marketing',
			'ct.pinterest.com|marketing',
			's.pinimg.com|marketing',
			'pixel.reddit.com|marketing',
			'alb.reddit.com|marketing',
			// Snapchat pixel tracking (the ACTUAL Snapchat host — distinct from
			// snap.licdn.com above, which despite its "snap" prefix is LinkedIn's
			// infrastructure, not Snapchat; kept separate here to avoid the naming
			// trap the audit flagged).
			'tr.snapchat.com|marketing|1',
			'sc-static.net|marketing|1',
			// Sales/marketing engagement chat widgets → marketing (they are
			// lead-gen/CRM platforms, not passive support tooling).
			'widget.intercom.io|marketing',      // Intercom is a sales/marketing engagement platform
			'js.intercomcdn.com|marketing',
			'js.driftt.com|marketing',           // Drift
			// HubSpot's official WordPress plugin (Leadin) auto-injects its
			// tracking beacon directly, completely outside any consent
			// system's control — this is the only mechanism (the auto-block
			// blocklist scanner) that can gate it without modifying Leadin
			// itself. Covers all regional script shards (js-na1/js-na2/
			// js-eu1.hs-scripts.com). Found unmanaged/ungated on
			// ascentialmls.com (a site running the Leadin plugin).
			'hs-scripts.com|marketing',
			// Pure customer-support chat widgets — functional, not ad-tech.
			'embed.tawk.to|functional',          // pure support chat widgets, not ad-tech
			'static.zdassets.com|functional',    // Zendesk Chat/widget
			'assets.zendesk.com|functional',
			'static.olark.com|functional',
			'client.crisp.chat|functional',
		) );
	}

	/**
	 * Parse a `host|category|sale|spi` blocklist string into an ordered list of
	 * [ 'host' => string, 'category' => string, 'sale' => bool, 'spi' => bool ].
	 * A line without a category defaults to 'marketing' (strictest opt-in).
	 * Only the gated, opt-in categories are valid release targets; anything
	 * else coerces to marketing. The `sale` and `spi` segments are optional
	 * trailing flags (1/0), defaulting to false when omitted, so 2-, 3-, and
	 * 4-segment lines are all handled gracefully.
	 */
	public static function parse_blocklist( $raw ) {
		$valid = array( 'functional', 'analytics', 'marketing' );
		$lines = preg_split( '/[\r\n]+/', (string) $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = explode( '|', $line, 4 );
			$host  = trim( strtolower( $parts[0] ) );
			if ( '' === $host ) {
				continue;
			}
			$cat = isset( $parts[1] ) ? trim( strtolower( $parts[1] ) ) : 'marketing';
			if ( ! in_array( $cat, $valid, true ) ) {
				$cat = 'marketing';
			}
			$sale = isset( $parts[2] ) ? ( '1' === trim( $parts[2] ) ) : false;
			$spi  = isset( $parts[3] ) ? ( '1' === trim( $parts[3] ) ) : false;
			$out[] = array( 'host' => $host, 'category' => $cat, 'sale' => $sale, 'spi' => $spi );
		}
		return $out;
	}

	public static function get_settings() {
		$saved = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	public static function save_settings( $settings ) {
		$defaults = self::default_settings();
		$clean    = array();
		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $settings[ $key ] ) ) {
				$clean[ $key ] = $default;
				continue;
			}
			switch ( $key ) {
				case 'consent_days':
					$clean[ $key ] = max( 1, min( 365, intval( $settings[ $key ] ) ) );
					break;
				case 'accent_color':
					$clean[ $key ] = preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $settings[ $key ] ) ? $settings[ $key ] : $default;
					break;
				case 'enabled':
				case 'floating_button':
				case 'webhook_enabled':
				case 'gpc_enabled':
				case 'consent_mode':
				case 'autoblock':
				case 'do_not_sell':
				case 'trust_proxy':
				case 'limit_spi_enabled':
					$clean[ $key ] = (bool) $settings[ $key ];
					break;
				case 'position':
					$clean[ $key ] = in_array( $settings[ $key ], array( 'bottom', 'center' ), true ) ? $settings[ $key ] : $default;
					break;
				case 'fab_position':
					$clean[ $key ] = in_array( $settings[ $key ], array( 'left', 'right' ), true ) ? $settings[ $key ] : $default;
					break;
				case 'ip_mode':
					$clean[ $key ] = in_array( $settings[ $key ], array( 'hash', 'truncate', 'none' ), true ) ? $settings[ $key ] : $default;
					break;
				case 'ua_mode':
					$clean[ $key ] = in_array( $settings[ $key ], array( 'full', 'minimal', 'none' ), true ) ? $settings[ $key ] : 'minimal';
					break;
				case 'privacy_url':
					$clean[ $key ] = esc_url_raw( $settings[ $key ], array( 'http', 'https' ) );
					break;
				case 'webhook_url':
					$clean[ $key ] = esc_url_raw( $settings[ $key ], array( 'http', 'https' ) );
					break;
				case 'webhook_secret':
					$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
					break;
				case 'policy_version':
					$clean[ $key ] = sanitize_text_field( substr( (string) $settings[ $key ], 0, 40 ) );
					if ( '' === $clean[ $key ] ) {
						$clean[ $key ] = '1';
					}
					break;
				case 'retention_days':
					// 0 = keep forever. Any positive value is FLOORED at 730 days
					// so an operator can't accidentally purge consent proof below
					// the CCPA 24-month record-keeping minimum (§7101).
					$rd = max( 0, intval( $settings[ $key ] ) );
					$clean[ $key ] = ( 0 === $rd ) ? 0 : max( 730, $rd );
					break;
				case 'blocklist':
					// Newline-separated `host|category|sale|spi` list; keep
					// host-ish tokens and up to 3 optional pipe-delimited
					// segments (category, sale flag, spi flag). parse_blocklist()
					// validates/defaults each segment later, so preserve all of
					// them here rather than truncating to just `host|category`.
					$lines = preg_split( '/[\r\n]+/', (string) $settings[ $key ] );
					$out   = array();
					foreach ( (array) $lines as $line ) {
						$line  = trim( strtolower( $line ) );
						if ( '' === $line ) {
							continue;
						}
						$parts = explode( '|', $line, 4 );
						$h     = trim( $parts[0] );
						$h     = preg_replace( '#^https?://#', '', $h );
						$h     = preg_replace( '#[^a-z0-9\.\-/_]#', '', $h );
						if ( '' === $h ) {
							continue;
						}
						$segs  = array( $h );
						$cat   = isset( $parts[1] ) ? preg_replace( '#[^a-z]#', '', trim( $parts[1] ) ) : '';
						$sale  = isset( $parts[2] ) ? preg_replace( '#[^01]#', '', trim( $parts[2] ) ) : '';
						$spi   = isset( $parts[3] ) ? preg_replace( '#[^01]#', '', trim( $parts[3] ) ) : '';
						// Only append a segment if it (or a later one) is non-empty,
						// so we don't pad every line out to 4 segments needlessly —
						// but if a LATER segment is present, earlier ones must be
						// filled (even if blank) to keep positions aligned.
						if ( '' !== $spi ) {
							$segs[] = $cat;
							$segs[] = ( '' !== $sale ) ? $sale : '0';
							$segs[] = $spi;
						} elseif ( '' !== $sale ) {
							$segs[] = $cat;
							$segs[] = $sale;
						} elseif ( '' !== $cat ) {
							$segs[] = $cat;
						}
						$out[] = implode( '|', $segs );
					}
					$clean[ $key ] = implode( "\n", array_values( array_unique( $out ) ) );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
			}
		}
		update_option( self::OPT_SETTINGS, $clean );
		return $clean;
	}

	/**
	 * The active policy version. Returned in every consent receipt so a record
	 * can be tied to exactly what the user agreed to. Bumping it (in settings)
	 * invalidates stored client consent and re-prompts.
	 */
	public static function policy_version() {
		$s = self::get_settings();
		return isset( $s['policy_version'] ) ? (string) $s['policy_version'] : '1';
	}

	/**
	 * Stable hash of the current managed-script + cookie catalog. Stored on each
	 * consent receipt AND compared client-side: if the catalog changes (a new
	 * tracker is added), a returning visitor's stored consent is treated as
	 * stale and they are re-prompted instead of silently auto-releasing the new
	 * tracker. Closes the GDPR "consent not specific" / silent-re-release hole.
	 *
	 * Also folds in the CURRENT blocklist (host, category, sale flag, spi flag)
	 * and whether auto-block is on: a change to WHICH unmanaged trackers are
	 * gated, or to a host's sale/spi flags, is just as "not specific" a change
	 * as adding a managed script — so it must also invalidate stored consent
	 * and re-prompt, not silently start/stop gating a tracker.
	 */
	public static function catalog_hash() {
		$parts = array();
		foreach ( self::get_scripts() as $s ) {
			if ( empty( $s['enabled'] ) ) {
				continue;
			}
			$parts[] = $s['id'] . ':' . $s['category'] . ':' . md5( (string) $s['code'] );
		}
		sort( $parts );
		// Fold in the current version label of every published legal doc, so
		// publishing Privacy Policy v3 (or Terms v2) changes the hash and
		// re-prompts returning visitors to consent against the new text.
		$legal = array();
		foreach ( self::legal_snapshot() as $id => $snap ) {
			$legal[] = $id . ':' . $snap['version'];
		}
		sort( $legal );

		// Blocklist + autoblock state. Includes the sale/spi flags so a
		// category/sale/spi-only change to an existing host ALSO invalidates
		// stored consent, not just adding/removing hosts.
		$s  = self::get_settings();
		$bl = array();
		foreach ( self::parse_blocklist( isset( $s['blocklist'] ) ? $s['blocklist'] : '' ) as $entry ) {
			$bl[] = $entry['host'] . ':' . $entry['category'] . ':' . ( $entry['sale'] ? '1' : '0' ) . ':' . ( $entry['spi'] ? '1' : '0' );
		}
		sort( $bl );
		$bl_blob = ( ! empty( $s['autoblock'] ) ? '1' : '0' ) . '|' . implode( '|', $bl );

		return substr( hash( 'sha256', self::policy_version() . '|' . implode( '|', $parts ) . '|' . implode( '|', $legal ) . '|' . $bl_blob ), 0, 16 );
	}

	/* ---------------- Managed scripts ---------------- */

	public static function get_scripts() {
		$scripts = get_option( self::OPT_SCRIPTS, array() );
		return is_array( $scripts ) ? array_values( $scripts ) : array();
	}

	/**
	 * Sanitize a single script record. The `code` field intentionally keeps the
	 * raw markup (it IS a script the admin pasted) — it is escaped for display
	 * but stored verbatim so it can be re-emitted as a gated tag.
	 */
	public static function sanitize_script( $s ) {
		// SECURITY: 'necessary' is DELIBERATELY excluded from the allowed set for
		// managed scripts. A script tagged 'necessary' would release on every
		// load — even after the visitor clicks Reject — with no consent. Only the
		// opt-in categories may carry a tracking script; anything else is coerced
		// to 'analytics' (a gated, opt-in bucket).
		$categories = array( 'functional', 'analytics', 'marketing' );
		$placements = array( 'head', 'body_open', 'footer' );
		return array(
			'id'        => isset( $s['id'] ) && $s['id'] ? sanitize_key( $s['id'] ) : 'scr_' . strtolower( wp_generate_password( 8, false, false ) ),
			'label'     => isset( $s['label'] ) ? sanitize_text_field( $s['label'] ) : '',
			'category'  => isset( $s['category'] ) && in_array( $s['category'], $categories, true ) ? $s['category'] : 'analytics',
			'placement' => isset( $s['placement'] ) && in_array( $s['placement'], $placements, true ) ? $s['placement'] : 'head',
			'code'      => isset( $s['code'] ) ? (string) $s['code'] : '',
			'enabled'   => isset( $s['enabled'] ) ? (bool) $s['enabled'] : true,
			// CCPA/CPRA: this script's data flow constitutes a "sale/share" of
			// personal information — GPC / "Do Not Sell" will withhold it even
			// if its category is otherwise granted. See H1 (Sephora action).
			'sale_share'   => isset( $s['sale_share'] ) ? (bool) $s['sale_share'] : false,
			// CCPA §1798.121: this script handles Sensitive Personal
			// Information — permanently withheld (independent of any category
			// grant) whenever "Limit the Use of Sensitive PI" is enabled.
			'sensitive_pi' => isset( $s['sensitive_pi'] ) ? (bool) $s['sensitive_pi'] : false,
			// Google Advanced Consent Mode: when the global consent_mode setting
			// is ON and this flag is set, the script is loaded LIVE pre-consent
			// (instead of hard-withheld in an inert <template>) so a
			// Consent-Mode-aware Google tag (GTM/gtag/GA4) can send cookieless,
			// anonymized "modeling pings" while the gtag('consent','default',
			// {…denied…}) block keeps every identifier off. On grant the JS
			// fires gtag('consent','update',{…granted…}) to upgrade it to full
			// tracking. The sale_share / sensitive_pi withholding above STILL
			// applies at runtime (via the network shim's CM-allowlist bypass,
			// which yields to GPC / Do-Not-Sell / Limit-SPI), so this does not
			// weaken the CCPA opt-out protections — it only lets a Google tag
			// model the un-consented gap the way Google's own Consent Mode does.
			// Only meaningful for Google-family tags; a non-Consent-Mode-aware
			// tracker flagged here would simply fire un-gated, so the admin UI
			// documents that this is for Google tags.
			'consent_mode' => isset( $s['consent_mode'] ) ? (bool) $s['consent_mode'] : false,
		);
	}

	public static function save_scripts( $scripts ) {
		$clean = array();
		foreach ( (array) $scripts as $s ) {
			$rec = self::sanitize_script( $s );
			if ( '' === trim( $rec['code'] ) ) {
				continue;
			}
			$clean[ $rec['id'] ] = $rec;
		}
		update_option( self::OPT_SCRIPTS, $clean );
		return array_values( $clean );
	}

	public static function get_script( $id ) {
		$id = sanitize_key( $id );
		foreach ( self::get_scripts() as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	/* ---------------- Cookie catalog ---------------- */

	public static function get_cookies() {
		$cookies = get_option( self::OPT_COOKIES, array() );
		return is_array( $cookies ) ? array_values( $cookies ) : array();
	}

	public static function sanitize_cookie( $c ) {
		$categories   = array_keys( self::categories() );
		$categories[] = 'unclassified'; // detected-but-not-yet-reviewed; NOT shown under a granted bucket.
		return array(
			'name'        => isset( $c['name'] ) ? sanitize_text_field( $c['name'] ) : '',
			'category'    => isset( $c['category'] ) && in_array( $c['category'], $categories, true ) ? $c['category'] : 'unclassified',
			'script_id'   => isset( $c['script_id'] ) ? sanitize_key( $c['script_id'] ) : '',
			'domain'      => isset( $c['domain'] ) ? sanitize_text_field( $c['domain'] ) : '',
			'duration'    => isset( $c['duration'] ) ? sanitize_text_field( $c['duration'] ) : '',
			'description' => isset( $c['description'] ) ? sanitize_text_field( $c['description'] ) : '',
		);
	}

	public static function save_cookies( $cookies ) {
		$clean = array();
		foreach ( (array) $cookies as $c ) {
			$rec = self::sanitize_cookie( $c );
			if ( '' === $rec['name'] ) {
				continue;
			}
			$clean[ $rec['name'] ] = $rec; // keyed by name → dedupes.
		}
		update_option( self::OPT_COOKIES, array_values( $clean ) );
		return array_values( $clean );
	}

	/**
	 * Known-cookie duration lookup. `document.cookie` (the scanner's only
	 * client-side view) CANNOT read a cookie's expiry — JS sees name=value only.
	 * Commercial CMPs solve this with a maintained cookie-knowledge database;
	 * this is a focused version covering the common first-party trackers so the
	 * catalog auto-fills a human-readable duration instead of leaving it blank.
	 * Matching is by exact name first, then by prefix (e.g. `_ga_` containers).
	 *
	 * @param string $name Cookie name.
	 * @return string Human-readable duration, or '' if unknown.
	 */
	public static function lookup_cookie_duration( $name ) {
		$name = (string) $name;

		// Exact-name map.
		$exact = array(
			'_ga'        => '2 years',   // Google Analytics client id
			'_gid'       => '24 hours',  // GA session id
			'_gat'       => '1 minute',  // GA throttle
			'__utma'     => '2 years',
			'__utmb'     => '30 minutes',
			'__utmc'     => 'session',
			'__utmz'     => '6 months',
			'__utmt'     => '10 minutes',
			'_gcl_au'    => '3 months',  // Google Ads conversion linker
			'_clck'      => '1 year',    // Microsoft Clarity user id
			'_clsk'      => '1 day',     // Microsoft Clarity session
			'CLID'       => '1 year',    // Clarity (clarity.ms domain)
			'ANONCHK'    => '10 minutes',
			'MUID'       => '1 year',    // Microsoft / Bing
			'SM'         => 'session',
			'_fbp'       => '3 months',  // Meta Pixel browser id
			'_fbc'       => '3 months',  // Meta Pixel click id
			'fr'         => '3 months',  // Facebook
			'_hjSessionUser' => '1 year',      // Hotjar
			'_hjSession'     => '30 minutes',  // Hotjar
			'_hjid'          => '1 year',
			'_hjIncludedInSessionSample' => '2 minutes',
			'_hjAbsoluteSessionInProgress' => '30 minutes',
			'IDE'        => '1 year',    // DoubleClick
			'test_cookie' => '15 minutes',
			'li_sugr'    => '3 months',  // LinkedIn
			'bcookie'    => '1 year',    // LinkedIn browser id
			'lidc'       => '1 day',     // LinkedIn
			'UserMatchHistory' => '30 days',
			'AnalyticsSyncHistory' => '30 days',
			'_pin_unauth' => '1 year',   // Pinterest
			'_scid'      => '13 months', // Snapchat
			'_tt_enable_cookie' => '13 months', // TikTok
			'_ttp'       => '13 months', // TikTok
			'ajs_user_id'      => '1 year',   // Segment
			'ajs_anonymous_id' => '1 year',   // Segment
		);
		if ( isset( $exact[ $name ] ) ) {
			return $exact[ $name ];
		}

		// Prefix map (GA4 measurement-id containers etc.).
		$prefix = array(
			'_ga_'      => '2 years',    // GA4 per-property container: _ga_XXXXXXXX
			'_gac_'     => '3 months',   // Google Ads
			'_gcl_'     => '3 months',
			'_uetsid'   => '1 day',      // Bing UET session
			'_uetvid'   => '13 months',  // Bing UET visitor
			'__Secure-' => 'varies',
			'_hjSession_' => '30 minutes',
		);
		foreach ( $prefix as $p => $dur ) {
			if ( 0 === strncmp( $name, $p, strlen( $p ) ) ) {
				return $dur;
			}
		}

		return '';
	}

	/** Merge newly-detected cookies into the catalog without clobbering categorizations already made. */
	public static function merge_detected_cookies( $detected, $script_id ) {
		$existing = array();
		foreach ( self::get_cookies() as $c ) {
			$existing[ $c['name'] ] = $c;
		}
		foreach ( (array) $detected as $d ) {
			$name = isset( $d['name'] ) ? sanitize_text_field( $d['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $existing[ $name ] ) ) {
				// Keep the admin's categorization; just refresh domain/script linkage
				// and backfill a known duration if it was left blank.
				if ( empty( $existing[ $name ]['script_id'] ) ) {
					$existing[ $name ]['script_id'] = sanitize_key( $script_id );
				}
				if ( empty( $existing[ $name ]['domain'] ) && ! empty( $d['domain'] ) ) {
					$existing[ $name ]['domain'] = sanitize_text_field( $d['domain'] );
				}
				if ( empty( $existing[ $name ]['duration'] ) ) {
					$auto = self::lookup_cookie_duration( $name );
					if ( '' !== $auto ) {
						$existing[ $name ]['duration'] = $auto;
					}
				}
				continue;
			}
			// New cookie: use the detected duration if the client supplied one,
			// otherwise auto-fill from the known-cookie table.
			$duration = isset( $d['duration'] ) && '' !== $d['duration']
				? $d['duration']
				: self::lookup_cookie_duration( $name );
			$existing[ $name ] = self::sanitize_cookie( array(
				'name'      => $name,
				'category'  => '', // unset → defaults to 'unclassified' (withheld from disclosure until an admin reviews).
				'script_id' => $script_id,
				'domain'    => isset( $d['domain'] ) ? $d['domain'] : '',
				'duration'  => $duration,
			) );
		}
		update_option( self::OPT_COOKIES, array_values( $existing ) );
		return array_values( $existing );
	}

	/* ---------------- Legal documents (versioned) ---------------- */

	/**
	 * Legal docs are versioned policy texts (Privacy Policy, Terms, Cookie
	 * Policy, or custom) managed inside the app. Each doc has an ordered list of
	 * versions; the newest is "current". The current version label of every
	 * published doc is snapshotted into every consent receipt, so the log can
	 * prove exactly which policy texts were live when a visitor consented.
	 * Placed on the site via the [amplifi-legal-doc] shortcode and linked from
	 * the consent manager.
	 *
	 * Shape: [ doc_id => [ 'id','slug','title','type','versions'=>[ ['version','content','published_at'], ... ] ] ]
	 */
	public static function get_legal_docs() {
		$docs = get_option( self::OPT_LEGAL, array() );
		return is_array( $docs ) ? $docs : array();
	}

	public static function get_legal_doc( $id ) {
		$id   = sanitize_key( $id );
		$docs = self::get_legal_docs();
		return isset( $docs[ $id ] ) ? $docs[ $id ] : null;
	}

	/** Resolve a doc by slug (used by the shortcode). */
	public static function get_legal_doc_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		foreach ( self::get_legal_docs() as $doc ) {
			if ( isset( $doc['slug'] ) && $doc['slug'] === $slug ) {
				return $doc;
			}
		}
		return null;
	}

	/** The current (newest) version record of a doc, or null. */
	public static function current_version( $doc ) {
		if ( empty( $doc['versions'] ) || ! is_array( $doc['versions'] ) ) {
			return null;
		}
		return end( $doc['versions'] );
	}

	/**
	 * Create or update a doc's metadata (title/slug/type). Does NOT add a
	 * version — use publish_legal_version for that.
	 */
	public static function save_legal_doc( $data ) {
		$docs  = self::get_legal_docs();
		$types = array( 'privacy', 'terms', 'cookie', 'custom' );
		$id    = isset( $data['id'] ) && $data['id'] ? sanitize_key( $data['id'] ) : 'doc_' . strtolower( wp_generate_password( 8, false, false ) );
		$slug  = isset( $data['slug'] ) && $data['slug'] ? sanitize_title( $data['slug'] ) : sanitize_title( isset( $data['title'] ) ? $data['title'] : $id );

		$existing            = isset( $docs[ $id ] ) ? $docs[ $id ] : array( 'versions' => array() );
		$existing['id']      = $id;
		$existing['slug']    = $slug;
		$existing['title']   = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : ( isset( $existing['title'] ) ? $existing['title'] : 'Untitled' );
		$existing['type']    = isset( $data['type'] ) && in_array( $data['type'], $types, true ) ? $data['type'] : ( isset( $existing['type'] ) ? $existing['type'] : 'custom' );
		if ( ! isset( $existing['versions'] ) || ! is_array( $existing['versions'] ) ) {
			$existing['versions'] = array();
		}
		$docs[ $id ] = $existing;
		update_option( self::OPT_LEGAL, $docs );
		return $existing;
	}

	/**
	 * Publish a new immutable version of a doc. Appending a version is what
	 * makes the doc's "current version" advance — and bumping any published
	 * doc's version changes the catalog_hash() below, which forces returning
	 * visitors to re-consent against the new policy text.
	 *
	 * $published_at is optional (defaults to now, in GMT MySQL format) — pass
	 * an explicit historical date when migrating EXISTING legal content that
	 * already carries its own stated effective date in the body text (e.g.
	 * moving a page's HTML into this store verbatim). Stamping "now" on an
	 * unchanged document is misleading: it implies the policy text changed
	 * on the migration date when it did not.
	 */
	public static function publish_legal_version( $id, $version_label, $content, $published_at = '' ) {
		$docs = self::get_legal_docs();
		$id   = sanitize_key( $id );
		if ( ! isset( $docs[ $id ] ) ) {
			return new WP_Error( 'acconsent_legal', 'Unknown legal document.' );
		}
		$label = sanitize_text_field( $version_label );
		if ( '' === $label ) {
			// Auto-increment: v1, v2, …
			$label = 'v' . ( count( $docs[ $id ]['versions'] ) + 1 );
		}
		$docs[ $id ]['versions'][] = array(
			'version'      => $label,
			'content'      => wp_kses_post( $content ),
			'published_at' => $published_at ? $published_at : current_time( 'mysql', true ),
		);
		update_option( self::OPT_LEGAL, $docs );
		return $docs[ $id ];
	}

	public static function delete_legal_doc( $id ) {
		$docs = self::get_legal_docs();
		$id   = sanitize_key( $id );
		unset( $docs[ $id ] );
		update_option( self::OPT_LEGAL, $docs );
	}

	/**
	 * Snapshot of every doc's current version, for stamping into a consent
	 * receipt and for surfacing links in the consent manager.
	 * Returns [ doc_id => [ 'title','slug','type','version','url' ] ].
	 */
	public static function legal_snapshot() {
		$out = array();
		foreach ( self::get_legal_docs() as $id => $doc ) {
			$cur = self::current_version( $doc );
			if ( ! $cur ) {
				continue; // unpublished docs aren't part of the consent record.
			}
			$out[ $id ] = array(
				'title'   => isset( $doc['title'] ) ? $doc['title'] : '',
				'slug'    => isset( $doc['slug'] ) ? $doc['slug'] : '',
				'type'    => isset( $doc['type'] ) ? $doc['type'] : 'custom',
				'version' => isset( $cur['version'] ) ? $cur['version'] : '',
				'url'     => self::legal_doc_url( $doc ),
			);
		}
		return $out;
	}

	/**
	 * Best-effort public URL for a doc: the first page/post that contains the
	 * doc's [amplifi-legal-doc] shortcode, else empty. Cached briefly.
	 */
	public static function legal_doc_url( $doc ) {
		if ( empty( $doc['slug'] ) ) {
			return '';
		}
		$slug  = $doc['slug'];
		$cache = get_transient( 'acconsent_legal_url_' . $slug );
		if ( false !== $cache ) {
			return $cache;
		}
		$url   = '';
		$pages = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			's'              => 'amplifi-legal-doc',
			'fields'         => 'ids',
		) );
		foreach ( $pages as $pid ) {
			$content = get_post_field( 'post_content', $pid );
			if ( false !== strpos( $content, 'amplifi-legal-doc' ) && false !== strpos( $content, $slug ) ) {
				$url = get_permalink( $pid );
				break;
			}
		}
		set_transient( 'acconsent_legal_url_' . $slug, $url, 5 * MINUTE_IN_SECONDS );
		return $url;
	}

	public static function activate() {
		if ( false === get_option( self::OPT_SETTINGS, false ) ) {
			update_option( self::OPT_SETTINGS, self::default_settings() );
		}
		if ( false === get_option( self::OPT_SCRIPTS, false ) ) {
			update_option( self::OPT_SCRIPTS, array() );
		}
		if ( false === get_option( self::OPT_COOKIES, false ) ) {
			update_option( self::OPT_COOKIES, array() );
		}
		if ( false === get_option( self::OPT_LEGAL, false ) ) {
			update_option( self::OPT_LEGAL, array() );
		}
		// Create / upgrade the consent-log table.
		if ( class_exists( 'Amplifi_Consent_Log' ) ) {
			Amplifi_Consent_Log::install();
		}
	}
}
