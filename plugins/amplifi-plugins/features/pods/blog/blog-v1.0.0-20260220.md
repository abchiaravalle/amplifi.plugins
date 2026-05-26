# Blog Post — amplifi.pods v1.0.0 Launch
# Date: 2026-02-20
# Version: 1.0.0
# Author: Adam Chiaravalle / amplifi.studio

---

## SEO Metadata

| Field | Value |
|-------|-------|
| **Title Tag** | amplifi.pods: Free WordPress Podcast Carousel and Floating Player (Open Source) |
| **Meta Description** | Display your Apple Podcasts episodes in a responsive carousel with a floating player. amplifi.pods is a free, open-source WordPress plugin with RSS feed and custom post type modes. |
| **URL Slug** | /blog/amplifi-pods-wordpress-podcast-carousel-player |
| **Canonical** | https://amplifi.studio/blog/amplifi-pods-wordpress-podcast-carousel-player |
| **OG Title** | A Responsive Podcast Carousel and Floating Player for WordPress |
| **OG Description** | amplifi.pods is a free, open-source WordPress plugin that displays your podcast episodes in a swipeable carousel with a floating Apple Podcasts player. RSS feed or custom post type. |
| **OG Image** | amplifi.pods carousel screenshot (1200x630 crop) |
| **OG Type** | article |
| **Twitter Card** | summary_large_image |
| **Focus Keyphrase** | WordPress podcast carousel plugin |
| **Secondary Keywords** | WordPress podcast player, Apple Podcasts WordPress, podcast carousel shortcode, RSS podcast WordPress, floating podcast player, free podcast plugin WordPress |
| **Schema Type** | Article / BlogPosting |
| **Author** | Adam Chiaravalle |
| **Publisher** | amplifi.studio |
| **Category** | WordPress, Open Source, Podcasting |
| **Tags** | WordPress, podcast, carousel, Apple Podcasts, RSS, Swiper, floating player, shortcode, open source, plugin |

---

## Article

# How to Display Your Podcast Episodes in a WordPress Carousel with a Floating Player

If you host a podcast and have a WordPress site, you've probably tried embedding episodes. And you've probably been disappointed.

The default Apple Podcasts embed is a single episode. Spotify's embed is the same. There's no native way to showcase multiple episodes in a browsable, visual format — and most podcast plugins either look dated, add bloat, or try to replace your entire podcast workflow.

We wanted something simpler: a good-looking carousel of episodes that visitors can browse, and a floating player that stays with them as they scroll.

That's **amplifi.pods**.

## What is amplifi.pods?

amplifi.pods is a **free, open-source WordPress plugin** that displays your podcast episodes in a responsive, swipeable carousel. Click on an episode card and a floating Apple Podcasts player slides up from the bottom of the screen.

It works in two modes:
- **RSS Feed mode**: Point it at any Apple Podcasts RSS feed URL and it pulls episodes automatically
- **CPT mode**: Use the built-in `amplifi-podcasts` custom post type to manage episodes manually with full control

Drop a shortcode on any page and the carousel appears. Multiple carousels per page are supported.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

## How It Works

### RSS Feed Mode

The fastest way to get started. Add the shortcode with your podcast's RSS feed URL:

```
[amplifi-pods feed="https://your-rss-feed-url" count="8"]
```

The plugin parses the feed, extracts episode titles, artwork, numbers, durations, and Apple Podcasts embed IDs, then renders them as carousel cards. Feed data is cached for 1 hour to avoid repeated API calls.

### Custom Post Type Mode

For more control, use the built-in custom post type. Each `amplifi-podcasts` post represents an episode with fields for:
- Show name
- Apple show and episode IDs (for the embed player)
- Artwork URL
- Episode number
- Duration
- Categories and tags via a custom taxonomy

Then use the shortcode without a feed URL:

```
[amplifi-pods]                              <!-- All episodes -->
[amplifi-pods category="tech" count="6"]    <!-- Filtered -->
```

### The Carousel

Episode cards are displayed in a responsive Swiper.js carousel. Each card shows the episode artwork, title, number, duration, and show name. The carousel adapts to screen size — multiple cards on desktop, fewer on tablet, single card on mobile.

Cards are clickable. Click one and the floating player appears.

### The Floating Player

When a visitor clicks an episode card, a floating Apple Podcasts player slides up from the bottom of the viewport. It stays fixed in position as they scroll, so they can continue browsing your site while listening.

The player uses Apple Podcasts' official embed, so listeners can play the episode directly or open it in Apple Podcasts.

## Design Philosophy

amplifi.pods was built to be **site-agnostic**:

- **No font declarations** — Uses your theme's fonts
- **No Bootstrap** — Zero framework dependencies
- **CSS custom properties** — All styling via `--acpods-*` variables for easy theming
- **Swiper.js via CDN** — Only external dependency, loaded from CDN to minimize plugin size

The carousel blends with any theme. If you need to customize colors or spacing, override the CSS custom properties in your theme.

## Features

- **Responsive Swiper carousel** with touch/swipe support
- **Floating Apple Podcasts player** with slide-up animation
- **Dual mode**: RSS feed or custom post type
- **Multiple carousels per page** supported
- **RSS caching** — 1-hour transient cache per feed URL
- **Custom taxonomy** for episode categories
- **Meta boxes** for episode details (show name, IDs, artwork, number, duration)
- **Shortcode with parameters** — feed URL, count, category filter
- **CSS custom properties** for easy theming
- **Site-agnostic design** — no fonts, no frameworks, blends with any theme
- **Admin settings page** under the amplifi.studio menu

## Shortcode Reference

| Shortcode | Mode | Description |
|-----------|------|-------------|
| `[amplifi-pods feed="URL" count="8"]` | RSS | Pull episodes from an RSS feed |
| `[amplifi-pods]` | CPT | Show all episodes from the custom post type |
| `[amplifi-pods category="tech" count="6"]` | CPT | Filter by category with a count limit |

## Part of amplifi.plugins

amplifi.pods is part of the **amplifi.plugins** suite — a growing collection of WordPress tools by amplifi.studio.

All plugins share a unified admin under the "amplifi.studio" sidebar menu. Install one and discover the rest from the built-in Plugin Hub.

## Getting Started

1. **Download** the latest release from [GitHub Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip file and click **Activate**
4. Navigate to **amplifi.studio > Pods** in your admin sidebar
5. Add the `[amplifi-pods feed="your-feed-url"]` shortcode to any page
6. Your podcast carousel is live

## Open Source and Transparent

amplifi.pods is **MIT licensed**. The full source is on GitHub. The only external requests are to your RSS feed (for episode data) and Swiper.js CDN (for the carousel library). No tracking, no analytics, no data collection.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

---

*amplifi.pods v1.0.0 is available now. If your podcast deserves more than a single embed on your WordPress site — [give it a proper showcase](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).*

*Built by [amplifi.studio](https://amplifi.studio)*

---

## Internal Notes

### Suggested Internal Links
- amplifi.studio homepage
- amplifi.plugins GitHub repository
- Other amplifi plugin blog posts

### Suggested External Links
- Apple Podcasts for Creators documentation
- Swiper.js documentation
- WordPress shortcode documentation

### Content Distribution
- Publish on amplifi.studio/blog
- Share on LinkedIn (see companion LinkedIn post)
- Submit to podcast and WordPress communities (r/podcasting, r/WordPress, Podcast Movement forums)
- Cross-post to Dev.to and Hashnode with canonical pointing to amplifi.studio

### Republishing Schedule
- Week 1: LinkedIn + blog publish
- Week 2: Dev.to cross-post
- Week 3: Hashnode cross-post
- Week 4: Reddit / podcast + WordPress community posts
