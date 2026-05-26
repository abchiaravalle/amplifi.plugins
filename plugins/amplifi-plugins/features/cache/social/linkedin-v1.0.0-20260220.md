# LinkedIn Post — amplifi.lockcache v1.0.0 Launch
# Date: 2026-02-20
# Type: Product launch / Open source announcement
# Target: WordPress developers, agency owners, anyone using password-protected pages
# Goal: Awareness, GitHub stars, installs
# Best practices: Hook in first 2 lines (before "see more"), use line breaks for scannability,
#   numbers/stats for credibility, single CTA, 3-5 hashtags max, no links in body (comment instead)

---

Most WordPress caching plugins completely ignore password-protected pages.

We just open-sourced a plugin that fixes that.

It's called amplifi.lockcache.

Here's the problem:

WP Super Cache, W3 Total Cache, WP Rocket — they all skip password-protected pages. It makes sense from a security standpoint. But it means every visit to a protected page hits your full PHP stack and database.

For sites that rely on protected content — client portals, gated resources, internal docs — this adds up fast.

amplifi.lockcache generates static HTML cache files for protected pages after a visitor unlocks them.

How it works:
1. Visitor enters the password (or uses a magic link)
2. First load renders normally and generates a cache file
3. Every subsequent visit serves the cached HTML instantly
4. No PHP, no database, no template rendering

The result:
- Response time drops from 200-800ms to 10-50ms
- Database queries go from 20-50+ to zero
- Server load drops significantly

What makes it safe:
- Only caches after authentication
- Admin users are never cached (no admin bar in cache files)
- Cache directory protected by .htaccess (deny all)
- Files set to 0600 permissions
- Validates password cookie before serving

Works perfectly with amplifi.magic (our magic links plugin).
One click to unlock. Instant loads from there.

Zero configuration. Activate and it works.

Free, MIT licensed, and part of the amplifi.plugins suite.

Link in comments.

#WordPress #OpenSource #Performance #WebDev #Caching

---

## Comment (post immediately after publishing):

GitHub: https://github.com/abchiaravalle/amplifi.plugins
Download: https://github.com/abchiaravalle/amplifi.plugins/releases/latest

Built by amplifi.studio — https://amplifi.studio
