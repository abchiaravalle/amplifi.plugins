# amplifi.meta

Bulk SEO meta editor for Yoast fields. It lists every post of a chosen post type in one admin table, generates title tags, meta descriptions and focus keyphrases through the OpenAI Chat Completions API (individually, for a selection, or as a resumable bulk run), and writes the results straight into Yoast's post meta. It also owns two adjacent subsystems: an AI FAQ generator backed by its own database table with front-end deployment, and a per-post JSON-LD editor that emits structured data in `wp_head`.

## At a glance

| | |
|---|---|
| Feature slug | `meta` |
| Product name | amplifi.meta |
| Entry file | `plugins/amplifi-plugins/features/meta/ac-bulk-meta.php` |
| Version constant | `ACMETA_VERSION` (`3.3.7`, tracks the suite version) |
| Main class | `AC_Bulk_Meta_Pages` (singleton via `get_instance()`) |
| Registered slug | `ac-bulk-meta` (product name `Meta`) |
| DB tables | `{$wpdb->prefix}ac_faqs` |
| LOC | 7,938 (`ac-bulk-meta.php`), plus 29 (`uninstall.php`) |
| Other constants | `ACMETA_PLUGIN_DIR`, `ACMETA_PLUGIN_URL`, `ACMETA_PLUGIN_FILE` |

Loads only when `meta` is present in the `amplifi_plugins_enabled_features` option. The file self-guards with `if ( defined( 'ACMETA_VERSION' ) ) { return; }`.

## Architecture

Single-file monolith. Inline CSS, inline JavaScript, AJAX handlers, OpenAI calls, FAQ storage, JSON-LD generation and all three admin page renderers live in `AC_Bulk_Meta_Pages`.

Bootstrap order at the bottom of the file:

```php
AC_Bulk_Meta_Pages::get_instance();            // wires all hooks in the constructor
amplifi_register_plugin(
    'ac-bulk-meta', 'Meta', '...', ACMETA_VERSION, __FILE__,
    array( AC_Bulk_Meta_Pages::get_instance(), 'render_admin_page' )
);
register_activation_hook( __FILE__, 'acmeta_activate' );      // creates ac_faqs
register_deactivation_hook( __FILE__, '__return_false' );     // no-op
```

### Hooks

| Hook | Priority | Callback | Purpose |
|---|---|---|---|
| `admin_menu` | 10 | `add_extra_submenus` | Registers the FAQ and JSON-LD submenus |
| `admin_enqueue_scripts` | 10 (default) | `enqueue_scripts` | Hook-suffix gate, then attaches inline CSS/JS |
| `admin_head` | 10 | `output_inline_styles` | Added from inside `enqueue_scripts` only |
| `admin_footer` | 10 | `output_inline_scripts` | Added from inside `enqueue_scripts` only |
| `wp_head` | 10 | `output_jsonld` | Per-post JSON-LD + FAQPage schema |
| `wp_head` | 10 | `output_faq_deploy_head` | FAQ deploy CSS/markup styling |
| `wp_footer` | 10 | `output_faqs_before_footer` | Injects the deployed FAQ block |
| 37 × `wp_ajax_*` | 10 | see [AJAX actions](#ajax-actions) | Admin-only endpoints |

`enqueue_scripts` short-circuits unless `$hook` is one of the three page hook suffixes. On the FAQ page it additionally calls `wp_enqueue_editor()` and `wp_enqueue_media()` for the WYSIWYG answer editors.

### Method groups

- **Rendering** — `render_admin_page`, `render_faq_page`, `render_jsonld_page`, `output_inline_styles` (~1,200 lines of CSS), `output_inline_scripts` (~2,700 lines of jQuery).
- **Yoast read/write** — `ajax_get_pages_data`, `ajax_update_yoast_meta`.
- **Single-item AI generation** — `ajax_generate_meta_description`, `ajax_generate_title_tag`, `ajax_generate_focus_keyphrase`, backed by `generate_single_meta_description`, `generate_single_title_tag`, `generate_single_focus_keyphrase`.
- **Bulk queue** — `ajax_bulk_generate_start` / `_next` / `_status` / `_stop`, plus per-field entry points `ajax_bulk_generate_titles`, `ajax_bulk_generate_descriptions`, `ajax_bulk_generate_focus_keyphrases`, and `ajax_generate_selected`. State lives in two per-user transients, not in an option.
- **FAQ** — `ajax_generate_faqs`, `generate_faqs_for_post`, `parse_faq_content`, `store_faq`, `get_faqs_for_post`, `get_faq_count_for_post`, `ajax_add_faq`, `ajax_save_faq`, `ajax_delete_faq`, `ajax_export_faqs_csv`, `ajax_deploy_faqs`, `ajax_undeploy_faqs`.
- **JSON-LD** — `generate_jsonld_for_post`, `generate_faqpage_schema`, `ajax_generate_jsonld`, `ajax_save_jsonld_post`, `ajax_validate_jsonld`, `ajax_get_jsonld_data`, `ajax_save_jsonld_settings`.
- **Logging** — `log_ai_generation`, `send_log_to_webhook`, `ajax_get_ai_logs`.
- **Helpers** — `is_external_url`, `truncate_at_word_boundary`, `scope_css_to_wrapper`, `extract_prioritized_content`.

### OpenAI usage

All generation calls `wp_remote_post( 'https://api.openai.com/v1/chat/completions', ... )` with a hard-coded `'model' => 'gpt-4o-mini'` (9 call sites). Key validation (`ajax_validate_openai_key`) uses `wp_remote_get( 'https://api.openai.com/v1/models' )`. The model is not configurable from the UI.

## Admin UI

Three pages under the top-level `amplifi-studio` menu.

| Page | Slug | Hook suffix | Capability | Renderer |
|---|---|---|---|---|
| Meta (main) | `amplifi-ac-bulk-meta` | `amplifi-studio_page_amplifi-ac-bulk-meta` | `manage_options` | `render_admin_page` |
| Meta: FAQ | `amplifi-ac-bulk-meta-faq` | `amplifi-studio_page_amplifi-ac-bulk-meta-faq` | `edit_posts` | `render_faq_page` |
| Meta: JSON-LD | `amplifi-ac-bulk-meta-jsonld` | `amplifi-studio_page_amplifi-ac-bulk-meta-jsonld` | `edit_posts` | `render_jsonld_page` |

The main page is registered by the framework (`amplifi_register_plugin` → `amplifi_page_slug( 'ac-bulk-meta' )` → `amplifi-ac-bulk-meta`) at `admin_menu` priority 5, always with `manage_options`. The two extra pages are added directly by `add_extra_submenus` at priority 10 with `edit_posts`, so editors can reach FAQ and JSON-LD but not the main page.

Dark mode is a per-user toggle. `render_admin_page` reads `get_user_meta( $user, 'ac_bulk_meta_dark_mode', true )` to add a `dark-mode` class to the `.wrap`, and the toggle button posts `ac_save_dark_mode`.

## Database

### `{$wpdb->prefix}ac_faqs`

Created with `dbDelta` by `create_faqs_table()`. Called from the activation hook (`acmeta_activate`) and lazily from `store_faq()` on every insert.

| Column | Type | Notes |
|---|---|---|
| `id` | `mediumint(9) NOT NULL AUTO_INCREMENT` | Primary key |
| `post_id` | `bigint(20) NOT NULL` | Indexed (`KEY post_id`) |
| `question` | `text NOT NULL` | |
| `answer` | `text NOT NULL` | Stored as HTML; rendered through `wpautop()` |
| `created_at` | `datetime DEFAULT CURRENT_TIMESTAMP` | Written as `current_time('mysql')` |
| `created_by` | `bigint(20) NOT NULL` | `get_current_user_id()`, indexed (`KEY created_by`) |

There is no `updated_at`, no post-type column, and no foreign key. Rows are never cascaded when a post is deleted.

### `wp_options`

| Key | Written by | Contents |
|---|---|---|
| `ac_openai_api_key` | `ajax_save_openai_key` | OpenAI API key, plain text |
| `ac_global_prompt` | `ajax_save_global_prompt` | Extra instructions appended to every generation prompt |
| `ac_site_title_override` | `ajax_save_site_title_override` | Site name used in `TITLE | SITE` construction |
| `ac_webhook_url` | `ajax_save_webhook_url` | Optional log-forwarding endpoint |
| `ac_webhook_url_set_by` | `ajax_save_webhook_url` | User ID that owns the webhook lock; deleted when the URL is cleared |
| `ac_faq_focus` | `ajax_save_faq_focus` | Free-text topical steer for FAQ generation |
| `ac_faq_count` | `ajax_save_faq_count` | FAQs per post; validated to 1–15 |
| `ac_faq_deploy_global` | `ajax_save_faq_deploy_global` | Deploy render settings (below) |
| `ac_jsonld_settings` | `ajax_save_jsonld_settings` | Organization schema fields (below) |
| `ac_ai_generation_logs` | `log_ai_generation` | Array of log entries, trimmed to the last 100 |

`ac_faq_deploy_global` keys: `mode` (`accordion` or `expanded`), `header`, `container_class`, `selector`, `header_color`, `header_font_weight`, `heading_color`, `heading_font_weight`, `answer_color`, `answer_font_weight`, `number_faqs` (bool), `wrapper_css`.

`ac_jsonld_settings` keys: `org_name`, `org_url`, `org_logo`, `org_description`, `org_phone`, `org_email`, `org_address`, `org_facebook`, `org_twitter`, `org_linkedin`.

### Transients

| Key | TTL | Purpose |
|---|---|---|
| `ac_bulk_generate_queue_{user_id}` | 3600s on create | Remaining post IDs for the bulk run |
| `ac_bulk_generate_progress_{user_id}` | 3600s on create | `{total, completed, ...}` progress payload |

Both are re-saved by `ajax_bulk_generate_next` with **no expiry argument**, so after the first tick they become non-expiring. `ajax_bulk_generate_stop` deletes both.

### Post meta

| Key | Owner | Purpose |
|---|---|---|
| `_yoast_wpseo_title` | Yoast SEO | SEO title tag, read and written |
| `_yoast_wpseo_metadesc` | Yoast SEO | Meta description, read and written |
| `_yoast_wpseo_focuskw` | Yoast SEO | Focus keyphrase, read and written |
| `_ac_targeted_keywords` | amplifi.meta | Per-post keyword steer fed into prompts |
| `_ac_jsonld` | amplifi.meta | JSON-LD document as a JSON string |
| `_ac_faqs_deployed` | amplifi.meta | Boolean flag; set by `ajax_deploy_faqs`, deleted by `ajax_undeploy_faqs` |

### User meta

| Key | Values |
|---|---|
| `ac_bulk_meta_dark_mode` | `'1'` or `'0'` |

## Yoast SEO integration

`ajax_update_yoast_meta` is the only write path and uses an explicit allowlist, so an arbitrary `field` value cannot reach `update_post_meta`:

```php
$allowed_fields = array(
    'yoast_title'       => '_yoast_wpseo_title',
    'yoast_desc'        => '_yoast_wpseo_metadesc',
    'yoast_focus'       => '_yoast_wpseo_focuskw',
    'targeted_keywords' => '_ac_targeted_keywords',
);
```

`targeted_keywords` is sanitized with `sanitize_textarea_field`, the three Yoast fields with `sanitize_text_field`. The plugin writes the raw meta keys directly; it never calls a Yoast API, so Yoast does not have to be active for the values to be stored, and Yoast's own variable syntax (`%%title%%` etc.) is not expanded. Generated title tags are assembled as `GENERATED TITLE | SITE NAME` with a 65-character ceiling, using `ac_site_title_override` when set and `get_bloginfo('name')` otherwise.

## FAQ system

1. **Generate** — `ajax_generate_faqs` / `ajax_bulk_generate_faqs` prompt the model for `ac_faq_count` question-and-answer pairs, steered by `ac_faq_focus` and the post body (reduced by `extract_prioritized_content`). `parse_faq_content` splits the response and `store_faq` inserts one row per pair into `ac_faqs`.
2. **Edit** — the FAQ page renders each answer in a WordPress WYSIWYG editor. `ajax_save_faq`, `ajax_add_faq` and `ajax_delete_faq` are per-row operations.
3. **Deploy** — `ajax_deploy_faqs` sets `_ac_faqs_deployed = true` on the post. Nothing else is stored per post. At render time only **one** key is read from the global `ac_faq_deploy_global` option: `header` (`ac-bulk-meta.php:7146–7151`). Other keys stored by the settings UI (`mode`, colours, font weights, `selector`, `container_class`) are **not** consulted by the renderer — the layout and styling are hard-coded.
4. **Render** — `output_faqs_before_footer` (on `wp_footer`) builds a `.ac-faq-section` block: the first three questions in a left column, all answers stacked in a right column, and inline JavaScript (`insertFAQs()`, `initFaqClicks()`) that relocates the block and wires click-to-switch behaviour. The insertion target is a hard-coded chain, not the stored `selector` / `container_class` values.
5. **Style** — `output_faq_deploy_head` (on `wp_head`) emits a fixed stylesheet: it bails unless `is_singular()`, the post has `_ac_faqs_deployed`, and the post has FAQs, then echoes a hard-coded `<style id="ac-faq-deploy-styles">` block.

   > **`scope_css_to_wrapper()` is dead code.** It is defined at
   > `ac-bulk-meta.php:6734` and never called anywhere. No operator `wrapper_css`
   > is emitted and no selector prefixing happens, so do not treat it as a
   > containment guarantee for custom CSS.
6. **Export** — `ajax_export_faqs_csv` streams a CSV (`Post ID, Post Title, Post Status, FAQ ID, Question, Answer, Created At, Created By`) joined against `{$wpdb->posts}` for one post type. It reads `$_GET['post_type']` and `exit`s after `fclose`.

## JSON-LD output

`output_jsonld` runs on `wp_head` and does two independent things:

```php
public function output_jsonld() {
    if ( defined( 'AMPLIFI_SCHEMA_ACTIVE' ) ) { return; }
    if ( ! is_singular() ) { return; }
    // 1. echo _ac_jsonld verbatim, but only if json_decode() succeeds
    // 2. if _ac_faqs_deployed, echo generate_faqpage_schema( get_faqs_for_post( $post->ID ) )
}
```

- The whole method yields to **amplifi.schema**: if `AMPLIFI_SCHEMA_ACTIVE` is defined, amplifi.meta emits no structured data at all, including the FAQPage block.
- Per-post JSON-LD is stored as a string in `_ac_jsonld` and echoed unescaped inside `<script type="application/ld+json">`. The only gate is a successful `json_decode`.
- `generate_faqpage_schema` builds a `schema.org/FAQPage` with `mainEntity` of `Question` / `acceptedAnswer` pairs, running `wp_strip_all_tags()` over both question and answer, encoded with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.
- The FAQPage block is emitted whenever FAQs are deployed, independently of whether `_ac_jsonld` exists.
- `ajax_save_jsonld_post` validates with `json_decode` before saving and rejects on `json_last_error()`, but sanitizes with `sanitize_textarea_field`, which strips tags and can corrupt otherwise-valid JSON string values.

## AJAX actions

All 37 actions are registered as `wp_ajax_*` only — there is no `nopriv` surface. Every handler starts with `check_ajax_referer( 'ac_bulk_meta_nonce', 'nonce' )`. The nonce is printed once by `output_inline_scripts`.

| Capability | Actions |
|---|---|
| `manage_options` | `ac_save_openai_key`, `ac_save_webhook_url`, `ac_save_jsonld_settings` |
| `edit_posts` | `ac_get_pages_data`, `ac_update_yoast_meta`, `ac_save_global_prompt`, `ac_save_site_title_override`, `ac_save_dark_mode`, `ac_generate_meta_description`, `ac_generate_title_tag`, `ac_generate_focus_keyphrase`, `ac_generate_selected`, `ac_bulk_generate_start`, `ac_bulk_generate_next`, `ac_bulk_generate_titles`, `ac_bulk_generate_descriptions`, `ac_bulk_generate_focus_keyphrases`, `ac_get_ai_logs`, `ac_validate_openai_key`, `ac_generate_faqs`, `ac_get_faqs_data`, `ac_bulk_generate_faqs`, `ac_export_faqs_csv`, `ac_delete_faq`, `ac_add_faq`, `ac_save_faq`, `ac_save_faq_focus`, `ac_save_faq_count`, `ac_save_faq_deploy_global`, `ac_deploy_faqs`, `ac_undeploy_faqs`, `ac_generate_jsonld`, `ac_get_jsonld_data`, `ac_save_jsonld_post`, `ac_validate_jsonld` |
| nonce only, no capability check | `ac_bulk_generate_status`, `ac_bulk_generate_stop` |

The webhook has an ownership lock: once `ac_webhook_url` is set, `ac_webhook_url_set_by` records the user ID, and any other administrator gets `Webhook URL is locked. Only <name> can modify or disable it.` The lock is per-user, not per-role, so an administrator cannot clear another administrator's webhook from the UI.

`send_log_to_webhook` fires `wp_remote_post` with `'blocking' => false`, a 5s timeout and `sslverify => true`, posting the full log entry as JSON — including the generated content, the post title and the acting user's display name.

## Uninstall

`features/meta/uninstall.php` runs `DROP TABLE IF EXISTS {$wpdb->prefix}ac_faqs` and deletes options. It is included by the suite-level `plugins/amplifi-plugins/uninstall.php`, which globs `features/*/uninstall.php`.

## Pitfalls

- **The uninstall option list does not match the code.** It deletes `ac_ai_generation_log` (singular) but the plugin writes `ac_ai_generation_logs` (plural), so the AI log — which can hold 100 entries of generated content — survives deletion. It also deletes `ac_bulk_generation_status`, an option no code path ever writes; bulk state lives in the two per-user transients, and those are never cleaned up.
- **Post meta is never cleaned up.** `_ac_jsonld`, `_ac_targeted_keywords`, `_ac_faqs_deployed` and the `ac_bulk_meta_dark_mode` user meta all persist after uninstall.
- **The activation hook cannot fire in the monorepo layout.** `register_activation_hook( __FILE__, 'acmeta_activate' )` derives its hook name from `features/meta/ac-bulk-meta.php`, which is not the activated plugin file (`amplifi-plugins.php` is). The FAQ table is therefore created lazily by `store_faq()` instead. Any read path that runs before the first FAQ is stored — `get_faqs_for_post`, `get_faq_count_for_post`, `ajax_export_faqs_csv` — queries a table that may not exist yet.
- **The suite activation hook targets the wrong class.** `amplifi-plugins.php` guards with `class_exists( 'AC_Bulk_Meta' )`, but the class is `AC_Bulk_Meta_Pages`, so that branch never runs.
- **Bulk transients stop expiring after the first tick.** `ajax_bulk_generate_next` re-saves both transients without a TTL. An abandoned run leaves permanent rows in `wp_options` unless the user clicks Stop.
- **`ac_bulk_generate_status` and `ac_bulk_generate_stop` check only the nonce**, not a capability. The nonce is only printed on pages gated by `manage_options` / `edit_posts`, but the handlers themselves would accept any logged-in user holding a valid nonce.
- **`_ac_jsonld` is echoed unescaped.** Anyone with `edit_posts` can store arbitrary JSON that is printed verbatim into a `<script>` tag on the front end.
- **The OpenAI key is stored and returned in plaintext**, and the model is hard-coded to `gpt-4o-mini` in nine places — changing it means editing all nine.
- **`ajax_generate_jsonld` writes to `error_log()` on every request**, including a `print_r` of `ac_jsonld_settings` and the first 200 characters of the generated payload. On a busy site this fills the PHP error log with schema noise.
- **FAQ rows are orphaned on post deletion.** Nothing hooks `deleted_post` or `before_delete_post`, so `ac_faqs` accumulates rows pointing at post IDs that no longer exist, and the CSV export silently drops them because it `INNER JOIN`s against `{$wpdb->posts}`.
- **Only the first three questions render.** `output_faqs_before_footer` breaks out of the question loop at `$i >= 3` while emitting answers for every FAQ, so FAQs 4+ are in the DOM and in the FAQPage schema but have no clickable question.
- The recent history for this path (`git log --oneline -15 -- plugins/amplifi-plugins/features/meta/`) is entirely suite-wide version bumps and consent-feature commits; there are no meta-specific commits in the last 15, so the monolith is effectively frozen and any change here is unexercised.
