# amplifi.alt — AI Alt Text for WordPress

**Status:** Approved design, ready for implementation plan
**Date:** 2026-05-14
**Owner:** Adam Chiaravalle

## Goal

Add a new plugin to the amplifi.studio suite that generates accessible, SEO-friendly alt text for WordPress media library images using OpenAI vision models. The plugin must support both bulk generation across the existing media library and automatic generation on new uploads, with cost protection and a daily email report.

## Non-Goals

- Cross-attachment image deduplication.
- Translation of alt text into other languages (handled downstream by `amplifi.translate`).
- Captions, descriptions, or titles — alt text only.
- Human-in-the-loop approval queue (deferred; the "fills empty only" rule + audit table + retry action covers the common concern).
- Per-post-type or per-taxonomy rules.

## Plugin identity & layout

- Display name: **amplifi.alt** (short label: "Alt")
- Plugin slug: `amplifi-ac-alt-text`
- Directory: `plugins/ac-alt-text/`
- Multi-file layout (mirrors `amplifi-security`, `ac-wp-translator`):
  - `ac-alt-text.php` — bootstrap, activation hook, framework registration, cron schedule registration
  - `includes/amplifi-framework.php` — shared framework (copied from `shared/` at release time)
  - `includes/class-acalt-generator.php` — OpenAI client + prompt + per-image generation
  - `includes/class-acalt-queue.php` — bulk queue: enqueue, claim batch, mark done/failed
  - `includes/class-acalt-cron.php` — 1-minute queue worker + daily report event
  - `includes/class-acalt-uploader-hook.php` — `add_attachment` listener for auto-on-upload
  - `includes/class-acalt-admin.php` — three admin pages + AJAX handlers
  - `includes/class-acalt-report.php` — daily email composition + send via `wp_mail()`
  - `uninstall.php` — drop jobs table, delete options
- Docker development environment: WordPress on port `8093`, MySQL on `3319`.

## Architecture

### Provider

- OpenAI Chat Completions with `gpt-4o-mini` as the default vision model. Model is configurable; available models fetched from `/v1/models` and cached 1 hour (same pattern as `ac-wp-translator`).
- API key entered by user in settings, stored in the `acalt_settings` option blob.

### Data model

**Custom table `{prefix}_acalt_jobs`** — one row per attachment we want to process. Lets us page, retry, dedupe, and report:

| Column | Type | Purpose |
|---|---|---|
| `id` | BIGINT PK AUTO_INCREMENT | |
| `attachment_id` | BIGINT UNSIGNED, UNIQUE | WP attachment post ID |
| `status` | ENUM(`pending`,`processing`,`done`,`failed`,`skipped`) | |
| `source` | ENUM(`bulk`,`upload`) | Who enqueued it |
| `attempts` | TINYINT UNSIGNED, default 0 | Retry counter (cap 3) |
| `last_error` | TEXT NULL | Last failure / skip reason |
| `alt_generated` | TEXT NULL | The text we wrote (audit trail) |
| `tokens_in` | INT UNSIGNED, default 0 | |
| `tokens_out` | INT UNSIGNED, default 0 | |
| `cost_usd` | DECIMAL(10,6), default 0 | |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indexes: `KEY idx_status (status)`, `KEY idx_updated (updated_at)`.

**Option `acalt_settings`** (JSON blob in `wp_options`):

```
{
  "api_key": "",
  "model": "gpt-4o-mini",
  "auto_on_upload": false,
  "daily_spend_cap_usd": 5.00,
  "report_email": "",
  "report_enabled": true,
  "prompt_style": "concise",       // "concise" | "descriptive"
  "language": "en_US"
}
```

**Option `acalt_daily_stats`** — rolling per-day counters keyed by UTC date, capped at last 60 days:

```
{
  "2026-05-14": {
    "generated": 25,
    "failed": 0,
    "skipped": 2,
    "cost_usd": 0.0042,
    "tokens_in": 18420,
    "tokens_out": 510
  }
}
```

**Native post meta:** alt text is written through to WP's standard `_wp_attachment_image_alt` field so themes, blocks, and other SEO plugins see it without any integration.

### Generation flow (`Generator::generate($attachment_id)`)

1. Load the attachment post. Mark `skipped` and exit if:
   - Not an image MIME type
   - `_wp_attachment_image_alt` is already non-empty (respect manual alt text)
   - No image file URL resolvable
2. Resolve image URL preference: `medium` → `large` → `full`. Use `wp_get_attachment_image_src($id, $size)`.
3. **Daily cap check:** if today's `cost_usd` in `acalt_daily_stats` ≥ `daily_spend_cap_usd`, leave the job `pending`, set `last_error = "daily cap reached"`, and exit. Cron will retry the next UTC day.
4. Call OpenAI `chat/completions` with the chosen model, passing `image_url` referencing the medium URL. System prompt enforces alt-text best practices:
   - Under 125 characters
   - Descriptive of content + relevant context
   - No "image of" / "picture of" / "graphic showing"
   - No trailing period unless a real sentence
   - Factual, not interpretive
   - Output JSON: `{"alt": "...", "decorative": bool}`
5. Use OpenAI's JSON-mode (`response_format: { type: "json_object" }`) to enforce structure.
6. If `decorative: true`, write empty string to alt (the WCAG-correct value for purely ornamental images). Record verdict + reasoning in `alt_generated` and `last_error` for audit transparency.
7. `update_post_meta($id, '_wp_attachment_image_alt', $alt)`.
8. Mark job `done`; record `tokens_in`, `tokens_out`, `cost_usd`. Increment `acalt_daily_stats` for today's UTC date.
9. On API failure: increment `attempts`, store `last_error`, set status to `pending` if `attempts < 3` else `failed`.

### Auto-on-upload

- Hook `add_attachment` (fires after WP has finished sub-size generation).
- If `auto_on_upload` is enabled and the attachment is an image, insert a row into `acalt_jobs` with `source = upload` and `status = pending`. Do nothing synchronously.
- The cron worker picks it up within ~1 minute.

### Bulk scan

- "Generate for all existing images" button on the dashboard page triggers an AJAX endpoint.
- Endpoint enumerates `attachment` posts where `_wp_attachment_image_alt` is empty/missing, in batches of 200 IDs, using `INSERT IGNORE` into `acalt_jobs` so re-runs don't duplicate.
- Returns total enqueued count. The cron worker drains the queue.

### Cron worker (every minute)

- `acalt_cron_drain` WP-Cron event scheduled at activation, runs every minute.
- Claims up to 10 pending jobs atomically:
  ```sql
  UPDATE {prefix}_acalt_jobs
  SET status='processing', updated_at=NOW()
  WHERE status='pending'
  ORDER BY id ASC
  LIMIT 10;
  ```
  Then `SELECT ... WHERE status='processing' AND updated_at >= (NOW() - INTERVAL 90 SECOND)` to get the just-claimed rows. (Stale `processing` rows older than 5 minutes are reset to `pending` at the start of each tick.)
- Processes serially in the cron tick (~5–10 seconds per image), then exits.
- Stops early if the daily cap is reached.

### Daily report

- WP-Cron event `acalt_daily_report` scheduled at activation for 09:00 UTC daily.
- No-op if `report_enabled = false` or `report_email` empty.
- Builds a plain-text + simple HTML email and sends via `wp_mail()`.
- Contents:
  - Subject: `[amplifi.alt] Daily report — {site_name} — {date}`
  - Yesterday's totals: generated, failed, skipped, cost USD
  - Last 7 days table of daily totals
  - Top 5 failures from `acalt_jobs` where `status = 'failed'` and `updated_at` in the last 24 hours, with reason + admin URL link to the attachment
  - Pending queue size
  - Daily cap status (hit / under) + today's spend

## Admin UI

Three pages under the **amplifi.studio** menu:

1. **Alt** (main, registered via `amplifi_register_plugin()`, slug `amplifi-ac-alt-text`)
   - Today's generated count, today's cost vs cap (progress bar)
   - Pending queue size, last 24h activity summary
   - Recent activity table (last 20 jobs)
   - "Generate for all existing images" primary action
   - Toggle: "Auto-generate alt text on upload"
2. **Alt: Jobs** (manual submenu, slug `amplifi-ac-alt-text-jobs`)
   - Paginated table of `acalt_jobs`
   - Filter by status; search by attachment ID
   - Per-row actions: "Retry" (resets status to `pending` and `attempts` to 0), "View image" (opens attachment edit screen)
3. **Alt: Settings** (manual submenu, slug `amplifi-ac-alt-text-settings`)
   - API key (password input, autocomplete off)
   - Model dropdown (populated from `/v1/models`, cached 1h)
   - Daily spend cap USD input
   - Report email + enabled toggle
   - Prompt style radio (concise / descriptive)
   - Language (defaults to site locale)

Hook suffixes follow the established pattern: `amplifi-studio_page_amplifi-ac-alt-text[-X]`.

## Security & cost protection

- API key stored in `wp_options` (matches the convention used by `ac-bulk-meta` and `ac-wp-translator`; a future hardening pass could move it to `Secret_Store` from `amplifi-security`, but that's out of scope here).
- Daily spend cap enforced in `Generator::generate` before any API call. UI shows progress against cap.
- Per-job retry capped at 3 attempts. After 3 failures the job is `failed` and excluded from the worker until a manual retry.
- Image URL sent to OpenAI is the publicly-reachable WP medium-size URL — same exposure surface the site already has.

## Testing plan (end-to-end on docker)

1. `cd plugins/ac-alt-text && docker-compose up -d`. WordPress on `:8093`, MySQL on `:3319`.
2. Complete WP setup wizard. Install and activate the plugin. Open Settings → paste OpenAI key, set report email, leave auto-on-upload OFF.
3. **Bulk test:**
   - Use `wp media import` inside the container to upload 25 Unsplash images.
   - Confirm 25 image attachments exist with empty alt.
   - Click "Generate for all existing images." Watch the jobs table fill with `pending`.
   - Cron drains within ~3 minutes (10/min × 25 ≈ 3 min). Confirm all 25 attachments now have alt text via `wp post meta get <id> _wp_attachment_image_alt`.
   - Spot-check 3 images for quality (concise, descriptive, no "image of").
4. **Auto-upload test:** flip "Auto-generate on upload" ON. Upload 3 fresh images via Media Library. Confirm alt text populates within ~1 minute.
5. **Failure path:** set an invalid API key, upload 1 image, confirm job lands in `failed` after 3 attempts, daily report includes it.
6. **Spend cap path:** set cap to `$0.001`, click bulk, confirm worker pauses with `last_error = "daily cap reached"`. Raise cap, confirm it resumes on the next cron tick.
7. **Daily report:** run `wp cron event run acalt_daily_report` (or use MailHog/SMTP capture) and verify the email arrives with the documented sections.

## Release plan

- Add `plugins/ac-alt-text/` to `plugins-manifest.json` with name, description, icon.
- Add a `social/` and `blog/` directory with placeholder structure following the suite convention.
- `./scripts/release.sh <version>` will pick the plugin up automatically.

## Open questions for implementation phase

- Exact cost-per-1k-tokens table for `gpt-4o-mini` to convert usage → USD — pull current values from OpenAI's pricing page at implementation time.
- Whether to expose a WP-CLI command (`wp amplifi-alt scan|status|retry`) in v1 or defer to v2. Recommend v1 for parity with `amplifi-security` — small surface, useful for headless ops.
