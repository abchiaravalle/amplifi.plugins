<h1 align="center">amplifi.plugins</h1>

<p align="center">
  The complete WordPress suite by <a href="https://amplifi.studio">amplifi.studio</a><br>
  Ten AI-powered tools in a single plugin. Install once, enable what you need.
</p>

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Enabling Features](#enabling-features)
- [Auto-Updates](#auto-updates)
- [Releases](#releases)
- [API Key Security](#api-key-security)
- [Development](#development)
- [License](#license)

## Overview

**amplifi.plugins** bundles every amplifi.studio tool into one WordPress plugin. You install a single zip, activate it once, and turn individual features on or off from the **amplifi.studio** hub page. Every feature lives under the **amplifi.studio** menu in the WordPress admin sidebar.

All features are **disabled by default**. Open **amplifi.studio** in the sidebar and flip on the ones you want — their menus appear immediately.

## Features

| Feature | What it does |
|---------|--------------|
| **Schema** | AI schema.org JSON-LD generation, editing, validation, and deployment. Single `@graph` output in `<head>`, per-post editor, foreign-source detection (Yoast / Rank Math / SEOPress / AIOSEO) with auto-override. |
| **Security** | AI-powered security scanning with Claude triage. Local scanners surface findings; Claude triages them with your own API key; alerts fire only on confirmed/likely verdicts. |
| **Optimize** | AI SEO triage. Scans for fixable issues (titles, meta descriptions, alt text, unpublish candidates), drafts fixes with Claude, and lets a human approve each one. |
| **Meta** | Bulk SEO meta editor with FAQ generation. Edit Yoast titles, descriptions, and focus keyphrases across all post types. |
| **Translate** | AI-powered real-time translation via Claude. URL-based language prefixes (`/es/`, `/fr/`, `/zh/`), per-language native-speaker prompts, smart caching, hreflang + multilingual sitemap. |
| **Alt** | AI alt text for WordPress images. Bulk-generate for the existing media library and auto-generate on upload, with spend caps and daily email reports. |
| **Magic** | One-click magic links for password-protected pages. Shareable tokens that auto-set WP password cookies, with usage logging and IP geolocation. |
| **LockCache** | Static HTML cache for password-protected posts. Caches unlocked content for non-admin visitors and serves it instantly. |
| **Pods** | Podcast carousel and floating player. Apple Podcasts + Spotify support via shortcode. |
| **Sync** | WordPress environment sync. REST API for file, database, and media operations between production and staging. |

AI features (Schema, Security, Optimize, Translate, Alt, and the AI parts of Meta) use the **Anthropic Claude API** (or OpenAI, where noted) with your own key. Each enforces per-day and per-month spend caps.

## Installation

1. Download `amplifi-plugins-vX.Y.Z.zip` from [Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins → Add Plugin → Upload Plugin**
3. Upload the zip and activate
4. Open **amplifi.studio** in the sidebar

If you previously installed any of the individual amplifi plugins, the suite detects them on activation and offers a one-click button to deactivate the old ones. Your data is preserved — the combined plugin reads the same database tables and options.

## Enabling Features

Go to **amplifi.studio** (`admin.php?page=amplifi-studio`). Each feature has a toggle. Flip one on and the page reloads with that feature's menus added to the sidebar. Flip it off to unload it. Toggle state is stored in the `amplifi_plugins_enabled_features` option.

## Auto-Updates

The plugin checks `github.com/abchiaravalle/amplifi.plugins` for new releases every 6 hours and surfaces updates in **Dashboard → Updates** like any other plugin. A **Check for updates** button on the amplifi.studio hub forces an immediate check. No configuration needed.

## Releases

```bash
./scripts/release.sh 3.1.0
```

The script bumps the version in the plugin header and `AMPLIFI_PLUGINS_VERSION` constant, commits it, builds a single `amplifi-plugins-vX.Y.Z.zip`, generates a changelog from git history, tags the release, and publishes it to GitHub with the zip and manifest attached. Every release must use a new version number so the auto-updater can detect it.

## API Key Security

AI features require your own API key (Anthropic Claude or OpenAI, depending on the feature). You are responsible for securing your keys:

- **Scope keys** to the Messages API (Anthropic) or Chat Completions (OpenAI) only
- **Set spend caps** in your provider's console, plus the per-day/per-month caps inside each feature's settings
- **Rotate** any key you suspect is compromised
- Keys are encrypted at rest (AES-256-GCM via the WordPress `AUTH_KEY` family) and only sent to their respective vendor APIs

## Development

```
amplifi.plugins/
├── plugins/
│   └── amplifi-plugins/              # The combined plugin
│       ├── amplifi-plugins.php       # Master bootstrap: loads framework, gates features
│       ├── uninstall.php             # Delegates to each feature's uninstall
│       ├── docker-compose.yml        # Dev env — WordPress :8094, MySQL :3320
│       ├── includes/
│       │   └── amplifi-framework.php # Shared menu, hub (feature toggles), auto-updater
│       └── features/
│           ├── schema/               # AI schema.org (namespace Amplifi\Schema)
│           ├── security/             # AI security scanner (namespace Amplifi\Security)
│           ├── optimize/             # AI SEO triage (React admin UI)
│           ├── meta/                 # Bulk SEO meta + FAQ
│           ├── translate/            # AI translation
│           ├── alt/                  # AI alt text
│           ├── magic/                # Magic links
│           ├── cache/                # Static cache
│           ├── pods/                 # Podcast carousel
│           └── sync/                 # Environment sync
├── plugins/ac-*/                     # Standalone individual plugins (legacy reference copies)
├── scripts/
│   ├── release.sh                    # Build & publish the combined release
│   └── ...
├── plugins-manifest.json             # Feature catalog
├── LICENSE                           # MIT / Zero Warranty
├── CLAUDE.md                         # AI assistant context
└── README.md                         # This file
```

Each feature lives in `features/{name}/` with its original code intact — its plugin header stripped so WordPress treats the suite as one plugin. The master bootstrap loads the shared framework once, then `require_once`s only the features whose slug is in `amplifi_plugins_enabled_features`. Schema and Security use PSR-style autoloaders under their own namespaces; the other features use global class prefixes.

Local dev:

```bash
cd plugins/amplifi-plugins
docker-compose up -d        # WordPress on :8094, MySQL on :3320
```

The plugin directory is volume-mounted, so edits are live.

## License

MIT License — Zero Warranty. See [LICENSE](LICENSE) for the full text.

This software is provided "AS IS" without warranty of any kind. amplifi.studio makes no guarantees regarding quality, reliability, accuracy, or fitness for a particular purpose. Use at your own risk.
