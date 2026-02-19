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
Podcast carousel and floating player via Apple Podcasts RSS feed or built-in custom post type.

### Architecture
- **Single-file plugin**: All logic in `ac-pods.php` — CPT, taxonomy, meta boxes, shortcode, carousel, floating player, admin page
- **Dual Mode**: RSS Feed mode (parses Apple Podcasts RSS) or CPT mode (queries `amplifi-podcasts` posts)
- **Swiper Carousel**: Responsive card carousel via Swiper.js CDN, multiple instances per page
- **Floating Player**: Fixed-position Apple Podcasts embed player with slide-up animation
- **RSS Caching**: 1-hour transient cache keyed on `md5($feed_url)`
- **Site-Agnostic**: No font declarations, no Bootstrap, all styling via `--acpods-*` CSS custom properties

### Key Files
- `ac-pods.php` - Everything: bootstrap, framework registration, CPT, taxonomy, meta boxes, shortcode, carousel, player, CSS, JS, admin page
- `includes/amplifi-framework.php` - Shared framework (copied from `shared/`)
- `uninstall.php` - Removes all `amplifi-podcasts` posts and RSS cache transients

### Admin Menu
Single page under the **amplifi.studio** menu:
- **Pods** — registered via `amplifi_register_plugin()` → slug: `amplifi-ac-pods`

Hook suffix: `amplifi-studio_page_amplifi-ac-pods`

### Data Storage
**Post meta on `amplifi-podcasts` CPT:**
| Key | Purpose |
|-----|---------|
| `_acpods_show_name` | Podcast show name |
| `_acpods_apple_show_id` | Apple show ID (for embed) |
| `_acpods_apple_episode_id` | Apple episode ID (for embed) |
| `_acpods_artwork_url` | Episode artwork URL |
| `_acpods_episode_number` | Episode number |
| `_acpods_duration` | Duration (e.g. "42 min") |

**Transients:** `acpods_rss_{md5}` — cached RSS feed data (1 hour)

### Shortcode
```
[amplifi-pods feed="https://..." count="8"]   <!-- RSS mode -->
[amplifi-pods]                                 <!-- CPT mode, all episodes -->
[amplifi-pods category="tech" count="6"]       <!-- CPT mode, filtered -->
```

## Releasing
```bash
./scripts/release.sh 1.0.0
```
Always releases **all** plugins — single-plugin releases are not supported. The dynamic plugin hub depends on every plugin zip being present in the latest release for one-click install to work.

The script: validates semver, copies `shared/amplifi-framework.php` + `LICENSE` into each plugin, zips them, includes `plugins-manifest.json`, generates changelog from git log, creates a GitHub release with all assets.

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
```
Plugin dirs are volume-mounted so edits are live.

## Social & Blog Content
Each plugin has `social/` and `blog/` directories for versioned marketing content:
- **LinkedIn posts**: `social/linkedin-v{VERSION}-{YYYYMMDD}.md` — includes posting notes, target audience, and a follow-up comment with links
- **Blog posts**: `blog/blog-v{VERSION}-{YYYYMMDD}.md` — includes full SEO metadata table (title tag, meta description, OG tags, focus keyphrase, secondary keywords, schema type), the article body, and internal notes (distribution plan, suggested links, republishing schedule)

Create new versions when asked. Follow LinkedIn best practices (hook before the fold, line breaks for scannability, stats for credibility, link in comments not body, 3-5 hashtags). Follow SEO best practices for blog posts (focus keyphrase in H1/title/meta/first paragraph, comparison tables, structured headings, internal/external link suggestions).

## API Key Security
Document to users: scope your OpenAI keys to Chat Completions only, set rate limits and spending caps in the OpenAI dashboard, and rotate if compromised. Keys are stored in `wp_options` and only sent to `api.openai.com`.
