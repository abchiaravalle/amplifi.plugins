# Blog Post — amplifi.lockcache v1.0.0 Launch
# Date: 2026-02-20
# Version: 1.0.0
# Author: Adam Chiaravalle / amplifi.studio

---

## SEO Metadata

| Field | Value |
|-------|-------|
| **Title Tag** | amplifi.lockcache: Free Static HTML Cache for WordPress Password-Protected Pages (Open Source) |
| **Meta Description** | Speed up WordPress password-protected pages with static HTML caching. amplifi.lockcache serves cached HTML instantly once a visitor unlocks a page. Free and open source. |
| **URL Slug** | /blog/amplifi-lockcache-static-cache-wordpress-protected-pages |
| **Canonical** | https://amplifi.studio/blog/amplifi-lockcache-static-cache-wordpress-protected-pages |
| **OG Title** | Static HTML Caching for WordPress Password-Protected Pages |
| **OG Description** | amplifi.lockcache is a free, open-source WordPress plugin that caches password-protected pages as static HTML files. Instant page loads after first unlock. |
| **OG Image** | amplifi.lockcache admin dashboard screenshot (1200x630 crop) |
| **OG Type** | article |
| **Twitter Card** | summary_large_image |
| **Focus Keyphrase** | WordPress cache password-protected pages |
| **Secondary Keywords** | static HTML cache WordPress, cache protected pages, WordPress performance plugin, speed up protected pages, free WordPress cache plugin, open source WordPress cache |
| **Schema Type** | Article / BlogPosting |
| **Author** | Adam Chiaravalle |
| **Publisher** | amplifi.studio |
| **Category** | WordPress, Open Source, Performance |
| **Tags** | WordPress, caching, static HTML, password protection, performance, speed, open source, plugin |

---

## Article

# How to Cache WordPress Password-Protected Pages for Instant Load Times

Most WordPress caching plugins ignore password-protected pages.

It makes sense — caching a protected page and serving it to everyone would be a security problem. So WP Super Cache, W3 Total Cache, WP Rocket, and most others simply skip these pages entirely.

But that means every single visit to a password-protected page hits your PHP stack and database, even after the visitor has already unlocked it. For sites that rely heavily on protected content — client portals, gated resources, membership content, internal documentation — this creates a real performance gap.

**amplifi.lockcache** fills that gap.

## What is amplifi.lockcache?

amplifi.lockcache is a **free, open-source WordPress plugin** that generates static HTML cache files for password-protected posts and pages. Once a visitor has unlocked a page (by entering the password or using a magic link), subsequent visits serve the cached HTML file instead of processing PHP and querying the database.

The cached files live on your server at `wp-content/pp-static-cache/`. They're protected by `.htaccess` rules and restrictive file permissions, so they can't be accessed directly by the web server.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

## How It Works

### 1. First Visit After Unlock

When a visitor unlocks a password-protected page (via the password form or a magic link), the plugin captures the rendered HTML output and saves it as a static file.

### 2. Subsequent Visits

On the next visit, if the visitor has a valid password cookie for that page, the plugin serves the cached HTML file directly. No PHP execution, no database queries, no template rendering.

### 3. Smart Skip Logic

The plugin is careful about what it caches and when:

- **Admin users are skipped** — Caching with the WordPress admin bar would serve admin UI to non-admin visitors
- **AJAX requests are skipped** — Dynamic requests should always hit PHP
- **Search & Filter Pro requests are skipped** — Filtered content needs live processing
- **Only unlocked pages are cached** — The cache is only generated after a visitor has authenticated

### 4. Security

- Cache files are stored in `wp-content/pp-static-cache/` with an `.htaccess` file that denies all direct access
- File permissions are set to `0600` — readable only by the owner
- The plugin validates the password cookie before serving cached content
- Cache files contain rendered HTML only — no sensitive data beyond what the page normally displays

## When Would You Use This?

### Client Portals

If you run a WordPress site where clients log in with passwords to view deliverables, reports, or project pages — every page load hits your server's full rendering pipeline. With lockcache, the first load generates the cache, and every subsequent load is instant.

### Gated Content Libraries

Sites with dozens or hundreds of password-protected resources (whitepapers, case studies, templates) benefit significantly. Once the content is cached, your server barely notices the traffic.

### Internal Documentation

Password-protected pages used for internal docs get the same treatment. Frequently accessed reference pages serve instantly from cache.

### Paired with amplifi.magic

amplifi.lockcache works naturally with **amplifi.magic** (one-click magic links). A magic link unlocks the page, the first load generates the cache, and every subsequent load from that visitor serves static HTML. The combination gives you frictionless access and fast load times.

## Features

- **Static HTML caching** for password-protected posts and pages
- **Automatic cache generation** on first unlocked visit
- **Smart skip logic** for admin users, AJAX, and Search & Filter Pro
- **Security-first storage** with `.htaccess` deny rules and `0600` permissions
- **No-cache headers** on protected pages (prevents browser/CDN caching of password forms)
- **Debug logging** for troubleshooting cache behavior
- **Admin dashboard** showing cache status and controls
- **Zero configuration** — activate and it works

## Performance Impact

The exact improvement depends on your hosting environment and page complexity, but the difference is straightforward:

| Metric | Without Cache | With Cache |
|--------|--------------|------------|
| PHP execution | Full template render | Skipped |
| Database queries | 20-50+ queries | 0 queries |
| Response time | 200-800ms (typical) | 10-50ms |
| Server load | Normal | Minimal |

For sites with heavy traffic to protected pages, this reduces server load significantly and improves the visitor experience.

## Part of amplifi.plugins

amplifi.lockcache is part of the **amplifi.plugins** suite — a growing collection of WordPress tools by amplifi.studio.

All plugins share a unified admin under the "amplifi.studio" sidebar menu. Install one and discover the rest from the built-in Plugin Hub.

## Getting Started

1. **Download** the latest release from [GitHub Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip file and click **Activate**
4. Navigate to **amplifi.studio > LockCache** in your admin sidebar
5. That's it — password-protected pages will start caching automatically on first unlocked visit

## Open Source and Transparent

amplifi.lockcache is **MIT licensed**. The full source is on GitHub. Cache files are stored locally on your server. No data leaves your site.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

---

*amplifi.lockcache v1.0.0 is available now. If your password-protected pages are slow — [they don't have to be](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).*

*Built by [amplifi.studio](https://amplifi.studio)*

---

## Internal Notes

### Suggested Internal Links
- amplifi.studio homepage
- amplifi.plugins GitHub repository
- amplifi.magic blog post (natural companion — magic links for protected pages)
- Other amplifi plugin blog posts

### Suggested External Links
- WordPress password protection documentation
- WP Rocket, W3 Total Cache documentation (comparison context)

### Content Distribution
- Publish on amplifi.studio/blog
- Share on LinkedIn (see companion LinkedIn post)
- Submit to WordPress performance communities (r/WordPress, WP Tavern)
- Cross-post to Dev.to and Hashnode with canonical pointing to amplifi.studio

### Republishing Schedule
- Week 1: LinkedIn + blog publish
- Week 2: Dev.to cross-post
- Week 3: Hashnode cross-post
- Week 4: Reddit / WordPress community posts
