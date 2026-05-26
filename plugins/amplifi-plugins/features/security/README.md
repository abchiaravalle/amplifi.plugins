# amplifi.security

WordPress security with an AI brain. Less noise, more signal.

A WordPress plugin by [Amplifi Studio](https://amplifi.studio) that runs scans locally on your WP server, ships findings to Claude (your own Anthropic API key) for triage, and only alerts you when the model classifies a finding as `confirmed` or `likely`.

## Why

Existing WP security plugins suffer from one of two failure modes:
1. **Alert fatigue.** They flag every changed file, every failed login. Admins mute notifications within a week and miss the alert that actually matters.
2. **Black-box SaaS.** Findings are triaged on a vendor's server with opaque rules, and the customer pays a recurring fee for what amounts to regex matching plus a CVE feed.

amplifi.security's wedge: AI-rubric triage with a fully transparent prompt, your own API key, no SaaS fee, no telemetry.

## Architecture

```
Scanners (WP-Cron, every 4h)
   ↓
Findings queue (DB)
   ↓
Honeypot pre-check + verdict cache lookup
   ↓
Claude triage (single batched call, structured JSON output)
   ↓
Verdict router (category × verdict matrix)
   ↓
SMTP2Go email   |   Textbelt SMS   |   Daily digest   |   Audit log only
```

## Components

| Module | Phase | What it does |
|---|---|---|
| `Secret_Store` | 0 | AES-256-GCM with HKDF-SHA256 key derived from WP `AUTH_KEY` family |
| `Audit_Logger` + `Audit_Chain` | 0 | HMAC hash-chained event journal |
| `Canary` | 1 | Per-site randomized URL that returns plugin health for external uptime monitors |
| `Self_Integrity` | 1 | Hashes plugin's own files at activation, verifies on every scan |
| `Tamper_Detector` | 1 | HMAC stamps on critical config rows + cron re-arm + liveness check |
| `Stealth_Mode` | 1 | Hides plugin from non-installer admins |
| `Signature_Engine` | 2 | Pure-PHP YARA-style rule evaluator (no PECL, no host YARA dependency) |
| `Shell_Scanner` | 2 | Backdoor / shell detection across `wp-content`, `wp-includes`, `wp-admin` |
| `Integrity_Scanner` | 2 | WP core checksum + plugin/theme baseline diff |
| `Critical_File_Scanner` | 2 | `.htaccess`, `wp-config.php`, mu-plugins, dropins (with redacted diffs) |
| `Anthropic_Client` | 3 | Messages API client w/ tool-use forced for strict JSON output |
| `Prompt_Builder` | 3 | System prompt + adversarial framing + delimiter wrapping |
| `Spend_Tracker` | 3 | Per-day USD buckets + cap enforcement |
| `Verdict_Cache` | 3 | 7-day cache of benign verdicts to keep token spend low |
| `Triage_Engine` | 3 | Batches, dispatches, parses verdicts, falls back to naive mode on API failure |
| `Smtp2Go_Client` | 4 | REST email send with `wp_mail` fallback |
| `Textbelt_Client` | 4 | SMS for `confirmed`-only with hardcoded 3/day cap |
| `Alert_Router` | 4 | Category × verdict matrix → channel resolver |
| `Db_Anomaly_Scanner` | 5 | User lifecycle hooks + periodic content-injection / orphan-cap sweep |
| `Auth_Scanner` | 5 | Brute force, distributed brute force, new-geo admin login, hour-of-day pivots |
| `Vuln_Scanner` | 5 | Wordfence Intelligence v3 cross-reference (locally cached) |
| `Cron_Scanner` | 5 | Unregistered scheduled hooks, closures persisted, fake-core-name patterns |
| `Rest_Xmlrpc_Scanner` | 5 | App-password bursts, REST user enumeration check |
| `Hardening_Scanner` | 5 | Config sanity (default `admin` user, `WP_DEBUG_DISPLAY`, EOL PHP, exposed `.bak`/`.sql`) |
| `Login_Honeypot` | 5 | Fake admin paths that flag scanner probes |
| `Log_Fetcher` | 5 | URL-based raw-log retrieval for forensic correlation |
| `GeoIP` | 5 | DB-IP Lite country lookup (CC-BY) |
| `AbuseIPDB_Client` | 5 | Optional IP reputation enrichment, 6h cache |
| `Cli_Commands` | 6 | `wp amplifi-security scan|findings|verify|canary|stealth` |

## Local development

```bash
# clone into your WP install's plugins dir as `amplifi-security`
cd wp-content/plugins/
ln -s /path/to/amplifi.security amplifi-security

# verify with Plugin Check (Automattic)
wp plugin install plugin-check --activate
wp plugin check amplifi-security

# run a scan immediately
wp amplifi-security scan
```

## Build a release zip

The monorepo release script handles all plugins together:

```bash
# from the repo root (one level above this plugin)
./scripts/release.sh <version>
# produces dist/amplifi-security-v<version>.zip alongside zips for the other plugins
```

## License

GPL v2 or later. See `LICENSE`. Bundled third-party data and rule logic is attributed in `THIRD_PARTY_NOTICES.md`.

## Security disclosure

See `SECURITY.md`.
