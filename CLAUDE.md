# CLAUDE.md - amplifi.plugins

## Overview
Monorepo for the **amplifi.studio** WordPress plugin suite. All plugins share the `amplifi-framework.php` for a unified admin menu, auto-updates from GitHub releases, and a plugin hub.

## Repository Structure
- `plugins/` - Each plugin in its own directory (e.g. `plugins/ac-wp-translator/`)
- `shared/amplifi-framework.php` - Shared code: top-level admin menu, plugin registry, GitHub auto-updater, hub page. Bundled into each plugin's `includes/` at release time.
- `scripts/release.sh` - Builds plugin zips, generates changelog, creates GitHub release with assets.
- `LICENSE` - MIT with zero warranty clause.

## amplifi.studio Framework (`shared/amplifi-framework.php`)
- **Admin Menu**: Registers a top-level "amplifi.studio" menu at position 3. Each plugin adds itself as a submenu via `amplifi_register_plugin()`.
- **Plugin Hub**: Hub page at `admin.php?page=amplifi-studio` lists all installed + available plugins.
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

## Releasing
```bash
./scripts/release.sh 1.0.0                  # All plugins
./scripts/release.sh 1.0.0 ac-wp-translator # Just translate
```
The script: validates semver, copies `shared/amplifi-framework.php` + `LICENSE` into each plugin, zips them, generates changelog from git log, creates a GitHub release with assets.

## Development
```bash
cd plugins/ac-wp-translator
docker-compose up -d    # WordPress on :8085, MySQL on :3310
```
Plugin dir is volume-mounted so edits are live.

## API Key Security
Document to users: scope your OpenAI keys to Chat Completions only, set rate limits and spending caps in the OpenAI dashboard, and rotate if compromised. Keys are stored in `wp_options` and only sent to `api.openai.com`.
