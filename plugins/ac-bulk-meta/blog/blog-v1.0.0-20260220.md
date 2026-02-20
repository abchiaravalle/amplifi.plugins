# Blog Post — amplifi.meta v1.0.0 Launch
# Date: 2026-02-20
# Version: 1.0.0
# Author: Adam Chiaravalle / amplifi.studio

---

## SEO Metadata

| Field | Value |
|-------|-------|
| **Title Tag** | amplifi.meta: Free AI-Powered Bulk SEO Meta Editor for WordPress (Open Source) |
| **Meta Description** | Generate SEO titles, meta descriptions, focus keyphrases, and FAQ schema for every page on your WordPress site with AI. Free, open-source plugin using OpenAI. |
| **URL Slug** | /blog/amplifi-meta-ai-bulk-seo-wordpress-plugin |
| **Canonical** | https://amplifi.studio/blog/amplifi-meta-ai-bulk-seo-wordpress-plugin |
| **OG Title** | Bulk-Generate SEO Meta for Your Entire WordPress Site with AI |
| **OG Description** | amplifi.meta is a free, open-source WordPress plugin that uses OpenAI to generate title tags, meta descriptions, focus keyphrases, FAQs, and JSON-LD structured data — in bulk. |
| **OG Image** | amplifi.meta admin dashboard screenshot (1200x630 crop) |
| **OG Type** | article |
| **Twitter Card** | summary_large_image |
| **Focus Keyphrase** | AI bulk SEO WordPress plugin |
| **Secondary Keywords** | AI meta description generator, bulk SEO editor WordPress, WordPress FAQ schema plugin, JSON-LD WordPress plugin, OpenAI SEO tool, free SEO plugin |
| **Schema Type** | Article / BlogPosting |
| **Author** | Adam Chiaravalle |
| **Publisher** | amplifi.studio |
| **Category** | WordPress, Open Source, SEO, AI Tools |
| **Tags** | WordPress, SEO, OpenAI, GPT, meta descriptions, title tags, FAQ schema, JSON-LD, structured data, bulk editor, open source, AI, plugin |

---

## Article

# How to Bulk-Generate SEO Meta for Your Entire WordPress Site with AI

Writing meta descriptions is one of those tasks that everyone knows matters and nobody wants to do.

For a 10-page site, it's manageable. For a 200-page site with years of blog posts, product pages, and landing pages? It's a full day of work — at minimum. And then you need focus keyphrases. And FAQ schema. And structured data.

We built **amplifi.meta** because AI can now do this in minutes, for pennies, and the results are genuinely good.

## What is amplifi.meta?

amplifi.meta is a **free, open-source WordPress plugin** that uses OpenAI to generate SEO metadata in bulk. It integrates directly with Yoast SEO and gives you a spreadsheet-style editor for managing title tags, meta descriptions, and focus keyphrases across your entire site.

But it doesn't stop at meta tags. It also generates **FAQ sections** with accordion displays and deploys **JSON-LD structured data** — both Organization schema and per-post Article/FAQ schema — directly into your page markup.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

## The Problem with Manual SEO Meta

If you've ever audited a WordPress site's SEO, you've seen the pattern:

- Half the pages have no meta description at all
- The other half have descriptions that were copied from the first paragraph
- Focus keyphrases are either missing or the same generic term on every page
- Structured data doesn't exist
- FAQ pages have no schema markup, so Google can't display them as rich results

Fixing this manually means opening every post, reading the content, crafting a unique description, researching a keyphrase, and saving. Multiply by 200 posts. It's tedious, it's error-prone, and it's exactly the kind of work that AI excels at.

## How It Works

### Bulk Meta Editor

The main interface shows all your posts and pages in a sortable, filterable table. For each item you can see the current title tag, meta description, and focus keyphrase — or the lack thereof.

Select the posts you want to generate meta for, click a button, and AI writes unique, SEO-optimized metadata for each one. The results integrate directly with Yoast SEO's post meta fields.

You can also set a **global prompt** to guide the AI's tone and style. Tell it to write in a professional voice, keep descriptions under 155 characters, or focus on specific themes. The prompt applies to every generation.

### FAQ Generation

Select any post and generate contextually relevant FAQs based on the content. The plugin:

1. Reads the post content
2. Generates questions and answers that a reader would naturally ask
3. Stores them in a dedicated database table
4. Deploys them as an accordion or expanded section on the post
5. Outputs proper `FAQPage` JSON-LD schema so Google can display rich results

You control the number of FAQs per post and can set a focus topic to guide the questions. FAQs can be deployed globally or per-post.

### JSON-LD Structured Data

The JSON-LD module handles two types of schema:

- **Organization schema**: Site-wide structured data with your business name, logo, contact info, and social profiles
- **Per-post schema**: Article or BlogPosting schema with author, date, headline, and description — automatically generated from your post metadata

All schema is output as clean JSON-LD in the `<head>` of your pages, exactly how Google recommends.

## What Does It Cost?

The plugin is free. You pay only for OpenAI API usage.

Generating a meta description for a single post costs approximately **$0.001-0.003** depending on content length and model choice. FAQ generation costs slightly more since it processes the full post content.

| Task | Per Post Cost | 100 Posts |
|------|--------------|-----------|
| Meta description | ~$0.002 | ~$0.20 |
| Title tag | ~$0.001 | ~$0.10 |
| Focus keyphrase | ~$0.001 | ~$0.10 |
| FAQ (5 questions) | ~$0.005 | ~$0.50 |
| **All of the above** | **~$0.009** | **~$0.90** |

Full SEO metadata for 100 posts — titles, descriptions, keyphrases, and FAQs — for under a dollar. Compare that to hiring an SEO consultant or spending a full day writing them yourself.

## Features at a Glance

- **Bulk meta editor** with sortable, filterable post table
- **AI-generated title tags** optimized for click-through rate
- **AI-generated meta descriptions** unique to each post's content
- **AI-generated focus keyphrases** based on content analysis
- **FAQ generation** with customizable count and focus topic
- **FAQ display modes**: Accordion (click to expand) or always-expanded
- **FAQPage JSON-LD schema** for Google rich results
- **Organization JSON-LD** for site-wide structured data
- **Per-post Article/BlogPosting schema**
- **Yoast SEO integration** — reads and writes Yoast meta fields directly
- **Dark mode** for late-night SEO sessions
- **Global AI prompt** to control tone and style
- **API usage tracking** with webhook logging support
- **Any OpenAI model** — dynamically fetches your available models

## How It Compares

| Feature | amplifi.meta | Yoast Premium | Rank Math Pro | All in One SEO Pro |
|---------|-------------|---------------|---------------|-------------------|
| **Price** | Free (+ ~$0.002/post API) | $99/year | $59-599/year | $49-299/year |
| **AI Meta Generation** | Yes (bulk) | Yes (single post) | Yes (single post) | Yes (single post) |
| **Bulk Editor** | Yes | Limited | Yes | Yes |
| **FAQ Schema** | Yes (AI-generated) | Manual only | Manual only | Manual only |
| **JSON-LD** | Yes (auto) | Yes | Yes | Yes |
| **Open Source** | Yes (MIT) | No | No | No |

The key differentiator: amplifi.meta generates meta in **bulk** with AI, while premium SEO plugins either don't offer AI generation or only support one post at a time. And it's free.

## Part of amplifi.plugins

amplifi.meta is part of the **amplifi.plugins** suite — a growing collection of AI-powered WordPress tools by amplifi.studio.

All plugins share a unified admin under the "amplifi.studio" sidebar menu. Install one and discover the rest from the built-in Plugin Hub.

## Getting Started

1. **Download** the latest release from [GitHub Releases](https://github.com/abchiaravalle/amplifi.plugins/releases/latest)
2. In WordPress admin, go to **Plugins > Add Plugin > Upload Plugin**
3. Upload the zip file and click **Activate**
4. Navigate to **amplifi.studio > Meta** in your admin sidebar
5. Enter your OpenAI API key
6. Select posts and click generate — your SEO metadata is done

## Open Source and Transparent

amplifi.meta is **MIT licensed**. The full source is on GitHub. Your content is sent to OpenAI for generation and the results are stored in your database. Nothing else leaves your server.

**GitHub:** [github.com/abchiaravalle/amplifi.plugins](https://github.com/abchiaravalle/amplifi.plugins)

---

*amplifi.meta v1.0.0 is available now. If your site has dozens or hundreds of posts with missing or weak SEO metadata — [fix them all in minutes](https://github.com/abchiaravalle/amplifi.plugins/releases/latest).*

*Built by [amplifi.studio](https://amplifi.studio)*

---

## Internal Notes

### Suggested Internal Links
- amplifi.studio homepage
- amplifi.plugins GitHub repository
- amplifi.translate blog post (cross-link)
- Future: other amplifi plugin blog posts

### Suggested External Links
- OpenAI API pricing page
- Yoast SEO plugin page
- Google's structured data documentation
- Google's FAQ rich results guide

### Content Distribution
- Publish on amplifi.studio/blog
- Share on LinkedIn (see companion LinkedIn post)
- Submit to SEO communities (r/SEO, r/WordPress, WordPress Slack)
- Cross-post to Dev.to and Hashnode with canonical pointing to amplifi.studio

### Republishing Schedule
- Week 1: LinkedIn + blog publish
- Week 2: Dev.to cross-post
- Week 3: Hashnode cross-post
- Week 4: Reddit / SEO + WordPress community posts
