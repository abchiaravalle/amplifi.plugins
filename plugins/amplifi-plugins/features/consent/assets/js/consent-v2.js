/* amplifi.consent — front-end engine (v1.1)
 * Hard-withholds gated scripts until consent. Gated scripts live inside inert
 * <template> blocks; we only re-create them as live <script> nodes for granted
 * categories. Reject => never released => zero tracking.
 *
 * v1.1 additions:
 *  - Honors Global Privacy Control (navigator.globalPrivacyControl) as an opt-out.
 *  - Records every consent event to the server (proof of consent) + receipt.
 *  - Re-prompts when the policy/catalog version changes (stale consent invalid).
 *  - Releases AUTO-BLOCKED unmanaged trackers (type=text/plain + data-acconsent-*).
 *  - Release fidelity: external scripts loaded sequentially (order preserved);
 *    document.write after load is neutralized to avoid blanking the page.
 *  - Google Consent Mode v2 signal updates on grant.
 *  - Persistent floating preferences trigger + legal-doc links in the manager.
 */
(function () {
  'use strict';

  if (typeof window.ACCONSENT === 'undefined') return;
  var CFG = window.ACCONSENT;
  var S = CFG.settings;
  var KEY = CFG.storage_key || 'acconsent_v1';

  function gtagSafe() {
    if (typeof window.gtag === 'function') { window.gtag.apply(window, arguments); }
    else if (window.dataLayer) { window.dataLayer.push(arguments); }
  }

  /* ---------------- consent storage (localStorage, N-day TTL, version-bound) ---------------- */

  function now() { return Date.now(); }

  function readConsent() {
    try {
      var raw = window.localStorage.getItem(KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || !data.expires || data.expires < now()) return null;
      if (!data.categories || typeof data.categories !== 'object') return null;
      // VERSION INVALIDATION: stored consent is only valid for the policy +
      // catalog it was given against. If either changed (new tracker, new
      // policy text/version), treat it as expired and re-prompt.
      if (data.policy_version !== CFG.policy_version) return null;
      if (data.catalog_hash !== CFG.catalog_hash) return null;
      return data;
    } catch (e) { return null; }
  }

  function writeConsent(categories, saleShareOptOut) {
    var days = parseInt(S.consent_days, 10) || 180;
    var data = {
      ts: now(),
      expires: now() + days * 24 * 60 * 60 * 1000,
      categories: categories,
      // H1: CCPA/CPRA "sale/share" opt-out — independent of (in addition to)
      // the ordinary category grants. True whenever GPC is active or the
      // visitor clicked "Do Not Sell".
      sale_share_opt_out: !!saleShareOptOut,
      policy_version: CFG.policy_version,
      catalog_hash: CFG.catalog_hash
    };
    var ok = false;
    try { window.localStorage.setItem(KEY, JSON.stringify(data)); ok = true; } catch (e) {}
    return { data: data, persisted: ok };
  }

  function allCategories() { return Object.keys(CFG.categories || {}); }

  function grantedSet(consent) {
    var out = { necessary: true };
    allCategories().forEach(function (c) {
      if (c === 'necessary') { out[c] = true; return; }
      out[c] = !!(consent && consent.categories && consent.categories[c]);
    });
    return out;
  }

  function fullGrant(value) {
    var out = {};
    allCategories().forEach(function (c) {
      out[c] = c === 'necessary' ? true : !!value;
    });
    return out;
  }

  /* ---------------- Global Privacy Control ---------------- */
  // A GPC signal is a legally-valid opt-out (CPRA). If present and honoring is
  // enabled, force-deny all opt-in categories regardless of stored/clicked state.
  function gpcActive() {
    return !!(S.gpc_enabled && navigator && navigator.globalPrivacyControl === true);
  }

  // H1: whether the CURRENT stored consent carries a sale/share opt-out
  // (GPC active at the time it was written, or "Do Not Sell" was clicked).
  function saleShareOptOut(consent) {
    return !!(consent && consent.sale_share_opt_out);
  }

  /* ---------------- script release (the core withholding mechanism) ---------------- */

  // Neutralize document.write after load (a released legacy tag calling it would
  // wipe the DOM). We buffer into the nearest container instead.
  function withDocWriteGuard(fn) {
    var origWrite = document.write, origWriteln = document.writeln;
    var buffer = '';
    document.write = function (s) { buffer += s; };
    document.writeln = function (s) { buffer += s + '\n'; };
    try { fn(); } finally {
      document.write = origWrite; document.writeln = origWriteln;
    }
    if (buffer) {
      var holder = document.createElement('div');
      holder.innerHTML = buffer;
      document.body.appendChild(holder);
    }
  }

  // Recreate a single <script> node so the browser executes it. Returns a
  // promise that resolves when external scripts finish (to preserve order).
  function runScript(oldScript, parent, ref) {
    return new Promise(function (resolve) {
      var s = document.createElement('script');
      for (var i = 0; i < oldScript.attributes.length; i++) {
        var a = oldScript.attributes[i];
        if (a.name === 'type' && (a.value === 'text/plain' || a.value === '')) continue;
        if (a.name.indexOf('data-acconsent') === 0) continue;
        s.setAttribute(a.name, a.value);
      }
      // Restore a deferred src (autoblock stashes it in data-acconsent-src).
      var deferredSrc = oldScript.getAttribute('data-acconsent-src');
      if (deferredSrc) s.src = deferredSrc;
      if (!s.src) s.textContent = oldScript.textContent;
      // Force ordered execution for external scripts.
      if (s.src) {
        s.async = false;
        s.onload = s.onerror = function () { resolve(); };
        parent.insertBefore(s, ref);
      } else {
        withDocWriteGuard(function () { parent.insertBefore(s, ref); });
        resolve();
      }
    });
  }

  function releaseTemplate(tpl, saleOptOut) {
    if (tpl.getAttribute('data-acconsent-released') === '1') return;
    // H1: a sale/share-flagged managed script stays withheld while the
    // visitor has opted out, even if its (possibly narrower) category was
    // otherwise granted.
    var id = tpl.getAttribute('data-acconsent-id');
    if (saleOptOut && id && CFG.sale_share_scripts && CFG.sale_share_scripts.indexOf(id) !== -1) return;
    // H2: a Sensitive-PI-flagged managed script is withheld UNCONDITIONALLY
    // while limit_spi_enabled is on, independent of any category grant.
    if (S.limit_spi_enabled && id && CFG.spi_scripts && CFG.spi_scripts.indexOf(id) !== -1) return;
    tpl.setAttribute('data-acconsent-released', '1');
    var frag;
    // The gated body is base64-encoded (so a "</template>" inside the snippet
    // can't break out of the inert container). Decode it into a real fragment.
    if (tpl.getAttribute('data-acconsent-enc') === 'base64') {
      // BUG (fixed): a browser-parsed <template>'s markup lives in the inert
      // `.content` DocumentFragment per spec, NOT in the element's own
      // `.textContent`/`.childNodes` — `tpl.textContent` is ALWAYS the empty
      // string for a template parsed from server-rendered HTML. Must read
      // the base64 payload from `tpl.content.textContent` instead. (This bug
      // made EVERY managed script's release silently no-op, since managed
      // scripts always render with data-acconsent-enc="base64".)
      var raw = tpl.content ? tpl.content.textContent : tpl.textContent;
      var decoded = '';
      try { decoded = decodeURIComponent(escape(window.atob(raw.trim()))); }
      catch (e) { try { decoded = window.atob(raw.trim()); } catch (e2) { decoded = ''; } }
      var holder = document.createElement('template');
      holder.innerHTML = decoded;
      frag = holder.content.cloneNode(true);
    } else {
      frag = tpl.content.cloneNode(true);
    }
    var scripts = Array.prototype.slice.call(frag.querySelectorAll('script'));
    // Insert non-script nodes immediately (pixels/iframes), then run scripts in order.
    var ref = tpl.nextSibling;
    tpl.parentNode.insertBefore(frag, ref);
    // Run scripts sequentially to preserve dependency order.
    var chain = Promise.resolve();
    scripts.forEach(function (old) {
      chain = chain.then(function () { return runScript(old, tpl.parentNode, ref); });
    });
  }

  // Release auto-blocked UNMANAGED tags (data-acconsent-blocked). Restores
  // whichever attribute was deferred (src/href/srcset) for the node's category.
  function releaseBlocked(granted, saleOptOut) {
    var nodes = document.querySelectorAll('[data-acconsent-blocked]');
    Array.prototype.forEach.call(nodes, function (node) {
      var cat = node.getAttribute('data-acconsent-blocked') || 'analytics';
      if (!granted[cat]) return;
      var src = node.getAttribute('data-acconsent-src') || '';
      // H1: a host flagged sale=1 in the blocklist stays withheld while the
      // visitor has opted out, even if its category was otherwise granted.
      if (saleOptOut && src && CFG.sale_share_hosts && CFG.sale_share_hosts.some(function (h) { return src.toLowerCase().indexOf(h) !== -1; })) return;
      // H2: a host flagged spi=1 is withheld UNCONDITIONALLY while
      // limit_spi_enabled is on, independent of any category grant.
      if (S.limit_spi_enabled && src && CFG.spi_hosts && CFG.spi_hosts.some(function (h) { return src.toLowerCase().indexOf(h) !== -1; })) return;
      if (node.getAttribute('data-acconsent-released') === '1') return;
      node.setAttribute('data-acconsent-released', '1');
      var tag = node.tagName.toLowerCase();
      if (tag === 'script') {
        runScript(node, node.parentNode, node.nextSibling);
        return;
      }
      // img / iframe / link / source pixel: restore the deferred attribute.
      var attr = node.getAttribute('data-acconsent-attr') || 'src';
      if (src) node.setAttribute(attr, src);
    });
  }

  function applyConsent(granted, saleOptOut) {
    // Lift the network-API block FIRST so the shim's granted-map is current
    // before we materialize deferred scripts/pixels. If we released first, the
    // still-active shim would re-block any external src we set (re-stashing it
    // into data-acconsent-src) and — because the node is already marked
    // released — it would never load even after the user accepted. Order matters.
    if (typeof window.__acconsentReleaseNetwork === 'function') {
      window.__acconsentReleaseNetwork(granted);
    }
    // H1: keep the shim's sale/share block state consistent with what we're
    // about to release, BEFORE releasing anything, same ordering rationale
    // as __acconsentReleaseNetwork above.
    if (typeof window.__acconsentSetSaleShareOptOut === 'function') {
      window.__acconsentSetSaleShareOptOut(!!saleOptOut);
    }
    document.querySelectorAll('template.acconsent-gated').forEach(function (tpl) {
      var cat = tpl.getAttribute('data-acconsent-category') || 'analytics';
      if (granted[cat]) releaseTemplate(tpl, saleOptOut);
    });
    releaseBlocked(granted, saleOptOut);
    updateConsentMode(granted);
  }

  // Google Consent Mode v2 update on grant.
  function updateConsentMode(granted) {
    if (!S.consent_mode) return;
    gtagSafe('consent', 'update', {
      'ad_storage': granted.marketing ? 'granted' : 'denied',
      'ad_user_data': granted.marketing ? 'granted' : 'denied',
      'ad_personalization': granted.marketing ? 'granted' : 'denied',
      'analytics_storage': granted.analytics ? 'granted' : 'denied',
      'functionality_storage': granted.functional ? 'granted' : 'denied',
      'personalization_storage': granted.functional ? 'granted' : 'denied'
    });
  }

  /* ---------------- server record (proof of consent) ---------------- */

  // The visitor id is owned by the SERVER (first-party cookie acconsent_vid,
  // set by the /config endpoint). We read it so the client and server agree on
  // the subject id; the server never trusts the value the client posts — it
  // re-reads the cookie itself. localStorage is only a last-resort fallback when
  // cookies are blocked (the record then can't be visitor-bound, which is fine —
  // it degrades to the nonce/unbound path).
  function readCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function visitorId() {
    var c = readCookie('acconsent_vid');
    if (c) return c;
    var k = 'acconsent_vid';
    try {
      var v = window.localStorage.getItem(k);
      if (!v) {
        v = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
          : 'v-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        window.localStorage.setItem(k, v);
      }
      return v;
    } catch (e) { return ''; }
  }

  // The consent token travels in the POST BODY (so sendBeacon works) and proves
  // a real page render, bound to the visitor cookie. A full-page-cached page may
  // carry an expired/unbound token; we refresh a fresh BOUND one from the
  // uncached /config endpoint (which also sets the visitor cookie) on a 403 —
  // or proactively when this render had no cookie yet — and retry once.
  var curToken = CFG.token || null;
  var tokenBound = !!CFG.has_vid; // render-time token bound to a visitor cookie?
  function getFreshToken() {
    if (!CFG.config_url) return Promise.resolve(null);
    return fetch(CFG.config_url, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.token) { curToken = j.token; tokenBound = true; } return curToken; })
      .catch(function () { return null; });
  }

  function consentBody(event, categories, source, token, sensitivePiLimited) {
    return JSON.stringify({
      visitor_id: visitorId(),
      event: event,
      categories: categories,
      source: source || 'banner',
      token: token || '',
      // H2: purely an audit-record assertion — release-blocking of SPI items
      // is unconditional and independent of this flag (see releaseTemplate/
      // releaseBlocked). This just records that the right was exercised.
      sensitive_pi_limited: !!sensitivePiLimited
    });
  }

  function postConsent(body) {
    return fetch(CFG.rest_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      keepalive: true,
      body: body
    });
  }

  // Tokens are SINGLE-USE server-side: once a consent write succeeds, that token
  // is burned. We track whether the token in hand is still spendable so a later
  // event (GPC→accept, a preferences change) transparently fetches a fresh one
  // instead of getting a 409 replay rejection.
  var tokenSpent = false;
  function recordServer(event, categories, source, sensitivePiLimited) {
    if (!CFG.rest_url) return;
    var send = function (token) {
      return postConsent(consentBody(event, categories, source, token, sensitivePiLimited));
    };
    // Last-ditch fallback when a normal fetch can't complete (e.g. the page is
    // being torn down). sendBeacon can't set headers, but the token rides in the
    // BODY so it still authenticates. Only fire it with a token we have NOT
    // already spent — beaconing a burned token just earns a 409 and silently
    // loses the event. If our token is spent and we couldn't get a fresh one,
    // skip the beacon (the client-side localStorage record is still authoritative
    // for the UX; the server log just misses this one best-effort event).
    var beacon = function (token) {
      try {
        if (token && !tokenSpent && navigator.sendBeacon) {
          navigator.sendBeacon(CFG.rest_url,
            new Blob([consentBody(event, categories, source, token, sensitivePiLimited)], { type: 'application/json' }));
          tokenSpent = true;
        }
      } catch (e2) {}
    };
    // Ensure we hold a fresh, bound, unspent token before posting. We refetch
    // when: the render token was unbound (no cookie yet), or the token we hold
    // was already spent on a prior event. /config also (re)sets the cookie.
    var needFresh = !tokenBound || tokenSpent || !curToken;
    var prime = needFresh ? getFreshToken() : Promise.resolve(curToken);
    try {
      prime.then(function () {
        return send(curToken).then(function (resp) {
          if (resp && (resp.status === 403 || resp.status === 409)) {
            // Expired/unbound/already-used token (cached page or reused token).
            // Refresh + retry once.
            return getFreshToken().then(function (t) {
              if (t) return send(t).then(function (r2) { if (r2 && r2.ok) tokenSpent = true; });
            });
          }
          if (resp && resp.ok) { tokenSpent = true; } // burned server-side.
        }).catch(function () { beacon(curToken); });
      }).catch(function () { beacon(curToken); });
    } catch (e) {}
  }

  /* ---------------- toast ---------------- */

  function toast(msg) {
    if (!msg) return;
    var t = document.createElement('div');
    t.className = 'acconsent-toast';
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 350);
    }, 3200);
  }

  /* ---------------- UI ---------------- */

  var root, modalOpen = false, lastFocus = null;

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Do the CCPA opt-out controls belong INSIDE the banner/modal on this site?
  // Default placement is 'footer' (see class-acconsent-store.php for the
  // regulatory reasoning); the banner only carries them when the site has
  // explicitly chosen 'banner' or 'both'. Unknown/missing values fall back to
  // 'footer' so an older saved settings row can't accidentally re-enable the
  // banner copy of the controls and produce two of each on the page.
  function showBannerOptouts() {
    var p = S.optout_placement || 'footer';
    if (p !== 'banner' && p !== 'both') return false;
    return !!((S.do_not_sell && S.dns_label) || (S.limit_spi_enabled && S.limit_spi_label));
  }

  // Disclosure line: privacy link + legal-doc version links, shown on the banner
  // and modal BEFORE any choice (informed consent).
  function legalLinksHtml() {
    var bits = [];
    if (S.privacy_url) {
      bits.push('<a href="' + escapeHtml(S.privacy_url) + '" target="_blank" rel="noopener">' + escapeHtml(S.privacy_text || 'Privacy Policy') + '</a>');
    }
    var legal = CFG.legal || {};
    Object.keys(legal).forEach(function (id) {
      var d = legal[id];
      // d.version already carries its own label (auto-increment yields "v1",
      // "v2"; custom labels are shown verbatim). Do NOT prepend another "v".
      if (d.url) {
        bits.push('<a href="' + escapeHtml(d.url) + '" target="_blank" rel="noopener">' +
          escapeHtml(d.title) + ' (' + escapeHtml(d.version) + ')</a>');
      } else {
        bits.push('<span class="acconsent-legal-ref">' + escapeHtml(d.title) + ' ' + escapeHtml(d.version) + '</span>');
      }
    });
    if (!bits.length && !showBannerOptouts() && !CFG.webhook_active) return '';
    var html = '';
    if (bits.length) {
      html += '<div class="acconsent-legal-links">' + bits.join(' · ') + '</div>';
    }
    // CCPA/CPRA "Do Not Sell or Share" + §1798.121 "Limit the Use of My
    // Sensitive Personal Information".
    //
    // These render in the FOOTER by default (optout_placement), because CCR
    // tit.11 §7013(c) / §7014(c) require them "at either the header or footer
    // of the business's internet homepage(s)", and §7026(a)(4) says a cookie
    // banner is not by itself an acceptable opt-out method. They only appear
    // here when the site has opted into 'banner' or 'both' placement.
    if (showBannerOptouts()) {
      var oi = '';
      if (S.do_not_sell && S.dns_label) {
        oi += '<button type="button" class="acconsent-btn acconsent-btn-outline acconsent-optout-btn" data-acconsent-donotsell>' +
          escapeHtml(S.dns_label) + '</button>';
      }
      if (S.limit_spi_enabled && S.limit_spi_label) {
        oi += ' <button type="button" class="acconsent-btn acconsent-btn-outline acconsent-optout-btn" data-acconsent-limitspi>' +
          escapeHtml(S.limit_spi_label) + '</button>';
      }
      if (oi) html += '<div class="acconsent-optout-links">' + oi + '</div>';
    }
    // M5: disclose that consent records may also be mirrored to a webhook
    // (a data processor), which may be located in a different country.
    if (CFG.webhook_active && S.webhook_disclosure) {
      html += '<div class="acconsent-webhook-disclosure">' + escapeHtml(S.webhook_disclosure) + '</div>';
    }
    return html;
  }

  function buildBanner() {
    var b = el('div', 'acconsent-banner acconsent-pos-' + (S.position || 'bottom'));
    // A labelled, focusable region (NOT aria-live: a live region with content
    // already present isn't announced; moving focus in on show is what announces
    // it to AT). role=region + aria-labelledby gives it an accessible name.
    b.setAttribute('role', 'region');
    b.setAttribute('aria-label', S.banner_title || S.aria_consent || 'Cookie consent');
    b.setAttribute('tabindex', '-1');
    var inner = el('div', 'acconsent-banner-inner');
    var titleEl = el('div', 'acconsent-banner-title', escapeHtml(S.banner_title));
    titleEl.id = 'acconsent-banner-title';
    b.setAttribute('aria-labelledby', 'acconsent-banner-title');
    inner.appendChild(titleEl);
    inner.appendChild(el('div', 'acconsent-banner-msg', escapeHtml(S.banner_message)));
    var links = legalLinksHtml();
    if (links) { var lw = el('div'); lw.innerHTML = links; inner.appendChild(lw); }
    var btns = el('div', 'acconsent-banner-btns');

    var accept = el('button', 'acconsent-btn acconsent-btn-primary', escapeHtml(S.accept_label));
    accept.type = 'button';
    accept.addEventListener('click', function () { acceptAll(); });

    var reject = el('button', 'acconsent-btn acconsent-btn-primary', escapeHtml(S.reject_label));
    reject.type = 'button';
    reject.addEventListener('click', function () { rejectAll(); });

    var manage = el('button', 'acconsent-btn acconsent-btn-link', escapeHtml(S.manage_label));
    manage.type = 'button';
    manage.addEventListener('click', function () { openModal(); });

    btns.appendChild(manage);
    btns.appendChild(reject);
    btns.appendChild(accept);
    inner.appendChild(btns);
    b.appendChild(inner);
    return b;
  }

  function buildModal(current) {
    var overlay = el('div', 'acconsent-modal-overlay');
    var modal = el('div', 'acconsent-modal');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    // Visible, labelled close control (Esc/overlay-click are undiscoverable for
    // many users). First child so it's the first focus target in the trap.
    var close = el('button', 'acconsent-modal-close', '\u00d7');
    close.type = 'button';
    close.setAttribute('aria-label', S.close_label || 'Close');
    close.addEventListener('click', function () { closeModal(); });
    modal.appendChild(close);

    var mTitle = el('div', 'acconsent-modal-title', escapeHtml(S.banner_title));
    mTitle.id = 'acconsent-modal-title';
    var mMsg = el('div', 'acconsent-modal-msg', escapeHtml(S.banner_message));
    mMsg.id = 'acconsent-modal-msg';
    // Accessible name + description match what is VISIBLE (WCAG 2.4.6 / 1.3.1).
    modal.setAttribute('aria-labelledby', 'acconsent-modal-title');
    modal.setAttribute('aria-describedby', 'acconsent-modal-msg');
    modal.appendChild(mTitle);
    modal.appendChild(mMsg);
    var links = legalLinksHtml();
    if (links) { var lw = el('div'); lw.innerHTML = links; modal.appendChild(lw); }

    var list = el('div', 'acconsent-cat-list');
    allCategories().forEach(function (key) {
      var cat = CFG.categories[key];
      var row = el('div', 'acconsent-cat');
      var head = el('div', 'acconsent-cat-head');
      var label = el('label', 'acconsent-cat-label');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'acconsent-cat-toggle';
      cb.setAttribute('data-cat', key);
      var granted = grantedSet(current);
      cb.checked = !!granted[key];
      if (cat.locked) { cb.checked = true; cb.disabled = true; }
      label.appendChild(cb);
      label.appendChild(el('span', 'acconsent-cat-name', escapeHtml(cat.label)));
      head.appendChild(label);
      row.appendChild(head);
      row.appendChild(el('div', 'acconsent-cat-desc', escapeHtml(cat.description)));

      var cookies = (CFG.cookies && CFG.cookies[key]) || [];
      if (cookies.length) {
        var details = el('details', 'acconsent-cat-cookies');
        var cw = (cookies.length === 1 ? (S.cookie_one || 'cookie') : (S.cookie_many || 'cookies'));
        details.appendChild(el('summary', null, cookies.length + ' ' + cw));
        var tbl = '<table class="acconsent-cookie-tbl"><thead><tr><th>' +
          escapeHtml(S.col_name || 'Name') + '</th><th>' +
          escapeHtml(S.col_domain || 'Domain') + '</th><th>' +
          escapeHtml(S.col_duration || 'Duration') + '</th></tr></thead><tbody>';
        cookies.forEach(function (c) {
          tbl += '<tr><td>' + escapeHtml(c.name) + '</td><td>' + escapeHtml(c.domain || '—') + '</td><td>' + escapeHtml(c.duration || '—') + '</td></tr>';
        });
        tbl += '</tbody></table>';
        details.appendChild(el('div', null, tbl));
        row.appendChild(details);
      }
      list.appendChild(row);
    });
    modal.appendChild(list);

    var btns = el('div', 'acconsent-modal-btns');
    var reject = el('button', 'acconsent-btn acconsent-btn-primary', escapeHtml(S.reject_label));
    reject.type = 'button';
    reject.addEventListener('click', function () { rejectAll(); }); // commit() closes the modal.

    // Equal visual weight with Accept/Reject so saving a granular (protective)
    // choice is not demoted to a faint link (CNIL symmetry-in-choice).
    var save = el('button', 'acconsent-btn acconsent-btn-outline', escapeHtml(S.save_label));
    save.type = 'button';
    save.addEventListener('click', function () {
      var chosen = { necessary: true };
      modal.querySelectorAll('.acconsent-cat-toggle').forEach(function (cb) {
        chosen[cb.getAttribute('data-cat')] = cb.checked;
      });
      saveChoices(chosen); // commit() closes the modal.
    });

    var accept = el('button', 'acconsent-btn acconsent-btn-primary', escapeHtml(S.accept_label));
    accept.type = 'button';
    accept.addEventListener('click', function () { acceptAll(); }); // commit() closes the modal.

    btns.appendChild(reject);
    btns.appendChild(save);
    btns.appendChild(accept);
    modal.appendChild(btns);
    overlay.appendChild(modal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    return overlay;
  }

  /* ---------------- actions ---------------- */

  // The banner is position:fixed at the bottom of the viewport, so it sits ON
  // TOP of the very region the footer opt-out controls occupy — a visitor who
  // scrolls to the bottom finds the "Do Not Sell or Share" button covered and
  // physically unclickable until they make a consent choice first. That is the
  // §7004(a)(4)(A) fact pattern ("requiring the consumer to click through
  // disruptive screens before they are able to submit a request to opt-out").
  //
  // Fix: while the banner is up, reserve exactly its height of extra room at
  // the end of the document, so the end of the document scrolls clear above it.
  // Measured from the live element (not a guess) and re-measured on resize,
  // because the banner's height changes with viewport width as its buttons
  // wrap. Removed entirely when the banner goes away.
  //
  // That reserved room is a real ELEMENT appended after the theme's content,
  // NOT `padding-bottom` on <body>. Padding on <body> looks equivalent but is
  // not: padding is inside the body box, so the reserved band is painted by
  // the BODY background, and per CSS 3.11 the body background also propagates
  // to the canvas. On a light-<body> site sitting under a dark theme footer
  // that band renders as a bright seam below the footer — and the obvious
  // "fix" for the seam (forcing the body background dark while the banner is
  // up) is worse: it repaints the canvas behind EVERY transparent region of
  // EVERY page, so any section that relies on the body background showing
  // through turns dark and its dark text goes invisible until the visitor
  // makes a consent choice. Real production incident (asctmprd, 2026-08-25):
  // the /leadership grid went black-on-black for un-consented visitors.
  //
  // A spacer element cannot do that. It paints only its own box, we colour it
  // to match whatever the theme actually paints at the bottom of the document,
  // and the body/canvas background is never touched — so the seam disappears
  // on light AND dark themes with no per-site CSS.
  var bannerSpacer = null;

  function opaqueBg(cs) {
    if (cs.backgroundImage && cs.backgroundImage !== 'none') return true;
    var m = cs.backgroundColor && cs.backgroundColor.match(/rgba?\(([^)]+)\)/);
    if (!m) return false;
    var parts = m[1].split(',').map(parseFloat);
    return (parts.length > 3 ? parts[3] : 1) > 0.05;
  }

  // Colour of whatever the theme paints LAST in normal flow — the footer on a
  // conventional layout, or our own auto-rendered opt-out row when a site has
  // styled it. Picked by geometry (bottom-most wide painted box) rather than a
  // selector guess, so it works on any theme. Falls back to the body/canvas
  // colour, which is the pre-existing behaviour.
  function trailingPaintedBg() {
    var best = '';
    var maxBottom = -Infinity;
    var nodes = document.body.querySelectorAll('*');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el === bannerSpacer || el === root || (root && root.contains(el))) continue;
      if (el.classList && el.classList.contains('acconsent-fab')) continue;
      var cs = getComputedStyle(el);
      // Fixed/sticky chrome floats over the page; it is not what the document
      // ends with, so it must not decide the spacer colour.
      if (cs.position === 'fixed' || cs.position === 'sticky') continue;
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      if (!opaqueBg(cs)) continue;
      var r = el.getBoundingClientRect();
      if (r.width < 200 || r.height < 8) continue;
      var bottom = r.bottom + window.scrollY;
      if (bottom > maxBottom) { maxBottom = bottom; best = cs.backgroundColor; }
    }
    if (best) return best;
    var bodyCs = getComputedStyle(document.body);
    return opaqueBg(bodyCs) ? bodyCs.backgroundColor
      : getComputedStyle(document.documentElement).backgroundColor;
  }

  function syncBannerPadding() {
    var b = root && root.querySelector('.acconsent-banner');
    if (!b) {
      if (bannerSpacer && bannerSpacer.parentNode) {
        bannerSpacer.parentNode.removeChild(bannerSpacer);
      }
      bannerSpacer = null;
      document.documentElement.classList.remove('acconsent-banner-open');
      return;
    }
    document.documentElement.classList.add('acconsent-banner-open');
    var h = Math.ceil(b.getBoundingClientRect().height);
    if (!h) return;
    var fresh = false;
    if (!bannerSpacer) {
      bannerSpacer = document.createElement('div');
      bannerSpacer.className = 'acconsent-banner-spacer';
      bannerSpacer.setAttribute('aria-hidden', 'true');
      fresh = true;
    }
    // Colour is resolved once per banner appearance, not on every resize tick:
    // this walks the document and syncBannerPadding() is a resize handler.
    // Measured BEFORE the spacer enters the DOM so it can never sample itself.
    var bg = fresh ? trailingPaintedBg() : '';
    if (bannerSpacer.parentNode !== document.body) {
      document.body.appendChild(bannerSpacer);
    }
    bannerSpacer.style.setProperty('height', h + 'px', 'important');
    if (bg) bannerSpacer.style.setProperty('background', bg, 'important');
  }

  function removeBanner() {
    var b = root.querySelector('.acconsent-banner');
    if (b) b.parentNode.removeChild(b);
    syncBannerPadding();
  }

  function showBanner() {
    if (root.querySelector('.acconsent-banner')) return;
    var b = buildBanner();
    root.appendChild(b);
    // Move focus into the banner so screen-reader / keyboard users are told a
    // consent choice is required and land on it (announces name + role).
    requestAnimationFrame(function () { try { b.focus(); } catch (e) {} });
    // Reserve the banner's footprint AFTER layout so the measurement is real.
    requestAnimationFrame(syncBannerPadding);
    if (!showBanner._resizeBound) {
      showBanner._resizeBound = true;
      window.addEventListener('resize', syncBannerPadding);
    }
  }

  function focusable(container) {
    return Array.prototype.slice.call(container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (n) { return n.offsetParent !== null || n === document.activeElement; });
  }

  function trapTab(e) {
    if (e.key !== 'Tab') return;
    var modal = root.querySelector('.acconsent-modal');
    if (!modal) return;
    var f = focusable(modal);
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault(); last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault(); first.focus();
    }
  }

  function openModal() {
    if (modalOpen) return;
    lastFocus = document.activeElement;
    var overlay = buildModal(readConsent());
    root.appendChild(overlay);
    modalOpen = true;
    document.documentElement.style.overflow = 'hidden'; // scroll-lock the page behind the modal.
    document.addEventListener('keydown', escClose);
    document.addEventListener('keydown', trapTab);
    // Move focus into the dialog so keyboard / screen-reader users land inside it.
    var f = focusable(overlay.querySelector('.acconsent-modal'));
    if (f.length) { f[0].focus(); }
  }

  function closeModal() {
    var o = root.querySelector('.acconsent-modal-overlay');
    if (o) o.parentNode.removeChild(o);
    modalOpen = false;
    document.documentElement.style.overflow = ''; // release scroll-lock.
    document.removeEventListener('keydown', escClose);
    document.removeEventListener('keydown', trapTab);
    // Restore focus to whatever opened the modal — but if that element was
    // removed (e.g. the banner's Manage button after a commit), fall back to the
    // persistent FAB so focus never drops to <body>.
    var target = ( lastFocus && document.contains( lastFocus ) ) ? lastFocus
      : document.querySelector('.acconsent-fab');
    if (target && typeof target.focus === 'function') {
      try { target.focus(); } catch (e) {}
    }
    lastFocus = null;
  }

  function escClose(e) { if (e.key === 'Escape') closeModal(); }

  function commit(categories, event, toastMsg, source, sensitivePiLimited) {
    // GPC GUARD: an active Global Privacy Control signal is a legally-binding
    // sale/share opt-out (CCPA §1798.135). The user may still opt INTO analytics
    // /functional via the modal, but marketing (sale/share) is forced denied and
    // can never be re-enabled in-session by an "Accept all" click.
    // H1: GPC also forces the sale-share opt-out flag true (independent of the
    // marketing category itself — see writeConsent()/applyConsent()).
    var saleOptOut = false;
    if (gpcActive()) {
      categories = Object.assign({}, categories, { marketing: false });
      saleOptOut = true;
      if (event === 'grant') { event = 'update'; }
    }
    writeConsent(categories, saleOptOut);
    applyConsent(grantedSet({ categories: categories }), saleOptOut);
    recordServer(event, categories, source, sensitivePiLimited);
    removeBanner();
    // If a choice was committed from INSIDE the open modal (e.g. the Do-Not-Sell
    // button, which lives in both banner and modal), close the modal first — that
    // restores scroll-lock and focus correctly. Otherwise move focus to the
    // persistent FAB (the banner button the user clicked was just removed, so
    // focus would fall to <body>). If no FAB exists, focus is left at <body>.
    if (modalOpen) {
      closeModal();
    } else {
      var fab = document.querySelector('.acconsent-fab');
      if (fab) { try { fab.focus(); } catch (e) {} }
    }
    if (toastMsg) toast(toastMsg);
  }

  function acceptAll() { commit(fullGrant(true), 'grant', S.toast_accepted, 'banner'); }
  function rejectAll() { commit(fullGrant(false), 'deny', S.toast_rejected, 'banner'); }
  function saveChoices(cats) { commit(cats, 'update', S.toast_accepted, 'manage'); }

  // One-click CCPA "Do Not Sell or Share": deny marketing (sale/share) immediately
  // and record the opt-out. Other categories keep their current state. H1: the
  // INVARIANT is sale_share_opt_out must end up true in localStorage whenever
  // this is clicked (or GPC is active — handled inside commit() above) and
  // false otherwise (e.g. after a plain Accept All with no GPC). We can't
  // route this through commit()'s categories-only signature and still set the
  // flag when GPC is NOT active, so set it directly here via a second
  // writeConsent()/applyConsent() call matching commit()'s own sequencing.
  function doNotSell() {
    var cur = grantedSet(readConsent() || { categories: {} });
    cur.marketing = false;
    cur.necessary = true;
    writeConsent(cur, true);
    applyConsent(grantedSet({ categories: cur }), true);
    recordServer('deny', cur, 'do_not_sell');
    removeBanner();
    if (modalOpen) {
      closeModal();
    } else {
      var fab = document.querySelector('.acconsent-fab');
      if (fab) { try { fab.focus(); } catch (e) {} }
    }
    if (S.toast_rejected) toast(S.toast_rejected);
  }

  // H2: "Limit the Use of My Sensitive Personal Information" (CCPA §1798.121).
  // Release-blocking of SPI-flagged scripts/hosts is UNCONDITIONAL whenever
  // limit_spi_enabled is on (see releaseTemplate()/releaseBlocked() above) —
  // clicking this button doesn't change WHAT gets blocked, it exercises and
  // RECORDS the statutory right for the audit trail.
  function limitSensitivePI() {
    var consent = readConsent();
    var categories = (consent && consent.categories) || fullGrant(false);
    // Persist LOCALLY first. recordServer() is a network call; if it fails
    // (offline, blocked, 4xx) a click that only called it would have zero
    // effect and leave no trace, while still showing the visitor a success
    // toast. §7014(a) contemplates the click having an immediate effect, so
    // the local record has to be written unconditionally and synchronously.
    // spiLimited is already ON by default in the network shim, so this
    // exercises and records the right rather than changing what is blocked —
    // but it must be recorded even when the server never hears about it.
    try {
      var raw = window.localStorage.getItem('acconsent_v1');
      var blob = raw ? JSON.parse(raw) : null;
      if (!blob) {
        blob = { categories: categories, ts: Date.now(), expires: Date.now() + (S.consent_days || 180) * 86400000 };
      }
      blob.limit_spi = true;
      blob.limit_spi_ts = Date.now();
      blob.policy_version = CFG.policy_version;
      blob.catalog_hash = CFG.catalog_hash;
      window.localStorage.setItem('acconsent_v1', JSON.stringify(blob));
    } catch (e) {}
    if (typeof window.__acconsentSetSpiLimited === 'function') {
      try { window.__acconsentSetSpiLimited(true); } catch (e) {}
    }
    recordServer('update', categories, 'limit_spi', true);
    if (S.limit_spi_label) toast(S.limit_spi_label);
  }

  /* ---------------- boot ---------------- */

  function boot() {
    root = document.getElementById('acconsent-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'acconsent-root';
    }
    // Root, AND the persistent FAB trigger, MUST be direct children of
    // <body> — not wherever wp_footer() happened to echo them in the page
    // template. render_banner() (PHP) echoes #acconsent-root and the
    // .acconsent-fab <button> as TWO SEPARATE top-level siblings, both
    // `position: fixed`, and consent.js never previously repositioned
    // either one. Several real themes/plugins (megamenu libraries like
    // mmenu/jet-menu, off-canvas nav, page-transition wrappers) restructure
    // the DOM by wrapping the existing body content into a new container
    // div — and that wrapper commonly carries `transform`, `will-change:
    // transform`, `filter`, or `contain: layout/paint`, any of which
    // creates a NEW containing block for `position: fixed` descendants per
    // spec. If root and/or the FAB end up trapped inside such a wrapper,
    // they stop being fixed to the viewport and instead become fixed
    // relative to that wrapper — breaking the popup/FAB positioning even
    // though `position: fixed` is correctly applied in CSS. Force BOTH to
    // be direct children of body every time boot() runs (moves existing
    // nodes, doesn't clone them) and keep them there via the same
    // MutationObserver guard below.
    function anchorToBody() {
      if (root.parentNode !== document.body) {
        document.body.appendChild(root);
      }
      var fab = document.querySelector('.acconsent-fab');
      if (fab && fab.parentNode !== document.body) {
        document.body.appendChild(fab);
      }
    }
    anchorToBody();
    root.hidden = false;

    // Some of those same DOM-restructuring scripts run asynchronously AFTER
    // this boot() (on a later tick, on first interaction, or on window load,
    // not just DOMContentLoaded) — so a one-time re-parent above isn't
    // sufficient by itself. Watch <body>'s direct children and, if root or
    // the FAB ever stop being direct children of body, move them back
    // immediately. This keeps the popup and FAB correctly positioned even
    // when the page's body structure changes dynamically after initial boot.
    if (window.MutationObserver) {
      var bodyGuard = new MutationObserver(anchorToBody);
      bodyGuard.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('click', function (e) {
      var dns = e.target.closest('[data-acconsent-donotsell]');
      if (dns) { e.preventDefault(); doNotSell(); return; }
      var spi = e.target.closest('[data-acconsent-limitspi]');
      if (spi) { e.preventDefault(); limitSensitivePI(); return; }
      var t = e.target.closest('[data-acconsent-open]');
      if (t) { e.preventDefault(); openModal(); }
    });

    // GPC: an active opt-out signal forces deny and applies it on every load,
    // but we only RECORD the server event ONCE per policy/catalog version (not
    // on every pageview) to keep the audit log clean and the first-opt-out
    // timestamp meaningful.
    if (gpcActive()) {
      var denied = fullGrant(false);
      // H1: GPC is itself a sale/share opt-out signal (CCPA §1798.135) —
      // record it on the stored consent and apply it to the network shim,
      // same as an explicit "Do Not Sell" click.
      writeConsent(denied, true);
      applyConsent(grantedSet({ categories: denied }), true);
      var gpcMark = 'acconsent_gpc_' + CFG.policy_version + '_' + CFG.catalog_hash;
      var alreadyLogged = false;
      try { alreadyLogged = !!window.localStorage.getItem(gpcMark); } catch (e) {}
      if (!alreadyLogged) {
        recordServer('gpc', denied, 'gpc');
        try { window.localStorage.setItem(gpcMark, String(Date.now())); } catch (e) {}
      }
      return;
    }

    var consent = readConsent();
    if (consent) {
      applyConsent(grantedSet(consent), saleShareOptOut(consent));
    } else {
      // First visit OR stale (version changed): nothing released, show banner.
      showBanner();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
