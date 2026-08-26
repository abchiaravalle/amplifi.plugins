# Installation

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer |
| PHP | 8.1 or newer |
| For AI features | An Anthropic (or OpenAI, where noted) API key |

## Install

1. Download `amplifi-plugins-vX.Y.Z.zip` from the
   [latest release](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).
   Download the **zip asset**, not the "Source code" archive — the source archive
   has the repo layout, not the plugin layout, and will not activate.
2. In WordPress admin: **Plugins &rarr; Add Plugin &rarr; Upload Plugin**.
3. Upload the zip and click **Activate**.
4. Open **amplifi.studio** in the admin sidebar.

### Install by WP-CLI

```bash
wp plugin install https://github.com/abchiaravalle/amplifi.plugins/releases/latest/download/amplifi-plugins-v3.3.7.zip --activate
```

Substitute the version you actually want. Verify afterwards:

```bash
wp plugin get amplifi-plugins --format=json
```

## Enable features

Every feature is **off by default**. Activating the plugin loads the framework and
nothing else.

Open **amplifi.studio** in the sidebar and toggle on what you need. Each feature's
own menu item appears immediately underneath.

The toggles write to a single option:

```bash
wp option get amplifi_plugins_enabled_features --format=json
# ["schema","consent"]
```

You can set it directly, which is useful for scripted provisioning:

```bash
wp option update amplifi_plugins_enabled_features '["schema","consent"]' --format=json
```

| Slug | Feature |
|---|---|
| `schema` | Schema |
| `security` | Security |
| `optimize` | Optimize |
| `meta` | Meta |
| `translate` | Translate |
| `alt` | Alt |
| `consent` | Consent |
| `magic` | Magic |
| `cache` | LockCache |
| `pods` | Pods |
| `sync` | Sync |

Note `cache` is the slug; **LockCache** is the display name.

### If a feature's tables are missing

The activation hook installs DB tables, and it only runs on plugin
activation — not when you toggle a feature on later. Features that need tables
generally self-heal by checking a stored DB-version on load, but if a feature
looks broken right after you enable it, force the installer:

```bash
wp plugin deactivate amplifi-plugins && wp plugin activate amplifi-plugins
```

## Upgrading from the old standalone plugins

Older sites may still have separate `ac-schema`, `ac-bulk-meta`,
`ac-wp-translator`, `amplifi-security` and similar plugins installed. Those tools
now live inside amplifi.plugins.

1. Install and activate `amplifi-plugins`.
2. Enable the equivalent features in the hub.
3. Deactivate the old standalone plugins. The plugin shows an admin notice with a
   **Deactivate old plugins** button that does this for you.
4. Confirm settings and data carried over before deleting anything.

The double-load guard means running both at once will not fatal — the second copy
returns early — but you should not leave it that way.

## Auto-updates

Once activated, the plugin checks GitHub for new releases every six hours and
surfaces updates in **Dashboard &rarr; Updates** like any other plugin. The hub
page has a **Check Now** button to clear the cache and re-check immediately.

## API keys

AI-backed features (Schema, Security, Optimize, Translate, Alt, and the AI parts of
Meta) require your own API key, entered on that feature's settings page. Keys are
stored per feature and each feature enforces per-day and per-month spend caps.

Set caps before your first bulk run. A bulk operation across a large media library
or post archive is the expensive case.

## Site-specific styling

Do not edit plugin files on a live site. The next auto-update overwrites them
silently and without warning.

When a site needs different front-end behaviour from a feature default, add a small
mu-plugin that injects an override late:

```php
<?php
// wp-content/mu-plugins/my-consent-override.php
add_action( 'wp_head', function () {
    echo '<style id="my-consent-override">'
       . 'html body .acconsent-fab{left:66px !important;right:auto !important;}'
       . '</style>';
}, 100 );
```

Deleting that one file fully reverts the change.

## Uninstalling

Deactivating stops all features. Deleting the plugin runs `uninstall.php`, which
removes suite-level options; several features clean up their own data too.

**Consent logs are a compliance record.** If you use the Consent feature, export
`wp_acconsent_log` before deleting the plugin — it is your evidence of what each
visitor consented to and when.

## Troubleshooting

**Menu missing after activation.** The framework registers the menu on
`admin_menu` priority 5. Another plugin fataling earlier in the load order can
prevent it. Check the PHP error log.

**A feature's page 404s.** Confirm the slug is in
`amplifi_plugins_enabled_features`. Note the admin page slug is usually **not**
`amplifi-<feature-slug>` — most features register under their legacy plugin slug
(e.g. feature `consent` &rarr; page `amplifi-ac-consent`). See the slug table in
[architecture.md](architecture.md#1-the-admin-menu).

**Front-end output missing.** Check server-side before you trust a browser or a
`curl`. A CDN or WAF in front of the site can serve a stale page or block the
request entirely, which looks identical to broken code:

```bash
wp eval 'ob_start(); do_action("wp_footer"); $o=ob_get_clean(); echo strlen($o) . " bytes, markers: " . substr_count($o,"acconsent");'
```

**Updates not appearing.** Release data is cached for six hours. Use **Check Now**
in the hub, or `wp transient delete amplifi_latest_release`.
