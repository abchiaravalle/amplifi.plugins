# amplifi.schema — Design Spec

**Date:** 2026-05-21
**Plugin slug:** `ac-schema`
**Display name:** Schema
**Status:** Approved by user, ready to implement

## Goal

Bulk-generate, edit, validate, and deploy schema.org JSON-LD for any WordPress site using a Claude API key. The plugin must:

- Generate schema for one page or many pages at once via Claude.
- Support **every schema.org type** through a generic editor (AI picks the best `@type` per page).
- Provide a friendly **dual-pane editor** (form + raw JSON, synced) on the post edit screen.
- Accept **raw JSON-LD paste** for users who already have a snippet.
- Be **backwards compatible**: detect schema already in `<head>` (Yoast, Rank Math, SEOPress, AIOSEO, theme, manual, amplifi.meta) and let the user adopt, override, or ignore it without silent breakage.
- Manage **global schema** (Organization, WebSite, LocalBusiness) site-wide.
- Cover non-post URLs (archives, taxonomies, search, 404, custom patterns) via a **URL Rules** tab.
- Emit a single combined `<script type="application/ld+json">` `@graph` block in `<head>`.
- Validate locally against a bundled schema.org type/property registry; link to Google Rich Results test.

## Relationship to existing plugins

- **amplifi.meta JSON-LD page** — deprecated by this plugin. On activation, offer one-time import of `_ac_jsonld_data` post meta and `ac_jsonld_settings`. After import (or if user skips), amplifi.meta's JSON-LD output is suppressed when amplifi.schema is active, and its admin page shows a deprecation notice pointing to amplifi.schema. The amplifi.meta plugin itself remains; only the JSON-LD subsystem defers.
- **amplifi-framework** — registers under the amplifi.studio top-level menu like all other plugins; uses `amplifi_register_plugin()` for the main submenu and manual `add_submenu_page()` calls for the other three. Framework auto-update applies normally.

## Requirements (locked from brainstorm)

| # | Decision |
|---|----------|
| 1 | Replaces amplifi.meta's JSON-LD feature |
| 2 | Full schema.org coverage via generic editor; AI picks best type per page |
| 3 | Bulk runs as a background queue with live progress UI |
| 4 | Raw JSON-LD paste-and-save is supported alongside form editing |
| 5 | Detect existing schema, show in UI, leave foreign source alone unless user picks "override" |
| 6 | Dual-pane editor: form left, raw JSON right, kept in sync |
| 7 | Global schema editors (Organization, WebSite, LocalBusiness) with AI prefill, output on every page |
| 8 | Default model Claude Haiku 4.5 (`claude-haiku-4-5-20251001`), switchable to Sonnet 4.6 / Opus 4.7 |
| 9 | Daily and monthly USD spend caps with cost preview before bulk runs |
| 10 | Single combined `@graph` block in `<head>` |
| 11 | Local schema.org validation + "Test in Google Rich Results" external link |
| 12 | Posts/pages/CPTs handled per-post; archives/taxonomies/custom URLs handled via URL Rules tab |

## Architecture

Multi-file plugin under `plugins/ac-schema/`, mirroring `amplifi-security`'s structure. Reason: editor UI + URL rules + bulk queue + validation + AI client + detector is too much for a single-file plugin; clean class boundaries make each subsystem testable and editable in isolation.

```
plugins/ac-schema/
├── ac-schema.php                          # bootstrap, PHP 8.1+/WP 6.4+ gates, framework registration
├── uninstall.php                          # drop tables, delete options
├── docker-compose.yml                     # local dev on :8093 / MySQL :3319
├── includes/
│   ├── amplifi-framework.php              # copied from shared/ at release time
│   ├── class-autoloader.php               # PSR-style under namespace Amplifi\Schema\*
│   ├── class-plugin.php                   # subsystem registration + hook wiring
│   ├── class-installer.php                # dbDelta for 3 tables, migration runner
│   ├── class-activator.php
│   ├── class-deactivator.php
│   ├── admin/
│   │   ├── class-admin.php                # menu wiring (4 submenus), asset enqueueing
│   │   ├── class-dashboard-page.php       # main "Schema" page
│   │   ├── class-bulk-page.php            # bulk generate UI + queue progress
│   │   ├── class-global-page.php          # Organization / WebSite / LocalBusiness editors
│   │   ├── class-rules-page.php           # URL Rules tab
│   │   ├── class-post-editor.php          # metabox on post/page/CPT edit screens
│   │   └── assets/                        # built JS/CSS for dual-pane editor (React)
│   ├── ai/
│   │   ├── class-anthropic-client.php     # Messages API with tool-use for strict JSON output
│   │   ├── class-prompt-builder.php       # per-schema-type prompt templates
│   │   └── class-spend-tracker.php        # daily/monthly USD rollups + cap enforcement
│   ├── schema/
│   │   ├── class-registry.php             # bundled schema.org types + required-for-rich-results map
│   │   ├── class-validator.php            # local JSON-LD validation
│   │   ├── class-detector.php             # parse rendered <head> for existing schema
│   │   ├── class-graph-builder.php        # merge global + URL-rule + per-post into one @graph
│   │   └── data/
│   │       └── schema-org-types.json      # bundled schema.org type/property index
│   ├── queue/
│   │   ├── class-bulk-job.php             # WP-Cron-backed batch runner
│   │   └── class-job-store.php
│   ├── frontend/
│   │   ├── class-head-output.php          # wp_head hook, emits @graph block
│   │   └── class-foreign-suppressor.php   # strips Yoast/Rank Math/meta JSON-LD when user chose override
│   ├── rest/
│   │   └── class-rest-controller.php      # /amplifi-schema/v1/* endpoints
│   ├── migration/
│   │   └── class-meta-importer.php        # one-time import from amplifi.meta JSON-LD
│   └── crypto/
│       └── class-secret-store.php         # AES-256-GCM via WP AUTH_KEY family (copied pattern from amplifi-security)
```

## Data model

### Custom tables (`{prefix}_ac_schema_*`, source of truth `Installer::install()`)

**`entries`** — every piece of schema this plugin owns

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `scope_type` | varchar(16) | `post` \| `url_rule` \| `global` |
| `scope_id` | varchar(191) | post ID, URL rule ID, or global key (`organization`/`website`/`localbusiness`) |
| `schema_type` | varchar(64) | resolved `@type`, e.g. `Article`, `Product`, `Organization` |
| `source` | varchar(16) | `ai` \| `manual` \| `imported` \| `raw` |
| `json_ld` | longtext | the full JSON-LD object as JSON string |
| `hash` | char(64) | sha256 of `json_ld`, for change detection |
| `updated_at` | datetime | |

Unique index on `(scope_type, scope_id, schema_type)` so each scope can hold multiple types (e.g., a post can have Article + Breadcrumb + FAQ).

**`bulk_jobs`** — bulk-generation runs

| Column | Type |
|--------|------|
| `id` | bigint PK |
| `status` | varchar(16) — `queued` / `running` / `paused` / `completed` / `failed` |
| `scope` | longtext (JSON: post types, taxonomy filters, date range, IDs) |
| `total` | int |
| `processed` | int |
| `failed` | int |
| `model` | varchar(64) |
| `started_at` | datetime nullable |
| `finished_at` | datetime nullable |
| `cost_usd` | decimal(10,4) |

**`spend`** — daily rollups for spend cap enforcement

| Column | Type |
|--------|------|
| `day` | date PK |
| `input_tokens` | bigint |
| `output_tokens` | bigint |
| `cost_usd` | decimal(10,4) |

### Post meta

- `_ac_schema_overrides` — array of `schema_type` strings the user has elected to override (suppress foreign schema for these types on this post).
- `_ac_schema_detected_cache` — last detected foreign schema for this post (cached 1h, refreshed on editor open).

### Options

- `ac_schema_settings` (JSON) — `api_key_encrypted`, `default_model`, `daily_spend_cap_usd` (default 5), `monthly_spend_cap_usd` (default 50), `output_priority` (default 1), `suppress_amplifi_meta_jsonld` (bool, default true)
- `ac_schema_global_organization` (JSON)
- `ac_schema_global_website` (JSON)
- `ac_schema_global_localbusiness` (JSON, nullable)
- `ac_schema_url_rules` (JSON array of `{id, pattern, match_type: glob|regex, schema_entries: [{type, json_ld}]}`)
- `ac_schema_db_version`
- `ac_schema_onboarding_complete` (bool)
- `ac_schema_meta_import_status` (string — `pending` / `done` / `skipped`)

## Admin UI

Under the amplifi.studio top-level menu, four submenus (hook suffix prefix: `amplifi-studio_page_`):

1. **Schema** (main) — slug `amplifi-ac-schema` — dashboard: total entries by type, recent bulk jobs, current queue status, spend this month, "Generate for entire site" call-to-action, links to other tabs. Registered via `amplifi_register_plugin()`.
2. **Schema: Bulk** — slug `amplifi-ac-schema-bulk` — scope picker (post type / taxonomy / date range / specific IDs / "all unscoped posts"), cost preview, model override, "Start" button, live progress (polled via REST every 3s while job running), pause/resume/cancel, per-entry success/failure log.
3. **Schema: Global** — slug `amplifi-ac-schema-global` — three form sections (Organization, WebSite, LocalBusiness). "Prefill with AI" button on each pulls from site title, tagline, admin email, site icon, blog description. Toggle to enable/disable LocalBusiness output.
4. **Schema: URL Rules** — slug `amplifi-ac-schema-rules` — table of patterns and their schema. Add new rule, test rule against URL, reorder. Pattern types: glob (`/blog/*`) or regex (`^/products/category/.*$`).

### Per-post editor (metabox)

Registered on `post`, `page`, and all public CPTs. Default position: side, high priority.

**Dual-pane** (React component built in `admin/assets/`, mounted on metabox render):

- **Left (Form pane):** auto-generated from `Registry` based on selected `@type`. Each property gets the right input (text, URL, date, image picker, repeating group for nested objects/arrays).
- **Right (Raw JSON pane):** monospace editor (CodeMirror or simple textarea with syntax highlighting). Live-synced — edits on either side update the other on blur (debounced).
- **Validation:** red underline + tooltip on both panes for: invalid JSON, unknown `@type`, unknown property for type, missing required-for-rich-results fields.
- **Banner:** if `Detector` found foreign schema for this URL, banner reads e.g. "Found `Article` schema from Yoast SEO" with three buttons: **Import a copy to edit here** (loads it into the editor), **Override theirs** (adds `Article` to `_ac_schema_overrides` so front-end strips Yoast's block), **Ignore** (no action, banner dismissed).
- **Actions:** "Generate with AI" (single call), "Add another schema type" (multi-type per post), "Test in Google Rich Results" (opens external link in new tab), "Save" (writes via REST).

## Front-end output

`Head_Output` hooks `wp_head` at priority 1.

Flow:
1. `Graph_Builder->build_for_current_request()`:
   - Always include global Organization and WebSite entries if present.
   - Include LocalBusiness if enabled in settings.
   - Match current URL against URL rules; include matched rule's entries.
   - If on a singular post/page/CPT, include that post's entries from the `entries` table.
   - Add BreadcrumbList automatically if breadcrumbs detectable from request context (CPT hierarchy + taxonomy).
   - Resolve cross-references: Article's `author` → Person entity `@id`, `publisher` → Organization `@id`.
2. Wrap collected entities in `{ "@context": "https://schema.org", "@graph": [...] }`.
3. Emit one `<script type="application/ld+json" id="amplifi-schema">` block.

`Foreign_Suppressor` runs only if any matching entry has `_ac_schema_overrides` flag for that type. Strategy:
- For Yoast: hook `wpseo_schema_graph` filter and remove offending pieces.
- For Rank Math: hook `rank_math/json_ld` filter.
- For SEOPress: hook `seopress_pro_schemas_json` filter.
- For AIOSEO: hook `aioseo_schema_output` filter.
- For amplifi.meta: when `suppress_amplifi_meta_jsonld` setting is true and amplifi.meta is active, hook directly into amplifi.meta's output method (known: `_ac_jsonld_data` post meta) and short-circuit it.
- For theme/manual: as last resort, use `ob_start` on `template_redirect` to strip `<script type="application/ld+json">` blocks matching the overridden types. Only enabled per-page if user explicitly chose override AND no filter-based suppression worked.

## AI integration

**Client:** `Anthropic_Client` uses Messages API with **tool-use** to force strict JSON output (same pattern as amplifi.security's `Triage_Engine`). The tool schema requires a top-level JSON-LD object with `@context`, `@type`, and type-specific properties from `Registry`.

**Prompt builder:** for a given post, builds prompt with: post title, URL, post type, trimmed content (~8K tokens max via `Prompt_Builder::trim_to_token_budget()`), existing schema if any, list of allowed `@type`s to pick from. For global Organization prefill: site title, tagline, admin email, site URL, site icon URL.

**Spend tracking:** every Anthropic response includes token usage. `Spend_Tracker::record()` writes a row to `spend` table (upsert by day). Before any AI call, `Spend_Tracker::can_spend($estimated_usd)` checks both daily and monthly caps; if exceeded, the call is refused with a user-facing error.

**Default model:** `claude-haiku-4-5-20251001`. Settings page exposes a dropdown with Haiku 4.5 / Sonnet 4.6 / Opus 4.7. Model prices hard-coded in `Spend_Tracker::PRICING` (input/output per million tokens).

## Bulk queue

`Bulk_Job` is a WP-Cron-driven batch runner:

1. User configures scope in **Schema: Bulk** page; clicks "Preview cost". Server counts matching posts, computes `estimated_cost = count × avg_tokens_per_post × model_price`. Page shows preview.
2. User clicks "Start". `Job_Store::create()` writes a row to `bulk_jobs` with status `queued`, scope JSON, model. Returns job ID.
3. `wp_schedule_single_event(time(), 'ac_schema_run_bulk_batch', [$job_id])` fires.
4. Handler processes up to **5 posts per tick**, then re-schedules itself for `time() + 30` if more posts remain. After every batch: increment `processed`, record spend, update job status.
5. If `Spend_Tracker::can_spend()` returns false mid-job, job is **paused** (status set, next batch not scheduled). User can resume next day.
6. On error per-post: increment `failed`, log error in job's metadata, continue with next post.
7. UI polls `/amplifi-schema/v1/jobs/{id}` every 3s while open and job is running.

Pause/resume/cancel exposed via REST.

## Validation

`Validator::validate($json_ld)` returns `{ ok: bool, errors: [{path, code, message}] }`. Checks, in order:

1. JSON syntax.
2. Top-level `@context` is `https://schema.org` (or schema.org URL variants).
3. Top-level `@type` is known to `Registry`.
4. Every property key is valid for that `@type` (per bundled `schema-org-types.json`).
5. For known rich-result types: required fields present (e.g., `Article` → `headline`, `author`, `datePublished`, `image`; `Product` → `name`, `image`, `offers`; `FAQPage` → `mainEntity` array with `Question`/`Answer`).

`Registry` ships a bundled JSON index of schema.org types and their properties, refreshable via plugin update. Source: schema.org's published `.jsonld` vocabulary file, processed at build time into a lean lookup map.

"Test in Google Rich Results" button on the editor opens `https://search.google.com/test/rich-results?url={urlencode(get_permalink($post))}` in a new tab. No API call — that test endpoint has no public API.

## Backwards compatibility / detection

`Detector::detect_for_url($url)`:

1. Cache key `_ac_schema_detected_cache_{md5(url)}`, TTL 1 hour. Return cached if present.
2. `wp_remote_get($url, [...])` with a unique user agent so we can skip our own output if needed.
3. Parse HTML, find all `<script type="application/ld+json">` blocks.
4. For each block: parse JSON, extract `@type`(s), guess source from surrounding HTML and known signatures (Yoast leaves `class="yoast-schema-graph"` on its script; Rank Math leaves a `data-rank-math` attribute; SEOPress and AIOSEO have similar markers; amplifi.meta's script has `id="ac-jsonld-data"` — to be added during the migration phase if missing).
5. Return list: `[{source, schema_type, json_string}, ...]`.

Refresh trigger: post save (`save_post`), post URL change (`post_updated` with permalink delta), or manual "Refresh" button in editor.

Activation-time scan: enqueue a background scan of all published posts by reusing the `Bulk_Job` framework with a `detect` job type (no AI calls, just `Detector` per post). Result populates the dashboard with detected-schema counts per source.

## REST API

Namespace: `amplifi-schema/v1`. Auth: `manage_options` capability via nonce (same pattern as other plugins).

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/entries` | List entries, filterable by scope_type, scope_id, schema_type |
| POST | `/entries` | Create entry |
| GET | `/entries/{id}` | Get one |
| PUT | `/entries/{id}` | Update |
| DELETE | `/entries/{id}` | Delete |
| POST | `/entries/validate` | Validate a JSON-LD payload without saving |
| POST | `/entries/generate` | One-shot AI generation for a single post |
| GET | `/jobs` | List bulk jobs |
| POST | `/jobs` | Create + start a bulk job |
| GET | `/jobs/{id}` | Get job status |
| POST | `/jobs/{id}/pause` | |
| POST | `/jobs/{id}/resume` | |
| POST | `/jobs/{id}/cancel` | |
| POST | `/jobs/preview-cost` | Estimate cost for a scope |
| GET | `/detect` | Detect foreign schema for a URL |
| GET | `/global/{key}` | Get global entry (organization/website/localbusiness) |
| PUT | `/global/{key}` | Update global entry |
| POST | `/global/{key}/ai-prefill` | AI prefill from site context |
| GET | `/rules` | List URL rules |
| POST | `/rules` | Create rule |
| PUT | `/rules/{id}` | Update |
| DELETE | `/rules/{id}` | Delete |
| POST | `/rules/test` | Test a pattern against a URL |
| GET | `/spend` | Current daily/monthly spend + caps |
| POST | `/migrate-from-meta` | Trigger amplifi.meta JSON-LD import |

## Migration from amplifi.meta

Bootstrap (`ac-schema.php`) defines `AMPLIFI_SCHEMA_ACTIVE` as `true` when the plugin loads. amplifi.meta checks this constant to defer.

On `Activator::activate()`:

1. If amplifi.meta plugin file exists and is active and `ac_schema_meta_import_status` is unset, set the status option to `pending`.
2. On the next admin page load, `Admin` reads the status and renders a notice: "Import N posts' JSON-LD from amplifi.meta? [Import] [Skip]". (The activator itself does not render — activation can run during plugin upload where admin UI is not rendered.)
3. On user click, `Meta_Importer::import_all()` reads every post's `_ac_jsonld_data`, writes equivalent `entries` rows with `source = 'imported'`, copies `ac_jsonld_settings` Organization data into `ac_schema_global_organization` if our global entry is empty.
4. Set `ac_schema_meta_import_status = 'done'` (or `skipped`). Set `suppress_amplifi_meta_jsonld = true` on import.
5. amplifi.meta's JSON-LD admin page checks `defined('AMPLIFI_SCHEMA_ACTIVE')` and shows a deprecation notice with a "Manage in amplifi.schema" link. Its `wp_head` output method early-returns when the constant is set.

The amplifi.meta plugin itself is **not** modified by amplifi.schema's installer — the deprecation behavior is added in a follow-up amplifi.meta release that ships in the same monorepo release.

## Security

- API key stored encrypted via `Secret_Store` (AES-256-GCM with HKDF-SHA256 key derived from `AUTH_KEY`/`SECURE_AUTH_KEY` — same pattern as amplifi.security).
- All REST routes require `manage_options`.
- All form inputs and JSON payloads sanitized; raw JSON-LD values are validated as JSON, then re-encoded before storage (no script injection).
- Output uses `wp_json_encode` with `JSON_UNESCAPED_SLASHES | JSON_HEX_TAG` to prevent `</script>` injection.
- `Detector` HTTP calls have a 5-second timeout and a 5MB response cap.
- Spend caps prevent runaway AI bills; calls refused at hard cap.

## Testing strategy

- **PHPUnit tests** in `tests/` for: `Validator` (table-driven cases for valid/invalid schemas), `Graph_Builder` (merge logic, `@id` resolution), `Detector` (parsing real Yoast/Rank Math/SEOPress fixtures), `Spend_Tracker` (cap enforcement), `Prompt_Builder::trim_to_token_budget`.
- **JS unit tests** for the dual-pane sync logic (form ↔ JSON conversion in both directions).
- **Fixtures**: real-world `<head>` HTML samples from Yoast, Rank Math, SEOPress, AIOSEO, amplifi.meta committed under `tests/fixtures/`.
- **Manual smoke tests** documented in `docs/testing-amplifi-schema.md`: install → onboarding → generate one → generate bulk 10 → verify in Google Rich Results → install Yoast → verify detector finds Yoast → override → verify Yoast block stripped.

## Releasing

`scripts/release.sh` handles bundling: copies `shared/amplifi-framework.php` + `LICENSE` into the plugin, zips, includes `plugins-manifest.json` entry. Add `ac-schema` entry to `plugins-manifest.json` with name, description, icon during implementation.

## Out of scope (v1)

- Server-side schema verification against Google's Structured Data Testing Tool (no public API).
- Schema A/B testing.
- Automatic schema for WooCommerce Product objects (handled by Woo itself; we'd surface as "detected from WooCommerce", let user override if desired).
- Multi-site network admin UI (each site manages its own).
- Internationalized schema (one `@language` per entry — v2).

## Open questions

None. All decisions confirmed in brainstorm session 2026-05-21.
