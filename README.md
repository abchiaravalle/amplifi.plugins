<h1 align="center">amplifi.plugins</h1>

<p align="center">
  The complete WordPress suite by <a href="https://amplifi.studio">amplifi.studio</a><br>
  Eleven tools in a single plugin. Install once, enable what you need.
</p>

<p align="center">
  <a href="https://github.com/abchiaravalle/amplifi.plugins/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/abchiaravalle/amplifi.plugins?label=release"></a>
  <img alt="WordPress 6.4+" src="https://img.shields.io/badge/WordPress-6.4%2B-21759b">
  <img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="License MIT" src="https://img.shields.io/badge/license-MIT-green">
</p>

---

## Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Enabling features](#enabling-features)
- [Auto-updates](#auto-updates)
- [Releases](#releases)
- [API key security](#api-key-security)
- [Development](#development)
- [Documentation](#documentation)
- [License](#license)

## Overview

**amplifi.plugins** bundles every amplifi.studio tool into one WordPress plugin.
You install a single zip, activate it once, and turn individual features on or off
from the **amplifi.studio** hub page. Every feature lives under the
**amplifi.studio** menu in the admin sidebar.

All features are **disabled by default**. Activating the plugin loads the shared
framework and nothing else. Open **amplifi.studio** in the sidebar and flip on the
ones you want — their menus appear immediately.

## Features

| Feature | Slug | What it does |
|---|---|---|
| **Schema** | `schema` | AI schema.org JSON-LD generation, editing, validation, and deployment. Single `@graph` output in `<head>`, per-post editor, foreign-source detection (Yoast / Rank Math / SEOPress / AIOSEO) with auto-override. |
| **Security** | `security` | Security scanning with AI triage. Local scanners surface findings, AI triages them with your own API key, and alerts fire only on confirmed or likely verdicts. |
| **Optimize** | `optimize` | AI SEO triage. Scans for fixable issues (titles, meta descriptions, alt text, unpublish candidates), drafts fixes, and lets a human approve each one. |
| **Meta** | `meta` | Bulk SEO meta editor with FAQ generation. Edit Yoast titles, descriptions, and focus keyphrases across all post types. |
| **Translate** | `translate` | Real-time AI translation. URL-based language prefixes (`/es/`, `/fr/`, `/zh/`), per-language native-speaker prompts, caching, hreflang, and a multilingual sitemap. |
| **Alt** | `alt` | AI alt text for WordPress images. Bulk-generate across the existing media library and auto-generate on upload, with spend caps and daily email reports. |
| **Consent** | `consent` | GDPR/CCPA cookie consent that genuinely withholds trackers until the visitor accepts. Server-side consent log, GPC support, CCPA opt-out controls, and optional auto-blocking of unmanaged trackers. |
| **Magic** | `magic` | One-click magic links for password-protected pages. Shareable tokens that auto-set WordPress password cookies, with usage logging and IP geolocation. |
| **LockCache** | `cache` | Static HTML cache for password-protected posts. Caches unlocked content for non-admin visitors and serves it instantly. |
| **Pods** | `pods` | Podcast carousel and floating player. Apple Podcasts and Spotify support via shortcode. |
| **Sync** | `sync` | WordPress environment sync. REST API for file, database, and media operations between production and staging. |

AI-backed features use your own API key and enforce per-day and per-month spend
caps. See each feature's doc in [`docs/features/`](docs/features/) for its
provider, model, and settings.

> Note the slug/name split on one feature: the slug is `cache`, the product name is
> **LockCache**. Use the slug in code and options.

## Installation

1. Download `amplifi-plugins-vX.Y.Z.zip` from
   [Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).
   Take the **zip asset**, not the "Source code" archive — the source archive has
   the repo layout, not the plugin layout, and will not activate.
2. In WordPress admin: **Plugins &rarr; Add Plugin &rarr; Upload Plugin**.
3. Upload the zip and activate.
4. Open **amplifi.studio** in the sidebar.

Or by WP-CLI:

```bash
wp plugin install https://github.com/abchiaravalle/amplifi.plugins/releases/latest/download/amplifi-plugins-v3.3.7.zip --activate
```

If you previously installed any of the individual amplifi plugins, the suite
detects them on activation and offers a one-click button to deactivate the old
ones. Your data is preserved — the combined plugin reads the same tables and
options.

Full guide: [docs/installation.md](docs/installation.md).

## Enabling features

Go to **amplifi.studio** (`admin.php?page=amplifi-studio`). Each feature has a
toggle. Flip one on and its menus appear in the sidebar; flip it off to unload it.

State lives in a single option, which makes scripted provisioning easy:

```bash
wp option get amplifi_plugins_enabled_features --format=json
wp option update amplifi_plugins_enabled_features '["schema","consent"]' --format=json
```

## Auto-updates

The plugin checks GitHub for new releases every 6 hours and surfaces updates in
**Dashboard &rarr; Updates** like any other plugin. A **Check Now** button on the
hub forces an immediate check. No configuration needed.

> **A release is a fleet-wide deploy.** Every site running this plugin will be
> offered the new version within six hours. There is no staged rollout and no
> per-site version pinning.

## Releases

```bash
./scripts/release.sh 3.3.8
```

The script bumps the version in the plugin header, in `AMPLIFI_PLUGINS_VERSION`,
and in all eleven per-feature version constants; commits the bump; builds
`amplifi-plugins-vX.Y.Z.zip`; generates a changelog from git history; tags; and
publishes the release with the zip and manifest attached.

Every release must use a new version number so the auto-updater can detect it.
Read [docs/releasing.md](docs/releasing.md) before your first one — the script
runs `git add -A`, rebuilds `dist/` with `rm -rf`, and publishes without a
confirmation prompt.

## API key security

AI features require your own API key. You are responsible for securing it:

- **Scope keys** to the Messages API (Anthropic) or Chat Completions (OpenAI) only.
- **Set spend caps** in your provider's console, plus the per-day and per-month
  caps inside each feature's settings. Do this before your first bulk run.
- **Rotate** any key you suspect is compromised.
- Keys are encrypted at rest (AES-256-GCM via the WordPress `AUTH_KEY` family) and
  are only sent to their respective vendor APIs.

## Development

```
amplifi.plugins/
├── plugins/amplifi-plugins/          # The combined plugin — the ONLY thing that ships
│   ├── amplifi-plugins.php           # Bootstrap: loads framework, gates features
│   ├── uninstall.php                 # Delegates to each feature's uninstall
│   ├── docker-compose.yml            # Dev env — WordPress :8094, MySQL :3320
│   ├── includes/amplifi-framework.php  # Bundled copy of shared/ (do not edit here)
│   └── features/
│       ├── schema/      security/     optimize/    meta/
│       ├── translate/   alt/          consent/     magic/
│       └── cache/       pods/         sync/
├── plugins/ac-*/                     # Legacy standalone plugins (reference only, not built)
├── shared/amplifi-framework.php      # STALE snapshot — not shipped, see docs/architecture.md
├── scripts/release.sh                # Build & publish
├── plugins-manifest.json             # Feature catalog (published as a release asset)
├── tools/sync-tui/                   # Go TUI companion for amplifi.sync
├── docs/                             # Documentation
└── CLAUDE.md                         # AI assistant context
```

Each feature lives in `features/<slug>/` with its original code intact and its
plugin header stripped, so WordPress treats the suite as one plugin. The bootstrap
loads the shared framework once, then `require_once`s only the features whose slug
is in `amplifi_plugins_enabled_features`. Schema and Security use PSR-style
autoloaders under their own namespaces; the other features use global class
prefixes.

Two rules that save real time:

- **Edit the framework copy that ships:**
  `plugins/amplifi-plugins/includes/amplifi-framework.php`. The bootstrap loads
  that path and `release.sh` packages it verbatim. `shared/amplifi-framework.php`
  is **not** copied in at release time — it is a stale snapshot that predates the
  feature-toggle hub.
- **Never edit plugin files on a live site.** The next auto-update silently
  overwrites them. Use a small mu-plugin to override behaviour instead — see
  [docs/architecture.md](docs/architecture.md#site-specific-overrides).

Local dev:

```bash
cd plugins/amplifi-plugins
docker-compose up -d        # WordPress on :8094, MySQL on :3320
```

The plugin directory is volume-mounted, so edits are live.

## Documentation

| Document | What it covers |
|---|---|
| [docs/](docs/README.md) | Documentation index |
| [Installation](docs/installation.md) | Requirements, install, enabling features, upgrading |
| [Architecture](docs/architecture.md) | Single-plugin model, boot sequence, framework, auto-updates |
| [Releasing](docs/releasing.md) | Build, tag, publish, verify, and roll back |
| [Feature reference](docs/features/) | Per-feature architecture, options, tables, routes, pitfalls |

## License

MIT License — Zero Warranty. See [LICENSE](LICENSE) for the full text.

This software is provided "AS IS" without warranty of any kind. amplifi.studio
makes no guarantees regarding quality, reliability, accuracy, or fitness for a
particular purpose. Use at your own risk.
