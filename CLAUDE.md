# CLAUDE.md - AC WP Translator

## Project Overview
WordPress plugin that translates site content in real-time using OpenAI (GPT-4o Mini). Serves translated pages at URL-prefixed paths (`/es/`, `/fr/`, `/zh/`) with database-backed caching.

## Architecture
- **URL Detection**: Hooks into `plugins_loaded` to detect language prefix from `$_SERVER['REQUEST_URI']`, strips the prefix so WordPress resolves the original post/page normally
- **Content Filters**: `the_title`, `the_content`, `the_excerpt` filters at priority 1 swap in translated content before any other processing (wpautop, do_blocks, do_shortcode)
- **Translation**: Raw post content (with block markup and shortcodes intact) is sent to OpenAI. The AI preserves HTML/shortcodes and only translates text
- **Caching**: Custom DB table `{prefix}_acwpt_translations` keyed by `post_id + language`. Content hash (md5) detects when posts are updated
- **Cache Invalidation**: `save_post` hook deletes all cached translations for the updated post

## Key Files
- `ac-wp-translator.php` - Bootstrap, activation/deactivation hooks
- `includes/class-acwpt-frontend.php` - Core logic: URL routing, content filters, shortcode, hreflang, language detection JS
- `includes/class-acwpt-translator.php` - OpenAI API calls with delimiter-based response parsing
- `includes/class-acwpt-cache.php` - Database CRUD operations
- `includes/class-acwpt-admin.php` - Settings page (Settings API + AJAX for key test/cache flush)
- `includes/class-acwpt-languages.php` - Language definitions (32 languages with native names and flag emojis)

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
- Translation of menu items and widgets is intentionally skipped (only post/page content is translated)
