# Blog Post — amplifi.magic v1.0.0 Launch
# Date: 2026-02-20
# Version: 1.0.0
# Author: Adam Chiaravalle / amplifi.studio

---

## SEO Metadata

| Field | Value |
|-------|-------|
| **Title Tag** | amplifi.magic: Free WordPress Magic Links for Password-Protected Pages (Open Source) |
| **Meta Description** | Share password-protected WordPress pages without sharing the password. amplifi.magic generates one-click magic links with usage tracking and geolocation logging. Free and open source. |
| **URL Slug** | /blog/amplifi-magic-wordpress-magic-links-plugin |
| **Canonical** | https://amplifi.studio/blog/amplifi-magic-wordpress-magic-links-plugin |
| **OG Title** | Share Password-Protected WordPress Pages Without Sharing the Password |
| **OG Description** | amplifi.magic generates one-click magic links for WordPress password-protected pages. Named tokens, usage logs with IP geolocation, and one-click revocation. Free and open source. |
| **OG Image** | amplifi.magic admin dashboard screenshot (1200x630 crop) |
| **OG Type** | article |
| **Twitter Card** | summary_large_image |
| **Focus Keyphrase** | WordPress magic links plugin |
| **Secondary Keywords** | WordPress password-protected pages, share protected content, WordPress access links, magic link authentication, free WordPress access plugin, one-click page access |
| **Schema Type** | Article / BlogPosting |
| **Author** | Adam Chiaravalle |
| **Publisher** | amplifi.studio |
| **Category** | WordPress, Open Source, Security, Content Access |
| **Tags** | WordPress, magic links, password protection, access control, security, tokens, geolocation, open source, plugin |

---

## Article

# How to Share Password-Protected WordPress Pages Without Sharing the Password

WordPress password protection is useful — until you need to share the page with someone.

You give them the password. They type it in. It works. But now that password is in an email, a Slack message, a text thread. You have no idea who's actually using it, and revoking access means changing the password for everyone.

For client portals, gated content, internal documentation, or any page where you need controlled access, this isn't good enough.

**amplifi.magic** fixes this with one-click magic links.

## What is amplifi.magic?

amplifi.magic is a **free, open-source WordPress plugin** that generates shareable links for password-protected pages. When someone clicks a magic link, they're granted access instantly — no password prompt, no friction, no shared credentials.

Each link is a **named token** that you can track, monitor, and revoke independently. You see exactly who accessed what, when, from where, and through which link.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

## How It Works

### 1. Password-Protect a Page

Use WordPress's built-in password protection. Publish a page or post with visibility set to "Password protected." This is native WordPress — no special setup needed.

### 2. Generate Named Tokens

In the amplifi.magic admin panel, select a protected page and create one or more named tokens. Each token gets a descriptive name — "Client: Acme Corp," "Review: Design Team," "Stakeholder: Board Members."

The plugin generates a unique URL for each token. The URL looks like:
```
https://yoursite.com/?ocml_token=abc123def456
```

### 3. Share the Link

Send the magic link to whoever needs access. When they click it, the plugin sets the appropriate WordPress password cookie and redirects them to the page. They see the content immediately, as if they'd entered the password.

### 4. Track Usage

Every time a magic link is used, the plugin logs:
- **Which token** was used (by name)
- **IP address** of the visitor
- **Geolocation** — city, region, and country (via ip-api.com)
- **Timestamp** of each access

The access log is filterable by page, token, IP address, location, and date range.

### 5. Revoke Anytime

Deactivate any individual token without affecting others. If a link is compromised or a client relationship ends, revoke that specific token. Everyone else's access continues uninterrupted.

## Use Cases

### Client Deliverables
Share work-in-progress pages with specific clients. Each client gets their own named token. Track who's viewing and when. Revoke after project completion.

### Gated Content
Distribute exclusive content to specific audiences. Create tokens per campaign, per partner, or per distribution channel. See which channels drive the most engagement.

### Internal Documentation
Share protected internal pages with team members or stakeholders without managing WordPress user accounts. Named tokens make it clear who has access to what.

### Freelancer Portfolios
Share password-protected portfolio pieces with prospective clients. Track which pieces get viewed and how often. Revoke access after the pitch.

## Features

- **Named tokens** — Every link has a descriptive name for easy management
- **One-click access** — Recipients click the link and see the content immediately
- **Per-token usage logs** — IP, geolocation, timestamp for every access event
- **Filterable access logs** — Filter by page, token, IP, location, date range
- **Individual revocation** — Deactivate one token without affecting others
- **Native WordPress integration** — Uses WordPress's built-in password cookie system
- **No user accounts required** — Recipients don't need WordPress accounts
- **Works with any protected page** — Posts, pages, custom post types

## How It Compares to Alternatives

| Approach | Friction | Tracking | Revocation | Cost |
|----------|----------|----------|------------|------|
| **Share the password** | Low | None | Change password for everyone | Free |
| **WordPress user accounts** | High (registration) | Login logs | Delete account | Free |
| **Membership plugins** | Medium | Varies | Per-user | $49-299/year |
| **amplifi.magic** | None (one click) | Full (IP, geo, time) | Per-token | Free |

amplifi.magic gives you the lowest friction (one click) with the best tracking (per-token logs with geolocation) and granular revocation — for free.

## Security

Magic link tokens are stored as **hashed values** in post meta. The actual token only exists in the URL. Even if someone accesses your database, they can't reconstruct valid magic links from the stored data.

The plugin uses WordPress's native `wp-postpass_` cookie mechanism — the same system that WordPress itself uses for password protection. It doesn't bypass security; it automates the password entry step.

## Part of amplifi.plugins

amplifi.magic is part of the **amplifi.plugins** suite — a growing collection of AI-powered and utility WordPress tools by amplifi.studio.

All plugins share a unified admin under the "amplifi.studio" sidebar menu. Install one and discover the rest from the built-in Plugin Hub.

## Getting Started

1. **Download** the latest release from [GitHub Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip file and click **Activate**
4. Navigate to **amplifi.studio > Magic** in your admin sidebar
5. Select a password-protected page, create a named token, and share the link

## Open Source and Transparent

amplifi.magic is **MIT licensed**. The full source is on GitHub. Geolocation lookups use the free ip-api.com service. All token and usage data is stored in your WordPress database as post meta. Nothing is sent to external servers beyond the IP geolocation lookup.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

---

*amplifi.magic v1.0.0 is available now. If you've been sharing passwords in emails and Slack messages — [there's a better way](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).*

*Built by [amplifi.studio](https://amplifi.studio)*

---

## Internal Notes

### Suggested Internal Links
- amplifi.studio homepage
- amplifi.plugins GitHub repository
- amplifi.lockcache blog post (natural companion — caching for protected pages)
- Other amplifi plugin blog posts

### Suggested External Links
- WordPress password protection documentation
- ip-api.com (geolocation service)

### Content Distribution
- Publish on amplifi.studio/blog
- Share on LinkedIn (see companion LinkedIn post)
- Submit to WordPress communities (r/WordPress, WordPress Slack)
- Cross-post to Dev.to and Hashnode with canonical pointing to amplifi.studio

### Republishing Schedule
- Week 1: LinkedIn + blog publish
- Week 2: Dev.to cross-post
- Week 3: Hashnode cross-post
- Week 4: Reddit / WordPress community posts
