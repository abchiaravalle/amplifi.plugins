=== amplifi.security ===
Contributors: amplifistudio
Tags: security, malware, scanner, ai, anthropic, claude, audit, integrity, brute force, vulnerability
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress security with an AI brain. Less noise, more signal.

== Description ==

amplifi.security scans your WordPress install locally and ships findings to Claude (your own Anthropic API key) for triage. Only alerts you when the AI classifies a finding as `confirmed` or `likely`. Drops noise on the floor with a logged rationale you can audit later.

= What it does =

* **AI-triaged findings** — local scanners find suspicious patterns; Claude makes the judgement call. You see verdicts (`confirmed`, `likely`, `worth_reviewing`, `benign`) instead of raw alerts.
* **8 scanner modules** — shell/backdoor, file integrity (WP core + plugins + themes), critical-file watch (`.htaccess`, `wp-config.php`, mu-plugins, dropins), DB/user-lifecycle anomaly, auth anomaly (with optional GeoIP + AbuseIPDB), vulnerability (Wordfence Intelligence v3), cron anomaly, REST/XML-RPC abuse, plus a hardening scanner for config sanity.
* **Hash-chained audit log** — every privileged event (logins, role changes, plugin/theme lifecycle, settings changes) is journaled with HMAC chaining so tampering is detectable.
* **Canary URL** — point an external uptime monitor at a per-site URL that confirms the plugin is alive AND triage is healthy. Catches "plugin silently disabled by attacker."
* **Stealth Mode (optional, default OFF)** — hides the plugin from non-installer admins. Disclosed up-front so you know what it does.
* **Self-defense** — the plugin checks its OWN files every scan and HMACs critical config rows. If something tampers with us, we tell you.
* **Customizable routing** — every (category × verdict) pair maps to a channel: email + SMS, email, daily digest, log only, or mute. `confirmed malware` cannot be muted.
* **Per-site spend caps** — daily and monthly USD ceilings on Claude usage. Default $2/day, $30/month.

= Free dependencies =

You bring an Anthropic API key (paid, your own) and an SMTP2Go API key (free tier 1000 emails/month). Optional: AbuseIPDB free key, Wordfence Intelligence free token, Textbelt SMS key.

= What's NOT in here =

No telemetry, no SaaS layer, no vendor lock-in. The plugin only contacts services you explicitly configure. The "About" tab lists every outbound endpoint.

= Bundled FOSS =

* php-malware-finder rule logic (Apache 2.0)
* Pressidium YARA Rules (MIT)
* DB-IP Lite IP geolocation (CC-BY 4.0)

See `THIRD_PARTY_NOTICES.md` in the plugin folder for full attributions.

== Installation ==

1. Upload the `amplifi-security` folder to `/wp-content/plugins/`.
2. Activate via the Plugins menu.
3. Walk through the 4-step onboarding wizard: API keys → recipients → log sources (optional) → first scan.
4. Point an uptime monitor at the canary URL shown in Settings → Stealth & Defense.

== Frequently Asked Questions ==

= Does this send my files to a third party? =

Only to Claude (Anthropic), via your own API key, and only the specific evidence the scanners surface as suspicious. The "Last triage payload" debug viewer in Settings → Triage shows you exactly what was sent on the most recent scan.

`wp-config.php` content is *never* sent — only a redacted unified diff with secret-pattern lines stripped client-side.

= Will Stealth Mode get the plugin rejected from WP.org? =

Stealth Mode is OFF by default, requires explicit user opt-in, and is fully disclosed in this readme. The feature exists because attackers commonly disable visible security plugins as their first move on a compromised site. Hiding the menu raises that bar.

= What happens if my Claude key is invalid or the API is down? =

After 3 failed batches the plugin drops into "naive mode" — high-confidence local signatures get `confirmed`, everything else is queued for the next batch. You see a persistent admin notice and the canary URL flips to `last_triage_ok: false` so your uptime monitor catches it.

== Screenshots ==

1. Health dashboard with green/yellow/red status pill.
2. Findings list with category × verdict filters.
3. Routing matrix.
4. Audit log with hash-chain status.

== Changelog ==

= 0.1.0 =
* Initial release.

== Privacy ==

amplifi.security only contacts:

* Anthropic Messages API (api.anthropic.com) — for triage. Sends scanner findings + optional log excerpts.
* SMTP2Go REST API (api.smtp2go.com) — for email alerts.
* WordPress.org core checksums API — for integrity baseline.
* Wordfence Intelligence v3 (wordfence.com) — for the vulnerability feed (optional, requires your own free token).
* AbuseIPDB v2 (abuseipdb.com) — for IP reputation (optional, requires your own free key).
* Textbelt (textbelt.com) — for SMS alerts (optional, requires your own paid key).

No analytics. No telemetry. No vendor servers.
