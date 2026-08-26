# amplifi.lockcache

Static HTML cache for password-protected posts only. Once a visitor has unlocked a password-protected post, the rendered HTML is captured to a file under `wp-content/pp-static-cache/` and served directly on subsequent unlocked visits, skipping the full theme render. It deliberately caches nothing else: non-singular views, posts without a password, still-locked posts, AJAX requests and administrators all fall through to a normal render.

Note the naming split: the **feature slug is `cache`**, the directory is `features/cache/`, but the **product name is `LockCache`** and the framework slug is `ac-static-cache`.

## At a glance

| | |
|---|---|
| Feature slug | `cache` |
| Product name | LockCache (registered as `LockCache`, page slug derived from `ac-static-cache`) |
| Entry file | `plugins/amplifi-plugins/features/cache/ac-static-cache.php` |
| Version constant | `ACSC_VERSION` (`3.3.7`, tracks the suite version) |
| Main class | `Amplifi_Static_Cache` |
| Registered slug | `ac-static-cache` |
| DB tables | None |
| `wp_options` / post meta | None |
| LOC | 427 (`ac-static-cache.php`), plus 27 (`uninstall.php`) |
| Other constants | `ACSC_PLUGIN_DIR`, `ACSC_PLUGIN_URL`, `ACSC_PLUGIN_FILE` |

Loads only when `cache` is present in the `amplifi_plugins_enabled_features` option. Self-guards with `if ( defined( 'ACSC_VERSION' ) ) { return; }` and instantiates `new Amplifi_Static_Cache()` at the bottom of the file.

## Architecture

Single-file plugin. The class holds the cache engine, the admin page and the debug logger. All state is on disk; nothing is written to the database.

Two instance properties set in the constructor:

```php
$this->cache_dir     = WP_CONTENT_DIR . '/pp-static-cache/';
$this->log_file_path = $this->cache_dir . 'ppsc-debug.log';
```

### Hooks and priorities

| Hook | Priority | Callback | Purpose |
|---|---|---|---|
| `template_redirect` | **1** | `maybe_set_no_cache_headers` | Sends `nocache_headers()` while the post is still locked |
| `template_redirect` | **100** | `maybe_serve_cached_content` | Serves the cache file and `exit`s |
| `template_include` | **9999** | `capture_template_output` | Buffers the theme render, writes the cache file, echoes and `exit`s |
| `register_activation_hook` | — | `activate_plugin` | Creates the directory, `.htaccess` and log file |

The priorities are deliberate. Priority 1 runs before anything can emit output, so the no-cache headers are still sendable. Priority 100 simply serves late, after other `template_redirect` consumers have had their say. (`post_password_required()` evaluates the cookie on each call and returns the same answer at either priority — WordPress does not "process" it at some point in between, so that is not the reason for the ordering.) `template_include` at 9999 is the last filter before the theme file is loaded, so the buffer captures the fully-resolved template.

### Method groups

- **Cache engine** — `maybe_set_no_cache_headers`, `maybe_serve_cached_content`, `capture_template_output`, `get_cache_file_path`.
- **Filesystem setup** — `create_cache_dir`, `write_htaccess`, `init_log_file`, `activate_plugin`.
- **Skip logic** — `is_searchandfilter_request`.
- **Admin** — `render_admin_page`, `preload_all_cache`, `clear_all_cache`, `clear_single_cache`, `read_log_file_newest_first`.
- **Logging** — `add_log`.

## What is cached and what is skipped

The two caching methods (`maybe_serve_cached_content`, `capture_template_output`) apply the same gate chain, in this order. `maybe_set_no_cache_headers()` is different: it has **no** `current_user_can()` check and *acts on* `post_password_required()` rather than skipping on it — it fires precisely because the post is locked. Note the two caching methods also order their own checks differently (`maybe_serve_cached_content` tests `manage_options` before `file_exists`; `capture_template_output` does the reverse):

1. `is_searchandfilter_request()` → skip.
2. `! is_singular()` → skip.
3. No `$post`, or `empty( $post->post_password )` → skip. **Only password-protected posts are ever cached.**
4. `post_password_required( $post )` → skip (the post is still locked; the password form is showing).
5. `current_user_can( 'manage_options' )` → skip.

`is_searchandfilter_request()` returns true for either of two conditions:

```php
if ( wp_doing_ajax() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) return true;
if ( ! empty( $_REQUEST['sfid'] ) || ! empty( $_REQUEST['sf_data'] ) || ! empty( $_REQUEST['sf_action'] ) ) return true;
```

So it covers **all AJAX requests**, not just Search & Filter Pro ones, plus any request carrying the Search & Filter Pro parameters `sfid`, `sf_data` or `sf_action`.

The administrator skip exists so the admin bar is never baked into a cache file, and applies to both serving and capturing — an administrator can never poison the cache and never sees a stale one.

Additionally, `capture_template_output` performs a post-render safety check before writing:

```php
if ( strpos( $html, 'post-password-form' ) !== false
  || strpos( $html, 'name="post_password_form"' ) !== false ) {
    // password form leaked into the output — echo and exit without caching
}
```

If the rendered HTML still contains a password form, the file is not written. This is the last line of defence against caching a locked page.

## Cache files and directory

| Path | Contents | Permissions |
|---|---|---|
| `wp-content/pp-static-cache/` | Cache root | `0700` (set on create *and* re-applied every time `create_cache_dir()` runs) |
| `wp-content/pp-static-cache/.htaccess` | `Order allow,deny\nDeny from all\n` | `0600` |
| `wp-content/pp-static-cache/cache-{post_id}.html` | One file per cached post | `0600` |
| `wp-content/pp-static-cache/ppsc-debug.log` | Append-only debug log | `0600` (re-applied on every write) |

The `.htaccess` denies direct HTTP access to the whole directory. Combined with `0700` on the directory and `0600` on the files, the cached HTML of a password-protected post is not reachable over the web and not readable by other users on a shared host running as a different UID.

> **In practice the `.htaccess` is usually never written.** It is created only by
> `activate_plugin()`, which is bound with
> `register_activation_hook( ACSC_PLUGIN_FILE, ... )` — the *feature* file. In the
> monorepo layout WordPress only fires activation hooks registered against the
> main plugin file (`amplifi-plugins.php`), and the suite's activation hook does
> not call the LockCache installer. On the normal path only
> `capture_template_output()` runs, and it calls `create_cache_dir()` alone.
>
> So the real protection is the directory's `0700` mode, not the `.htaccess`. That
> is sufficient under the common setup where PHP-FPM and the web server share a
> UID and Apache cannot traverse the directory — but do not rely on the
> `.htaccess` being present. See Pitfalls.

Served responses are prefixed with a marker comment so a cached hit is identifiable from the browser:

```html
<!-- Cached by amplifi.lockcache -->
```

Both `maybe_serve_cached_content` (via `readfile`) and `capture_template_output` (on the freshly-captured HTML) emit it, then `exit`.

## Admin UI

One page under the top-level `amplifi-studio` menu.

| Page | Slug | Hook suffix | Capability | Renderer |
|---|---|---|---|---|
| LockCache | `amplifi-ac-static-cache` | `amplifi-studio_page_amplifi-ac-static-cache` | `manage_options` | `render_admin_page` |

`render_admin_page` re-checks `current_user_can( 'manage_options' )` and returns early if it fails. Actions are plain `POST` submissions, each nonce-protected with `check_admin_referer( 'ppsc_cache_action', 'ppsc_nonce' )`.

| `ppsc_action` | Extra field | Handler | Effect |
|---|---|---|---|
| `clear_all` | — | `clear_all_cache` | `@unlink` every `cache-*.html` |
| `clear_one` | `ppsc_post_id` | `clear_single_cache` | `@unlink` one file (`intval`'d) |
| `preload_all` | — | `preload_all_cache` | `wp_remote_get` each protected post's permalink |

The page lists every password-protected post across **all** registered post types and **all** statuses (`get_post_types( array(), 'names' )`, `'post_status' => 'any'`, `'has_password' => true`, `'posts_per_page' => -1`) with columns: Post ID, Title, Post Type, Status, Cached?, Cache File, Created/Modified, and a per-row Clear button. Below that, the debug log is rendered newest-first in a scrolling `<pre>`.

## Logging

`add_log( $message )` pushes to an in-memory array, touches and `chmod`s the log file if needed, then appends `[Y-m-d H:i:s] {message}\n`. It is called on every skip, serve, capture, write failure and admin action, so the log records a line for essentially every request that reaches a singular password-protected view.

`read_log_file_newest_first()` reads the file with `file()` and `array_reverse`s it.

## Uninstall

`features/cache/uninstall.php` removes the cache files, `.htaccess` and debug log, then `@rmdir`s the directory:

```php
$files = array_merge(
    (array) glob( $cache_dir . 'cache-*.html' ),
    array( $cache_dir . '.htaccess', $cache_dir . 'ppsc-debug.log' )
);
```

It is included by the suite-level `plugins/amplifi-plugins/uninstall.php`. Note it enumerates a fixed list rather than globbing everything, so any other file that ends up in the directory blocks the final `@rmdir`.

## Pitfalls

- **The activation hook cannot fire in the monorepo layout.** `register_activation_hook( ACSC_PLUGIN_FILE, ... )` names the hook after `features/cache/ac-static-cache.php`, which is not the activated plugin file. The directory, `.htaccess` and log file are therefore never created at activation. `capture_template_output` calls `create_cache_dir()` before its first write, so caching still works — but there is a window where the directory exists without the `.htaccess`, because `write_htaccess()` is only ever called from `activate_plugin()`. **On any server that relies on `.htaccess` rather than filesystem permissions, cached HTML of password-protected posts can be web-readable at `/wp-content/pp-static-cache/cache-123.html`.** The `0700` directory mode is the only thing standing in the way, and it does not stop the web server itself from serving the file.
- **`ppsc-debug.log` is likewise never initialised at activation**; `add_log()` creates it on first use, so it inherits the umask before the `@chmod( 0600 )` lands.
- **The cache never invalidates.** Nothing hooks `save_post`, `post_updated`, `clean_post_cache` or `transition_post_status`. Once `cache-{id}.html` exists, `capture_template_output` returns early (`if ( file_exists( $cache_file ) ) return $template;`) and the file is served forever. Editing a post has no effect on the front end until an administrator clicks Clear. This is the single most surprising behaviour of the feature.
- **Changing a post's password does not clear its cache.** The old rendered HTML is still served to anyone whose `wp-postpass_` cookie satisfies `post_password_required()`, and that cookie is keyed to the password value.
- **Removing a post's password strands the file.** The gate chain skips posts with an empty `post_password`, so the cache file is never served again — but it is also never deleted, and it still contains the full rendered content.
- **`preload_all_cache` cannot actually warm the cache.** It calls `wp_remote_get( get_permalink( $post_id ) )` with no `wp-postpass_` cookie, so the request hits a locked post, `post_password_required()` returns true, and `capture_template_output` skips. The button logs `Preload all triggered by admin.` and produces no cache files. It also fires one blocking HTTP request per protected post with no timeout override or concurrency limit.
- **The debug log is unbounded.** `add_log` appends on every qualifying request with no rotation, no size cap and no way to clear it from the UI. On a site with heavy traffic to protected posts this file grows without limit, and the admin page reads the whole thing into memory on every load.
- **`date()` is used instead of `gmdate()` / `wp_date()`** for log timestamps and the admin "Created/Modified" column, so entries follow the server's PHP timezone rather than the site's configured timezone.
- **Cache files are keyed by post ID only.** Query strings, `Accept-Language`, logged-in non-admin user state, and any per-visitor personalisation are all collapsed into one file. A theme that renders anything user-specific on a password-protected post will leak it between visitors.
- **Non-admin logged-in users are cached and served.** The skip is `current_user_can( 'manage_options' )`, so editors, authors and subscribers share the cache with logged-out visitors — including whatever their toolbar or personalised markup produced for the first one through.
- **`readfile()` output is not buffered against a partially-written file.** `capture_template_output` writes with `file_put_contents` (non-atomic), so a concurrent request during a write can `readfile` a truncated document.
- The recent history for this path (`git log --oneline -15 -- plugins/amplifi-plugins/features/cache/`) is entirely suite-wide version bumps and consent-feature commits; there are no lockcache-specific commits in the last 15.
