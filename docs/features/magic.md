# amplifi.magic

Generates shareable one-click links for password-protected posts. Visiting a link with a valid `magic_token` query parameter sets the same hashed `wp-postpass_` cookie that WordPress's native password form would set, then redirects to the post's permalink, so the visitor never sees the password prompt. Every use is logged with IP address, geolocation and timestamp, and tokens can be named and revoked individually.

## At a glance

| | |
|---|---|
| Feature slug | `magic` |
| Product name | amplifi.magic |
| Entry file | `plugins/amplifi-plugins/features/magic/ac-magic-links.php` |
| Version constant | `ACML_VERSION` (`3.3.7`, tracks the suite version) |
| Main class | `Amplifi_Magic_Links` |
| Registered slug | `ac-magic-links` (product name `Magic`) |
| DB tables | None — all state is post meta |
| LOC | 701 (`ac-magic-links.php`), plus 16 (`uninstall.php`) |
| Other constants | `ACML_PLUGIN_DIR`, `ACML_PLUGIN_URL`, `ACML_PLUGIN_FILE` |

Loads only when `magic` is present in the `amplifi_plugins_enabled_features` option. The file self-guards with `if ( defined( 'ACML_VERSION' ) ) { return; }` and instantiates `new Amplifi_Magic_Links()` at the bottom.

## Architecture

Single-file plugin. The class carries the admin page, token CRUD, the front-end token handler, the usage log and its filter UI.

### Hooks

| Hook | Priority | Callback | Purpose |
|---|---|---|---|
| `template_redirect` | 1 | `ocml_handle_magic_token` | Consumes `?magic_token=`, sets the cookie, redirects |

That is the entire hook surface. The admin page itself is registered through the framework rather than a direct `admin_menu` call:

```php
amplifi_register_plugin(
    'ac-magic-links', 'Magic',
    'One-click magic links for password-protected posts of any type, with usage logging and IP geolocation.',
    ACML_VERSION, ACML_PLUGIN_FILE,
    array( $this, 'ocml_admin_page' )
);
```

There are no AJAX actions and no REST routes. All admin mutations are plain `POST` submissions handled at the top of `ocml_admin_page`.

### Method groups

- **Post-type resolution** — `ocml_get_supported_post_types()` returns every public post type minus `attachment`, run through the `amplifi_magic_post_types` filter; `ocml_post_type_label()` resolves a slug to its singular label.
- **Token lifecycle** — `ocml_create_token()`, `ocml_revoke_token()`, `ocml_find_page_by_token()`.
- **Front end** — `ocml_handle_magic_token()`.
- **Logging** — `ocml_log_token_usage()`, `ocml_get_token_usage_logs()`, `ocml_get_all_usage_logs()`, `ocml_get_ip_address()`, `ocml_get_geolocation()`.
- **Admin rendering** — `ocml_admin_page()`, `ocml_render_usage_logs_table()`.

### Extensibility

```php
/**
 * Filter the post types eligible for magic links.
 *
 * @param string[] $types Array of post type slugs.
 */
$types = apply_filters( 'amplifi_magic_post_types', $types );
```

This is the only filter the feature exposes. It is applied in `ocml_get_supported_post_types()`, which feeds the admin listing, `ocml_find_page_by_token()` and `ocml_get_all_usage_logs()` — so narrowing it also narrows which tokens still resolve on the front end.

## Admin UI

One page under the top-level `amplifi-studio` menu.

| Page | Slug | Hook suffix | Capability | Renderer |
|---|---|---|---|---|
| Magic | `amplifi-ac-magic-links` | `amplifi-studio_page_amplifi-ac-magic-links` | `manage_options` | `ocml_admin_page` |

The page has two sections:

1. **Per-post token table** — one row per published, password-protected post across all supported post types (`get_posts` with `'has_password' => true`, `'post_status' => 'publish'`, `'posts_per_page' => -1`). Columns: Post (title, ID, permalink), Type, Active Tokens (with the generated URL, a Copy button, a Revoke button, and that token's own usage log), and a Create Token form. Revoked tokens render in a collapsed sub-row below each post.
2. **All Access Logs** — a consolidated, filterable table across every post. Filters are `<select>` dropdowns populated from the distinct values present in the logs: Post ID, Post Title, Post Type, Token, Token Name, IP, Location, plus From/To date dropdowns. Filtering is a `POST` back to the same page and is applied in PHP with `array_filter`.

Both forms are guarded by `current_user_can( 'manage_options' )` inside the handler, in addition to the page's own capability.

## Data storage

No custom tables. Everything is post meta on the protected post.

### `_ocml_tokens_hashed_named`

Array of token records:

| Field | Type | Notes |
|---|---|---|
| `token` | string | 20 characters from `wp_generate_password( 20, false )` — alphanumeric, no special characters |
| `name` | string | Optional operator label, `sanitize_text_field` |
| `active` | bool | `true` on create; set to `false` on revoke |

Despite the meta key's name, the token is stored **in plaintext**. Nothing about this array is hashed.

### `_ocml_token_usages_named`

Append-only array of usage records:

| Field | Source |
|---|---|
| `token` | The token that was used |
| `token_name` | Resolved from `_ocml_tokens_hashed_named` at log time |
| `post_type` | `get_post_type( $page_id )` |
| `ip` | `ocml_get_ip_address()` |
| `location` | `ocml_get_geolocation( $ip )` |
| `datetime` | `current_time( 'mysql' )` |

`ocml_get_all_usage_logs()` decorates each entry at read time with `page_id` and `page_title`, and backfills `post_type` from the post when the stored entry predates that field.

## Token generation and revocation

**Create** (`ocml_create_token`): generates `wp_generate_password( 20, false )`, appends `{token, name, active: true}` to the post's `_ocml_tokens_hashed_named` array, and writes it back. There is no uniqueness check across posts, no expiry, and no use limit.

**Revoke** (`ocml_revoke_token`): walks the array by reference and flips `active` to `false` for the matching token. Records are never removed, so revoked tokens stay visible in the admin sub-row and remain resolvable in the usage log.

**Resolve** (`ocml_find_page_by_token`): a two-stage lookup.

```php
$args = array(
    'post_type'      => $this->ocml_get_supported_post_types(),
    'posts_per_page' => -1,
    'meta_query'     => array( array(
        'key'     => '_ocml_tokens_hashed_named',
        'value'   => $token,
        'compare' => 'LIKE'
    ) )
);
```

The `LIKE` meta query narrows candidates against the serialized array, then the code re-reads the meta on each candidate and confirms an exact `$tk['token'] === $token` match with `! empty( $tk['active'] )`. The `LIKE` stage alone is not authoritative — the exact comparison is what grants access.

## The cookie mechanism

`ocml_handle_magic_token` runs on `template_redirect` at priority 1, before most theme and plugin output.

```php
$hashed_password = $wp_hasher->HashPassword( stripslashes( $post->post_password ) );
$cookie_name = 'wp-postpass_' . COOKIEHASH;
setcookie(
    $cookie_name,
    $hashed_password,
    time() + ( 10 * DAY_IN_SECONDS ),
    COOKIEPATH,
    COOKIE_DOMAIN,
    is_ssl()
);
$this->ocml_log_token_usage( $page_id, $token );
wp_redirect( get_permalink( $page_id ) );
exit;
```

- `$wp_hasher` is the global `PasswordHash( 8, true )`, lazily required from `wp-includes/class-phpass.php` if not already set. This is exactly what `wp-login.php`'s `postpass` action does, so the resulting cookie is indistinguishable from one produced by the native password form and is validated by core's `post_password_required()`.
- Lifetime is 10 days, matching core's default.
- The `secure` flag follows `is_ssl()`. The `httponly` argument is not passed, so it defaults to `false` — the cookie is readable from JavaScript.
- The handler only acts when the resolved post actually has a `post_password`. If the token resolves but the password has since been removed, nothing happens and the request falls through.
- Because the cookie is keyed to the *password*, not the post, unlocking one post also unlocks every other post that shares the same password.

## Usage logging and geolocation

`ocml_get_ip_address()` reads, in order, `$_SERVER['HTTP_CLIENT_IP']`, `$_SERVER['HTTP_X_FORWARDED_FOR']`, then `$_SERVER['REMOTE_ADDR']`, falling back to the literal string `UNKNOWN`.

`ocml_get_geolocation( $ip )` calls the free ip-api.com endpoint:

```php
$response = wp_remote_get( 'http://ip-api.com/json/' . $ip );
```

On `status === 'success'` it returns `"{city}, {regionName}, {country}"`. On a `WP_Error`, an empty body, or any non-success status it returns the string `Location lookup failed`. For an unresolvable client it returns `Unknown IP`.

This is a **blocking, synchronous, plaintext HTTP request made on the visitor's redirect**, with no timeout override, no caching and no result memoisation.

## Uninstall

`features/magic/uninstall.php` deletes both meta keys across the whole `postmeta` table:

```php
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ocml_tokens_hashed_named' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ocml_token_usages_named' ) );
```

It is included by the suite-level `plugins/amplifi-plugins/uninstall.php`.

## Pitfalls

- **Tokens are stored in plaintext despite the `_hashed_` meta key name.** Anyone with database read access, a `postmeta` export, or a backup file has working bypass links for every protected post. The key name is a leftover and is actively misleading.
- **Client-controlled headers drive the IP log.** `HTTP_CLIENT_IP` and `HTTP_X_FORWARDED_FOR` are trusted unconditionally and preferred over `REMOTE_ADDR`, so a visitor can put any value in the audit log — and can feed an arbitrary string straight into the ip-api.com URL. Behind a proxy that does not strip these headers, the log is not evidence.
- **Geolocation blocks the redirect.** Every magic-link use makes a synchronous `wp_remote_get` to `http://ip-api.com` before the redirect fires. If ip-api is slow or unreachable, the visitor waits for the default HTTP timeout. ip-api's free tier is also rate limited per source IP, so a busy site will start logging `Location lookup failed` for legitimate visits.
- **The geolocation call is plain HTTP.** The visitor's IP address is sent to a third party unencrypted on every use, which is a disclosure worth knowing about before enabling this on a client site.
- **`ocml_find_page_by_token` is an unbounded query on every page load.** The handler runs on `template_redirect` for any request carrying `magic_token`, and issues a `posts_per_page => -1` query with a `LIKE` meta comparison across all public post types. On a large site this is a full meta scan, and it is reachable by an unauthenticated visitor who can guess a parameter name.
- **The usage log grows without bound in a single meta row.** `ocml_log_token_usage` appends to `_ocml_token_usages_named` and re-serializes the entire array on every hit. There is no cap, no rotation and no pruning, so a popular link produces one ever-growing `longtext` row that is read and unserialized on every admin page load.
- **The admin page loads every log for every post.** `ocml_get_all_usage_logs()` runs `get_posts` with `posts_per_page => -1` and **no** `has_password` restriction, then reads the usage meta for each. On a site with thousands of posts, opening the Magic page is expensive.
- **Filter dropdowns leak token values into the admin UI.** The Token filter renders every token in the log as a visible `<option value="...">`, and the per-post table prints token values in `<code>` blocks. Anyone who can view the page — or a screenshot of it — has the links.
- **Tokens never expire and are not use-limited.** Revocation is the only control, and it is manual. A link shared once is valid until someone remembers to revoke it.
- **The cookie unlocks by password, not by post.** If several posts share a password, one magic link opens all of them. The per-token usage log will only record the post the link pointed at.
- **The admin table only lists `publish` posts.** Tokens on drafts, private or scheduled posts still work on the front end but cannot be seen or revoked from the UI.
- **The Copy button uses `document.execCommand("copy")`**, which is deprecated and blocked in some browser configurations; the field is `readonly` and selected first, so manual copy still works.
- The recent history for this path (`git log --oneline -15 -- plugins/amplifi-plugins/features/magic/`) contains only suite-wide version bumps and consent-feature commits — no magic-specific changes. Treat this code as unmaintained and unexercised by recent CI.
