# Changelog

All notable changes to amplifi.optimize.

## 1.0.0

Initial release.

### Added
- Pluggable scanner/proposer/applier architecture, registered in `Amplifi_Optimize_Plugin::register_fix_types()`.
- Four fix-type verticals:
  - **Meta descriptions** — `WP_Query` scanner with Yoast/RankMath/AIOSEO meta-key fallback, Claude proposer batched 10 per call, applier via `Amplifi_Optimize_SEO_Detector::set_meta_description()`.
  - **Image alt text** — SQL scanner that skips images under the configured minimum dimension and SVGs by default; Claude vision proposer with progress transient; applier writes `_wp_attachment_image_alt`.
  - **Long titles** — rendered-title scanner (template applied for Yoast/RankMath), ≤58 char rewrite proposer, applier targeting the SEO plugin's title meta with optional `post_title` update.
  - **Unpublish candidates** — heuristic scanner (title regex, thin content, stale-with-no-inbound-links, URL suffix patterns), Claude classifier proposer, applier covering trash / 301 redirect (Redirection plugin or built-in `template_redirect` map) / noindex / keep.
- Anthropic Claude client (`includes/class-claude-client.php`):
  - `wp_remote_post` against `/v1/messages` with `anthropic-version: 2023-06-01`.
  - Default model `claude-sonnet-4-5`, configurable.
  - Sliding-window local rate limiter (default 50 req/min).
  - Token usage tracked per model and surfaced in the Dashboard.
  - Defensive JSON extractor with markdown-fence fallback.
  - 429 retry-after surfaced to the UI.
- Suggestions table `{prefix}_amplifi_optimize_suggestions` with status/type and target indexes; created via `dbDelta` on activation.
- REST API at `/wp-json/amplifi-optimize/v1/` covering scan, propose, list, approve, reject, edit, undo, retry, batch-approve, stats, settings. All routes require `manage_options`.
- React admin UI built with `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`:
  - Dashboard with per-fix-type counts and Claude token usage.
  - Scans screen that runs scan + propose for each fix type.
  - Review Queue with one-card-at-a-time flow, four card variants (one per fix type), buffered approve mode, keyboard shortcuts (A approve, R reject, E edit, S/→ skip), inline edit, batch-approve with a sync indicator.
  - History screen with status + fix-type filters and undo for applied changes.
  - Settings: API key (write-only, encrypted), model, batch sizes, rate limit, included post types, image-size threshold, SVG opt-in, SEO plugin detection override, undo window, data-on-uninstall opt-in.
- WP-CLI commands: `wp amplifi-optimize scan|propose|apply|report` with `--auto`, `--limit`, `--offset` flags.
- API key stored encrypted at rest (AES-256-CBC keyed off `wp_salt('auth')`).
- Translation-ready: all user-facing strings wrapped in `__()`/`_e()` under the `amplifi-optimize` text domain.
- Footer credit on every admin page linking to amplifi.studio.

### Known limitations
- AIOSEO support is read-write via direct table access — install AIOSEO before activating amplifi.optimize if you want to use it.
- Redirect application uses the Redirection plugin when present; otherwise falls back to a plugin-managed map served at `template_redirect`. Order of registration is first-match.
- One language only at launch (English). Translation files welcome.

### Not in v1 (planned for v2)
- Schema generation (LocalBusiness, FAQPage, Article).
- H1 fixes (requires theme-level DOM analysis).
- Internal link suggestions.
- Content rewriting / expansion.
- Bulk title generation from scratch.
- Redirect-map import for `-2`/`-3` duplicates.
