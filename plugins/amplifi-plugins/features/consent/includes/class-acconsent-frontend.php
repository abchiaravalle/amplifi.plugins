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

		// The network shim must run before ANY other script can call out, so it
		// prints at the very top of <head> (priority -1, ahead of consent-mode).
		if ( self::autoblock_on() ) {
			add_action( 'wp_head', array( __CLASS__, 'network_shim' ), -1 );
		}

		// Consent Mode v2 defaults must print as early as possible in <head>.
		add_action( 'wp_head', array( __CLASS__, 'consent_mode_defaults' ), 0 );

		add_action( 'wp_head', array( __CLASS__, 'emit_head_scripts' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'emit_body_open_scripts' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'emit_footer_scripts' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 50 );

		add_shortcode( 'amplifi-consent-manager', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'amplifi-legal-doc', array( __CLASS__, 'legal_doc_shortcode' ) );

		// Auto-block UNMANAGED trackers (opt-in via settings).
		if ( self::autoblock_on() ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'gate_enqueued_script' ), 10, 3 );
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
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
		wp_register_style( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/css/consent.css', array(), ACCONSENT_VERSION );
		wp_register_script( 'acconsent', ACCONSENT_PLUGIN_URL . 'assets/js/consent.js', array(), ACCONSENT_VERSION, true );

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

		wp_localize_script( 'acconsent', 'ACCONSENT', array(
			'settings'       => array(
				'banner_title'   => $settings['banner_title'],
				'banner_message' => $settings['banner_message'],
				'accept_label'   => $settings['accept_label'],
				'reject_label'   => $settings['reject_label'],
				'manage_label'   => $settings['manage_label'],
				'save_label'     => $settings['save_label'],
				'prefs_label'    => $settings['prefs_label'],
				'toast_accepted' => $settings['toast_accepted'],
				'toast_rejected' => $settings['toast_rejected'],
				'consent_days'   => (int) $settings['consent_days'],
				'accent_color'   => $settings['accent_color'],
				'position'       => $settings['position'],
				'privacy_url'    => $settings['privacy_url'],
				'floating'       => (bool) $settings['floating_button'],
				'gpc_enabled'    => (bool) $settings['gpc_enabled'],
				'consent_mode'   => (bool) $settings['consent_mode'],
				'do_not_sell'    => (bool) $settings['do_not_sell'],
				'dns_label'      => $settings['dns_label'],
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
			'categories'     => $categories,
			'cookies'        => $cookies_by_cat,
			'legal'          => Amplifi_Consent_Store::legal_snapshot(),
			'policy_version' => Amplifi_Consent_Store::policy_version(),
			'catalog_hash'   => Amplifi_Consent_Store::catalog_hash(),
			'rest_url'       => esc_url_raw( rest_url( Amplifi_Consent_Rest::NS . '/consent' ) ),
			'config_url'     => esc_url_raw( rest_url( Amplifi_Consent_Rest::NS . '/config' ) ),
			// Signed consent token issued at render (proves a real page render,
			// bound to the visitor cookie when present). Travels in the POST body
			// so a sendBeacon unload-fallback works. When no cookie exists yet
			// (first-time visitor on a cached page) this token is unbound; the JS
			// fetches a bound one from /config (which also sets the cookie).
			'token'          => Amplifi_Consent_Rest::issue_token( $render_vid ),
			'has_vid'        => ( '' !== $render_vid ),
			'storage_key'    => 'acconsent_v1',
		) );

		wp_enqueue_style( 'acconsent' );
		wp_enqueue_script( 'acconsent' );

		$accent = $settings['accent_color'];
		wp_add_inline_style( 'acconsent', ":root{--acconsent-accent:{$accent};}" );
	}

	/**
	 * Client-side network shim. Tag-rewriting can't catch a tracker that a
	 * first-party bundle fires via fetch()/XHR/sendBeacon/new Image(). This
	 * inline script (printed FIRST in <head>) monkey-patches those APIs so any
	 * request to a blocklisted host is dropped until consent is granted, then
	 * replayed/allowed. window.__acconsentReleaseNetwork(grantedCats) is called
	 * by the consent engine on accept to lift the block for granted categories.
	 */
	public static function network_shim() {
		if ( ! self::enabled() ) {
			return;
		}
		$entries = self::blocklist();
		if ( empty( $entries ) ) {
			return;
		}
		// host => category map for the client.
		$map = array();
		foreach ( $entries as $e ) {
			$map[ $e['host'] ] = $e['category'];
		}
		$json = wp_json_encode( $map );
		?>
<script data-acconsent="net-shim">
(function(){
  var MAP = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
  var granted = { necessary: true };
  function catFor(url){
    try { url = String(url).toLowerCase(); } catch(e){ return null; }
    for (var host in MAP){ if (url.indexOf(host) !== -1) return MAP[host]; }
    return null;
  }
  function blocked(url){
    var c = catFor(url);
    return c && !granted[c];
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
  }
  patchRealm(window);
  // NOTE: dynamic import('https://tracker/…') goes through the module pipeline
  // and cannot be monkey-patched from script; a server CSP script-src/connect-src
  // is the only reliable backstop for that vector. Documented in the readme.
  //
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
  if (window.HTMLScriptElement) guardProp(HTMLScriptElement.prototype, 'src');
  if (window.HTMLImageElement) { guardProp(HTMLImageElement.prototype, 'src'); guardProp(HTMLImageElement.prototype, 'srcset'); }
  if (window.HTMLIFrameElement) guardProp(HTMLIFrameElement.prototype, 'src');
  if (window.HTMLLinkElement) guardProp(HTMLLinkElement.prototype, 'href');
  if (window.HTMLMediaElement) guardProp(HTMLMediaElement.prototype, 'src'); // audio/video
  if (window.HTMLVideoElement) guardProp(HTMLVideoElement.prototype, 'poster');
  if (window.HTMLEmbedElement) guardProp(HTMLEmbedElement.prototype, 'src');
  if (window.HTMLObjectElement) guardProp(HTMLObjectElement.prototype, 'data');
  if (window.HTMLSourceElement) { guardProp(HTMLSourceElement.prototype, 'src'); guardProp(HTMLSourceElement.prototype, 'srcset'); }
  if (window.HTMLTrackElement) guardProp(HTMLTrackElement.prototype, 'src');
  // setAttribute / setAttributeNS('src'|'href'|'srcset'|'imagesrcset'|'data'|'poster',
  // tracker) bypass the property setters — guard both. (setAttributeNS is a
  // SEPARATE method, not covered by the setAttribute patch.)
  var ATTRS = { 'src':1, 'href':1, 'srcset':1, 'imagesrcset':1, 'data':1, 'poster':1 };
  var TAGS = { 'script':1, 'img':1, 'iframe':1, 'link':1, 'video':1, 'audio':1, 'embed':1, 'object':1, 'track':1, 'source':1 };
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
  if (window.Element){
    Element.prototype.setAttribute = guardSetAttr(Element.prototype.setAttribute);
    if (Element.prototype.setAttributeNS) Element.prototype.setAttributeNS = guardSetAttr(Element.prototype.setAttributeNS);
  }
  // document.write / writeln inject raw markup the parser sets attributes on
  // directly (the classic document.write('<script src=fb>') GTM/pixel pattern),
  // bypassing every setter above. Pre-scan the string, neutralize blocked
  // resources in a detached fragment, and write the sanitized markup instead.
  function sanitizeMarkup(str){
    try {
      if (str.indexOf('<') === -1) return str;
      var tpl = document.createElement('template');
      tpl.innerHTML = str;
      var changed = false;
      var nodes = tpl.content.querySelectorAll('script[src],img[src],img[srcset],iframe[src],link[href],video[src],video[poster],audio[src],embed[src],object[data],track[src],source[src],source[srcset]');
      Array.prototype.forEach.call(nodes, function(n){
        ['src','href','srcset','data','poster'].forEach(function(a){
          var v = n.getAttribute(a);
          if (v && blocked(v)) { neutralize(n, a, v); n.removeAttribute(a); changed = true; }
        });
      });
      return changed ? tpl.innerHTML : str;
    } catch(e){ return str; }
  }
  if (document.write){
    var ow = document.write;
    document.write = function(s){ return ow.call(document, sanitizeMarkup(String(s == null ? '' : s))); };
  }
  if (document.writeln){
    var owl = document.writeln;
    document.writeln = function(s){ return owl.call(document, sanitizeMarkup(String(s == null ? '' : s))); };
  }
  // Range.createContextualFragment builds nodes whose scripts DO execute on
  // insertion (unlike innerHTML), and the parser sets src so guardProp never
  // fires — sanitize the markup string the same way as document.write.
  if (window.Range && Range.prototype.createContextualFragment){
    var ocf = Range.prototype.createContextualFragment;
    Range.prototype.createContextualFragment = function(str){
      return ocf.call(this, sanitizeMarkup(String(str == null ? '' : str)));
    };
  }
  // MutationObserver backstop for innerHTML/insertAdjacentHTML-injected pixels
  // (<img>/<iframe>/<link>) — those are parser-constructed, so no setter fires.
  // Best-effort: neutralizes on insertion so the resource is deferred/releasable
  // and repeat loads are blocked. (Scripts injected via innerHTML do not execute
  // per spec, so they need no handling here.)
  function neutralizeIfBlocked(node){
    if (!node || node.nodeType !== 1 || !node.getAttribute) return;
    if (node.getAttribute('data-acconsent-blocked')) return; // already handled.
    ['src','href','srcset','data','poster'].forEach(function(a){
      var v = node.getAttribute(a);
      if (v && blocked(v)){
        neutralize(node, a, v);
        try { node.removeAttribute(a); } catch(e){}
      }
    });
  }
  // Patch a same-origin child iframe's pristine realm so its un-patched
  // fetch/XHR/sendBeacon/WebSocket can't be used to bypass the gate. Re-runs on
  // load too, since contentWindow is replaced when the frame navigates.
  function patchFrame(frame){
    try {
      if (frame.__acFramePatched) return; // element-level guard: no listener leak.
      frame.__acFramePatched = true;
      patchRealm(frame.contentWindow);
      frame.addEventListener('load', function(){ try { patchRealm(frame.contentWindow); } catch(e){} });
    } catch(e){} // cross-origin: inaccessible (and can't host our trackers anyway).
  }
  function scrub(node){
    if (!node || node.nodeType !== 1) return;
    var t = node.tagName ? node.tagName.toLowerCase() : '';
    if (TAGS[t]) neutralizeIfBlocked(node);
    if (t === 'iframe') patchFrame(node);
    // querySelectorAll already returns ALL matching descendants (flat) — no need
    // to recurse per-match (that would re-scan subtrees super-linearly).
    if (node.querySelectorAll){
      var kids = node.querySelectorAll('img[src],img[srcset],iframe[src],link[href],video[src],video[poster],audio[src],embed[src],object[data],track[src],source[src],source[srcset]');
      Array.prototype.forEach.call(kids, neutralizeIfBlocked);
      var frames = node.querySelectorAll('iframe');
      Array.prototype.forEach.call(frames, patchFrame);
    }
  }
  if (window.MutationObserver){
    try {
      var mo = new MutationObserver(function(muts){
        for (var i=0;i<muts.length;i++){
          var added = muts[i].addedNodes;
          for (var j=0;j<added.length;j++){ scrub(added[j]); }
        }
      });
      mo.observe(document.documentElement || document, { childList: true, subtree: true });
      window.__acconsentObserver = mo;
    } catch(e){}
  }
  // Patch any iframes already present at shim execution time.
  try {
    var existing = document.getElementsByTagName('iframe');
    for (var fi=0; fi<existing.length; fi++){ patchFrame(existing[fi]); }
  } catch(e){}
  // Released by the consent engine on grant.
  window.__acconsentReleaseNetwork = function(grantedCats){
    if (grantedCats && typeof grantedCats === 'object'){
      for (var k in grantedCats){ granted[k] = !!grantedCats[k]; }
    }
  };
})();
</script>
		<?php
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
		foreach ( Amplifi_Consent_Store::get_scripts() as $s ) {
			if ( empty( $s['enabled'] ) || $s['placement'] !== $placement ) {
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
	 * prefetch> resource hints) whose URL matches the blocklist and that weren't
	 * already gated. Each neutralized tracker carries its release CATEGORY so a
	 * narrow grant can't release a broader-category tracker.
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

		$entries = self::blocklist();
		if ( empty( $entries ) ) {
			return $html;
		}

		// Size cap: on a very large page, running five regex passes over the whole
		// buffer is expensive AND raises the odds of hitting pcre.backtrack_limit
		// (which makes preg_* return NULL). The JS net-shim already neutralizes
		// these same vectors at runtime, so skip the server rewrite for oversized
		// documents rather than risk CPU spikes or a blanked page.
		if ( strlen( $html ) > 2097152 ) { // ~2 MB
			return $html;
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

		// 1) <script ...src="tracker">...</script> (skip already-gated/our own).
		$html = $safe_replace(
			'#<script\b(?![^>]*\bdata-acconsent\b)(?![^>]*type=["\']text/plain["\'])[^>]*\bsrc=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*>(.*?)</script>#is',
			function ( $m ) use ( $cat_for ) {
				$src = $m[1];
				$cat = $cat_for( $src );
				return '<script type="text/plain" data-acconsent-blocked="' . esc_attr( $cat ) . '" data-acconsent-src="' . esc_url( $src ) . '"></script>';
			},
			$html
		);

		// 2) Inline <script> bodies that reference a tracker host (gtag/fbq loaders).
		$html = $safe_replace(
			'#<script\b(?![^>]*\bsrc=)(?![^>]*\bdata-acconsent\b)(?![^>]*type=["\']text/plain["\'])([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $pattern, $cat_for ) {
				if ( preg_match( '#(' . $pattern . ')#i', $m[2], $mm ) ) {
					$cat = $cat_for( $mm[1] );
					return '<script type="text/plain" data-acconsent-blocked="' . esc_attr( $cat ) . '"' . $m[1] . '>' . $m[2] . '</script>';
				}
				return $m[0];
			},
			$html
		);

		// 3) Tracking <img>/<iframe> pixels.
		$html = $safe_replace(
			'#<(img|iframe)\b(?![^>]*\bdata-acconsent\b)[^>]*\bsrc=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*>#is',
			function ( $m ) use ( $cat_for ) {
				$cat = $cat_for( $m[2] );
				$tag = preg_replace( '/\bsrc=/i', 'data-acconsent-blocked="' . esc_attr( $cat ) . '" data-acconsent-src=', $m[0], 1 );
				return ( null === $tag ) ? $m[0] : $tag;
			},
			$html
		);

		// 4) Resource hints (<link rel=preconnect|dns-prefetch|preload|prefetch>)
		// to a tracker host: a preconnect/dns-prefetch performs a DNS+TLS
		// handshake to the third party BEFORE consent, leaking the visitor IP.
		// Strip them entirely (they're an optimization, not required to load).
		$html = $safe_replace(
			'#<link\b[^>]*\brel=["\'](?:preconnect|dns-prefetch|preload|prefetch)["\'][^>]*\bhref=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*/?>#is',
			function () {
				return ''; // drop the hint.
			},
			$html
		);
		// Same, with rel/href in the opposite order.
		$html = $safe_replace(
			'#<link\b[^>]*\bhref=["\']([^"\']*(?:' . $pattern . ')[^"\']*)["\'][^>]*\brel=["\'](?:preconnect|dns-prefetch|preload|prefetch)["\'][^>]*/?>#is',
			function () {
				return '';
			},
			$html
		);

		return $html;
	}

	/**
	 * Banner shell + a persistent floating preferences trigger (the always-
	 * available withdrawal path required by GDPR Art. 7(3)).
	 */
	public static function render_banner() {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<div id="acconsent-root" hidden></div>';
		$s = Amplifi_Consent_Store::get_settings();
		if ( ! empty( $s['floating_button'] ) ) {
			printf(
				'<button type="button" class="acconsent-fab" data-acconsent-open aria-label="%s" title="%s">%s</button>',
				esc_attr( $s['prefs_label'] ),
				esc_attr( $s['prefs_label'] ),
				/* a small cookie glyph */ '&#127850;'
			);
		}
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

		$out  = '<div class="acconsent-legal-doc" data-doc="' . esc_attr( $doc['slug'] ) . '">';
		$out .= '<h2 class="acconsent-legal-title">' . esc_html( $doc['title'] ) . '</h2>';
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
