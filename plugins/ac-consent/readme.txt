=== amplifi.consent ===
Contributors: amplifistudio
Tags: cookie consent, gdpr, privacy, cookie banner, tracking, consent mode
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT

First-party cookie consent that HARD-WITHHOLDS tracking scripts until the visitor accepts. Add scripts, categorize them, and nothing fires on reject.

== Description ==

Unlike Google Consent Mode (which lets tags fire in an "anonymized" mode before
consent), amplifi.consent genuinely withholds your tracking scripts. Every script
you add is rendered inside an inert `<template>` element — browsers do not execute
anything inside a template, including `<script src>` loaders — and is only
re-materialized as a live script for the categories the visitor has granted.

Reject = the templates are never released = zero tracking runs.

= Features =

* **Hard withholding** — scripts you paste in are gated by category and do not run until accepted. This includes external `src` loaders and GTM containers.
* **First-visit popup** — Accept all / Reject all / Manage, with a centered-modal or bottom-bar layout.
* **Per-category toggles** — Strictly Necessary (always on), Functional, Analytics, Marketing.
* **Toast feedback** — a small toast confirms when the visitor accepts or rejects.
* **180-day localStorage consent** — consent is remembered client-side with a configurable TTL (default 180 days); the banner stays hidden until it expires.
* **`[amplifi-consent-manager]` shortcode** — drops a button anywhere that re-opens the preferences modal so visitors can change their mind.
* **Cookie scanner** — load any managed script in a sandboxed same-origin iframe to detect the cookies it sets, then categorize each one. Those categorizations populate the visitor-facing Manage panel.
* **REST config endpoint** — `GET /wp-json/amplifi-consent/v1/config` exposes the categories and categorized cookie catalog (no script bodies).

== Installation ==

1. Install and activate the plugin (or enable Consent in the amplifi.studio hub).
2. Go to **amplifi.studio → Consent → Scripts** and add your tracking snippets, each with a category.
3. Click **Scan cookies** on each script to detect the cookies it sets.
4. Go to the **Cookies** tab and assign each detected cookie to a category.
5. Tune the banner copy and colors under **Settings**.

== Frequently Asked Questions ==

= Does this stop Google Tag Manager from firing? =

Yes — if you add the GTM container snippet as a managed script, it is held inside
the inert template and only released after consent. That closes the GTM hole that
defeats most CMPs (where GTM injects tags the CMP never sees).

= How is this different from Consent Mode? =

Consent Mode (Advanced) still fires tags and sends cookieless pings before consent.
amplifi.consent does not load or run the script at all until the category is granted.

== Changelog ==

= 1.0.0 =
* Initial release: hard script withholding, first-visit popup, per-category toggles,
  toast feedback, 180-day localStorage consent, manager shortcode, iframe cookie
  scanner with categorization, and a REST config endpoint.
