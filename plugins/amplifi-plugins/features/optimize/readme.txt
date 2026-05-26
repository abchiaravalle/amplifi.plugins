=== amplifi.optimize ===
Contributors: amplifistudio
Tags: seo, claude, ai, meta description, alt text, content audit
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO triage. Scans for fixable issues, drafts fixes with Claude, lets a human approve each one.

== Description ==

amplifi.optimize finds common, fixable SEO problems on your WordPress site and uses the Anthropic Claude API to draft fixes. Every change goes through a human-review queue before it touches your content.

Four fix types in v1:

* **Missing meta descriptions** — finds posts without a description set in Yoast, RankMath, or AIOSEO and drafts 140–155 character descriptions in batches.
* **Missing image alt text** — uses Claude vision to write WCAG-friendly alt text for unaltered images, and flags decorative ones.
* **Long SEO titles** — rewrites titles over 60 characters down to ≤58 while preserving the primary keyword.
* **Unpublish candidates** — heuristically flags junk/stale/duplicate pages and asks Claude whether each should be deleted, redirected, noindexed, or kept.

Designed for power users: keyboard-driven review queue (A/R/E/S/→), buffered batch approve, WP-CLI for bulk runs, every applied change is undoable.

== Installation ==

1. Upload the `amplifi-optimize` folder to `/wp-content/plugins/` and activate.
2. Go to **amplifi.optimize → Settings** and paste your Anthropic API key.
3. Run a scan from **amplifi.optimize → Scans**.
4. Review and approve suggestions in **Review Queue**.

== Frequently Asked Questions ==

= Do I need an Anthropic API key? =

Yes. The plugin calls the Claude API to draft suggestions. You'll be billed for token usage on your Anthropic account. The Dashboard shows cumulative token counts per model.

= Which SEO plugins are supported? =

Yoast SEO, RankMath, and All in One SEO are auto-detected. If you don't have any of them, the plugin stores meta descriptions and titles under its own meta keys.

= Where is my API key stored? =

Encrypted at rest in the `wp_options` table using AES-256-CBC keyed off `wp_salt('auth')`. The plaintext key is never returned by the REST API.

= Can I undo changes? =

Yes. Every applied change saves a snapshot of the previous value. The History screen exposes an Undo button on recent applies.

= Does this replace Yoast / RankMath / AIOSEO? =

No. It writes into their meta keys. Think of it as triage — not a full SEO suite.

== Screenshots ==

1. Review Queue — one card at a time with keyboard shortcuts.
2. Dashboard — counts by fix type and Claude token usage.
3. Settings — API key, model, batching, scan filters.

== Changelog ==

= 1.0.0 =
* Initial release. Four fix types: meta description, alt text, long title, unpublish candidates.

== Upgrade Notice ==

= 1.0.0 =
First release.

== Coming in v2 ==

* Schema generation (LocalBusiness, FAQPage, Article)
* H1 fixes (theme-level)
* Internal link suggestions
* Content rewriting / expansion
* Redirect map import for `-2`/`-3` duplicates
