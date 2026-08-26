# amplifi.sync

Remote-control REST API for moving files, database tables and media between WordPress environments. It exposes 13 routes under the `amplifi-sync/v1` namespace, all authenticated by a single shared API key sent in the `X-AmpliSync-Key` header, and is designed to be driven by an external sync TUI rather than by a browser. The admin page does nothing but display and regenerate the key and show a short connection log.

This is a remote write API with filesystem and database mutation capability. Read [Security posture](#security-posture) before enabling it on any site.

## At a glance

| | |
|---|---|
| Feature slug | `sync` |
| Product name | amplifi.sync |
| Entry file | `plugins/amplifi-plugins/features/sync/ac-sync.php` (53 LOC) |
| Version constant | `ACSYNC_VERSION` (`3.3.7`, tracks the suite version) |
| Classes | `Amplifi_Sync` (bootstrap), `ACSYNC_Admin`, `ACSYNC_API` |
| Registered slug | `ac-sync` (product name `Sync`) |
| REST namespace | `amplifi-sync/v1` |
| DB tables | None |
| LOC | 53 + 158 (`class-acsync-admin.php`) + 932 (`class-acsync-api.php`), plus 37 (`uninstall.php`) |
| Other constants | `ACSYNC_PLUGIN_DIR`, `ACSYNC_PLUGIN_URL`, `ACSYNC_PLUGIN_FILE` |

Loads only when `sync` is present in the `amplifi_plugins_enabled_features` option. Self-guards with `if ( defined( 'ACSYNC_VERSION' ) ) { return; }`.

## Architecture

Unlike the other small features, sync is split across three files.

```php
// ac-sync.php
require_once ACSYNC_PLUGIN_DIR . 'includes/amplifi-framework.php';
require_once ACSYNC_PLUGIN_DIR . 'includes/class-acsync-admin.php';
require_once ACSYNC_PLUGIN_DIR . 'includes/class-acsync-api.php';

class Amplifi_Sync {
    public function __construct() {
        $this->admin = new ACSYNC_Admin();
        $this->api   = new ACSYNC_API();
        amplifi_register_plugin( 'ac-sync', 'Sync', '...', ACSYNC_VERSION,
            ACSYNC_PLUGIN_FILE, array( $this->admin, 'render_page' ) );
    }
}
new Amplifi_Sync();
```

### `ACSYNC_API` — the REST surface

Constructor registers a single hook:

| Hook | Priority | Callback |
|---|---|---|
| `rest_api_init` | 10 (default) | `register_routes` |

Class constants define every operational limit:

| Constant | Value | Meaning |
|---|---|---|
| `NAMESPACE` | `amplifi-sync/v1` | REST namespace |
| `MAX_FILE_SIZE` | 52,428,800 (50 MB) | Ceiling for `/files/read` |
| `MAX_BASE64_SIZE` | 70,254,592 (~67 MB) | Ceiling for the encoded `/files/write` payload |
| `MAX_RESTORE_SIZE` | 52,428,800 (50 MB) | Ceiling for `/db/restore` SQL, inline or uploaded |
| `MAX_MANIFEST_FILES` | 10,000 | Manifest truncation point |
| `MD5_SIZE_THRESHOLD` | 10,485,760 (10 MB) | Files above this get no `md5` in the manifest |
| `MAX_BACKUPS` | 10 | Backup files retained before rotation |

Method groups: authentication (`check_auth`, `log_request`), status (`get_status`), files (`get_files_manifest`, `read_file`, `write_file`, `delete_file`, `is_safe_path`), database (`get_db_tables`, `validate_confirmation_token`, `export_table`, `import_table`, `db_backup`, `db_restore`), media (`get_media_list`, `import_media`, `is_private_ip`), Elementor (`elementor_regenerate`).

### `ACSYNC_Admin` — the settings page

| Hook | Priority | Callback |
|---|---|---|
| `admin_enqueue_scripts` | 10 | `enqueue_assets` (gated on hook suffix `amplifi-studio_page_amplifi-ac-sync`) |
| `wp_ajax_acsync_regenerate_key` | 10 | `ajax_regenerate_key` |

`render_page` re-checks `current_user_can( 'manage_options' )` and returns early if it fails.

### Activation

```php
register_activation_hook( __FILE__, function () {
    $settings = get_option( 'acsync_settings', array() );
    if ( empty( $settings['api_key'] ) ) {
        $settings['api_key'] = wp_generate_password( 48, false );
        update_option( 'acsync_settings', $settings );
    }
    if ( ! get_option( 'acsync_connection_log' ) ) {
        update_option( 'acsync_connection_log', array() );
    }
} );
```

The key is 48 alphanumeric characters from `wp_generate_password( 48, false )`.

## Admin UI

One page under the top-level `amplifi-studio` menu.

| Page | Slug | Hook suffix | Capability | Renderer |
|---|---|---|---|---|
| Sync | `amplifi-ac-sync` | `amplifi-studio_page_amplifi-ac-sync` | `manage_options` | `ACSYNC_Admin::render_page` |

Three cards:

1. **API Key** — the key in a `readonly` input masked with `-webkit-text-security: disc`, plus Show, Copy and Regenerate buttons. Regenerate posts `acsync_regenerate_key` to `admin-ajax.php`.
2. **API Endpoints** — a static reference table plus the base URL from `rest_url( 'amplifi-sync/v1/' )`.
3. **Connection Log** — the last 10 entries from `acsync_connection_log`, newest first (Time, IP, Endpoint, Status).

`ajax_regenerate_key` checks `check_ajax_referer( 'acsync_admin', 'nonce' )` then `current_user_can( 'manage_options' )`, writes a fresh 48-character key, and returns it in the JSON response.

## Storage

### `wp_options`

| Key | Autoload | Contents |
|---|---|---|
| `acsync_settings` | default (yes) | `array( 'api_key' => string )` — the shared secret in plaintext |
| `acsync_connection_log` | `false` (set explicitly on update) | Last 50 request records: `time`, `ip`, `endpoint`, `status` |

### Transients

| Key pattern | TTL | Contents |
|---|---|---|
| `acsync_db_token_{token_id}` | 300s | `array( 'token' => string, 'operation' => 'import'\|'restore' )` |

### Filesystem

| Path | Contents |
|---|---|
| `{uploads}/acsync-backups/` | SQL dumps from `/db/backup`, plus a `Deny from all` `.htaccess` and an `index.php` silence file |
| `{uploads}/acsync-backups/backup-{Y-m-d-His}-{micro}.sql` | One dump per call, rotated to `MAX_BACKUPS` |

## Authentication

Every route uses the same `permission_callback`:

```php
public function check_auth( WP_REST_Request $request ) {
    $key = $request->get_header( 'X-AmpliSync-Key' );
    if ( empty( $key ) ) {
        return new WP_Error( 'missing_key', '...', array( 'status' => 401 ) );
    }
    $settings = get_option( 'acsync_settings', array() );
    $stored   = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
    if ( ! hash_equals( $stored, $key ) ) {
        return new WP_Error( 'invalid_key', 'Invalid API key.', array( 'status' => 403 ) );
    }
    $this->log_request( $request );
    return true;
}
```

There is **no WordPress user context**. Requests are not tied to a user, no capability is evaluated, and there are no scopes, roles or per-route permissions. Possession of the 48-character key grants the full API surface. `hash_equals` provides constant-time comparison.

`log_request` appends `{time, ip, endpoint, status: 'ok'}` to `acsync_connection_log`, trims to the last 50 entries, and re-saves with `autoload = false`. The source acknowledges a race condition between concurrent requests and accepts it. Only successful authentications are logged — failed attempts leave no trace.

## REST routes

Base: `{site}/wp-json/amplifi-sync/v1`. All routes: `permission_callback => check_auth`.

| Method | Route | Parameters | Effect |
|---|---|---|---|
| GET | `/status` | — | Read-only site fingerprint |
| GET | `/files/manifest` | `dir` (default `wp-content`) | Recursive listing with MD5s |
| GET | `/files/read` | `path` | Returns file contents base64-encoded |
| POST | `/files/write` | `path`, `content` (base64), `mode` (optional octal) | **Writes/overwrites a file**, creates parent dirs |
| DELETE | `/files/delete` | `path` | **Deletes a file** |
| GET | `/db/tables` | — | Table inventory **and mints confirmation tokens** |
| GET | `/db/export` | `table`, `page` | 1,000 rows per page as JSON |
| POST | `/db/import` | `table`, `rows`, `mode`, `confirmation_token`, `token_id` | **Truncates and/or inserts rows** |
| POST | `/db/backup` | — | **Writes a full SQL dump** to uploads |
| POST | `/db/restore` | `sql` or file upload, `confirmation_token`, `token_id` | **Drops, recreates and repopulates tables** |
| GET | `/media/list` | `page`, `per_page` (max 100) | Attachment inventory |
| POST | `/media/import` | `url`, `title` | **Sideloads a remote file into the media library** |
| POST | `/elementor/regenerate` | — | Clears the Elementor CSS cache |

### `/status`

Returns `site_url`, `home_url`, `wp_version`, `php_version`, `active_theme`, `child_theme`, the full `active_plugins` array, `plugin_count`, whether Elementor is active, `uploads_dir` (absolute server path), `uploads_url`, `multisite`, `db_prefix` and `sync_version`.

### File operations

All four file routes funnel through `is_safe_path( $path, $context )`, which is the only containment boundary. It has three behaviours:

- Rejects if `is_link( $path )` — the target itself may not be a symlink.
- For `read` and `dir`: `realpath()` the full path, reject if it does not resolve, then require `strpos( $real, realpath( WP_CONTENT_DIR ) ) === 0`.
- For `write`: the file may not exist, so it resolves the nearest existing ancestor directory instead, rejects if that ancestor is a symlink, walks up until it finds a real directory (bailing at `/` or `.`), rejects if the un-resolved remainder contains `..`, then requires the resolved parent to start with the content dir.

Paths are always built as `ABSPATH . $param`, so the caller supplies a path relative to the WordPress root, but only `wp-content` and below is reachable.

`/files/manifest` walks with `RecursiveIteratorIterator` (`SELF_FIRST`), skips symlinks, stops at `MAX_MANIFEST_FILES` and sets `truncated: true`, and omits `md5` for files over `MD5_SIZE_THRESHOLD` (size + mtime are the fallback comparison signal).

`/files/write` validates `mode` as numeric, converts with `intval( $mode, 8 )` and requires `0000`–`0777`; checks the encoded length against `MAX_BASE64_SIZE` *before* decoding; `wp_mkdir_p`s the parent; rejects non-base64 content via `base64_decode( $content, true )`; writes with `file_put_contents`; then `chmod`s if a mode was given. Returns `path`, `size`, `md5`, `success`.

`/files/delete` uses the `read` context (so the file must already exist and resolve inside `wp-content`) and calls `unlink`. It refuses directories only implicitly — `unlink` on a directory fails and `deleted` comes back false.

### Database operations

**`/db/query` and `/db/execute` no longer exist.** The source carries an explicit note:

```php
// NOTE: /db/query and /db/execute endpoints have been removed.
// Raw SQL execution is too dangerous even behind an API key.
// Use /db/export for structured reads and /db/import for structured writes.
```

`/db/tables` runs `SHOW TABLE STATUS` and returns `name`, `rows`, `size`, `engine`, `collation` per table, plus `prefix`. It also mints two single-use confirmation tokens, one for `import` and one for `restore`, each stored in a 300-second transient:

```php
$tokens[ $op ] = array( 'token_id' => wp_generate_password(16,false), 'token' => wp_generate_password(32,false) );
```

`validate_confirmation_token()` requires both values, looks up the transient, compares with `hash_equals`, checks the operation type matches, then deletes the transient — single use, enforced.

`/db/export` validates the table against `SHOW TABLES` before querying and paginates at 1,000 rows. It returns `total_rows` and `total_pages`.

`/db/import` requires a valid `import` token, restricts `mode` to `truncate` or `append`, re-validates the table against `SHOW TABLES`, then runs inside `START TRANSACTION`. In `truncate` mode it issues `TRUNCATE TABLE` first, inserts each row with `$wpdb->insert`, throws on `$wpdb->last_error`, and `ROLLBACK`s the whole batch on failure.

`/db/backup` creates `{uploads}/acsync-backups/` with a `Deny from all` `.htaccess` and an `index.php` silence file (re-creating either if missing), rotates to `MAX_BACKUPS` oldest-first by mtime, then writes `DROP TABLE IF EXISTS` + `SHOW CREATE TABLE` + paginated `INSERT` statements (500 rows at a time) for **every** table returned by `SHOW TABLES`. Values are escaped with `$wpdb->_real_escape`. Returns `filename`, `size`, `tables`, and `path` relative to `ABSPATH`.

`/db/restore` requires a valid `restore` token *before* reading any input. SQL comes from the `sql` parameter or an uploaded `file`, size-capped at `MAX_RESTORE_SIZE` either way. It then splits on `";\n"` and applies a statement allowlist:

```php
if ( strpos( $normalized, 'DROP TABLE IF EXISTS' ) === 0 ) $allowed = true;
elseif ( strpos( $normalized, 'CREATE TABLE' ) === 0 )     $allowed = true;
elseif ( strpos( $normalized, 'INSERT INTO' ) === 0 )      $allowed = true;
```

Anything else returns 403 with the offending statement truncated to 200 characters. Execution is wrapped in a transaction, with an inline note that DDL causes implicit commits in MySQL so only the INSERT sequence rolls back cleanly.

### Media operations

`/media/list` is a `WP_Query` over `attachment` / `inherit`, ordered by ID ascending, `per_page` clamped to 1–100 (default 50). Each item carries `id`, `title`, `url`, `path` (absolute server path), `mime`, `date`, `filesize`, `width`, `height` and the registered `sizes` keys.

`/media/import` has explicit SSRF protection before it fetches anything:

1. `wp_parse_url` and require an `http` or `https` scheme.
2. Require a host.
3. `gethostbyname( $host )`; if it returns the host unchanged and the host is not itself a literal IP, fail with `dns_failed`.
4. `is_private_ip( $ip )` → 403. That helper rejects anything `filter_var( ..., FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )` refuses, and explicitly re-checks `169.254.0.0/16`.

It then `download_url()`s the file and `media_handle_sideload()`s it with parent `0`, unlinking the temp file on error.

### Elementor

`/elementor/regenerate` returns 404 `no_elementor` unless `\Elementor\Plugin` exists, then calls `\Elementor\Plugin::$instance->files_manager->clear_cache()`.

## Security posture

amplifi.sync is the highest-risk feature in the suite. The mitigations that exist are real, but so are the gaps.

**What is defended:**

- Raw SQL execution has been removed entirely, with the reasoning left in the source.
- Destructive database operations (`import`, `restore`) require a single-use, operation-typed, 300-second confirmation token that can only be obtained from an authenticated `/db/tables` call.
- File operations are confined to `wp-content` by `realpath` containment, with symlink rejection on both the target and the write-parent, and explicit `..` rejection in the unresolved write remainder.
- `/media/import` blocks non-HTTP schemes, unresolvable hosts and private/reserved/link-local IP ranges.
- Size ceilings on read, write and restore, checked before decode or execution.
- Backup rotation with a microsecond-suffixed filename to avoid collisions, and a `Deny from all` / `index.php` pair in the backup directory.
- `hash_equals` for both the API key and confirmation tokens.
- The admin AJAX handler checks both nonce and capability.

**Concerns, reported as observations only:**

1. **A single static bearer key grants the entire surface.** There is no user binding, no capability check, no per-route scoping and no expiry. Anyone holding the key can read any file under `wp-content` (including `wp-config` backups, `.env` files, private uploads), overwrite any file under `wp-content` (including `wp-content/plugins/*/*.php` and `wp-content/mu-plugins/`), and dump the entire database. **Arbitrary PHP write access under `wp-content` is remote code execution**, so the key is functionally equivalent to a shell on the site.
2. **The key is transmitted in a custom header and stored in plaintext in an autoloaded option.** `acsync_settings` is written with the default autoload, so the secret is loaded into memory on every request. Any other plugin, any debug dump of `wp_load_alloptions()`, or any database export exposes it. There is no TLS enforcement in the code — if the site is reachable over plain HTTP, the key is sent in cleartext.
3. **`/files/write` accepts an arbitrary `mode`.** The validated range is `0000`–`0777`, so a caller can set a file to `0777` on a shared host. There is no allowlist of sane modes.
4. **`/files/write` has no extension or content restriction.** Nothing prevents writing `.php`, `.htaccess` or `.user.ini` anywhere under `wp-content`, including into the uploads directory.
5. **`/db/backup` requires no confirmation token and no rate limit.** It dumps every table in the database — including `wp_users` password hashes and `wp_usermeta` — to a predictable directory, and returns the relative path. The `.htaccess` denies HTTP access on Apache, but on nginx (where `.htaccess` is inert) the dump is served directly from `{uploads}/acsync-backups/backup-*.sql` to anyone who can guess or list the filename. This is the single most consequential gap in the feature.
6. **The `/db/restore` statement allowlist is prefix matching on a naive split.** Statements are split on the literal `";\n"`, and each is matched with `strpos( $normalized, 'CREATE TABLE' ) === 0`. Any INSERT payload containing `";\n"` inside a string literal fragments the statement stream, and the allowlist reasons about the fragments rather than about real SQL. It also does not constrain *which* tables may be dropped or recreated.
7. **`/db/import` can truncate any table in the database**, including `wp_users` and `wp_options`, once a token is held. Table validation confirms existence, not that the table is in scope for a sync.
8. **`/status` is an unauthenticated-adjacent reconnaissance payload.** It is behind the key, but it hands over the full active-plugin list, WordPress and PHP versions, the DB prefix and absolute server paths — everything needed to target a known-vulnerable plugin version.
9. **Failed authentication is never logged.** `log_request` runs only after `hash_equals` succeeds, so brute-force attempts against the key leave no record in `acsync_connection_log`, and there is no rate limiting or lockout on the permission callback.
10. **`log_request` records `$_SERVER['REMOTE_ADDR']` only**, so behind a proxy or CDN every entry shows the proxy IP.
11. **`/media/import` re-resolves DNS.** `gethostbyname` is called for the SSRF check, then `download_url()` resolves the host again independently — a classic DNS-rebinding window between check and use.
12. **`is_safe_path` uses `strpos( $real, $content_dir ) === 0`**, a string-prefix test rather than a path-boundary test. A sibling directory whose name begins with the content directory's name (for example `/var/www/wp-content-backup` alongside `/var/www/wp-content`) satisfies the prefix and passes containment.
13. **`/db/export` and `/db/backup` use `$wpdb->prepare` with the non-standard `%1s` placeholder** for table names. `%1s` is not a documented `wpdb` placeholder; table identifiers are interpolated rather than quoted as identifiers. In practice the table name is validated against `SHOW TABLES` first on the export path, but `db_backup` interpolates `{$table}` directly into `SHOW CREATE TABLE` and the `INSERT` prefix with no quoting at all.
14. **The admin page's endpoint table is stale.** It still advertises `POST /db/query` and `POST /db/execute`, which were deliberately removed from `ACSYNC_API`. An operator reading the admin page will believe raw SQL execution is available.

## Uninstall

`features/sync/uninstall.php` deletes `acsync_settings` and `acsync_connection_log`, sweeps `_transient_acsync_db_token_%` and `_transient_timeout_acsync_db_token_%` from `wp_options`, then recursively removes `{uploads}/acsync-backups/` with a `CHILD_FIRST` iterator. It is included by the suite-level `plugins/amplifi-plugins/uninstall.php`.

This is the most complete uninstaller of the five small features.

## Pitfalls

- **The activation hook cannot fire in the monorepo layout.** `register_activation_hook( __FILE__, ... )` names the hook after `features/sync/ac-sync.php`, which is not the activated plugin file, and the suite's own activation callback in `amplifi-plugins.php` does not call into sync. **No API key is ever generated.** `check_auth` then compares the caller's key against an empty string with `hash_equals`, which fails for any non-empty key — so with a fresh install the API is inert and returns 403 for everything, and the admin page shows an empty key field with no way to populate it except the Regenerate button. Regenerate is the de facto provisioning step.
- **Regenerate is the only key-creation path**, and it is a plain AJAX call with a `confirm()` prompt. Clicking it silently breaks every configured client.
- **The admin endpoint table lies.** It lists `/db/query` and `/db/execute`, which do not exist. Anything built from that table will 404.
- **`acsync_settings` autoloads.** Both the activation callback and `ajax_regenerate_key` call `update_option( 'acsync_settings', $settings )` without `$autoload = false`, unlike `acsync_connection_log`, which is explicitly set to `false`. The secret is in `alloptions` on every request.
- **`/db/backup` dumps every table with no filter, no exclusion list and no token.** On a large site this is a long-running, memory-touching request with no timeout guard, and it silently keeps 10 full copies of the database inside the uploads directory — which counts against disk quota and, on most hosts, gets picked up by the site's own backup and CDN tooling.
- **`.htaccess` protection is Apache-only.** The `Deny from all` in `acsync-backups/` does nothing on nginx, LiteSpeed in some configurations, or any setup serving uploads from a CDN origin pull.
- **`db_restore`'s transaction does not protect DDL.** The source says so explicitly: a mid-restore failure leaves tables dropped or half-created even though the INSERTs roll back. There is no automatic pre-restore backup.
- **`import_table` inserts row-by-row with `$wpdb->insert`.** For a large table this is thousands of round trips inside one transaction, and the whole payload has to be held in memory as a decoded JSON array first.
- **The connection log races.** Concurrent requests read-modify-write the same option; the source documents this as accepted.
- **`get_media_list` calls `get_attached_file()` up to three times per item** when `filesize` is missing from metadata, and hits the filesystem for each — slow on large libraries.
- **`elementor_regenerate` returns 404 rather than a 501/409 when Elementor is inactive**, which is easy to mistake for a bad route.
- The recent history for this path (`git log --oneline -15 -- plugins/amplifi-plugins/features/sync/`) contains only suite-wide version bumps and consent-feature commits. The `/db/query` and `/db/execute` removal predates that window, and the admin page was never updated to match.
