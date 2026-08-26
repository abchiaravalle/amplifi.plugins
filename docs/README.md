# amplifi.plugins documentation

Reference documentation for the amplifi.studio WordPress suite.

## Start here

| Document | What it covers |
|---|---|
| [Installation](installation.md) | Requirements, install, enabling features, upgrading from the old standalone plugins |
| [Architecture](architecture.md) | The single-plugin model, boot sequence, the shared framework, auto-updates |
| [Releasing](releasing.md) | Building, tagging, and publishing a version — and what that does to every site |

## Feature reference

Each feature is independently toggleable and off by default. Slug is what you use
in code and in the `amplifi_plugins_enabled_features` option; name is what appears
in the UI.

| Feature | Slug | What it does |
|---|---|---|
| [Schema](features/schema.md) | `schema` | AI schema.org JSON-LD generation, editing, validation, and deployment |
| [Security](features/security.md) | `security` | Security scanning with AI triage and verdict-gated alerts |
| [Optimize](features/optimize.md) | `optimize` | AI SEO triage: scan, draft fixes, human approves each one |
| [Meta](features/meta.md) | `meta` | Bulk SEO meta editor with FAQ generation and JSON-LD |
| [Translate](features/translate.md) | `translate` | Real-time AI translation with URL language prefixes, hreflang, and a multilingual sitemap |
| [Alt](features/alt.md) | `alt` | AI alt text for the media library and on upload |
| [Consent](features/consent.md) | `consent` | GDPR/CCPA cookie consent that genuinely withholds trackers until consent |
| [Magic](features/magic.md) | `magic` | One-click magic links for password-protected pages |
| [LockCache](features/cache.md) | `cache` | Static HTML cache for password-protected posts |
| [Pods](features/pods.md) | `pods` | Podcast carousel and floating player |
| [Sync](features/sync.md) | `sync` | REST API sync between WordPress environments |

## Two things worth knowing up front

**Publishing a release deploys to every site.** Every install polls
`releases/latest` and offers the new version within six hours. There is no staged
rollout. See [Releasing](releasing.md).

**Never edit plugin files on a live site.** The next auto-update overwrites them
silently. For site-specific behaviour, add a small mu-plugin that overrides from a
late `wp_head` hook. See [Architecture](architecture.md#site-specific-overrides).

## Repository layout

```
amplifi.plugins/
├── plugins/amplifi-plugins/   the ONLY thing that ships
├── plugins/<legacy>/          historical per-plugin dirs, not built, not released
├── shared/amplifi-framework.php   STALE snapshot — NOT shipped (see architecture.md)
├── plugins-manifest.json      feature catalog, published as a release asset
├── scripts/release.sh         build + tag + publish
├── dist/                      build output, regenerated each release
├── tools/sync-tui/            Go TUI companion for amplifi.sync
└── docs/                      this documentation
```

## Design notes and audits

Historical design specs and audit records, kept for provenance. These describe
intent at a point in time and are **not** a reliable description of current
behaviour — the feature docs above are.

- `superpowers/plans/` — implementation plans
- `superpowers/specs/` — design specs
- `audits/` — audit records
