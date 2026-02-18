# CLAUDE.md - AC WP Translator

## Project Overview
WordPress plugin that translates site content in real-time using OpenAI (GPT-4o Mini). Serves translated pages at URL-prefixed paths (`/es/`, `/fr/`, `/zh/`) with database-backed caching.

## Architecture
- **URL Detection**: Hooks into `plugins_loaded` to detect language prefix from `$_SERVER['REQUEST_URI']`, strips the prefix so WordPress resolves the original post/page normally
- **Content Filters**: `the_title`, `the_content`, `the_excerpt` filters at priority 1 swap in translated content before any other processing (wpautop, do_blocks, do_shortcode)
- **Full Page Translation**: Output buffer captures entire HTML, translates nav/header/footer text, meta tags, and prefixes internal links with the language code
- **String Translation**: Site-wide strings (menu items, page titles, site title/tagline, common theme strings) are batch-translated in a single API call and cached in `wp_options` per language (`acwpt_strings_{lang}`)
- **Translation**: Raw post content (with block markup and shortcodes intact) is sent to OpenAI. The AI preserves HTML/shortcodes and only translates text
- **Caching**: Custom DB table `{prefix}_acwpt_translations` keyed by `post_id + language`. Content hash (md5) detects when posts are updated
- **Cache Invalidation**: `save_post` hook deletes all cached translations for the updated post
- **Usage Tracking**: Token counts and estimated costs recorded per month in `wp_options` key `acwpt_usage`, displayed in admin dashboard

## Key Files
- `ac-wp-translator.php` - Bootstrap, activation/deactivation hooks, nav menu location registration
- `includes/class-acwpt-frontend.php` - Core logic: URL routing, content filters, output buffer, shortcode, nav menu switcher, hreflang, language detection
- `includes/class-acwpt-translator.php` - OpenAI API calls, response parsing, usage tracking
- `includes/class-acwpt-cache.php` - Database CRUD operations
- `includes/class-acwpt-admin.php` - Settings page, nav menu meta box, AJAX handlers, usage dashboard
- `includes/class-acwpt-languages.php` - Language definitions (32 languages with native names and flag emojis)

## Language Switcher
Two methods available:
1. **Nav Menu Item**: Added via Appearance > Menus meta box. Uses `wp_nav_menu_objects` filter to expand a `#acwpt-language-switcher` placeholder into dynamic language items. Links are protected from output buffer re-prefixing via `data-acwpt-lang` attribute.
2. **Shortcode**: `[acwpt_switcher]` renders a `<select>` dropdown.

## Settings
Stored as serialized array in `wp_options` key `acwpt_settings`:
- `api_key`, `source_language`, `enabled_languages[]`, `show_flags`, `show_suggestion`, `model`

## Development
```bash
docker-compose up -d    # WordPress on :8085, MySQL on :3310
```
Plugin dir is volume-mounted so edits are live.

## Testing Notes
- Pretty permalinks MUST be enabled (not "Plain")
- First visit to a translated URL is slow (API call). Subsequent visits are cached
- The `[acwpt_switcher]` shortcode must be in post content or template, not in block editor's "Shortcode" block (use Custom HTML block instead)
- For nav menu switcher in block themes: use the Classic Menu block in the Site Editor, or call `wp_nav_menu()` with the `acwpt_languages` theme location
- Output buffer protects `data-acwpt-lang` links from being translated or re-prefixed
