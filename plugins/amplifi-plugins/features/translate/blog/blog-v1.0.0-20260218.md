# Blog Post — amplifi.translate v1.0.0 Launch
# Date: 2026-02-18
# Version: 1.0.0
# Author: Adam Chiaravalle / amplifi.studio

---

## SEO Metadata

| Field | Value |
|-------|-------|
| **Title Tag** | amplifi.translate: Free AI-Powered WordPress Translation Plugin (Open Source) |
| **Meta Description** | Translate your WordPress site into 33 languages with AI for under $2. amplifi.translate is a free, open-source plugin using OpenAI. No subscriptions, no lock-in. |
| **URL Slug** | /blog/amplifi-translate-ai-wordpress-translation-plugin |
| **Canonical** | https://amplifi.studio/blog/amplifi-translate-ai-wordpress-translation-plugin |
| **OG Title** | Translate Your WordPress Site with AI for Under $2 |
| **OG Description** | amplifi.translate is a free, open-source WordPress plugin that uses OpenAI to translate your entire site into 33 languages. No subscriptions. No manual work. |
| **OG Image** | amplifi.translate admin settings screenshot (1200x630 crop) |
| **OG Type** | article |
| **Twitter Card** | summary_large_image |
| **Focus Keyphrase** | AI WordPress translation plugin |
| **Secondary Keywords** | WordPress multilingual plugin, OpenAI translation, free translation plugin, open source WordPress translation, GPT WordPress plugin, automatic website translation |
| **Schema Type** | Article / BlogPosting |
| **Author** | Adam Chiaravalle |
| **Publisher** | amplifi.studio |
| **Category** | WordPress, Open Source, AI Tools |
| **Tags** | WordPress, translation, OpenAI, GPT, multilingual, open source, AI, plugin, i18n, localization |

---

## Article

# How to Translate Your WordPress Site with AI for Under $2

Most WordPress translation plugins give you two options: do it yourself, or pay someone else to do it every month.

Manual translation is thorough but expensive — a professional translator charges hundreds or thousands of dollars per language. Subscription plugins like WPML or Weglot handle it automatically but cost $15-50/month, and your translations live on their servers.

We built **amplifi.translate** because there's now a third option that didn't exist a few years ago: let AI do the work for almost nothing, and keep everything in your own database.

## What is amplifi.translate?

amplifi.translate is a **free, open-source WordPress plugin** that translates your entire site using OpenAI's language models. It supports **33 languages** and works through URL-based language prefixes — your Spanish pages live at `/es/`, French at `/fr/`, Chinese at `/zh/`, and so on.

There are no duplicate pages to manage, no content syncing to worry about, and no third-party service to depend on. You bring your own OpenAI API key. The plugin handles everything else.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

## How Does AI WordPress Translation Work?

The translation flow is straightforward:

### 1. Install and Configure
Upload the plugin, enter your OpenAI API key, and select which languages you want. That's the entire setup.

### 2. First Visit Triggers Translation
When someone visits `/es/your-page/` for the first time, the plugin sends your content to OpenAI for translation and stores the result in your WordPress database.

### 3. Cached From There
Every subsequent visit serves the cached translation instantly — no API call, no added latency. Your translated pages load just as fast as your original content.

### 4. Automatic Cache Invalidation
When you update a post or page in WordPress, the plugin automatically clears the cached translations for that content. The next visitor gets a fresh, up-to-date translation.

The plugin doesn't just translate post content. It translates **everything on the page** — navigation menus, headers, footers, meta descriptions, and page titles. Visitors get a fully translated experience, not a partially patched page with English fragments.

## What Does It Cost?

This is where AI translation changes the equation.

OpenAI's **GPT-4o Mini** model translates a typical WordPress page for approximately **$0.002** — that's two-tenths of a cent per page.

Here's what that looks like in practice:

| Site Size | Languages | Approximate Cost |
|-----------|-----------|-----------------|
| 10 pages | 3 languages | $0.06 |
| 50 pages | 5 languages | $0.50 |
| 100 pages | 5 languages | $1-2 |
| 100 pages | 10 languages | $2-4 |

Once translations are cached, **they're free forever**. You only pay when new content is published or existing content is updated.

Compare that to WPML ($39-99/year), Weglot ($15-79/month), or TranslatePress ($89-199/year) — and those are recurring costs for the life of your site.

## Features

### 33 Supported Languages
Spanish, French, German, Portuguese, Italian, Dutch, Chinese (Simplified & Traditional), Japanese, Korean, Arabic, Hindi, Russian, and 20 more. The full list covers every major world language.

### Any OpenAI Model
GPT-4o Mini is the default and recommended model for cost efficiency, but you can use **any chat-compatible model** available in your OpenAI account. The plugin dynamically fetches your available models so you always have the latest options.

### Language Switcher
Add a language switcher to your site as a **native WordPress menu item** or with the `[acwpt_switcher]` shortcode. It shows flag icons and language names, and integrates naturally with your existing navigation.

### Browser Language Detection
The plugin detects your visitor's browser language preference and displays a non-intrusive suggestion banner: "This page is available in Spanish" with a one-click switch.

### SEO-Optimized
- Proper `hreflang` tags on every page telling search engines which language version to serve
- Correct `<html lang>` attributes for accessibility and SEO
- **Multilingual XML sitemap** at `/acwpt-sitemap.xml` with all language variants
- Clean URL structure that search engines understand (`/es/page-slug/`, `/fr/page-slug/`)

### API Usage Tracking
See exactly how many tokens you've used and what it's costing you, broken down by month, right in the WordPress admin dashboard. No surprises.

### Smart Caching
Translations are stored in a dedicated database table, keyed by post ID and language. Cache invalidation is automatic — edit a post and the stale translations clear themselves.

## How It Compares

| Feature | amplifi.translate | WPML | Weglot | TranslatePress |
|---------|------------------|------|--------|----------------|
| **Price** | Free (+ ~$0.002/page API) | $39-99/year | $15-79/month | $89-199/year |
| **Translation Method** | AI (OpenAI) | Manual / AI add-on | Machine + manual | Machine + manual |
| **Data Ownership** | Your database | Your database | Their servers | Your database |
| **Open Source** | Yes (MIT) | No | No | Partial |
| **Languages** | 33 | 65+ | 110+ | 220+ |
| **Setup Time** | ~2 minutes | 15-30 minutes | ~5 minutes | ~5 minutes |
| **Recurring Cost** | None | Annual | Monthly | Annual |

The trade-off is clear: amplifi.translate supports fewer languages and doesn't have a visual translation editor, but it's free, open source, and the per-page cost is negligible. For most sites that need 2-10 languages, it's the most cost-effective option available.

## Part of amplifi.plugins

amplifi.translate is the first plugin in the **amplifi.plugins** suite — a growing collection of AI-powered WordPress tools by amplifi.studio.

All amplifi plugins share a unified admin experience:
- **Single sidebar menu**: Every plugin appears under "amplifi.studio" in your WordPress admin
- **Plugin Hub**: Install one plugin and discover others directly from your dashboard
- **Auto-updates**: New versions show up in your WordPress update screen automatically, pulled from GitHub releases

## Getting Started

Getting your WordPress site translated takes about two minutes:

1. **Download** the latest release from [GitHub Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip file and click **Activate**
4. Navigate to **amplifi.studio > Translate** in your admin sidebar
5. Enter your OpenAI API key and select your target languages
6. Visit any page with a language prefix (e.g., `/es/`) to see it translated

## Securing Your API Key

Since you provide your own OpenAI API key, you control your costs and security:

- **Scope your key** — Use OpenAI's API key permissions to restrict access to Chat Completions only
- **Set spending limits** — Configure monthly caps and rate limits in your [OpenAI dashboard](https://platform.openai.com)
- **Enable billing alerts** — Get notified before costs exceed your expectations
- **Rotate regularly** — If you suspect a key has been exposed, rotate it immediately

Your API key is stored in your WordPress database (`wp_options`) and is **never transmitted to any server other than OpenAI's API**.

## Open Source and Transparent

amplifi.translate is **MIT licensed**. The entire codebase is on GitHub — you can read every line, fork it, extend it, or contribute back.

We believe WordPress plugins that handle your content should be transparent. You shouldn't have to wonder what happens with your data. With amplifi.translate, the answer is simple: content goes to OpenAI for translation, the result comes back and gets stored in your database. Nothing else leaves your server.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

---

*amplifi.translate v1.0.0 is available now. If you've been putting off making your WordPress site multilingual because it seemed too expensive or too complicated — [download it and try it](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).*

*Built by [amplifi.studio](https://amplifi.studio)*

---

## Internal Notes

### Suggested Internal Links
- amplifi.studio homepage
- amplifi.plugins GitHub repository
- Future: other amplifi plugin blog posts

### Suggested External Links
- OpenAI API pricing page
- WordPress.org plugin directory (if/when listed)
- WPML, Weglot, TranslatePress pricing pages (for comparison context)

### Content Distribution
- Publish on amplifi.studio/blog
- Share on LinkedIn (see companion LinkedIn post)
- Submit to WordPress-focused communities (WP Tavern, r/WordPress, WordPress Slack)
- Cross-post to Dev.to and Hashnode with canonical pointing to amplifi.studio

### Republishing Schedule
- Week 1: LinkedIn + blog publish
- Week 2: Dev.to cross-post
- Week 3: Hashnode cross-post
- Week 4: Reddit / WordPress community posts
