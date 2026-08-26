# Architecture

How amplifi.plugins is put together: one WordPress plugin, one activation, eleven
independently toggleable features that load only when you turn them on.

## At a glance

| | |
|---|---|
| Plugin slug | `amplifi-plugins` |
| Entry file | `plugins/amplifi-plugins/amplifi-plugins.php` |
| Version constant | `AMPLIFI_PLUGINS_VERSION` |
| Requires | WordPress 6.4+, PHP 8.1+ |
| License | MIT |
| Update source | GitHub releases on `abchiaravalle/amplifi.plugins` |
| Feature toggle | `wp_options` &rarr; `amplifi_plugins_enabled_features` (array of slugs) |

## The single-plugin model

Earlier versions of this suite shipped as separate plugins, one zip per tool. That
is no longer the case. Everything ships inside `amplifi-plugins`, and each tool is
a *feature* living under `features/<slug>/`.

The `plugins/` directory in this repo still contains the legacy per-plugin
directories (`ac-schema/`, `ac-bulk-meta/`, `amplifi-security/` and so on). Those
are **historical reference only**. They are not built, not released, and not
shipped. The only directory that becomes a release artifact is
`plugins/amplifi-plugins/`.

```
plugins/amplifi-plugins/          <-- the ONLY thing that ships
├── amplifi-plugins.php           bootstrap: constants, feature registry, toggles
├── uninstall.php
├── includes/
│   └── amplifi-framework.php     THE shipping framework — edit this one
└── features/
    ├── alt/          ac-alt-text.php
    ├── cache/        ac-static-cache.php
    ├── consent/      ac-consent.php
    ├── magic/        ac-magic-links.php
    ├── meta/         ac-bulk-meta.php
    ├── optimize/     amplifi-optimize.php
    ├── pods/         ac-pods.php
    ├── schema/       ac-schema.php
    ├── security/     amplifi-security.php
    ├── sync/         ac-sync.php
    └── translate/    ac-wp-translator.php
```

## Boot sequence

`amplifi-plugins.php` runs in this order:

1. **Guard.** Bail unless `ABSPATH` is defined.
2. **Constants.** Define `AMPLIFI_PLUGINS_VERSION`, `_FILE`, `_PATH`, `_URL`,
   `_BASENAME`.
3. **Framework first.** `require_once includes/amplifi-framework.php`. This must
   happen before any feature loads, so the admin menu, the hub page, and the
   auto-updater are registered exactly once.
4. **Self-registration for updates.** On `init`, the plugin adds itself to the
   global `$amplifi_plugins` registry so the updater matches the
   `amplifi-plugins-vX.Y.Z.zip` release asset.
5. **Feature registry.** `$amplifi_all_features` maps each slug to its entry file,
   display name, and description.
6. **Selective load.** Read `amplifi_plugins_enabled_features` from options and
   `require_once` only the entry files whose slug is in that array.

Everything is off by default. A fresh install activates the plugin and loads
nothing but the framework until you enable features in the hub.

### The feature registry

```php
$amplifi_all_features = [
    'schema'    => [ 'file' => 'features/schema/ac-schema.php',           'name' => 'Schema',    ... ],
    'security'  => [ 'file' => 'features/security/amplifi-security.php',  'name' => 'Security',  ... ],
    'meta'      => [ 'file' => 'features/meta/ac-bulk-meta.php',          'name' => 'Meta',      ... ],
    'magic'     => [ 'file' => 'features/magic/ac-magic-links.php',       'name' => 'Magic',     ... ],
    'pods'      => [ 'file' => 'features/pods/ac-pods.php',               'name' => 'Pods',      ... ],
    'cache'     => [ 'file' => 'features/cache/ac-static-cache.php',      'name' => 'LockCache', ... ],
    'sync'      => [ 'file' => 'features/sync/ac-sync.php',               'name' => 'Sync',      ... ],
    'translate' => [ 'file' => 'features/translate/ac-wp-translator.php', 'name' => 'Translate', ... ],
    'alt'       => [ 'file' => 'features/alt/ac-alt-text.php',            'name' => 'Alt',       ... ],
    'optimize'  => [ 'file' => 'features/optimize/amplifi-optimize.php',  'name' => 'Optimize',  ... ],
    'consent'   => [ 'file' => 'features/consent/ac-consent.php',         'name' => 'Consent',   ... ],
];
```

Note that the slug and the display name diverge in one place: slug `cache` is
presented as **LockCache**. Use the slug in code and options, the name in UI.

### Adding a feature

1. Create `features/<slug>/<entry>.php`.
2. Add an entry to `$amplifi_all_features` in the bootstrap.
3. **Add it to the hardcoded `$all_features` array in `amplifi_render_hub()`**
   (`includes/amplifi-framework.php`, ~line 218). Miss this and the feature loads
   but cannot be toggled on from the UI.
4. Add an entry to `plugins-manifest.json` (name, description, dashicon).
5. If the feature has DB tables, wire its installer into the activation hook.
6. Give the entry file the standard double-load guard (below).

## The double-load guard

Every feature entry file opens with the same pattern:

```php
if ( defined( 'ACCONSENT_VERSION' ) ) {
    return;
}
define( 'ACCONSENT_VERSION', '3.3.7' );
```

This makes a second load a no-op rather than a fatal redeclare. It matters because
a site may still have an older standalone copy of the same tool in
`wp-content/plugins/`, and because some features ship their own bundled framework
copy.

Each feature owns its own version constant, all bumped together by the release
script:

| Feature | Version constant |
|---|---|
| alt | `ACALT_VERSION` |
| cache | `ACSC_VERSION` |
| consent | `ACCONSENT_VERSION` |
| magic | `ACML_VERSION` |
| meta | `ACMETA_VERSION` |
| optimize | `AMPLIFI_OPTIMIZE_VERSION` |
| pods | `ACPODS_VERSION` |
| schema | `AMPLIFI_SCHEMA_VERSION` |
| security | `AMPLIFI_SECURITY_VERSION` |
| sync | `ACSYNC_VERSION` |
| translate | `ACWPT_VERSION` |

Two features (`schema`, `security`) deliberately omit `declare(strict_types=1)`
from their entry file so the file still parses on PHP below 8.1 and can render a
graceful admin notice instead of white-screening.

## The framework

The framework supplies the admin menu, the hub, and the auto-updater. The
`AMPLIFI_FRAMEWORK_LOADED` constant makes every copy after the first a no-op.

> **Which copy actually ships:**
> `plugins/amplifi-plugins/includes/amplifi-framework.php`.
>
> The bootstrap `require_once`s that path, and `scripts/release.sh` packages
> `plugins/amplifi-plugins/` verbatim. **The release script does not copy
> `shared/` into the plugin.** Edit the bundled `includes/` copy — that is the
> live one.

`shared/amplifi-framework.php` is a stale snapshot, last touched 2026-05-14. It is
**68 lines behind** the shipping copy and predates the feature-toggle hub
entirely: it contains no `amplifi_toggle_feature` handler and no Consent
registration. Do not treat it as canonical and do not copy it over the bundled
file — doing so would delete the hub.

Several features also carry their own `includes/amplifi-framework.php`. There are
five distinct variants across the repo, all superseded by the bundled copy at load
time thanks to the guard.

| Copy | Lines | Status |
|---|---|---|
| `plugins/amplifi-plugins/includes/` | 664 | **Ships. Edit this one.** |
| `shared/` | 596 | Stale (2026-05-14), no hub |
| `features/{alt,consent,schema}/includes/` | 596 | Superseded at load |
| `features/security/includes/` | 591 | Superseded at load |
| `features/{cache,magic,meta,pods,sync}/includes/` | 522 | Superseded at load |
| `features/translate/includes/` | 522 | Superseded at load |

Consolidating these onto one file is worthwhile cleanup, but it is a behavioural
change and needs its own testing pass — not something to fold into an unrelated
edit.

The framework provides four things:

### 1. The admin menu

Registers a top-level **amplifi.studio** menu at position 3 on `admin_menu`
priority 5. Features add themselves as submenus via:

```php
amplifi_register_plugin( $slug, $name, $description, $version, $file, $render );
```

Page slugs are **not** derived from the feature slug. `amplifi_register_plugin()`
takes a slug of the feature's own choosing, and `amplifi_page_slug()` prefixes it
with `amplifi-` unless it already starts with that:

```php
function amplifi_page_slug( $slug ) {
    return ( strpos( $slug, 'amplifi-' ) === 0 ) ? $slug : 'amplifi-' . $slug;
}
```

Every feature except `optimize` and `security` registers under its **legacy plugin
slug**, so the page slug does not match the feature slug:

| Feature slug | Registers as | Page slug | Hook suffix |
|---|---|---|---|
| `alt` | `ac-alt-text` | `amplifi-ac-alt-text` | `amplifi-studio_page_amplifi-ac-alt-text` |
| `cache` | `ac-static-cache` | `amplifi-ac-static-cache` | `amplifi-studio_page_amplifi-ac-static-cache` |
| `consent` | `ac-consent` | `amplifi-ac-consent` | `amplifi-studio_page_amplifi-ac-consent` |
| `magic` | `ac-magic-links` | `amplifi-ac-magic-links` | `amplifi-studio_page_amplifi-ac-magic-links` |
| `meta` | `ac-bulk-meta` | `amplifi-ac-bulk-meta` | `amplifi-studio_page_amplifi-ac-bulk-meta` |
| `optimize` | `amplifi-optimize` | `amplifi-optimize` | `amplifi-studio_page_amplifi-optimize` |
| `pods` | `ac-pods` | `amplifi-ac-pods` | `amplifi-studio_page_amplifi-ac-pods` |
| `schema` | `ac-schema` | `amplifi-ac-schema` | `amplifi-studio_page_amplifi-ac-schema` |
| `security` | `amplifi-security` | `amplifi-security` | `amplifi-studio_page_amplifi-security` |
| `sync` | `ac-sync` | `amplifi-ac-sync` | `amplifi-studio_page_amplifi-ac-sync` |
| `translate` | `ac-wp-translator` | `amplifi-ac-wp-translator` | `amplifi-studio_page_amplifi-ac-wp-translator` |

Guessing `amplifi-<feature-slug>` gives you an `admin_enqueue_scripts` comparison
that silently never fires, which is a genuinely annoying bug to chase. Read the
feature's own `amplifi_register_plugin()` call.

### 2. The hub page

`admin.php?page=amplifi-studio` lists every feature with an on/off toggle. Toggling
posts to the `amplifi_toggle_feature` AJAX action, which requires
`manage_options` plus a matching nonce, and rewrites the
`amplifi_plugins_enabled_features` option.

> **The toggle grid is a hardcoded array**, not the manifest. It lives in
> `amplifi_render_hub()` in `includes/amplifi-framework.php` (~line 218). **Adding
> a feature to the registry and to `plugins-manifest.json` is not enough — if you
> do not also add it to that array, the feature cannot be enabled from the UI.**
> This exact bug was fixed by hand in commit `fix(hub): register Consent in the
> feature-toggle grid so it can be enabled` (2026-07-07).

There is a second, separate catalog used for the "available plugins" listing,
fetched by `amplifi_get_manifest()`. It is worth knowing that it currently never
uses the published manifest: the reader accepts a payload only when
`$data['plugins']` is set and `schema_version` is absent or `1`, while the shipped
`plugins-manifest.json` is `schema_version: 2` keyed on `features`. The condition
never matches, so it always falls through to its hardcoded fallback catalog. The
manifest is still published as a release asset, but nothing consumes it.

### 3. Auto-updates

| | |
|---|---|
| Filter | `pre_set_site_transient_update_plugins` &rarr; `amplifi_check_for_updates` |
| Filter | `plugins_api` &rarr; `amplifi_plugin_info` (priority 20) |
| Endpoint | `api.github.com/repos/abchiaravalle/amplifi.plugins/releases/latest` |
| Cache | `amplifi_latest_release` transient, 6 hours (1 hour on failure) |
| Asset match | release asset named `amplifi-plugins-v<version>.zip` |

Updates surface in **Dashboard &rarr; Updates** like any other plugin. The hub has
a **Check Now** button (`amplifi_check_updates` AJAX, requires `update_plugins`)
that clears the transients and forces a re-check.

**Publishing a release updates every site running this plugin.** There is no
staged rollout and no per-site pinning. Treat a release as a fleet-wide deploy.

### 4. One-click install

The hub can install and activate the latest release zip via the
`amplifi_install_plugin` AJAX action, gated on `install_plugins`.

## Activation and deactivation

The activation hook is defensive: it calls each feature's installer only if that
feature's class actually exists, because most features are not loaded at
activation time.

```php
register_activation_hook( __FILE__, function () {
    if ( class_exists( \Amplifi\Schema\Installer::class ) )   { \Amplifi\Schema\Installer::install(); }
    if ( class_exists( \Amplifi\Security\Installer::class ) ) { \Amplifi\Security\Installer::install(); }
    if ( class_exists( 'Amplifi_Consent_Log' ) )              { \Amplifi_Consent_Log::install(); }
    // ... etc
    flush_rewrite_rules();
} );
```

This has a practical consequence: **enabling a feature from the hub does not run
its installer**, because the activation hook already fired. Features that need DB
tables handle this themselves by checking a stored DB-version option on load and
running their upgrade routine on a version bump. If you enable a feature and its
tables are missing, deactivate and reactivate the plugin to force the installer.

## Data model conventions

- **Feature toggles** live in one option: `amplifi_plugins_enabled_features`.
- **Per-feature settings** live in their own namespaced options.
- **Tables** are prefixed per feature, for example `wp_acconsent_log`,
  `wp_ac_faqs`, `wp_ac_schema_*`, `wp_amplifi_security_*`.
- **API keys** are stored per feature. AI features enforce per-day and per-month
  spend caps.

`uninstall.php` at the plugin root handles suite-level cleanup; several features
carry their own `uninstall.php` for feature-scoped data.

## Site-specific overrides

Features intentionally ship opinionated defaults with `!important` on some
front-end positioning. When a specific site needs different behaviour, the
established pattern is a small mu-plugin that injects an override from a late
`wp_head` hook, rather than editing the feature:

```php
add_action( 'wp_head', function () {
    echo '<style id="my-override">html body .acconsent-fab{left:66px !important;right:auto !important;}</style>';
}, 100 );
```

This keeps the plugin upgradeable. Deleting the mu-plugin fully reverts the
override. Never patch a feature in place on a live site: the next auto-update
overwrites it silently.

## See also

- [Feature reference](features/) for per-feature architecture
- [Releasing](releasing.md) for the build and publish process
- [Installation](installation.md) for install and enable steps
