# CLAUDE.md - amplifi.plugins

## Overview
Monorepo for the **amplifi.studio** WordPress plugin suite. All plugins share the `amplifi-framework.php` for a unified admin menu, auto-updates from GitHub releases, and a plugin hub.

## Repository Structure
- `plugins/` - Each plugin in its own directory (e.g. `plugins/ac-wp-translator/`)
- `shared/amplifi-framework.php` - Shared code: top-level admin menu, plugin registry, GitHub auto-updater, hub page. Bundled into each plugin's `includes/` at release time.
- `plugins-manifest.json` - Plugin catalog (name, description, icon) included as a release asset. The hub fetches this dynamically. Update when adding/removing plugins.
- `scripts/release.sh` - Builds all plugin zips, includes manifest, generates changelog, creates GitHub release with assets.
- `LICENSE` - MIT with zero warranty clause.

## amplifi.studio Framework (`shared/amplifi-framework.php`)
- **Admin Menu**: Registers a top-level "amplifi.studio" menu at position 3. Each plugin adds itself as a submenu via `amplifi_register_plugin()`.
- **Plugin Hub**: Hub page at `admin.php?page=amplifi-studio` lists all installed + available plugins. Catalog fetched dynamically from `plugins-manifest.json` release asset (falls back to hardcoded catalog). One-click install/activate for uninstalled plugins via AJAX.
- **Auto-Updates**: Checks `api.github.com/repos/{REPO}/releases/latest` every 6 hours. Matches release zip assets by plugin slug. Updates appear in WP's native update system.
- **Guard**: `AMPLIFI_FRAMEWORK_LOADED` constant prevents double-loading when multiple plugins are active.

## Plugin: amplifi.translate (`plugins/ac-wp-translator/`)
AI-powered WordPress translation using OpenAI.

### Architecture
- **URL Detection**: `plugins_loaded` hook detects language prefix from `$_SERVER['REQUEST_URI']`
- **Content Filters**: `the_title`, `the_content`, `the_excerpt` at priority 1
- **Full Page Translation**: Output buffer captures entire HTML for nav/header/footer translation
- **String Translation**: Batch translation of UI strings via single API call, cached in `wp_options` per language
- **Caching**: Custom DB table `{prefix}_acwpt_translations` keyed by `post_id + language`
- **Cache Invalidation**: `save_post` hook deletes cached translations
- **Usage Tracking**: Token counts and costs per month in `wp_options`
- **Multilingual Sitemap**: `/acwpt-sitemap.xml` with hreflang alternates
- **Dynamic Models**: Fetches available models from OpenAI `/v1/models` API, cached 1 hour

### Key Files
- `ac-wp-translator.php` - Bootstrap, activation, framework registration
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `includes/class-acwpt-frontend.php` - URL routing, content filters, output buffer, shortcode, nav menu switcher, hreflang, sitemap
- `includes/class-acwpt-translator.php` - OpenAI API calls, response parsing, usage tracking
- `includes/class-acwpt-cache.php` - Database CRUD
- `includes/class-acwpt-admin.php` - Settings page (submenu under amplifi.studio), AJAX handlers
- `includes/class-acwpt-languages.php` - 33 language definitions

### Admin Menu
The plugin's settings page is a submenu under the **amplifi.studio** top-level menu, not under Settings. The hook suffix for `enqueue_assets` is `amplifi-studio_page_amplifi-ac-wp-translator`.

### Settings
`wp_options` key `acwpt_settings`: `api_key`, `source_language`, `enabled_languages[]`, `show_flags`, `show_suggestion`, `model`

## Plugin: amplifi.meta (`plugins/ac-bulk-meta/`)
AI-powered bulk SEO meta editor with FAQ generation and JSON-LD structured data.

### Architecture
- **Single-file monolith**: All logic in `ac-bulk-meta.php` (~8000 lines) — CSS, JS, AJAX handlers, and render methods inline
- **Yoast SEO Integration**: Reads/writes `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` post meta
- **AI Generation**: OpenAI Chat Completions for meta descriptions, title tags, focus keyphrases, and FAQs
- **FAQ System**: Custom DB table `{prefix}_ac_faqs`, per-post deploy via `_ac_faqs_deployed` post meta, front-end output with accordion/expanded modes
- **JSON-LD**: Organization + per-post structured data via `_ac_jsonld_data` post meta, output in `wp_head`
- **Dark Mode**: Per-user toggle stored in `ac_bulk_meta_dark_mode` user meta
- **Webhook Logging**: Optional webhook URL for AI generation logs

### Key Files
- `ac-bulk-meta.php` - Everything: bootstrap, framework registration, class definition, CSS, JS, AJAX, rendering
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Cleans up options and `ac_faqs` table

### Admin Menu
Three pages under the **amplifi.studio** menu:
1. **Meta** (main) — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-bulk-meta`
2. **Meta: FAQ** — manual submenu → slug: `amplifi-ac-bulk-meta-faq`
3. **Meta: JSON-LD** — manual submenu → slug: `amplifi-ac-bulk-meta-jsonld`

Hook suffixes: `amplifi-studio_page_amplifi-ac-bulk-meta`, `amplifi-studio_page_amplifi-ac-bulk-meta-faq`, `amplifi-studio_page_amplifi-ac-bulk-meta-jsonld`

### Settings (all in `wp_options`)
`ac_openai_api_key`, `ac_global_prompt`, `ac_site_title_override`, `ac_webhook_url`, `ac_webhook_url_set_by`, `ac_faq_focus`, `ac_faq_count`, `ac_faq_deploy_global`, `ac_jsonld_settings`, `ac_ai_generation_log`, `ac_bulk_generation_status`

## Plugin: amplifi.magic (`plugins/ac-magic-links/`)
One-click magic links for WordPress password-protected pages.

### Architecture
- **Single-file plugin**: All logic in `ac-magic-links.php` (~500 lines) — admin page, token management, cookie handling, usage logging inline
- **Token Management**: Generate named tokens, revoke tokens, view per-token usage logs
- **Password Cookies**: Sets hashed `wp-postpass_` cookies via `template_redirect` hook, same as native WP password form
- **Usage Logging**: Each token use logged with IP, geolocation (ip-api.com), and timestamp in post meta
- **Filterable Logs**: Unified access logs table with dropdowns for page ID, title, token, IP, location, date range

### Key Files
- `ac-magic-links.php` - Everything: bootstrap, framework registration, class definition, admin page, front-end token handler
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Cleans up `_ocml_tokens_hashed_named` and `_ocml_token_usages_named` post meta

### Admin Menu
Single page under the **amplifi.studio** menu:
- **Magic** — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-magic-links`

Hook suffix: `amplifi-studio_page_amplifi-ac-magic-links`

### Data Storage (all in post meta)
- `_ocml_tokens_hashed_named` - Array of token objects: `{token, name, active}`
- `_ocml_token_usages_named` - Array of usage log entries: `{token, token_name, ip, location, datetime}`

## Plugin: amplifi.lockcache (`plugins/ac-static-cache/`)
Static HTML cache for password-protected WordPress posts.

### Architecture
- **Single-file plugin**: All logic in `ac-static-cache.php` — caching engine, admin page, debug logging inline
- **Cache Engine**: Captures HTML output for unlocked password-protected posts, stores as static files in `wp-content/pp-static-cache/`
- **Smart Skip**: Skips caching for admin users (avoids admin bar in cache), AJAX requests, and Search & Filter Pro requests
- **Security**: Cache directory protected by `.htaccess` (deny all), files set to 0600 permissions
- **Hooks**: `template_redirect` at priority 1 (no-cache headers) and 100 (serve cache), `template_include` at 9999 (capture output)

### Key Files
- `ac-static-cache.php` - Everything: bootstrap, framework registration, cache engine, admin page, logging
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Removes `wp-content/pp-static-cache/` directory and all contents

### Admin Menu
Single page under the **amplifi.studio** menu:
- **LockCache** — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-static-cache`

Hook suffix: `amplifi-studio_page_amplifi-ac-static-cache`

### Data Storage
- Cache files: `wp-content/pp-static-cache/cache-{post_id}.html`
- Debug log: `wp-content/pp-static-cache/ppsc-debug.log`
- No `wp_options` or post meta used

## Plugin: amplifi.pods (`plugins/ac-pods/`)
Podcast carousel and floating player via shortcode — mirrors the Norwest Resources page podcast player with Apple Podcasts CPT + Spotify playlist support.

### Architecture
- **Single-file plugin**: All logic in `ac-pods.php` — CPT, ACF fields, shortcode, carousel, floating player, admin page
- **Dual Source**: Merges Apple Podcasts CPT (`podcast` post type with ACF fields) + Spotify playlist episodes (via `nwr_spotify_get_all_episodes()` if available)
- **Swiper Carousel**: Responsive card carousel via Swiper.js CDN with equal-height slides and playlist filter pills
- **Floating Player**: Fixed-position embed player (Apple Podcasts or Spotify) with teal header, loading spinner, smooth slide-up transition, and collapsible episode description
- **ACF Integration**: Registers its own ACF field group for the `podcast` CPT; falls back to native meta box if ACF is not installed
- **Site-Agnostic**: No font declarations, no Bootstrap, no Iconify dependency — all icons are inline SVG, styling via `--acpods-accent` CSS custom property

### Key Files
- `ac-pods.php` - Everything: bootstrap, framework registration, CPT, ACF fields, meta box fallback, shortcode, carousel, player, CSS, JS, admin page
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Removes all `podcast` posts and transients

### Admin Menu
Single page under the **amplifi.studio** menu:
- **Pods** — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-pods`

Hook suffix: `amplifi-studio_page_amplifi-ac-pods`

### Data Storage
**ACF fields on `podcast` CPT (or post meta fallback):**
| Field / Key | Purpose |
|-------------|---------|
| `podcast_show_name` | Podcast show name |
| `podcast_apple_show_id` | Apple show ID (for embed URL) |
| `podcast_apple_episode_id` | Apple episode ID (for embed URL) |
| `podcast_artwork_url` | Episode artwork URL |
| `podcast_episode_number` | Episode label (e.g. "Episode 42" or "Feb 13, 2026") |
| `podcast_duration` | Duration (e.g. "45 min") |

**Spotify integration:** If `nwr_spotify_get_all_episodes()` exists, Spotify episodes are merged and sorted by date. Playlist filter pills use `nwr_spotify_playlist_links` transient.

### Shortcode
\`\`\`
[amplifi-pods]                                          <!-- All episodes, full header -->
[amplifi-pods count="8" show_header="false"]          <!-- 8 episodes, no header -->
[amplifi-pods heading="Our Podcasts" accent_color="#6366f1"]
[amplifi-pods show_filters="false" description=""]     <!-- No filters or description -->
\`\`\`

| Attribute | Description | Default |
|-----------|-------------|---------|
| `count` | Max episodes (-1 = all) | `-1` |
| `show_filters` | Show Spotify playlist filter pills | `true` |
| `show_header` | Show heading/subheading/description | `true` |
| `heading` | Main heading text | `Podcasts` |
| `subheading` | Uppercase label above heading | `Featured Podcasts` |
| `description` | Paragraph below heading | *(default)* |
| `accent_color` | Hex color for accents | `#055c5f` |

## Releasing
```bash
./scripts/release.sh 1.0.0
```
Always releases **all** plugins — single-plugin releases are not supported. The dynamic plugin hub depends on every plugin zip being present in the latest release for one-click install to work.

The script: validates semver, copies `shared/amplifi-framework.php` + `LICENSE` into each plugin, zips them, includes `plugins-manifest.json`, generates changelog from git log, creates a GitHub release with all assets.

## Plugin: amplifi.sync (`plugins/ac-sync/`)
WordPress environment sync — REST API endpoints for file, database, and media operations between production and staging.

### Architecture
- **Multi-file plugin**: Bootstrap (`ac-sync.php`), API endpoints (`class-acsync-api.php`), admin page (`class-acsync-admin.php`)
- **REST API**: Full REST API under `amplifi-sync/v1` namespace with API key auth via `X-AmpliSync-Key` header
- **File Operations**: Manifest (tree with MD5 hashes), read/write/delete — restricted to `wp-content/`
- **Database Operations**: Table listing, paginated export/import, raw SQL (read-only + confirmed writes), full backup/restore
- **Media Operations**: Paginated media library listing, sideload import from URL
- **Elementor**: CSS cache regeneration endpoint
- **Security**: API key generated on activation, confirmation tokens for write SQL, file ops restricted to wp-content

### Key Files
- `ac-sync.php` - Bootstrap, constants, activation hook (API key generation)
- `includes/class-acsync-api.php` - All REST API endpoints (15 routes)
- `includes/class-acsync-admin.php` - Settings page with API key management and connection log
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Cleans up options and backup files

### Admin Menu
Single page under the **amplifi.studio** menu:
- **Sync** — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-sync`

Hook suffix: `amplifi-studio_page_amplifi-ac-sync`

### REST API Endpoints (namespace: `amplifi-sync/v1`)
| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/status` | Site info, versions, active plugins/themes |
| GET | `/files/manifest` | File tree with MD5 hashes |
| GET | `/files/read` | Download file content (base64) |
| POST | `/files/write` | Upload file (base64) |
| DELETE | `/files/delete` | Delete file |
| GET | `/db/tables` | List tables + confirmation token |
| GET | `/db/export` | Export table as JSON (paginated) |
| POST | `/db/import` | Import rows (truncate/merge) |
| POST | `/db/query` | Read-only SQL |
| POST | `/db/execute` | Write SQL (requires token) |
| POST | `/db/backup` | Full database dump |
| POST | `/db/restore` | Restore from SQL dump |
| GET | `/media/list` | Media library items |
| POST | `/media/import` | Import media from URL |
| POST | `/elementor/regenerate` | Clear Elementor CSS cache |

### Data Storage
- `acsync_settings` (wp_options) — API key
- `acsync_connection_log` (wp_options) — Last 50 API requests
- `acsync_db_token` (transient, 5min) — Write confirmation token
- Backup files in `wp-content/uploads/acsync-backups/`

## Plugin: amplifi.security (`plugins/amplifi-security/`)
WordPress security with an AI brain. Local scanners surface findings; Claude triages them with the user's own Anthropic API key; alerts fire only on `confirmed`/`likely` verdicts.

### Architecture
- **Multi-file plugin** with PSR-style autoloader (`includes/class-autoloader.php`) under namespace `Amplifi\Security\*`. PHP 8.1+ / WP 6.4+ gated in the bootstrap before any 8.1-only code loads.
- **Scanners (WP-Cron, every 4h by default)**: shell/backdoor, file integrity (core + plugins + themes), critical file (.htaccess, wp-config, mu-plugins, dropins), DB anomaly, auth anomaly, vuln (Wordfence Intelligence), cron, REST/XML-RPC, hardening.
- **Triage**: `Triage_Engine` batches findings, calls Anthropic Messages API with tool-use forced for strict JSON output, caches benign verdicts 7 days in a custom table, tracks per-day USD spend with caps.
- **Alert routing**: Category × verdict matrix → SMTP2Go email, Textbelt SMS (3/day cap), daily digest, or audit log only. Confirmed malware cannot be muted (hardcoded floor).
- **Self-defense**: `Self_Integrity` baseline of plugin's own files, `Tamper_Detector` HMAC stamps on critical config + cron re-arm + liveness check, `Canary` health URL for external uptime monitors, `Stealth_Mode` to hide from non-installer admins.
- **Audit log**: HMAC hash-chained event journal in custom table, daily prune retention.
- **Onboarding wizard**: 4 steps (API keys → recipients → log sources → first scan), reachable at `?wizard=1` on the Settings page until `amplifi_security_onboarding_complete` is set.
- **WP-CLI**: `wp amplifi-security scan|findings|verify|canary|stealth`.

### Key Files
- `amplifi-security.php` - Bootstrap with PHP/WP/OpenSSL gates, version constants, framework + autoloader registration
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `includes/class-autoloader.php` - Namespace → file resolver
- `includes/class-plugin.php` - Single bootstrap entry: subsystem registration + cron hooks
- `includes/class-installer.php` - dbDelta source of truth for nine `wp_amplifi_security_*` tables
- `includes/class-activator.php` / `class-deactivator.php` - Activation/pre-deactivation hooks
- `includes/admin/class-admin.php` - Framework integration + Findings/Audit/Settings submenus
- `includes/admin/class-onboarding-wizard.php` - 4-step wizard UI + completion handlers
- `includes/scanners/*.php` - Nine scanners + interface + Scan_Runner orchestrator
- `includes/triage/*.php` - Anthropic_Client, Prompt_Builder, Spend_Tracker, Verdict_Cache, Triage_Engine
- `includes/alerts/*.php` - Alert_Router, Smtp2Go_Client, Textbelt_Client
- `includes/audit/*.php` - Audit_Logger + hash-chained Audit_Chain
- `includes/crypto/class-secret-store.php` - AES-256-GCM with HKDF-SHA256 key from WP `AUTH_KEY` family
- `includes/self-defense/*.php` - Self_Integrity, Tamper_Detector
- `includes/canary/`, `includes/honeypot/`, `includes/stealth/`, `includes/log-sources/`, `includes/data/`, `includes/signatures/`, `includes/cli/` - Subsystems

### Admin Menu
Four pages under the **amplifi.studio** menu:
1. **Security** (Health dashboard, main) — registered via `amplifi_register_plugin()` → slug: `amplifi-security`
2. **Security: Findings** — manual submenu → slug: `amplifi-security-findings`
3. **Security: Audit Log** — manual submenu → slug: `amplifi-security-audit`
4. **Security: Settings** — manual submenu → slug: `amplifi-security-settings`

The framework's slug-prefix guard (in `shared/amplifi-framework.php`) keeps the URL as `?page=amplifi-security` instead of doubling to `amplifi-amplifi-security`. Hook suffixes: `amplifi-studio_page_amplifi-security[-X]`.

### Stealth Mode
When enabled and viewed by a non-installer, Stealth filters: the Plugins list (`all_plugins`), update transients, plugin action links, and the amplifi.studio hub catalog (via the `amplifi_hub_catalog` filter — registered by this plugin). The `amplifi-studio` top-level menu remains visible because it's owned by other plugins; only the Security submenus are hidden. Recovery: define `AMPLIFI_SECURITY_INSTALLER_ID` in wp-config, or visit `?amplifi_unhide=<token>` once.

### Settings (in `wp_options`)
- `amplifi_security_settings` (JSON blob) — `scan_interval`, `enabled_scanners`, `model`, `sensitivity`, `daily_spend_cap_usd`, `monthly_spend_cap_usd`, `notification_recipients`, `digest_hour_utc`, `quiet_hours`, `routing_matrix`, `file_exclusions`, `ip_allowlist`
- Encrypted secrets via `Secret_Store` — Anthropic key, SMTP2Go key+sender, Wordfence Intelligence token, AbuseIPDB key, Textbelt key+phone
- `amplifi_security_onboarding_complete`, `amplifi_security_stealth_enabled`, `amplifi_security_unhide_token`, `amplifi_security_canary_secret`, `amplifi_security_installer_id`, `amplifi_security_db_version`, `amplifi_security_last_scan_*`

### Database Tables (`wp_amplifi_security_*`)
`findings`, `baseline`, `auth_log`, `audit`, `scans`, `verdict_cache`, `log_sources`, `vuln_feed`, `spend`. Schema source-of-truth in `Installer::install()`. `dbDelta` re-runs on version-bump page loads via `Installer::maybe_upgrade()`.

### API Key Security
Document to users: scope your Anthropic key to Messages API only, set spend caps in the Anthropic console, and rotate if compromised. All keys are encrypted at rest (`Secret_Store`, AES-256-GCM) and only sent to their respective vendor APIs.

## Go TUI: amplifi.sync (`tools/sync-tui/`)
Terminal UI orchestrator for syncing between WordPress environments.

### Architecture
- **Bubbletea TUI**: Tab-based interface (Dashboard, Files, Database, Media, Logs, Settings)
- **REST API Client**: Connects to amplifi.sync WP plugin on both sites
- **SSH/SFTP**: Direct file operations and WP-CLI for emergency recovery
- **Serializer**: PHP serialize/unserialize in Go with serialization-safe search/replace
- **Backup Manager**: Local backups with JSON table exports and SQL dumps

### Directory Structure
```
tools/sync-tui/
├── main.go                          # Entry point, .env loading, TUI launch
├── go.mod / go.sum
├── .env.example
└── internal/
    ├── config/                      # Config loading + interactive wizard
    ├── api/                         # WP REST API client + types
    ├── remote/                      # SSH, SFTP, WP-CLI connections
    ├── sync/                        # File/DB/media sync engines + backup
    ├── serializer/                  # PHP serializer + safe replace (31 tests)
    └── tui/                         # Bubbletea views (app, dashboard, files, db, media, logs, settings)
```

### Config (.env)
`PROD_SITE_URL`, `PROD_API_KEY`, `PROD_SFTP_HOST`, `PROD_SFTP_USER`, `PROD_SSH_KEY_PATH` (repeated for `STAGING_*`), `BACKUP_DIR`, `BACKUP_RETENTION`

### Building
```bash
cd tools/sync-tui
go build -o amplifi-sync .
./amplifi-sync
```

### Key Packages
- `internal/serializer` — PHP serialize/unserialize, serialization-safe URL replacement (handles nested serialization, JSON inside serialized data like Elementor). 31 tests.
- `internal/sync` — File diff (hash-based), database comparison/sync with URL mappings, media transfer, backup/rollback.
- `internal/remote` — SSH connection pool shared by SFTP + WP-CLI. Emergency recovery via `wp db import`.

## Development
```bash
cd plugins/ac-wp-translator
docker-compose up -d    # WordPress on :8085, MySQL on :3310

cd plugins/ac-bulk-meta
docker-compose up -d    # WordPress on :8086, MySQL on :3311

cd plugins/ac-magic-links
docker-compose up -d    # WordPress on :8088, MySQL on :3314

cd plugins/ac-static-cache
docker-compose up -d    # WordPress on :8089, MySQL on :3315

cd plugins/ac-pods
docker-compose up -d    # WordPress on :8090, MySQL on :3316

cd plugins/ac-sync
docker-compose up -d    # WordPress on :8091, MySQL on :3317

cd plugins/amplifi-security
docker-compose up -d    # WordPress on :8092, MySQL on :3318
```
Plugin dirs are volume-mounted so edits are live.

## Social & Blog Content
Each plugin has `social/` and `blog/` directories for versioned marketing content:
- **LinkedIn posts**: `social/linkedin-v{VERSION}-{YYYYMMDD}.md` — includes posting notes, target audience, and a follow-up comment with links
- **Blog posts**: `blog/blog-v{VERSION}-{YYYYMMDD}.md` — includes full SEO metadata table (title tag, meta description, OG tags, focus keyphrase, secondary keywords, schema type), the article body, and internal notes (distribution plan, suggested links, republishing schedule)

Create new versions when asked. Follow LinkedIn best practices (hook before the fold, line breaks for scannability, stats for credibility, link in comments not body, 3-5 hashtags). Follow SEO best practices for blog posts (focus keyphrase in H1/title/meta/first paragraph, comparison tables, structured headings, internal/external link suggestions).

## API Key Security
Document to users: scope your OpenAI keys to Chat Completions only, set rate limits and spending caps in the OpenAI dashboard, and rotate if compromised. Keys are stored in `wp_options` and only sent to `api.openai.com`.
