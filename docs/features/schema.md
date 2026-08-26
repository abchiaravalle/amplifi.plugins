# amplifi.schema

Generates, edits, validates, and deploys schema.org JSON-LD for a WordPress site using the Anthropic Messages API. Every entity the feature owns — site-global, URL-rule, and per-post — is merged into a single `<script type="application/ld+json" id="amplifi-schema">` `@graph` block in `<head>`. It also detects JSON-LD already emitted by Yoast, Rank Math, SEOPress, AIOSEO, and amplifi.meta, and suppresses the foreign copy of any `@type` amplifi.schema already owns for the current post.

## At a glance

| Item | Value |
|------|-------|
| Feature slug | `schema` (in `wp_option` `amplifi_plugins_enabled_features`) |
| Source path | `plugins/amplifi-plugins/features/schema/` |
| Entry file | `ac-schema.php` |
| Namespace | `Amplifi\Schema\*` |
| Version constants | `AMPLIFI_SCHEMA_VERSION` = `3.3.7`, `AMPLIFI_SCHEMA_DB_VERSION` = `1` |
| Other constants | `AMPLIFI_SCHEMA_FILE`, `_PATH`, `_URL`, `_BASENAME`, `_SLUG` (`amplifi-schema`), `_MIN_PHP` (`8.1`), `_MIN_WP` (`6.4`), `AMPLIFI_SCHEMA_ACTIVE` |
| DB tables | `{prefix}ac_schema_entries`, `{prefix}ac_schema_bulk_jobs`, `{prefix}ac_schema_spend` |
| REST namespace | `amplifi-schema/v1` |
| Admin pages | 4 submenus under `amplifi-studio` + a metabox on every public post type |
| Cron hook | `ac_schema_run_bulk_batch` (single events, self-re-arming) |
| PHP LOC | ~4,734 across 42 PHP files (53 files total). Excluding the bundled framework copy and `tests/`: 3,316 LOC |
| AI vendor | Anthropic Claude, `POST https://api.anthropic.com/v1/messages` |

### Load path

`amplifi-plugins.php` builds a feature registry and `require_once`s `features/schema/ac-schema.php` only when `'schema'` is present in the `amplifi_plugins_enabled_features` option array. Default is an empty array, so the feature is off until toggled on in the hub.

The entry file deliberately omits `declare(strict_types=1)` so it parses on PHP < 8.1 and can render a graceful `admin_notices` error through `amplifi_schema_render_blocking_notice()`. Three gates run before any 8.1-only code is touched:

```php
if ( version_compare( PHP_VERSION, AMPLIFI_SCHEMA_MIN_PHP, '<' ) ) { /* notice + return */ }
if ( isset( $wp_version ) && version_compare( $wp_version, AMPLIFI_SCHEMA_MIN_WP, '<' ) ) { /* notice + return */ }
if ( ! extension_loaded( 'openssl' ) ) { /* notice + return */ }
```

If `AMPLIFI_SCHEMA_VERSION` is already defined the file returns immediately, so a duplicate standalone `plugins/ac-schema/` copy cannot double-load.

Bootstrap order after the gates: shared framework → `Autoloader::register()` → activation/deactivation hooks → `plugins_loaded` @ priority 20 → `( new Plugin() )->boot()`.

## Architecture

`Plugin::boot()` is the only wiring point:

```php
Installer::maybe_upgrade();
( new Admin\Admin() )->register();
( new Frontend\Head_Output() )->register();
( new Frontend\Foreign_Suppressor() )->register();
( new Rest\Rest_Controller() )->register();
( new Queue\Bulk_Job() )->register();
```

| File | Class | Responsibility |
|------|-------|----------------|
| `includes/class-autoloader.php` | `Autoloader` | Maps `Amplifi\Schema\` onto `includes/`. Sub-namespaces lowercase with `_`→`-`; leaf tries `class-<name>.php` then `interface-<name>.php` |
| `includes/class-plugin.php` | `Plugin` | Subsystem registration (above) |
| `includes/class-installer.php` | `Installer` | `dbDelta` source of truth for the three tables; `DB_VERSION = '1'`; `maybe_upgrade()` re-runs `install()` when `ac_schema_db_version` drifts |
| `includes/class-activator.php` | `Activator` | `Installer::install()`, seeds `ac_schema_settings` defaults, stages the amplifi.meta import notice |
| `includes/class-deactivator.php` | `Deactivator` | `wp_clear_scheduled_hook( 'ac_schema_run_bulk_batch' )` |
| `includes/admin/class-admin.php` | `Admin\Admin` | Framework registration, three manual submenus, REST-bridge script, amplifi.meta import notice |
| `includes/admin/class-dashboard-page.php` | `Admin\Dashboard_Page` | Spend cards, entries-by-type table, recent jobs, inline settings form (PUTs `/settings`) |
| `includes/admin/class-global-page.php` | `Admin\Global_Page` | Raw-JSON textarea per global key + "Prefill with AI" |
| `includes/admin/class-rules-page.php` | `Admin\Rules_Page` | URL-rule CRUD UI (glob/regex) |
| `includes/admin/class-bulk-page.php` | `Admin\Bulk_Page` | Scope picker, cost preview, job start, live progress |
| `includes/admin/class-post-editor.php` | `Admin\Post_Editor` | `ac-schema-editor` metabox on every `public => true` post type; foreign-detection banner; inline vanilla-JS REST client |
| `includes/ai/class-anthropic-client.php` | `AI\Anthropic_Client` | Messages API call with forced `tool_choice` on the `emit_jsonld` tool; injectable `$transport` callable for tests |
| `includes/ai/class-prompt-builder.php` | `AI\Prompt_Builder` | `build_for_post()` / `build_for_global()`; `trim_to_token_budget()` keeps head 70% + tail 20% |
| `includes/ai/class-spend-tracker.php` | `AI\Spend_Tracker` | `PRICING` table, `estimate_cost()`, `record()`, `spend_today_usd()`, `spend_month_usd()`, `can_spend()` |
| `includes/crypto/class-secret-store.php` | `Crypto\Secret_Store` | AES-256-GCM with an HKDF-SHA256 key from the `AUTH_KEY` family; `set()`/`get()`/`delete()` wrap `ac_schema_secret_<key>` options |
| `includes/data/class-entry-store.php` | `Data\Entry_Store` | Typed `wpdb` helpers over `ac_schema_entries`; `save()` is an `INSERT … ON DUPLICATE KEY UPDATE` upsert on the unique key |
| `includes/queue/class-job-store.php` | `Queue\Job_Store` | Job lifecycle; statuses `queued`/`running`/`paused`/`completed`/`failed` |
| `includes/queue/class-bulk-job.php` | `Queue\Bulk_Job` | Cron batch runner: 5 posts/tick, re-arms in 30 s, `$0.05` estimate per post |
| `includes/schema/class-registry.php` | `Schema\Registry` | Reads the bundled type index; `has_type()`, `properties_for()`, `required_for_rich_results()`, `all_types()` |
| `includes/schema/class-validator.php` | `Schema\Validator` | Errors vs. warnings (see below) |
| `includes/schema/class-detector.php` | `Schema\Detector` | Fetches a URL, regex-extracts every `application/ld+json` block, expands `@graph`, guesses the source |
| `includes/schema/class-graph-builder.php` | `Schema\Graph_Builder` | Merges global → URL-rule → per-post entries, strips inner `@context`, returns `{ "@context": …, "@graph": [ … ] }` |
| `includes/schema/data/schema-org-types.json` | — | 933 types, 1.86 MB. Each entry: `parent`, `properties[]`, `required_for_rich_results[]` |
| `includes/frontend/class-head-output.php` | `Frontend\Head_Output` | `wp_head` emitter at the configured priority |
| `includes/frontend/class-foreign-suppressor.php` | `Frontend\Foreign_Suppressor` | Per-vendor filters that drop overridden `@type`s |
| `includes/migration/class-meta-importer.php` | `Migration\Meta_Importer` | One-shot import of `_ac_jsonld_data` and `ac_jsonld_settings` from amplifi.meta |
| `includes/rest/class-rest-controller.php` | `Rest\Rest_Controller` | All 17 route registrations and their handlers (758 LOC) |
| `scripts/build-schema-index.php` | — | Rebuilds `schema-org-types.json` from `https://schema.org/version/latest/schemaorg-current-https.jsonld`. `REQUIRED_FOR_RICH_RESULTS` is a hand-maintained const in this script |

`tests/` holds 11 PHPUnit test classes plus five HTML fixtures (`yoast-head.html`, `rankmath-head.html`, `seopress-head.html`, `aioseo-head.html`, `manual-head.html`). `tests/bootstrap.php` stubs `ABSPATH`, `AMPLIFI_SCHEMA_PATH`, and `ARRAY_A` and registers the autoloader — no WordPress test suite is required.

## Admin UI

`Admin\Admin::register_with_framework()` runs on `init` @ 5 and calls `amplifi_register_plugin( 'ac-schema', 'Schema', … )`. The framework's `amplifi_page_slug()` prefixes slugs that do not already start with `amplifi-`, so `ac-schema` becomes `amplifi-ac-schema`. The other three pages are registered manually on `admin_menu` @ 20.

| Page | Page slug | Hook suffix | Renderer |
|------|-----------|-------------|----------|
| Schema (dashboard) | `amplifi-ac-schema` | `amplifi-studio_page_amplifi-ac-schema` | `Dashboard_Page::render()` |
| Schema: Global | `amplifi-ac-schema-global` | `amplifi-studio_page_amplifi-ac-schema-global` | `Global_Page::render()` |
| Schema: URL Rules | `amplifi-ac-schema-rules` | `amplifi-studio_page_amplifi-ac-schema-rules` | `Rules_Page::render()` |
| Schema: Bulk | `amplifi-ac-schema-bulk` | `amplifi-studio_page_amplifi-ac-schema-bulk` | `Bulk_Page::render()` |

All four require `manage_options`. `enqueue_assets()` matches the exact hook suffixes above and registers a scriptless handle `ac-schema-admin-bridge` carrying `window.AcSchemaAdmin = { restUrl, nonce }`. There is no build step and no bundled JS/CSS — every page emits inline `<script>`/`<style>`.

### Per-post editor

`Post_Editor::add_meta_box()` adds `ac-schema-editor` (title `amplifi.schema`, context `normal`, priority `high`) to every post type returned by `get_post_types( [ 'public' => true ] )`.

The metabox renders:

- A **detected-schema banner** when the post is `publish`: `Detector::detect_for_url( get_permalink() )` with `source === 'amplifi-schema'` filtered out. Each detected entity gets three buttons — `Import a copy` (clones the JSON into a new local entry, `source = imported`), `Override (suppress theirs)` → `PUT /post-overrides/{id}` with `{add: <type>}`, and `Un-override` → `{remove: <type>}`.
- One `.ac-entry` block per row in `ac_schema_entries` for `scope_type = 'post'`, each a pretty-printed `<textarea>` with Save / Delete.
- `Generate with AI` → `POST /entries/generate` with `{post_id}`; the returned JSON-LD is appended as an **unsaved** entry the user must review and Save.
- `Add blank entry` → seeds `{"@context":"https://schema.org","@type":"Thing","name":""}`.
- `Test in Google Rich Results` → `https://search.google.com/test/rich-results?url=<permalink>`.

Saves POST/PUT `entries` with `source: 'manual'` and `schema_type` taken from the parsed `@type` (falling back to the block's `data-type`, then `Thing`).

## Database tables

Created by `Installer::install()` with `dbDelta`, using `$wpdb->get_charset_collate()`.

### `{prefix}ac_schema_entries`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `scope_type` | `varchar(16) NOT NULL` | `post` \| `url_rule` \| `global` |
| `scope_id` | `varchar(191) NOT NULL` | post ID, rule ID, or `organization`/`website`/`localbusiness` |
| `schema_type` | `varchar(64) NOT NULL` | e.g. `Article` |
| `source` | `varchar(16) NOT NULL` | `ai` \| `manual` \| `imported` |
| `json_ld` | `longtext NOT NULL` | |
| `hash` | `char(64) NOT NULL` | `sha256` of `json_ld`, computed in `Entry_Store::save()` |
| `updated_at` | `datetime NOT NULL` | UTC, `gmdate()` |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY scope_type_id_schema (scope_type, scope_id, schema_type)`, `KEY scope_lookup (scope_type, scope_id)`.

### `{prefix}ac_schema_bulk_jobs`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `status` | `varchar(16) NOT NULL` | `queued`/`running`/`paused`/`completed`/`failed` |
| `scope` | `longtext NOT NULL` | JSON: `post_types[]`, optional `ids[]`, optional `after` |
| `total` | `int NOT NULL DEFAULT 0` | |
| `processed` | `int NOT NULL DEFAULT 0` | |
| `failed` | `int NOT NULL DEFAULT 0` | |
| `model` | `varchar(64) NOT NULL` | |
| `started_at` | `datetime NULL` | Set once, via `COALESCE(started_at, %s)` |
| `finished_at` | `datetime NULL` | Set on `completed`/`failed` |
| `cost_usd` | `decimal(10,4) NOT NULL DEFAULT 0` | |

Keys: `PRIMARY KEY (id)`, `KEY status (status)`.

### `{prefix}ac_schema_spend`

| Column | Type | Notes |
|--------|------|-------|
| `day` | `date NOT NULL` | PK — one row per UTC day |
| `input_tokens` | `bigint(20) NOT NULL DEFAULT 0` | |
| `output_tokens` | `bigint(20) NOT NULL DEFAULT 0` | |
| `cost_usd` | `decimal(10,4) NOT NULL DEFAULT 0` | |

## Options and post meta

### `wp_options`

| Key | Written by | Contents |
|-----|-----------|----------|
| `ac_schema_settings` | `Activator`, `PUT /settings`, `Meta_Importer` | Array: `default_model`, `daily_spend_cap_usd`, `monthly_spend_cap_usd`, `output_priority`, `suppress_amplifi_meta_jsonld` |
| `ac_schema_global_organization` | `PUT /global/organization`, `Meta_Importer` | Decoded JSON-LD object |
| `ac_schema_global_website` | `PUT /global/website` | Decoded JSON-LD object |
| `ac_schema_global_localbusiness` | `PUT /global/localbusiness` | Decoded JSON-LD object |
| `ac_schema_url_rules` | `POST/PUT/DELETE /rules` | Array of `{ id, pattern, match_type, schema_entries }`; `id` from `uniqid( 'rule_' )` |
| `ac_schema_db_version` | `Installer::install()` | `'1'` |
| `ac_schema_meta_import_status` | `Activator`, `Admin`, `Meta_Importer` | `pending` \| `done` \| `skipped` |
| `ac_schema_secret_anthropic_api_key` | `Secret_Store::set( 'anthropic_api_key', … )` | `v1:` + base64(IV‖tag‖ciphertext), `autoload = false` |
| `amplifi_security_fallback_key` | `Secret_Store::derive_key()` | Only written when the whole `AUTH_KEY` family is undefined. The option name is shared with amplifi.security — see Pitfalls |

Activator defaults, applied only when `ac_schema_settings` is falsy:

```php
'default_model'                => 'claude-haiku-4-5-20251001',
'daily_spend_cap_usd'          => 5.0,
'monthly_spend_cap_usd'        => 50.0,
'output_priority'              => 1,
'suppress_amplifi_meta_jsonld' => true,
```

`PUT /settings` only accepts those five keys plus `api_key` (which is diverted to `Secret_Store` and never persisted into the settings blob).

`uninstall.php` also deletes `ac_schema_onboarding_complete`, but nothing in the feature ever writes that option.

### Post meta

| Key | Written by | Contents |
|-----|-----------|----------|
| `_ac_schema_overrides` | `PUT /post-overrides/{id}` | Flat array of schema-type strings whose foreign copies should be suppressed for this post |

`uninstall.php` also calls `delete_post_meta_by_key( '_ac_schema_detected_cache' )`, but that key is never written — foreign-detection results are cached in the transient `ac_schema_detected_<md5(url)>` for `HOUR_IN_SECONDS` instead.

## REST API

Namespace `amplifi-schema/v1` (`Rest_Controller::NS`), registered on `rest_api_init`. Every route shares one permission callback:

```php
$perm = static fn() => current_user_can( 'manage_options' );
```

| Method | Route | Handler | Notes |
|--------|-------|---------|-------|
| GET | `/entries` | `list_entries` | Requires both `scope_type` and `scope_id`; returns `[]` otherwise |
| POST | `/entries` | `create_entry` | Auto-injects `@context` when missing, validates, upserts |
| POST | `/entries/validate` | `validate_entry` | Returns `{ok, errors, warnings}`; persists nothing |
| POST | `/entries/generate` | `generate_entry` | AI generate for `post_id` or a freeform `{title,url,post_type,content}` |
| GET | `/entries/(?P<id>\d+)` | `get_entry` | 404 when absent |
| PUT | `/entries/(?P<id>\d+)` | `update_entry` | Accepts `json_ld` and/or `source` |
| DELETE | `/entries/(?P<id>\d+)` | `delete_entry` | Always `{deleted: true}` |
| GET | `/detect` | `detect` | `url` query param required |
| GET | `/global/(?P<key>[a-z_-]+)` | `get_global` | Key restricted to `organization`/`website`/`localbusiness` |
| PUT | `/global/(?P<key>[a-z_-]+)` | `put_global` | Fills `@context`/`@type`, validates, writes both the option and a `global`-scope entry |
| POST | `/global/(?P<key>[a-z_-]+)/ai-prefill` | `prefill_global` | Returns for review; does **not** save |
| GET | `/rules` | `list_rules` | |
| POST | `/rules` | `create_rule` | |
| POST | `/rules/test` | `test_rule` | `{pattern, match_type, url}` → `{matches: bool}` |
| PUT | `/rules/(?P<id>[a-z0-9_-]+)` | `update_rule` | |
| DELETE | `/rules/(?P<id>[a-z0-9_-]+)` | `delete_rule` | |
| GET | `/jobs` | `list_jobs` | 20 most recent |
| POST | `/jobs` | `create_job` | Counts scope, inserts the job, schedules `ac_schema_run_bulk_batch` immediately |
| POST | `/jobs/preview-cost` | `preview_cost` | Estimates 2,000 in + 500 out tokens per post |
| GET | `/jobs/(?P<id>\d+)` | `get_job` | |
| POST | `/jobs/(?P<id>\d+)/(?P<action>pause\|resume\|cancel)` | `control_job` | |
| GET | `/spend` | `spend` | `{today_usd, month_usd, daily_cap, monthly_cap}` |
| POST | `/migrate-from-meta` | `migrate_from_meta` | `{action:"skip"}` or import |
| GET | `/settings` | `get_settings` | Strips `api_key`, adds `api_key_set: bool` |
| PUT | `/settings` | `put_settings` | Same response shape |
| PUT | `/post-overrides/(?P<id>\d+)` | `put_post_overrides` | `{add}` and/or `{remove}` |

Error codes in use: `invalid_schema` (400), `missing_url` (400), `invalid_key` (400), `no_api_key` (400), `not_found` (404), `spend_cap_reached` (429), `key_error` / `schema_generate_error` (500), `ai_error` (502).

## AI usage

**Vendor:** Anthropic Claude, `POST https://api.anthropic.com/v1/messages`, header `anthropic-version: 2023-06-01`, `x-api-key: <key>`, `wp_remote_post` with a 60 s timeout. There is no OpenAI path in this feature.

**Structured output:** `max_tokens: 2048`, one tool named `emit_jsonld` with `input_schema = { type: object, properties: {}, additionalProperties: true }`, forced via `'tool_choice' => [ 'type' => 'tool', 'name' => 'emit_jsonld' ]`. The client walks `content[]` for the matching `tool_use` block; anything else returns `['error' => 'no_tool_use_block']`.

**Prompts** (`Prompt_Builder`) — system prompt for per-post generation:

> You generate schema.org JSON-LD for web pages. Pick the most specific @type from schema.org that fits the content. Return strictly valid JSON-LD that would pass Google Rich Results validation. Use https://schema.org as @context. Do not invent properties.

User content is `Title / URL / Post type / Content`, with content trimmed to a 6,000-token budget (4 chars ≈ 1 token) keeping the first 70% and last 20% around a `[...content truncated...]` marker.

**Key storage:** encrypted at rest by `Crypto\Secret_Store` — AES-256-GCM, 12-byte IV, 16-byte tag, `v1:` version prefix, key derived with `hash_hkdf( 'sha256', AUTH_KEY.SECURE_AUTH_KEY.AUTH_SALT.SECURE_AUTH_SALT, 32, 'amplifi-security:secret-store:v1' )`. Persisted to `ac_schema_secret_anthropic_api_key` with `autoload = false`. `GET/PUT /settings` never return it; the dashboard form only shows a placeholder. Plaintext is only ever handed to `api.anthropic.com`. The feature refuses to load at all without the OpenSSL extension.

**Models and pricing** (`Spend_Tracker::PRICING`, USD per million tokens):

| Model id | Input | Output |
|----------|-------|--------|
| `claude-haiku-4-5-20251001` (activator default) | 1.00 | 5.00 |
| `claude-sonnet-4-6` | 3.00 | 15.00 |
| `claude-opus-4-7` | 15.00 | 75.00 |

Unknown model ids fall back to Sonnet pricing. All three are selectable on the dashboard and bulk pages; unlike amplifi.security there is no server-side allowlist, so `PUT /settings` will store any string.

**Spend caps:** defaults `daily_spend_cap_usd = 5.0`, `monthly_spend_cap_usd = 50.0`, both editable. `can_spend( $estimated )` is a pre-flight check requiring *both* `today + est <= daily` and `month + est <= monthly`; today is a single-row lookup on `day = gmdate('Y-m-d')`, month is `SUM(cost_usd) WHERE day >= gmdate('Y-m-01')`. Both interactive AI routes pre-check with a flat `$0.05`; the bulk runner pre-checks the same figure before every post and flips the job to `paused` on refusal. `record()` is an `ON DUPLICATE KEY UPDATE` accumulate against the day row.

## Single `@graph` output

`Head_Output::register()` reads `output_priority` from settings (default `1`) at registration time and hooks `wp_head` at that priority. `emit()` builds a context of `post_id` (`get_queried_object_id()` when `is_singular()`, else `0`) plus every matching URL-rule ID, hands it to `Graph_Builder`, and returns early when `@graph` is empty.

`Graph_Builder::build()` appends, in order:

1. `global` scope entries for `organization`, `website`, `localbusiness`
2. `url_rule` scope entries for every rule ID whose pattern matched
3. `post` scope entries for the current post

Then strips `@context` from each member and wraps the result:

```php
[ '@context' => 'https://schema.org', '@graph' => $graph ]
```

Output uses `wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT )`. `JSON_HEX_TAG` converts `<`/`>` to `\u003C`/`\u003E`, so stored content containing `</script>` cannot break out of the block — the exact failure mode flagged against amplifi.meta in `docs/audits/20260218-222518-security.md`.

URL rules are matched against `wp_parse_url( $url, PHP_URL_PATH )` only — never the query string — with `fnmatch()` for `glob` and error-suppressed `preg_match()` for `regex`.

## Validation

`Schema\Validator` returns `{ ok, errors, warnings }`. `ok` is true when `errors` is empty; warnings never block a save.

Errors:

| Code | Trigger |
|------|---------|
| `invalid_json` | `json_decode` failure |
| `not_object` | Decoded value is not an array/object |
| `missing_context` | `@context` is not a string equal to `https://schema.org` or `http://schema.org` (trailing slash trimmed) |
| `missing_type` | `@type` is not a string; validation returns immediately |

Warnings:

| Code | Trigger |
|------|---------|
| `unknown_type` | `@type` is not in the bundled registry (custom types are legal JSON-LD) |
| `unknown_property` | Property is not in `properties_for( $type )` plus the JSON-LD keyword allowlist |
| `missing_required_for_rich_results` | One of `required_for_rich_results` is absent |

Commit `fb52695` demoted the last three from errors to warnings; commit `28f4dd9` made the index build walk all parent chains so, e.g., `LocalBusiness` inherits `Organization` properties.

## Foreign-source detection and override

`Schema\Detector::detect_for_url()` does a `wp_remote_get` (5 s timeout, 3 redirects, UA `amplifi.schema-detector/1.0`), truncates bodies over 5 MB, regex-extracts every `<script … type="application/ld+json">` block, HTML-entity-decodes it, expands `@graph` into individual entities, and caches the result for an hour.

`guess_source()` inspects the **script tag** (not its body) in this order:

| Marker in the tag | Reported `source` |
|-------------------|-------------------|
| `yoast-schema-graph` or `yoast` | `yoast` |
| `rank-math` or `rank_math` | `rankmath` |
| `seopress` | `seopress` |
| `aioseo` | `aioseo` |
| `amplifi-schema` | `amplifi-schema` |
| `ac-jsonld-data` | `amplifi-meta` |
| anything else | `unknown` |

`Frontend\Foreign_Suppressor::register()` attaches four vendor filters at priority 99:

| Filter | Vendor payload shape | Strategy |
|--------|---------------------|----------|
| `wpseo_schema_graph` | flat `@graph` array | `array_filter` out pieces whose `@type` intersects the kill list |
| `rank_math/json_ld` | assoc array keyed by type | `unset( $data[ $type ] )` |
| `seopress_pro_schemas_json` | array of entities | `array_filter` on exact `@type` match |
| `aioseo_schema_output` | rendered HTML string | `preg_replace` of `<script … "@type":"<Type>" …</script>` |

The kill list (`overridden_types()`) is empty on non-singular requests. On a singular request it is the union of:

- `_ac_schema_overrides` post meta (explicit, set through the editor banner), and
- **auto-override** — the `schema_type` of every `ac_schema_entries` row for `scope_type = 'post', scope_id = <post ID>`.

Auto-override is the important half: as soon as amplifi.schema has an `Article` entry for a post, the Yoast/Rank Math/SEOPress/AIOSEO `Article` disappears from that page whether or not anyone clicked Override.

amplifi.meta is suppressed differently — the entry file defines `AMPLIFI_SCHEMA_ACTIVE`, and `features/meta/ac-bulk-meta.php` early-returns from its own `wp_head` JSON-LD emitter (`if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) { return; }`) and shows a deprecation banner on its JSON-LD page.

## Bulk generation

`POST /jobs` counts the scope with a `posts_per_page => -1, fields => ids` query, inserts a `queued` row, and schedules `wp_schedule_single_event( time(), 'ac_schema_run_bulk_batch', [ $job_id ] )`.

Per tick (`Bulk_Job::run_batch()`):

1. Bail unless status is `queued` or `running`; flip to `running` (stamping `started_at` once).
2. `Secret_Store::get( 'anthropic_api_key' )` — missing key sets the job `failed`.
3. `next_post_ids()` selects up to `BATCH_SIZE = 5` published posts in the scope, ordered by ID ascending, excluding every post that already has an `ai`-sourced entry.
4. Empty result → `completed`.
5. Per post: `can_spend( 0.05 )` (else `paused` and break) → build prompt from `wp_strip_all_tags( $post->post_content )` → generate → `Spend_Tracker::record()` → validate → `Entry_Store::save()` with `source = 'ai'`.
6. `record_progress()` accumulates `processed`, `failed`, `cost_usd`.
7. If still `running`, re-arm with `wp_schedule_single_event( time() + 30, … )`.

`control_job` maps `pause` → `paused`, `resume` → `running` plus an immediate re-schedule, and `cancel` → `failed`.

## Migration from amplifi.meta

`Activator::activate()` sets `ac_schema_meta_import_status = 'pending'` when `AC_BULK_META_VERSION` is defined or the legacy `ac-bulk-meta/ac-bulk-meta.php` plugin is active, and only when the option has never been set.

`Admin::maybe_render_import_notice()` fires on `admin_notices` for `manage_options` users when the status is `pending`. It counts `_ac_jsonld_data` rows; a zero count silently flips the status to `done`. Otherwise it renders a dismissible notice with Import / Skip buttons that `POST /migrate-from-meta`.

`Meta_Importer::import_all()`:

- Reads every `_ac_jsonld_data` postmeta row, `maybe_unserialize`s it, and accepts either an array or a JSON string.
- Expands `@graph` into separate entities; each entity gets `@context: https://schema.org` if absent and is saved with `scope_type = 'post'`, `source = 'imported'`, `schema_type` from `@type` (first element if array, else `Thing`).
- Copies `ac_jsonld_settings['organization']` into `ac_schema_global_organization` and a matching `global` entry, but only when `ac_schema_global_organization` is currently empty.
- Sets `ac_schema_meta_import_status = 'done'` and forces `suppress_amplifi_meta_jsonld = true`.
- Returns `{imported, skipped}`.

`Meta_Importer::skip()` sets the status to `skipped`.

## Uninstall

`plugins/amplifi-plugins/uninstall.php` globs `features/*/uninstall.php` and includes each one. The schema copy drops all three tables, deletes `ac_schema_settings`, the three `ac_schema_global_*` keys, `ac_schema_url_rules`, `ac_schema_db_version`, `ac_schema_onboarding_complete`, `ac_schema_meta_import_status`, and both post-meta keys.

It does **not** delete `ac_schema_secret_anthropic_api_key`. The encrypted key survives uninstall.

## Pitfalls

- **The amplifi.meta import notice never fires in the combined plugin.** `Activator` checks `defined( 'AC_BULK_META_VERSION' )`, but `features/meta/ac-bulk-meta.php` defines `ACMETA_VERSION`. The `is_plugin_active( 'ac-bulk-meta/ac-bulk-meta.php' )` fallback only matches the retired standalone plugin. On a monorepo install `ac_schema_meta_import_status` stays unset, the notice never renders, and `POST /migrate-from-meta` must be called by hand.
- **Auto-override is silent and non-obvious.** Creating any post-scope entry suppresses the matching `@type` from all four SEO plugins for that URL, with no UI indication and no way to opt out short of deleting the entry. Debug this before assuming a Yoast filter broke.
- **`Head_Output` reads `output_priority` at hook-registration time**, inside `register()` on `plugins_loaded`. Changing it through `PUT /settings` has no effect until the next request.
- **`Validator` rejects array-form `@context`.** `CONTEXT_OK` compares a string, so the perfectly legal `"@context": ["https://schema.org", {...}]` fails with `missing_context` and blocks the save.
- **`Bulk_Job::next_post_ids()` builds `post__not_in` from every AI-generated `scope_id` in the table.** On a large site that array — and the resulting `NOT IN (…)` clause — grows with every completed post. There is no pagination or cursor.
- **`cancel` is stored as `failed`.** `Job_Store` has no `cancelled` status, so cancelled jobs are indistinguishable from genuinely failed ones in the dashboard and in `GET /jobs`.
- **`Secret_Store` in this feature is a near-verbatim copy of the amplifi.security one** — its HKDF `KEY_INFO` is still `'amplifi-security:secret-store:v1'` and its no-`AUTH_KEY` fallback writes `amplifi_security_fallback_key`. Both features share that option; the security feature's `uninstall.php` deletes every `amplifi_security_*` option, which would destroy the key material needed to decrypt schema's stored Anthropic key on a site running only the fallback path.
- **`_ac_schema_detected_cache` post meta and `ac_schema_onboarding_complete` do not exist.** Both appear only in `uninstall.php`. Detection caching is transient-based (`ac_schema_detected_<md5(url)>`, 1 hour); there is no onboarding flow.
- **The detector cache is keyed on URL, not post ID, and never invalidated on save.** Editing schema and reloading the edit screen shows up to an hour of stale foreign-detection results.
- **The AIOSEO suppression regex uses `[^<]*` around the `@type` match**, so it only strips scripts whose payload contains no `<` characters and where `"@type":"X"` appears before any other tag-like content. Nested or escaped markup in AIOSEO's output will survive.
- **`generate_entry` leaks internal paths on failure.** Its catch-all returns `$e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()` to the client. It is `manage_options`-gated, but the absolute server path ends up in the browser.
- **No model allowlist on write.** `PUT /settings` stores `default_model` verbatim; an unrecognised id silently falls back to Sonnet *pricing* in `Spend_Tracker` while the API call itself fails with an Anthropic 4xx.
- **The dual-pane form editor described in `docs/superpowers/specs/2026-05-21-amplifi-schema-design.md` was never built.** The metabox is a raw-JSON textarea. `includes/admin/assets/` (git-ignored in `.gitignore` as `includes/admin/assets/dist/`) does not exist and no React bundle is enqueued.
- **`git log` history for the feature lives under the old path.** Most substantive commits (`fb52695`, `2091dcc`, `28f4dd9`, `31c2349`, `ecb4224`, `429f8ee`, `725177d`) predate `d65d50b feat: create combined amplifi.plugins with all 10 features`, which is where `plugins/ac-schema/` was copied to `features/schema/`. `git log -- plugins/amplifi-plugins/features/schema` alone will miss them; add `plugins/ac-schema` to the pathspec. Note that `plugins/ac-schema/` still exists in the repo and has since **diverged** from the shipping copy in six files, including `class-validator.php` and `class-rest-controller.php`.
