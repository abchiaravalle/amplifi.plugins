=== amplifi.consent ===
Contributors: amplifistudio
Tags: cookie consent, gdpr, ccpa, privacy, consent log, gpc, consent mode
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.9.1
License: MIT

Cookie consent that withholds your tracking scripts until consent, keeps a best-effort server-side record of each choice, honors GPC, and can auto-block unmanaged trackers.

== Description ==

amplifi.consent withholds tracking scripts until the visitor consents, and —
unlike Google Consent Mode's "fire anyway in anonymized mode" — genuinely does
not run them on reject. Managed scripts are emitted inside inert `<template>`
elements (browsers never execute template contents, including `<script src>`)
and are only re-materialized for the categories the visitor grants.

It also keeps a best-effort server-side record of each consent choice (designed
to help you demonstrate consent), honors the Global Privacy Control signal as an
opt-out, manages versioned legal documents, and can auto-block trackers added by
OTHER plugins or your theme.

= Scope of withholding (read this) =

* **Managed scripts** you add in the Scripts tab are always gated by category.
* **Unmanaged trackers** (added by another plugin, your theme, or hardcoded) are
  only gated when you enable **Auto-block** in Settings, which neutralizes any
  `<script src>`, `<img>`, or `<iframe>` pointing at a blocklisted tracker
  domain until consent. With Auto-block off, the plugin only governs scripts you
  explicitly added.

= Proof of consent =

Each accept / reject / save / GPC event is written on a best-effort basis to a
dedicated database table (`wp_acconsent_log`), server-stamped with the time, the
policy/catalog versions that were live when the visitor saw the banner, the
version of every published legal document, the GPC signal, and a
privacy-preserving (truncated or hashed) IP. It is designed to help you
demonstrate consent (GDPR Art. 7(1)) — it is a tool, not legal advice or a
guarantee, and an event can be missed if the visitor is offline or blocks the
request. Export to CSV/JSON for DSAR / DPA / audit, and optionally mirror every
event to a signed webhook.

= Versioned legal documents =

Manage your Privacy Policy, Terms, and Cookie Policy as versioned documents
inside the plugin and place them on pages with
`[amplifi-legal-doc slug="privacy-policy"]`. The current version of each
published document is stamped into every consent record, so the log proves
exactly which policy texts a visitor agreed to. Publishing a new version
re-prompts returning visitors.

= Features =

* Hard withholding of managed scripts by consent category.
* Optional auto-blocking of unmanaged third-party trackers by domain.
* First-visit popup: Accept all / Reject all / Manage — Reject and Accept carry
  equal visual weight (no dark pattern).
* Per-category toggles: Strictly Necessary (locked), Functional, Analytics,
  Marketing. Tracking scripts cannot be assigned to "necessary."
* Server-side consent log + CSV/JSON export + signed webhook mirror (HMAC-256).
* Global Privacy Control (GPC) honored as an opt-out (CCPA).
* Google Consent Mode v2 defaults (denied) + per-category update.
* Consent versioning: stored consent is invalidated and re-prompted when the
  policy version, script catalog, or any legal-document version changes.
* Versioned legal documents + `[amplifi-legal-doc]` shortcode.
* Persistent floating preferences trigger + `[amplifi-consent-manager]`
  shortcode for withdrawal at any time.
* Pre-consent disclosure: privacy-policy + legal-doc version links on the banner.
* Detected cookies start "unclassified" and are not disclosed until reviewed.
* REST: public read-only `/config`, authenticated `/consent` (record),
  admin-only `/export`.

== Installation ==

1. Install and activate (or enable Consent in the amplifi.studio hub).
2. **Settings**: set your Privacy Policy URL, choose IP handling, and optionally
   enable Webhook, GPC, Consent Mode v2, and Auto-block.
3. **Scripts**: add your tracking snippets, each with a category.
4. **Cookies**: optionally scan a script to detect its cookies, then categorize.
5. **Legal Docs**: add your Privacy Policy / Terms / Cookie Policy and place each
   on a page with the `[amplifi-legal-doc]` shortcode.
6. **Consent Log**: review and export the server-side record of consent.

== Frequently Asked Questions ==

= Does this help prove consent for GDPR? =

It is designed to. Each choice is recorded server-side on a best-effort basis
with a timestamp, the policy version, the catalog hash, and the legal-document
versions live at the time, and that database row — not the client localStorage
cache — is the artifact you would rely on. No plugin can guarantee legal
compliance, and this is not legal advice; the record is a tool to help you
demonstrate consent. Note the record is written by a client request, so events
can be missed if the visitor is offline, blocks the request, or the page is
served from a stale full-page cache. Consult your own counsel.

= Does it handle US / CCPA opt-out? =

It honors the Global Privacy Control browser signal as an opt-out and records
it. CCPA is an opt-out regime; pair this with a "Do Not Sell or Share" link on
your site as appropriate to your business.

= Will it stop trackers added by other plugins? =

Only when Auto-block is enabled. Auto-block neutralizes trackers from a domain
blocklist regardless of who added them, until consent. It covers scripts, images,
iframes, and resource hints whether they are enqueued, hardcoded, or injected at
runtime (via createElement, setAttribute/setAttributeNS, document.write, or
innerHTML), plus fetch / XHR / sendBeacon / WebSocket / EventSource / Worker
requests to blocklisted hosts.

= Is there anything it can't block? =

A few vectors cannot be fully intercepted from JavaScript:

* A native dynamic `import()` of a module from a blocklisted host — browsers do
  not expose a hook for the module loader.
* CSS-driven requests — a background `url(...)` or `@import` to a tracker host in
  a stylesheet.
* Resources loaded inside a *cross-origin* iframe (its realm is inaccessible to
  the parent page's script). Same-origin iframes ARE covered.

For defense-in-depth against these (and as a backstop in general), add a Content
Security Policy (`script-src` / `connect-src` / `img-src` / `style-src`) that
excludes tracker hosts at the server. These are known limitations of every
JavaScript-based consent blocker, not specific to this plugin.

== Changelog ==

= 1.9.1 =
* Fix: the "Do Not Sell or Share" / "Limit Sensitive PI" opt-out buttons now
  have proper spacing (10px gap) instead of relying on a single whitespace
  character between the two `<button>` tags.
* Removed the standalone persistent floating "Do Not Sell or Share" button
  next to the cookie-preferences FAB. The opt-out control is only shown
  where a visitor is actually reviewing/making privacy choices — inside the
  initial consent popup and the revisit/preferences modal — not as an
  always-visible floating element. The `[amplifi-do-not-sell]` shortcode is
  unaffected and still works anywhere on the site.
* CCPA/CPRA "sale/share" scoping (H1): GPC / "Do Not Sell" now also blocks
  specific third-party analytics/session-replay tools flagged as involving
  disclosure to a third party (a "sale/share"), independent of the Marketing
  category grant — closing the gap highlighted by the Sephora enforcement
  action.
* CCPA §1798.121 "Limit the Use of My Sensitive Personal Information" (H2):
  reinstated as a real, wired control (a prior version removed this for
  being cosmetic). Scripts/hosts flagged as Sensitive PI are withheld
  unconditionally whenever the setting is enabled, independent of any
  category grant.
* Robustness (C1): the network shim that gates unmanaged trackers is no
  longer printed via a `wp_head` action — it's now spliced as the absolute
  first thing inside `<head>` during the output-buffer pass, which starts
  on `send_headers` (before `template_redirect`). This closes a gap where a
  raw tracker `<script>` hardcoded into a theme's header.php BEFORE its own
  `wp_head()` call could execute completely ungated.
* Robustness (H6): the 2MB output-buffer size cap now applies only to the
  `<body>` portion of the page — `<head>`, where the vast majority of
  tracker tags live and where the network shim must land regardless of page
  size, is always fully processed.
* Disclosure (M5): consent records may be mirrored to a webhook (a data
  processor), disclosed on the banner/modal when a webhook is configured.

= 1.8.0 =
* Accessibility (WCAG 2.1 AA): the banner is now a labelled region that receives
  focus on show (so screen-reader and keyboard users are told a choice is
  required); focus is moved to the persistent preferences button after a choice
  instead of being lost to the page; the preferences modal scroll-locks the page,
  has matching accessible name/description, and never drops focus to <body>;
  category checkboxes are 24px tap targets and the cookie-table header colour now
  meets 4.5:1 contrast. Accept and Reject remain equal-weight (no dark pattern).
* Internationalization: the plugin now loads its text domain (it ships via
  GitHub, not wp.org, so this is required), declares Domain Path, ships a
  languages/amplifi-consent.pot template, wraps the entire admin UI in gettext,
  and passes the remaining visitor-facing JS strings (Privacy Policy, cookie
  table headers, ARIA labels, cookie/cookies) through translation.
* Robustness: the output-buffer tracker rewrite now skips pages over ~2 MB and
  can never blank a page if a regex hits the PCRE backtrack limit (each pass is
  null-guarded). The /config endpoint sends explicit no-store cache headers so a
  CDN/page cache can't serve a stale token.

= 1.7.0 =
* Single-use consent tokens are now backed by a UNIQUE database key (schema v3),
  enforced explicitly on upgrade (the index is verified/created directly, since
  dbDelta can skip adding a UNIQUE key to an existing table). Duplicate detection
  uses the driver error code (locale-independent) instead of the English message.
* Removed the wp_rest-nonce write path: a consent record now REQUIRES a visitor-
  bound single-use token. The published anonymous nonce could otherwise be used
  to write log rows that skipped visitor/single-use/version binding.
* Restored the "Do Not Sell or Share" admin controls (a checkbox + label field)
  that were missing from the settings form, so the CCPA opt-out can be toggled and
  is no longer silently disabled on the first save.
* Auto-block coverage extended: same-origin child-iframe realms are now patched
  (their fetch/XHR/sendBeacon/WebSocket can't bypass the gate),
  Range.createContextualFragment markup is sanitized (its scripts execute on
  insertion), and object/embed/video(+poster)/audio/track/source resources are
  gated in addition to script/img/iframe/link.
* IP-handling default fallback aligned to "truncate" (data-minimizing). Added a
  deactivation hook that unschedules the purge cron. Documented the CSS-url() and
  cross-origin-iframe limitations alongside dynamic import().

= 1.6.1 =
* Auto-block no longer breaks real-time libraries: the WebSocket / EventSource /
  Worker / SharedWorker guards now preserve the interface's static constants
  (WebSocket.OPEN, EventSource.CLOSED, …) so libraries that read them keep
  working, and a blocked transport returns an inert no-op stub instead of
  throwing (so host init code isn't crashed).
* Single-use tokens are now enforced ATOMICALLY by a UNIQUE database key on the
  token id, so a duplicate/replayed token can never produce a second consent row
  — this holds on every host (no object cache required) and under true
  concurrency. A fast object-cache pre-check short-circuits obvious replays when
  a persistent cache is present. The token-id window was widened to cover clock
  skew so a replay can't reopen on a skewed cluster.
* The sendBeacon network-failure fallback no longer re-sends an already-spent
  token (which would 409 and silently drop the event).
* Trimmed the MutationObserver backstop to a single flat pass (no redundant
  subtree re-scans) and dropped <source> from auto-block release (it can't be
  reliably reloaded after grant).

= 1.6.0 =
* Single-use consent tokens. Each render token is now valid for exactly one
  consent write (a random token id is burned on use); a captured token can no
  longer be replayed within its window to write duplicate/forged records. The
  front-end transparently fetches a fresh token for each later preference change.
* Much broader auto-block coverage for runtime-injected trackers. In addition to
  the existing script/img/iframe `.src` setters and `setAttribute('src')`, the
  engine now also covers: `setAttributeNS`; `srcset` / `imagesrcset`; `<link href>`
  resource hints set via JS; `document.write` / `document.writeln` markup; a
  MutationObserver backstop for `innerHTML`-injected `<img>`/`<iframe>`/`<link>`
  pixels; and the `WebSocket`, `EventSource`, `Worker`, and `SharedWorker`
  constructors. Released uniformly on grant. (Native dynamic `import()` still can't
  be hooked from JS — see the FAQ; use a CSP backstop.)
* Rate-limit counter now uses an atomic object-cache increment when a persistent
  cache is available, so concurrent requests can't overshoot the ceiling.

= 1.5.1 =
* Hardened consent-record attribution (closes a forgery path found in a security
  review). A consent write that presents a signed token now REQUIRES that token
  to be visitor-bound and to match the first-party cookie on the request — an
  unbound page-render token can no longer be replayed to fabricate a record, and
  the client-supplied visitor_id is ignored entirely (the server uses only the
  cookie it issued). Token lifetime shortened from 24h to 2h, and the visitor
  cookie is now HttpOnly (the browser JS never needs it; the server re-reads it).
* Fixed a release-ordering bug: after the visitor accepts, the network block is
  now lifted BEFORE deferred external scripts/pixels are materialized, so
  auto-blocked third-party scripts actually load on grant instead of being
  re-blocked and silently dropped.
* Honest framing: internal docs and the cookie-scanner description no longer say
  "authoritative proof" / "append-only" / "sandboxed" — the record is a
  best-effort, server-stamped, visitor-bound record, and the scanner is a real
  (only-scan-what-you-trust) execution.
* Fixed a latent save bug where the new trust_proxy / do_not_sell checkboxes
  could not be turned back off once enabled.

= 1.5.0 =
* Visitor-bound consent proof. The server now issues a first-party visitor
  cookie (acconsent_vid) from the uncached /config endpoint and BINDS the signed
  consent token to it. A recorded consent event is attributed to a subject id the
  SERVER issued, not an arbitrary client-asserted visitor_id, and a token minted
  for one browser can't be replayed to pollute another visitor's record. The
  client's posted visitor_id is no longer trusted — the server re-reads the cookie.
* The render-time legal-doc snapshot (which Privacy/Terms versions the visitor
  actually saw) is now bound INTO the signed token alongside policy_version and
  catalog_hash, so it can't drift on a cached/delayed POST — closing the last
  spot where a receipt could attest to docs the visitor never saw.
* Auto-block now also guards the iframe.src property setter (pixel iframes), in
  addition to the existing script/img setters and the setAttribute('src') path.
* Optional "trust reverse proxy" setting: behind Cloudflare/nginx, derive the
  real client IP (CF-Connecting-IP / X-Forwarded-For) for rate-limiting instead
  of bucketing every visitor under the proxy IP. Off by default (XFF is
  spoofable on a direct-connect origin).
* Honest copy: the "Do Not Sell or Share" control is described as a real
  one-click button (outline/secondary style), not "button parity".

= 1.4.0 =
* Removed the "Sensitive Personal Information" category added in 1.3.0. It could
  not be assigned to any managed script (silently downgraded to Analytics) and
  "Accept all" inverted the right by granting it — a half-wired statutory control
  is worse than none, so it's gone until it can be wired to real processing.
* "Do Not Sell or Share" is now a genuine ONE-CLICK opt-out — a real button (it
  immediately denies sale/share and records it) instead of a micro-text link that
  just opened the preferences modal.
* GPC override closed: with an active Global Privacy Control signal, marketing
  (sale/share) is forced denied and can no longer be re-enabled in-session by an
  "Accept all" click; such a click is recorded as an update, never a grant.
* Hardened the network shim against dynamically DOM-injected trackers — it now
  guards HTMLScriptElement/HTMLImageElement `src` setters and `setAttribute`, and
  patches `sendBeacon` on the prototype (the createElement('script') loader
  pattern used by Meta Pixel / GTM child tags is now deferred until consent).
* Consent records are now authenticated by a server-signed token issued at page
  render (carried in the POST body) rather than a publicly-vended nonce. The
  token also BINDS the policy/catalog versions live at render time, so a cached
  or delayed POST attests to exactly what the visitor saw — and because the token
  rides in the body, the `navigator.sendBeacon` unload fallback now actually
  persists (previously it 403'd because a beacon can't set the nonce header).
* Rate limiting is now always enforced on the request IP (with a tighter
  per-visitor sub-limit on top), so rotating a client-chosen visitor_id can no
  longer bypass it.
* Retention now hard-floors any positive value at 730 days so an operator can't
  accidentally purge consent proof below the CCPA 24-month minimum.
* Schema migration now runs on `init` (not only `admin_init`) with a column-exists
  self-heal in the writer, so front-end-only / headless sites no longer drop
  records during the window after an auto-update.
* IP logging now defaults to `truncate` (data minimization) instead of `hash`.
* Cookie-scanner iframe now carries a `sandbox` attribute; admin/readme copy
  softened from "authoritative proof" to "best-effort record / not legal advice";
  coverage badge counts only enabled scripts; plugin header copy refreshed.

= 1.3.0 =
* Consent records are no longer dropped on full-page-cached sites: the client
  fetches a fresh REST nonce from the uncached /config endpoint and retries on a
  stale-nonce 403, with a sendBeacon fallback on network failure.
* Rate limiting is now keyed on the pseudonymous visitor id (not a shared egress
  IP), so visitors behind one NAT/CDN IP are no longer throttled into dropped
  records.
* The legal-document snapshot now has its own `legal_snapshot` DB column (and CSV
  column) instead of being folded into `categories`, so an auditor can query
  exactly which policy texts applied to each receipt. (DB schema v2 — upgrades
  automatically; old rows still read correctly.)
* CSV export neutralizes spreadsheet formula injection (cells starting with
  = + - @ are escaped) so a tracker-set User-Agent can't run a formula when an
  admin opens the export.
* Added a Sensitive Personal Information category for the CCPA/CPRA "Limit the
  Use of My Sensitive PI" right.
* GPC opt-out is recorded server-side only once per policy/catalog version
  instead of on every page load (cleaner audit trail, meaningful first-opt-out
  timestamp); the deny is still applied on every load.
* Logged referer URLs are stripped to scheme+host+path (query string dropped)
  for data minimization.
* Optional consent-log retention window (purges rows older than N days via a
  daily cron; 0 = keep forever).
* Cookie-scanner postMessage is now scoped to the site origin on both sender and
  receiver instead of using a wildcard.
* Fixed a settings-save bug that stripped the `|category` from blocklist lines.

= 1.2.0 =
* Auto-block is now ON by default, with a per-host release category (the
  blocklist uses `host|category` lines) so granting Analytics no longer releases
  Marketing/ad pixels. Default category is `marketing` (strictest opt-in).
* Added a pre-`<head>` network shim that intercepts fetch/XHR/sendBeacon/Image
  calls to blocklisted hosts before consent (catches trackers fired by
  first-party bundles, not just `<script src>` tags).
* Output-buffer parsing now uses WP_HTML_Tag_Processor (case-insensitive,
  attribute-order safe) and strips preconnect/dns-prefetch resource hints to
  tracker hosts. Gated managed scripts are base64-encoded so a `</template>`
  inside a snippet can't break out of the inert container.
* Expanded the default blocklist (TikTok, Twitter/X, Reddit, Pinterest, B2B
  de-anonymization) with correct analytics-vs-marketing categories.
* Added CCPA/CPRA "Do Not Sell or Share" and "Limit the Use of Sensitive PI"
  opt-out affordances that open the preferences modal.
* Consent modal: "Save choices" now carries equal visual weight to Accept/Reject
  (symmetry-in-choice); added a keyboard focus trap and focus restore.
* Admin Settings shows a live coverage status ("gating N trackers") so a banner
  that withholds nothing can't ship silently.
* Honesty pass on readme / manifest / FAQ copy: "best-effort record" instead of
  "proof of every choice"; added a not-legal-advice / no-guarantee disclaimer.

= 1.1.0 =
* Server-side consent log (new `wp_acconsent_log` table) — durable, timestamped,
  versioned proof of consent. CSV/JSON export.
* Optional HMAC-signed webhook mirror of every consent event.
* Global Privacy Control (GPC) honored as an opt-out.
* Google Consent Mode v2 defaults + per-category update.
* Auto-block unmanaged third-party trackers by domain (script_loader_tag filter
  + output-buffer + deferred pixels).
* Consent versioning: stored consent invalidated + re-prompt on policy/catalog/
  legal-document change.
* Versioned legal documents + `[amplifi-legal-doc]` shortcode; versions stamped
  into every consent record.
* Persistent floating preferences trigger; pre-consent legal/version links.
* Security: tracking scripts can no longer be assigned to "necessary."
* Detected cookies default to "unclassified" (not disclosed until reviewed).
* Release fidelity: external scripts load in order; post-load document.write is
  neutralized.
* Locked-down REST surface + rate-limited consent endpoint.

= 1.0.0 =
* Initial release: hard script withholding, first-visit popup, per-category
  toggles, toast feedback, localStorage consent, manager shortcode, iframe
  cookie scanner, REST config endpoint.
