# amplifi.translate

Real-time AI translation of a WordPress site into language-prefixed URLs (`/es/`, `/fr/`, `/zh/`) using the Anthropic Claude Messages API. Post title, content, and excerpt are translated per post and cached in a custom table keyed by a content hash; everything else on the page — nav, header, footer, meta tags, form placeholders — is collected from the rendered HTML, batch-translated as short strings, and cached per language in `wp_options`. Language-prefixed URLs are real WordPress rewrite rules, not `REQUEST_URI` rewriting, so host caches key on them correctly.

## At a glance

| | |
|---|---|
| Feature slug | `translate` |
| Entry file | `plugins/amplifi-plugins/features/translate/ac-wp-translator.php` |
| Version constant | `ACWPT_VERSION` (`3.3.7`) |
| Framework slug | `ac-wp-translator` (menu label "Translate") |
| DB tables | `{$wpdb->prefix}acwpt_translations` |
| REST namespace | none — admin-AJAX only, plus one `parse_request` route for the sitemap |
| AI provider | Anthropic Claude (`https://api.anthropic.com/v1/messages`) |
| Default model | `claude-haiku-4-5` |
| Files / PHP LOC | 41 files, ~5,536 PHP LOC (~4,964 excluding `tests/`) |
**34** defined in `ACWPT_Languages::get_all()`
| Prompt packs | 8 per-language packs (`de`, `es`, `fr`, `it`, `ja`, `pl`, `pt`, `zh`) plus a base prompt |

### Loading

`translate` loads only when its slug is present in the `amplifi_plugins_enabled_features` option array, dispatched from `plugins/amplifi-plugins/amplifi-plugins.php`. The entry file guards on `ACWPT_VERSION`, requires eight includes, registers with the framework, and boots on `plugins_loaded` at **priority 1**:

```php
add_action( 'plugins_loaded', 'acwpt_init', 1 );

function acwpt_init() {
    ACWPT_Preloader::register();
    ACWPT_Frontend::instance()->init();
    if ( is_admin() ) {
        ACWPT_Admin::instance()->init();
    }
}
```

It also registers an `acwpt_languages` nav menu location on `after_setup_theme` priority 20, so Appearance → Menus is available even in block themes.

## Provider: this is Claude, not OpenAI

> **Correction to `CLAUDE.md`.** The repo-root `CLAUDE.md` section "Plugin: amplifi.translate (`plugins/ac-wp-translator/`)" is stale on two counts. It documents the obsolete per-plugin directory layout (the feature now lives at `plugins/amplifi-plugins/features/translate/`), and it states the translator uses the OpenAI API. It does not. The planned rewrite in `docs/superpowers/plans/2026-04-14-claude-translator-rewrite.md` and `docs/superpowers/specs/2026-04-14-claude-translator-rewrite-design.md` **landed**. Verified in the shipping code:

```php
// includes/class-acwpt-translator.php:155-177
private static function call_anthropic( $api_key, $model, $system, $user, $max_tokens = 8192, $timeout = 30 ) {
    $response = wp_remote_post(
        'https://api.anthropic.com/v1/messages',          // line 157
        array(
            'timeout' => $timeout,
            'headers' => array(
                'x-api-key'         => $api_key,           // line 161
                'anthropic-version' => '2023-06-01',       // line 162
                'content-type'      => 'application/json',
            ),
            …
```

Corroborating evidence in the shipping code:

- `includes/class-acwpt-translator.php:21` — `private static $default_model = 'claude-haiku-4-5';`
- `includes/class-acwpt-translator.php:13-19` — the pricing table contains only `claude-*` models.
- `includes/class-acwpt-translator.php:349` — `test_api_key()` also posts to `https://api.anthropic.com/v1/messages`.
- `includes/class-acwpt-admin.php:659` — the model picker fetches `https://api.anthropic.com/v1/models?limit=1000` with the same `x-api-key` / `anthropic-version` headers, and line 692 filters to IDs starting with `claude-`.
- `ac-wp-translator.php:30-47` — a `v2.0.0` upgrade routine whose comment reads "provider switched from OpenAI to Anthropic"; it blanks the stored `model`, deletes the cached models list, and sets a one-time admin notice.
- `ac-wp-translator.php:86-88` — that notice tells the user their existing OpenAI key will not work.

The `wp_options` key is still `acwpt_settings` with `api_key` and `model` members, as `CLAUDE.md` says — but the key is an Anthropic key and the model is a Claude model ID. There is no OpenAI code path left in the feature.

## Architecture

### Class map

| File | Class | Responsibility | Hooks |
|---|---|---|---|
| `ac-wp-translator.php` | — | Constants, `acwpt_asset_version()` (mtime cache-busting), `acwpt_maybe_upgrade()` migrations, the v2 admin notice, includes, framework registration, activation/deactivation. | `plugins_loaded` **priority 1** → `acwpt_init()`; `admin_init` → `acwpt_maybe_upgrade()`; `admin_notices` → v2 notice; `after_setup_theme` **priority 20** → `register_nav_menus()` |
| `includes/class-acwpt-frontend.php` | `ACWPT_Frontend` | The bulk of the feature (~1,399 LOC): rewrite rules, language detection, content filters, output buffer, hreflang, canonical, switcher shortcode, nav menu expansion, sitemap, cache invalidation. Singleton. | 25+ hooks, tabulated below |
| `includes/class-acwpt-translator.php` | `ACWPT_Translator` | `translate()` (title/content/excerpt), `translate_strings()` (batch JSON), `call_anthropic()`, `parse_response()`, usage accounting, `test_api_key()`. | — |
| `includes/class-acwpt-cache.php` | `ACWPT_Cache` | The translations table: `create_table()`, `drop_table()`, `get()`, `set()`, `delete_post()`, `delete_language()`, `flush_all()` (TRUNCATE), `stats()`. | — |
| `includes/class-acwpt-prompts.php` | `ACWPT_Prompts` | Assembles system prompts: base prompt + per-language nuance pack + custom section (never-translate, glossary, custom instructions) + output contract. `build_content_prompt()`, `build_strings_prompt()`. Pack dir overridable for tests. | — |
| `includes/class-acwpt-glossary.php` | `ACWPT_Glossary` | `<x-keep>` sentinel wrapping/stripping for never-translate terms, glossary sentinels carrying mandated translations, `entries_for_language()`, `format_prompt_block()`, `extract_first_json_object()`. | — |
| `includes/class-acwpt-languages.php` | `ACWPT_Languages` | 33 language definitions (`name`, `native`, `flag`), `bcp47()`, `get_enabled()`, `get_enabled_codes()`, `get_source()`, `label()`. | — |
| `includes/class-acwpt-preloader.php` | `ACWPT_Preloader` | Background pre-translation so visitors never trigger a live API call on first load. `start_all()`, `start_for_post()`, `process_batch()`, `tick()`, `stop()`. `BATCH_SIZE = 3`. | `acwpt_process_preload_batch` cron action |
| `includes/class-acwpt-admin.php` | `ACWPT_Admin` | One settings page, seven AJAX endpoints, the nav-menu meta box. | `admin_init`, `admin_enqueue_scripts`, seven `wp_ajax_acwpt_*`, `admin_head-nav-menus.php`, `wp_setup_nav_menu_item` |
| `includes/prompts/base-prompt.php` | — | Shared base system prompt. | — |
| `includes/prompts/lang/{de,es,fr,it,ja,pl,pt,zh}.php` | — | Per-language nuance packs. `_template.php` is the scaffold for adding more. | — |
| `tests/` | — | Standalone PHP unit scripts (no PHPUnit): `test_glossary`, `test_json_extract`, `test_parse_response`, `test_prompts`, `test_sentinels`, `test_live_anthropic`. Run with plain `php`; `bootstrap.php` stubs the WP functions they touch. | — |

### Frontend hooks and priorities

Registered in `ACWPT_Frontend::init()`:

| Hook | Priority | Args | Callback | Purpose |
|---|---|---|---|---|
| `query_vars` (filter) | 10 | 1 | `add_language_query_var` | Allows `acwpt_lang` |
| `rewrite_rules_array` (filter) | 10 | 1 | `add_language_rewrite_rules` | Injects a language-prefixed twin of every rule |
| `parse_request` (action) | **1** | 1 | `detect_language_from_query` | Reads `acwpt_lang`, sets `current_language`, unsets the var |
| `the_title` (filter) | **1** | 2 | `filter_title` | |
| `the_content` (filter) | **1** | 1 | `filter_content` | |
| `the_excerpt` (filter) | **1** | 1 | `filter_excerpt` | |
| `document_title_parts` (filter) | **1** | 1 | `filter_document_title` | Translates the `title` and `site` parts |
| `option_blogname` (filter) | 10 | 1 | `filter_blogname` | |
| `option_blogdescription` (filter) | 10 | 1 | `filter_blogdescription` | |
| `wp` (action) | 10 | 1 | `prepare_translations` | Fetch/warm post + string translations |
| `template_redirect` (action) | **0** | 1 | `start_output_buffer` | |
| `wp_head` (action) | **1** | 1 | `output_hreflang_tags` | |
| `language_attributes` (filter) | 10 | 1 | `filter_language_attributes` | Rewrites `lang="…"` to the BCP-47 code |
| `wp_nav_menu_objects` (filter) | 10 | 2 | `expand_language_menu_items` | |
| `nav_menu_link_attributes` (filter) | 10 | 4 | `add_lang_link_attributes` | Adds `data-acwpt-lang` |
| `wp_enqueue_scripts` (action) | 10 | 1 | `enqueue_assets` | `frontend.css`, optional `detect.js` |
| `save_post` (action) | 10 | 2 | `invalidate_post_cache` | |
| `update_option_blogname` / `update_option_blogdescription` | 10 | 1 | `clear_all_string_caches` | |
| `parse_request` (action) | 10 | 1 | `maybe_serve_sitemap` | |
| `robots_txt` (filter) | 10 | 2 | `add_sitemap_to_robots` | |
| `get_canonical_url` (filter) | 10 | 2 | `filter_canonical_url` | |
| `redirect_canonical` (filter) | 10 | 2 | `prevent_canonical_redirect` | |
| `send_headers` (action) | 10 | 1 | `send_translated_page_headers` | `X-ACWPT-Language` |
| `wp_footer` (action) | **99** | 1 | `debug_console_log` | |
| `elementor/frontend/the_content` (filter) | 10 | 1 | `translate_elementor_content` | Registered only when Elementor is present |
| `init` (action, conditional) | **999** | — | anonymous | Deferred `flush_rewrite_rules()` when `acwpt_flush_rules` is set |

`add_shortcode( 'acwpt_switcher', … )` is registered in the same method.

## URL language-prefix routing

Routing is done with genuine WordPress rewrite rules, not `REQUEST_URI` mutation. `add_language_rewrite_rules()` walks the existing rules array and, for each rule, adds a parallel rule with the language group prepended:

```php
$lang_group = '(' . implode( '|', array_map( 'preg_quote', $enabled ) ) . ')';

// Homepage rule added FIRST, before WP's catch-all pagename rule.
$new_rules[ '^' . $lang_group . '/?$' ] = 'index.php?acwpt_lang=$matches[1]';

foreach ( $rules as $regex => $redirect ) {
    // Shift all $matches[N] up by one — the lang group becomes $matches[1].
    $shifted = preg_replace_callback( '/\$matches\[(\d+)\]/', fn($m) => '$matches[' . ((int)$m[1] + 1) . ']', $redirect );
    $new_rules[ '^' . $lang_group . '/' . $stripped ] = $shifted . '&acwpt_lang=$matches[1]';
    $new_rules[ $regex ] = $redirect;   // original preserved
}
```

Two details matter. The homepage rule must come first, or WordPress's catch-all `(.?.+?)(?:/([0-9]+))?/?$` rule matches `/es/` as a page named `es` and 404s. And every `$matches[N]` in the original redirect target has to be shifted up by one, because the language group takes index 1.

`detect_language_from_query()` runs on `parse_request` priority 1, validates `acwpt_lang` against the enabled list, stores it on the singleton, and then **unsets the query var** so `WP_Query` never sees it as a public parameter.

Because `/es/about/` is a distinct real URL, host caches key on it correctly — `send_translated_page_headers()` only adds an informational `X-ACWPT-Language` header rather than suppressing caching. `prevent_canonical_redirect()` returns `false` on translated pages, without which WordPress's `redirect_canonical()` would 301 `/es/about/` straight back to `/about/`.

Rewrite rules are flushed on activation, and on settings save when the enabled-language set changed: the sanitizer sets the `acwpt_flush_rules` option and the frontend performs the flush on `init` priority 999, late enough that all post types and taxonomies are registered.

## Content filters and the output buffer

`prepare_translations()` (on `wp`) walks the queried object and, for non-singular views, every post in `$wp_query->posts`, calling `ensure_translation()` on each. That method:

1. Computes `content_hash = md5( post_title . '||' . post_content . '||' . post_excerpt . '||v' . custom_version )`.
2. Returns the cached row if `content_hash` matches.
3. Otherwise takes a `acwpt_lock_{post_id}_{lang}` transient (45s TTL) so concurrent requests don't all hit the API — a second in-flight request serves the source language rather than blocking.
4. Calls `ACWPT_Translator::translate()`, writes to the cache table, releases the lock.

`the_title` / `the_content` / `the_excerpt` at priority 1 then read from the in-memory `$this->translations[$post_id]` map. Running at priority 1 means the translated text is what every later filter (shortcodes, `wpautop`, embeds) sees. `filter_title` explicitly bails for `nav_menu_item` and `wp_navigation` post types, which are handled by the string pipeline instead.

Elementor bypasses `the_content` entirely, so when Elementor is loaded the feature also filters `elementor/frontend/the_content` through the same HTML-blob string translator.

### Full-page output-buffer translation

`start_output_buffer()` runs on `template_redirect` **priority 0** — before nearly everything — and installs `process_output_buffer()` as the callback. That callback, in order:

1. **Protect** the language switcher. Any `<a … data-acwpt-lang …>…</a>` is swapped for an `<!--ACWPT_PROT_n-->` placeholder so it is neither translated nor re-prefixed.
2. **Translate meta tags** — `description`, `og:title`, `og:description`, `og:site_name`, `twitter:title`, `twitter:description`. Uncached values are translated on the fly, one string per call, and written back into the string cache.
3. **Collect and translate visible strings** — `ensure_strings_cached_for_html()` then `translate_html_blob()`.
4. **Prefix internal links** with the language code.
5. **Fix `og:url`** to the translated URL.
6. **Restore** the protected switcher links.

`extract_translatable_strings_from_html()` pulls text from seven distinct patterns: `<a>` inner text; a fixed set of block/inline elements (`p`, `span`, `div`, `h1`–`h6`, `li`, `td`, `th`, `label`, `figcaption`, `button`, `strong`, `em`, `b`, `dt`, `dd`, `blockquote`, `cite`, `caption`); leading text before a child inline tag or `<br>`; trailing text after a closing inline tag; text after `<br>`; `placeholder="…"` attributes; and `<input type="submit" value="…">` in both attribute orders. Candidates shorter than 2 characters, purely numeric/punctuation, URL-looking, or containing `{}()[]<>` are dropped.

`translate_html_blob()` then re-runs the same five text patterns as `preg_replace_callback` passes, substituting from the string cache.

`prefix_internal_links()` rewrites `href="{home_url}/…"` to `href="{home_url}/{lang}/…"` with a negative lookahead excluding `wp-admin`, `wp-content`, `wp-includes`, `wp-json`, `wp-login`, `feed`, `xmlrpc`, `wp-cron`, and any already-prefixed language code. Bare and trailing-slash home URLs are handled separately.

## String batching

`ensure_strings_cached_for_html()` diffs extracted strings against the per-language cache, chunks the misses into batches of **40**, and issues one `translate_strings()` call per chunk. The wire format is a JSON object keyed by array index:

```json
{"0": "Read more", "1": "Contact us", "2": "Our services"}
```

Claude is asked to return the same shape with translated values (`max_tokens` 8192, 30s timeout). Results are mapped back by index; any index missing from the response falls back to the original string.

`prepare_string_translations()` handles the site-wide warm-up separately: site title, tagline, every nav menu item across all registered locations, up to 50 published page titles, and a hardcoded list of ~24 common theme strings (`Read more`, `Skip to content`, `Leave a comment`, and so on). It stamps `_populated_at` into the cache and skips all of those DB queries entirely for the next hour.

`save_string_cache()` caps the cache at **500 entries**, keeping the most recent via `array_slice($cache, -500, null, true)` and preserving `_populated_at` across trims. Storage is `update_option( 'acwpt_strings_' . $lang, $cache, false )` — non-autoloaded, one option per language.

## Database

### `{$wpdb->prefix}acwpt_translations`

Created by `ACWPT_Cache::create_table()` via `dbDelta`.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | |
| `post_id` | `BIGINT UNSIGNED NOT NULL` | |
| `language` | `VARCHAR(10) NOT NULL` | Language code |
| `translated_title` | `TEXT` | |
| `translated_content` | `LONGTEXT` | |
| `translated_excerpt` | `TEXT` | |
| `content_hash` | `VARCHAR(32) NOT NULL DEFAULT ''` | MD5 of source + `custom_version` |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |
| `updated_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY post_lang (post_id, language)`, `KEY language (language)`.

### Cache invalidation

Four distinct mechanisms:

| Trigger | Effect |
|---|---|
| `save_post` (skipping revisions and autosaves) | `ACWPT_Cache::delete_post( $post_id )`; if the post type is `page`, also `clear_all_string_caches()` (pages appear in nav menus and page lists, regular posts don't); `delete_transient('acwpt_sitemap_xml')`; queues a preload when `preload_auto` is on and the post is published |
| Source content edited without `save_post` firing | The `content_hash` mismatch in `ensure_translation()` causes a re-fetch on next view; the stale row is overwritten |
| Never-translate list, glossary, or custom instructions changed | The settings sanitizer increments `custom_version`, which is mixed into every `content_hash`, invalidating **all** cached translations for **all** posts and languages at once |
| Settings saved, or blogname/blogdescription updated | `clear_all_string_caches()` deletes `acwpt_strings_{code}` for every enabled language; `delete_transient('acwpt_sitemap_xml')` |

Two shipped migrations use the `custom_version` bump as a one-shot cache invalidator: `acwpt_v2b3_migrated` (a `parse_response()` fix that stopped trailing `===EXCERPT===` delimiters leaking into content) and `acwpt_v2b5_migrated` (a `&nbsp;` double-escaping fix). Manual flushes are available: `flush_all()` (TRUNCATE), `delete_language()`, `delete_post()`.

### Options

| Option | Autoload | Contents |
|---|---|---|
| `acwpt_settings` | default | Settings blob including the plaintext `api_key` |
| `acwpt_usage` | `false` | `{ 'YYYY-MM': {requests, prompt_tokens, completion_tokens, total_tokens, estimated_cost, content_translations, string_translations} }` |
| `acwpt_strings_{code}` | `false` | Per-language string cache, capped at 500 entries, with a `_populated_at` marker |
| `acwpt_db_version` | default | `2.0.0` after the provider migration |
| `acwpt_flush_rules` | default | Pending-flush flag |
| `acwpt_version` | default | |
| `acwpt_show_v2_notice` | default | One-shot flag for the provider-change admin notice |
| `acwpt_v2b3_migrated` / `acwpt_v2b5_migrated` | default | One-shot migration flags |
| `acwpt_preload_queue` | default | Array of `{post_id, language}` pairs |
| `acwpt_preload_status` | default | Progress counters and timestamps |

Settings keys: `api_key`, `source_language` (default `en`), `enabled_languages[]` (validated against `ACWPT_Languages::get_all()`), `show_flags`, `show_suggestion`, `model` (empty string means "use the default"), `preload_auto`, `never_translate[]`, `glossary[]`, `custom_instructions[]`, `custom_version`.

### Transients

| Transient | TTL | Purpose |
|---|---|---|
| `acwpt_models_list` | `HOUR_IN_SECONDS` | Claude model IDs from `/v1/models`; cleared when the API key changes |
| `acwpt_sitemap_xml` | `HOUR_IN_SECONDS` | Rendered multilingual sitemap |
| `acwpt_lock_{post_id}_{lang}` | 45s | Per-post translation in-flight lock |
| `acwpt_preload_lock` | 90s | Prevents concurrent preload batches |

No post meta is written by this feature.

## hreflang output

`output_hreflang_tags()` runs on `wp_head` priority 1 and **only on singular views** (`is_singular()`). For a post it emits:

```html
<link rel="alternate" hreflang="x-default" href="{original permalink}" />
<link rel="alternate" hreflang="{source bcp47}" href="{original permalink}" />
<link rel="alternate" hreflang="{lang bcp47}" href="{home}/{lang}{path}" />   <!-- one per enabled language -->
```

`x-default` and the source language both point at the untranslated permalink. Codes are normalised through `ACWPT_Languages::bcp47()`. Archives, the blog index, and the homepage get no hreflang tags.

Complementing this, `filter_canonical_url()` rewrites the canonical to the translated URL on translated pages, and `filter_language_attributes()` rewrites the `<html lang="…">` attribute.

## Multilingual sitemap

`maybe_serve_sitemap()` hooks `parse_request` and matches `$wp->request === 'acwpt-sitemap.xml'` exactly. It is not a rewrite rule and not a REST route.

- Returns without acting (letting WordPress 404 normally) when no languages are enabled.
- Serves the `acwpt_sitemap_xml` transient when warm; otherwise generates and caches for an hour.
- Sends `status_header(200)`, `Content-Type: application/xml; charset=UTF-8`, echoes, and `exit`s.

`generate_sitemap_xml()` queries **all** published `page` and `post` entries (`posts_per_page => -1`, ordered by modified date) and emits a `<url>` entry for every post × language combination, each carrying `<xhtml:link>` alternates for all languages plus `x-default` pointing at the source-language URL.

`add_sitemap_to_robots()` appends `Sitemap: {home}/acwpt-sitemap.xml` to `robots.txt` when the site is public and languages are enabled. The transient is invalidated on `save_post` and on settings save.

## AI provider

**Anthropic Claude.** Endpoint, headers, and proof are in the "Provider" section above (`includes/class-acwpt-translator.php:157`, `161`, `162`).

- **Default model:** `claude-haiku-4-5`. The admin UI describes it as recommended for cost-effective translation. An empty `model` setting falls through to the default.
- **Model discovery:** `ajax_fetch_models` calls `GET https://api.anthropic.com/v1/models?limit=1000`, filters to IDs beginning with `claude-`, sorts, and caches for one hour in `acwpt_models_list`. The list is genuinely fetched from the user's Anthropic account, so newer models appear without a plugin update.
- **Key storage:** plaintext inside `acwpt_settings`, sanitized with `sanitize_text_field()`. Not encrypted at rest.
- **Request shape:** `temperature: 0.3`, a top-level `system` prompt, and a single user message. Content translation uses `max_tokens: 16000` with a 60s timeout; string batches use `max_tokens: 8192` with a 30s timeout.
- **Content wire format:** the user message is delimiter-separated —

  ```
  ===TITLE===
  {title}

  ===CONTENT===
  {content}

  ===EXCERPT===
  {excerpt}     ← only when the source has one
  ```

  `parse_response()` splits on those delimiters, always looking for `===EXCERPT===` even when the source had none (Claude sometimes emits it anyway) and discarding it if unexpected. It then scrubs stray `=== … ===` tokens and normalises `&nbsp;` entities to a real U+00A0, because themes that run titles through `esc_html()` would otherwise render `&amp;nbsp;` as literal text. If both title and content come back empty, the whole response is treated as content.
- **Glossary sentinels:** before sending, never-translate terms are wrapped in `<x-keep>…</x-keep>` and glossary terms in sentinels carrying the mandated translation. Both are stripped from the response — glossary first (replacing with the mandated translation), then never-translate. Wrapping is done via a two-pass token swap so a longer earlier term can't cause a shorter term to be wrapped inside an existing sentinel.

### Spend caps

**There are none.** No per-day cap, no per-month cap, no request-rate limiter, no pre-flight cost estimate. Spend tracking is retrospective only: `record_usage()` accumulates into `acwpt_usage` keyed by `gmdate('Y-m')` with `requests`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `estimated_cost`, `content_translations`, and `string_translations`. The legacy `prompt_tokens`/`completion_tokens` key names are kept for back-compat with the OpenAI-era schema even though the values now come from Anthropic's `input_tokens`/`output_tokens`.

`estimated_cost` is derived from a hardcoded per-token pricing table (`class-acwpt-translator.php:13-19`), with unknown models falling back to Sonnet pricing:

| Model | Input (per token) | Output (per token) |
|---|---|---|
| `claude-haiku-4-5` | 0.000001 | 0.000005 |
| `claude-sonnet-4-5` | 0.000003 | 0.000015 |
| `claude-sonnet-4-6` | 0.000003 | 0.000015 |
| `claude-opus-4-5` | 0.000015 | 0.000075 |
| `claude-opus-4-6` | 0.000015 | 0.000075 |

The code comment instructs verifying these against Anthropic's published rates before each release. The only structural cost controls are the caching layers themselves: the translations table, the 500-entry string cache, the one-hour `_populated_at` skip, and the 45-second per-post lock.

## Preloader

`ACWPT_Preloader` exists so visitors never trigger a live Claude call on first load.

- `start_all()` builds a queue of every published post × every enabled language whose cached `content_hash` doesn't match, stored in `acwpt_preload_queue`.
- `start_for_post( $post_id )` queues one post across all languages; called from `invalidate_post_cache()` when `preload_auto` is enabled and the post is published.
- `process_batch()` runs on the `acwpt_process_preload_batch` cron hook, takes the `acwpt_preload_lock` transient (90s), processes `BATCH_SIZE = 3` pairs, releases the lock, and schedules the next single event if work remains.
- When the site has `DISABLE_WP_CRON`, `schedule_next()` also pokes `wp-cron.php` directly with a `doing_wp_cron` query arg.
- `stop()` clears the scheduled hook, the queue, and the lock.

## Admin UI and AJAX

One settings page, registered through the framework as `amplifi_register_plugin('ac-wp-translator', 'Translate', …)`.

| Screen | Page slug | Hook suffix |
|---|---|---|
| Translate | `amplifi-ac-wp-translator` | `amplifi-studio_page_amplifi-ac-wp-translator` |

`enqueue_assets()` gates on an exact string match of that hook suffix, then loads `assets/css/admin.css` and `assets/js/admin.js` with mtime-based cache busting, localizing `acwptAdmin = { nonce, ajaxurl }`.

Settings are registered with `register_setting( 'acwpt_settings_group', 'acwpt_settings', ['sanitize_callback' => 'sanitize_settings'] )` — the standard Settings API nonce and `manage_options` gate apply to the form POST.

| AJAX action | Nonce | Capability | Purpose |
|---|---|---|---|
| `acwpt_test_api_key` | `acwpt_admin` (as `nonce`) | `manage_options` | Minimal 16-token Messages call to validate the key |
| `acwpt_flush_cache` | `acwpt_admin` | `manage_options` | Truncate the translations table |
| `acwpt_fetch_models` | `acwpt_admin` | `manage_options` | Fetch + cache the Claude model list |
| `acwpt_preload_start` | `acwpt_admin` | `manage_options` | Build the preload queue |
| `acwpt_preload_status` | `acwpt_admin` | `manage_options` | Progress poll |
| `acwpt_preload_tick` | `acwpt_admin` | `manage_options` | Process one batch synchronously |
| `acwpt_preload_stop` | `acwpt_admin` | `manage_options` | Clear queue, lock, and schedule |

All seven call `check_ajax_referer( 'acwpt_admin', 'nonce' )` **before** the capability check. There are no `nopriv` variants.

A nav-menu meta box (`admin_head-nav-menus.php`) lets the language switcher be added to any menu; `wp_setup_nav_menu_item` relabels those items, `wp_nav_menu_objects` expands them into per-language entries, and `nav_menu_link_attributes` stamps `data-acwpt-lang` — the attribute the output buffer uses to protect them.

## Pitfalls

- **The API key is stored in plaintext** in `acwpt_settings`. No encryption at rest, unlike amplifi.optimize.
- **No spend cap of any kind.** A `preload_start` on a large site, or a crawler walking `/es/`, `/fr/`, `/zh/` across every URL, issues unbounded Claude calls. `acwpt_usage` tells you what you spent, after you spent it. Enabling many languages multiplies the surface: the preload queue is posts × languages.
- **Uncached page loads block on the API.** `prepare_translations()` runs synchronously on `wp`, and content calls use a 60-second timeout. A cold cache means the visitor waits. The 45-second lock limits the damage to one slow request rather than a thundering herd, but the second visitor silently gets untranslated content instead.
- **Rewrite-rule doubling.** Every enabled language adds a parallel copy of the entire rewrite array. On a site with many custom post types and taxonomies plus several languages, the rules array grows multiplicatively, which slows `parse_request` and inflates the `rewrite_rules` option.
- **`$matches[N]` shifting is fragile.** `add_language_rewrite_rules()` rewrites capture-group indices with a regex. A plugin whose rewrite target uses an unusual format, or that hooks `rewrite_rules_array` at a later priority and re-adds rules, can end up with mis-indexed rules that route to the wrong query.
- **HTML translation is regex-based, not DOM-based.** Text is matched with `preg_replace_callback` against a fixed element list, and substitution uses `str_replace($text, $translated, $raw)` — so a repeated short string inside the same match is replaced everywhere it occurs in that fragment. Text in elements outside the list, or split across nested inline tags in ways the five patterns don't cover, is left untranslated. Attributes other than `placeholder` and submit `value` are not touched.
- **The 500-entry string cache silently evicts.** On a large site, `array_slice($cache, -500, null, true)` drops the oldest entries — which are then re-translated on the next page that needs them, at cost. `_populated_at` is preserved across trims, so the hourly skip can leave the cache trimmed and stale simultaneously.
- **`custom_version` is a nuclear invalidator.** Editing one glossary row, one never-translate term, or any custom instruction increments it, changing every `content_hash` on the site and forcing a full re-translation of every post in every language on next view. Batch glossary edits.
- **The sitemap loads every post at once.** `posts_per_page => -1` over all published pages and posts, then N language entries each. On a large site this is a memory risk on the one uncached request per hour that regenerates it.
- **The `/acwpt-sitemap.xml` match is exact-string.** `$wp->request !== 'acwpt-sitemap.xml'` means a trailing slash, a subdirectory install path difference, or any query-string variant won't match, while `add_sitemap_to_robots()` advertises the URL unconditionally.
- **hreflang is singular-only.** Archives, category pages, the blog index, and the homepage emit no `hreflang` alternates even though translated versions of those URLs exist and are linked from the switcher.
- **`prevent_canonical_redirect()` disables canonical redirects entirely** on translated pages, returning a hard `false`. That also disables WordPress's legitimate canonical corrections (missing trailing slash, wrong case, ID-to-slug) on those URLs.
- **Output buffering starts at `template_redirect` priority 0** and holds the entire page in memory for post-processing. It interacts badly with plugins that stream output or start their own buffer earlier, and any fatal error after the buffer opens can result in a truncated or blank page.
- **String translations are context-free.** `translate_strings()` sends a JSON map of bare strings with no surrounding markup, so a word like "Close" or "Post" is translated without knowing whether it's a verb or a noun. Per-language prompt packs and the glossary are the intended mitigation.
- **`CLAUDE.md` is stale about this feature** on both the directory layout and the provider. Trust the code; see the Provider section above. It also documents "Dynamic Models: fetches from OpenAI `/v1/models`" — the mechanism survived the rewrite, but it now queries Anthropic.
- **`uninstall.php` is unconditional and incomplete.** It drops the translations table and deletes `acwpt_settings` and `acwpt_flush_rules` — but leaves `acwpt_usage`, every `acwpt_strings_{code}` option, `acwpt_db_version`, `acwpt_version`, `acwpt_preload_queue`, `acwpt_preload_status`, and the three one-shot migration flags orphaned in the options table.
- **Recent history is suite-level.** `git log --oneline -20 -- plugins/amplifi-plugins/features/translate/` returns only monorepo-wide version bumps and unrelated `consent:` commits (`4098218`, `d2b8773`, `1865c12`, `9230115`, `47e1e40`, `8f39726`, `811144e`, …). Feature-scoped history is not separable at this path; the in-file `acwpt_maybe_upgrade()` migration blocks are the most reliable changelog the feature has.
