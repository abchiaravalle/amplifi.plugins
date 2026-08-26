# amplifi.security

Scans a WordPress install locally with nine scanner modules on WP-Cron, queues every finding as `pending_triage`, then ships batches to Claude (the site owner's own Anthropic key) for a structured verdict. Alerts only fire for verdicts the routing matrix maps to a live channel — in practice `confirmed` and `likely` — and everything else is journaled into an HMAC hash-chained audit log. The feature also defends itself: it baselines its own files, HMAC-stamps its critical options, re-arms its own cron, exposes a per-site canary URL for external uptime monitors, and can optionally hide from non-installer admins.

## At a glance

| Item | Value |
|------|-------|
| Feature slug | `security` (in `wp_option` `amplifi_plugins_enabled_features`) |
| Source path | `plugins/amplifi-plugins/features/security/` |
| Entry file | `amplifi-security.php` |
| Namespace | `Amplifi\Security\*` |
| Version constants | `AMPLIFI_SECURITY_VERSION` = `3.3.7`, `AMPLIFI_SECURITY_DB_VERSION` = `1` |
| Other constants | `AMPLIFI_SECURITY_FILE`, `_PATH`, `_URL`, `_BASENAME`, `_SLUG` (`amplifi-security`), `_MIN_PHP` (`8.1`), `_MIN_WP` (`6.4`) |
| DB tables | Nine, all `{prefix}amplifi_security_*` |
| REST namespace | **None** — no `register_rest_route` calls anywhere in the feature |
| Public endpoints | `?amplifi_canary=<slug>` and `?amplifi_unhide=<token>`, both served off `parse_request` |
| Admin pages | 4 submenus under `amplifi-studio`, Settings split into 8 tabs |
| WP-CLI | `wp amplifi-security scan|findings|verify|canary|stealth` |
| PHP LOC | ~9,287 across 57 PHP files (64 files total). Excluding the bundled framework copy: 8,696 LOC |
| AI vendor | Anthropic Claude, `POST https://api.anthropic.com/v1/messages` |
| Text domain | `amplifi-security` |

### Load path

Loaded by `amplifi-plugins.php` only when `'security'` appears in `amplifi_plugins_enabled_features`. Like the schema feature, the entry file omits `declare(strict_types=1)` so it parses on PHP < 8.1 and can render an `admin_notices` message via `amplifi_security_render_blocking_notice()`. Gates: PHP ≥ 8.1, WP ≥ 6.4, OpenSSL loaded. A pre-defined `AMPLIFI_SECURITY_VERSION` short-circuits the whole file.

After the gates: shared framework → `Autoloader::register()` → explicit `require_once` of `class-activator.php` and `class-deactivator.php` (they must exist before `register_activation_hook`) → `plugins_loaded` → `Plugin::instance()->init()`.

`Plugin::init()` is idempotent (guarded by `$initialized`) and wires:

```php
I18n::register();  Installer::maybe_upgrade();
add_action( 'deactivate_plugin', [ Deactivator::class, 'on_pre_deactivate' ], 1, 2 );
Canary::register();  Stealth_Mode::register();  Tamper_Detector::register();
Scan_Runner::register();  Vuln_Feed::register();  Alert_Router::register();
Login_Honeypot::register();
Auth_Scanner::register_hooks();  Db_Anomaly_Scanner::register_hooks();  Integrity_Scanner::register_hooks();
// wp_login → AbuseIPDB_Client::note_admin_ip()
// amplifi_security_audit_prune → Audit_Logger::prune()
if ( is_admin() ) { Admin::register(); }
if ( defined( 'WP_CLI' ) && WP_CLI ) { Cli_Commands::register(); }
do_action( 'amplifi_security_loaded' );
```

`Installer::maybe_upgrade()` runs on every page load, so file-copy upgrades that skip the activation hook still reach `dbDelta`.

## Architecture

| File | Class | Responsibility |
|------|-------|----------------|
| `includes/class-autoloader.php` | `Autoloader` | `Amplifi\Security\` → `includes/`, `class-`/`interface-` filename resolution |
| `includes/class-plugin.php` | `Plugin` | Singleton bootstrap, subsystem registration |
| `includes/class-installer.php` | `Installer` | `TABLES` const + `dbDelta` source of truth; `maybe_upgrade()`, `drop_all()` |
| `includes/class-activator.php` | `Activator` | Re-checks PHP, installs schema, seeds options and the default routing matrix, records the self-integrity baseline, schedules four cron events, audits the activation |
| `includes/class-deactivator.php` | `Deactivator` | `on_pre_deactivate` synchronous alert @ priority 1; `deactivate()` clears five cron hooks |
| `includes/class-i18n.php` | `I18n` | `load_plugin_textdomain` from `<plugin>/languages` |
| `includes/scanners/interface-scanner.php` | `Scanners\Scanner` | `name()`, `enabled()`, `run( int $scan_id ): array` |
| `includes/scanners/class-scan-runner.php` | `Scanners\Scan_Runner` | Registers the custom cron intervals, orchestrates a run, persists findings, calls triage then alerting, closes the `scans` row |
| `includes/scanners/class-*-scanner.php` | 9 scanners | See the scanner table below |
| `includes/signatures/class-signature-engine.php` | `Signatures\Signature_Engine` | Pure-PHP rule evaluator (PCRE, `token_get_all`, Shannon entropy) — no YARA dependency, no `eval` |
| `includes/signatures/signatures.php` | — | 25 rule definitions returned as a plain array; keys `id`, `name`, `match`/`tokens`/`entropy_min`, `weight`, `category` |
| `includes/triage/class-triage-engine.php` | `Triage\Triage_Engine` | Cache pass → honeypot pass → batching → dispatch → verdict write-back → naive fallback |
| `includes/triage/class-prompt-builder.php` | `Triage\Prompt_Builder` | `CATEGORIES`, `VERDICTS`, `system_prompt()`, `user_message()`, `tool_schema()`, `detect_prompt_injection()` |
| `includes/triage/class-anthropic-client.php` | `Triage\Anthropic_Client` | Messages API call, retry/backoff, key storage, `ping()`, `estimate_cost()` |
| `includes/triage/class-spend-tracker.php` | `Triage\Spend_Tracker` | Daily buckets, `check_caps()`, `projected_month_end()`, `summary()` |
| `includes/triage/class-verdict-cache.php` | `Triage\Verdict_Cache` | 7-day cache of `benign` verdicts keyed on a canonical evidence hash |
| `includes/alerts/class-alert-router.php` | `Alerts\Alert_Router` | (category × verdict) → channel, hardcoded floors, quiet hours, per-category recipients, daily digest |
| `includes/alerts/class-smtp2go-client.php` | `Alerts\Smtp2Go_Client` | `POST https://api.smtp2go.com/v3/email/send`, falls back to `wp_mail` on any failure |
| `includes/alerts/class-textbelt-client.php` | `Alerts\Textbelt_Client` | `POST https://textbelt.com/text`, hard 3/day cap |
| `includes/audit/class-audit-logger.php` | `Audit\Audit_Logger` | `log()`, `prune()`, `client_ip()`, `client_ua()`; redacts secret-looking keys |
| `includes/audit/class-audit-chain.php` | `Audit\Audit_Chain` | `compute_hash()`, `verify()`; HKDF info `amplifi-security:audit-chain:v1` |
| `includes/canary/class-canary.php` | `Canary\Canary` | Heartbeat endpoint, `current_state()`, `sign()`, `url()`, `rotate_slug()` |
| `includes/honeypot/class-login-honeypot.php` | `Honeypot\Login_Honeypot` | Four fake admin paths; a hit writes `honeypot_hit` to `auth_log` and returns 404 |
| `includes/stealth/class-stealth-mode.php` | `Stealth\Stealth_Mode` | Visibility filters + unhide-token flow |
| `includes/self-defense/class-self-integrity.php` | `Self_Defense\Self_Integrity` | Per-file SHA-256 map of the plugin's own files + a root hash |
| `includes/self-defense/class-tamper-detector.php` | `Self_Defense\Tamper_Detector` | Protected-option HMAC, cron re-arm, liveness check |
| `includes/crypto/class-secret-store.php` | `Crypto\Secret_Store` | AES-256-GCM `encrypt`/`decrypt`/`try_decrypt`/`mask` |
| `includes/data/class-vuln-feed.php` | `Data\Vuln_Feed` | Wordfence Intelligence v3 sync + lookup |
| `includes/data/class-abuseipdb-client.php` | `Data\AbuseIPDB_Client` | AbuseIPDB v2 check with 6-hour caching and admin-IP allowlisting |
| `includes/data/class-geoip.php` | `Data\GeoIP` | Country lookup; MaxMind reader if present, else `HTTP_CF_IPCOUNTRY` |
| `includes/log-sources/class-log-fetcher.php` | `Log_Sources\Log_Fetcher` | Pulls up to 5 raw log URLs with byte caps and auto-disable |
| `includes/cli/class-cli-commands.php` | `Cli\Cli_Commands` | `WP_CLI::add_command( 'amplifi-security', … )` |
| `includes/admin/class-admin.php` | `Admin\Admin` | Framework registration, submenus, assets, notices, hub-catalog stealth filter |
| `includes/admin/class-health-page.php` | `Admin\Health_Page` | Status pill, spend, chain verification |
| `includes/admin/class-findings-page.php` | `Admin\Findings_Page` | Filterable paginated list (30/page) + `mark_fp`/`dismiss` |
| `includes/admin/class-audit-page.php` | `Admin\Audit_Page` | Paginated audit log (50/page) + chain status + export |
| `includes/admin/class-settings-page.php` | `Admin\Settings_Page` | 8 tabs, `admin-post.php` handlers, connection tests, rotations |
| `includes/admin/class-onboarding-wizard.php` | `Admin\Onboarding_Wizard` | 4-step wizard at `?wizard=1` |
| `includes/admin/views/settings-*.php` | — | One view per tab |
| `assets/admin.css`, `assets/admin.js` | — | Enqueued with a `jquery` dependency and `window.amplifiSecurity = { ajaxUrl, nonce }` |

Docs shipped in-tree: `README.md`, `readme.txt` (WP.org format, `Stable tag: 0.1.0`), `SECURITY.md`, `INTEGRITY.md` (release-time hash manifest; a placeholder in dev checkouts), `THIRD_PARTY_NOTICES.md`.

### Scanners

`Scan_Runner::collect_scanners()` instantiates all nine in this order and passes the list through the `amplifi_security_scanners` filter.

| Scanner | `name()` | Default enabled | What it does |
|---------|----------|-----------------|--------------|
| `Shell_Scanner` | `shell` | yes | Walks `wp-content/`, `wp-includes/`, `wp-admin/`, and the WP root, hashes every PHP file, runs `Signature_Engine`. Any PHP under `wp-content/uploads/**` is flagged regardless of signature match. Skips files > 4 MB (`MAX_FILE_SIZE`), caps at 8,000 files per run, excludes the plugin's own files and user globs |
| `Integrity_Scanner` | `integrity` | yes | Core hashes from `api.wordpress.org/core/checksums/1.0/` (cached 1 day in a transient); plugin/theme baselines captured at install and refreshed on `upgrader_process_complete` / `activated_plugin`. Diffs against `baseline` |
| `Critical_File_Scanner` | `critical_file` | yes | `.htaccess`, `wp-config.php`, `wp-content/mu-plugins/*.php`, and WP dropins. `wp-config.php` diffs have lines matching `SECRET_LINE_RE` (`AUTH_KEY`, `DB_PASSWORD`, salts, …) stripped before anything leaves the server. State in `amplifi_security_critical_file_state` |
| `Db_Anomaly_Scanner` | `db_anomaly` | yes | Real-time hooks (`user_register`, `set_user_role`, `after_password_reset`, `wp_create_application_password`, `granted_super_admin`, `activated_plugin`, `switch_theme`, plus `update_option_*`/`add_option_*` on 11 high-value options) write to the audit log; periodic pass surfaces patterns |
| `Auth_Scanner` | `auth` | yes | Hooks `wp_login`, `wp_login_failed`, `wp_logout` into `auth_log`; periodic pass finds brute force, distributed brute force, first-seen IP/country for admin logins, off-hours logins (after the `amplifi_security_learning_until` 14-day baseline), reset-then-login-elsewhere, and high AbuseIPDB confidence |
| `Vuln_Scanner` | `vuln` | yes | Pure DB read against the cached Wordfence feed, cross-referenced by plugin/theme slug + version |
| `Cron_Scanner` | `cron` | yes | Enumerates `_get_cron_array()`; flags hooks with no registered callback, closures persisted in the DB, and unattributed recurrences under `HIGH_FREQ_THRESHOLD = 600` seconds |
| `Rest_Xmlrpc_Scanner` | `rest_xmlrpc` | yes | 5+ application passwords in 24 h, user-enumeration patterns, app passwords used from unusual IPs |
| `Hardening_Scanner` | `hardening` | **implicitly** | Default `admin` user, `WP_DEBUG_DISPLAY`, non-HTTPS `siteurl`, EOL PHP, stale core minor, default `wp_` prefix, exposed `.bak`/`.old`/`.sql`/`.zip`, world-writable root or `wp-content/` |

The eight named scanners check `in_array( <name>, $settings['enabled_scanners'] )`. `Hardening_Scanner` inverts the test — `! in_array( 'hardening', $settings['disabled_scanners'] ?? [] )` — so it is always on and nothing in the UI writes `disabled_scanners`.

### One scan run

`Scan_Runner::run()`:

1. `INSERT` a `scans` row, capture `$scan_id`, log `scan_started`.
2. For each enabled scanner: `run( $scan_id )` inside a `try`/`catch` — a throwing scanner logs `scanner_error` and is skipped, it does not abort the run. Results are persisted by the runner (scanners never write to the findings table themselves) as `status = 'pending_triage'`.
3. `Self_Integrity::verify()` runs unconditionally; a failure becomes a `self_integrity` / `plugin_files_modified` finding.
4. `Triage_Engine::triage_pending( $scan_id )` in a `try`/`catch`; a throw sets `$triage_ok = false`.
5. `Alert_Router::route_findings_for_scan( $scan_id )` in a `try`/`catch`.
6. `UPDATE` the `scans` row with `completed_at`, `findings_count`, `scanners_run` (JSON of `{name, count, duration}`).
7. `update_option( 'amplifi_security_last_scan_ts', … )` and `amplifi_security_last_triage_ok`, then log `scan_completed`.

### Cron

| Hook | Recurrence | Registered by |
|------|-----------|---------------|
| `amplifi_security_run_scan` | `amplifi_security_four_hours` | `Activator` (+300 s), re-armed by `Tamper_Detector` |
| `amplifi_security_audit_prune` | `daily` | `Activator` (+600 s) |
| `amplifi_security_vuln_feed_refresh` | `daily` | `Activator` (+900 s) |
| `amplifi_security_daily_digest` | `daily` | `Activator` (+1200 s) |
| `amplifi_security_self_integrity` | — | Only ever *cleared*; nothing schedules it |

Custom intervals added via `cron_schedules`: `amplifi_security_two_hours`, `_four_hours`, `_eight_hours`, `_twelve_hours`.

## Admin UI

`Admin::register()` hooks `admin_menu` @ 4 for framework registration (before the framework's own handler at 5) and @ 11 for the extra submenus (after the parent menu exists). Both bail early when `Stealth_Mode::should_hide_for_current_user()` is true. Capability for every page: `manage_options` (`Admin::CAPABILITY`).

| Page | Page slug | Hook suffix | Renderer |
|------|-----------|-------------|----------|
| Security (Health) | `amplifi-security` | `amplifi-studio_page_amplifi-security` | `Health_Page::render()` |
| Security: Findings | `amplifi-security-findings` | `amplifi-studio_page_amplifi-security-findings` | `Findings_Page::render()` |
| Security: Audit Log | `amplifi-security-audit` | `amplifi-studio_page_amplifi-security-audit` | `Audit_Page::render()` |
| Security: Settings | `amplifi-security-settings` | `amplifi-studio_page_amplifi-security-settings` | `Settings_Page::render()` |

The main page slug is `amplifi-security`, not `amplifi-amplifi-security`, because the framework's `amplifi_page_slug()` leaves slugs that already start with `amplifi-` alone.

`enqueue_assets()` uses a substring test — `str_contains( $hook, 'amplifi-security' )` — so all four pages load `assets/admin.css` and `assets/admin.js`.

### Settings tabs

`Settings_Page::TABS` drives both the nav and the view include (`includes/admin/views/settings-<tab>.php`). Default and fallback tab is `connections`.

`connections`, `scanning`, `triage`, `notifications`, `stealth` (labelled "Stealth & Defense"), `findings`, `audit`, `health`.

`admin-post.php` handlers, all nonce-checked:

| Action | Handler |
|--------|---------|
| `amplifi_security_save_settings` | `Settings_Page::handle_save()` — per-tab sanitisation, secrets diverted to their clients |
| `amplifi_security_test_anthropic` | `Anthropic_Client::ping()` |
| `amplifi_security_test_smtp2go` | `Smtp2Go_Client::ping()` |
| `amplifi_security_rotate_canary` | `Canary::rotate_slug()` |
| `amplifi_security_rotate_unhide` | `Stealth_Mode::rotate_unhide_token()`, new token surfaced via a 60-second transient |
| `amplifi_security_toggle_stealth` | `Stealth_Mode::enable()` / `disable()` |
| `amplifi_security_mark_fp` | One-click false-positive from an alert email |
| `amplifi_security_findings_action` | `mark_fp` / `dismiss` from the Findings table |
| `amplifi_security_audit_export` | Audit log export |
| `amplifi_security_complete_onboarding` | Sets `amplifi_security_onboarding_complete` |
| `amplifi_security_run_first_scan` | Schedules a scan in 5 s and completes onboarding |

Secret fields are round-tripped masked; `handle_save()` skips any submitted value containing `•` so re-saving a form does not overwrite a stored key with its own mask.

### Onboarding wizard

Four steps (`TOTAL_STEPS = 4`): API keys → recipients → log sources → first scan. Reachable at `admin.php?page=amplifi-security-settings&wizard=1[&step=N]`. Steps post to `admin-post.php` with `wizard_next_step`, which makes `handle_save()` redirect forward instead of back to the tab. A persistent `admin_notices` prompt appears until `amplifi_security_onboarding_complete` is set.

## Database tables

All created by `Installer::install()` with `dbDelta`; prefix `{$wpdb->prefix}amplifi_security_`.

### `findings`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `scan_id` | `bigint(20) unsigned DEFAULT NULL` |
| `type` | `varchar(40) NOT NULL` |
| `subtype` | `varchar(60) DEFAULT NULL` |
| `category` | `varchar(40) DEFAULT NULL` |
| `category_label` | `varchar(80) DEFAULT NULL` |
| `evidence` | `longtext NOT NULL` |
| `status` | `varchar(20) NOT NULL DEFAULT 'pending_triage'` |
| `verdict` | `varchar(20) DEFAULT NULL` |
| `confidence` | `decimal(3,2) DEFAULT NULL` |
| `rationale` | `text DEFAULT NULL` |
| `recommended_action` | `text DEFAULT NULL` |
| `user_marked_fp` | `tinyint(1) NOT NULL DEFAULT 0` |
| `triaged_at` | `datetime DEFAULT NULL` |
| `created_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (id)`, `KEY status_created (status, created_at)`, `KEY category_verdict (category, verdict)`, `KEY scan_id (scan_id)`.

### `baseline`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `path` | `varchar(500) NOT NULL` |
| `hash` | `char(64) NOT NULL` |
| `source` | `varchar(20) NOT NULL` |
| `source_version` | `varchar(40) DEFAULT NULL` |
| `recorded_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY path_unique (path(191))`, `KEY source (source)`.

### `auth_log`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `event` | `varchar(40) NOT NULL` |
| `user_login` | `varchar(60) DEFAULT NULL` |
| `ip` | `varchar(45) DEFAULT NULL` |
| `ua` | `text DEFAULT NULL` |
| `country` | `char(2) DEFAULT NULL` |
| `created_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (id)`, `KEY created_at (created_at)`, `KEY user_login_created (user_login, created_at)`.

### `audit`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `event_type` | `varchar(50) NOT NULL` |
| `actor_user_id` | `bigint(20) unsigned DEFAULT NULL` |
| `actor_ip` | `varchar(45) DEFAULT NULL` |
| `actor_ua` | `text DEFAULT NULL` |
| `target_type` | `varchar(40) DEFAULT NULL` |
| `target_id` | `varchar(120) DEFAULT NULL` |
| `event_data` | `longtext NOT NULL` |
| `prev_hash` | `char(64) DEFAULT NULL` |
| `row_hash` | `char(64) NOT NULL` |
| `created_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (id)`, `KEY event_created (event_type, created_at)`, `KEY actor_created (actor_user_id, created_at)`.

### `scans`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `started_at` | `datetime NOT NULL` |
| `completed_at` | `datetime DEFAULT NULL` |
| `scanners_run` | `longtext DEFAULT NULL` |
| `findings_count` | `int(11) NOT NULL DEFAULT 0` |
| `triage_tokens_in` | `int(11) DEFAULT NULL` |
| `triage_tokens_out` | `int(11) DEFAULT NULL` |
| `triage_cost_usd` | `decimal(10,5) DEFAULT NULL` |

Keys: `PRIMARY KEY (id)`, `KEY started_at (started_at)`.

### `verdict_cache`

| Column | Type |
|--------|------|
| `cache_key` | `char(64) NOT NULL` (PK) |
| `verdict` | `varchar(20) NOT NULL` |
| `rationale` | `text DEFAULT NULL` |
| `expires_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (cache_key)`, `KEY expires_at (expires_at)`.

### `log_sources`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `name` | `varchar(60) NOT NULL` |
| `url` | `varchar(500) NOT NULL` |
| `auth_type` | `varchar(20) NOT NULL DEFAULT 'none'` (`none`/`basic`/`bearer`/`custom_header`) |
| `auth_secret` | `text DEFAULT NULL` (`Secret_Store` ciphertext) |
| `max_bytes` | `int(11) NOT NULL DEFAULT 2097152` |
| `last_fetch_at` | `datetime DEFAULT NULL` |
| `last_status` | `varchar(20) DEFAULT NULL` |
| `consecutive_failures` | `int(11) NOT NULL DEFAULT 0` |
| `enabled` | `tinyint(1) NOT NULL DEFAULT 1` |

Keys: `PRIMARY KEY (id)`, `KEY enabled (enabled)`.

### `vuln_feed`

| Column | Type |
|--------|------|
| `vuln_id` | `varchar(80) NOT NULL` (PK) |
| `component_slug` | `varchar(120) NOT NULL` |
| `component_type` | `varchar(20) NOT NULL` |
| `affected_versions` | `varchar(100) DEFAULT NULL` |
| `fixed_in` | `varchar(40) DEFAULT NULL` |
| `cvss` | `decimal(3,1) DEFAULT NULL` |
| `cves` | `longtext DEFAULT NULL` |
| `exploit_observed` | `tinyint(1) NOT NULL DEFAULT 0` |
| `raw_record` | `longtext NOT NULL` |
| `updated_at` | `datetime NOT NULL` |

Keys: `PRIMARY KEY (vuln_id)`, `KEY component (component_slug, component_type)`.

### `spend`

| Column | Type |
|--------|------|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `date` | `date NOT NULL` |
| `tokens_in` | `bigint(20) unsigned NOT NULL DEFAULT 0` |
| `tokens_out` | `bigint(20) unsigned NOT NULL DEFAULT 0` |
| `cost_usd` | `decimal(10,5) NOT NULL DEFAULT 0` |
| `triage_calls` | `int(11) NOT NULL DEFAULT 0` |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY date_unique (date)`.

## Options, transients, and post meta

**No post meta.** The feature does not read or write any.

### `wp_options`

| Key | Contents |
|-----|----------|
| `amplifi_security_settings` | **JSON string** (not an array) — see below |
| `amplifi_security_db_version` | `'1'` |
| `amplifi_security_installer_id` | User ID of the first activator; `add_option` so it never changes |
| `amplifi_security_canary_slug` | 32-char random; canary query value |
| `amplifi_security_canary_secret` | 64 hex chars; HMAC key for the canary signature and the stealth session cookie |
| `amplifi_security_unhide_token` | 48-char random |
| `amplifi_security_stealth_enabled` | `0`/`1` |
| `amplifi_security_preserve_data_on_uninstall` | `0`/`1` |
| `amplifi_security_first_activation` | UTC MySQL datetime |
| `amplifi_security_learning_until` | UTC MySQL datetime, activation + 14 days |
| `amplifi_security_onboarding_complete` | `1` once the wizard finishes |
| `amplifi_security_last_scan_ts` | Unix timestamp |
| `amplifi_security_last_triage_ok` | `0`/`1` |
| `amplifi_security_last_scan_summary` | Claude's `scan_summary` string |
| `amplifi_security_last_triage_payload` | `{when, system, user, model}` for the Settings → Triage debug viewer |
| `amplifi_security_triage_failures` | Consecutive-failure counter |
| `amplifi_security_audit_chain_head` | Latest `row_hash` mirror |
| `amplifi_security_self_baseline` | `path => sha256` map of the plugin's own files |
| `amplifi_security_self_baseline_hash` | Root hash of that map |
| `amplifi_security_self_integrity_ok` | `'0'`/`'1'` |
| `amplifi_security_critical_file_state` | Last-seen state for the critical-file scanner |
| `amplifi_security_sms_counter` | `{ 'YYYY-MM-DD' => int }`, pruned to today on write |
| `amplifi_security_vuln_feed_last_sync` | Timestamp |
| `amplifi_security_fallback_key` | Only when the whole `AUTH_KEY` family is undefined |
| `amplifi_security_anthropic_key` | Ciphertext |
| `amplifi_security_smtp2go_key` | Ciphertext |
| `amplifi_security_smtp2go_sender` | Plaintext email |
| `amplifi_security_abuseipdb_key` | Ciphertext |
| `amplifi_security_textbelt_key` | Ciphertext |
| `amplifi_security_textbelt_phone` | Plaintext |
| `amplifi_security_wordfence_token` | Ciphertext |
| `<protected_option>__hmac` | Tamper stamp for each of the six protected options |

Every secret option is written with `autoload = false`.

`amplifi_security_settings` (JSON, defaults from `Activator::default_settings()`):

| Field | Default |
|-------|---------|
| `scan_interval` | `four_hours` |
| `enabled_scanners` | `['shell','integrity','critical_file','db_anomaly','auth','vuln','cron','rest_xmlrpc']` |
| `file_exclusions` | `['wp-content/cache/*','wp-content/uploads/cache/*','wp-content/backup*']` |
| `ip_allowlist` | `[]` |
| `model` | `claude-haiku-4-5-20251001` |
| `sensitivity` | `balanced` (`conservative`/`balanced`/`aggressive`) |
| `daily_spend_cap_usd` | `2.0` |
| `monthly_spend_cap_usd` | `30.0` |
| `digest_hour_utc` | `13` |
| `sms_quota_per_day` | `3` |
| `quiet_hours` | `{ enabled: false, start: 22, end: 7 }` |
| `audit_retention_days` | `90` (clamped 7–365 at prune time) |
| `redact_log_query_strings` | `false` |
| `routing_matrix` | See "Routing" |
| `notification_recipients` | *not seeded* — written by the notifications tab; falls back to `admin_email` |
| `recipients_by_category` | *not seeded* — optional per-category override read by `Alert_Router` |

`register_setting( 'amplifi_security_settings_group', 'amplifi_security_settings' )` declares it `type => string`, `show_in_rest => false`.

### Transients

| Key | TTL |
|-----|-----|
| `amplifi_security_unhide_token_display` | 60 s |
| `amplifi_security_admin_ip_allowlist` | 7 days |
| `amplifi_security_geoip_*` (object cache, not transient) | 1 hour |
| `amplifi_security_abuseipdb_*` | 6 hours (`AbuseIPDB_Client::CACHE_TTL_SECS`) |
| WP core checksums (Integrity_Scanner) | 1 day |

## Endpoints

There is no REST controller. Two front-end entry points are served directly:

| Endpoint | Hook | Behaviour |
|----------|------|-----------|
| `?amplifi_canary=<slug>` | `muplugins_loaded` @ 1 and `parse_request` @ 1 | `hash_equals` against `amplifi_security_canary_slug`. Wrong slug → generic `404 not found` plaintext, so the canary's existence is not leaked. Correct slug → 200 `text/plain` with `X-Amplifi-Status: alive|degraded`, `X-Robots-Tag: noindex, nofollow`, no-store headers, and a body of `ts`, `last_scan`, `last_triage_ok`, `findings_open_confirmed`, `self_integrity_ok`, `sig` |
| `?amplifi_unhide=<token>` | `init` @ 1 | `hash_equals` against `amplifi_security_unhide_token`; on match sets an 8-hour HttpOnly `SameSite=Lax` cookie carrying `hash_hmac('sha256','stealth-session', canary_secret)`. The token is **not** rotated automatically |
| Honeypot paths | `parse_request` @ 5 | `wp-login-secure.php`, `wp-admin-original.php`, `wp-config-old.php`, `admin-ajax-old.php`. A hit inserts `honeypot_hit` into `auth_log`, logs the audit event, and returns a bare 404 |

`sig` = `hash_hmac( 'sha256', "{ts}|{last_scan}|{triage_ok?1:0}", canary_secret )`, or the literal `unsigned` when no secret exists.

`triage_ok` is forced false when `now - last_scan_ts > 2 × scan_interval`, so a silently disabled cron surfaces as `degraded` to an external monitor.

## AI usage

**Vendor:** Anthropic Claude only. `Anthropic_Client::ENDPOINT = 'https://api.anthropic.com/v1/messages'`, `API_VERSION = '2023-06-01'`, `wp_remote_post` with `timeout => 60`, `sslverify => true`, and `user-agent: amplifi-security/<version>`.

**Models:** `DEFAULT_MODEL = 'claude-haiku-4-5-20251001'`. `ALLOWED_MODELS = ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6']` — an out-of-list model is silently coerced back to the default both in `call()` and in `Settings_Page::handle_save()`. Pricing (USD per million tokens) is a `match` in `estimate_cost()`: Haiku 4.5 `1.00 / 5.00`, Sonnet 4.6 `3.00 / 15.00`, default branch = Haiku rates.

**Request shape:** `max_tokens: 4096`, `temperature: 0.0`, a single tool named `submit_verdicts` whose `input_schema` comes from `Prompt_Builder::tool_schema()`, and `'tool_choice' => [ 'type' => 'tool', 'name' => 'submit_verdicts' ]`. The response must contain a matching `tool_use` block or `extract_tool_input()` throws.

Each verdict object requires `finding_id`, `category` (enum of the 10 categories), `verdict` (enum of the 4), `confidence` (0–1), `rationale`, `recommended_first_action`, `evidence_cited[]`; `category_label` is `string|null`. Top level also carries `scan_summary`.

**Retry policy:** transport errors retry twice with a 500 ms sleep; `429`/`529` sleep `max(1, min(30, Retry-After))` and retry up to 3 attempts total; `401`/`403` log `anthropic_auth_error` and throw immediately; any other non-2xx throws with the first 500 bytes of the body.

**Key storage:** `Secret_Store` — AES-256-GCM with a 12-byte IV and 16-byte tag, `v1:` prefix, key from `hash_hkdf('sha256', AUTH_KEY.SECURE_AUTH_KEY.AUTH_SALT.SECURE_AUTH_SALT, 32, 'amplifi-security:secret-store:v1')`. Stored in `amplifi_security_anthropic_key` with `autoload = false`, and that option is one of the six under `Tamper_Detector::PROTECTED_OPTIONS`. `Secret_Store::mask()` renders only the last 4 characters in the UI, and `handle_save()` refuses any submitted value containing `•`. `Audit_Logger` scrubs keys matching `/api[_-]?key/i` and `/secret/i` before journaling. `Anthropic_Client::ping()` validates a key with a 1-token `max_tokens: 1` call.

**Spend caps:** `daily_spend_cap_usd` default `2.0`, `monthly_spend_cap_usd` default `30.0`. `Spend_Tracker::check_caps( $emergency )` is called before every batch:

- `$emergency === true` returns allowed unconditionally — no cap applies.
- Otherwise `today >= daily` returns `daily_cap_reached` and logs `triage_paused_daily_cap`; `month >= monthly` returns `monthly_cap_reached` and logs `triage_paused_monthly_cap`.
- A cap of `0` disables that ceiling (the check is `$daily > 0 && …`).

`Triage_Engine::has_emergency()` sets the emergency flag when any finding in the batch has `type === 'shell_in_uploads'` or `evidence.combined_score >= 10`. When capped, findings stay `pending_triage` and are re-attempted on the next run. `Spend_Tracker::summary()` also reports a linear `projected_month_end`.

**Data sent:** trusted site context (WP/PHP version, URLs, multisite flag, WooCommerce presence, first 60 active plugins with versions, active theme, admin count), the batch's findings, and up to `MAX_PAYLOAD_BYTES / 4` = 25,000 bytes of raw log text. `wp-config.php` contents are never sent — only a redacted diff. `amplifi_security_last_triage_payload` stores the exact system and user messages for the Settings → Triage debug viewer.

## Triage

`Triage_Engine::triage_pending( ?int $scan_id )`:

1. **Load** up to `MAX_BATCH * 4` = 200 `pending_triage` rows, `ORDER BY id ASC`.
2. **Cache pass** — `Verdict_Cache::key_for( type, evidence )` hashes a canonical blob of `type`, `path`, `sha256`/`file_hash`, sorted signal IDs, and `subtype`. A live cache hit with verdict `benign` short-circuits to a `benign` verdict at confidence 0.9 with rationale `cached: …`. Because the file hash is part of the key, a modified file always misses.
3. **Honeypot pass** — `Prompt_Builder::detect_prompt_injection()` runs six regexes (`ignore (all) previous instructions`, `disregard … instructions`, `you are now a/an`, `system: you must/will/are`, `new instructions:`, `respond with "benign"`) over the JSON-encoded evidence. A hit is **never sent to the model**: the finding is written as `other` / `prompt_injection_attempt` / `confirmed` at confidence 0.99.
4. **Batch** — `MAX_BATCH = 50` findings or `MAX_PAYLOAD_BYTES = 100_000` bytes of evidence, whichever comes first.
5. **Context once per run** — since `f942cfa`, `site_context()` and `Log_Fetcher::fetch_all()` are computed once and reused across all batches instead of per batch.
6. **Per batch** — cap check → `dispatch_batch()` → on success `reset_failure_count()`; on throw log `triage_call_failed` and `increment_failure_count()`, and once the counter reaches `FAILURE_THRESHOLD = 3` run `naive_fallback()` on that batch.
7. **Write-back** — verdicts are keyed by `finding_id`; a finding the model omitted stays `pending_triage`. `apply_verdict()` coerces an unknown `category` to `other` and an unknown `verdict` to `worth_reviewing`, then sets `status = 'triaged'` and `triaged_at`. Every `benign` verdict is written to the cache with a 7-day TTL.

**Naive mode** (`naive_fallback`, after 3 consecutive failures): `shell_in_uploads`, any evidence match with `category === 'shell'`, or `combined_score >= 10` → `confirmed` / `malware`; `file_integrity` with `context === 'wp_core_file'` → `likely` / `core_tampering`; everything else → `worth_reviewing` / `other`. All at confidence 0.6 with `evidence_cited: ['naive_mode']`. Logged as `triage_naive_fallback`.

**Prompt-injection framing:** all evidence is wrapped in `<UNTRUSTED_EVIDENCE>` tags, and `sanitize_for_delimiters()` neutralises any literal `</UNTRUSTED_EVIDENCE>` the attacker embedded. The system prompt instructs the model to treat an injection attempt as itself a `confirmed` finding.

**Sensitivity** shifts one paragraph of the system prompt: `conservative` biases down a rung, `aggressive` permits `confirmed` without hedging, `balanced` is the default calibration text.

## Alert routing

`Alert_Router::route_findings_for_scan()` reads every `triaged` row with a non-null verdict for the scan and resolves `(category × verdict)` through `settings.routing_matrix`, falling back to `digest` when the pair is unmapped.

Channels: `email_sms`, `email`, `digest`, `log`, `mute`.

**Hardcoded floor:** if `category === 'malware'` and `verdict === 'confirmed'` resolves to `mute`, `log`, `digest`, or nothing, it is forced to `email_sms`. This cannot be configured away.

**Verdict gating** — this is where the `confirmed`/`likely` promise is enforced, and it is a property of the default matrix rather than a hardcoded rule:

| Category | confirmed | likely | worth_reviewing | benign |
|----------|-----------|--------|-----------------|--------|
| `malware` | `email_sms` | `email` | `digest` | `log` |
| `core_tampering` | `email_sms` | `email` | `digest` | `log` |
| `privilege_escalation` | `email_sms` | `email` | `digest` | `log` |
| `content_injection` | `email` | `email` | `digest` | `log` |
| `plugin_theme_tampering` | `email` | `digest` | `digest` | `log` |
| `auth_anomaly` | `email` | `digest` | `digest` | `log` |
| `vulnerability` | `email` | `digest` | `digest` | `log` |
| `cron_anomaly` | `email` | `digest` | `digest` | `log` |
| `config_change` | `email` | `email` | `digest` | `log` |
| `other` | `email` | `email` | `digest` | `log` |

`mute` and `log` return without sending; `digest` defers to the daily cron. Quiet hours (UTC, wrap-around aware) suppress everything except `confirmed`. SMS only fires when the channel is `email_sms` **and** the verdict is `confirmed`, carrying the first sentence of the rationale.

Recipients: `settings.recipients_by_category[<category>]` if present, else `settings.notification_recipients`, else `admin_email`. Email goes through SMTP2Go's v3 REST endpoint and falls back to `wp_mail()` on a missing key, a transport error, or any non-2xx. Every alert email carries a nonce-signed one-click "Mark as false positive" link.

SMS is capped at `min( sms_quota_per_day, HARD_DAILY_CAP = 3 )` per UTC day, counted in `amplifi_security_sms_counter`; the counter only increments on a successful send, and blocked sends log `sms_blocked_daily_cap`.

**Daily digest** covers the last 24 hours of non-benign findings, grouped by verdict, and is skipped entirely when there is nothing to report.

**Pre-deactivation alert:** `Deactivator::on_pre_deactivate` runs on `deactivate_plugin` @ 1, before the active-plugins option is rewritten. It audits the attempt and dispatches synchronously as `core_tampering` / `confirmed`; `dispatch_sync()` upgrades a `mute`/`log` resolution to `email` so this alert can never be silenced.

## Audit log

`Audit_Logger::log( $event_type, $data )` seals each row with `Audit_Chain::compute_hash( $prev_hash, $row )` = HMAC-SHA256 over `prev_hash \x1f event_type \x1f actor_user_id \x1f actor_ip \x1f target_type \x1f …`, and mirrors the newest hash into `amplifi_security_audit_chain_head`. Divergence between the table's last row and the option is itself alarming.

`Audit_Chain::verify( $limit )` returns `{ verified, scanned, broken_at[] }`. The Health page verifies the last 500 rows, the Audit page 1,000, and `wp amplifi-security verify` the full chain. Retention is `audit_retention_days` (default 90, clamped 7–365) pruned daily.

## Self-defense

- **`Self_Integrity`** — `record_baseline()` at activation walks the plugin directory, stores a `path => sha256` map in `amplifi_security_self_baseline` plus a root hash in `amplifi_security_self_baseline_hash`. `verify()` runs on every scan and returns `{ok, changed[], added[], removed[]}`; a mismatch becomes a `self_integrity` finding and flips `amplifi_security_self_integrity_ok` to `0`, which the canary reports. Missing baseline silently records a fresh one and reports OK.
- **`Tamper_Detector`** — HMAC-stamps six protected options (`canary_slug`, `canary_secret`, `installer_id`, `unhide_token`, `self_baseline_hash`, `settings`) under key `<option>__hmac`, using a separate HKDF context `amplifi-security:option-hmac:v1`. On `init` @ 5 it re-arms any of the four cron hooks that vanished without going through `wp_clear_scheduled_hook()`, and runs a liveness check that flags an unhealthy state when `last_scan_ts` is older than 2× the configured interval.
- **`Canary`** — the external half of the same idea, described above.

## Stealth Mode

Default **off** (`amplifi_security_stealth_enabled = 0`, seeded via `add_option`). Opt-in only, and disclosed in `readme.txt`.

`should_hide_for_current_user()` returns false when stealth is disabled, when the current user ID equals `installer_id()` (which prefers a `AMPLIFI_SECURITY_INSTALLER_ID` constant in `wp-config.php` over the stored option), or when the session cookie matches the derived stealth session token. Otherwise true.

When enabled, `register()` attaches:

| Filter | Effect |
|--------|--------|
| `all_plugins` | Removes the row from the Plugins list |
| `plugin_action_links_<basename>` | Returns an empty array |
| `site_transient_update_plugins` / `transient_update_plugins` | Unsets both `response` and `no_update` entries so no update row appears |

Independently, `Admin` bails out of both `admin_menu` handlers (hiding all four submenus), suppresses its `admin_notices`, and filters the plugin out of the framework's hub via `amplifi_hub_catalog`. The `amplifi-studio` top-level menu itself remains — it belongs to the framework, not this feature.

**Recovery paths:** define `AMPLIFI_SECURITY_INSTALLER_ID` in `wp-config.php`, log in as the original installer, visit `?amplifi_unhide=<token>` once, or run `wp amplifi-security stealth off`.

Stealth does not hide files on disk, DB tables, or outbound traffic; the class docblock says so explicitly.

## WP-CLI

`WP_CLI::add_command( 'amplifi-security', Cli_Commands::class )`.

| Command | Options | Notes |
|---------|---------|-------|
| `wp amplifi-security scan` | `[--quiet]` | Runs a full scan synchronously, prints `scan_id` and finding count, and the `scanners_run` JSON unless quiet |
| `wp amplifi-security findings` | `[--limit=<int>]` (default 20, max 200), `[--verdict=<verdict>]` | Table output |
| `wp amplifi-security verify` | — | Full audit-chain verification; warns with the broken row IDs |
| `wp amplifi-security canary` | — | Prints the canary URL; errors if no slug has been generated |
| `wp amplifi-security stealth` | `<on\|off\|status>` | |

## Third-party services

Every outbound call is opt-in and configured by the site owner.

| Service | Endpoint | Required? |
|---------|----------|-----------|
| Anthropic Messages API | `api.anthropic.com/v1/messages` | Yes, for triage |
| SMTP2Go | `api.smtp2go.com/v3/email/send` | No — falls back to `wp_mail` |
| WordPress.org core checksums | `api.wordpress.org/core/checksums/1.0/` | Used by the integrity scanner |
| Wordfence Intelligence v3 | `www.wordfence.com/api/intelligence/v3/vulnerabilities/production` | Optional, free token |
| AbuseIPDB v2 | `api.abuseipdb.com/api/v2/check` | Optional, free key |
| Textbelt | `textbelt.com/text` | Optional, paid key |

Bundled FOSS per `THIRD_PARTY_NOTICES.md`: php-malware-finder rule *logic* (Apache 2.0, ported not copied), Pressidium YARA Rules (MIT), DB-IP Lite geolocation (CC-BY 4.0).

## Uninstall

`plugins/amplifi-plugins/uninstall.php` includes every `features/*/uninstall.php`. The security copy checks `amplifi_security_preserve_data_on_uninstall`; when false it drops all nine tables, `DELETE`s every `amplifi_security_%` option, deletes `_transient_amplifi_security_%` and its timeouts, and clears the five cron hooks.

## Pitfalls

- **`readme.txt` is stale.** It advertises "8 scanner modules" (there are nine registered) and `Stable tag: 0.1.0` against a `AMPLIFI_SECURITY_VERSION` of `3.3.7`. Its installation instructions still describe uploading a standalone `amplifi-security` folder rather than enabling the feature in the amplifi.plugins hub.
- **`Tamper_Detector::stamp()` is never called.** Nothing in the codebase invokes it, so `<option>__hmac` rows are never written and `verify()` always returns true through its "not yet stamped" branch. The option-HMAC layer is inert as shipped.
- **`hardening` cannot be turned off from the UI.** It gates on `disabled_scanners`, a key no form writes; the Scanning tab only edits `enabled_scanners`.
- **The scan interval setting does not reschedule cron.** `scan_interval` is read by `Canary` and `Tamper_Detector` for liveness math, but nothing calls `wp_reschedule_event`/`wp_unschedule_event` after a settings save, and `Tamper_Detector::rearm_cron_if_missing()` hardcodes `amplifi_security_four_hours`. Changing the interval only takes effect after the event is cleared and re-created (deactivate/reactivate).
- **`digest_hour_utc` is decorative.** The digest is on a plain `daily` schedule anchored to activation + 1200 s; no code reads `digest_hour_utc` to place or move the event.
- **The emergency spend override has no ceiling.** `check_caps( true )` returns allowed unconditionally, so a scan producing many `shell_in_uploads` or high-score findings can spend past both caps. The docblock's "a single confirmed-tier finding is always allowed through" is not what the code enforces — the flag is per batch, not per finding.
- **`triage_tokens_in` / `triage_tokens_out` / `triage_cost_usd` on the `scans` table are never populated.** Spend is only recorded in the `spend` day buckets; the per-scan columns stay `NULL`.
- **`GeoIP` has no database to read.** `MMDB_PATH` points at `<plugin>/data/dbip-country-lite.mmdb`, and no `data/` directory exists in the repo. Unless the host provides `\MaxMind\Db\Reader` *and* someone drops the MMDB in, country lookups degrade to the Cloudflare `HTTP_CF_IPCOUNTRY` header or `null`. `readme.txt` and `THIRD_PARTY_NOTICES.md` still credit DB-IP as bundled.
- **The unhide token is single-value and not auto-rotated.** `maybe_consume_unhide_token()` sets the session cookie but deliberately leaves the token in place; anyone who saw the URL once can re-use it until someone hits Rotate in Settings.
- **The stealth session cookie is a deterministic function of the canary secret** (`hash_hmac('sha256','stealth-session', canary_secret)`), so rotating the canary slug does not invalidate stealth sessions but rotating the canary *secret* silently does.
- **`Log_Fetcher` sends raw log text to Anthropic.** Up to 5 sources, 25,000 bytes per triage run, sliced from the tail. `redact_log_query_strings` exists in the settings defaults but no code reads it — query strings in access logs are forwarded verbatim.
- **`Shell_Scanner` caps at 8,000 files per run** with no cursor between runs, so very large installs may never scan their tail. Files over 4 MB are skipped entirely.
- **`Verdict_Cache` only ever caches `benign`.** Non-benign findings re-triage (and re-spend) on every scan until their evidence changes or they are dismissed.
- **Findings the model omits from its response are silently left `pending_triage`** and will be re-sent — and re-billed — on the next run.
- **`git log` for this feature mostly predates the current path.** The substantive commits are `50c15ac feat(amplifi-security): add new security plugin and integrate with shared framework`, `d65d50b feat: create combined amplifi.plugins with all 10 features` (the copy from `plugins/amplifi-security/` into `features/security/`), and `f942cfa perf(security): build site context + logs once per scan instead of per batch`. Everything else touching the path is a version bump or an unrelated consent-feature commit. Use `git log -- plugins/amplifi-plugins/features/security plugins/amplifi-security` to see the full history. `plugins/amplifi-security/` still exists and currently differs from the shipping copy only in `amplifi-security.php` and a `docker-compose.yml` that was not carried over.
