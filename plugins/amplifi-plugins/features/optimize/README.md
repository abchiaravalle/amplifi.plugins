# amplifi.optimize

AI-powered SEO triage for WordPress. Sibling of [amplifi.security](https://amplifi.studio/). Built by [Amplifi Studio](https://amplifi.studio/).

amplifi.optimize scans a WordPress site for fixable SEO issues, uses the Anthropic Claude API to draft fixes, and lets a human approve or reject each change in a review queue. Approved changes commit directly to WordPress.

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────────┐
│  Scanners   │───▶│  Suggestions │───▶│  Claude API │───▶│ Review Queue │
│  (PHP/SQL)  │    │  (custom DB) │    │  (proposals)│    │  (React UI)  │
└─────────────┘     └──────────────┘     └─────────────┘     └──────────────┘
                                                                     │
                                                                     ▼
                                                            ┌──────────────┐
                                                            │  Apply Layer │
                                                            │  (REST/AJAX) │
                                                            └──────────────┘
```

Each fix type has four pluggable components, registered in `Amplifi_Optimize_Plugin::register_fix_types()`:

1. **Scanner** (`includes/scanners/`) — finds candidates via `WP_Query` or SQL and inserts pending rows.
2. **Proposer** (`includes/proposers/`) — sends pending rows to Claude, writes proposed values back.
3. **Applier** (`includes/appliers/`) — commits an approved suggestion and stores a snapshot for undo.
4. **Card** (`assets/src/components/SuggestionCard/`) — React component that renders the diff for that fix type.

## Install

End users: drop the plugin folder into `wp-content/plugins/` (or upload the zip) and activate. The built React bundle ships with the plugin — no Node required.

Then paste an Anthropic API key under **amplifi.optimize → Settings**.

## Developing the React UI

Only needed if you're modifying anything under `assets/src/`.

```bash
npm install
npm run build     # produces assets/build/index.js + index.css + index.asset.php
npm run start     # watch mode
```

`assets/build/` is committed so the plugin remains install-ready. Re-build and commit when you change `assets/src/`.

## WP-CLI

```bash
wp amplifi-optimize scan meta_description --limit=500
wp amplifi-optimize propose meta_description --limit=50
wp amplifi-optimize apply meta_description --auto --limit=200
wp amplifi-optimize report
```

`apply --auto` skips the approval step and applies any pending suggestion that already has a proposed value. Use with care.

## Adding a new fix type

Say you want to add an "H1 missing" fix type. The four steps:

1. **Scanner** — implement `Amplifi_Optimize_Scanner_Interface` in `includes/scanners/class-h1-missing-scanner.php`. Return `fix_type() === 'h1_missing'` and insert pending rows whose `target_type` and `target_id` point at the post.
2. **Proposer** — implement `Amplifi_Optimize_Proposer_Interface` in `includes/proposers/class-h1-missing-proposer.php`. Read pending rows, call `$this->plugin->claude->send_text()` or `send_vision()`, store the proposed value and metadata.
3. **Prompt** — drop a file at `includes/prompts/h1-missing.php` that returns the system prompt string. Keep it strict about JSON output.
4. **Applier** — implement `Amplifi_Optimize_Applier_Interface` in `includes/appliers/class-h1-missing-applier.php`. Return `ok` plus a `snapshot` string for undo.
5. **Register** — add the entry to `register_fix_types()` in `includes/class-plugin.php`. The admin menu, REST routes, dashboard, history, and React tabs all read from that registry — there is nothing else to wire up on the backend.
6. **React card** — drop a `H1MissingCard.jsx` in `assets/src/components/SuggestionCard/` and register it in `SuggestionCard/index.jsx`. The queue picks it up via `fix_type`.

Everything else (REST permissions, queue UX, undo, history filtering, dashboard stats, WP-CLI subcommands) is shared.

## Security

- Permissions: all REST routes require `manage_options`.
- Nonces: handled by WP REST via the localized `wp_rest` nonce.
- SQL: every query goes through `$wpdb->prepare()`.
- API key: encrypted at rest with `openssl_encrypt` keyed off `wp_salt('auth')`; never returned by the REST API.
- Output: WP REST encodes responses; React renders text via children (no `dangerouslySetInnerHTML`).

## License

GPL-2.0-or-later. See `LICENSE`.
