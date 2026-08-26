# amplifi.consent

Cookie/tracking consent for WordPress that **hard-withholds** managed scripts until the
visitor grants the matching category, keeps a server-side record of every consent event,
honors Global Privacy Control, and can auto-block unmanaged trackers added by other
plugins or the theme.

## At a glance

| | |
|---|---|
| Feature slug | `consent` (enabled via the `amplifi_plugins_enabled_features` option) |
| Version constant | `ACCONSENT_VERSION = 3.3.7` |
| Entry file | `plugins/amplifi-plugins/features/consent/ac-consent.php` |
| Text domain | `amplifi-consent` (loaded from `languages/`, template `amplifi-consent.pot`) |
| Admin page | `admin.php?page=amplifi-ac-consent` (submenu under **amplifi.studio**) |
| Admin hook suffix | `amplifi-studio_page_amplifi-ac-consent` |
| DB tables | `{$wpdb->prefix}acconsent_log` (schema version `4`) |
| wp_options | `acconsent_settings`, `acconsent_scripts`, `acconsent_cookies`, `acconsent_legal`, `acconsent_db_version`, `acconsent_token_secret`, `acconsent_log_alert` |
| REST namespace | `amplifi-consent/v1` |
| Cron event | `acconsent_daily_purge` (daily retention purge) |
| PHP LOC | 4,507 feature code + 596 bundled shared framework = 5,103 |
| Front-end assets | `assets/js/consent-v2.js` (1,048 lines), `assets/css/consent-v4.css` (332 lines) |
| Admin asset | `assets/js/admin.js` (72 lines) |
| Minimums | WordPress 6.0, PHP 7.4 |

Feature loading is gated by the mega-plugin bootstrap. `plugins/amplifi-plugins/amplifi-plugins.php`
reads `get_option( 'amplifi_plugins_enabled_features', [] )` and only `require_once`s
`features/consent/ac-consent.php` when `'consent'` is present in that array.

---

## 1. What it does, and the core differentiator

Google Consent Mode's model is "fire anyway, in anonymized mode": the tag loads and
pings the vendor regardless of consent, with identifiers suppressed. amplifi.consent
does not do that by default. A managed script is rendered into the page **inside an
inert `<template>` element**:

```html
<template class="acconsent-gated"
          data-acconsent-category="marketing"
          data-acconsent-id="scr_a1b2c3d4"
          data-acconsent-enc="base64">PGRpdiBpZD0i…</template>
```

Browsers never execute anything inside a `<template>` — including `<script src>`. Nothing
is fetched, nothing is evaluated, no DNS lookup happens. The script body is additionally
**base64-encoded** so a snippet that itself contains the string `</template>` cannot break
out of the inert container and execute pre-consent.

`consent-v2.js` re-materializes a template only when the visitor's stored consent grants
its category. Reject = the payload is never decoded, never inserted, never runs.

Advanced Consent Mode (section 7) is the deliberate, per-script opt-out from this model.

### Scope of withholding

- **Managed scripts** (Scripts tab) are always gated by category.
- **Unmanaged trackers** (added by another plugin, the theme, or hardcoded in a template)
  are gated only when **Auto-block** is on (`autoblock`, default `true`).
- Documented limits that no JS-based blocker can cover: native dynamic `import()` of a
  module from a blocklisted host, CSS-driven requests (`url()` / `@import`), and resources
  loaded inside a **cross-origin** iframe. Same-origin iframes *are* covered. A server CSP
  (`script-src` / `connect-src` / `img-src` / `style-src`) is the recommended backstop.

---

## 2. Architecture

### Bootstrap: `ac-consent.php`

Returns early if `ACCONSENT_VERSION` is already defined (double-load guard), then defines
`ACCONSENT_VERSION`, `ACCONSENT_PLUGIN_DIR`, `ACCONSENT_PLUGIN_URL`, `ACCONSENT_PLUGIN_FILE`
and requires the shared framework plus all six core classes.

The `Amplifi_Consent` singleton is instantiated on `plugins_loaded`. Its constructor:

1. Calls `amplifi_register_plugin( 'ac-consent', 'Consent', …, [ 'Amplifi_Consent_Admin', 'render_main_page' ] )`
   to add the submenu under the amplifi.studio menu.
2. Calls `Amplifi_Consent_Frontend::init()`, `Amplifi_Consent_Admin::init()`, `Amplifi_Consent_Rest::init()`.
3. Hooks `init` → `Amplifi_Consent::load_textdomain` (required because this ships via GitHub,
   not wp.org, so the .org language-pack auto-loader does not apply).
4. Hooks `init` → `Amplifi_Consent_Log::maybe_upgrade` — schema migration runs on the
   front end too, not only `admin_init`, so front-end-only/headless sites do not drop
   records in the window after an auto-update. Short-circuits on a single option compare.
5. Hooks `acconsent_daily_purge` → `Amplifi_Consent_Log::purge_expired` and schedules the
   daily event if not already scheduled.

`register_activation_hook` → `Amplifi_Consent_Store::activate`, `register_deactivation_hook`
→ `wp_clear_scheduled_hook( 'acconsent_daily_purge' )`. **See "Pitfalls" — in the mega-plugin
these two hooks are bound to the feature file path, not the registered plugin file.**

### Class responsibilities

| File | Class | Responsibility |
|---|---|---|
| `includes/class-acconsent-store.php` | `Amplifi_Consent_Store` | All option-backed persistence: settings, managed scripts, cookie catalog, versioned legal docs. Defines `categories()`, `default_settings()`, `default_blocklist()`, `parse_blocklist()`, `policy_version()`, `catalog_hash()`, `legal_snapshot()`, cookie-duration lookup table, and `activate()`. |
| `includes/class-acconsent-consent-log.php` | `Amplifi_Consent_Log` | The `wp_acconsent_log` table: `install()`/`maybe_upgrade()` (dbDelta + explicit UNIQUE-index verification), `record()`, `query()`, `count()`, `delete_by_visitor()`, `purge_expired()`, IP/UA/country privacy handling, and the write-failure alerting path (admin notice + throttled email). |
| `includes/class-acconsent-frontend.php` | `Amplifi_Consent_Frontend` | The whole visitor-facing surface: asset enqueue + `wp_localize_script`, Consent Mode defaults, gated `<template>` emission per placement, the pre-`<head>` network shim, the output-buffer auto-block passes, banner/FAB markup, CCPA opt-out controls, and every shortcode. Largest file at 1,563 lines. |
| `includes/class-acconsent-admin.php` | `Amplifi_Consent_Admin` | The five-tab admin page (Settings / Scripts / Cookies / Legal Docs / Consent Log), nonce-checked POST handling, the same-origin cookie-scanner harness (`wp_ajax_acconsent_harness`), and the consent-log write-failure admin notice. |
| `includes/class-acconsent-rest.php` | `Amplifi_Consent_Rest` | REST routes, the signed single-use consent token (issue/verify/consume), the first-party `acconsent_vid` visitor cookie, rate limiting, CSV export with formula-injection neutralization. |
| `includes/class-acconsent-webhook.php` | `Amplifi_Consent_Webhook` | Optional HMAC-SHA256-signed mirror of each receipt to an external endpoint, plus a blocking `test()` used by the admin. |
| `includes/amplifi-framework.php` | — | Bundled copy of the shared amplifi.studio framework (menu, hub, updater). Not consent-specific. |
| `uninstall.php` | — | Deletes all `acconsent_*` options, drops the log table, sweeps `_transient_acconsent_%`, and clears the purge cron. Included by the mega-plugin's root `uninstall.php`, which globs `features/*/uninstall.php`. |

### Hooks and priorities

Registered in `Amplifi_Consent_Frontend::init()`:

| Hook | Callback | Priority | Notes |
|---|---|---|---|
| `wp_enqueue_scripts` | `enqueue` | 10 | Registers/enqueues `consent-v4.css` + `consent-v2.js`, localizes `window.ACCONSENT`, adds `:root{--acconsent-accent:…}` inline style. |
| `wp_head` | `consent_mode_defaults` | **0** | Consent Mode v2 `default` block must precede any Google tag. |
| `wp_head` | `emit_head_scripts` | 1 | Gated `<template>`s with `placement=head`. |
| `wp_body_open` | `emit_body_open_scripts` | 1 | `placement=body_open`. |
| `wp_footer` | `emit_footer_scripts` | 1 | `placement=footer`. |
| `wp_footer` | `render_banner` | 50 | Emits `#acconsent-root` and the FAB button. |
| `wp_footer` | `render_footer_optout` | **99** | Deliberately late so the auto row loses to a shortcode in the page body and to most footer widget areas / block-theme footer parts. It does **not** beat a shortcode hooked to `wp_footer` above 99 — the one case it cannot dedupe against. |
| `script_loader_tag` | `gate_enqueued_script` | 10 (3 args) | Auto-block only. |
| `send_headers` | `start_buffer` | 1 | Auto-block only. Chosen because it fires **before** `template_redirect`, so the buffer is open as early as possible. |

Registered in `Amplifi_Consent_Admin::init()`:

| Hook | Callback |
|---|---|
| `admin_enqueue_scripts` | `assets` (guards on hook suffix `amplifi-studio_page_amplifi-ac-consent`) |
| `wp_ajax_acconsent_harness` | `harness` (cookie scanner; `manage_options` + `check_ajax_referer( 'acconsent_harness' )`) |
| `admin_notices` | `maybe_render_failure_notice` |

Registered in `Amplifi_Consent_Rest::init()`: `rest_api_init` → `routes`.

---

## 3. Script gating

### Managed scripts (Scripts tab)

A managed script record (`Amplifi_Consent_Store::sanitize_script()`):

| Field | Type | Notes |
|---|---|---|
| `id` | string | `sanitize_key`, auto-generated as `scr_` + 8 random chars. |
| `label` | string | Admin-facing name. |
| `category` | enum | `functional` \| `analytics` \| `marketing`. **`necessary` is deliberately excluded** — a tracking script tagged necessary would release on every load even after Reject. Anything invalid coerces to `analytics`. |
| `placement` | enum | `head` \| `body_open` \| `footer`. |
| `code` | string | Stored **verbatim** (it is markup the admin pasted); escaped only for display. |
| `enabled` | bool | Disabled scripts are neither emitted nor counted in coverage. |
| `sale_share` | bool | CCPA/CPRA "sale/share" — withheld under GPC / Do Not Sell even if the category is granted. |
| `sensitive_pi` | bool | CCPA §1798.121 — withheld unconditionally while Limit-SPI is enabled, independent of any grant. |
| `consent_mode` | bool | Advanced Consent Mode opt-in (section 7). |

`emit_for_placement()` writes each enabled script for that placement as the inert
`<template class="acconsent-gated">` shown above.

### Unmanaged trackers (Auto-block)

Two server-side passes plus a client shim:

1. **`gate_enqueued_script()`** (`script_loader_tag`) — for anything registered through
   `wp_enqueue_script()` whose `src` matches the blocklist. Uses `WP_HTML_Tag_Processor`
   when available (case-insensitive, attribute-order safe), falls back to a
   case-insensitive regex. Rewrites the tag to `type="text/plain"`, adds
   `data-acconsent-blocked="<category>"` and `data-acconsent-src="<url>"`, removes `src`.

2. **`filter_buffer()`** (output buffer opened on `send_headers`) — six regex passes over
   the rendered HTML:
   - `<script src>` pointing at a blocklisted host → `type="text/plain"` + `data-acconsent-*`.
   - Inline `<script>` bodies that *reference* a blocklisted host (gtag/fbq loader patterns).
   - `<img>` / `<iframe>` pixels → `src` renamed to `data-acconsent-src`.
   - `<link rel="preconnect|dns-prefetch|preload|prefetch|icon|shortcut icon|apple-touch-icon|apple-touch-icon-precomposed|mask-icon">`
     to a tracker host → stripped entirely (a preconnect performs DNS + TLS to the third
     party before consent, leaking the visitor IP). Handled in both attribute orders.
   - `<meta http-equiv="refresh" content="…tracker…">` → stripped (both orders).

   Every pass runs through a `$safe_replace` wrapper that returns the prior subject when
   `preg_replace_callback` returns `NULL`, so a PCRE backtrack-limit failure can never blank
   the page.

   The buffer is split at `</head>`: the **head is always fully processed**, and only the
   **body** is subject to the ~2 MB size-cap skip. Nearly all tracker tags live in head, and
   the shim must land there regardless of page size.

   Every pass carries `(?![^>]*\bid=["\']acconsent-)` so the plugin's own
   `acconsent-js-extra` localized config block — which legitimately contains blocklisted
   hostnames as plain data in `sale_share_hosts` / `spi_hosts` — is not self-gated. Without
   it, `window.ACCONSENT` never initializes and the banner, FAB and modal all disappear.

3. **The network shim** — an inline `<script data-acconsent="net-shim">` that
   `filter_buffer()` **splices as the absolute first thing inside `<head>`** by direct
   string operation, not via a `wp_head` action. A `wp_head` hook only guarantees the shim
   runs wherever the theme calls `wp_head()`; a raw tracker `<script>` hardcoded into
   `header.php` *before* that call would execute completely ungated. If no `<head>` is found,
   the shim is prepended to the buffer.

   The shim patches, per realm (top window plus every same-origin child iframe, recursively
   via `patchFrame()` → `installDomGuards()`): `fetch`, `XMLHttpRequest.open/send`,
   `Navigator.prototype.sendBeacon`, `WebSocket` / `EventSource` / `Worker` / `SharedWorker`
   constructors (returning an inert stub rather than throwing, and copying static constants
   like `WebSocket.OPEN` so libraries keep working), `serviceWorker.register`,
   `RTCPeerConnection` (filters blocklisted `iceServers` rather than blocking outright),
   the `src`/`href`/`srcset`/`data`/`poster`/`ping`/`action` property setters on the relevant
   element prototypes, `Element.prototype.setAttribute` **and** `setAttributeNS` (a separate
   method, not covered by the former), `document.write` / `writeln`,
   `Range.prototype.createContextualFragment`, `HTMLFormElement.prototype.submit` plus a
   capturing `submit` listener, and a `MutationObserver` backstop for `innerHTML`-injected
   pixels.

   Blocked resources are *neutralized*, not deleted: the URL is stashed in
   `data-acconsent-src` with the attribute name in `data-acconsent-attr`, so release is
   uniform across tags.

### Blocklist format

`acconsent_settings['blocklist']` is newline-separated `host|category|sale|spi`. Only
`host` is required.

| Segment | Default when omitted | Meaning |
|---|---|---|
| `host` | — | Substring-matched (case-insensitive) against the resource URL. |
| `category` | `marketing` | Release bucket. Only `functional` / `analytics` / `marketing` are valid; anything else coerces to `marketing`, the strictest opt-in, so an unclassified tracker fails safe. |
| `sale` | `0` | `1` = constitutes a CCPA/CPRA sale/share. Blocked under GPC / Do Not Sell **regardless of category grant**. |
| `spi` | `0` | `1` = handles Sensitive Personal Information. Blocked unconditionally while Limit-SPI is on. |

The shipped default blocklist covers GTM, GA4 (`analytics`, **not** `sale=1` — see Pitfalls),
session-replay/product-analytics vendors that keep `sale=1` (Clarity, Hotjar, Segment,
FullStory, LogRocket, Mouseflow, Smartlook, Quantum Metric, OpenReplay), ad/remarketing/B2B
de-anonymization hosts, HubSpot Leadin's `hs-scripts.com`, and support-chat widgets bucketed
as `functional` (Tawk, Zendesk, Olark, Crisp) versus sales-engagement widgets bucketed as
`marketing` (Intercom, Drift).

`default_blocklist()` carries an explicit note that `snap.licdn.com` is LinkedIn
infrastructure, not Snapchat — the real Snapchat hosts are `tr.snapchat.com` and
`sc-static.net`, listed separately.

### Release path (`consent-v2.js`)

`applyConsent( granted, saleOptOut )` runs in a specific order:

1. `window.__acconsentReleaseNetwork( granted )` — lift the network block **first**. If
   templates were released first, the still-active shim would re-block any external `src`
   set on the new nodes (re-stashing it into `data-acconsent-src`) and, because the node is
   already marked released, it would never load even after acceptance.
2. `window.__acconsentSetSaleShareOptOut( saleOptOut )`.
3. `releaseTemplate()` for every `template.acconsent-gated` whose category is granted.
4. `releaseBlocked()` for every `[data-acconsent-blocked]` node whose category is granted.
5. `updateConsentMode( granted )`.

`releaseTemplate()` reads the base64 payload from **`tpl.content.textContent`** — see
Pitfalls (c). External scripts are recreated with `async = false` and chained through a
promise so execution order is preserved; `document.write` during release is buffered into
a holder div rather than allowed to wipe the DOM.

### The `consent-v2.js` filename

The `-vN` suffix on both asset filenames is the cache-busting mechanism. **Do not rely on
`?ver=`.** Admin Site Enhancements' `disable_resource_version_number` feature strips the
`?ver=` query string at `PHP_INT_MAX` priority; with a CDN serving `max-age=31536000` on the
resulting bare asset URL, the pre-update file stays pinned for up to a year. This was
observed live on a client site 2026-08-03, where 3.3.0's footer placement and banner-padding
fixes shipped successfully but never reached visitors because `consent.js` was still being
served from cache. Renaming the file is the only bust that survives that combination. The
stylesheet already used the convention (`consent-v4.css`, with `consent-v3.css` removed);
3.3.1 renamed `consent.js` → `consent-v2.js` to match.

Because a stale full-page/CDN cache can therefore serve two generations of the script on one
render, `consent-v2.js` carries a `window.__acconsentBooted` re-entry guard and
`syncBannerPadding()` adopts an existing `.acconsent-banner-spacer` before creating one.

**When you change either asset, bump the filename and update the corresponding
`wp_register_style` / `wp_register_script` call in `Amplifi_Consent_Frontend::enqueue()`.**

---

## 4. Consent log

Table: `{$wpdb->prefix}acconsent_log`. Schema version tracked in `acconsent_db_version`,
currently `4`.

| Column | Type | Source | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | DB | Primary key. |
| `receipt_id` | `CHAR(36) NOT NULL` | **server** | UUID v4. `UNIQUE KEY`. |
| `jti` | `VARCHAR(32) NULL` | token | Single-use token id. `UNIQUE KEY` — the authoritative atomic anti-replay guard. `NULL` allowed (multiple NULLs permitted by a UNIQUE index). |
| `visitor_id` | `VARCHAR(64) NOT NULL DEFAULT ''` | **server** | Always the server-issued `acconsent_vid` cookie re-read server-side; the client-posted `visitor_id` is ignored. Indexed. |
| `event` | `VARCHAR(20)` | client (validated) | One of `grant`, `deny`, `update`, `withdraw`, `gpc`; anything else coerces to `update`. |
| `categories` | `TEXT` | client (filtered) | JSON map of known category keys → bool. `necessary` is forced `true`. Also carries `_sensitive_pi_limited` as an extra key (folded in rather than adding a column). |
| `legal_snapshot` | `TEXT` | **server / token** | JSON of every published legal doc's current version at render time. |
| `policy_version` | `VARCHAR(40)` | **token, else server** | Prefers the render-time value carried in the signed token so a cached/delayed POST attests to what the visitor actually saw. |
| `catalog_hash` | `VARCHAR(64)` | **token, else server** | Same. |
| `gpc` | `TINYINT(1)` | **server** | From `$_SERVER['HTTP_SEC_GPC'] === '1'`. Recorded even when GPC honoring is off. |
| `country` | `VARCHAR(2)` | **server** | From `CF-IPCountry`, **only when `trust_proxy` is on**. Indexed. |
| `source` | `VARCHAR(20)` | client | e.g. `banner`, `manage`, `do_not_sell`, `limit_spi`, `gpc`. |
| `ip_hash` | `VARCHAR(64)` | **server** | Privacy-preserving; see below. |
| `user_agent` | `VARCHAR(255)` | **server** | Per `ua_mode`; see below. |
| `url` | `VARCHAR(255)` | **server** | Referer reduced to scheme + host + path. Query string and fragment dropped for data minimization. |
| `created_at` | `DATETIME NOT NULL` | **server** | Site-local time. |
| `created_gmt` | `DATETIME NOT NULL` | **server** | UTC. Indexed; used for retention purge and date filters. |

Indexes: `PRIMARY (id)`, `UNIQUE receipt_id`, `UNIQUE jti`, `KEY visitor_id`,
`KEY created_gmt`, `KEY country`.

### Server-stamped vs client-supplied

The client supplies only what it legitimately knows: the category choices, the event name,
the source label, and the `sensitive_pi_limited` assertion. Everything legally significant
— time, policy/catalog version, legal snapshot, GPC, country, IP, UA, referer, subject id
— is stamped by the server or extracted from the HMAC-signed token.

### IP privacy handling (`ip_mode`)

| Mode | Behaviour |
|---|---|
| `truncate` (**default**) | IPv4: last octet replaced with `0` (`203.0.113.47` → `203.0.113.0`). IPv6: keep the first three groups, i.e. a /48 (`2001:db8:1234:…` → `2001:db8:1234::`). |
| `hash` | `hash( 'sha256', $ip . '|' . AUTH_SALT )` — salted with the site's `AUTH_SALT`, so site-unique and non-reversible. |
| `none` | Empty string; nothing stored. |

The IP read is always `REMOTE_ADDR` — the `trust_proxy` forwarded-IP logic applies only to
rate limiting and country, not to the stored `ip_hash`.

### User-Agent handling (`ua_mode`)

| Mode | Behaviour |
|---|---|
| `minimal` (**default**) | Regex-reduced to `"<Browser> <major> / <OS>"`, e.g. `Chrome 128 / macOS`. Enough to debug a disputed consent without keeping a fingerprintable string. |
| `full` | Raw UA, truncated to 255 chars. |
| `none` | Not stored. |

### Retention

`retention_days` — `0` means keep forever. Any positive value is **hard-floored at 730 days**
in `save_settings()` so an operator cannot accidentally purge below the CCPA 24-month
record-keeping minimum (§7101). New installs default to `1095` (3 years). `purge_expired()`
runs daily via `acconsent_daily_purge` and deletes rows with `created_gmt < now - N days`.

### Schema self-heal and write-failure alerting

`install()` runs `dbDelta`, then **explicitly verifies and creates the `UNIQUE KEY jti`**,
because dbDelta is unreliable at adding a UNIQUE key to an existing table. It deduplicates
any pre-existing non-NULL `jti` rows first, re-checks immediately before `ALTER` (racing
request), and suppresses errors so a lost race is quiet. It only stamps
`acconsent_db_version` **after** confirming the index exists — if the `ALTER` failed
(insufficient privileges, engine quirk), the version stays unbumped so `maybe_upgrade()`
retries on a later request rather than silently degrading the single-use guard to the
non-atomic transient pre-check.

`record()` re-checks for `legal_snapshot`, `jti`, the `jti` index, and `country` on every
write and calls `install()` + retries if any is missing.

A genuine write failure (not a duplicate-`jti` replay, which is expected) accumulates in the
`acconsent_log_alert` option: `count`, `first_failed_at`, `last_failed_at`, `last_error`,
`emailed_at`. At 3+ failures an `admin_notices` error is shown; at 5+
(`ALERT_THRESHOLD`) the site admin is emailed, at most once per day. The alert is deleted
on the next successful write.

---

## 5. GPC (Global Privacy Control)

Two independent halves.

**Server side** — `Amplifi_Consent_Log::gpc_present()` reads `$_SERVER['HTTP_SEC_GPC']` and
stamps `gpc = 1` on every recorded event when it equals `'1'`. This happens regardless of
the `gpc_enabled` setting; turning the setting off stops GPC from forcing a deny, but the
signal is still recorded for audit.

**Client side** — `gpcActive()` returns true when `gpc_enabled` is on **and**
`navigator.globalPrivacyControl === true`. When active, `boot()`:

1. Writes a full deny to localStorage with `sale_share_opt_out: true`.
2. Applies it (`applyConsent`), which also tells the network shim about the sale/share opt-out.
3. Records a server event with `event = 'gpc'`, `source = 'gpc'` — but only **once per
   policy/catalog version**, keyed by a localStorage marker
   `acconsent_gpc_<policy_version>_<catalog_hash>`. The deny is still applied on every load;
   only the log write is deduplicated, so the audit trail stays clean and the first-opt-out
   timestamp stays meaningful.
4. Returns early — the banner is never shown.

**GPC override is closed.** In `commit()`, an active GPC signal forces
`categories.marketing = false` and `saleOptOut = true`, and downgrades an `event` of
`grant` to `update`. A visitor with GPC active who clicks "Accept all" cannot re-enable
marketing/sale-share in-session, and the click is never recorded as a grant.

---

## 6. CCPA/CPRA opt-out controls

Two independent statutory controls:

- **Do Not Sell or Share My Personal Information** — CCR tit. 11 §7013, Civil Code §1798.135.
- **Limit the Use of My Sensitive Personal Information** — §7014, Civil Code §1798.121.

### Placement

`optout_placement` — `footer` (default) | `banner` | `both`.

Footer is the default because §7013(c) and §7014(c) each require a conspicuous link
"located at either the header or footer of the business's internet homepage(s)", and
§7026(a)(4) states that a cookie banner "is not by itself an acceptable method for
submitting requests to opt-out of sale/sharing." A footer placement is also permanently
reachable; the banner disappears after the first choice, taking the controls with it.

Existing sites migrated automatically on upgrade to 3.3.0: `get_settings()` `array_merge`s
the saved row over the defaults, and a row written before 3.3.0 has no `optout_placement`
key at all, so it resolves to the new `footer` default with no admin action.

`optout_placement()` **forces `both` when `position === 'center'` and placement is `footer`.**
A centred banner is a full-viewport fixed scrim covering the footer at every scroll offset,
so a footer-only opt-out would be unreachable until the consent UI is dismissed — the
§7004(a)(4)(A) click-through fact pattern. Reserving room cannot help, because there is no
uncovered strip to scroll into. Enforced in code, not left to the admin UI, because either
setting can be changed independently and the failure is invisible from the front end.

### The auto-rendered footer row

`render_footer_optout()` on `wp_footer` priority 99 emits:

```html
<div class="acconsent-footer-optout acconsent-footer-optout-auto"
     role="group" aria-label="Privacy opt-out controls">
  <button type="button" class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-donotsell>…</button>
  <button type="button" class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-limitspi>…</button>
</div>
```

The accessible name is deliberately **not** "Your Privacy Choices". §7015(b) reserves that
exact phrase (and "Your California Privacy Choices") for the single combined Alternative
Opt-out Link, which must carry the CPPA opt-out icon and lead to a §7015(c) page. Using it
as a label would advertise a §7015 link this feature does not implement.

The auto row is a **fallback**. `wp_footer()` usually fires after the theme's own `<footer>`,
so it lands at the very bottom of the document with no adjacent links to be similar to —
which is why it forces a contrast-safe colour of its own. Prefer dropping the shortcodes
into the theme's real footer link row, where they inherit type from their neighbours.

### Per-control placement tracking

`Amplifi_Consent_Frontend` keeps **two** private static flags, `$optout_rendered_dns` and
`$optout_rendered_spi`. Each shortcode marks only what it actually printed, and
`render_footer_optout()` calls `optout_controls_html( 'footer', true )` so the auto row emits
only the controls a shortcode has *not* already placed — or nothing at all when a shortcode
placed both. Placing just `[amplifi-do-not-sell]` therefore does **not** delete the §7014
limit-SPI control from the site. (Prior to 3.3.2 both shortcodes set one shared flag; see
Pitfalls.)

### Behaviour on click

Both controls are delegated-click handled in `boot()` via
`e.target.closest('[data-acconsent-donotsell]')` / `[data-acconsent-limitspi]`.

`doNotSell()` sets `marketing = false`, keeps other categories, writes localStorage with
`sale_share_opt_out: true`, applies it, records `event='deny', source='do_not_sell'`, removes
the banner and toasts.

`limitSensitivePI()` writes `limit_spi: true` + `limit_spi_ts` to localStorage
**synchronously first**, then calls the server. Before 3.3.0 the click only fired a network
request and a success toast — if that request failed the visitor saw confirmation of an
action that left no trace anywhere. §7014(a) contemplates the click having an immediate
effect. Note that release-blocking of SPI-flagged items is *unconditional* whenever
`limit_spi_enabled` is on; the click exercises and **records** the right rather than changing
what is blocked.

### Known limitation, stated in the source

These are `<button>` elements, not `<a href>` links. §1798.135(a) and §7026(a) both say
"link"; a button has no href, is absent from a screen reader's link rotor, cannot be
bookmarked or deep-linked, and is inert if JavaScript is blocked. §7004(a)(5) requires
opt-out methods be "tested to ensure that they are functional." A future revision should
render a real link to a dedicated opt-out page and progressively enhance it.

The §7015 Alternative Opt-out Link is **not implemented, deliberately** — that arrangement
additionally requires the CPPA's official opt-out icon adjacent to the title.

---

## 7. Google Consent Mode

### Consent Mode v2 defaults

When `consent_mode` is on, `consent_mode_defaults()` prints on `wp_head` priority 0:

```html
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
```

On grant, `updateConsentMode()` fires `gtag('consent','update', …)` mapping
`marketing` → `ad_storage` / `ad_user_data` / `ad_personalization`, `analytics` →
`analytics_storage`, and `functional` → `functionality_storage` / `personalization_storage`.

### Advanced Consent Mode (per-script)

`cm_active()` returns true only when the global `consent_mode` setting is on **and** at least
one enabled managed script carries `consent_mode = true`. When false, every CM code path is
inert and behaviour is byte-identical to the hard-withholding default.

For a CM-flagged script, `emit_for_placement()` emits the tag **live** instead of gated:

```html
<div data-acconsent="cm" data-acconsent-id="scr_…"> …vendor tag verbatim… </div>
```

Because the `gtag('consent','default',{…denied…})` block is already in place, the tag loads
cookieless and sends Google's anonymized modeling pings, then upgrades on
`gtag('consent','update')` at accept.

Two supporting mechanisms:

- `filter_buffer()` **extracts** every `<div data-acconsent="cm">…</div>` region into
  placeholders *before* the auto-block passes run and splices them back verbatim at the end.
  The wrapper's own `data-acconsent` attribute is not enough, because the passes match the
  **inner** tag — the inner `<script>` body legitimately contains `googletagmanager.com`, so
  the inline-body pass would rewrite it to `type="text/plain"` and the tag would never load.
- `cm_hosts()` builds a client-shim allowlist by scanning each enabled CM-flagged script's
  body for known Consent-Mode-aware Google delivery hosts (`googletagmanager.com`,
  `google-analytics.com`, `analytics.google.com`, `g.doubleclick.net`,
  `region1.google-analytics.com`, `region1.analytics.google.com`). Only Google's own tag
  infrastructure is ever allowlisted — never an arbitrary third party. When
  `googletagmanager.com` is present, the GA collect endpoints are added unconditionally,
  because GTM injects GA4 which pings hosts that do not literally appear in the container
  loader snippet.

**The CM allowlist yields to opt-out.** In the shim's `blocked()`, `saleBlocked(url) ||
spiBlocked(url)` is checked *before* the CM allowlist, so a GPC / Do-Not-Sell / Limit-SPI
visitor still gets even the cookieless ping withheld. Compliance wins over modeling.

Flagging a non-Consent-Mode-aware tracker here would simply make it fire un-gated; the
admin UI documents that this is for Google tags.

---

## 8. Legal documents

Stored in the `acconsent_legal` option, shaped as:

```
[ doc_id => [
    'id', 'slug', 'title',
    'type'     => 'privacy' | 'terms' | 'cookie' | 'custom',
    'versions' => [ [ 'version', 'content', 'published_at' ], … ]   // append-only, newest last
] ]
```

- `save_legal_doc()` creates/updates metadata only (title/slug/type); it never adds a version.
- `publish_legal_version( $id, $version_label, $content, $published_at = '' )` appends an
  immutable version. An empty label auto-increments to `v1`, `v2`, …. Content passes through
  `wp_kses_post()`. `$published_at` defaults to `current_time( 'mysql', true )`.
- `current_version( $doc )` is `end( $doc['versions'] )`.
- `legal_snapshot()` returns `[ doc_id => [ title, slug, type, version, url ] ]` for every
  **published** doc — unpublished docs are not part of the consent record.
- `legal_doc_url()` resolves a best-effort public URL by searching published pages/posts for
  one containing both `amplifi-legal-doc` and the doc slug, cached for 5 minutes in the
  transient `acconsent_legal_url_<slug>`.

Publishing a new version changes `catalog_hash()`, which invalidates stored client consent
and re-prompts returning visitors against the new text.

### `[amplifi-legal-doc]`

| Arg | Default | Effect |
|---|---|---|
| `slug` | `''` | Resolve the doc by slug. |
| `id` | `''` | Resolve by doc id. Takes precedence over `slug`. |
| `show_version` | `"true"` | Any value other than the literal string `"false"` renders the `<p class="acconsent-legal-meta">Version %1$s — effective %2$s</p>` line, date formatted with the site's `date_format`. |
| `show_title` | `"true"` | `"false"` suppresses the shortcode's own `<h2 class="acconsent-legal-title">`. Use this on templates that already print the page title as `<h1>`, which is the common case — otherwise the heading is visibly duplicated. |

Both flags test `'false' !== $atts[…]`, so only the exact string `false` disables them.

Output shell:

```html
<div class="acconsent-legal-doc" data-doc="privacy-policy">
  <h2 class="acconsent-legal-title">…</h2>
  <p class="acconsent-legal-meta">Version v2 — effective January 4, 2026</p>
  <div class="acconsent-legal-body">…wp_kses_post( content )…</div>
</div>
```

Returns an empty string when the doc does not exist or has no published version.

**Migration note.** `$published_at` exists specifically for migrating *existing* legal
content whose body text already states its own effective date. Stamping "now" on unchanged
text is misleading — the rendered "Version v1 — effective &lt;today&gt;" line would
contradict the document's own stated date. Pass the real historical date instead.

---

## 9. REST API

Namespace: `amplifi-consent/v1` (`Amplifi_Consent_Rest::NS`).

| Method | Route | Permission | Purpose |
|---|---|---|---|
| `GET` | `/config` | `__return_true` (public) | Read-only consent config. Sets the first-party visitor cookie and mints a fresh visitor-bound token. Rate-limited at 300/min per IP. Sends `nocache_headers()` plus an explicit `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`. |
| `POST` | `/consent` | `__return_true`, validated in-callback | Records a consent event and mirrors it to the webhook. Requires a valid, visitor-bound, single-use signed token. |
| `GET` | `/export` | `current_user_can( 'manage_options' )` | CSV (default) or JSON export of the log. |
| `GET` | `/visitor/(?P<visitor_id>[a-zA-Z0-9\-]+)` | `manage_options` | DSAR lookup: every row for one visitor id (limit 1000). |
| `DELETE` | `/visitor/(?P<visitor_id>…)` | `manage_options` | DSAR erasure: permanently deletes every row for that visitor id, returns `{ deleted: N }`. |

### `GET /config` response

`enabled`, `consent_days`, `policy_version`, `catalog_hash`, `gpc_enabled`, `categories`,
`cookies` (grouped by category, `unclassified` excluded), `legal` (the snapshot), and
`token`. Intentionally excludes script bodies and any secret.

### `POST /consent` auth chain

1. `verify_token()` — base64url decode, HMAC-SHA256 signature check with `hash_equals`,
   payload must carry a numeric `t`, and the token must be ≤ 2 hours old and not more than
   300 s in the future. Failure → `403 acconsent_auth`.
2. Visitor binding — the token's `vh` must `hash_equals` the HMAC of the `acconsent_vid`
   cookie presented on this request. Failure → `403 acconsent_vid`. The client-posted
   `visitor_id` is ignored entirely.
3. Rate limit — checked **before** the token is consumed, so a 429'd client can retry with
   the same token: 120/min per IP (hard ceiling) plus a tighter 20/min per visitor.
   Failure → `429 acconsent_rate`.
4. `consume_jti()` — `wp_cache_add` (atomic first-writer-wins) when a persistent object
   cache is present, transient otherwise. Failure → `409 acconsent_replay`. The DB's
   `UNIQUE KEY jti` is the authoritative guard; this is an optimization.

A bare `wp_rest` nonce is **deliberately not accepted** — that nonce is published to every
anonymous visitor, so accepting it would allow log rows that bypass visitor binding,
single-use, and render-version binding.

### Token format

`base64url( payloadJson . '.' . hmac_sha256( payloadJson, secret ) )` where the secret is
`get_option( 'acconsent_token_secret' )` (64 chars, auto-generated on first use, autoload
off) concatenated with `AUTH_SALT`.

Payload keys: `t` (issued-at), `j` (random 20-char jti), `pv` (policy version), `ch`
(catalog hash), `ls` (legal snapshot), `vh` (truncated 32-char HMAC of the visitor cookie;
omitted for an unbound render-time token).

### Visitor cookie

`acconsent_vid` — UUID v4, 1 year, `path` from `COOKIEPATH`, `domain` from `COOKIE_DOMAIN`,
`secure` when `is_ssl()`, **`HttpOnly`**, `SameSite=Lax`. Set from the uncached `/config`
endpoint so full-page caching cannot suppress it. HttpOnly is safe because the browser JS
never needs to read it — the server re-reads it on each POST.

### CSV export

Columns: `id, receipt_id, visitor_id, event, categories, legal_snapshot, policy_version,
catalog_hash, gpc, country, source, ip_hash, user_agent, url, created_gmt`.

Query params: `format` (`csv`|`json`), `per_page` (default 1000, capped 5000), `offset`,
`visitor_id`, `date_from` / `date_to` (`Y-m-d`, inclusive), `country`.

`csv_cell()` neutralizes spreadsheet formula injection: a cell starting with `=`, `+`, `-`,
`@`, tab or CR is prefixed with a single quote, so a tracker-set User-Agent cannot execute
a formula when an admin opens the export.

---

## 10. Webhook

`Amplifi_Consent_Webhook::dispatch( $receipt )` is called from `post_consent()` **after** the
DB write, so a webhook failure never costs the visitor a recorded consent. No-op unless both
`webhook_enabled` and `webhook_url` are set.

Request: `POST`, `timeout: 5`, **`blocking: false`** (fire-and-forget; the visitor's request
is never held up), `redirection: 0`, `sslverify: true`.

Body:

```json
{ "type": "consent.recorded", "site": "https://example.com", "receipt": { … } }
```

Headers: `Content-Type: application/json`, `User-Agent: amplifi.consent/<ACCONSENT_VERSION>`,
and — when `webhook_secret` is set — `X-Amplifi-Consent-Signature: sha256=<hmac>` where the
HMAC is `hash_hmac( 'sha256', $body, $secret )` over the exact serialized body.

`Amplifi_Consent_Webhook::test()` sends `{"type":"consent.test","site":…,"time":…}` with the
same signature header, but **blocking** with a 10 s timeout, and returns
`[ 'ok' => bool, 'message' => string ]` for the admin's "Send test" button.

When a webhook is configured, the banner and modal disclose it to visitors via
`S.webhook_disclosure` ("Consent records may also be sent to a data processor configured by
this site, which may be located in a different country.").

---

## 11. Reference tables

### Shortcodes

| Shortcode | Args | Output |
|---|---|---|
| `[amplifi-consent-manager]` | `label` (default *Manage cookie preferences*) | `<button class="acconsent-manage-trigger" data-acconsent-open>` — reopens the preferences modal. |
| `[amplifi-legal-doc]` | `slug`, `id`, `show_version`, `show_title` | Versioned legal doc; see section 8. |
| `[amplifi-do-not-sell]` | none | `<button class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-donotsell>`. Marks `$optout_rendered_dns`. |
| `[amplifi-limit-spi]` | none | `<button class="acconsent-optout-btn acconsent-optout-btn-footer" data-acconsent-limitspi>`. Marks `$optout_rendered_spi`. |
| `[amplifi-optout-links]` | none | Both controls wrapped in `<span class="acconsent-footer-optout" role="group" aria-label="Privacy opt-out controls">`. Marks both flags. |

All except `[amplifi-optout-links]` return `''` when the feature is disabled or the
relevant setting/label is empty.

### `acconsent_settings` keys

| Key | Default | Validation | Notes |
|---|---|---|---|
| `enabled` | `true` | bool | Master switch. |
| `banner_title` | *We value your privacy* | text | |
| `banner_message` | *(long default)* | text | |
| `accept_label` / `reject_label` / `manage_label` / `save_label` | *Accept all* / *Reject all* / *Manage* / *Save choices* | text | Accept and Reject carry equal visual weight by design. |
| `toast_accepted` / `toast_rejected` | *(defaults)* | text | |
| `consent_days` | `180` | clamped 1–365 | localStorage TTL. |
| `accent_color` | `#4db6ac` | 3/6-digit hex | Emitted as `--acconsent-accent`. Only tints the category checkboxes. |
| `position` | `bottom` | `bottom` \| `center` | Bottom bar vs centred modal. |
| `privacy_url` | `''` | `esc_url_raw` http/https | Shown on the banner pre-choice. |
| `prefs_label` | *Cookie preferences* | text | FAB `aria-label` and `title`. |
| `floating_button` | `true` | bool | The persistent withdrawal trigger (GDPR Art. 7(3)). |
| `fab_position` | `left` | `left` \| `right` | Which bottom corner the FAB docks to. Independent of `position`. |
| `policy_version` | `'1'` | text, ≤40 chars, `''` → `'1'` | Bump to force everyone to re-consent. |
| `ip_mode` | `truncate` | `hash` \| `truncate` \| `none` | See section 4. |
| `retention_days` | `1095` | `0`, else floored at 730 | See section 4. |
| `trust_proxy` | `false` | bool | Enables `CF-Connecting-IP` / `X-Forwarded-For` for rate limiting and `CF-IPCountry` for `country`. Off by default — XFF is spoofable on a direct-connect origin. |
| `ua_mode` | `minimal` | `full` \| `minimal` \| `none` | Invalid values coerce to `minimal`. |
| `webhook_url` | `''` | `esc_url_raw` http/https | |
| `webhook_secret` | `''` | text | HMAC key. |
| `webhook_enabled` | `false` | bool | |
| `gpc_enabled` | `true` | bool | Honor GPC as an opt-out. GPC is recorded either way. |
| `consent_mode` | `false` | bool | Google Consent Mode v2 defaults + updates. |
| `autoblock` | `true` | bool | Gate unmanaged trackers by domain. |
| `blocklist` | *(see section 3)* | per-line `host\|category\|sale\|spi` | Lowercased, `https?://` stripped, chars outside `[a-z0-9.\-/_]` removed, deduplicated. |
| `do_not_sell` | `true` | bool | |
| `dns_label` | *Do Not Sell or Share My Personal Information* | text | Empty label suppresses the control. |
| `limit_spi_enabled` | `true` | bool | Also controls whether SPI-flagged items are withheld at all. |
| `limit_spi_label` | *Limit the Use of My Sensitive Personal Information* | text | |
| `optout_placement` | `footer` | `footer` \| `banner` \| `both` | Forced to `both` when `position === 'center'`. |

`get_settings()` `array_merge`s the saved row over `default_settings()`, so a key absent
from an older saved row picks up the current default with no migration step.

### Other options

| Option | Contents |
|---|---|
| `acconsent_scripts` | Managed scripts, keyed by script id. |
| `acconsent_cookies` | Cookie catalog, deduplicated by cookie name. Fields: `name`, `category` (categories plus `unclassified`), `script_id`, `domain`, `duration`, `description`. Detected cookies default to `unclassified` and are **not disclosed** in the Manage UI or `/config` until an admin reviews them. |
| `acconsent_legal` | Versioned legal documents. |
| `acconsent_db_version` | `'4'` when the log schema is fully migrated. |
| `acconsent_token_secret` | 64-char token HMAC secret, autoload off. |
| `acconsent_log_alert` | Standing write-failure alert; deleted on the next successful write. |

### localStorage keys (front end)

| Key | Contents |
|---|---|
| `acconsent_v1` (`CFG.storage_key`) | `{ ts, expires, categories, sale_share_opt_out, policy_version, catalog_hash }`, plus `limit_spi` / `limit_spi_ts` after a Limit-SPI click. Read is invalidated when `policy_version` or `catalog_hash` no longer match the server. |
| `acconsent_vid` | Last-resort visitor-id fallback when cookies are blocked. The server never trusts it. |
| `acconsent_gpc_<policy_version>_<catalog_hash>` | Marker so a GPC event is logged once per version, not per pageview. |

### Data attributes

| Attribute | Where | Meaning |
|---|---|---|
| `data-acconsent="net-shim"` / `"consent-mode"` / `"cm"` | plugin-emitted nodes | Excluded from every auto-block pass. |
| `data-acconsent-category` | gated `<template>` | Release bucket. |
| `data-acconsent-id` | gated `<template>`, CM wrapper | Managed-script id, matched against `sale_share_scripts` / `spi_scripts`. |
| `data-acconsent-enc="base64"` | gated `<template>` | Payload encoding. Always set for managed scripts. |
| `data-acconsent-blocked` | auto-blocked node | Release category. |
| `data-acconsent-src` | auto-blocked node | Stashed URL. |
| `data-acconsent-attr` | auto-blocked node | Which attribute to restore (`src`/`href`/`srcset`/…). Defaults to `src`. |
| `data-acconsent-released="1"` | any released node | Idempotency guard. |
| `data-acconsent-open` | any element | Delegated: opens the preferences modal. |
| `data-acconsent-donotsell` / `data-acconsent-limitspi` | opt-out buttons | Delegated click handlers. |

### CSS classes commonly overridden by site builders

| Class | Element |
|---|---|
| `.acconsent-fab`, `.acconsent-fab-left`, `.acconsent-fab-right` | The persistent floating preferences button. Heavily `!important`-armoured — see section 12. |
| `.acconsent-cookie-icon` | Inline SVG inside the FAB (22×22, `currentColor`). |
| `.acconsent-banner`, `.acconsent-banner-inner`, `.acconsent-pos-bottom`, `.acconsent-pos-center` | Banner frame, card, and position modifiers. |
| `.acconsent-banner-title`, `.acconsent-banner-msg`, `.acconsent-banner-btns` | Banner internals. |
| `.acconsent-banner-spacer` | The reserved band appended to `<body>`. Height set inline from JS; the CSS is armoured against unqualified theme rules. |
| `.acconsent-btn` + `-primary` / `-secondary` / `-link` / `-outline` | Consent UI buttons. |
| `.acconsent-modal-overlay`, `.acconsent-modal`, `.acconsent-modal-close`, `.acconsent-modal-title`, `.acconsent-modal-msg`, `.acconsent-modal-btns` | Preferences modal. |
| `.acconsent-cat`, `.acconsent-cat-list`, `.acconsent-cat-head`, `.acconsent-cat-label`, `.acconsent-cat-toggle`, `.acconsent-cat-name`, `.acconsent-cat-desc`, `.acconsent-cat-cookies` | Per-category rows. |
| `.acconsent-cookie-tbl` | Cookie disclosure table. |
| `.acconsent-toast` (`.show`) | Confirmation toast. |
| `.acconsent-manage-trigger` | `[amplifi-consent-manager]` button. |
| `.acconsent-legal-links`, `.acconsent-legal-ref` | Pre-consent disclosure line on the banner/modal. |
| `.acconsent-optout-links`, `.acconsent-optout-btn` | Opt-out controls when rendered inside the banner/modal. |
| **`.acconsent-footer-optout`** | Wrapper for both footer opt-out arrangements. |
| **`.acconsent-footer-optout-auto`** | The **auto-rendered** row only. Forces `color: CanvasText; background: Canvas` because it has no adjacent links to inherit from; a shortcode placement never gets this class. |
| **`.acconsent-optout-btn-footer`** | The footer opt-out buttons. Deliberately *not* `!important`-armoured beyond the UA button-chrome reset, so they lose to the theme's footer link styling — which is what keeps them "similar in manner" to their neighbours per §7003(c). |
| `.acconsent-legal-doc`, `.acconsent-legal-title`, `.acconsent-legal-meta`, `.acconsent-legal-body` | `[amplifi-legal-doc]` output. |

Two names have no stylesheet rule and exist purely as hooks:
`.acconsent-webhook-disclosure` (emitted by `legalLinksHtml()`) and the
`acconsent-banner-open` class that `syncBannerPadding()` toggles on
`document.documentElement`.

---

## 12. Styling and overrides

The consent UI is deliberately theme-proof: scoped class names, a forced `--acconsent-font`
stack, and `!important` on box-model/appearance properties. That is a response to a real
failure mode — theme and framework resets target `button` / `[type="button"]` element
selectors, which are the **same specificity (0,1,0)** as a single class selector, so whichever
stylesheet loads later wins on a tie, and the theme usually enqueues after plugins. On
`ascentialmls.com`, `hello-elementor`'s `reset.css` and Bootstrap both silently overrode
`.acconsent-fab`'s `border-radius` and `width` with no `!important` on either side.

### Moving the FAB

`.acconsent-fab` is positioned entirely with `!important`:

```css
.acconsent-fab {
  position: fixed !important; left: 16px !important; bottom: 16px !important;
  z-index: 2147482000 !important;
  width: 44px !important; height: 44px !important; min-width: 44px !important;
  border-radius: 50% !important; …
  display: flex !important; align-items: center !important; justify-content: center !important;
}
.acconsent-fab-right { left: auto !important; right: 16px !important; }
```

The admin **Floating button side** setting (`fab_position`) covers left vs right. When you
need something else — most commonly moving it up so it does not collide with an
accessibility widget, a chat bubble, or a back-to-top button in the same corner — you must
override from CSS that lands **after** `consent-v4.css` in the cascade and use `!important`
yourself. The reliable place is a late `wp_head` hook in the theme or a site-specific plugin:

```php
add_action( 'wp_head', function () {
    if ( is_admin() ) {
        return;
    }
    ?>
    <style id="site-acconsent-fab-position">
      /* Sit above the accessibility widget in the same corner. */
      .acconsent-fab { bottom: 88px !important; left: 16px !important; }
      @media (max-width: 782px) {
        .acconsent-fab { bottom: 80px !important; }
      }
    </style>
    <?php
}, 99 );
```

Notes:

- `display: flex !important` on `.acconsent-fab` is load-bearing. It was added because the
  icon rendered off-centre when the rule lacked `!important` and a theme reset won the tie.
  Do not override `display` unless you also re-centre the SVG.
- `boot()` force-moves both `#acconsent-root` **and** the `.acconsent-fab` button to be direct
  children of `<body>` on every boot, and keeps them there with a `MutationObserver` watching
  `childList` on `<body>` (no `subtree` — the invariant is only about direct children).
  Off-canvas menu libraries (mmenu, jet-menu), page-transition wrappers and similar restructure
  the DOM by wrapping body content in a container that commonly carries `transform`,
  `will-change: transform`, `filter` or `contain`, any of which creates a new containing block
  for `position: fixed` descendants. Do not add a wrapper and expect the FAB to stay inside it.
- The `.acconsent-banner-spacer` gets the same rescue treatment, because a wrapper with
  `overflow: hidden` or a fixed height would stop its height contributing to document scroll
  height and silently delete the reserved room.
- The banner, spacer, FAB and modal overlay are all hidden in `@media print`. The footer
  opt-out row is real page content and still prints.
- **Keep `consent-v4.css` ASCII-only.** See Pitfalls (b).

---

## 13. Pitfalls

Drawn from the commit history of `plugins/amplifi-plugins/features/consent/`.

### (a) Reserving banner room with `padding-bottom` on `<body>` repaints the page canvas

*Introduced 3.3.0, broken through 3.3.2, fixed 3.3.3 (`47e1e40`), settled 3.3.5–3.3.7.*

The banner is `position: fixed` at the bottom of the viewport and was physically covering
the footer opt-out controls — measured with `document.elementFromPoint()` at the button's own
centre, the topmost element was the banner, so the button could not be clicked at all until
the visitor made a consent choice. That is the §7004(a)(4)(A) fact pattern.

The first fix reserved the banner's measured height as `padding-bottom` on `<body>`. **Padding
lives inside the body box, so it is painted by the body background — and CSS propagates the
body background to the page canvas.** On a light-`<body>` site with a dark theme footer, the
band rendered as a bright seam below the footer; the obvious workaround (darkening the body
background while the banner was up) repainted the canvas behind *every* transparent section of
*every* page. In production on `asctmprd`, `/leadership` rendered the entire executive grid
black-on-black for un-consented visitors — 405 sampled points across 2450 px of that page
painted from the body background.

The reservation is now a dedicated `<div class="acconsent-banner-spacer">` appended to
`<body>`, which paints only its own box. Three intermediate attempts to make that band *match*
the page are all deleted, and each is worth knowing so nobody re-invents them:

- **3.3.3** sampled a colour from the bottom-most painted element. The scan picked a
  translucent list item inside an `overflow: auto` scroller whose rect reported 3458 px past
  the end of the document, painting a near-white band under a black footer.
- **3.3.4** padded the theme's own trailing element instead. That mutates an element the
  plugin does not own — a footer re-render, a third-party inline style write, or a
  `cloneNode()` can each corrupt it or silently drop the reservation — and it still mis-painted
  under `background-clip: content-box` and whenever the found element was narrower than the
  viewport.
- **3.3.5/3.3.6** established the real cause: the banner's outer box is 16 px larger than its
  card on every side and **that frame was fully transparent**, so the deliberately blank band
  showed through it as a pale halo (measured RGB ~246 desktop / ~239 mobile against a black
  footer). Painting the frame with the card's surface tint plus a backdrop blur fixes it at
  source. The blur is load-bearing, not decoration — making that frame clear again reintroduces
  the seam.

Two more traps in the same code:

- **A hit test cannot see this class of bug.** `elementFromPoint` reports the banner as
  covering the area because the element *is* there; it simply was not painting. Only a pixel
  measurement finds it. Four rounds of verification missed it for that reason.
- **`documentElement.scrollHeight` is clamped to the viewport height** — a 101 px page reports
  the full 700 px on a 700 px window. Using it for the short-page check made the reserve and
  release branches disagree on consecutive frames, inserting and removing a DOM node every
  animation frame indefinitely. `contentHeight()` now measures body's unclamped border-box
  height with 2 px of hysteresis at the boundary.

The band is sized `ceil(h) + 1` — one pixel **over**, not under. 3.3.5's `h - 1` existed to
stop a sliver of a blank band peeking above the banner, which was reasoning about a transparent
frame that 3.3.6 removed. With the frame painted, erring over is strictly safer, and fractional
device-pixel ratios (1.25 / 1.5 / 2.75) make a CSS-pixel-exact band land short.

### (b) A single non-ASCII character in a CSS comment ate the rest of the stylesheet

*Fixed in `d2b8773` (3.3.6).*

Adding `background` to `.acconsent-banner` appeared to do nothing in production. The rule
parsed, but the declaration was silently dropped. The stylesheet is served as `text/css`
**with no charset parameter**, so the em-dash in the comment immediately above that rule was
decoded as latin-1 and swallowed the declarations that followed it — `background` and
`font-family` both vanished from the computed style while the rest of the rule applied
normally. Confirmed by parsing the live file through `CSSStyleSheet.replace()` and diffing
the resulting `cssText`.

Fixed structurally, three ways: `@charset "UTF-8";` is now the first line of the file; all
15 non-ASCII characters (em-dashes, curly quotes, section signs) were replaced with ASCII
equivalents; and the rule carries an in-place comment telling the next editor to keep it
ASCII and why. **If you edit `consent-v4.css`, keep it ASCII-only** — including in comments.
Write `Sec.7013` rather than `§7013`, and `-` rather than `—`.

### (c) `tpl.textContent` is always empty, so managed-script templates never released

*Fixed in `ad26bb2` (3.1.11).*

`releaseTemplate()`'s base64-decode path read the gated payload from `tpl.textContent`. Per
the HTML spec, a browser-parsed `<template>` element's markup lives in the inert `.content`
DocumentFragment, **not** in the element's own `.textContent` / `.childNodes` — so
`tpl.textContent` is *always* the empty string for a server-rendered gated template.

Because managed scripts always render with `data-acconsent-enc="base64"`, this silently
no-op'd managed-script release for **every visitor on every site**, regardless of category
grant. "Accept all" and Manage → Save both went through the same
`commit() → applyConsent() → releaseTemplate()` path and released nothing.

Found live on `ascentialmls.com`, where GTM (`GTM-PR9XSHG3`) and Marketo Munchkin
(`325-BPD-091`) never fired post-accept. It had been masked in earlier debugging by two
independent config mistakes on that site: both scripts had been incorrectly flagged
`sale_share` / `sensitive_pi`, which also blocked them. Clearing those flags exposed the real
bug underneath.

The fix is one line — read from `tpl.content.textContent`. The lesson is the debugging one:
two overlapping causes, and the config-level one was found first and looked sufficient.

### (d) One shortcode could delete the other opt-out control from the entire site

*Fixed in `fccb07c` (3.3.2).*

`[amplifi-do-not-sell]` and `[amplifi-limit-spi]` both set a single shared "already rendered"
flag, and `render_footer_optout()` bailed entirely if it was set. A footer widget containing
just `[amplifi-do-not-sell]` therefore suppressed the whole auto-rendered row — and the
§7014(c) "Limit the Use of My Sensitive Personal Information" control, which the regulations
require in the header or footer, rendered **nowhere on the site**. Placement is now tracked per
control.

The same commit corrected three source comments that documented behaviour the code never had:
the `wp_footer` priority-99 rationale was stated backwards (a later priority loses to *earlier*
hooks, not later ones), an `auto` shortcode attribute was documented but never existed, and
setting `optout_placement` to `banner` was suggested as a way to avoid duplicate controls when
it actually re-renders them in the banner.

### (e) Asset cache-busting does not survive `?ver=` stripping

*Fixed in `2d665ba` (3.3.1).* See section 3 — bump the **filename**, not the version query
string, on every shipped asset change.

### (f) GA4 flagged `sale=1` silently zeroed analytics for opted-out traffic

*Fixed in `c00f5e6` (3.2.1).*

`google-analytics.com` and `analytics.google.com` carried `sale=1` in the default blocklist.
Because the network shim's sale-opt-out check wins over the Consent Mode allowlist **by
design**, that hard-blocked every GA4 hit for any GPC / Do-Not-Sell visitor, silently zeroing
GA4 for that whole traffic slice even with Advanced Consent Mode on. Under Consent Mode a GA4
hit is a cookieless, identifier-free modeling ping; its opt-out compliance is enforced by
Consent Mode (`ad_storage` denied), not by a hard network block. Session-replay vendors that
capture and disclose actual session content keep `sale=1` — those are genuine sale/share flows.

**This changed only the seed default for newly-activated sites.** A site provisioned before
3.2.1 has its blocklist stored in `acconsent_settings` and must be updated per-site: drop the
`|1` sale flag from the two `google-analytics` lines in the saved blocklist.

### (g) Other traps worth knowing

- **Do not tag a tracking script `necessary`.** `sanitize_script()` blocks it, but the reason
  matters: a `necessary` script releases on every load even after Reject.
- **Do not set `optout_placement` to `banner` to avoid duplicates.** It suppresses the footer
  row but makes the JS render the controls inside the banner, which both duplicates them and
  puts §7013/§7014 controls back in a location the regulations do not accept.
- **`network_shim_html()` builds its markup with a NOWDOC + `str_replace`, not
  `ob_start()`/`ob_get_clean()`.** It is called from `filter_buffer()`, which is itself running
  as an `ob_start()` display handler, and PHP raises a **hard fatal** ("Cannot use output
  buffering in output buffering display handlers") — not a warning. The nowdoc also avoids `$`
  interpolation collisions with the JS body.
- **A registered Service Worker from before the plugin was active cannot be reached.** The shim
  prevents a *new* registration from a blocklisted host pre-consent; it does not unregister an
  existing one.
- The bundled `includes/amplifi-framework.php` is a copy of `shared/amplifi-framework.php`.
  Edit the shared file, not the copy.

---

## Appendix: bugs and inconsistencies observed while writing this

Reported, not fixed.

1. **`filter_buffer()` blanks the page on a PCRE failure — the exact outcome its own comment
   says it is avoiding.** `class-acconsent-frontend.php` ~L1036: if the CM-region extraction
   `preg_replace_callback` returns `NULL` (catastrophic backtracking), the code sets
   `$html = ''` and continues, so the visitor is served an empty document. Every other pass in
   the same method goes through `$safe_replace`, which correctly returns the prior subject on
   `NULL`. This branch should preserve the original buffer instead. Also, `$cm_placeholder` is
   assigned `''` on the line above and never used.

2. **The feature's `register_activation_hook` / `register_deactivation_hook` never fire in the
   mega-plugin.** `ac-consent.php` binds both to `ACCONSENT_PLUGIN_FILE`
   (`…/features/consent/ac-consent.php`), but WordPress derives the hook name from
   `plugin_basename()` of the *registered* plugin file, which is `amplifi-plugins/amplifi-plugins.php`.
   Activation is compensated for — the master file calls `Amplifi_Consent_Store::activate()`
   and `Amplifi_Consent_Log::install()` in its own activation hook — but **deactivation is
   not**: `wp_clear_scheduled_hook( 'acconsent_daily_purge' )` is dead code, so a
   deactivated-but-not-deleted feature leaves an orphan daily cron event, which is the exact
   thing that hook's comment says it exists to prevent.

3. **`limitSensitivePI()` hardcodes the localStorage key.** `consent-v2.js` L922/L931 use the
   literal `'acconsent_v1'` twice instead of the `KEY` variable (`CFG.storage_key || 'acconsent_v1'`)
   used everywhere else. Latent today because `storage_key` is hardcoded to `acconsent_v1` in
   `enqueue()`, but a future change to that value would make Limit-SPI write to an orphan blob.

4. **`[amplifi-optout-links]` does not respect prior per-control placement.** It calls
   `optout_controls_html( 'footer' )` without `$skip_rendered`, so a page containing
   `[amplifi-do-not-sell]` followed by `[amplifi-optout-links]` renders the Do-Not-Sell button
   twice. The auto row handles this correctly; the combined shortcode does not.

5. **Stale docblocks.**
   - `Amplifi_Consent_Log::ip_hash()` says `'hash' (default, salted SHA-256…)`, but the default
     is `truncate` — both in `default_settings()` and in the function's own fallback.
   - `class-acconsent-rest.php`'s file header and `issue_token()`/`post_consent()` docblocks
     still say a `wp_rest` nonce is accepted for an unattributed write. The code removed that
     path in 1.7.0 and now hard-rejects a tokenless POST with `403`; an inline comment in the
     same function says so explicitly.
   - `class-acconsent-store.php`'s file header says "Everything is kept in wp_options (no custom
     tables)", which is true of that class but reads as a statement about the feature, which does
     own `wp_acconsent_log`.
   - `render_banner()`'s docblock says the Do-Not-Sell control "only appears inside the initial
     consent popup and the revisit/preferences modal". Since 3.3.0 the default placement is the
     page footer.

6. **`Amplifi_Consent_Admin::maybe_render_failure_notice()` uses a threshold of 3 while
   `Amplifi_Consent_Log::ALERT_THRESHOLD` is 5.** The 3 is a bare literal in the admin class
   rather than a named constant, so the notice and the email intentionally differ but the
   relationship is invisible from either side.

7. **`Amplifi_Consent_Log::record()` JSON-encodes `categories` twice** — once into `$receipt`
   and again into `$db_row` on the next line. Harmless, but the first encode is immediately
   overwritten by the array form before return.

8. **Blocklist host matching is substring-on-full-URL** (`strpos( $url, $host )`), both
   server-side in `blocked_category()` / `$cat_for` and client-side in `catFor()` / `hostMatch()`.
   A blocklisted host appearing anywhere in a URL — including in a query parameter or path
   segment of an unrelated first-party request — matches. This is consistent across both sides
   and appears intentional (it is what lets path-ish entries like `facebook.com/tr` and
   `t.co/i/adsct` work), but it is not host-based matching and can over-block.
