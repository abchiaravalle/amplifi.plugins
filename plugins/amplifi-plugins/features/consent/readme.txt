=== amplifi.consent ===
Contributors: amplifistudio
Tags: cookie consent, gdpr, ccpa, privacy, consent log, gpc, consent mode
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.3.7
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

= 3.3.7 =
* Fix: the reserved band was sized one pixel SHORT of the banner, which left the
  last content fractionally underneath it. On this site it landed inside the
  opt-out row's own padding so no control was ever covered, but the margin was
  the wrong way round and fractional device-pixel ratios (1.25/1.5/2.75) make it
  worse. The band is now sized one pixel OVER. Verified at true maximum scroll,
  desktop and mobile: zero overlap between the banner and the opt-out row, both
  controls hit-testing as themselves at their bottom edge.
* Fix: the banner, the reserved band, the floating preferences button and the
  modal overlay are hidden in print. They are screen chrome; printing them added
  a blank strip and a floating card to every printed page or PDF. The footer
  opt-out row is real page content and still prints.

= 3.3.6 =
* Fix: the banner's outer box is 16px larger than its card on every side, and
  that frame was fully transparent — so whatever sat at the end of the document
  showed through it. Because the room reserved behind the banner is deliberately
  blank, on a dark-footer site the frame rendered as a pale halo around the card,
  measured at RGB ~246 against a black footer (~239 on mobile). Earlier releases
  did not catch this because a hit test reports the banner as covering that area:
  the element is there, it simply was not painting. The frame is now
  backdrop-blurred, which ties it to whatever is genuinely behind it on light and
  dark themes alike, with a neutral scrim fallback where backdrop-filter is
  unsupported. This is the actual seam the previous three releases were trying to
  hide by colouring the reserved band instead.

= 3.3.5 =
* Fix: the reserved room no longer tries to match the page's colour, because it
  never needed to. The band sits exactly where a bottom-fixed banner paints, so
  the banner covers it and its colour is never visible; it is now a plain
  transparent block sized one pixel under the banner, which absorbs rounding so
  not even a sliver can show. Three releases of colour-matching machinery are
  deleted: 3.3.2's body padding (repainted the page canvas), 3.3.3's colour
  sampling (picked a translucent colour from inside a scroller and painted a
  near-white band under a black footer), and 3.3.4's padding of the theme's own
  footer element. That last approach also mis-painted under
  `background-clip: content-box` and whenever the element it found was narrower
  than the viewport, and it mutated an element the plugin does not own — a
  footer re-render, a third-party inline style write, or a `cloneNode()` could
  each corrupt it or silently drop the reservation.
* Fix: the short-page check used `documentElement.scrollHeight`, which the
  browser clamps to the viewport height — a 101px page reports the full 700px on
  a 700px window. That made the reserve and release branches disagree on
  consecutive frames, inserting and removing a DOM node on every animation frame
  indefinitely. It now measures the unclamped content height, with hysteresis at
  the boundary so the two branches cannot contradict each other.
* Fix: with the banner centred, the opt-out control is forced into the banner
  card. A centred banner is a full-viewport scrim that covers the footer at every
  scroll offset, so a footer-only opt-out could not be reached at all until the
  visitor dismissed the consent UI — the click-through §7004(a)(4)(A) prohibits.
  Reserving room cannot solve that, as there is no uncovered strip to scroll the
  control into. Enforced in code rather than left to the admin UI, because either
  setting can be changed independently and the failure is invisible from the
  front end.
* The modal's scroll-lock restore no longer keeps a stale value after closing.

= 3.3.4 =
* Fix: the banner-room reservation introduced in 3.3.3 sampled a background
  colour by scanning for the bottom-most painted element, and that scan could
  pick an element that is not painted where it claims to be — a list inside an
  `overflow:auto` scroller reports a rect far past the end of the document. On
  one production page it selected a translucent list item and painted the
  reserved band near-white directly beneath a black footer: the same seam the
  release was meant to remove. No colour is sampled any more. The room is added
  as padding on the element that already paints the bottom of the document, so
  that element's own background covers the band and the colour matches by
  construction — nothing to get stale on a dark-mode toggle, and nothing to
  mis-parse in `oklch()`/`lab()`/`color()` themes. Where no such element can be
  extended, the fallback spacer is explicitly transparent rather than coloured.
* Fix: with the banner positioned centre, the measured height was the full
  viewport (the centred banner is a full-viewport scrim), so 3.3.3 would append
  a viewport-tall band. Centre mode now reserves nothing — a full-viewport scrim
  cannot be escaped by reserving room, so the reservation bought nothing anyway.
* Fix: if the banner measured zero height on the first frame (deferred
  stylesheet, render-blocking CSS race, backgrounded tab), no room was ever
  reserved and there was no retry — a silent §7004(a)(4)(A) failure. A
  ResizeObserver now drives the reservation, which also covers reflows the
  resize event cannot see (web-font swap, button rows re-wrapping).
* Fix: the reservation is verified to have actually lengthened the document
  rather than assumed, and falls back automatically if a fixed-height or
  clipped container swallowed it.
* Fix: pages shorter than the viewport no longer get a reservation at all —
  nothing is covered there, and the band would have hung in mid-page whitespace.
* Hardened: spacer CSS armoured against unqualified theme rules that could
  delete or shrink it; spacer re-anchored to `<body>` if a theme re-parents it;
  resize handling coalesced to one pass per frame and the observer narrowed to
  direct children of `<body>`; a re-entry guard prevents a double-loaded script
  from reserving the room twice; the modal's scroll-lock now restores the prior
  value instead of clearing it.

= 3.3.3 =
* Fix: on sites with a dark footer, the room reserved for the banner rendered
  as a bright seam under the footer, and the only available workaround for it
  (recolouring the `<body>` background while the banner was up) turned whole
  sections of the page dark, hiding dark text against the recoloured canvas
  until the visitor accepted or rejected. The reserved room was `padding-bottom`
  on `<body>`; padding sits inside the body box, so it is painted by the body
  background, which CSS also propagates to the page canvas — recolouring it
  repaints the page behind every transparent section, not just the strip under
  the footer. The reservation is now a dedicated element appended after the
  content, coloured from whatever the theme actually paints at the end of the
  document. It paints only itself, the body background is never touched, and
  light and dark themes both render correctly with no per-site CSS.

= 3.3.2 =
* Fix: placing only ONE opt-out shortcode silently removed the other control
  from the entire site. `[amplifi-do-not-sell]` and `[amplifi-limit-spi]` both
  set a single shared "already rendered" flag, so a footer widget containing
  just `[amplifi-do-not-sell]` suppressed the whole auto-rendered footer row —
  and the §7014(c) "Limit the Use of My Sensitive Personal Information" control,
  which the regulations require in the header or footer, rendered nowhere.
  Placement is now tracked per control, and the auto row emits only the controls
  a shortcode has not already printed.
* Docs: corrected three statements in the source comments and this readme that
  described behaviour the code does not implement — the wp_footer priority-99
  rationale was stated backwards (a later priority loses to earlier hooks, not
  to later ones), an `auto` shortcode attribute was documented but never
  existed, and setting `optout_placement` to `banner` was suggested as a way to
  avoid duplicate controls when it actually re-renders them in the banner.

= 3.3.1 =
* Fix: renamed `assets/js/consent.js` to `assets/js/consent-v2.js` so a plugin
  update actually reaches visitors. Admin Site Enhancements'
  `disable_resource_version_number` feature strips the `?ver=` query string at
  PHP_INT_MAX priority; with a CDN serving `max-age=31536000` on the resulting
  bare asset URL, the pre-update file stays pinned for up to a year. On a live
  site this left 3.3.0's footer placement and banner-padding fix inert after a
  successful update. The stylesheet already used this `-vN` filename convention;
  the script now matches it.

= 3.3.0 =
* Change (DEFAULT BEHAVIOUR): the "Do Not Sell or Share My Personal Information"
  and "Limit the Use of My Sensitive Personal Information" controls now render
  in the page FOOTER instead of inside the consent banner and preferences
  modal. This is what the CPPA regulations actually specify. CCR tit.11
  §7013(c) (sale/share) and §7014(c) (limit sensitive PI) each require a
  conspicuous link "located at either the header or footer of the business's
  internet homepage(s)", and §7026(a)(4) states that a cookie banner "is not by
  itself an acceptable method for submitting requests to opt-out of
  sale/sharing because cookies concern the collection of personal information
  and not the sale or sharing of personal information." A footer placement is
  also permanently reachable — the banner disappears after the first choice,
  taking the opt-out controls with it, which the old placement did.
* New setting: US / CCPA -> "Where the two opt-out controls appear" —
  `footer` (default), `banner` (the pre-3.3.0 behaviour), or `both`. EXISTING
  SITES ARE MIGRATED ON UPGRADE: get_settings() array_merges the saved
  acconsent_settings row over the defaults, and a row written before 3.3.0 has
  no optout_placement key at all, so it resolves to the new `footer` default
  immediately — no admin action required, and no duplicate controls (verified
  on a clean install by deleting the key from the saved option). If you want
  the old placement, set it explicitly to `banner`.
* New shortcodes: `[amplifi-limit-spi]` (the §7014 control, which previously
  had no shortcode at all) and `[amplifi-optout-links]` (both controls in one
  wrapper). `[amplifi-do-not-sell]` is unchanged in name and behaviour.
  Placement is tracked PER CONTROL: whichever controls a shortcode prints are
  recorded, and the auto-rendered footer row then emits only the ones still
  missing (or nothing, if a shortcode placed both). Placing just
  `[amplifi-do-not-sell]` therefore does NOT delete the §7014 limit-SPI control
  from the site. The auto row is hooked at wp_footer priority 99 so it loses to
  a shortcode in the page body and to most footer widget areas / block-theme
  footer template parts; a shortcode hooked to wp_footer at a priority above 99
  runs after the row and is the one case it cannot dedupe against.
* Fix: the consent banner is `position: fixed` at the bottom of the viewport
  and was PHYSICALLY COVERING the footer opt-out controls — measured with
  `document.elementFromPoint()` at the button's own centre, the topmost
  element was the banner, so the button could not be clicked at all until the
  visitor made a consent choice first. That is the §7004(a)(4)(A) fact pattern
  ("requiring the consumer to click through disruptive screens before they are
  able to submit a request to opt-out"). While the banner is up, its measured
  height is now reserved as extra bottom padding on `<body>` (re-measured on
  resize, removed when the banner goes), so the end of the document scrolls
  clear above it.
* Fix: "Limit the Use of My Sensitive Personal Information" now writes its
  `limit_spi` record to localStorage synchronously BEFORE calling the server.
  Previously the click did nothing but fire a network request and a success
  toast — if that request failed, the visitor saw confirmation of an action
  that left no trace anywhere.
* The accessible name of the opt-out group is "Privacy opt-out controls", NOT
  "Your Privacy Choices". §7015(b) reserves that exact phrase (and "Your
  California Privacy Choices") for the single combined Alternative Opt-out
  Link, which must carry the CPPA opt-out icon and lead to a §7015(c) page.
  Using it as a label would advertise a §7015 link this plugin does not
  implement. The shortcode is `[amplifi-optout-links]` for the same reason.
* Placement guidance: prefer dropping the shortcodes directly into your theme's
  existing footer link row. §7003(c)'s operative test is that a conspicuous
  link "shall appear in a similar manner as other similarly-posted links"; the
  font-size-and-color sentence that follows it is prefaced "For example" and is
  an illustrative floor, not the whole standard. The shortcode-placed controls
  inherit type from whatever surrounds them, which clears that floor with no
  per-site CSS. The auto-rendered row is only a FALLBACK: `wp_footer()` usually
  fires AFTER the theme's own `<footer>`, so it lands at the very bottom of the
  document with no adjacent links to be similar to — it forces a contrast-safe
  colour of its own for that reason, but check it against your theme.
* Stylesheet is now `assets/css/consent-v4.css` (filename bumped so CDNs and
  page caches cannot serve the previous file; `consent-v3.css` is removed).
* Not implemented, deliberately: the §7015 "Alternative Opt-out Link" — the
  single combined link titled exactly "Your Privacy Choices" or "Your
  California Privacy Choices". That arrangement additionally requires the
  CPPA's official opt-out icon adjacent to the title ("shall include"), so it
  is a separate feature rather than a relabel of the above.
* TWO OBLIGATIONS THIS PLUGIN CANNOT SATISFY FOR YOU. Posting the links is
  necessary but not sufficient, and neither of these is automatic:
  1. Because clicking the controls takes effect immediately rather than
     opening a dedicated page, §7013(e)(1) and §7014(e)(1) require the Notice
     of Right to Opt-out and the Notice of Right to Limit to live IN YOUR
     PRIVACY POLICY — "If clicking on the ... link immediately effectuates the
     consumer's right to opt-out of sale/sharing ... the business shall provide
     the notice within its privacy policy." Each notice needs the §7013(f) /
     §7014(f) content: a description of the right and instructions for every
     method of exercising it. §7013(h) bars selling or sharing personal
     information collected during any period when no such notice was posted.
  2. §7026(a) requires TWO OR MORE designated methods for opt-out-of-sale/
     sharing requests, and §7027(b) requires two or more for limit requests.
     The link plus an honored Global Privacy Control signal covers the
     sale/sharing side. GPC is a sale/share signal only (§7025(b)) and does
     NOT count toward the limit right, so a second method for that — an email
     address, a form, a phone number — has to be offered and documented.
  The admin screen now states both of these next to the placement setting.
* Known limitation, stated plainly: these controls are `<button>` elements, not
  `<a href>` links. Civil Code §1798.135(a) and §7026(a) both say "link", and a
  button has no href, is absent from a screen reader's link rotor, cannot be
  bookmarked or deep-linked, and is inert if JavaScript is blocked. §7004(a)(5)
  requires opt-out methods be "tested to ensure that they are functional." A
  future revision should render a real link to a dedicated opt-out page and
  progressively enhance it into an immediate-effect click.
* Reminder (unchanged, but relevant when deciding whether to show these at
  all): §7013(g) and §7014(g) exempt a business from posting each link if it
  does not sell/share (resp. only uses sensitive PI for the §7027(m) purposes)
  AND says so in its privacy policy. Note that "share" under Civil Code
  §1798.140 covers cross-context behavioral advertising with or without money
  changing hands, so a site running ad-network remarketing tags generally
  cannot rely on the §7013(g) exemption.

= 3.2.1 =
* Fix: GA4 / Google Analytics measurement hosts (google-analytics.com,
  analytics.google.com) are no longer flagged as a CCPA "sale/share" in the
  default blocklist. They previously carried sale=1, which — because the
  network shim's sale-opt-out check wins over the Consent Mode allowlist by
  design — hard-blocked every GA4 hit for any GPC / "Do Not Sell" visitor,
  silently zeroing GA4 for that traffic slice even with Advanced Consent Mode
  on. A GA4 hit under Consent Mode is a cookieless, identifier-free modeling
  ping; its opt-out compliance is enforced by Consent Mode (ad_storage denied),
  not by a hard network block. Session-replay / product-analytics vendors that
  capture and disclose actual session content (Clarity, Hotjar, FullStory,
  Segment, Smartlook, Mouseflow, LogRocket, Quantum Metric, OpenReplay) KEEP
  sale=1 — those are genuine sale/share flows. NOTE: this changes only the
  seed default for NEWLY-activated sites; a site provisioned before 3.2.1 has
  its blocklist stored in acconsent_settings and must be updated per-site (drop
  the |1 sale flag from the two google-analytics lines in the saved blocklist).

= 3.2.0 =
* Feature: Google Advanced Consent Mode. A managed Google tag (GTM / gtag /
  GA4) can now be flagged "Consent Mode" on the Scripts tab so that — when the
  global Consent Mode v2 setting is on — it loads LIVE but COOKIELESS before
  consent (sending Google's anonymized "modeling pings", with the
  gtag('consent','default',{…denied…}) block keeping every identifier off),
  then upgrades to full tracking via gtag('consent','update') on accept. This
  recovers the un-consented-traffic gap Google's own Consent Mode is designed
  for, without firing any cookie pre-consent. Every OTHER managed script keeps
  hard-withholding in an inert <template> exactly as before — CM is strictly
  opt-in per script, and only meaningful for Consent-Mode-aware Google tags.
* A CM-flagged tag is emitted inside a <div data-acconsent="cm"> marker (so the
  server-side auto-block passes skip it) and its Google delivery hosts are added
  to a client network-shim allowlist that lets the cookieless pings through
  pre-consent. The allowlist YIELDS to GPC / "Do Not Sell" and "Limit Sensitive
  PI" — a visitor opt-out still withholds even the cookieless ping, so the CCPA
  sale/share and SPI protections are unchanged.
* When the global Consent Mode setting is off, or no enabled script is flagged,
  every new code path is inert and behaviour is byte-identical to 3.1.11.

= 3.1.11 =
* Fix: managed-script release was silently broken for EVERY visitor on EVERY
  site — `releaseTemplate()`'s base64-decode path read the gated payload from
  `tpl.textContent`, but per the HTML spec a browser-parsed `<template>`
  element's markup lives in the inert `.content` DocumentFragment, not in its
  own `.textContent`/`.childNodes`. `tpl.textContent` is therefore ALWAYS the
  empty string for a server-rendered gated template — every managed script
  (GTM, Marketo Munchkin, etc.) stayed inert forever, even after "Accept all"
  or Manage → Save granted every category. Now correctly reads the payload
  from `tpl.content.textContent`. Found live on ascentialmls.com (GTM +
  Munchkin never fired post-accept) but affects every site using Managed
  Scripts, since those always render with `data-acconsent-enc="base64"`.
* Fix (ascentialmls.com config only, not a code change): two managed scripts
  (GTM container, Munchkin tracker) had been incorrectly flagged
  `sale_share`/`sensitive_pi` — CCPA §1798.121-style unconditional withholding
  meant for actual Sensitive-PI/sale-of-data flows (health, financial,
  biometric, precise geolocation), not a standard analytics tag manager or
  marketing tracker. Cleared both flags so the scripts gate purely on normal
  category consent.

= 3.1.5 =
Three real-site edge cases found by installing on a live client site
(ascentialmls.com) with existing Marketo/GTM/gtag scripts already configured:
* Fix: the plugin's own consent-config script (localized `window.ACCONSENT`
  data) could be self-gated by the auto-block scanner, since that config
  legitimately lists blocklisted hostnames as plain data. When that happened,
  `window.ACCONSENT` never initialized client-side and the banner/FAB/modal
  never appeared at all, even though managed scripts were correctly
  configured. Own script tags (id="acconsent-*") are now excluded from the
  auto-block scan.
* Fix: the consent popup's root container is now forced to be a direct child
  of `<body>` on every boot, and stays there via a MutationObserver — some
  themes/plugins (off-canvas megamenus like mmenu, page-transition wrappers,
  etc.) restructure the page DOM by wrapping body content in a new container,
  which can carry a CSS `transform`/`will-change` that breaks `position:
  fixed` for anything trapped inside it. This kept the popup correctly
  anchored to the viewport even when the page's body structure changes
  dynamically after page load.
* Fix: button/FAB/close-button styling (border-radius, width, padding,
  appearance) is now `!important`-protected against same-specificity theme
  reset stylesheets and CSS frameworks (e.g. Bootstrap, hello-elementor's
  reset.css) that target `[type="button"]`/`button` element selectors and
  can silently override the consent UI's shape when they load later in the
  cascade.

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
