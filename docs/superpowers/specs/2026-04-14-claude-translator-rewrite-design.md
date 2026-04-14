# amplifi.translate — Claude Rewrite (v2.0.0)

**Date:** 2026-04-14
**Plugin:** `plugins/ac-wp-translator/`
**Status:** Approved design, ready for implementation plan

## Goal

Migrate `amplifi.translate` from OpenAI to Anthropic Claude, rewrite the translation prompts to produce output that reads as if a native B2B speaker wrote it, and add user-controlled custom instructions (never-translate list + glossary).

## Non-Goals

- No proxy server, license gating, or bundled API key. Bring-your-own-key (BYOK) Anthropic.
- No changes to the URL routing, content filters, output buffering, sitemap, or hreflang logic.
- No changes to the cache table schema.
- No new languages added to `class-acwpt-languages.php` (still 33 supported).

---

## 1. Provider Swap (OpenAI → Anthropic)

### API mechanics

| Concern | OpenAI (current) | Anthropic (new) |
|---|---|---|
| Endpoint | `https://api.openai.com/v1/chat/completions` | `https://api.anthropic.com/v1/messages` |
| Auth | `Authorization: Bearer <key>` | `x-api-key: <key>` + `anthropic-version: 2023-06-01` |
| System prompt location | `messages[0]` with `role: system` | Top-level `system` field |
| `max_tokens` | Optional | **Required** |
| Response text path | `choices[0].message.content` | `content[0].text` |
| Usage path | `usage.prompt_tokens` / `completion_tokens` | `usage.input_tokens` / `output_tokens` |
| JSON-only mode | `response_format: { type: 'json_object' }` | No native param — instruct in prompt + extract first balanced `{...}` from response |
| Models list endpoint | `/v1/models` | `/v1/models` (same shape, different IDs) |

### Pricing table (replace `$pricing` in `class-acwpt-translator.php`)

Per-token rates (input / output). Unknown models fall back to Sonnet pricing.

| Model | Input (per token) | Output (per token) |
|---|---|---|
| `claude-haiku-4-5` | 0.000001 | 0.000005 |
| `claude-sonnet-4-5` | 0.000003 | 0.000015 |
| `claude-sonnet-4-6` | 0.000003 | 0.000015 |
| `claude-opus-4-5` | 0.000015 | 0.000075 |
| `claude-opus-4-6` | 0.000015 | 0.000075 |

(Verify exact published rates at implementation time.)

### Model dropdown

`ajax_fetch_models` calls `https://api.anthropic.com/v1/models`, filters to IDs starting with `claude-`, caches in `acwpt_models_list` transient for 1 hour. Default model on fresh install: `claude-haiku-4-5` (cheapest, fast, plenty good for translation cache misses).

### JSON extraction (string translation)

Anthropic has no `response_format`. The string-translation prompt explicitly says:
> "Return ONLY a JSON object. No prose, no code fences, no commentary. The first character of your response must be `{` and the last must be `}`."

Parser uses a brace-balancing scan to extract the first complete JSON object, tolerating any leading/trailing text the model leaks anyway.

### Breaking change handling

Bump `Version: 2.0.0` in plugin header. Add an upgrade routine triggered by version comparison in the bootstrap:

```php
if ( version_compare( get_option( 'acwpt_db_version', '1.0' ), '2.0.0', '<' ) ) {
    $settings = get_option( 'acwpt_settings', array() );
    $settings['model'] = ''; // force re-pick on settings page
    update_option( 'acwpt_settings', $settings );
    delete_transient( 'acwpt_models_list' );
    update_option( 'acwpt_db_version', '2.0.0' );
}
```

The `api_key` field is preserved (label changes to "Anthropic API Key"). Existing OpenAI keys will fail `test_api_key()` and the user re-enters.

---

## 2. Prompt Architecture

### Directory layout

```
plugins/ac-wp-translator/includes/
├── class-acwpt-prompts.php          # Assembler
└── prompts/
    ├── base-prompt.php              # Shared system prompt
    └── lang/
        ├── pl.php                   # Polish
        ├── es.php                   # Spanish
        ├── fr.php                   # French
        ├── de.php                   # German
        ├── pt.php                   # Portuguese (Brazilian-leaning for B2B)
        ├── it.php                   # Italian
        ├── ja.php                   # Japanese
        └── zh.php                   # Simplified Chinese
```

### Assembler (`class-acwpt-prompts.php`)

Public API:

```php
ACWPT_Prompts::build_content_prompt( string $language_code, array $custom ): string
ACWPT_Prompts::build_strings_prompt( string $language_code, array $custom ): string
```

Where `$custom = [ 'never_translate' => [...], 'glossary' => [...] ]`.

Falls back to a generic B2B prompt when no language pack exists for the target language.

### Prompt assembly order

1. Base rules (HTML/shortcode/block-comment preservation, delimiter format, no AI tells, B2B tone)
2. Target language identification + register guidance (from language pack)
3. B2B terminology preferences (from language pack)
4. Nuance list — ~100 short rules (from language pack)
5. "Avoid" patterns — phrasings that scream machine translation (from language pack)
6. Few-shot examples — before/after pairs (from language pack)
7. Custom glossary block — only entries with non-empty translation for this language
8. Never-translate reminder — "Anything inside `<x-keep>...</x-keep>` must appear verbatim in your output."
9. Output format reminder (delimiter contract for content; JSON-only contract for strings)

### Language pack file shape

Each `lang/<code>.php` returns an array:

```php
return array(
    'name'            => 'Polish',
    'register'        => 'In B2B copy, use formal address (Pan/Pani, plural Państwo). Avoid casual ty/wy. Default to passive constructions for corporate tone where idiomatic.',
    'b2b_terminology' => array(
        'solution'    => 'rozwiązanie',
        'platform'    => 'platforma',
        'workflow'    => 'proces' /* not "workflow" calque */,
        // ...
    ),
    'nuances' => array(
        'Use Polish quotation marks „…" not English "…".',
        'Capitalize only the first word of headlines, not every word (no English title case).',
        'Decline company-name dependents correctly: "z firmą Acme" not "z firma Acme".',
        // ... ~100 entries
    ),
    'avoid' => array(
        'Direct calques like "biznesowe rozwiązanie" — prefer "rozwiązanie dla firm".',
        'Translating "Learn more" as "Naucz się więcej" — use "Dowiedz się więcej".',
        // ...
    ),
    'examples' => array(
        array( 'en' => 'Get started today.',         'pl' => 'Zacznij już dziś.' ),
        array( 'en' => 'Trusted by 500+ companies.', 'pl' => 'Zaufało nam ponad 500 firm.' ),
        // ...
    ),
);
```

### Why files, not one giant array

Independent diff/review per language. Non-developers can edit a single file. Each pack is roughly 2-4k tokens — sent only on cache misses, so amortized cost is small.

---

## 3. Custom Instructions

### Settings schema additions to `acwpt_settings`

```php
'never_translate' => array( 'Acme Cloud', 'PageSpeed Insights', /* ... */ ),
'glossary'        => array(
    array(
        'en' => 'Contact us',
        'pl' => 'Kontakt z nami',
        'es' => 'Contáctenos',
        'fr' => 'Contactez-nous',
        // ... one key per supported lang code, missing/empty = use model judgment
    ),
    // ... more rows
),
'custom_version'  => 1, // int, bumped on any change to never_translate or glossary
```

### Admin UI

Two new sections under the model picker on the existing settings page:

**Never-translate list**
- Single textarea, one term per line.
- Trimmed, deduplicated, empties dropped on save.

**Glossary**
- HTML table. First column: "English term". Subsequent columns: one per *enabled* language (column appears/disappears based on `enabled_languages[]`).
- Add/remove rows via vanilla JS (no build step — matches existing plugin style).
- Empty cells are allowed; a missing translation just means "model decides for that language."

### Translate-time application

**Sentinel injection (pre-call):**
For each term in `never_translate`, do a case-sensitive whole-word replace in the outgoing title/content/excerpt (and string values for batch translation): `Acme Cloud` → `<x-keep>Acme Cloud</x-keep>`. The base prompt instructs the model: *"Anything inside `<x-keep>...</x-keep>` must appear verbatim in your output, including the tags."*

**Sentinel stripping (post-call):**
Regex strip `<x-keep>` and `</x-keep>` tags from response before caching. If the model dropped a sentinel (rare with Claude), the original term still survives because we wrapped it.

**Glossary injection:**
For the target language, include only rows whose translation for that language is non-empty. Inject as a "MANDATORY GLOSSARY" block in the prompt. Additionally, sentinel-wrap the source term in the input so the model can't paraphrase it: `<x-glossary term="Kontakt z nami">Contact us</x-glossary>`. Post-process: replace each `<x-glossary term="X">...</x-glossary>` in the response with `X`.

### Cache invalidation

Cache key pattern was `post_id + language`. New pattern: `post_id + language + custom_version`. Bumping `custom_version` on save invalidates affected entries lazily without truncating the table. Old rows age out naturally; a "Clear stale cache" button can be added later if needed.

---

## 4. File Changes Summary

### Modified

| File | Changes |
|---|---|
| `ac-wp-translator.php` | Version → `2.0.0`. Add upgrade routine clearing `model` setting and models transient. Update plugin header description if it mentions OpenAI. |
| `includes/class-acwpt-translator.php` | Rewrite `translate()`, `translate_strings()`, `test_api_key()`, `record_usage()` for Anthropic. Replace `$pricing` table. Add private `call_anthropic($system, $user, $max_tokens, $type)`. Add `apply_keep_sentinels()` / `strip_keep_sentinels()`. Use `ACWPT_Prompts` for system prompts. Use `ACWPT_Glossary` for sentinel injection. |
| `includes/class-acwpt-admin.php` | Relabel "OpenAI" → "Anthropic"/"Claude" throughout. Update `ajax_fetch_models` to hit Anthropic. Add never-translate textarea + glossary table UI. Update sanitize/save logic for new settings keys. Bump `custom_version` when those fields change. Update cost-estimate copy to Claude pricing. |

### Added

| File | Purpose |
|---|---|
| `includes/class-acwpt-prompts.php` | Prompt assembler. Loads base + language pack + custom and composes final system prompts. |
| `includes/class-acwpt-glossary.php` | Helpers: sentinel application/stripping for never-translate, glossary lookup, glossary prompt-block formatting, glossary sentinel wrapping/unwrapping. |
| `includes/prompts/base-prompt.php` | Returns shared base system prompt string. |
| `includes/prompts/lang/pl.php` | Polish nuance pack. |
| `includes/prompts/lang/es.php` | Spanish nuance pack. |
| `includes/prompts/lang/fr.php` | French nuance pack. |
| `includes/prompts/lang/de.php` | German nuance pack. |
| `includes/prompts/lang/pt.php` | Portuguese (Brazilian-leaning) nuance pack. |
| `includes/prompts/lang/it.php` | Italian nuance pack. |
| `includes/prompts/lang/ja.php` | Japanese nuance pack. |
| `includes/prompts/lang/zh.php` | Simplified Chinese nuance pack. |

### Unchanged

- `includes/class-acwpt-frontend.php` (URL routing, filters, sitemap, hreflang, output buffer)
- `includes/class-acwpt-cache.php` (only the cache key composition shifts; the class itself can stay or take a small key-suffix patch)
- `includes/class-acwpt-languages.php`
- `includes/amplifi-framework.php`
- `docker-compose.yml`
- Database schema for `{prefix}_acwpt_translations`

---

## 5. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Existing users update and translations break until they re-enter the Anthropic key | Major version bump (2.0.0). Admin notice on first load post-upgrade: "amplifi.translate now uses Claude. Please enter your Anthropic API key and pick a model." |
| Claude leaks preamble before JSON in string-translation responses | Brace-balancing extractor instead of strict `json_decode` on full response. |
| Sentinel tags (`<x-keep>`) collide with real content | Use a tag name unlikely to appear in HTML/content (`x-keep` is non-standard). Document in admin help that this exact tag string is reserved. |
| Language packs grow large and inflate token cost | Translations are cached per `post_id + language + custom_version` — packs sent only on cache misses. Acceptable for B2B quality goal. |
| Pricing table drifts from Anthropic's published rates | `$pricing` is a class constant; document at top of class to verify against Anthropic pricing page on each plugin release. |
| Glossary entries conflict with language-pack defaults | Documented precedence: user glossary always wins (injected after base/language sections in the prompt). |

---

## 6. Out of Scope (Future Work)

- Per-language nuance packs for the remaining 25 supported languages — add iteratively after launch based on usage.
- "Clear stale cache" button to purge entries with old `custom_version`.
- Glossary import/export (CSV).
- Translation memory / fuzzy match before calling the API.
- Streaming responses (SSE) for long pages.
