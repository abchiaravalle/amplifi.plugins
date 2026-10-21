# AOS

Subtle staggered reveal animations as content scrolls into view: a short fade plus a
small upward drift, staggered across elements that enter the viewport together.

## At a glance

| | |
|---|---|
| Feature slug | `aos` |
| Product name | AOS |
| Entry file | `plugins/amplifi-plugins/features/aos/ac-aos.php` |
| Version constant | `ACAOS_VERSION` |
| Main class | `Amplifi_AOS` |
| Registered slug | `ac-aos` → page `amplifi-ac-aos`, hook suffix `amplifi-studio_page_amplifi-ac-aos` |
| Front-end asset | `assets/aos.js` (vanilla, no dependencies, script handle `amplifi-aos`) |
| Storage | one option, `amplifi_aos_settings` |
| DB tables | none |
| AI provider | none |

Loads only when `aos` is in `amplifi_plugins_enabled_features`.

## Settings (`amplifi_aos_settings`)

| Key | Type | Default | Notes |
|---|---|---|---|
| `scope` | `all` \| `front` \| `ids` | `all` | Where the script is enqueued |
| `page_ids` | int[] | `[]` | Used when `scope = ids` (singular views only) |
| `rules` | string | `''` | One CSS selector per line. Optional ` \| fade`, ` \| zoom` or ` \| up` suffix sets the variant. Lines starting `#` are ignored. `<`, `>`, `{`, `}` and control characters are stripped (so the child combinator `>` is not available — use a descendant selector); max 60 lines / 300 chars each |
| `distance` | int px (0–60) | `20` | Upward drift, desktop |
| `distance_mobile` | int px (0–60) | `14` | Upward drift under 768px |
| `duration` | int ms (150–1500) | `800` | |
| `stagger` | int ms (0–300) | `90` | Delay step between elements in one batch |
| `easing` | `expo` \| `quart` \| `cubic` \| `standard` \| `ease-out` | `expo` | All are ease-out curves with no overshoot. `expo` = `cubic-bezier(0.16, 1, 0.3, 1)` |
| `animate_on_load` | bool | `false` | Also animate targets already on screen at load |

Saved through `admin-post.php?action=amplifi_aos_save` (nonce + `manage_options`), and
re-sanitized on every read by `Amplifi_AOS::sanitize()`. Passed to the script as
`window.amplifiAos` with `wp_json_encode()` via `wp_add_inline_script( ..., 'before' )`.

## Targets

- Any element with a `data-aos` attribute (`data-aos="up"` default, `"fade"`, `"zoom"`;
  `data-aos="none"` opts an element out of a selector rule).
- Every element matched by a selector rule. An invalid selector is skipped (try/catch).
- A target nested inside another target is dropped; the outer one animates.

## Behaviour

- **Never hides what is already on screen.** At init only targets entirely *below* the
  viewport are hidden. No-JS visitors, visitors landing mid-page, and back/forward
  navigation (init waits for `load` so the restored scroll position is known) see
  content immediately. Targets taller than 1.2× the viewport are left alone.
- **Reveal once.** `IntersectionObserver` with threshold 0.12 and rootMargin
  `0px 0px -8% 0px`. Targets scrolled past above the viewport are shown instantly.
- **Batch stagger.** Targets that become visible in the same animation frame are sorted
  by position (row, then left) and delayed `index × stagger`, capped so the whole batch
  finishes starting within 450ms. Successive batches continue the cadence instead of
  restarting it.
- **Transitions, not keyframes.** Hidden state is inline `opacity:0` + `translate3d` /
  `scale(0.98)` with `!important`; the reveal is a CSS transition. This survives themes
  with global `animation-duration … !important` rules. The element's own transform is
  composed after the AOS offset so nothing jumps.
- **Clean hand-back.** When the transition ends, every inline property AOS touched is
  restored to its original value, so the element's own hover transforms and transitions
  work normally.
- `prefers-reduced-motion: reduce` → the script does nothing. Switching to reduced
  motion mid-visit reveals everything. Printing and keyboard focus reveal immediately.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `amplifi_aos_enabled` | filter `( bool $in_scope, array $settings )` | Force the script on/off for a request |
| `amplifi_aos_config` | filter `( array $config, array $settings )` | Adjust the JS config |

The script is never enqueued in wp-admin, feeds, AJAX, or Elementor / Beaver Builder /
Divi editor previews. The asset URL carries a content hash (`?aosv=`) so sites that strip
`?ver=` or CDNs that pin asset URLs still pick up a new build.

## Debugging

`window.amplifiAos.state()` returns `{targets, managed, hidden, animating, done}`;
`window.amplifiAos.revealAll()` shows everything at once.

## Uninstall

`features/aos/uninstall.php` deletes `amplifi_aos_settings`.
