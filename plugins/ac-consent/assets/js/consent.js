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

  function writeConsent(categories) {
    var days = parseInt(S.consent_days, 10) || 180;
    var data = {
      ts: now(),
      expires: now() + days * 24 * 60 * 60 * 1000,
      categories: categories,
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

  function releaseTemplate(tpl) {
    if (tpl.getAttribute('data-acconsent-released') === '1') return;
    tpl.setAttribute('data-acconsent-released', '1');
    var frag;
    // The gated body is base64-encoded (so a "</template>" inside the snippet
    // can't break out of the inert container). Decode it into a real fragment.
    if (tpl.getAttribute('data-acconsent-enc') === 'base64') {
      var decoded = '';
      try { decoded = decodeURIComponent(escape(window.atob(tpl.textContent.trim()))); }
      catch (e) { try { decoded = window.atob(tpl.textContent.trim()); } catch (e2) { decoded = ''; } }
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
  function releaseBlocked(granted) {
    var nodes = document.querySelectorAll('[data-acconsent-blocked]');
    Array.prototype.forEach.call(nodes, function (node) {
      var cat = node.getAttribute('data-acconsent-blocked') || 'analytics';
      if (!granted[cat]) return;
      if (node.getAttribute('data-acconsent-released') === '1') return;
      node.setAttribute('data-acconsent-released', '1');
      var tag = node.tagName.toLowerCase();
      if (tag === 'script') {
        runScript(node, node.parentNode, node.nextSibling);
        return;
      }
      // img / iframe / link / source pixel: restore the deferred attribute.
      var attr = node.getAttribute('data-acconsent-attr') || 'src';
      var src = node.getAttribute('data-acconsent-src');
      if (src) node.setAttribute(attr, src);
    });
  }

  function applyConsent(granted) {
    // Lift the network-API block FIRST so the shim's granted-map is current
    // before we materialize deferred scripts/pixels. If we released first, the
    // still-active shim would re-block any external src we set (re-stashing it
    // into data-acconsent-src) and — because the node is already marked
    // released — it would never load even after the user accepted. Order matters.
    if (typeof window.__acconsentReleaseNetwork === 'function') {
      window.__acconsentReleaseNetwork(granted);
    }
    document.querySelectorAll('template.acconsent-gated').forEach(function (tpl) {
      var cat = tpl.getAttribute('data-acconsent-category') || 'analytics';
      if (granted[cat]) releaseTemplate(tpl);
    });
    releaseBlocked(granted);
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

  function consentBody(event, categories, source, token) {
    return JSON.stringify({
      visitor_id: visitorId(),
      event: event,
      categories: categories,
      source: source || 'banner',
      token: token || ''
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
  function recordServer(event, categories, source) {
    if (!CFG.rest_url) return;
    var send = function (token) {
      return postConsent(consentBody(event, categories, source, token));
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
            new Blob([consentBody(event, categories, source, token)], { type: 'application/json' }));
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
    if (!bits.length && !S.do_not_sell) return '';
    var html = '';
    if (bits.length) {
      html += '<div class="acconsent-legal-links">' + bits.join(' · ') + '</div>';
    }
    // CCPA/CPRA "Do Not Sell or Share" — a genuine ONE-CLICK opt-out: it
    // immediately denies sale/share (marketing) and records the opt-out, with
    // no modal hunt. A real clickable button (outline/secondary style), not a
    // buried micro-text link.
    if (S.do_not_sell && S.dns_label) {
      html += '<div class="acconsent-optout-links">' +
        '<button type="button" class="acconsent-btn acconsent-btn-outline acconsent-optout-btn" data-acconsent-donotsell>' +
        escapeHtml(S.dns_label) + '</button></div>';
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

  function removeBanner() {
    var b = root.querySelector('.acconsent-banner');
    if (b) b.parentNode.removeChild(b);
  }

  function showBanner() {
    if (root.querySelector('.acconsent-banner')) return;
    var b = buildBanner();
    root.appendChild(b);
    // Move focus into the banner so screen-reader / keyboard users are told a
    // consent choice is required and land on it (announces name + role).
    requestAnimationFrame(function () { try { b.focus(); } catch (e) {} });
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

  function commit(categories, event, toastMsg, source) {
    // GPC GUARD: an active Global Privacy Control signal is a legally-binding
    // sale/share opt-out (CCPA §1798.135). The user may still opt INTO analytics
    // /functional via the modal, but marketing (sale/share) is forced denied and
    // can never be re-enabled in-session by an "Accept all" click.
    if (gpcActive()) {
      categories = Object.assign({}, categories, { marketing: false });
      if (event === 'grant') { event = 'update'; }
    }
    writeConsent(categories);
    applyConsent(grantedSet({ categories: categories }));
    recordServer(event, categories, source);
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
  // and record the opt-out. Other categories keep their current state.
  function doNotSell() {
    var cur = grantedSet(readConsent() || { categories: {} });
    cur.marketing = false;
    cur.necessary = true;
    commit(cur, 'deny', S.toast_rejected, 'do_not_sell');
  }

  /* ---------------- boot ---------------- */

  function boot() {
    root = document.getElementById('acconsent-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'acconsent-root';
      document.body.appendChild(root);
    }
    root.hidden = false;

    document.addEventListener('click', function (e) {
      var dns = e.target.closest('[data-acconsent-donotsell]');
      if (dns) { e.preventDefault(); doNotSell(); return; }
      var t = e.target.closest('[data-acconsent-open]');
      if (t) { e.preventDefault(); openModal(); }
    });

    // GPC: an active opt-out signal forces deny and applies it on every load,
    // but we only RECORD the server event ONCE per policy/catalog version (not
    // on every pageview) to keep the audit log clean and the first-opt-out
    // timestamp meaningful.
    if (gpcActive()) {
      var denied = fullGrant(false);
      writeConsent(denied);
      applyConsent(grantedSet({ categories: denied }));
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
      applyConsent(grantedSet(consent));
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
