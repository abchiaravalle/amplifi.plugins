# amplifi.pods

Podcast episode carousel and floating embed player, exposed as the `[amplifi-pods]` shortcode. It registers a `podcast` custom post type for Apple Podcasts episodes (with ACF fields, or a native meta box when ACF is absent) and optionally merges in Spotify episodes from a host-site function, sorts the combined set by release date, and renders them as a Swiper carousel. Clicking a card opens a fixed-position iframe player pointed at the Apple Podcasts or Spotify embed for that episode.

## At a glance

| | |
|---|---|
| Feature slug | `pods` |
| Product name | amplifi.pods |
| Entry file | `plugins/amplifi-plugins/features/pods/ac-pods.php` |
| Version constant | `ACPODS_VERSION` (`3.3.7`, tracks the suite version) |
| Main class | `Amplifi_Pods` |
| Registered slug | `ac-pods` (product name `Pods`) |
| Post type | `podcast` |
| Taxonomy | `acpods_category` |
| Shortcode | `[amplifi-pods]` |
| DB tables | None |
| `wp_options` | None |
| LOC | 1,341 (`ac-pods.php`), plus 31 (`uninstall.php`) |
| Other constants | `ACPODS_PLUGIN_DIR`, `ACPODS_PLUGIN_URL`, `ACPODS_PLUGIN_FILE` |

Loads only when `pods` is present in the `amplifi_plugins_enabled_features` option. Self-guards with `if ( defined( 'ACPODS_VERSION' ) ) { return; }`.

## Architecture

Single-file plugin. `Amplifi_Pods` owns the CPT, taxonomy, ACF field group, meta-box fallback, the shortcode, the carousel and player markup, roughly 490 lines of inline CSS, ~150 lines of inline JavaScript, and the admin reference page.

Two static properties provide per-request state:

```php
private static $player_rendered = false;  // ensures one floating player per page
private static $instance_count  = 0;      // unique DOM id per shortcode instance
```

Bootstrap at the bottom of the file:

```php
new Amplifi_Pods();
add_action( 'acf/init', array( 'Amplifi_Pods', 'register_acf_fields' ) );
```

### Hooks

| Hook | Priority | Callback | Purpose |
|---|---|---|---|
| `init` | 10 | `register_cpt` | Registers the `podcast` post type |
| `init` | 10 | `register_taxonomy` | Registers `acpods_category` |
| `add_meta_boxes` | 10 | `add_episode_meta_box` | Fallback fields, only when ACF is absent |
| `save_post_podcast` | 10 | `save_episode_meta` | Fallback save, only when ACF is absent |
| `wp_enqueue_scripts` | 10 | `register_assets` | Registers (does not enqueue) Swiper + inline CSS/JS |
| `acf/init` | 10 | `register_acf_fields` (static) | Registers the local field group |

`add_shortcode( 'amplifi-pods', array( $this, 'render_shortcode' ) )` is called in the constructor.

There are no AJAX actions and no REST routes. The admin page is registered through the framework via `amplifi_register_plugin( 'ac-pods', 'Pods', ..., array( $this, 'render_admin_page' ) )`.

### Method groups

- **Registration** — `register_cpt`, `register_taxonomy`, `register_acf_fields`.
- **Editing UI** — `add_episode_meta_box`, `render_episode_meta_box`, `save_episode_meta`.
- **Data** — `get_field_value`, `query_cpt_episodes`, `get_spotify_episodes`.
- **Rendering** — `render_shortcode`, `render_player_html`.
- **Assets** — `register_assets`, `get_inline_css`, `get_inline_js`.
- **Admin** — `render_admin_page`.

### Post type and taxonomy

Both registrations are idempotent — `register_cpt` returns early if `post_type_exists( 'podcast' )` and `register_taxonomy` returns early if `taxonomy_exists( 'acpods_category' )` — so a host site that already defines a `podcast` CPT keeps its own definition and amplifi.pods reads from it.

`podcast`: `public`, `has_archive`, rewrite slug `podcasts`, `menu_icon` `dashicons-microphone`, `menu_position` 21, supports `title`, `editor`, `thumbnail`, `excerpt`; plus the sibling top-level args `show_in_rest` and `show_in_nav_menus` (not `supports` entries).

`acpods_category`: hierarchical, `show_ui`, `show_admin_column`, `rewrite => false`. It is registered but is **not** used by the shortcode — there is no filter-by-category path in `render_shortcode`.

## Episode fields

`get_field_value()` prefers ACF's `get_field()` when available and falls back to `get_post_meta()`, so both storage paths use identical meta keys.

| Meta key / ACF name | ACF field key | Type | Required (ACF) | Purpose |
|---|---|---|---|---|
| `podcast_show_name` | `field_acpods_show_name` | text | yes | Show title, e.g. "Planet Money" |
| `podcast_apple_show_id` | `field_acpods_apple_show_id` | text | yes | Numeric Apple show ID, e.g. `290783428` |
| `podcast_apple_episode_id` | `field_acpods_apple_episode_id` | text | yes | Apple `?i=` value, e.g. `1000743335282` |
| `podcast_artwork_url` | `field_acpods_artwork_url` | url | yes | Artwork image URL (mzstatic.com) |
| `podcast_episode_number` | `field_acpods_episode_number` | text | no | Free-text label, e.g. "Episode 42" |
| `podcast_duration` | `field_acpods_duration` | text | no | Free-text duration, e.g. "45 min" |

ACF field group: key `group_acpods_episode`, title "Podcast Episode Details", location `post_type == podcast`, position `normal`.

**Meta box fallback.** `add_episode_meta_box` and `save_episode_meta` both return early if `function_exists( 'acf_add_local_field_group' )`, so exactly one of the two paths is active. The fallback box is `acpods_episode_details`, context `normal`, priority `high`, nonce `acpods_save_meta` / `acpods_meta_nonce`, and saves each key with `sanitize_text_field` after checking the nonce, `DOING_AUTOSAVE` and `current_user_can( 'edit_post', $post_id )`.

If `podcast_artwork_url` is empty, `query_cpt_episodes` falls back to the post's featured image at the `medium` size.

## Data sources

`render_shortcode` merges two sources and sorts the result by `release_date` descending with `strcmp`.

**Apple / CPT** (`query_cpt_episodes`): `WP_Query` on `post_type => podcast`, `post_status => publish`, ordered by date descending, `posts_per_page` set to the raw `count` attribute. Each episode gets `source => 'apple'`, `playlist_id => 'apple'`, description from `get_the_excerpt()`, and a `show_link` of `https://podcasts.apple.com/us/podcast/id{apple_show_id}` when the show ID is present.

**Spotify** (`get_spotify_episodes`): returns an empty array unless `nwr_spotify_get_all_episodes()` exists. That function is supplied by the host site (the Norwest Resources plugin), not by amplifi.pods. Each returned episode is normalised to `source => 'spotify'` with `title`, `description`, `show_name`, `artwork_url`, `duration`, `release_date`, `episode_id` (mapped to `spotify_episode_id`), `playlist_id`, and a `show_link` of `https://open.spotify.com/episode/{episode_id}`.

Playlist filter pills read the `nwr_spotify_playlist_links` transient, also owned by the host site. Each entry is expected to have `id`, `name` and optionally `url`.

## Shortcode

```
[amplifi-pods]
[amplifi-pods count="8" show_header="false"]
[amplifi-pods heading="Our Podcasts" accent_color="#6366f1"]
```

| Attribute | Type | Default | Behaviour |
|---|---|---|---|
| `count` | int | `-1` | `intval`'d and passed to `posts_per_page`; after the Spotify merge and sort, the array is sliced to `count` when `count > 0`. `-1` means all. |
| `show_filters` | bool | `true` | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`. Renders the "All" pill plus one pill per Spotify playlist. |
| `show_header` | bool | `true` | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`. Wraps subheading, heading and description. |
| `heading` | string | `Podcasts` | `<h2 class="acpods-heading">`, `esc_html`. Omitted if empty. |
| `subheading` | string | `Featured Podcasts` | Small uppercase label above the heading, `esc_html`. Omitted if empty. |
| `description` | string | `Listen to conversations with industry leaders, entrepreneurs, and innovators shaping the future of technology and business.` | Paragraph below the heading, `esc_html`. Omitted if empty. |
| `accent_color` | hex | `#055c5f` | Run through `sanitize_hex_color()`, falling back to the default on failure. Emitted as the `--acpods-accent` CSS custom property on `.acpods-wrap`. |

With no episodes the shortcode returns a plain paragraph, `No podcast episodes found.`, and enqueues nothing.

Each instance gets a DOM id of `acpods-instance-{n}` from the static counter, so multiple shortcodes on one page do not collide.

## Carousel

Swiper 11 is loaded from a CDN and registered — not enqueued — at `wp_enqueue_scripts`; the actual `wp_enqueue_*` calls happen inside `render_shortcode`, so a page without the shortcode ships no Swiper assets.

```php
wp_register_style(  'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), '11' );
wp_register_script( 'acpods-swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), '11', true );
wp_register_style(  'acpods-styles', false );   // inline via wp_add_inline_style
wp_register_script( 'acpods-scripts', false, array( 'acpods-swiper' ), ACPODS_VERSION, true );
```

Breakpoints: 1 slide per view by default, 2 at ≥768px, 4 at ≥992px, `spaceBetween: 12` throughout. `autoHeight` is off; instead the `init` and `resize` callbacks measure the tallest slide and set every slide to that pixel height, giving equal-height cards. Prev/next controls are scoped to the enclosing `.acpods-swiper-container`.

Each card carries the data needed by the player as attributes on `.acpods-card`: `data-source`, `data-show-id`, `data-episode-id`, `data-spotify-episode-id`, `data-show-name`, `data-episode-title`, `data-episode-desc`, `data-show-link`, `data-playlist-id`.

Filter pills operate purely client-side: clicking a pill toggles `display:none` on slides whose `data-playlist-id` does not match, adds `swiper-slide-hidden`, then calls `swiper.update()` and `slideTo(0, 0)`. The `all` pill clears the filter. Note this filters by **playlist**, and CPT episodes are all assigned `playlist_id => 'apple'`, so any non-`all` pill hides every Apple episode.

## Floating player

`render_player_html()` emits the player markup once per page — the static `$player_rendered` guard means a second `[amplifi-pods]` on the same page reuses the first player.

Structure: `#acpods-player` → header (show name, episode title, close button) → body (loading spinner + `<iframe id="acpods-player-iframe">`) → a `Episode Details` toggle button → a collapsible description panel.

The iframe carries `allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write"`.

Click handling is delegated on `document`. Clicks inside `.acpods-show-link` are allowed through so the show link navigates normally; everything else on a card is intercepted:

| Source | Embed URL | Body height |
|---|---|---|
| `spotify` | `https://open.spotify.com/embed/episode/{spotify_episode_id}?utm_source=generator&theme=0` | `232px` |
| `apple` (default) | `https://embed.podcasts.apple.com/us/podcast/id{show_id}?i={episode_id}&theme=light` | `175px` |

The spinner is shown, the description panel is collapsed, the iframe `src` is set, header text and description are populated via `textContent`, and `.is-visible` is added to slide the player up. The iframe's `load` event hides the spinner. Closing removes `.is-visible`, then after a 350ms transition clears `iframe.src`, restores the spinner and resets the body height to `175px`.

Styling is site-agnostic by design: no font-family declarations, no Bootstrap, no external icon library. Every icon is inline SVG (Apple mark, Spotify mark, play triangle, external-link, chevron, close), and the accent colour is a single CSS custom property.

## Admin UI

One page under the top-level `amplifi-studio` menu.

| Page | Slug | Hook suffix | Capability | Renderer |
|---|---|---|---|---|
| Pods | `amplifi-ac-pods` | `amplifi-studio_page_amplifi-ac-pods` | `manage_options` | `render_admin_page` |

The page is reference documentation plus a listing. It renders the shortcode attribute table, examples, a data-sources explainer, the ACF field list, a styling note, and a "Published Episodes" table (Title linked to the edit screen, Show Name, Episode Label, Duration, Apple Show ID, Apple Episode ID, Date) built from `get_posts( post_type => podcast, posts_per_page => -1, post_status => publish )`. When there are no episodes it links to `post-new.php?post_type=podcast`.

`render_admin_page` performs no capability check of its own; it relies entirely on the framework's `manage_options` submenu registration. It is read-only, so there is no state to protect.

Episodes are also editable through the standard `Podcasts` menu the CPT registers at position 21.

## Uninstall

`features/pods/uninstall.php` is **mismatched with the shipping code**:

```php
$post_ids = $wpdb->get_col( $wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'amplifi-podcasts'
) );
// ... wp_delete_post( $id, true ) for each
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acpods_rss_%' OR ..." );
```

It targets a post type named `amplifi-podcasts` and transients prefixed `acpods_rss_`. The plugin registers `podcast` and never sets any `acpods_rss_*` transient. See Pitfalls.

## Pitfalls

- **Uninstall deletes nothing.** The cleanup script queries `post_type = 'amplifi-podcasts'`, but the registered post type is `podcast`. Every episode post and all its meta survive deletion. The transient cleanup is equally dead — `acpods_rss_*` transients are never created by this code. Either the post type was renamed and the uninstaller was not updated, or the uninstaller was written against a different implementation.
- **Deleting episodes on uninstall would be the wrong behaviour anyway.** `register_cpt` yields to a pre-existing `podcast` post type, so on a host site that owns the CPT, a corrected uninstaller would destroy content amplifi.pods never created. Any fix needs to account for that.
- **`count` is applied twice, inconsistently.** It is passed raw to `posts_per_page` *before* the Spotify merge, then the merged array is sliced again after sorting. With `count="8"` and both sources present, you get the 8 newest Apple episodes plus all Spotify episodes, sorted, sliced to 8 — so the effective Apple pool is capped before the merge and newer Spotify episodes can push Apple episodes out. The result is correct in size but not a true "8 newest across both sources" when there are more than 8 Apple episodes.
- **Release dates are compared as strings.** `usort` uses `strcmp( $b['release_date'], $a['release_date'] )`. CPT episodes supply `Y-m-d` from `get_the_date( 'Y-m-d' )`, which sorts correctly, but Spotify `release_date` values come from the host function unvalidated. Any non-`Y-m-d` format sorts lexicographically and lands in the wrong place.
- **Swiper is loaded from jsdelivr with no local fallback and no SRI hash.** If the CDN is blocked or down, `new Swiper(...)` throws and the inline script aborts — which also kills the floating player, since both live in the same `DOMContentLoaded` handler.
- **The floating player breaks when the CDN fails.** `get_inline_js()` registers `acpods-scripts` with a hard dependency on `acpods-swiper`, and the player wiring sits after the Swiper init in the same handler. There is no `try`/`catch`.
- **Playlist filters hide all Apple episodes.** Every CPT episode is hard-coded to `playlist_id => 'apple'`, and pills are generated only from `nwr_spotify_playlist_links`. Selecting any playlist pill filters the Apple content out entirely, with no pill to bring just Apple back other than `All`.
- **`acpods_category` is registered but unused.** It appears in the editor and as an admin column, and editors will reasonably assume it filters the carousel. It does not.
- **Spotify support is not self-contained.** It depends on `nwr_spotify_get_all_episodes()` and the `nwr_spotify_playlist_links` transient, both from an unrelated host-site plugin, and neither is validated beyond `function_exists` / `is_array`. `get_spotify_episodes` reads `$sep['title']`, `$sep['description']`, `$sep['show_name']`, `$sep['artwork_url']`, `$sep['duration']`, `$sep['release_date']` and `$sep['episode_id']` with direct array access — a shape change in that function raises undefined-index notices.
- **`date()` is used for card dates** (`date( 'M j, Y', $ts )`), so the rendered date follows the server's PHP timezone rather than the site's configured timezone.
- **Episode descriptions are pushed through `esc_attr` into a data attribute** and then rendered with `textContent`, so all HTML in an excerpt is flattened. Long descriptions bloat the card markup because the full text ships in the attribute whether or not the player is opened.
- **The player is rendered inside `.acpods-wrap`**, which is inside whatever container the shortcode sits in. It is positioned `fixed`, so this works — but any ancestor with a `transform`, `filter` or `will-change` creates a containing block and the "floating" player will be clipped to that ancestor instead of the viewport.
- The recent history for this path (`git log --oneline -15 -- plugins/amplifi-plugins/features/pods/`) contains only suite-wide version bumps and consent-feature commits; there are no pods-specific changes in the last 15.
