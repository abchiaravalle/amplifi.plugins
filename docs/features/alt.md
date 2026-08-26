# amplifi.alt

Generates WCAG-oriented alt text for WordPress media library images using an OpenAI vision model. Work is queued in a custom jobs table and drained by a one-minute WP-Cron worker, so a bulk run over an existing library survives PHP timeouts and can be paused, retried, and audited. A daily USD spend cap, an auth-failure kill switch, a reachability probe that decides between sending an image URL and inlining base64, and a daily email report are all first-class.

## At a glance

| | |
|---|---|
| Feature slug | `alt` |
| Entry file | `plugins/amplifi-plugins/features/alt/ac-alt-text.php` |
| Version constant | `ACALT_VERSION` (`3.3.7`) |
| Framework slug | `ac-alt-text` (menu label "Alt") |
| DB tables | `{$wpdb->prefix}acalt_jobs` (e.g. `wp_acalt_jobs`) |
| REST namespace | none — the feature is admin-AJAX only |
| AI provider | OpenAI Chat Completions (`https://api.openai.com/v1/chat/completions`) |
| Default model | `gpt-4o-mini` |
| Files / PHP LOC | 11 files, ~3,006 PHP LOC |
| Cron hooks | `acalt_cron_drain` (every minute), `acalt_daily_report` (daily 09:00 UTC) |

### Loading

`alt` loads only when its slug is present in the `amplifi_plugins_enabled_features` option array, dispatched from `plugins/amplifi-plugins/amplifi-plugins.php`. The entry file guards on `ACALT_VERSION` already being defined, requires all eight includes, registers with the shared framework via `amplifi_register_plugin()`, and hooks `acalt_init` on `plugins_loaded` at **priority 1**.

```php
add_action( 'plugins_loaded', 'acalt_init', 1 );

function acalt_init() {
    ACALT_Cron::register();
    ACALT_Uploader_Hook::register();
    ACALT_Media_UI::register();
    if ( is_admin() ) {
        ACALT_Admin::instance()->init();
    }
}
```

The entry file also registers a custom `minute` cron schedule (60s interval) via the `cron_schedules` filter, guarded against re-registration.

### Design background

`docs/superpowers/specs/2026-05-14-amplifi-alt-design.md` is the approved design. Its non-goals are worth knowing: no cross-attachment dedupe, no translation of alt text (deferred to amplifi.translate), no captions/descriptions/titles, **no human-in-the-loop approval queue** (the "fills empty only" rule plus the audit table and retry action was judged sufficient), and no per-post-type rules. The spec's original per-plugin directory (`plugins/ac-alt-text/`) has since been folded into the monorepo feature layout.

## Architecture

### Class map

| File | Class | Responsibility | Hooks |
|---|---|---|---|
| `ac-alt-text.php` | — | Constants, includes, `amplifi_register_plugin()`, activation/deactivation, `minute` cron schedule. | `plugins_loaded` **priority 1** → `acalt_init()`; `cron_schedules` filter; `register_activation_hook` → `acalt_activate()`; `register_deactivation_hook` → `acalt_deactivate()` |
| `includes/class-acalt-queue.php` | `ACALT_Queue` | The jobs table. `create_table()` (dbDelta), `enqueue()` (`INSERT IGNORE`, dedupes on the unique attachment key), `reset_stale()`, `claim_batch()`, `mark_done/skipped/retry/park`, `reset_for_retry()`, `counts()`, `recent()`, `paged()`, `get()`, `enqueue_missing_alt()`. `TABLE = 'acalt_jobs'`. | — |
| `includes/class-acalt-reachability.php` | `ACALT_Reachability` | Decides whether OpenAI's fetcher can reach this site's image URLs. `probe()` (HEAD request), `current_mode()`, `info()`, `clear()`. `OPTION = 'acalt_reachability'`, `TTL = DAY_IN_SECONDS`. | — |
| `includes/class-acalt-generator.php` | `ACALT_Generator` | Per-image generation: validation, URL-vs-base64 decision, daily cap check, the OpenAI call, alt sanitisation, `update_post_meta()`, usage accounting. Also owns the queue pause/resume kill switch and the `PRICING` table. | — |
| `includes/class-acalt-cron.php` | `ACALT_Cron` | The worker. `drain()` with a 25-second tick budget; `park_remaining_claimed()`; `last_drain_at()`. `TICK_BUDGET_SECONDS = 25`. | `acalt_cron_drain` → `drain()`; `acalt_daily_report` → `ACALT_Report::send()` |
| `includes/class-acalt-uploader-hook.php` | `ACALT_Uploader_Hook` | Auto-enqueue on new uploads when `auto_on_upload` is on. | `add_attachment` (default priority 10) → `on_attachment()` |
| `includes/class-acalt-report.php` | `ACALT_Report` | Daily email: `send()`, `build_body_text()`, `build_body_html()`, `html_content_type()`. | Invoked from the `acalt_daily_report` cron action |
| `includes/class-acalt-admin.php` | `ACALT_Admin` | Three admin screens, settings save, and eight AJAX endpoints. Also `health_checks()`, which surfaces dashboard warnings. | `admin_menu` **priority 20**; `admin_post_acalt_save_settings`; `admin_post_acalt_retry_job`; eight `wp_ajax_acalt_*` actions |
| `includes/class-acalt-media-ui.php` | `ACALT_Media_UI` | "Generate now" affordances inside the media library: attachment edit field, list-table row action, and the media modal. | `wp_ajax_acalt_generate_now`; `attachment_fields_to_edit` **priority 20**, 2 args; `media_row_actions` (priority 10, 2 args); `wp_enqueue_media`; `admin_print_footer_scripts` **priority 99** |
| `includes/amplifi-framework.php` | — | Bundled copy of the shared framework; no-op when `AMPLIFI_FRAMEWORK_LOADED` is already defined. | — |
| `uninstall.php` | — | Drops the jobs table, deletes `acalt_settings` and `acalt_daily_stats`, clears both cron hooks. Unconditional — there is no opt-in gate. | — |

## Admin UI

Registration is split. The main dashboard comes from the framework (`amplifi_register_plugin('ac-alt-text', 'Alt', …, [ACALT_Admin::instance(), 'render_dashboard'])`), which produces the `amplifi-`-prefixed slug. Two more submenus are added directly to the `amplifi-studio` parent on `admin_menu` priority 20. All three require `manage_options`.

| Screen | Page slug | Hook suffix | Renderer |
|---|---|---|---|
| Alt (dashboard) | `amplifi-ac-alt-text` | `amplifi-studio_page_amplifi-ac-alt-text` | `render_dashboard()` |
| Alt: Jobs | `amplifi-ac-alt-text-jobs` | `amplifi-studio_page_amplifi-ac-alt-text-jobs` | `render_jobs()` |
| Alt: Settings | `amplifi-ac-alt-text-settings` | `amplifi-studio_page_amplifi-ac-alt-text-settings` | `render_settings()` |

The dashboard shows queue counts, today's spend against the cap, a bulk-generation pre-flight modal, live status polling, pause/resume, a reachability panel, and the 20 most recent jobs. The Jobs screen is a paged, status-filterable table with a per-row retry link. All markup and JavaScript is inline PHP — there are no separate asset files in this feature.

`health_checks()` renders dashboard notices for: `DISABLE_WP_CRON` being defined, the worker not having ticked in over 300 seconds while work is queued, and reachability mode being `unknown`.

### Media library integration

`ACALT_Media_UI` injects a "Generate now" control in three places — the attachment edit screen (`attachment_fields_to_edit`, priority 20), the media list-table row actions (`media_row_actions`), and the media modal (`wp_enqueue_media`) — each posting to `wp_ajax_acalt_generate_now`. These are gated on `current_user_can('upload_files')`, a lower bar than the `manage_options` used everywhere else.

## Database

### `{$wpdb->prefix}acalt_jobs`

Created by `ACALT_Queue::create_table()` via `dbDelta`, called from `acalt_activate()`.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` | Primary key |
| `attachment_id` | `BIGINT UNSIGNED NOT NULL` | WP attachment post ID |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'pending'` | `pending` \| `processing` \| `done` \| `failed` \| `skipped` |
| `source` | `VARCHAR(16) NOT NULL DEFAULT 'bulk'` | `bulk` \| `upload` |
| `attempts` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` | Retry counter, capped at 3 |
| `last_error` | `TEXT NULL` | Last failure / skip / park reason |
| `alt_generated` | `TEXT NULL` | Audit trail of what was written |
| `tokens_in` | `INT UNSIGNED NOT NULL DEFAULT 0` | |
| `tokens_out` | `INT UNSIGNED NOT NULL DEFAULT 0` | |
| `cost_usd` | `DECIMAL(10,6) NOT NULL DEFAULT 0` | |
| `created_at` | `DATETIME NOT NULL` | UTC (`current_time('mysql', true)`) |
| `updated_at` | `DATETIME NOT NULL` | UTC |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY uniq_attachment (attachment_id)`, `KEY idx_status (status)`, `KEY idx_updated (updated_at)`.

The unique key on `attachment_id` is what makes `enqueue()` idempotent — it uses `INSERT IGNORE` and returns whether a row was actually created.

### Options

| Option | Autoload | Contents |
|---|---|---|
| `acalt_settings` | default | Settings array (below), **including the plaintext API key** |
| `acalt_daily_stats` | default | `{ 'YYYY-MM-DD': {generated, failed, skipped, cost_usd, tokens_in, tokens_out} }`, trimmed to the last 60 days |
| `acalt_paused` | default | `{paused, reason_code, message, paused_at}` — absent when running |
| `acalt_reachability` | default | `{mode, reason, status, url, probed_at}` |
| `acalt_last_drain_at` | `false` | Unix timestamp of the last worker tick |

Settings keys and defaults (identical in `acalt_activate()` and `ACALT_Admin::settings()`):

| Key | Default | Notes |
|---|---|---|
| `api_key` | `''` | OpenAI key, stored in plaintext |
| `model` | `gpt-4o-mini` | UI offers `gpt-4o-mini` and `gpt-4o` only |
| `auto_on_upload` | `false` | |
| `daily_spend_cap_usd` | `5.0` | `0` or less disables the cap |
| `report_email` | `''` | |
| `report_enabled` | `true` | |
| `prompt_style` | `concise` | `concise` (<80 chars) or `descriptive` (~100 chars) |
| `language` | `get_locale()` | Passed verbatim into the prompt as the output language |
| `site_context` | `''` | Free-form domain vocabulary, truncated to 2000 chars on save |

### Post meta

Only one key is written: core's `_wp_attachment_image_alt`. Decorative images are written as an **empty string**, which is the WCAG-correct value.

## AJAX actions

There are no REST routes. All endpoints are `admin-ajax.php` actions, and all are `wp_ajax_` only (no `nopriv` variants).

| Action | Capability | Nonce | Purpose |
|---|---|---|---|
| `acalt_start_bulk` | `manage_options` | `acalt_bulk` | `ACALT_Queue::enqueue_missing_alt()`; returns the count enqueued |
| `acalt_run_drain_now` | `manage_options` | `acalt_bulk` | Runs `ACALT_Cron::drain()` synchronously; returns jobs processed |
| `acalt_preflight` | `manage_options` | `acalt_bulk` | Counts candidates and estimates cost (below) |
| `acalt_status` | `manage_options` | `acalt_bulk` | Poll: `{counts, today, recent_html, paused}` |
| `acalt_pause` | `manage_options` | `acalt_bulk` | `pause_queue('manual', …)` |
| `acalt_resume` | `manage_options` | `acalt_bulk` | `resume_queue()` (deletes `acalt_paused`) |
| `acalt_probe` | `manage_options` | `acalt_bulk` | Runs the reachability probe synchronously |
| `acalt_send_test_report` | `manage_options` | `acalt_test_report` | Sends the daily report immediately |
| `acalt_generate_now` | `upload_files` | `acalt_generate_now` | Generates for a single attachment from the media UI |

Two non-AJAX `admin_post_` handlers exist as well: `acalt_save_settings` (`manage_options` + `check_admin_referer('acalt_save_settings')`) and `acalt_retry_job` (`manage_options` + `check_admin_referer('acalt_retry_' . $job_id)`).

Note the capability checks run *before* the nonce checks in the AJAX handlers, and `check_ajax_referer( 'acalt_bulk' )` is called without an explicit query-arg name, so it falls back to `_ajax_nonce`/`_wpnonce`.

## Bulk generation over the existing library

`ACALT_Queue::enqueue_missing_alt( $batch_size = 200, $max_batches = 50 )` pages through the library with a `LEFT JOIN` on `postmeta`:

```sql
SELECT p.ID
FROM {posts} p
LEFT JOIN {postmeta} m
  ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
WHERE p.post_type = 'attachment'
  AND p.post_mime_type LIKE 'image/%'
  AND (m.meta_value IS NULL OR m.meta_value = '')
ORDER BY p.ID ASC
LIMIT %d OFFSET %d
```

Each ID is passed to `enqueue( $id, 'bulk' )`. The ceiling is `200 × 50 = 10,000` attachments per invocation; running it again picks up where the unique key left off, since already-queued rows are ignored.

The dashboard's pre-flight modal (`ajax_preflight`) counts candidates with the same query and estimates cost from empirical `gpt-4o-mini` rates recorded in the code — ~8,700 input tokens and ~22 output tokens per image, with a low band at 0.5× input and a high band at 1.2× input and 1.5× output. Input tokens dominate because images may be inlined as base64. It also reports `days_to_finish = ceil(cost_high / daily_cap)`.

## Auto-generate on upload

`ACALT_Uploader_Hook::on_attachment()` fires on `add_attachment` and enqueues with `source = 'upload'`, but only when all of the following hold:

1. `auto_on_upload` is enabled in settings.
2. The attachment exists and its `post_mime_type` starts with `image/`.
3. `_wp_attachment_image_alt` is currently empty.

It enqueues only — generation happens on the next cron tick, not during the upload request.

## Queue and cron

`acalt_activate()` schedules two events:

- `acalt_cron_drain` on the custom `minute` schedule, first run at `time() + 60`.
- `acalt_daily_report` on `daily`, first run at `strtotime('tomorrow 09:00 UTC')`.

Both are cleared on deactivation and on uninstall.

`ACALT_Cron::drain( $max_jobs = 10, $budget = 25 )` per tick:

1. Records `acalt_last_drain_at`.
2. Returns immediately if the queue is paused.
3. `ACALT_Queue::reset_stale( 300 )` — any row stuck in `processing` with `updated_at` older than 300 seconds goes back to `pending`, recovering from a tick that died mid-run.
4. `claim_batch( 10 )` — atomically flips up to 10 `pending` rows to `processing` tagged with a random marker in `last_error`, selects them back by that marker, then nulls the marker so it never leaks into the audit trail.
5. For each job: if the 25-second budget is exhausted, park the job and continue. Otherwise call `ACALT_Generator::generate()` and route the result:

| Result | Action |
|---|---|
| `ok` | `mark_done()` with alt, tokens, cost |
| `skip` | `mark_skipped()` + `record_usage(0,0,0,'skipped')` |
| `park` | `park()` — status back to `pending` **without consuming a retry attempt**. If `rate_limit` is set or the queue is now paused, the remaining claimed jobs in this batch are parked too and the loop breaks |
| anything else | `mark_retry( $attempts, $error, max 3 )` — `pending` again below 3 attempts, `failed` at 3 |

`ACALT_Generator::generate()` skips (does not fail) when: no API key, attachment missing, not an image, alt text already set, no resolvable image URL, or the image file cannot be read for inlining. The "alt text already set" check is what makes the whole feature non-destructive — it never overwrites existing alt text.

Error routing inside the generator:

- **HTTP 429** → park with `rate_limit = true`, no retry consumed, and the rest of the batch is parked. The comment is explicit about why: burning 10 retries in 10 seconds is worse than waiting.
- **HTTP 401 / 403** → `pause_queue('auth_fail', …)`, which sets `acalt_paused`, emails `report_email` once, and stops all future ticks until manually resumed. Without it, 17k jobs × 3 attempts = 51k doomed calls.
- Anything else → normal retry path.

## Reachability checking

`ACALT_Reachability` exists because many real sites sit behind Cloudflare Bot Fight or hostname-bypass rules that serve 403/503/HTML challenges to non-browser user agents. OpenAI's image fetcher then fails silently.

`probe()` picks a sample image (first of up to 50 attachments with a `medium` size), then:

| Condition | Resulting `mode` |
|---|---|
| No probeable image in the library | `unknown` |
| Host is local-only (`localhost`, `127.0.0.1`, `::1`, `.local`/`.test`/`.internal`/`.localhost` suffix, RFC1918, `169.254.0.0/16`) | `base64` |
| `wp_remote_head()` returns a `WP_Error` | `base64` |
| HTTP 200 **and** `content-type` starts with `image/` | `url` |
| Anything else (challenge page, HTML, redirect, 403, 503) | `base64` |

The HEAD request uses the user agent `Mozilla/5.0 (compatible; OpenAI-ImageFetcher/1.0; +https://openai.com)`. A `url` verdict older than `DAY_IN_SECONDS` reports as `unknown` so a Cloudflare rule change gets re-detected; a `base64` verdict is sticky until cleared or re-probed.

`ACALT_Generator::generate()` inlines the image as a `data:` URL when either the cached mode is `base64` or `is_locally_reachable_only()` returns true for the specific URL. `file_as_data_url()` tries `medium`, then `large`, then `full` from disk.

## AI provider

**OpenAI, definitively.** Proof: `includes/class-acalt-generator.php` line 269 posts to the OpenAI Chat Completions endpoint with a `Bearer` token:

```php
// includes/class-acalt-generator.php:268-278
$response = wp_remote_post(
    'https://api.openai.com/v1/chat/completions',
    array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
    )
);
```

There is no Anthropic code path in this feature. This matches the approved design spec, which specifies OpenAI. Note that its sibling features diverge: amplifi.optimize and amplifi.translate both call Anthropic.

- **Models:** `gpt-4o-mini` (default) or `gpt-4o`. The settings UI is a hardcoded two-option `<select>`; unlike the design spec's plan, there is no `/v1/models` fetch in the shipping code.
- **Request shape:** `response_format: {type: 'json_object'}`, `max_tokens: 200`, a system message assembled from the site context block plus WCAG rules plus the style instruction plus the output language, and a user message carrying a text instruction and an `image_url` content part (either a public URL or a `data:` URL).
- **Key storage:** plaintext inside the `acalt_settings` option blob, sanitized with `sanitize_text_field()` on save. It is not encrypted at rest, unlike amplifi.optimize's key.
- **Response contract:** strict JSON `{"alt": string, "decorative": boolean}`. A missing `alt` key is a `WP_Error('openai_parse')`.
- **Pricing table** (`ACALT_Generator::PRICING`, USD per 1k tokens, `price()` divides token counts by 1000):

| Model | Input | Output |
|---|---|---|
| `gpt-4o-mini` | 0.000150 | 0.000600 |
| `gpt-4o` | 0.002500 | 0.010000 |

Unknown models fall back to `gpt-4o-mini` pricing.

### Spend caps

- **Per-day:** yes. `daily_spend_cap_usd`, default `$5.00`. Checked in `generate()` before the API call: if today's `acalt_daily_stats[gmdate('Y-m-d')]['cost_usd']` is already `>=` the cap, the job is parked with `daily cap reached ($x / $y)` and no call is made. A cap of `0` or less disables the check.
- **Per-month:** none. There is no monthly cap or monthly rollup anywhere in the feature — `acalt_daily_stats` is keyed by UTC date and trimmed to 60 days.
- The cap is checked *before* each call, not enforced mid-call, so a single expensive image can push the day's total past the cap by one image's cost.
- The kill switch (`acalt_paused`) is a separate, harder stop that survives across days until manually resumed.

### Alt text sanitisation

`sanitize_alt()` runs on every non-decorative result: `wp_strip_all_tags()`, trim, strip wrapping single/double quotes, strip a leading `image|picture|photo|photograph|graphic|illustration of|showing` prefix if the model slipped one in despite the prompt, then hard-cap at 125 characters (truncating to 122 plus `...`).

## Daily email report

`acalt_daily_report` fires daily at 09:00 UTC into `ACALT_Report::send()`, which returns immediately unless `report_enabled` is true and `report_email` sanitizes to a non-empty address.

- Subject: `[amplifi.alt] Daily report — {site name} — {yesterday, Y-m-d}`.
- Body is HTML, sent by temporarily adding a `wp_mail_content_type` filter around the `wp_mail()` call and removing it afterwards.
- Content: yesterday's `generated` / `failed` / `skipped` / `cost_usd` from `acalt_daily_stats`, a multi-day trend table, current pending queue depth, today's spend against the cap, and up to 5 jobs that hit `failed` in the last 24 hours (queried live from the jobs table) linked to their attachment edit screens.

A separate, unrelated email is sent once by `pause_queue()` when the queue auto-pauses: `[amplifi.alt] Queue paused on {site} — {reason_code}`.

## Pitfalls

- **The API key is stored in plaintext** in the `acalt_settings` option. Anything with DB read access or an options-dumping plugin can read it. amplifi.optimize encrypts its key; this feature does not.
- **`uninstall.php` is unconditional.** Deleting the plugin drops `{$wpdb->prefix}acalt_jobs` and both options with no opt-in gate, destroying the audit trail of every alt text ever generated. The generated alt text itself survives, because it lives in `_wp_attachment_image_alt`.
- **`claim_batch()` overwrites `last_error` to claim rows.** It writes a random `__claim_…` marker into `last_error`, selects by it, then nulls it. Any pre-existing error text on a claimed row is destroyed, and if the process dies between the UPDATE and the cleanup UPDATE, rows are left carrying a visible marker string in `last_error` until `reset_stale()` picks them up 300 seconds later.
- **`park()` sets `pending` without consuming an attempt — so a persistently-parking job loops forever.** Daily-cap parks are intended to resume the next day, but a job that parks for any other non-rate-limit reason will be re-claimed every tick indefinitely.
- **A stale `processing` row blocks for a full 300 seconds.** `reset_stale()` only reclaims rows older than the threshold, so a tick killed by an OOM leaves up to 10 rows idle for five minutes.
- **WP-Cron is the only scheduler.** On a low-traffic site, or one with `DISABLE_WP_CRON` set, the queue does not drain. `health_checks()` warns about both, and the dashboard's "run drain now" button is the manual escape hatch, but a 10,000-image backlog at 10 jobs per minute is roughly 17 hours of continuous ticking even when cron is healthy.
- **The tick budget can be exceeded by one job.** The 25-second budget is checked *before* each job, not during it, and the OpenAI call alone has a 30-second timeout. A tick can therefore run ~55 seconds, which exceeds the 30-second `max_execution_time` common on managed hosts — the exact scenario the budget was meant to prevent.
- **Base64 inlining is expensive.** The pre-flight's own estimate of ~8,700 input tokens per image reflects inlined images. A site that probes to `base64` mode pays substantially more per image than one serving public URLs, and `file_as_data_url()` loads the whole file into memory with `file_get_contents()`.
- **Reachability mode is site-wide and sampled from one image.** The probe checks the first attachment with a `medium` size. A library spanning several CDNs or hostnames gets a single verdict from one sample.
- **`get_locale()` goes straight into the prompt.** `language` defaults to the WordPress locale string (`en_US`, `de_DE_formal`) and is interpolated into the system prompt as "Output language: …". Unusual locale strings are passed through verbatim.
- **Media-library "Generate now" is `upload_files`, not `manage_options`.** Any Author-level user can trigger a paid API call from the media library. The cost still counts against the daily cap, but the capability bar is lower than every other endpoint in the feature.
- **Decorative results write an empty string, which the scanner treats as "no alt".** `generate()` writes `''` for decorative images by design (WCAG-correct), but the enqueue query matches `meta_value IS NULL OR meta_value = ''`. Re-running a bulk enqueue re-queues every decorative image; the job then skips on the "alt text already set" check only if the value is non-empty, so those images are re-sent to the API each bulk run.
- **The pricing table is hardcoded** and, per its own comment, must be updated at release time against current OpenAI list prices. Stale pricing silently mis-reports cost and mis-enforces the daily cap.
- **Recent history is suite-level.** `git log --oneline -20 -- plugins/amplifi-plugins/features/alt/` returns only monorepo-wide version bumps and unrelated `consent:` commits (`4098218`, `d2b8773`, `1865c12`, `9230115`, `47e1e40`, `8f39726`, `811144e`, …). Feature-scoped history is not separable at this path. The in-code comment "New endpoints (beta.8): preflight, status polling, pause/resume, probe" is the only marker of when the operational controls were added.
