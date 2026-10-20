/* amplifi.translate: one-time country redirect.
 *
 * Privacy:
 * - The country comes from the site's own CDN edge (Cloudflare /cdn-cgi/trace). The request is same-origin,
 *   is never cached, and no third party is involved. Only the two-letter country ("loc=") is read. The IP
 *   address in that response is never read or stored.
 * - No localStorage. Two sessionStorage keys (this tab only, cleared when the tab closes):
 *     acwpt_geo_done  = the first page of this visit has been handled (never redirect again this visit)
 *     acwpt_lang_pref = the language the visitor chose in a language switcher (always wins)
 * - Two first-party SESSION cookies (no Expires/Max-Age, so the browser deletes them when it closes;
 *   path=/, SameSite=Lax, Secure on https). They exist only because sessionStorage is per tab: a link
 *   opened in a new tab (target=_blank + noopener) starts with empty sessionStorage and would otherwise be
 *   treated as a brand-new visit and redirected again.
 *     acwpt_lang = the 2-letter language code the visitor explicitly chose in a language switcher. Set ONLY
 *                  by a click on a switcher link. Nothing else is stored in it, and it is never sent to any
 *                  third party (the server receives it with page requests and does not read it).
 *     acwpt_geo  = "1": this browser session's first page has been handled.
 *   Purpose: remember a user-interface choice (language) for the session = a strictly necessary,
 *   consent-exempt "user interface customisation" cookie. It does not identify the visitor, track across
 *   sites, or carry analytics/advertising data.
 *
 * Behaviour:
 * - Only the FIRST page view of a visit can redirect, and only from the English (source) version of a page.
 *   A visitor who lands on a translated URL is already on a language they chose.
 * - The target is the page's own hreflang alternate, so the visitor stays on the same page.
 * - Picking a language in any switcher is remembered for the browser session (all tabs) and stops any
 *   further country redirect. If the visitor later opens an English page in a new tab, they are taken to
 *   that page in the language they chose. Choosing English means "never redirect" for the session.
 * - Never redirects crawlers, headless browsers or logged-in editors.
 * - Test/escape hatch: ?acwpt_geo=PL simulates a country; ?acwpt_geo=0 turns it off for the visit.
 */
(function () {
	'use strict';
	var C = window.acwptGeo;
	if (!C || !window.fetch || !document.querySelector) { return; }

	var SS;
	try {
		SS = window.sessionStorage;
		SS.setItem('acwpt_t', '1');
		SS.removeItem('acwpt_t');
	} catch (e) {
		return; // no session storage: cannot guarantee "once per visit", so never redirect
	}

	var K_DONE = 'acwpt_geo_done', K_PREF = 'acwpt_lang_pref';
	var CK_LANG = 'acwpt_lang', CK_DONE = 'acwpt_geo';
	var enabled = C.enabled || [], source = C.source || 'en', cur = C.current || source;

	function isLang(l) { return !!l && (l === source || enabled.indexOf(l) !== -1); }

	// Session cookies (no Expires / Max-Age => deleted when the browser closes).
	function getCookie(name) {
		var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : null;
	}
	function setCookie(name, value) {
		try {
			document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax' +
				(location.protocol === 'https:' ? '; Secure' : '');
		} catch (e) {}
	}

	function langOfLink(a) {
		var d = a.getAttribute('data-acwpt-lang');
		if (d && isLang(d)) { return d; }
		var h = (a.getAttribute('hreflang') || '').slice(0, 2).toLowerCase();
		if (h && isLang(h)) { return h; }
		try {
			var p = new URL(a.href, location.href).pathname.split('/')[1] || '';
			return enabled.indexOf(p) !== -1 ? p : source;
		} catch (e) { return null; }
	}

	// 1. Remember an explicit choice made in any language switcher (for the rest of the browser session).
	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[data-acwpt-lang]') : null;
		if (!a || a.getAttribute('data-acwpt-lang') === 'toggle') { return; }
		var l = langOfLink(a);
		if (l) {
			try { SS.setItem(K_PREF, l); SS.setItem(K_DONE, '1'); } catch (err) {}
			setCookie(CK_LANG, l);
			setCookie(CK_DONE, '1');
		}
	}, true);

	// 2. Escape hatch / test override.
	var q = (location.search.match(/[?&]acwpt_geo=([A-Za-z0-9]{1,2})/) || [])[1];
	if (q === '0') { try { SS.setItem(K_DONE, '1'); } catch (e) {} setCookie(CK_DONE, '1'); return; }

	// 3. Once per visit: the first page decides. A new tab of the same browser session has empty
	//    sessionStorage, so it is recognised by the session cookies instead.
	if (SS.getItem(K_PREF) || SS.getItem(K_DONE)) { setCookie(CK_DONE, '1'); return; }
	var chosen = getCookie(CK_LANG);
	if (!isLang(chosen)) { chosen = null; }
	var handled = getCookie(CK_DONE) === '1';
	SS.setItem(K_DONE, '1');
	if (chosen) { try { SS.setItem(K_PREF, chosen); } catch (e) {} }
	setCookie(CK_DONE, '1');
	if (cur !== source) { return; }
	if (chosen === source) { return; } // chose English: never redirect this session
	if (handled && !chosen) { return; } // this session already had its one country redirect decision

	var ua = navigator.userAgent || '';
	if (navigator.webdriver && !q) { return; }
	if (/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|lighthouse|headless|google|baidu|yandex|duckduck|semrush|ahrefs|mj12|petal/i.test(ua)) { return; }

	// Country -> language. Only countries whose national language matches the site's variant.
	var MAP = {
		PL: 'pl', DE: 'de', AT: 'de', LI: 'de', FR: 'fr', MC: 'fr', IT: 'it', SM: 'it', VA: 'it',
		ES: 'es', AD: 'es', PT: 'pt', CZ: 'cs', RO: 'ro', MD: 'ro', TR: 'tr', CN: 'zh', NL: 'nl',
		MX: 'mx', GT: 'mx', HN: 'mx', SV: 'mx', NI: 'mx', CR: 'mx', PA: 'mx', CU: 'mx', DO: 'mx', PR: 'mx',
		CO: 'mx', VE: 'mx', EC: 'mx', PE: 'mx', BO: 'mx', CL: 'mx', AR: 'mx', UY: 'mx', PY: 'mx'
	};
	// Multilingual countries: use the browser's own language to choose; if none of these, no redirect.
	var MULTI = { CH: ['de', 'fr', 'it'], BE: ['nl', 'fr'], LU: ['fr', 'de'] };
	// Countries where only the browser's MAIN language decides (first entry of navigator.languages): a US visitor
	// whose browser is set to Spanish gets Latin American Spanish; every other US visitor stays on the page they opened.
	var BY_BROWSER = { US: { es: 'mx' } };

	function pick(cc) {
		if (!cc) { return null; }
		if (MAP[cc]) { return MAP[cc]; }
		if (BY_BROWSER[cc]) {
			var main = String((navigator.languages && navigator.languages[0]) || navigator.language || '').slice(0, 2).toLowerCase();
			return BY_BROWSER[cc][main] || null;
		}
		var opts = MULTI[cc];
		if (!opts) { return null; }
		var bl = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''];
		for (var i = 0; i < bl.length; i++) {
			var b = String(bl[i]).slice(0, 2).toLowerCase();
			if (opts.indexOf(b) !== -1) { return b; }
		}
		return null;
	}

	function country() {
		if (q && q.length === 2) { return Promise.resolve(q.toUpperCase()); }
		var ctl = window.AbortController ? new AbortController() : null;
		var t = ctl ? setTimeout(function () { ctl.abort(); }, 1500) : null;
		return fetch('/cdn-cgi/trace', { cache: 'no-store', credentials: 'omit', signal: ctl ? ctl.signal : undefined })
			.then(function (r) { return r.ok ? r.text() : ''; })
			.then(function (txt) { if (t) { clearTimeout(t); } var m = /(?:^|\n)loc=([A-Z]{2})/.exec(txt); return m ? m[1] : null; })
			.catch(function () { return null; });
	}

	function go(lang) {
		if (document.body && document.body.classList.contains('logged-in')) { return; }
		var tag = (C.tags && C.tags[lang]) || lang;
		var alt = document.querySelector('link[rel="alternate"][hreflang="' + tag + '"]');
		if (!alt || !alt.href) { return; }
		var search = location.search.replace(/([?&])acwpt_geo=[^&]*&?/, '$1').replace(/[?&]$/, '');
		location.replace(alt.href + search + location.hash);
	}

	function when(lang) {
		if (!lang || lang === cur || enabled.indexOf(lang) === -1) { return; }
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () { go(lang); });
		} else {
			go(lang);
		}
	}

	// A language the visitor chose earlier in this browser session wins over the country.
	if (chosen) { when(chosen); return; }

	country().then(function (cc) { when(pick(cc)); });
})();
