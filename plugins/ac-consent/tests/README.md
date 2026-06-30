# amplifi.consent — test fixtures & verified results

End-to-end test config used to validate the module on a throwaway Docker WP
stack on `trashcan` (loopback-only, port 8094).

## Files
- `docker-compose.trashcan.yml` — throwaway WP 6.6 + MariaDB 11 + wp-cli sidecar,
  bound to `127.0.0.1:8094` only (never touches the live Caddy/dnsmasq mesh).
  Plugin dir is volume-mounted read-only.
- `sample-scripts.json` — two managed scripts seeded via
  `wp option update acconsent_scripts --format=json < sample-scripts.json`:
  an Analytics script (head) and a Marketing pixel (footer), each setting a
  test cookie and a window flag so release can be observed.

## Bring-up (on a Docker host)
```bash
mkdir -p ac-consent-test/plugin && cp -r <repo>/plugins/ac-consent/* ac-consent-test/plugin/
cp <repo>/shared/amplifi-framework.php ac-consent-test/plugin/includes/   # release.sh does this normally
cp docker-compose.trashcan.yml ac-consent-test/docker-compose.yml
cd ac-consent-test && docker compose up -d
docker compose exec -T wpcli wp core install --url=http://localhost:8094 \
  --title="AC Consent Test" --admin_user=admin --admin_password=pass \
  --admin_email=t@example.com --skip-email
docker compose exec -T wpcli wp plugin activate ac-consent
docker compose exec -T wpcli wp option update acconsent_scripts --format=json < scripts.json
```

## Verified behavior (2026-06-29)
| Check | Result |
|-------|--------|
| Scripts emitted as inert `<template>` blocks in HTML | ✅ both gated, 0 released |
| First visit: banner shows, nothing runs, no cookies | ✅ `testRan:false mktRan:false cookies:""` |
| Accept all → both scripts run, both cookies set, banner gone | ✅ released:2, cookies present |
| Consent persisted to localStorage, TTL = exactly 180 days | ✅ `expires - ts = 15552000000ms` |
| Reload with stored consent → auto-release, no banner | ✅ |
| Reject all → nothing runs, opt-in categories all false | ✅ |
| Manage modal: 4 categories, Necessary locked+checked | ✅ |
| Selective save (Analytics only) → head script runs, footer withheld | ✅ `_actest_ga` only |
| Toast on save | ✅ "Preferences saved — thanks!" |
| `[amplifi-consent-manager]` shortcode → reopens modal | ✅ |
| Admin cookie scanner (iframe harness) detects + catalogs cookie | ✅ `_actest_ga` → analytics, linked to script |
| REST `GET /amplifi-consent/v1/config` (metadata only, no script bodies) | ✅ |
| All PHP `php -l` clean | ✅ |

Teardown: `docker compose down -v` (nukes volumes).
