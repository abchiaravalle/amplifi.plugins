<?php
/**
 * amplifi.consent — front-end engine.
 *
 * Hard-withholding model: every managed script is emitted inside an INERT
 * <template> element tagged with its consent category. Browsers do not execute
 * anything inside a <template> (including <script src>), so NOTHING fires on
 * page load. The front-end JS only re-materializes scripts for granted
 * categories. Reject = zero tracking runs.
 *
 * v1.1 adds, beyond the managed-script gate:
 *  - Google Consent Mode v2 defaults (denied) pushed in <head> before any tag.
 *  - Auto-blocking of UNMANAGED third-party trackers (enqueued by other
 *    plugins/theme or printed in templates) via a script_loader_tag filter +
 *    an output-buffer pass over wp_head/body, by domain blocklist.
 *  - A persistent, always-available preferences trigger (withdrawal path).
 *  - The [amplifi-legal-doc] shortcode to render versioned policy texts.
 *  - localized policy/catalog versions + REST nonce so the JS can record
 *    consent server-side and re-prompt on policy change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amplifi_Consent_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// C1: the network shim is NO LONGER printed via a wp_head action. Doing
		// so only guaranteed it ran wherever the theme calls wp_head() — but if
		// a theme's header.php prints a raw/hardcoded tracker <script> BEFORE
		// its own wp_head() call, that code would execute before our shim node
		// even existed in the DOM, silently defeating the withholding gate.
		// Instead, filter_buffer() (below) injects the shim as the ABSOLUTE
		// FIRST thing inside <head> via a direct string-splice on the rendered
		// HTML — this is unconditionally true regardless of where/whether the
		// theme calls wp_head(). See network_shim_html().

		// Consent Mode v2 defaults must print as early as possible in <head>.
		// (Not part of the withholding mechanism above — lower priority to fix.)
		add_action( 'wp_head', array( __CLASS__, 'consent_mode_defaults' ), 0 );

		add_action( 'wp_head', array( __CLASS__, 'emit_head_scripts' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'emit_body_open_scripts' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'emit_footer_scripts' ), 1 );
		// CCPA/CPRA opt-out controls in the page FOOTER (the default). CCR
		// tit.11 §7013(c) and §7014(c) each require a conspicuous link
		// "located at either the header or footer of the business's internet
		// homepage(s)" — the banner is not one of the two named locations, and
		// it also disappears after the first choice, taking the controls with
		// it. Footer is therefore the default.
		//
		// Priority 99, deliberately LATE: the auto-rendered row must lose to a
		// shortcode placement anywhere in the page BODY, which is rendered
		// before wp_footer fires at all. Running late also lets it lose to
		// most footer widget areas / block-theme footer template parts, which
		// render earlier in wp_footer. It does NOT beat a shortcode hooked to
		// wp_footer at a priority ABOVE 99 — that one runs after us and would
		// still duplicate. Placement is tracked per control, so a shortcode
		// that prints only one of the two leaves the other to this row.
		// See $optout_rendered_dns / $optout_rendered_spi.
		add_action( 'wp_footer', array( __CLASS__, 'render_footer_optout' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 50 );

		add_shortcode( 'amplifi-consent-manager', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'amplifi-legal-doc', array( __CLASS__, 'legal_doc_shortcode' ) );
		// Placeable opt-out controls, for dropping into a theme's real footer
		// widget / nav menu so they inherit the styling of the links beside
		// them (which is what §7003(c)'s "conspicuous" test actually measures:
		// "a font size and color that is at least the approximate size or
		// color as other links next to it"). Using these INSTEAD of the
		// auto-rendered footer row is the better placement when the theme has
		// a real footer link row — no setting change is needed, and no `auto`
		// attribute exists: whichever controls a shortcode prints are recorded
		// per control, and render_footer_optout() then emits only the ones
		// still missing (or nothing at all, if a shortcode placed both).
		// Do NOT set optout_placement to 'banner' to "avoid duplicates" — that
		// suppresses the footer row but makes the JS render the controls in the
		// banner instead, which both duplicates them and puts the §7013/§7014
		// controls back in a location the regulations do not accept.
		add_shortcode( 'amplifi-do-not-sell', array( __CLASS__, 'do_not_sell_shortcode' ) );
		add_shortcode( 'amplifi-limit-spi', array( __CLASS__, 'limit_spi_shortcode' ) );
		add_shortcode( 'amplifi-optout-links', array( __CLASS__, 'optout_links_shortcode' ) );

		// Auto-block UNMANAGED trackers (opt-in via settings).
		if ( self::autoblock_on() ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'gate_enqueued_script' ), 10, 3 );
			// C1: start the output buffer on send_headers (fires BEFORE
			// template_redirect) so the buffer is open — and filter_buffer()
			// able to splice the shim into <head> — as early as physically
			// possible in the WordPress request lifecycle.
			add_action( 'send_headers', array( __CLASS__, 'start_buffer' ), 1 );
		}
	}

	private static function enabled() {
		$s = Amplifi_Consent_Store::get_settings();
		return ! empty( $s['enabled'] );
	}

	private static function autoblock_on() {
		$s = Amplifi_Consent_Store::get_settings();
		return ! empty( $s['enabled'] ) && ! empty( $s['autoblock'] );
	}

	public static function enqueue() {
		if ( ! self::enabled() ) {
			return;
		}
		// NOTE: both asset filenames carry an explicit -vN suffix that is bumped
		// on every shipped change. Do NOT rely on the `ver` query string for
		// cache busting: plugins in the wild (e.g. Admin Site Enhancements'
		// "disable_resource_version_number") strip `?ver=` at PHP_INT_MAX
		// priority, after which a CDN serving `max-age=31536000` on the bare URL
		// pins the old file for a year. Renaming the file is the only bust that
		// survives that combination. Verified live on a client site 2026-08-03,
		// where a stale consent.js kept running for hours after a plugin update.
		wp_register_style( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/css/consent-v4.css', array(), ACCONSENT_VERSION );
		wp_register_script( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/js/consent-v2.js', array(), ACCONSENT_VERSION, true );

		$settings   = Amplifi_Consent_Store::get_settings();
		$categories = Amplifi_Consent_Store::categories();

		// Group cookies by category for the Manage UI (skip unclassified).
		$cookies_by_cat = array();
		foreach ( Amplifi_Consent_Store::get_cookies() as $c ) {
			if ( 'unclassified' === $c['category'] ) {
				continue;
			}
			$cookies_by_cat[ $c['category'] ][] = array(
				'name'        => $c['name'],
				'domain'      => $c['domain'],
				'duration'    => $c['duration'],
				'description' => $c['description'],
			);
		}

		// Bind the render-time token to the visitor cookie when one already
		// exists on this request. (A first-time visitor has no cookie yet on a
		// cached page; the JS then refreshes a bound token from /config, which
		// also SETS the cookie.) We don't set the cookie here because this output
		// may be full-page-cached — /config is the uncached, reliable setter.
		$render_vid = Amplifi_Consent_Rest::read_visitor_cookie();

		// H1: CCPA/CPRA sale/share scoping. GPC / "Do Not Sell" must also block
		// specific third-party analytics/session-replay tools flagged as
		// involving disclosure to a third party (a "sale/share"), not just the
		// Marketing category — see the Sephora enforcement action.
		$sale_share_hosts = array();
		foreach ( self::blocklist() as $e ) {
			if ( ! empty( $e['sale'] ) ) {
				$sale_share_hosts[] = $e['host'];
			}
		}
		$sale_share_scripts = array();
		// H2: CCPA §1798.121 "Limit the Use of Sensitive PI" — permanently
		// blocked whenever enabled, independent of any category grant.
		$spi_hosts = array();
		foreach ( self::blocklist() as $e ) {
			if ( ! empty( $e['spi'] ) ) {
				$spi_hosts[] = $e['host'];
			}
		}
		$spi_scripts = array();
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			if ( ! empty( $s['sale_share'] ) ) {
				$sale_share_scripts[] = $s['id'];
			}
			if ( ! empty( $s['sensitive_pi'] ) ) {
				$spi_scripts[] = $s['id'];
			}
		}

		// M5: disclose that consent records may also be mirrored to a webhook
		// (a data processor), which may be located in a different country.
		$webhook_active = ! empty( $settings['webhook_enabled'] ) && ! empty( $settings['webhook_url'] );

		wp_localize_script( 'acconsent', 'ACCONSENT', array(
			'settings'       => array(
				'banner_title'      => $settings['banner_title'],
				'banner_message'    => $settings['banner_message'],
				'accept_label'      => $settings['accept_label'],
				'reject_label'      => $settings['reject_label'],
				'manage_label'      => $settings['manage_label'],
				'save_label'        => $settings['save_label'],
				'prefs_label'       => $settings['prefs_label'],
				'toast_accepted'    => $settings['toast_accepted'],
				'toast_rejected'    => $settings['toast_rejected'],
				'consent_days'      => (int) $settings['consent_days'],
				'accent_color'      => $settings['accent_color'],
				'position'          => $settings['position'],
				'privacy_url'       => $settings['privacy_url'],
				'floating'          => (bool) $settings['floating_button'],
				'gpc_enabled'       => (bool) $settings['gpc_enabled'],
				'consent_mode'      => (bool) $settings['consent_mode'],
				'do_not_sell'       => (bool) $settings['do_not_sell'],
				'dns_label'         => $settings['dns_label'],
				'limit_spi_enabled' => (bool) $settings['limit_spi_enabled'],
				'limit_spi_label'   => $settings['limit_spi_label'],
				'optout_placement'  => self::optout_placement(),
				'webhook_active'    => $webhook_active,
				'webhook_disclosure' => __( 'Consent records may also be sent to a data processor configured by this site, which may be located in a different country.', 'amplifi-consent' ),
				// Visitor-facing strings that live only in the JS — passed here so
				// they are translatable (gettext) rather than baked into consent.js.
				'privacy_text'   => __( 'Privacy Policy', 'amplifi-consent' ),
				'aria_consent'   => __( 'Cookie consent', 'amplifi-consent' ),
				'col_name'       => __( 'Name', 'amplifi-consent' ),
				'col_domain'     => __( 'Domain', 'amplifi-consent' ),
				'col_duration'   => __( 'Duration', 'amplifi-consent' ),
				'cookie_one'     => __( 'cookie', 'amplifi-consent' ),
				'cookie_many'    => __( 'cookies', 'amplifi-consent' ),
				'close_label'    => __( 'Close', 'amplifi-consent' ),
			),
			'categories'         => $categories,
			'cookies'            => $cookies_by_cat,
			'legal'              => Amplifi_Consent_Store::legal_snapshot(),
			'policy_version'     => Amplifi_Consent_Store::policy_version(),
			'catalog_hash'       => Amplifi_Consent_Store::catalog_hash(),
			'rest_url'           => esc_url_raw( rest_url( Amplifi_Consent_Rest::NS . '/consent' ) ),
			'config_url'         => esc_url_raw( rest_url( Amplifi_Consent_Rest::NS . '/config' ) ),
			// H1: hosts/managed-script ids flagged as "sale/share" — GPC / Do
			// Not Sell withholds these even if their category is granted.
			'sale_share_hosts'   => array_values( array_unique( $sale_share_hosts ) ),
			'sale_share_scripts' => array_values( array_unique( $sale_share_scripts ) ),
			// H2: hosts/managed-script ids flagged as Sensitive PI — always
			// withheld while limit_spi_enabled is true, independent of consent.
			'spi_hosts'          => array_values( array_unique( $spi_hosts ) ),
			'spi_scripts'        => array_values( array_unique( $spi_scripts ) ),
			// Signed consent token issued at render (proves a real page render,
			// bound to the visitor cookie when present). Travels in the POST body
			// so a sendBeacon unload-fallback works. When no cookie exists yet
			// (first-time visitor on a cached page) this token is unbound; the JS
			// fetches a bound one from /config (which also sets the cookie).
			'token'              => Amplifi_Consent_Rest::issue_token( $render_vid ),
			'has_vid'            => ( '' !== $render_vid ),
			'storage_key'        => 'acconsent_v1',
		) );

		wp_enqueue_style( 'acconsent' );
		wp_enqueue_script( 'acconsent' );

		$accent = $settings['accent_color'];
		wp_add_inline_style( 'acconsent', ":root{--acconsent-accent:{$accent};}" );
	}

	/**
	 * Client-side network shim. Tag-rewriting can't catch a tracker that a
	 * first-party bundle fires via fetch()/XHR/sendBeacon/new Image(). This
	 * inline script monkey-patches those APIs so any request to a blocklisted
	 * host is dropped until consent is granted, then replayed/allowed.
	 * window.__acconsentReleaseNetwork(grantedCats) is called by the consent
	 * engine on accept to lift the block for granted categories.
	 *
	 * C1: this method no longer echoes directly nor is hooked to wp_head — a
	 * wp_head hook only guarantees the shim runs wherever the THEME calls
	 * wp_head(), which is too late if header.php prints a raw tracker
	 * <script> before that call. Instead this returns the shim markup as a
	 * string; filter_buffer() (below) splices it as the ABSOLUTE FIRST thing
	 * inside <head> via a direct string operation on the buffered response —
	 * true regardless of where/whether the theme calls wp_head(). See
	 * network_shim_html() / filter_buffer().
	 */
	public static function network_shim() {
		// Back-compat shim for any external caller still invoking this
		// directly (e.g. via a manual add_action) — echoes the same markup
		// filter_buffer() now splices automatically.
		echo self::network_shim_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the network-shim <script> markup as a string (see network_shim()
	 * docblock for why this isn't echoed directly by a hook).
	 */
	private static function network_shim_html() {
		if ( ! self::enabled() ) {
			return '';
		}
		$entries = self::blocklist();
		if ( empty( $entries ) ) {
			return '';
		}
		// host => category map for the client.
		$map = array();
		foreach ( $entries as $e ) {
			$map[ $e['host'] ] = $e['category'];
		}
		// H1/H2: hosts flagged as constituting a CCPA "sale/share" or as
		// handling Sensitive Personal Information. These are blocked
		// independent of (in addition to) the ordinary category grant — see
		// saleBlocked()/spiBlocked() below.
		$sale_hosts = array();
		$spi_hosts  = array();
		foreach ( $entries as $e ) {
			if ( ! empty( $e['sale'] ) ) {
				$sale_hosts[] = $e['host'];
			}
			if ( ! empty( $e['spi'] ) ) {
				$spi_hosts[] = $e['host'];
			}
		}
		$json      = wp_json_encode( $map );
		$sale_json = wp_json_encode( array_values( $sale_hosts ) );
		$spi_json  = wp_json_encode( array_values( $spi_hosts ) );
		// Advanced Consent Mode allowlist for the client shim. Empty unless the
		// consent_mode setting is on AND at least one ENABLED managed script is
		// flagged consent_mode — so a site not using CM behaves byte-identically
		// to the hard-withholding default. See self::cm_hosts().
		$cm_json = wp_json_encode( array_values( self::cm_hosts() ) );
				// NOTE: built via a NOWDOC + str_replace (not ob_start()/ob_get_clean())
				// because this method is called from filter_buffer(), which is itself
				// running as an ob_start() display-handler callback — PHP disallows
				// ob_start() while inside another buffer's display handler ("Cannot use
				// output buffering in output buffering display handlers"), which is a
				// hard fatal, not a warning. A nowdoc (single-quoted heredoc) with
				// unique __TOKEN__ placeholders avoids both that restriction AND any
				// PHP variable-interpolation collision with the JS body (bare `$`).
				$template = <<<'ACCONSENT_NET_SHIM'
		<script data-acconsent="net-shim">
		(function(){
		  var MAP = __ACCONSENT_SHIM_MAP__;
		  // H1: hosts whose data flow constitutes a CCPA/CPRA "sale/share" — blocked
		  // whenever the visitor has opted out via GPC or "Do Not Sell", regardless
		  // of whether their (possibly narrower, e.g. analytics-only) category was
		  // otherwise granted. See the Sephora enforcement action re: bucketed
		  // analytics tools that still disclose data to a third party.
		  var SALE = __ACCONSENT_SHIM_SALE__;
		  // H2: hosts flagged as handling Sensitive Personal Information (CCPA
		  // §1798.121) — permanently blocked while limit_spi_enabled is on,
		  // independent of any category grant.
		  var SPI = __ACCONSENT_SHIM_SPI__;
		  // Google Advanced Consent Mode allowlist: hosts that MAY load
		  // pre-consent (in cookieless "modeling ping" mode) even though their
		  // category isn't granted yet. Populated ONLY when the consent_mode
		  // setting is on AND at least one enabled managed script is flagged
		  // consent_mode — otherwise empty, so behaviour is byte-identical to
		  // the hard-withholding default. A CM-allowed host STILL yields to a
		  // sale/share or SPI opt-out below (compliance wins over modeling).
		  var CM = __ACCONSENT_SHIM_CM__;
		  var granted = { necessary: true };
		  var saleShareOptOut = false; // set by consent.js via __acconsentSetSaleShareOptOut.
		  var spiLimited = true; // H2: SPI limitation is ON by default — see readme FAQ.
		  function catFor(url){
		    try { url = String(url).toLowerCase(); } catch(e){ return null; }
		    for (var host in MAP){ if (url.indexOf(host) !== -1) return MAP[host]; }
		    return null;
		  }
		  function hostMatch(url, list){
		    try { url = String(url).toLowerCase(); } catch(e){ return false; }
		    for (var i=0;i<list.length;i++){ if (url.indexOf(list[i]) !== -1) return true; }
		    return false;
		  }
		  function saleBlocked(url){ return saleShareOptOut && hostMatch(url, SALE); }
		  function spiBlocked(url){ return spiLimited && hostMatch(url, SPI); }
		  function cmAllowed(url){ return hostMatch(url, CM); }
		  function blocked(url){
		    // A sale/share or SPI opt-out ALWAYS wins — even the cookieless
		    // Consent-Mode ping is withheld once the visitor has opted out.
		    if (saleBlocked(url) || spiBlocked(url)) return true;
		    var c = catFor(url);
		    if (c && !granted[c]) {
		      // Category not yet granted. Normally withheld — UNLESS this is a
		      // Consent-Mode-allowlisted Google host, which is permitted to load
		      // cookieless pre-consent so GA4 can send anonymized modeling pings
		      // (the gtag('consent','default',{…denied…}) block keeps identifiers
		      // off; consent.js upgrades to granted via gtag('consent','update')).
		      if (cmAllowed(url)) return false;
		      return true;
		    }
		    return false;
		  }
		  // Generalized deferral: stash the blocked resource attribute so the consent
		  // engine can restore it on grant. Works for any tag/attribute (src, href,
		  // srcset) so release is uniform. Script also gets type=text/plain (inert).
		  function neutralize(node, attr, value){
		    try {
		      var tag = node.tagName ? node.tagName.toLowerCase() : '';
		      if (tag === 'script') { try { node.type = 'text/plain'; } catch(e){} }
		      node.setAttribute('data-acconsent-blocked', catFor(value) || 'marketing');
		      node.setAttribute('data-acconsent-attr', attr);
		      node.setAttribute('data-acconsent-src', value);
		    } catch(e){}
		  }
		  function inertStub(){
		    // Minimal EventTarget-ish no-op so `x.addEventListener`, `x.close()`,
		    // `x.send()`, `x.postMessage()` and readyState reads don't throw.
		    return {
		      readyState: 3, // CLOSED
		      close: function(){}, send: function(){}, postMessage: function(){},
		      terminate: function(){}, start: function(){},
		      addEventListener: function(){}, removeEventListener: function(){},
		      dispatchEvent: function(){ return false; },
		      onopen: null, onmessage: null, onerror: null, onclose: null
		    };
		  }
		  // Apply the network-API guards to a given window/realm. Called for the top
		  // window AND for each same-origin child iframe (whose pristine realm would
		  // otherwise expose un-patched fetch/XHR/sendBeacon/WebSocket — a full escape).
		  // Idempotent via a per-realm flag so re-observing the same frame is a no-op.
		  function patchRealm(win){
		    try {
		      if (!win || win.__acconsentPatched) return;
		      win.__acconsentPatched = true;
		    } catch(e){ return; } // cross-origin frame: property access throws — skip.
		    // fetch
		    if (win.fetch){
		      var of = win.fetch;
		      win.fetch = function(input, init){
		        var url = (input && input.url) ? input.url : input;
		        if (blocked(url)) return Promise.resolve(new win.Response(null, {status:204, statusText:'blocked-by-consent'}));
		        return of.apply(this, arguments);
		      };
		    }
		    // XHR
		    if (win.XMLHttpRequest){
		      var op = win.XMLHttpRequest.prototype.open;
		      win.XMLHttpRequest.prototype.open = function(method, url){ this.__acUrl = url; return op.apply(this, arguments); };
		      var os = win.XMLHttpRequest.prototype.send;
		      win.XMLHttpRequest.prototype.send = function(){
		        if (blocked(this.__acUrl)) { try { this.abort(); } catch(e){} return; }
		        return os.apply(this, arguments);
		      };
		    }
		    // sendBeacon (prototype, so Navigator.prototype.sendBeacon.call(...) is caught)
		    if (win.Navigator && win.Navigator.prototype && win.Navigator.prototype.sendBeacon){
		      var ob = win.Navigator.prototype.sendBeacon;
		      win.Navigator.prototype.sendBeacon = function(url, data){
		        if (blocked(url)) return false;
		        return ob.apply(this, arguments);
		      };
		    }
		    // Transport constructors that bypass the fetch/XHR wrappers entirely. A
		    // tracker can open a WebSocket / EventSource / Worker straight to its host;
		    // gate the constructor on the URL argument. For a BLOCKED url we return an
		    // inert stub (no connection, no-op methods) rather than throwing — matching
		    // the silent fetch/XHR behaviour so host init code isn't crashed.
		    ['WebSocket','EventSource','Worker','SharedWorker'].forEach(function(name){
		      var O = win[name];
		      if (typeof O !== 'function') return;
		      function Guarded(url){
		        if (blocked(url)) { return inertStub(); }
		        var a = Array.prototype.slice.call(arguments);
		        return new (Function.prototype.bind.apply(O, [null].concat(a)))();
		      }
		      try {
		        Guarded.prototype = O.prototype; // keep instanceof + instance constants.
		        // Copy the interface's STATIC props (WebSocket.OPEN, EventSource.CLOSED,
		        // …) — libraries read `ws.readyState === WebSocket.OPEN`, which silently
		        // breaks if these are dropped even when the connection is allowed.
		        Object.getOwnPropertyNames(O).forEach(function(k){
		          if (k === 'prototype' || k === 'length' || k === 'name') return;
		          try { Object.defineProperty(Guarded, k, Object.getOwnPropertyDescriptor(O, k)); } catch(e){}
		        });
		        win[name] = Guarded;
		      } catch(e){}
		    });
		    // C2: a registered Service Worker persists across page loads/tabs in a
		    // scope this shim can never reach once it's running (its own internal
		    // fetch/XHR calls are invisible to a main-thread patch). This guard
		    // prevents a NEW registration from a blocklisted host pre-consent; it
		    // does NOT unregister an already-registered SW from before this plugin
		    // was active — documented as a known limitation in the readme FAQ.
		    try {
		      if (win.navigator && win.navigator.serviceWorker && win.navigator.serviceWorker.register) {
		        var osw = win.navigator.serviceWorker.register.bind(win.navigator.serviceWorker);
		        win.navigator.serviceWorker.register = function(scriptURL, opts){
		          if (blocked(scriptURL)) {
		            return Promise.reject(new (win.DOMException || Error)('Blocked pending consent', 'SecurityError'));
		          }
		          return osw(scriptURL, opts);
		        };
		      }
		    } catch(e){}
		    // M2: RTCPeerConnection is a WebRTC IP-leak vector — a tracker can use a
		    // STUN/TURN server purely to enumerate the visitor's local/public IPs
		    // outside the fetch/XHR path entirely. We FILTER (not block outright)
		    // blocklisted iceServers entries so legitimate WebRTC audio/video
		    // features on the page keep working while tracker-operated STUN/TURN
		    // hosts are stripped from the config before the connection is opened.
		    ['RTCPeerConnection','webkitRTCPeerConnection','mozRTCPeerConnection'].forEach(function(name){
		      var O = win[name];
		      if (typeof O !== 'function') return;
		      function GuardedRTC(config, constraints){
		        try {
		          if (config && Array.isArray(config.iceServers)) {
		            config = Object.assign({}, config, {
		              iceServers: config.iceServers.filter(function(srv){
		                var urls = [].concat(srv && srv.urls ? srv.urls : []);
		                return !urls.some(function(u){ return blocked(u); });
		              })
		            });
		          }
		        } catch(e){}
		        return new (Function.prototype.bind.apply(O, [null, config, constraints]))();
		      }
		      try {
		        GuardedRTC.prototype = O.prototype;
		        Object.getOwnPropertyNames(O).forEach(function(k){
		          if (k === 'prototype' || k === 'length' || k === 'name') return;
		          try { Object.defineProperty(GuardedRTC, k, Object.getOwnPropertyDescriptor(O, k)); } catch(e){}
		        });
		        win[name] = GuardedRTC;
		      } catch(e){}
		    });
		  }
		  patchRealm(window);
		  // NOTE: dynamic import('https://tracker/…') goes through the module pipeline
		  // and cannot be monkey-patched from script; a server CSP script-src/connect-src
		  // is the only reliable backstop for that vector. Documented in the readme.
		  //
		  // H7: everything below used to patch ONLY the top window/document directly
		  // (bare `window.HTMLScriptElement`, `document.write`, a single top-level
		  // MutationObserver, …). That meant a same-origin CHILD IFRAME's own realm
		  // — its own HTMLScriptElement, its own document.write, its own
		  // MutationObserver — was never guarded: a tracker running inside a
		  // same-origin iframe doing `document.createElement('script'); s.src=...`
		  // bypassed everything. installDomGuards(win, doc) makes the exact same
		  // logic reusable per-realm; patchFrame() (below) calls it for every
		  // same-origin child iframe, and because its own MutationObserver calls
		  // patchFrame() again when IT discovers a nested iframe, the guard applies
		  // recursively to any nesting depth (patchFrame's own __acFramePatched
		  // per-element flag prevents infinite loops).
		  var ATTRS = { 'src':1, 'href':1, 'srcset':1, 'imagesrcset':1, 'data':1, 'poster':1, 'ping':1, 'action':1 };
		  var TAGS = { 'script':1, 'img':1, 'iframe':1, 'link':1, 'video':1, 'audio':1, 'embed':1, 'object':1, 'track':1, 'source':1, 'a':1, 'form':1 };
		  // Attribute names actually swept for a blocked value by neutralizeIfBlocked/
		  // sanitizeMarkup (M1 adds 'ping'/'action' to the earlier src/href/srcset/
		  // data/poster set used by the tag-manager/pixel vectors).
		  var SCAN_ATTRS = ['src','href','srcset','data','poster','ping','action'];
		  var SCAN_SELECTOR = 'script[src],img[src],img[srcset],iframe[src],link[href],video[src],video[poster],audio[src],embed[src],object[data],track[src],source[src],source[srcset],a[ping],form[action],meta[http-equiv]';
		  // Dynamically-injected <script src> / <img src>/<iframe src>/<link href> — the
		  // PRIMARY tag-manager and Meta-Pixel loader pattern:
		  //   document.createElement('script'); s.src='…fb…'.
		  // Patch the prototype attribute setter so any blocklisted resource is deferred
		  // (stashed via neutralize, released on grant) instead of fetched.
		  function guardProp(proto, prop){
		    try {
		      var d = Object.getOwnPropertyDescriptor(proto, prop);
		      if (!d || !d.set) return;
		      Object.defineProperty(proto, prop, {
		        configurable: true, enumerable: d.enumerable,
		        get: function(){ return d.get.call(this); },
		        set: function(v){
		          if (blocked(v)) { neutralize(this, prop, v); return; }
		          d.set.call(this, v);
		        }
		      });
		    } catch(e){}
		  }
		  // setAttribute / setAttributeNS('src'|'href'|'srcset'|'imagesrcset'|'data'|
		  // 'poster'|'ping'|'action', tracker) bypass the property setters — guard
		  // both. (setAttributeNS is a SEPARATE method, not covered by setAttribute.)
		  // Map a blocked tag+attr to the attribute the release path should restore.
		  function guardSetAttr(method){
		    return function(){
		      try {
		        // setAttribute(name,val) | setAttributeNS(ns,name,val)
		        var isNS = arguments.length >= 3;
		        var name = isNS ? arguments[1] : arguments[0];
		        var value = isNS ? arguments[2] : arguments[1];
		        var lname = String(name).toLowerCase();
		        if (ATTRS[lname] && blocked(value)){
		          var t = this.tagName ? this.tagName.toLowerCase() : '';
		          if (TAGS[t]) { neutralize(this, lname, value); return; }
		        }
		      } catch(e){}
		      return method.apply(this, arguments);
		    };
		  }
		  // document.write / writeln inject raw markup the parser sets attributes on
		  // directly (the classic document.write('<script src=fb>') GTM/pixel pattern),
		  // bypassing every setter above. Pre-scan the string, neutralize blocked
		  // resources in a detached fragment, and write the sanitized markup instead.
		  // Shared across realms (doesn't touch win/doc — operates on a detached
		  // <template> in whichever document happens to be current when called).
		  function sanitizeMarkup(str){
		    try {
		      if (str.indexOf('<') === -1) return str;
		      var tpl = document.createElement('template');
		      tpl.innerHTML = str;
		      var changed = false;
		      var nodes = tpl.content.querySelectorAll(SCAN_SELECTOR);
		      Array.prototype.forEach.call(nodes, function(n){
		        SCAN_ATTRS.forEach(function(a){
		          var v = n.getAttribute(a);
		          if (v && blocked(v)) { neutralize(n, a, v); n.removeAttribute(a); changed = true; }
		        });
		      });
		      return changed ? tpl.innerHTML : str;
		    } catch(e){ return str; }
		  }
		  // MutationObserver backstop for innerHTML/insertAdjacentHTML-injected pixels
		  // (<img>/<iframe>/<link>) — those are parser-constructed, so no setter fires.
		  // Best-effort: neutralizes on insertion so the resource is deferred/releasable
		  // and repeat loads are blocked. (Scripts injected via innerHTML do not execute
		  // per spec, so they need no handling here.) M3: also removes a blocklisted
		  // <meta http-equiv="refresh"> outright — its content attribute isn't
		  // meaningfully "releasable" the way a src is, so removing the tag means the
		  // redirect never happens (the correct outcome until consent is granted, at
		  // which point the page has already moved on — a meta-refresh is a one-shot).
		  function neutralizeIfBlocked(node){
		    if (!node || node.nodeType !== 1 || !node.getAttribute) return;
		    if (node.getAttribute('data-acconsent-blocked')) return; // already handled.
		    var t = node.tagName ? node.tagName.toLowerCase() : '';
		    if (t === 'meta' && String(node.getAttribute('http-equiv') || '').toLowerCase() === 'refresh'){
		      var content = node.getAttribute('content') || '';
		      var mm = content.match(/url\s*=\s*(.+)$/i);
		      var url = mm ? mm[1].trim() : content;
		      if (url && blocked(url)) { try { node.parentNode.removeChild(node); } catch(e){} }
		      return;
		    }
		    SCAN_ATTRS.forEach(function(a){
		      var v = node.getAttribute(a);
		      if (v && blocked(v)){
		        neutralize(node, a, v);
		        try { node.removeAttribute(a); } catch(e){}
		      }
		    });
		  }
		  // M1: <form> submitted via a JS call to .submit() bypasses the 'submit'
		  // event entirely (that event only fires for a user-initiated/requestSubmit
		  // path) — guard the method directly so a JS-triggered submission to a
		  // blocklisted action is silently dropped (matching the inert-stub
		  // philosophy used elsewhere in this shim) rather than thrown.
		  function guardFormSubmit(win){
		    try {
		      if (!win.HTMLFormElement || !win.HTMLFormElement.prototype.submit) return;
		      var os = win.HTMLFormElement.prototype.submit;
		      win.HTMLFormElement.prototype.submit = function(){
		        if (blocked(this.action)) { return; }
		        return os.apply(this, arguments);
		      };
		    } catch(e){}
		  }
		  // M1: a REAL (non-JS-triggered) form submission — e.g. the visitor clicking
		  // a submit button — never goes through .submit() above, so it needs its
		  // own capturing listener on the document.
		  function guardFormSubmitEvent(doc){
		    try {
		      doc.addEventListener('submit', function(e){
		        var f = e.target;
		        if (f && f.action && blocked(f.action)) { e.preventDefault(); }
		      }, true);
		    } catch(e){}
		  }
		  // Patch a same-origin child iframe's pristine realm — BOTH its network APIs
		  // (patchRealm) and its DOM-injection guards (installDomGuards, defined
		  // below) — so nothing in that realm can bypass the gate. Re-runs on load
		  // too, since contentWindow/contentDocument are replaced when the frame
		  // navigates.
		  function patchFrame(frame){
		    try {
		      if (frame.__acFramePatched) return; // element-level guard: no listener leak.
		      frame.__acFramePatched = true;
		      patchRealm(frame.contentWindow);
		      try { installDomGuards(frame.contentWindow, frame.contentDocument || (frame.contentWindow && frame.contentWindow.document)); } catch(e2){}
		      frame.addEventListener('load', function(){
		        try { patchRealm(frame.contentWindow); } catch(e){}
		        try { installDomGuards(frame.contentWindow, frame.contentDocument || (frame.contentWindow && frame.contentWindow.document)); } catch(e2){}
		      });
		    } catch(e){} // cross-origin: inaccessible (and can't host our trackers anyway).
		  }
		  function scrub(node){
		    if (!node || node.nodeType !== 1) return;
		    var t = node.tagName ? node.tagName.toLowerCase() : '';
		    if (TAGS[t] || t === 'meta') neutralizeIfBlocked(node);
		    if (t === 'iframe') patchFrame(node);
		    // querySelectorAll already returns ALL matching descendants (flat) — no need
		    // to recurse per-match (that would re-scan subtrees super-linearly).
		    if (node.querySelectorAll){
		      var kids = node.querySelectorAll(SCAN_SELECTOR);
		      Array.prototype.forEach.call(kids, neutralizeIfBlocked);
		      var frames = node.querySelectorAll('iframe');
		      Array.prototype.forEach.call(frames, patchFrame);
		    }
		  }
		  // H7: install every DOM-injection guard (property setters, setAttribute/NS,
		  // document.write/writeln, Range.createContextualFragment, form-submit,
		  // the MutationObserver backstop) into a given realm (win/doc pair). Called
		  // once for the TOP window/document, and again for every same-origin child
		  // iframe's realm via patchFrame() above — giving natural recursion to any
		  // iframe nesting depth.
		  function installDomGuards(win, doc){
		    try {
		      if (!win || !doc || win.__acconsentDomGuarded) return;
		      win.__acconsentDomGuarded = true;
		    } catch(e){ return; } // cross-origin: property access throws — skip.
		    if (win.HTMLScriptElement) guardProp(win.HTMLScriptElement.prototype, 'src');
		    if (win.HTMLImageElement) { guardProp(win.HTMLImageElement.prototype, 'src'); guardProp(win.HTMLImageElement.prototype, 'srcset'); }
		    if (win.HTMLIFrameElement) guardProp(win.HTMLIFrameElement.prototype, 'src');
		    if (win.HTMLLinkElement) guardProp(win.HTMLLinkElement.prototype, 'href');
		    if (win.HTMLMediaElement) guardProp(win.HTMLMediaElement.prototype, 'src'); // audio/video
		    if (win.HTMLVideoElement) guardProp(win.HTMLVideoElement.prototype, 'poster');
		    if (win.HTMLEmbedElement) guardProp(win.HTMLEmbedElement.prototype, 'src');
		    if (win.HTMLObjectElement) guardProp(win.HTMLObjectElement.prototype, 'data');
		    if (win.HTMLSourceElement) { guardProp(win.HTMLSourceElement.prototype, 'src'); guardProp(win.HTMLSourceElement.prototype, 'srcset'); }
		    if (win.HTMLTrackElement) guardProp(win.HTMLTrackElement.prototype, 'src');
		    if (win.HTMLAnchorElement) guardProp(win.HTMLAnchorElement.prototype, 'ping'); // M1
		    if (win.HTMLFormElement) guardProp(win.HTMLFormElement.prototype, 'action'); // M1
		    if (win.Element){
		      win.Element.prototype.setAttribute = guardSetAttr(win.Element.prototype.setAttribute);
		      if (win.Element.prototype.setAttributeNS) win.Element.prototype.setAttributeNS = guardSetAttr(win.Element.prototype.setAttributeNS);
		    }
		    if (doc.write){
		      var ow = doc.write;
		      doc.write = function(s){ return ow.call(doc, sanitizeMarkup(String(s == null ? '' : s))); };
		    }
		    if (doc.writeln){
		      var owl = doc.writeln;
		      doc.writeln = function(s){ return owl.call(doc, sanitizeMarkup(String(s == null ? '' : s))); };
		    }
		    // Range.createContextualFragment builds nodes whose scripts DO execute on
		    // insertion (unlike innerHTML), and the parser sets src so guardProp never
		    // fires — sanitize the markup string the same way as document.write.
		    if (win.Range && win.Range.prototype.createContextualFragment){
		      var ocf = win.Range.prototype.createContextualFragment;
		      win.Range.prototype.createContextualFragment = function(str){
		        return ocf.call(this, sanitizeMarkup(String(str == null ? '' : str)));
		      };
		    }
		    guardFormSubmit(win); // M1
		    guardFormSubmitEvent(doc); // M1
		    if (win.MutationObserver){
		      try {
		        var mo = new win.MutationObserver(function(muts){
		          for (var i=0;i<muts.length;i++){
		            var added = muts[i].addedNodes;
		            for (var j=0;j<added.length;j++){ scrub(added[j]); }
		          }
		        });
		        mo.observe(doc.documentElement || doc, { childList: true, subtree: true });
		        win.__acconsentObserver = mo;
		      } catch(e){}
		    }
		    // Sweep anything already present in this realm's document at install
		    // time (mirrors the top-level "existing iframes at shim execution time"
		    // sweep, generalized to any realm/depth).
		    try {
		      var pre = doc.querySelectorAll ? doc.querySelectorAll(SCAN_SELECTOR) : [];
		      Array.prototype.forEach.call(pre, neutralizeIfBlocked);
		      var preFrames = doc.getElementsByTagName ? doc.getElementsByTagName('iframe') : [];
		      for (var fi=0; fi<preFrames.length; fi++){ patchFrame(preFrames[fi]); }
		    } catch(e){}
		  }
		  installDomGuards(window, document);
		  // Released by the consent engine on grant.
		  window.__acconsentReleaseNetwork = function(grantedCats){
		    if (grantedCats && typeof grantedCats === 'object'){
		      for (var k in grantedCats){ granted[k] = !!grantedCats[k]; }
		    }
		  };
		  // H1: called by consent.js so the shim's block/allow decision for the
		  // sale/share-flagged hosts stays consistent with whatever was just
		  // recorded (GPC active, or an explicit "Do Not Sell" click).
		  window.__acconsentSetSaleShareOptOut = function(v){ saleShareOptOut = !!v; };
		  // H2: exposed for symmetry/future use; spiLimited currently has no visitor-
		  // facing "allow" path (SPI limitation is unconditional while enabled), but
		  // an admin-configurable off-switch is still applied via the localized
		  // limit_spi_enabled flag which controls whether spiLimited is ever true —
		  // see enqueue().
		  window.__acconsentSetSpiLimited = function(v){ spiLimited = !!v; };
		})();
		</script>
		ACCONSENT_NET_SHIM;

				return str_replace(
					array( '__ACCONSENT_SHIM_MAP__', '__ACCONSENT_SHIM_SALE__', '__ACCONSENT_SHIM_SPI__', '__ACCONSENT_SHIM_CM__' ),
					array( $json, $sale_json, $spi_json, $cm_json ),
					$template
				);
			}

	/**
	 * Google Consent Mode v2: set all signals to 'denied' before any Google tag
	 * loads, so even tags we don't directly gate respect consent. The JS flips
	 * them to 'granted' per category on accept. Only printed when enabled.
	 */
	public static function consent_mode_defaults() {
		if ( ! self::enabled() ) {
			return;
		}
		$s = Amplifi_Consent_Store::get_settings();
		if ( empty( $s['consent_mode'] ) ) {
			return;
		}
		?>
<script data-acconsent="consent-mode">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{
  'ad_storage':'denied','analytics_storage':'denied',
  'ad_user_data':'denied','ad_personalization':'denied',
  'functionality_storage':'denied','personalization_storage':'denied',
  'security_storage':'granted','wait_for_update':500
});
</script>
		<?php
	}

	private static function emit_for_placement( $placement ) {
		if ( ! self::enabled() ) {
			return;
		}
		$cm_on = self::cm_active();
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			if ( empty( $s['enabled'] ) || $s['placement'] !== $placement ) {
				continue;
			}
			// Advanced Consent Mode: a CM-flagged Google tag loads LIVE
			// pre-consent (cookieless, via the gtag('consent','default',
			// {…denied…}) block) instead of being hard-withheld in an inert
			// <template>. It is wrapped in a <div data-acconsent="cm"> marker so
			// the server-side filter_buffer() auto-block passes skip it (they
			// already exclude any data-acconsent-tagged node), and the client
			// network shim's CM allowlist lets its Google host through. Only when
			// the global setting is on AND this script is flagged — otherwise it
			// falls through to the normal hard-withholding <template> path.
			if ( $cm_on && ! empty( $s['consent_mode'] ) ) {
				printf(
					"\n<div data-acconsent=\"cm\" data-acconsent-id=\"%s\">%s</div>\n",
					esc_attr( $s['id'] ),
					$s['code'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verbatim vendor tag markup, emitted live by design (Advanced Consent Mode)
				);
				continue;
			}
			// The gated script body is BASE64-ENCODED inside the inert <template>.
			// Encoding makes the payload opaque to the HTML parser, so a managed
			// snippet that itself contains "</template>" (or any delimiter) can
			// no longer break out of the inert container and execute pre-consent.
			// The front-end JS base64-decodes the body before releasing it.
			printf(
				"\n<template class=\"acconsent-gated\" data-acconsent-category=\"%s\" data-acconsent-id=\"%s\" data-acconsent-enc=\"base64\">%s</template>\n",
				esc_attr( $s['category'] ),
				esc_attr( $s['id'] ),
				base64_encode( (string) $s['code'] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
		}
	}

	/**
	 * Advanced Consent Mode active? True only when the global consent_mode
	 * setting is on AND at least one ENABLED managed script is flagged
	 * consent_mode. When false, every CM code path is inert and behaviour is
	 * byte-identical to the hard-withholding default.
	 */
	private static function cm_active() {
		$s = Amplifi_Consent_Store::get_settings();
		if ( empty( $s['consent_mode'] ) ) {
			return false;
		}
		foreach ( Amplifi_Consent_Store::get_scripts() as $sc ) {
			if ( ! empty( $sc['enabled'] ) && ! empty( $sc['consent_mode'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hosts that the client network shim may allow to load cookieless
	 * pre-consent under Advanced Consent Mode. Derived by scanning each
	 * enabled, CM-flagged managed script's body for known Consent-Mode-aware
	 * Google-family loader hosts — we only ever allowlist Google's own tag
	 * infrastructure (which honours gtag consent state), never an arbitrary
	 * third-party tracker. Empty unless cm_active().
	 */
	private static function cm_hosts() {
		if ( ! self::cm_active() ) {
			return array();
		}
		// Consent-Mode-aware Google tag delivery hosts. GTM (googletagmanager)
		// is the loader; the *.google-analytics.com / analytics.google.com /
		// *.google.com collect endpoints are where the cookieless pings go.
		$known = array(
			'googletagmanager.com',
			'google-analytics.com',
			'analytics.google.com',
			'g.doubleclick.net',
			'region1.google-analytics.com',
			'region1.analytics.google.com',
		);
		$hosts = array();
		foreach ( Amplifi_Consent_Store::get_scripts() as $sc ) {
			if ( empty( $sc['enabled'] ) || empty( $sc['consent_mode'] ) ) {
				continue;
			}
			$code = strtolower( (string) $sc['code'] );
			foreach ( $known as $h ) {
				if ( false !== strpos( $code, $h ) ) {
					$hosts[] = $h;
				}
			}
		}
		// Always include the GA collect endpoints when GTM is present — GTM
		// injects GA4, which pings *.google-analytics.com even though that host
		// isn't literally in the container-loader snippet's text.
		if ( in_array( 'googletagmanager.com', $hosts, true ) ) {
			$hosts[] = 'google-analytics.com';
			$hosts[] = 'analytics.google.com';
			$hosts[] = 'region1.google-analytics.com';
			$hosts[] = 'region1.analytics.google.com';
			$hosts[] = 'g.doubleclick.net';
		}
		return array_values( array_unique( $hosts ) );
	}

	public static function emit_head_scripts() {
		self::emit_for_placement( 'head' );
	}

	public static function emit_body_open_scripts() {
		self::emit_for_placement( 'body_open' );
	}

	public static function emit_footer_scripts() {
		self::emit_for_placement( 'footer' );
	}

	/* ---------------- Auto-block UNMANAGED trackers ---------------- */

	/**
	 * Parsed blocklist: ordered list of [ 'host' => string, 'category' => string ].
	 * The category is the consent bucket the tracker is released under, so
	 * granting Analytics does NOT release Marketing/ad pixels.
	 */
	private static function blocklist() {
		$s = Amplifi_Consent_Store::get_settings();
		return Amplifi_Consent_Store::parse_blocklist( isset( $s['blocklist'] ) ? $s['blocklist'] : '' );
	}

	/**
	 * If $url matches a blocklisted host, return the category it should be
	 * released under; otherwise false. Matching is case-insensitive.
	 */
	private static function blocked_category( $url ) {
		if ( ! $url ) {
			return false;
		}
		$url = strtolower( $url );
		foreach ( self::blocklist() as $entry ) {
			if ( '' !== $entry['host'] && false !== strpos( $url, $entry['host'] ) ) {
				return $entry['category'];
			}
		}
		return false;
	}

	/**
	 * Gate WP-enqueued tracker scripts: if a registered script's src matches the
	 * blocklist, rewrite its tag to an inert type that won't execute, tagged
	 * with the correct consent CATEGORY so the front-end JS releases it only on
	 * the matching grant. Uses WP_HTML_Tag_Processor when available (robust to
	 * casing/quoting/attribute order); falls back to a case-insensitive regex.
	 */
	public static function gate_enqueued_script( $tag, $handle, $src ) {
		$cat = self::blocked_category( $src );
		if ( false === $cat ) {
			return $tag;
		}
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$p = new WP_HTML_Tag_Processor( $tag );
			if ( $p->next_tag( 'script' ) ) {
				$p->set_attribute( 'type', 'text/plain' );
				$p->set_attribute( 'data-acconsent-blocked', $cat );
				$p->set_attribute( 'data-acconsent-src', $src );
				$p->remove_attribute( 'src' );
				return $p->get_updated_html();
			}
		}
		// Fallback: case-insensitive regex.
		$tag = preg_replace( '/<script\b/i', '<script type="text/plain" data-acconsent-blocked="' . esc_attr( $cat ) . '" data-acconsent-src="' . esc_url( $src ) . '"', $tag, 1 );
		$tag = preg_replace( '/\ssrc=([\'"]).*?\1/i', '', $tag, 1 );
		return $tag;
	}

	public static function start_buffer() {
		if ( is_admin() ) {
			return;
		}
		// HTML responses only — never rewrite feeds, REST/JSON, sitemaps, etc.
		if ( is_feed() || is_robots() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter_buffer' ) );
	}

	/**
	 * Output-buffer pass: neutralize INLINE and src tracker <script>/<img>/
	 * <iframe> tags (and strip <link rel=preconnect|dns-prefetch|preload|
	 * prefetch|icon|...> resource hints/icons) whose URL matches the blocklist
	 * and that weren't already gated. Each neutralized tracker carries its
	 * release CATEGORY so a narrow grant can't release a broader-category
	 * tracker.
	 *
	 * C1: this is also where the network shim (network_shim_html()) gets
	 * spliced in as the ABSOLUTE FIRST thing inside <head> — unconditionally,
	 * regardless of whether/where the theme calls wp_head(). This closes the
	 * gap where a raw tracker <script> hardcoded into header.php BEFORE the
	 * theme's wp_head() call would otherwise execute completely ungated.
	 *
	 * H6: the size-cap skip (below) now applies ONLY to the <body> portion of
	 * the page. The <head> — where the overwhelming majority of tracker
	 * <script src>/<link> tags actually live, and where the shim above MUST
	 * land regardless of page size — is always fully processed.
	 */
	public static function filter_buffer( $html ) {
		if ( '' === $html ) {
			return $html;
		}
		// Guard: only process HTML documents (some buffered responses aren't).
		$ctype = '';
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) ) {
				$ctype = strtolower( $h );
			}
		}
		if ( '' !== $ctype && false === strpos( $ctype, 'text/html' ) ) {
			return $html;
		}

		// Advanced Consent Mode: pull any <div data-acconsent="cm">…</div>
		// regions OUT of the buffer before the auto-block passes run, replacing
		// each with an opaque placeholder, then splice them back verbatim at the
		// very end. A CM-flagged Google tag is emitted LIVE by design, but its
		// INNER <script> body legitimately contains a blocklisted host string
		// (googletagmanager.com), so filter_buffer()'s inline-body auto-block
		// pass would otherwise rewrite that inner script to type="text/plain"
		// and the tag would never load — defeating the whole point of Consent
		// Mode. The wrapping div's own data-acconsent attribute isn't enough
		// because the passes match on the INNER tag, not the wrapper. Extracting
		// the region entirely is the robust fix (same "exclude your own
		// instrumentation output from your own scanner" principle as the
		// acconsent-* id exclusion). Restored unmodified after all passes.
		$cm_regions      = array();
		$cm_placeholder  = '';
		$html = preg_replace_callback(
			'#<div\s+data-acconsent="cm"[^>]*>.*?</div>#is',
			function ( $m ) use ( &$cm_regions ) {
				$key             = '<!--ACCONSENT_CM_' . count( $cm_regions ) . '-->';
				$cm_regions[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( null === $html ) {
			// A PCRE failure returns null and would blank the page — bail to the
			// original buffer (unfiltered) rather than serve nothing. Rebuild it
			// from the placeholders we already swapped is impossible here, so we
			// must not have mutated $html destructively; guard by re-fetching is
			// not available, so treat null defensively: this branch is only hit
			// on catastrophic PCRE limits, in which case returning early is safe.
			$html = '';
		}
		// Closure to restore the extracted CM regions into a finished buffer.
		$restore_cm = function ( $out ) use ( &$cm_regions ) {
			if ( empty( $cm_regions ) ) {
				return $out;
			}
			return strtr( $out, $cm_regions );
		};

		// C1: splice the network shim as the first thing inside <head>,
		// unconditionally — before any blocklist entries are even checked,
		// since the shim itself is what enforces the blocklist at runtime and
		// must exist before ANY other script (theme-hardcoded or otherwise).
		$shim = self::network_shim_html();
		if ( '' !== $shim ) {
			$spliced = preg_replace( '/(<head\b[^>]*>)/i', '$1' . $shim, $html, 1 );
			if ( null !== $spliced && $spliced !== $html ) {
				$html = $spliced;
			} elseif ( false === stripos( $html, '<head' ) ) {
				// No <head> tag found at all (rare/broken templates) — prepend
				// to the very start of the document as a fallback so the shim
				// still runs before literally everything else in the buffer.
				$html = $shim . $html;
			}
		}

		$entries = self::blocklist();
		if ( empty( $entries ) ) {
			return $restore_cm( $html );
		}

		// H6: split head vs body so the head — where nearly all tracker tags
		// live, and which is never pathologically large — is ALWAYS fully
		// rewritten, independent of total page size. Only the body portion is
		// subject to the size-cap skip below (the JS net-shim, already
		// spliced into head above, still protects runtime-injected body
		// trackers on an oversized page regardless).
		$head = '';
		$body = $html;
		if ( preg_match( '#^(.*?</head\s*>)(.*)$#is', $html, $m ) ) {
			$head = $m[1];
			$body = $m[2];
		}

		// Build one alternation, but remember each host's category for the callback.
		$host_cat = array();
		$quoted   = array();
		foreach ( $entries as $e ) {
			$host_cat[ $e['host'] ] = $e['category'];
			$quoted[]               = preg_quote( $e['host'], '#' );
		}
		$pattern = implode( '|', $quoted );

		// Resolve the release category for a matched URL (longest/first host wins).
		$cat_for = function ( $url ) use ( $host_cat ) {
			$url = strtolower( $url );
			foreach ( $host_cat as $host => $cat ) {
				if ( false !== strpos( $url, $host ) ) {
					return $cat;
				}
			}
			return 'marketing';
		};

		// Run a preg_replace_callback but NEVER let a PCRE failure (backtrack
		// limit, etc.) blank the page: on NULL, keep the prior HTML unchanged.
		$safe_replace = function ( $re, $cb, $subject ) {
			$out = preg_replace_callback( $re, $cb, $subject );
			return ( null === $out ) ? $subject : $out;
		};

		$apply_passes = function ( $chunk ) use ( $pattern, $cat_for, $safe_replace ) {
			// 1) <script ...src="tracker">...</script> (skip already-gated/our own).
			$chunk = $safe_replace(
				'#<script\b(?![^>]*\bdata-acconsent\b)(?![^>]*type=["\']text/plain["\'])(?![^>]*\bid=["\']acconsent-)[^>]*\bsrc=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*>(.*?)</script>#is',
				function ( $m ) use ( $cat_for ) {
					$src = $m[1];
					$cat = $cat_for( $src );
					return '<script type="text/plain" data-acconsent-blocked="' . esc_attr( $cat ) . '" data-acconsent-src="' . esc_url( $src ) . '"></script>';
				},
				$chunk
			);

			// 2) Inline <script> bodies that reference a tracker host (gtag/fbq loaders).
			// H-self-gate: exclude any of OUR OWN script tags (id="acconsent-*", e.g.
			// the localized `acconsent-js-extra` config block) — its JSON payload
			// legitimately CONTAINS the blocklisted hostnames as plain data
			// (`sale_share_hosts`, `spi_hosts`, etc. list them so the browser-side
			// net-shim knows what to gate), which is NOT the same as loading a
			// tracker. Without this exclusion the plugin self-gates its own
			// consent config, `window.ACCONSENT` never initializes, and consent.js
			// bails out entirely (`if (typeof window.ACCONSENT === 'undefined')
			// return;`) — killing the banner, FAB, and modal. Found in the wild on
			// ascentialmls.com.
			$chunk = $safe_replace(
				'#<script\b(?![^>]*\bsrc=)(?![^>]*\bdata-acconsent\b)(?![^>]*type=["\']text/plain["\'])(?![^>]*\bid=["\']acconsent-)([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $pattern, $cat_for ) {
					if ( preg_match( '#(' . $pattern . ')#i', $m[2], $mm ) ) {
						$cat = $cat_for( $mm[1] );
						return '<script type="text/plain" data-acconsent-blocked="' . esc_attr( $cat ) . '"' . $m[1] . '>' . $m[2] . '</script>';
					}
					return $m[0];
				},
				$chunk
			);

			// 3) Tracking <img>/<iframe> pixels.
			$chunk = $safe_replace(
				'#<(img|iframe)\b(?![^>]*\bdata-acconsent\b)[^>]*\bsrc=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*>#is',
				function ( $m ) use ( $cat_for ) {
					$cat = $cat_for( $m[2] );
					$tag = preg_replace( '/\bsrc=/i', 'data-acconsent-blocked="' . esc_attr( $cat ) . '" data-acconsent-src=', $m[0], 1 );
					return ( null === $tag ) ? $m[0] : $tag;
				},
				$chunk
			);

			// 4) Resource hints (<link rel=preconnect|dns-prefetch|preload|
			// prefetch>) to a tracker host: a preconnect/dns-prefetch performs
			// a DNS+TLS handshake to the third party BEFORE consent, leaking
			// the visitor IP. L1: also covers favicon/apple-touch-icon links —
			// a request for one of those to a blocklisted host is just as much
			// a pre-consent network leak as a resource hint. Strip them
			// entirely (they're an optimization/cosmetic, not required to load).
			$rel_alt = 'preconnect|dns-prefetch|preload|prefetch|icon|shortcut icon|apple-touch-icon|apple-touch-icon-precomposed|mask-icon';
			$chunk   = $safe_replace(
				'#<link\b[^>]*\brel=["\'](?:' . $rel_alt . ')["\'][^>]*\bhref=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*/?>#is',
				function () {
					return ''; // drop the hint/icon link.
				},
				$chunk
			);
			// Same, with rel/href in the opposite order.
			$chunk = $safe_replace(
				'#<link\b[^>]*\bhref=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*\brel=["\'](?:' . $rel_alt . ')["\'][^>]*/?>#is',
				function () {
					return '';
				},
				$chunk
			);

			// M3: <meta http-equiv="refresh" content="0;url=tracker..."> is a
			// redirect vector — strip it outright (both attribute orderings)
			// so a consent-gated redirect to a blocklisted host never fires.
			$chunk = $safe_replace(
				'#<meta\b[^>]*\bhttp-equiv=["\']refresh["\'][^>]*\bcontent=["\'][^"\']*(?:' . $pattern . ')[^"\']*["\'][^>]*/?>#is',
				function () {
					return '';
				},
				$chunk
			);
			$chunk = $safe_replace(
				'#<meta\b[^>]*\bcontent=["\'][^"\']*(?:' . $pattern . ')[^"\']*["\'][^>]*\bhttp-equiv=["\']refresh["\'][^>]*/?>#is',
				function () {
					return '';
				},
				$chunk
			);

			return $chunk;
		};

		if ( '' !== $head ) {
			// H6: head is ALWAYS fully processed, independent of total-page size.
			$head = $apply_passes( $head );
			// Size cap on body only: on a very large body, running these regex
			// passes is expensive AND raises the odds of hitting
			// pcre.backtrack_limit (which makes preg_* return NULL). The JS
			// net-shim (already spliced into head above) still neutralizes
			// these same vectors at runtime, so skip the server rewrite for an
			// oversized body rather than risk CPU spikes or a blanked page.
			if ( strlen( $body ) <= 2097152 ) { // ~2 MB
				$body = $apply_passes( $body );
			}
			return $restore_cm( $head . $body );
		}

		// Fallback: no <head> tag found — run the existing whole-document size
		// cap logic (today's pre-H6 behavior) over the entire buffer.
		if ( strlen( $html ) > 2097152 ) { // ~2 MB
			return $restore_cm( $html );
		}
		return $restore_cm( $apply_passes( $html ) );
	}

	/**
	 * Banner shell + a persistent floating preferences trigger (the always-
	 * available withdrawal path required by GDPR Art. 7(3)).
	 *
	 * The CCPA/CPRA "Do Not Sell or Share" opt-out is intentionally NOT
	 * rendered here as a persistent floating element — it only appears
	 * inside the initial consent popup and the revisit/preferences modal
	 * (see legalLinksHtml() in consent.js), which is where a visitor is
	 * actually reviewing/making privacy choices.
	 */
	public static function render_banner() {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<div id="acconsent-root" hidden></div>';
		$s = Amplifi_Consent_Store::get_settings();
		if ( ! empty( $s['floating_button'] ) ) {
			$fab_pos   = ( isset( $s['fab_position'] ) && 'right' === $s['fab_position'] ) ? 'right' : 'left';
			$fab_class = 'acconsent-fab acconsent-fab-' . $fab_pos;
			printf(
				'<button type="button" class="%s" data-acconsent-open aria-label="%s" title="%s">%s</button>',
				esc_attr( $fab_class ),
				esc_attr( $s['prefs_label'] ),
				esc_attr( $s['prefs_label'] ),
				/* inline cookie icon (SVG, no external asset, currentColor-inheriting) */ self::cookie_icon_svg()
			);
		}
	}

	/**
	 * Which opt-out controls has a shortcode ALREADY printed on this request?
	 *
	 * Tracked PER CONTROL, not as one shared boolean. The two controls are
	 * independent rights (§7013 sale/share opt-out vs. §7014 limit-SPI), and
	 * an editor may reasonably place only one shortcode — e.g. just
	 * [amplifi-do-not-sell] in a footer widget. With a single shared flag
	 * that partial placement suppressed the WHOLE auto-rendered row, so the
	 * "Limit the Use of My Sensitive Personal Information" control rendered
	 * nowhere on the site: a control §7014(c) requires in the header or
	 * footer would silently vanish because of an unrelated shortcode.
	 *
	 * wp_footer fires after the template body, so by the time
	 * render_footer_optout() runs, any shortcode in the page content has
	 * already recorded what it printed, and the auto row emits only the
	 * remainder.
	 */
	private static $optout_rendered_dns = false;
	private static $optout_rendered_spi = false;

	private static function optout_placement() {
		$s = Amplifi_Consent_Store::get_settings();
		$p = isset( $s['optout_placement'] ) ? $s['optout_placement'] : 'footer';
		return in_array( $p, array( 'footer', 'banner', 'both' ), true ) ? $p : 'footer';
	}

	/**
	 * The two CCPA/CPRA opt-out controls as markup, or '' when both are off.
	 *
	 * Rendered as <button> (not <a href>) deliberately: clicking must
	 * immediately effectuate the right in-page — CCR tit.11 §7013(a) and
	 * §7014(a) both contemplate a link that "will either have the immediate
	 * effect of" applying the opt-out "or lead the consumer to a webpage
	 * where the consumer can learn about and make that choice". This build
	 * takes the immediate-effect path, so there is no destination URL to
	 * link to.
	 *
	 * CAVEAT worth knowing before relying on this: Civil Code §1798.135(a)
	 * and §7026(a) both say "link", and a <button> is not one — it has no
	 * href, does not appear in a screen reader's link rotor, cannot be
	 * bookmarked or deep-linked, and is INERT if JavaScript is disabled or
	 * blocked (the handler is a delegated listener in consent.js). §7004(a)(5)
	 * requires opt-out methods be "tested to ensure that they are functional";
	 * a visible control that silently does nothing is the case it is aimed at.
	 * A future revision should render a real <a href> to a dedicated opt-out
	 * page and progressively enhance it into an immediate-effect click.
	 *
	 * The buttons carry link-ish styling in the footer context so they sit
	 * alongside a theme's other footer links. §7003(c)'s operative test is
	 * that a conspicuous link "shall appear in a similar manner as other
	 * similarly-posted links"; the font-size/color sentence that follows is
	 * prefaced "For example" and is an illustrative FLOOR, not the whole
	 * standard. Inheriting type from the surrounding footer clears that floor
	 * — it does not by itself establish conspicuousness.
	 */
	private static function optout_controls_html( $context = 'footer', $skip_rendered = false ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$s   = Amplifi_Consent_Store::get_settings();
		$dns = ! empty( $s['do_not_sell'] ) && ! empty( $s['dns_label'] );
		$spi = ! empty( $s['limit_spi_enabled'] ) && ! empty( $s['limit_spi_label'] );
		// The auto-rendered row passes $skip_rendered so it emits only the
		// controls a shortcode has NOT already printed. A shortcode placing
		// one control must not delete the other from the whole site.
		if ( $skip_rendered ) {
			$dns = $dns && ! self::$optout_rendered_dns;
			$spi = $spi && ! self::$optout_rendered_spi;
		}
		if ( ! $dns && ! $spi ) {
			return '';
		}
		$cls  = 'footer' === $context ? ' acconsent-optout-btn-footer' : '';
		$html = '';
		if ( $dns ) {
			$html .= sprintf(
				'<button type="button" class="acconsent-optout-btn%s" data-acconsent-donotsell>%s</button>',
				esc_attr( $cls ),
				esc_html( $s['dns_label'] )
			);
		}
		if ( $spi ) {
			$html .= sprintf(
				'<button type="button" class="acconsent-optout-btn%s" data-acconsent-limitspi>%s</button>',
				esc_attr( $cls ),
				esc_html( $s['limit_spi_label'] )
			);
		}
		return $html;
	}

	/**
	 * Auto-rendered footer opt-out row (default placement).
	 *
	 * This is a FALLBACK for themes with no obvious footer link row. If the
	 * theme has one, prefer dropping [amplifi-do-not-sell] /
	 * [amplifi-limit-spi] straight into it so the controls inherit the
	 * neighbouring links' font size and color — that is literally the
	 * §7003(c) test. A shortcode's placement is recorded per control, so this
	 * row emits only the controls no shortcode has already printed (and
	 * nothing at all when a shortcode placed both).
	 */
	public static function render_footer_optout() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( 'banner' === self::optout_placement() ) {
			return;
		}
		// Emit only what a shortcode has not already placed on this page.
		$controls = self::optout_controls_html( 'footer', true );
		if ( '' === $controls ) {
			return;
		}
		self::$optout_rendered_dns = true;
		self::$optout_rendered_spi = true;
		// NOTE the accessible name: deliberately NOT "Your Privacy Choices".
		// That exact phrase (and "Your California Privacy Choices") is the
		// RESERVED title of the §7015 Alternative Opt-out Link, which must be a
		// SINGLE link carrying the CPPA's opt-out icon and leading to a page
		// offering both rights. Naming this group that would advertise a §7015
		// link we do not implement.
		printf(
			'<div class="acconsent-footer-optout acconsent-footer-optout-auto" role="group" aria-label="%s">%s</div>',
			esc_attr__( 'Privacy opt-out controls', 'amplifi-consent' ),
			$controls // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd labels in optout_controls_html().
		);
	}

	/**
	 * [amplifi-do-not-sell] — the CCPA/CPRA "Do Not Sell or Share My
	 * Personal Information" control, placeable in a footer widget, nav menu,
	 * or a dedicated page. Placing it suppresses the auto-rendered footer
	 * row so the control never appears twice.
	 */
	public static function do_not_sell_shortcode( $atts ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$s = Amplifi_Consent_Store::get_settings();
		if ( empty( $s['do_not_sell'] ) || empty( $s['dns_label'] ) ) {
			return '';
		}
		self::$optout_rendered_dns = true;
		return sprintf(
			'<button type="button" class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-donotsell>%s</button>',
			esc_html( $s['dns_label'] )
		);
	}

	/**
	 * [amplifi-limit-spi] — the CCPA §1798.121 "Limit the Use of My
	 * Sensitive Personal Information" control. Same placement rules as
	 * [amplifi-do-not-sell]; per CCR §7014(c) it belongs in the header or
	 * footer, not buried in a banner.
	 */
	public static function limit_spi_shortcode( $atts ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$s = Amplifi_Consent_Store::get_settings();
		if ( empty( $s['limit_spi_enabled'] ) || empty( $s['limit_spi_label'] ) ) {
			return '';
		}
		self::$optout_rendered_spi = true;
		return sprintf(
			'<button type="button" class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-limitspi>%s</button>',
			esc_html( $s['limit_spi_label'] )
		);
	}

	/**
	 * [amplifi-optout-links] — both controls in one wrapper, for dropping a
	 * complete opt-out group into a footer.
	 *
	 * NOT the §7015 "Alternative Opt-out Link", and deliberately NOT named or
	 * labeled after it. §7015(b) reserves the titles "Your Privacy Choices"
	 * and "Your California Privacy Choices" for a SINGLE combined link that
	 * "shall include" the CPPA's opt-out icon and lead to a §7015(c) webpage
	 * offering both rights. This shortcode renders the two separate
	 * §7013/§7014 controls side by side — the other permitted arrangement,
	 * and the statutory default per §7013(d)/§7014(d) ("in lieu of").
	 */
	public static function optout_links_shortcode( $atts ) {
		$controls = self::optout_controls_html( 'footer' );
		if ( '' === $controls ) {
			return '';
		}
		self::$optout_rendered_dns = true;
		self::$optout_rendered_spi = true;
		return sprintf(
			'<span class="acconsent-footer-optout" role="group" aria-label="%s">%s</span>',
			esc_attr__( 'Privacy opt-out controls', 'amplifi-consent' ),
			$controls
		);
	}

	/**
	 * Inline cookie icon (SVG). Static trusted markup — no external asset,
	 * inherits button color via currentColor, sized to the FAB.
	 */
	public static function cookie_icon_svg() {
		return '<svg class="acconsent-cookie-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
			. '<path d="M21.5 12a9.5 9.5 0 1 1-6.7-9.08 3.2 3.2 0 0 0 3.02 4.14 3.2 3.2 0 0 0 3.4 3.05c.18.6.28 1.24.28 1.89Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
			. '<circle cx="8.5" cy="10" r="1.15" fill="currentColor"/>'
			. '<circle cx="12.5" cy="14.5" r="1.15" fill="currentColor"/>'
			. '<circle cx="15.5" cy="9.5" r="1.05" fill="currentColor"/>'
			. '<circle cx="9" cy="15.5" r="0.9" fill="currentColor"/>'
			. '</svg>';
	}

	/**
	 * [amplifi-consent-manager] — a button that re-opens the preferences modal.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'label' => __( 'Manage cookie preferences', 'amplifi-consent' ),
		), $atts, 'amplifi-consent-manager' );

		if ( ! self::enabled() ) {
			return '';
		}
		return sprintf(
			'<button type="button" class="acconsent-manage-trigger" data-acconsent-open>%s</button>',
			esc_html( $atts['label'] )
		);
	}

	/**
	 * [amplifi-legal-doc slug="privacy-policy"] — render the current version of a
	 * versioned legal document, with a visible version + effective-date line.
	 * This is how the canonical policy text gets placed on the Privacy/Terms
	 * pages so the consent log can reference the exact version shown.
	 */
	public static function legal_doc_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'slug'         => '',
			'id'           => '',
			'show_version' => 'true',
			// Suppress the shortcode's own <h2> title when the page's own
			// template already renders an <h1> for the page title (the
			// common case) — avoids a visible duplicate heading.
			'show_title'   => 'true',
		), $atts, 'amplifi-legal-doc' );

		$doc = '';
		if ( $atts['id'] ) {
			$doc = Amplifi_Consent_Store::get_legal_doc( $atts['id'] );
		} elseif ( $atts['slug'] ) {
			$doc = Amplifi_Consent_Store::get_legal_doc_by_slug( $atts['slug'] );
		}
		if ( ! $doc ) {
			return '';
		}
		$cur = Amplifi_Consent_Store::current_version( $doc );
		if ( ! $cur ) {
			return '';
		}

		$out = '<div class="acconsent-legal-doc" data-doc="' . esc_attr( $doc['slug'] ) . '">';
		if ( 'false' !== $atts['show_title'] ) {
			$out .= '<h2 class="acconsent-legal-title">' . esc_html( $doc['title'] ) . '</h2>';
		}
		if ( 'false' !== $atts['show_version'] ) {
			$date = isset( $cur['published_at'] ) ? mysql2date( get_option( 'date_format' ), $cur['published_at'] ) : '';
			$out .= '<p class="acconsent-legal-meta">' . esc_html(
				sprintf(
					/* translators: 1: version label, 2: date */
					__( 'Version %1$s — effective %2$s', 'amplifi-consent' ),
					$cur['version'],
					$date
				)
			) . '</p>';
		}
		$out .= '<div class="acconsent-legal-body">' . wp_kses_post( $cur['content'] ) . '</div>';
		$out .= '</div>';
		return $out;
	}
}
