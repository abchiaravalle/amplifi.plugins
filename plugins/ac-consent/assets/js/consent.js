/* amplifi.consent — front-end engine
 * Hard-withholds gated scripts until consent. Gated scripts live inside inert
 * <template> blocks; we only re-create them as live <script> nodes for granted
 * categories. Reject => never released => zero tracking.
 */
(function () {
  'use strict';

  if (typeof window.ACCONSENT === 'undefined') return;
  var CFG = window.ACCONSENT;
  var S = CFG.settings;
  var KEY = CFG.storage_key || 'acconsent_v1';

  /* ---------------- consent storage (localStorage, N-day TTL) ---------------- */

  function now() { return Date.now(); }

  function readConsent() {
    try {
      var raw = window.localStorage.getItem(KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || !data.expires || data.expires < now()) return null;
      if (!data.categories || typeof data.categories !== 'object') return null;
      return data;
    } catch (e) { return null; }
  }

  function writeConsent(categories) {
    var days = parseInt(S.consent_days, 10) || 180;
    var data = {
      ts: now(),
      expires: now() + days * 24 * 60 * 60 * 1000,
      categories: categories
    };
    try { window.localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) {}
    return data;
  }

  function allCategories() { return Object.keys(CFG.categories || {}); }

  function grantedSet(consent) {
    // necessary is always granted
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

  /* ---------------- script release (the core withholding mechanism) ---------------- */

  function releaseTemplate(tpl) {
    if (tpl.getAttribute('data-acconsent-released') === '1') return;
    var frag = tpl.content.cloneNode(true);
    // Re-create every <script> so the browser actually executes it. Scripts
    // parsed inside a <template> (or cloned) are inert until rebuilt this way.
    var scripts = frag.querySelectorAll('script');
    scripts.forEach(function (old) {
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) {
        s.setAttribute(old.attributes[i].name, old.attributes[i].value);
      }
      s.textContent = old.textContent;
      old.parentNode.replaceChild(s, old);
    });
    tpl.parentNode.insertBefore(frag, tpl.nextSibling);
    tpl.setAttribute('data-acconsent-released', '1');
  }

  function applyConsent(granted) {
    var tpls = document.querySelectorAll('template.acconsent-gated');
    tpls.forEach(function (tpl) {
      var cat = tpl.getAttribute('data-acconsent-category') || 'analytics';
      if (granted[cat]) releaseTemplate(tpl);
    });
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

  var root, modalOpen = false;

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function buildBanner() {
    var b = el('div', 'acconsent-banner acconsent-pos-' + (S.position || 'bottom'));
    b.setAttribute('role', 'dialog');
    b.setAttribute('aria-live', 'polite');
    b.setAttribute('aria-label', S.banner_title || 'Cookie consent');
    var inner = el('div', 'acconsent-banner-inner');
    inner.appendChild(el('div', 'acconsent-banner-title', escapeHtml(S.banner_title)));
    inner.appendChild(el('div', 'acconsent-banner-msg', escapeHtml(S.banner_message)));
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
    modal.setAttribute('aria-label', S.manage_label || 'Manage preferences');

    modal.appendChild(el('div', 'acconsent-modal-title', escapeHtml(S.banner_title)));
    modal.appendChild(el('div', 'acconsent-modal-msg', escapeHtml(S.banner_message)));

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

      // Cookie detail for this category, if any catalogued.
      var cookies = (CFG.cookies && CFG.cookies[key]) || [];
      if (cookies.length) {
        var details = el('details', 'acconsent-cat-cookies');
        var sum = el('summary', null, cookies.length + ' cookie' + (cookies.length === 1 ? '' : 's'));
        details.appendChild(sum);
        var tbl = '<table class="acconsent-cookie-tbl"><thead><tr><th>Name</th><th>Domain</th><th>Duration</th></tr></thead><tbody>';
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
    reject.addEventListener('click', function () { rejectAll(); closeModal(); });

    var save = el('button', 'acconsent-btn acconsent-btn-link', escapeHtml(S.save_label));
    save.type = 'button';
    save.addEventListener('click', function () {
      var chosen = { necessary: true };
      modal.querySelectorAll('.acconsent-cat-toggle').forEach(function (cb) {
        chosen[cb.getAttribute('data-cat')] = cb.checked;
      });
      saveChoices(chosen);
      closeModal();
    });

    var accept = el('button', 'acconsent-btn acconsent-btn-primary', escapeHtml(S.accept_label));
    accept.type = 'button';
    accept.addEventListener('click', function () { acceptAll(); closeModal(); });

    btns.appendChild(reject);
    btns.appendChild(save);
    btns.appendChild(accept);
    modal.appendChild(btns);
    overlay.appendChild(modal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    return overlay;
  }

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------- actions ---------------- */

  function removeBanner() {
    var b = root.querySelector('.acconsent-banner');
    if (b) b.parentNode.removeChild(b);
  }

  function showBanner() {
    if (root.querySelector('.acconsent-banner')) return;
    root.appendChild(buildBanner());
  }

  function openModal() {
    if (modalOpen) return;
    var current = readConsent();
    var overlay = buildModal(current);
    root.appendChild(overlay);
    modalOpen = true;
    document.addEventListener('keydown', escClose);
  }

  function closeModal() {
    var o = root.querySelector('.acconsent-modal-overlay');
    if (o) o.parentNode.removeChild(o);
    modalOpen = false;
    document.removeEventListener('keydown', escClose);
  }

  function escClose(e) { if (e.key === 'Escape') closeModal(); }

  function acceptAll() {
    var cats = fullGrant(true);
    writeConsent(cats);
    applyConsent(grantedSet({ categories: cats }));
    removeBanner();
    toast(S.toast_accepted);
  }

  function rejectAll() {
    var cats = fullGrant(false);
    writeConsent(cats);
    // nothing to release; necessary scripts (if any) released
    applyConsent(grantedSet({ categories: cats }));
    removeBanner();
    toast(S.toast_rejected);
  }

  function saveChoices(cats) {
    writeConsent(cats);
    applyConsent(grantedSet({ categories: cats }));
    removeBanner();
    toast(S.toast_accepted);
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

    // Re-open trigger (shortcode button or any [data-acconsent-open]).
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-acconsent-open]');
      if (t) { e.preventDefault(); openModal(); }
    });

    var consent = readConsent();
    if (consent) {
      // Returning visitor with valid consent: release granted categories, no banner.
      applyConsent(grantedSet(consent));
    } else {
      // First visit: nothing released, show the banner.
      showBanner();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
