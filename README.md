<p align="center">
  <picture>
    <source media="(prefers-color-scheme: light)" srcset="assets/icon-on-dark.svg">
    <source media="(prefers-color-scheme: dark)" srcset="plugins/ac-wp-translator/assets/icon.svg">
    <img src="assets/icon-on-dark.svg" alt="amplifi.studio" width="280">
  </picture>
</p>

<h1 align="center">amplifi.plugins</h1>

<p align="center">
  WordPress plugin suite by <a href="https://amplifi.studio">amplifi.studio</a><br>
  AI-powered tools that just work. Install one, discover the rest.
</p>

---

## Table of Contents

- [Plugins](#plugins)
  - [amplifi.translate](#amplifitranslate)
  - [amplifi.meta](#amplifimeta)
  - [amplifi.magic](#amplifimagic)
  - [amplifi.lockcache](#amplifilockcache)
  - [amplifi.pods](#amplifipods)
- [Installation](#installation)
- [Auto-Updates](#auto-updates)
- [Releases](#releases)
- [API Key Security](#api-key-security)
- [Development](#development)
- [License](#license)

## Plugins

All amplifi plugins appear under a single **amplifi.studio** menu in the WordPress admin sidebar. Install any plugin and use the **Plugin Hub** to discover and install others.

### amplifi.translate

AI-powered real-time translation for WordPress using OpenAI. Translates pages and posts on the fly with URL-based language prefixes (`/es/`, `/fr/`, `/zh/`, etc.) and smart database caching.

**Features:**
- URL-based language routing (`/es/page-slug/`, `/fr/page-slug/`)
- OpenAI-powered translation (any model - GPT-4o Mini recommended)
- Smart caching with auto-invalidation on content changes
- Language switcher as nav menu item or `[acwpt_switcher]` shortcode
- Browser language detection with suggestion banner
- SEO: hreflang tags, `<html lang>`, multilingual sitemap at `/acwpt-sitemap.xml`
- 33 languages supported
- API usage tracking with cost estimates

**Screenshots:**

| Admin Settings | English Source | Spanish | Chinese |
|:-:|:-:|:-:|:-:|
| ![Admin](plugins/ac-wp-translator/assets/screenshots/admin-settings.png) | ![English](plugins/ac-wp-translator/assets/screenshots/page-english.png) | ![Spanish](plugins/ac-wp-translator/assets/screenshots/page-spanish.png) | ![Chinese](plugins/ac-wp-translator/assets/screenshots/page-chinese.png) |

[Full documentation](plugins/ac-wp-translator/README.md)

### amplifi.meta

AI-powered bulk SEO meta editor for WordPress. Edit Yoast SEO metadata (titles, descriptions, focus keyphrases) across all post types with bulk AI generation via OpenAI.

**Features:**
- Bulk edit Yoast SEO titles, meta descriptions, and focus keyphrases
- AI-powered generation for all metadata types (single or bulk)
- FAQ generation and deployment with accordion/expanded display modes
- JSON-LD structured data generation (Organization + per-page)
- Dark mode, webhook logging, CSV export
- Custom AI writing style instructions
- Global FAQ deploy settings with custom CSS

### amplifi.magic

One-click magic links for WordPress password-protected pages. Generate shareable tokens that auto-set WP password cookies — no password entry needed. Includes usage logging with IP geolocation.

**Features:**
- Generate named magic link tokens for any password-protected post/page
- One-click access — sets hashed WP password cookies automatically
- Token revocation with revoked tokens history
- Per-token usage logs with date/time, IP address, and geolocation
- Unified, filterable access logs table (filter by page, token, IP, location, date range)
- Copy-to-clipboard for sharing magic links

### amplifi.lockcache

Static HTML cache for password-protected WordPress posts. Caches unlocked content for non-admin visitors, serves it instantly on subsequent visits, and provides an admin panel for cache management and debug logging.

**Features:**
- Automatic static HTML caching of unlocked password-protected posts
- Skips caching for admin users (avoids admin bar in cache)
- Skips caching for AJAX and Search & Filter Pro requests
- Cache directory protected by `.htaccess` with 0600 file permissions
- Admin panel: view all password-protected posts, cache status, clear individual or all caches
- Preload all caches, debug log viewer (newest first)
- No-cache headers for still-locked posts

### amplifi.pods

Podcast carousel and floating player for WordPress. Display episodes from an Apple Podcasts RSS feed or a built-in custom post type with a Swiper-powered carousel and floating Apple Podcasts embed player.

**Features:**
- Dual mode: RSS feed parsing or custom post type episodes
- Responsive Swiper.js carousel with breakpoints for mobile/tablet/desktop
- Floating Apple Podcasts embed player with slide-up animation
- Episode categories for CPT mode filtering
- Site-agnostic: no font declarations, no Bootstrap, CSS custom properties for theme overrides
- RSS feed caching (1 hour) for performance
- Admin documentation page with shortcode reference and episode list

## Installation

1. Download the latest plugin zip from [Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip and activate
4. Find the plugin under the **amplifi.studio** sidebar menu

## Auto-Updates

All amplifi plugins support automatic updates from GitHub releases. When a new version is published, WordPress will show it in **Dashboard > Updates** just like any other plugin. No configuration needed.

## Releases

Releases are created with the included release script:

```bash
./scripts/release.sh 1.2.0                    # Release all plugins
./scripts/release.sh 1.2.0 ac-wp-translator   # Release specific plugin
./scripts/release.sh 1.2.0 ac-bulk-meta       # Release specific plugin
./scripts/release.sh 1.2.0 ac-magic-links     # Release specific plugin
./scripts/release.sh 1.2.0 ac-static-cache   # Release specific plugin
./scripts/release.sh 1.2.0 ac-pods           # Release specific plugin
```

This will:
1. Bundle each plugin into a production-ready zip (excluding dev files)
2. Generate a changelog from git history since the last release
3. Create a GitHub release with the zips attached
4. Tag the release for auto-update discovery

## API Key Security

Plugins that use external APIs (like OpenAI) require you to provide your own API key. **You are responsible for securing your keys:**

- **Scope your keys** - Use OpenAI's API key permissions to restrict access to only the endpoints the plugin needs (Chat Completions)
- **Set rate limits** - Configure spending limits and rate limits in your OpenAI dashboard to prevent unexpected charges
- **Set budget alerts** - Enable billing alerts at [platform.openai.com](https://platform.openai.com/settings/organization/billing/overview)
- **Rotate keys** - If you suspect a key has been compromised, rotate it immediately
- Keys are stored in your WordPress database (`wp_options`) and are never transmitted to any server other than OpenAI's API

## Development

```
amplifi.plugins/
├── plugins/
│   ├── ac-wp-translator/          # amplifi.translate plugin
│   │   ├── ac-wp-translator.php   # Main plugin file
│   │   ├── includes/              # PHP classes
│   │   ├── assets/                # CSS, JS, screenshots, icon
│   │   ├── uninstall.php          # Clean uninstall
│   │   ├── docker-compose.yml     # Dev environment
│   │   └── README.md              # Plugin-specific docs
│   ├── ac-bulk-meta/              # amplifi.meta plugin
│   │   ├── ac-bulk-meta.php       # Main plugin file (monolith)
│   │   ├── includes/              # Framework (copied at release)
│   │   ├── uninstall.php          # Clean uninstall
│   │   └── docker-compose.yml     # Dev environment
│   ├── ac-magic-links/            # amplifi.magic plugin
│   │   ├── ac-magic-links.php     # Main plugin file
│   │   ├── includes/              # Framework (copied at release)
│   │   ├── uninstall.php          # Clean uninstall
│   │   └── docker-compose.yml     # Dev environment
│   ├── ac-static-cache/           # amplifi.lockcache plugin
│   │   ├── ac-static-cache.php    # Main plugin file
│   │   ├── includes/              # Framework (copied at release)
│   │   ├── uninstall.php          # Clean uninstall
│   │   └── docker-compose.yml     # Dev environment
│   └── ac-pods/                   # amplifi.pods plugin
│       ├── ac-pods.php            # Main plugin file
│       ├── includes/              # Framework (copied at release)
│       ├── uninstall.php          # Clean uninstall
│       └── docker-compose.yml     # Dev environment
├── shared/
│   └── amplifi-framework.php      # Shared: admin menu, auto-updates, hub
├── scripts/
│   └── release.sh                 # Build & publish releases
├── LICENSE                        # MIT / Zero Warranty
├── CLAUDE.md                      # AI assistant context
└── README.md                      # This file
```

Each plugin has its own `docker-compose.yml` for local development:

```bash
cd plugins/ac-wp-translator
docker-compose up -d
# WordPress on :8085, MySQL on :3310

cd plugins/ac-bulk-meta
docker-compose up -d
# WordPress on :8086, MySQL on :3311

cd plugins/ac-magic-links
docker-compose up -d
# WordPress on :8088, MySQL on :3314

cd plugins/ac-static-cache
docker-compose up -d
# WordPress on :8089, MySQL on :3315

cd plugins/ac-pods
docker-compose up -d
# WordPress on :8090, MySQL on :3316
```

## License

MIT License - Zero Warranty. See [LICENSE](LICENSE) for the full text.

This software is provided "AS IS" without warranty of any kind. amplifi.studio makes no guarantees regarding quality, reliability, accuracy, or fitness for a particular purpose. Use at your own risk.
