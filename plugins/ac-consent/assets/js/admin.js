/* amplifi.consent — admin scanner.
 * Loads a managed script inside a hidden same-origin iframe (the harness),
 * receives the cookies it set via postMessage, and submits them to the merge
 * endpoint so they land in the cookie catalog for categorization.
 */
(function () {
  'use strict';
  if (typeof window.ACCONSENT_ADMIN === 'undefined') return;

  var pending = null;

  window.addEventListener('message', function (e) {
    // Only trust messages from our own origin (the same-origin scan harness).
    if (e.origin !== window.location.origin) return;
    var d = e.data;
    if (!d || !d.acconsent) return;
    var resultEl = document.querySelector('.acconsent-scan-result[data-for="' + d.scriptId + '"]');
    var cookies = d.cookies || [];
    if (resultEl) {
      resultEl.textContent = cookies.length
        ? ('Found ' + cookies.length + ' cookie(s): ' + cookies.map(function (c) { return c.name; }).join(', ') + ' — saving…')
        : 'No new first-party cookies detected.';
    }
    if (cookies.length) {
      // Post to the hidden merge form so PHP persists them, then reload to the Cookies tab.
      var f = document.getElementById('acconsent-merge-form');
      if (f) {
        document.getElementById('acconsent-merge-script-id').value = d.scriptId;
        document.getElementById('acconsent-merge-detected').value = JSON.stringify(cookies);
        f.submit();
      }
    }
    cleanup();
  });

  function cleanup() {
    if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
    pending = null;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.acconsent-scan-btn');
    if (!btn) return;
    e.preventDefault();
    if (!window.confirm('This runs the script once to detect its cookies and may contact the third party. Continue?')) return;
    var id = btn.getAttribute('data-script-id');
    var resultEl = document.querySelector('.acconsent-scan-result[data-for="' + id + '"]');
    if (resultEl) resultEl.textContent = 'Scanning…';
    cleanup();
    var iframe = document.createElement('iframe');
    // The harness must read document.cookie (its purpose) so it needs
    // allow-same-origin, but we deny top-navigation, popups, form submission,
    // pointer-lock, etc. to limit the blast radius of running third-party JS.
    iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    iframe.style.cssText = 'width:0;height:0;border:0;position:absolute;left:-9999px';
    iframe.src = window.ACCONSENT_ADMIN.harness_url + '&script_id=' + encodeURIComponent(id);
    document.body.appendChild(iframe);
    pending = iframe;
    // Safety timeout.
    setTimeout(function () {
      if (pending === iframe) {
        if (resultEl) resultEl.textContent = 'Scan timed out (no cookies reported).';
        cleanup();
      }
    }, 8000);
  });
})();
