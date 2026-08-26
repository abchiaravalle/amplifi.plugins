# amplifi.optimize

AI-assisted SEO triage. A scanner pass finds fixable issues and writes `pending` rows to a suggestions table, a proposer pass sends batches to the Anthropic Claude Messages API to draft fixes, and an applier writes the approved value into whichever SEO plugin is active — only after a human approves it in the review queue. Every applied change stores a `previous_snapshot` so it can be undone.

## At a glance

| | |
|---|---|
| Feature slug | `optimize` |
| Entry file | `plugins/amplifi-plugins/features/optimize/amplifi-optimize.php` |
| Version constant | `AMPLIFI_OPTIMIZE_VERSION` (`3.3.7`) |
| Plugin slug constant | `AMPLIFI_OPTIMIZE_SLUG` = `amplifi-optimize` |
| DB tables | `{$wpdb->prefix}amplifi_optimize_suggestions` |
| REST namespace | `AMPLIFI_OPTIMIZE_REST_NAMESPACE` = `amplifi-optimize/v1` |
| AI provider | Anthropic Claude (`AMPLIFI_OPTIMIZE_API_BASE` = `https://api.anthropic.com/v1/messages`) |
| Default model | `AMPLIFI_OPTIMIZE_DEFAULT_MODEL` = `claude-sonnet-4-5` |
| Files / PHP LOC | 56 files, ~3,872 PHP LOC |
| Admin UI | React (`@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`) |
| WP-CLI | `wp amplifi-optimize <scan\|propose\|apply\|report>` |

### Loading

Like every feature in the monorepo, `optimize` only loads when its slug is present in the `amplifi_plugins_enabled_features` option array. The dispatcher is in `plugins/amplifi-plugins/amplifi-plugins.php`:

```php
$amplifi_enabled = get_option( 'amplifi_plugins_enabled_features', [] );
foreach ( $amplifi_all_features as $slug => $feature ) {
    if ( ! in_array( $slug, $amplifi_enabled, true ) ) { continue; }
    require_once AMPLIFI_PLUGINS_PATH . $feature['file'];
}
```

The entry file self-guards on `AMPLIFI_OPTIMIZE_VERSION` being already defined and returns early if so, then registers activation/deactivation hooks and boots the singleton on `plugins_loaded`.

## Constants

Defined in `amplifi-optimize.php`:

| Constant | Value |
|---|---|
| `AMPLIFI_OPTIMIZE_VERSION` | `3.3.7` |
| `AMPLIFI_OPTIMIZE_FILE` / `_DIR` / `_URL` / `_BASENAME` | Standard path constants |
| `AMPLIFI_OPTIMIZE_SLUG` | `amplifi-optimize` |
| `AMPLIFI_OPTIMIZE_TEXT_DOMAIN` | `amplifi-optimize` |
| `AMPLIFI_OPTIMIZE_REST_NAMESPACE` | `amplifi-optimize/v1` |
| `AMPLIFI_OPTIMIZE_DEFAULT_MODEL` | `claude-sonnet-4-5` |
| `AMPLIFI_OPTIMIZE_API_BASE` | `https://api.anthropic.com/v1/messages` |
| `AMPLIFI_OPTIMIZE_ANTHROPIC_VERSION` | `2023-06-01` |

## Architecture

The design is a registry of four **fix types**, each backed by a scanner / proposer / applier triple registered in `Amplifi_Optimize_Plugin::register_fix_types()`:

```php
'meta_description' => [ 'label' => …, 'scanner' => …, 'proposer' => …, 'applier' => … ],
'alt_text'         => [ … ],
'title'            => [ … ],
'unpublish'        => [ … ],
```

### Class map

| File | Class | Responsibility | Hooks |
|---|---|---|---|
| `includes/class-plugin.php` | `Amplifi_Optimize_Plugin` | Singleton. Requires all includes, builds `db`/`seo`/`claude`, registers fix types, loads text domain, registers WP-CLI commands when `WP_CLI` is defined. | `plugins_loaded` → `instance()` (registered in entry file); `register_activation_hook` → `activate()`; `register_deactivation_hook` → `deactivate()` |
| `includes/class-database.php` | `Amplifi_Optimize_Database` | All SQL against the suggestions table: `install()` (dbDelta), `insert`, `update`, `get`, `list`, `counts_by_status`, `counts_by_fix_type`, `pending_exists`, `recent_applied`. JSON-encodes `proposed_metadata` on write, decodes on read. `DB_VERSION = '1.0.0'`. | — |
| `includes/class-encryption.php` | `Amplifi_Optimize_Encryption` | Static AES-256-CBC helpers. `PREFIX = 'enc:v1:'`, key = `hash('sha256', wp_salt('auth'), true)`. Random IV prepended to ciphertext, base64-encoded. Values without the prefix are returned unchanged (legacy plaintext read path). | — |
| `includes/class-claude-client.php` | `Amplifi_Optimize_Claude_Client` | `wp_remote_post` against `/v1/messages`. `send_text()`, `send_vision()` (image block with `source.type = 'url'`), `send()`. Local sliding-window rate limiter, token accounting, defensive JSON extraction, 429 `retry-after` surfacing. | — |
| `includes/class-seo-detector.php` | `Amplifi_Optimize_SEO_Detector` | Detects Yoast / RankMath / AIOSEO (or a manual override) and maps to the right meta key for description, title, noindex. AIOSEO is read/written directly against `{prefix}aioseo_posts`. Also renders titles with a small `%%placeholder%%` substitution. | — |
| `includes/admin/class-admin-menu.php` | `Amplifi_Optimize_Admin_Menu` | Registers with the shared framework via `amplifi_register_plugin()` and adds four extra submenus under `amplifi-studio`. Renders one React mount point with a `data-screen` attribute. | `admin_menu` (default priority 10) |
| `includes/admin/class-rest-api.php` | `Amplifi_Optimize_REST_API` | All 13 REST routes. Single `permissions()` callback = `current_user_can('manage_options')`. `enrich_suggestion()` denormalises target post title / permalink / edit link / thumbnail into list responses. | `rest_api_init` |
| `includes/admin/class-assets.php` | `Amplifi_Optimize_Assets` | Enqueues `assets/build/index.js` + `index.css` only when the admin hook contains `amplifi-optimize`. Reads `index.asset.php` for dependencies/version, localizes `window.AmplifiOptimize`. | `admin_enqueue_scripts` |
| `includes/scanners/interface-scanner.php` | `Amplifi_Optimize_Scanner_Interface` | Contract: `fix_type(): string`, `scan(array $args): array{inserted,examined,skipped}`. Scanners never call Claude. | — |
| `includes/scanners/class-meta-description-scanner.php` | `…_Meta_Description_Scanner` | `WP_Query` over `included_post_types`, `post_status=publish`, `fields=ids`. Skips posts where the detected description is non-empty and rows that already have a pending/approved suggestion. Default limit 200. | — |
| `includes/scanners/class-alt-text-scanner.php` | `…_Alt_Text_Scanner` | Direct SQL `NOT EXISTS` against `postmeta` for `_wp_attachment_image_alt`. Skips SVG unless `include_svg`, and images under `min_image_dimension` on either axis. Default limit 500. | — |
| `includes/scanners/class-title-scanner.php` | `…_Title_Scanner` | `MAX_LEN = 60`. Compares `mb_strlen()` of the *rendered* title (template applied) and flags anything over 60. | — |
| `includes/scanners/class-unpublish-scanner.php` | `…_Unpublish_Scanner` | Multi-signal heuristic; any single signal flags the post. Stores fired signal names in `proposed_metadata.reasons`. Default limit 200. | — |
| `includes/proposers/interface-proposer.php` | `Amplifi_Optimize_Proposer_Interface` | Contract: `fix_type()`, `propose(array $args): array{processed,failed}`. | — |
| `includes/proposers/class-meta-description-proposer.php` | `…_Meta_Description_Proposer` | Chunks pending rows by `batch_size_meta` (default 10) and sends one Claude call per chunk (`max_tokens` 2048). Results matched back by `id`. | — |
| `includes/proposers/class-alt-text-proposer.php` | `…_Alt_Text_Proposer` | One `send_vision()` call per image (`max_tokens` 400). Writes progress to the `amplifi_optimize_scan_progress` transient. Records `is_decorative` and `char_count` into `proposed_metadata`, plus a usage lookup (`find_usage()`) showing which published posts reference the attachment. | — |
| `includes/proposers/class-title-proposer.php` | `…_Title_Proposer` | One call per row (`max_tokens` 400). Records `char_count` and `previous_chars`. Default limit 25. | — |
| `includes/proposers/class-unpublish-proposer.php` | `…_Unpublish_Proposer` | One call per row (`max_tokens` 400). Stores the classifier's `action` in `proposed_value` and `redirect_target` in `proposed_metadata`. Default limit 25. | — |
| `includes/appliers/interface-applier.php` | `Amplifi_Optimize_Applier_Interface` | Contract: `fix_type()`, `apply(array $suggestion): array{ok,error?,snapshot?}`, `undo(array $suggestion)`. | — |
| `includes/appliers/class-meta-description-applier.php` | `…_Meta_Description_Applier` | Reads the current value as the snapshot, then `SEO_Detector::set_meta_description()`. | — |
| `includes/appliers/class-alt-text-applier.php` | `…_Alt_Text_Applier` | `update_post_meta( $id, '_wp_attachment_image_alt', $value )`; snapshot is the previous string. | — |
| `includes/appliers/class-title-applier.php` | `…_Title_Applier` | Snapshot is a JSON blob of `{post_title, seo_title}`. Writes the SEO title meta and optionally `wp_update_post()` on `post_title`. | — |
| `includes/appliers/class-unpublish-applier.php` | `…_Unpublish_Applier` | Routes on the action: `delete` → `wp_trash_post()`, `redirect` → Redirection plugin row or built-in map + noindex, `noindex` → `set_noindex(true)`, `keep` → no-op. Serves the built-in map itself. | `template_redirect` (default priority 10) → `maybe_redirect()` |
| `includes/prompts/*.php` | — | Four system prompts returned as heredoc strings: `meta-description.php`, `alt-text.php`, `title.php`, `unpublish.php`. | — |
| `includes/cli/class-cli-commands.php` | `Amplifi_Optimize_CLI_Commands` | `wp amplifi-optimize scan|propose|apply|report`. Loaded only under `WP_CLI`. | — |

## Admin UI

`Amplifi_Optimize_Admin_Menu::register()` calls `amplifi_register_plugin('amplifi-optimize', 'Optimize', …)`, which the shared framework turns into a submenu of the top-level `amplifi-studio` menu. Four more submenus are added directly. All require `manage_options`.

| Screen | Page slug | Hook suffix |
|---|---|---|
| Dashboard (framework-registered) | `amplifi-optimize` | `amplifi-studio_page_amplifi-optimize` |
| Optimize: Scans | `amplifi-optimize-scans` | `amplifi-studio_page_amplifi-optimize-scans` |
| Optimize: Queue | `amplifi-optimize-queue` | `amplifi-studio_page_amplifi-optimize-queue` |
| Optimize: History | `amplifi-optimize-history` | `amplifi-studio_page_amplifi-optimize-history` |
| Optimize: Settings | `amplifi-optimize-settings` | `amplifi-studio_page_amplifi-optimize-settings` |

Every screen renders the same markup — `<div id="amplifi-optimize-root" data-screen="…">` — and the React app switches on `data-screen`. The screen name is derived by stripping the `amplifi-optimize-` prefix from `$_GET['page']`; a bare `amplifi-optimize` becomes `dashboard`.

`Amplifi_Optimize_Assets::enqueue()` gates on `strpos( $hook, 'amplifi-optimize' ) !== false`, so all five screens get the bundle. It localizes:

```js
window.AmplifiOptimize = {
  restUrl, nonce, fixTypes, adminUrl, siteName, version, provider
}
```

React sources live in `assets/src/` (`Dashboard.jsx`, `Scans.jsx`, `ReviewQueue.jsx`, `History.jsx`, `Settings.jsx`, and one `SuggestionCard/` variant per fix type). The queue is keyboard-driven: A approve, R reject, E edit, S/→ skip.

## Database

### `{$wpdb->prefix}amplifi_optimize_suggestions`

Created by `Amplifi_Optimize_Database::install()` via `dbDelta`, invoked from `Amplifi_Optimize_Plugin::activate()`.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | |
| `fix_type` | `VARCHAR(50) NOT NULL` | `meta_description` \| `alt_text` \| `title` \| `unpublish` |
| `target_type` | `VARCHAR(20) NOT NULL` | `post` or `attachment` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` | WP post/attachment ID |
| `current_value` | `LONGTEXT NULL` | Value at scan time |
| `proposed_value` | `LONGTEXT NULL` | Claude's draft (for `unpublish`, the action keyword) |
| `proposed_metadata` | `LONGTEXT NULL` | JSON: `reasoning`, `char_count`, `is_decorative`, `reasons`, `redirect_target`, `url`, `modified` |
| `claude_response_raw` | `LONGTEXT NULL` | Raw API body for debugging |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` | `pending` \| `approved` \| `applied` \| `rejected` \| `failed` |
| `applied_at` | `DATETIME NULL` | |
| `previous_snapshot` | `LONGTEXT NULL` | Undo payload; string or JSON depending on applier |
| `error_message` | `TEXT NULL` | |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |
| `updated_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | |

Indexes: `KEY idx_status_type (status, fix_type)`, `KEY idx_target (target_type, target_id)`.

### Options

| Option | Autoload | Contents |
|---|---|---|
| `amplifi_optimize_api_key` | default | Anthropic key, AES-256-CBC encrypted with the `enc:v1:` prefix |
| `amplifi_optimize_settings` | default | Settings array (below) |
| `amplifi_optimize_token_usage` | `false` | `{ model: { input, output, calls } }` cumulative |
| `amplifi_optimize_db_version` | default | `1.0.0` |
| `amplifi_optimize_delete_data_on_uninstall` | default | Bool mirror of the setting, read by `uninstall.php` |
| `amplifi_optimize_redirect_map` | default | `{ '/source-path' => '/target' }` for the built-in redirect fallback |

Settings keys and defaults from `Amplifi_Optimize_Plugin::get_settings()`:

| Key | Default |
|---|---|
| `model` | `claude-sonnet-4-5` |
| `batch_size_meta` | `10` |
| `batch_size_alt` | `5` |
| `rate_limit_per_minute` | `50` |
| `included_post_types` | `['post','page']` |
| `min_image_dimension` | `100` |
| `include_svg` | `false` |
| `date_range_days` | `0` |
| `detector_override` | `auto` |
| `delete_data_on_uninstall` | `false` |
| `undo_window` | `50` |

### Transients

| Transient | TTL | Purpose |
|---|---|---|
| `amplifi_optimize_scan_progress` | — | Alt-text propose progress; deleted on deactivation |
| `amplifi_optimize_rate_window` | 120s | Sliding-window request counter (`{ window_start, count }`) |

### Post meta written

The keys depend on the detected SEO provider:

| Provider | Description | Title | Noindex |
|---|---|---|---|
| Yoast | `_yoast_wpseo_metadesc` | `_yoast_wpseo_title` | `_yoast_wpseo_meta-robots-noindex` |
| RankMath | `rank_math_description` | `rank_math_title` | `rank_math_robots` (array, `noindex` member) |
| AIOSEO | `{prefix}aioseo_posts.description` (table, not meta) | `''` (unsupported) | `_amplifi_optimize_noindex` |
| None | `_amplifi_optimize_metadesc` | `_amplifi_optimize_title` | `_amplifi_optimize_noindex` |

Alt text always writes core's `_wp_attachment_image_alt`.

## REST API

Namespace `amplifi-optimize/v1`. **Every route** uses the same permission callback:

```php
public function permissions(): bool {
    return current_user_can( 'manage_options' );
}
```

Authentication from the admin UI is the standard `wp_rest` nonce passed via `@wordpress/api-fetch`.

| Method | Route | Purpose |
|---|---|---|
| POST | `/scan/(?P<fix_type>[a-z_]+)` | Run the scanner; args `limit`. Returns `{inserted, examined, skipped}` |
| POST | `/propose/(?P<fix_type>[a-z_]+)` | Run the proposer against pending rows; args `limit`. Returns `{processed, failed}` |
| GET | `/scan/progress` | Reads the `amplifi_optimize_scan_progress` transient |
| GET | `/suggestions` | List; args `type`, `status` (default `pending`), `page`, `per_page` (clamped 1–100, default 20) |
| POST | `/suggestions/(?P<id>\d+)/approve` | Runs the applier; on success sets `status=applied`, `applied_at`, `previous_snapshot`. On failure sets `status=failed` + `error_message` and returns HTTP 500 |
| POST | `/suggestions/(?P<id>\d+)/reject` | Sets `status=rejected` |
| POST | `/suggestions/(?P<id>\d+)/edit` | Body `{proposed_value, then_approve?}`. Overwrites the draft, optionally chains into approve |
| POST | `/suggestions/(?P<id>\d+)/undo` | Requires `status=applied`; calls `applier->undo()` then sets `status=rejected` |
| POST | `/suggestions/(?P<id>\d+)/retry` | Clears `proposed_value`, `claude_response_raw`, `error_message` and resets to `pending` |
| POST | `/suggestions/batch-approve` | Body `{ids:int[]}`. Loops `approve()` per id, returns a per-id `{ok}`/`{ok:false,error}` map |
| GET | `/stats` | `{by_status, by_type, pending, usage}` |
| GET | `/settings` | Settings plus `has_api_key`; the key itself is never returned |
| POST | `/settings` | Write-only `api_key`, plus an allow-list of sanitized keys |

`POST /settings` sanitizes through an explicit allow-list — `model` (`sanitize_text_field`), `batch_size_meta`/`batch_size_alt`/`rate_limit_per_minute`/`min_image_dimension`/`date_range_days`/`undo_window` (`intval`), `include_svg`/`delete_data_on_uninstall` (`rest_sanitize_boolean`), `detector_override` (`sanitize_key`), and `included_post_types` (array mapped through `sanitize_key`). Anything else in the body is dropped.

## AI provider

**Anthropic Claude, definitively.** Proof: `includes/class-claude-client.php` line 152–153 sets `x-api-key` and `anthropic-version` headers, and `amplifi-optimize.php` line 18 defines the endpoint:

```php
// amplifi-optimize.php:18
define( 'AMPLIFI_OPTIMIZE_API_BASE', 'https://api.anthropic.com/v1/messages' );
```

```php
// includes/class-claude-client.php:147-158
$response = wp_remote_post(
    AMPLIFI_OPTIMIZE_API_BASE,
    array(
        'timeout' => $timeout,
        'headers' => array(
            'x-api-key'         => $api_key,
            'anthropic-version' => AMPLIFI_OPTIMIZE_ANTHROPIC_VERSION,
            'content-type'      => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
    )
);
```

There is no OpenAI code path in this feature.

- **Model:** `claude-sonnet-4-5` by default; overridable per-request via `$opts['model']` and globally via the `model` setting. There is no model-list fetch — the model is a free-text setting.
- **Key storage:** `amplifi_optimize_api_key` option, encrypted at rest with AES-256-CBC keyed off `wp_salt('auth')` (`Amplifi_Optimize_Encryption`). `GET /settings` returns only a `has_api_key` boolean.
- **Vision:** alt text uses an image content block with `source.type = 'url'` pointing at the public attachment URL. The image is never inlined.
- **Spend caps:** **there are none.** There is no per-day or per-month USD cap, and no cost estimate anywhere in the feature. The only throttle is a request-rate limiter — `rate_limit_per_minute` (default 50) enforced by `rate_limit_gate()`, a 60-second sliding window backed by the `amplifi_optimize_rate_window` transient. Exceeding it returns an error with `retry_after` rather than calling the API. Spend visibility is retrospective only: `amplifi_optimize_token_usage` accumulates `{input, output, calls}` per model and is surfaced on the Dashboard and by `wp amplifi-optimize report`. Contrast with amplifi.alt, which does enforce a dollar cap.
- **Rate-limit handling:** an upstream HTTP 429 is returned as an error carrying the `retry-after` header value; the client does not retry automatically.
- **Response parsing:** `extract_json()` tries strict `json_decode`, then a ```` ```json ```` fenced block, then the first balanced `{...}`/`[...]` in the text.

## Scan → propose → approve workflow

1. **Scan.** `POST /scan/{fix_type}` (or `wp amplifi-optimize scan <type>`). The scanner queries the site, skips anything with an existing `pending` or `approved` row for the same `fix_type` + target (`Database::pending_exists()`), and inserts `status=pending` rows with `current_value` populated. No API calls happen here — a scan is free.
2. **Propose.** `POST /propose/{fix_type}` (or `wp amplifi-optimize propose <type>`). Pulls `pending` rows that have no `proposed_value` yet and sends them to Claude. Meta descriptions are batched (`batch_size_meta`, default 10, one call per chunk with results matched back by `id`); the other three are one call per row. On success the row gets `proposed_value`, `proposed_metadata` (including the model's `reasoning`) and `claude_response_raw`. On failure the row becomes `status=failed` with `error_message`, recoverable via `/retry`.
3. **Human approve.** Nothing is written to the site until a `manage_options` user hits `/suggestions/{id}/approve` from the Review Queue (or `wp amplifi-optimize apply <type> --auto`, which is the deliberate bypass). The applier returns a snapshot which is stored in `previous_snapshot` alongside `status=applied` and `applied_at`. Alternatives from the queue: `/reject`, `/edit` (optionally `then_approve`), `/undo` on an already-applied row, and `/batch-approve` for a buffered set.

`--auto` on the CLI `apply` command is the only path that applies suggestions without an interactive approval step.

### Issue classes detected

| Fix type | Detection | Proposal contract |
|---|---|---|
| `meta_description` | Published posts in `included_post_types` where the active SEO plugin's description meta is empty or whitespace | 140–155 characters; forbidden openers (`Learn`, `Discover`, `Find out`, `Explore`, `In this article`); no quotes/emoji/exclamation marks. Returns `{results:[{id, meta_description, reasoning}]}` |
| `alt_text` | Image attachments (`post_mime_type LIKE 'image/%'`) with no non-empty `_wp_attachment_image_alt`. SVGs skipped unless `include_svg`; images smaller than `min_image_dimension` on either axis skipped | Under 125 chars; no "Image of"/"Picture of"/"Photo of" opener; transcribe visible wordmarks; decorative images return `is_decorative=true` with empty `alt_text`. Returns `{alt_text, is_decorative, reasoning}` |
| `title` | Rendered SEO title (`SEO_Detector::rendered_title()`, `%%placeholder%%` substitution applied) longer than `MAX_LEN = 60` characters by `mb_strlen()` | ≤58 characters, primary keyword preserved, brand suffix excluded (appended outside the model output). Returns `{title, reasoning}` |
| `unpublish` | Any one of four signals fires: `title_pattern` (regex over `test\|draft\|don't delete\|coming soon\|reference\|temp\|placeholder\|-2\|-3\|copy of`), `thin_content` (stripped content under `THIN_CHARS = 100`), `url_suffix` (permalink matching `-2\|-3\|-temp\|-old\|-new\|-copy`), `stale_no_inbound` (unmodified for `STALE_YEARS = 3` **and** no other published post links to its path) | Classify as `delete` \| `redirect` \| `noindex` \| `keep`, defaulting to `keep` when unsure. Returns `{action, redirect_target, reasoning}` |

The `unpublish` applier's `redirect` branch prefers the Redirection plugin (`{prefix}redirection_items`, 301, `status=enabled`, `group_id=1`) when that table exists, and otherwise writes to `amplifi_optimize_redirect_map` and serves the redirect itself on `template_redirect`.

## WP-CLI

```bash
wp amplifi-optimize scan meta_description --limit=500 [--offset=<n>]
wp amplifi-optimize propose meta_description --limit=50
wp amplifi-optimize apply meta_description --auto --limit=200
wp amplifi-optimize report
```

`report` prints counts by status and fix type plus per-model token usage (`calls`, `input`, `output`).

## Uninstall

`uninstall.php` is a no-op unless `amplifi_optimize_delete_data_on_uninstall` is truthy. When it is, it drops `{$wpdb->prefix}amplifi_optimize_suggestions` and deletes `amplifi_optimize_api_key`, `amplifi_optimize_model`, `amplifi_optimize_settings`, `amplifi_optimize_token_usage`, `amplifi_optimize_db_version`, and the opt-in flag itself. Note that `amplifi_optimize_redirect_map` is **not** in the deletion list — redirects registered through the built-in fallback map survive uninstall as an orphaned option (harmless once the code is gone, but it is left behind).

## Pitfalls

- **No spend cap.** The rate limiter caps requests per minute, not dollars. A scan that inserts thousands of alt-text rows followed by an unbounded `wp amplifi-optimize propose alt_text --limit=5000` will issue one vision call per image with nothing stopping it. Set `--limit` deliberately, and watch `amplifi_optimize_token_usage` on the Dashboard.
- **Alt text requires a publicly reachable image URL.** `send_vision()` passes `source.type = 'url'`; Anthropic fetches the image itself. On localhost, a private network, or behind a Cloudflare bot-fight rule the fetch fails and the row lands in `failed`. There is no base64 inlining fallback here (amplifi.alt has one; optimize does not).
- **AIOSEO titles are silently unsupported.** `SEO_Detector::title_key()` returns `''` for AIOSEO, so `set_title()` returns `false` immediately and the title applier fails. AIOSEO descriptions work, via direct writes to `{prefix}aioseo_posts` — which bypasses AIOSEO's own model layer and any caching it does.
- **AIOSEO must already be installed.** The detector only branches on `AIOSEO_VERSION` / `aioseo()`. Installing AIOSEO after the fact leaves earlier suggestions pointing at `_amplifi_optimize_metadesc` keys the site no longer reads.
- **`pending_exists()` blocks re-scanning, not re-proposing.** It matches `status IN ('pending','approved')`, so a `rejected` or `failed` row does *not* prevent a fresh scan from inserting a duplicate suggestion for the same target. Re-running a scan after rejecting suggestions will re-surface them.
- **`batch_approve()` fabricates sub-requests.** It builds a bare `new WP_REST_Request('POST')` with only `id` set, so the per-item permission check re-runs against the *outer* request's user context. It works, but any future body params on `approve()` would be lost in the batch path.
- **Redirect matching is first-match and exact.** `maybe_redirect()` iterates `amplifi_optimize_redirect_map` in insertion order comparing `rtrim($src,'/') === $path`. Query strings are stripped by `wp_parse_url(..., PHP_URL_PATH)`, there is no regex/wildcard support, and the hook runs at default priority 10 — after most caching and canonical plugins have had their turn.
- **Undo of a `redirect` leaves Redirection rows behind.** `unregister_redirect()` only touches the option-backed map; the comment in `class-unpublish-applier.php` states Redirection plugin rows are intentionally left intact to avoid clobbering manual edits. Undoing a redirect on a site with Redirection installed reverts the noindex but not the 301.
- **Undo sets `status=rejected`, not `pending`.** After undoing you cannot re-approve the same row; you must `/retry` it (which discards the draft) or re-scan.
- **Encryption degrades silently.** If `openssl_encrypt` is unavailable the key is stored as plaintext with no prefix and no warning. `decrypt()` treats any unprefixed value as plaintext, which is what makes the legacy migration work — and also what makes a failed encrypt indistinguishable from a legacy key.
- **Rate limiter state lives in a transient.** On object-cache-backed sites with a non-persistent cache, or where transients are flushed aggressively, the window resets and the effective request rate exceeds `rate_limit_per_minute`.
- **Version constants are suite-wide.** `AMPLIFI_OPTIMIZE_VERSION` is `3.3.7`, matching the monorepo release, while `readme.txt` still declares `Stable tag: 1.0.0` and the changelog documents only a `1.0.0` release. Do not read feature maturity from those files.
- **Recent history is suite-level.** `git log --oneline -20 -- plugins/amplifi-plugins/features/optimize/` returns only monorepo-wide version bumps and unrelated `consent:` commits (`4098218`, `d2b8773`, `1865c12`, `9230115`, `47e1e40`, `8f39726`, `811144e`, …). Feature-scoped history is not separable from the suite release history at this path.
