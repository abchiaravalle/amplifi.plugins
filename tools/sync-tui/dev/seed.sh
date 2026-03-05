#!/usr/bin/env bash
# seed.sh — Installs WordPress on both dev containers, seeds different content,
#            imports Picsum photos, and writes amplifi-sync.json.
set -euo pipefail
cd "$(dirname "$0")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { printf "${GREEN}[ok]${NC}   %s\n" "$1"; }
info() { printf "${YELLOW}[..] %s${NC}\n" "$1"; }

PROD_URL="http://localhost:8091"
STAGING_URL="http://localhost:8092"
ADMIN_USER="admin"
ADMIN_PASS="password"
ADMIN_EMAIL="admin@example.com"

# WP-CLI via dedicated CLI containers.
# --no-deps: don't try to re-start already-running dependency containers.
wpcli_prod()    { docker-compose run --rm --no-deps -T wpcli_prod    "$@"; }
wpcli_staging() { docker-compose run --rm --no-deps -T wpcli_staging "$@"; }

# ── 1. Bring up containers ─────────────────────────────────────────────────────
info "Starting containers..."
# Start only the web+db services (wpcli containers are run on demand).
docker-compose up -d db_prod wordpress_prod db_staging wordpress_staging

# ── 2. Wait for WordPress to be reachable ──────────────────────────────────────
wait_wp() {
  local name="$1" url="$2"
  info "Waiting for $name to be reachable..."
  for i in $(seq 1 60); do
    if curl -sf "$url/wp-login.php" -o /dev/null 2>/dev/null; then
      ok "$name is up"
      return 0
    fi
    sleep 3
  done
  echo "ERROR: $name never became reachable at $url" >&2
  exit 1
}
wait_wp "prod"    "$PROD_URL"
wait_wp "staging" "$STAGING_URL"

# ── 3. Install WordPress on both sites ────────────────────────────────────────
install_wp() {
  local fn="$1" url="$2" title="$3"
  if $fn core is-installed 2>/dev/null; then
    ok "WordPress already installed on $title"
    return
  fi
  info "Installing WordPress on $title..."
  $fn core install \
    --url="$url" \
    --title="$title" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
  ok "WordPress installed on $title"
}
install_wp wpcli_prod    "$PROD_URL"    "Amplifi Prod"
install_wp wpcli_staging "$STAGING_URL" "Amplifi Staging"

# ── 4. Copy shared framework into plugin and activate ─────────────────────────
info "Preparing ac-sync plugin..."
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SHARED_FRAMEWORK="${SCRIPT_DIR}/../../../shared/amplifi-framework.php"
PLUGIN_INCLUDES="${SCRIPT_DIR}/../../../plugins/ac-sync/includes"
if [[ -f "$SHARED_FRAMEWORK" ]]; then
  cp "$SHARED_FRAMEWORK" "${PLUGIN_INCLUDES}/amplifi-framework.php"
  ok "Copied amplifi-framework.php into plugin"
else
  echo "WARNING: shared/amplifi-framework.php not found — plugin may fail to load" >&2
fi

info "Activating ac-sync plugin..."
wpcli_prod    plugin activate ac-sync 2>&1 | grep -v "^$" | head -5 || true
wpcli_staging plugin activate ac-sync 2>&1 | grep -v "^$" | head -5 || true
ok "ac-sync activated"

# ── 5. Ensure uploads directory is writable ───────────────────────────────────
info "Setting up uploads directory..."
docker-compose exec -T wordpress_prod    bash -c "mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads && chmod -R 755 /var/www/html/wp-content/uploads" 2>/dev/null || true
docker-compose exec -T wordpress_staging bash -c "mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads && chmod -R 755 /var/www/html/wp-content/uploads" 2>/dev/null || true
ok "Uploads directory ready"

# ── 6. Seed production: categories + 10 posts + Picsum images ─────────────────
info "Seeding production content..."

# Categories — create if absent, fetch term_id if already exists.
get_or_create_term() {
  local fn="$1" name="$2"
  local id
  id=$($fn term create category "$name" --porcelain 2>/dev/null) && { echo "$id"; return 0; }
  $fn term list category --name="$name" --field=term_id 2>/dev/null
}
CAT_TECH=$( get_or_create_term wpcli_prod "Technology")
CAT_DESIGN=$(get_or_create_term wpcli_prod "Design")
CAT_BUSINESS=$(get_or_create_term wpcli_prod "Business")

# Import 8 Picsum photos as media on prod.
PICSUM_IDS=(10 20 30 40 50 60 70 80)
declare -a PROD_MEDIA_IDS=()
info "Importing Picsum images to prod media library..."
for pid in "${PICSUM_IDS[@]}"; do
  MID=$(wpcli_prod media import "https://picsum.photos/id/${pid}/1200/800.jpg" \
    --title="Photo ${pid}" --porcelain 2>/dev/null || echo "")
  if [[ -n "$MID" ]]; then
    PROD_MEDIA_IDS+=("$MID")
    ok "  Imported picsum id=${pid} → attachment #${MID}"
  else
    info "  Skipped picsum id=${pid} (import failed)"
  fi
done

# 10 prod posts with varied content.
declare -a PROD_POST_IDS=()
create_prod_post() {
  local title="$1" cat="$2" content="$3"
  local id
  id=$(wpcli_prod post create \
    --post_title="$title" \
    --post_content="$content" \
    --post_status=publish \
    --post_category="$cat" \
    --porcelain)
  PROD_POST_IDS+=("$id")
  echo "$id"
}

P1=$(create_prod_post "The Future of AI in Web Development" "$CAT_TECH" \
  "<p>Artificial intelligence is reshaping how we build websites. From code completion to automated testing, the tools available to developers in 2024 are remarkable.</p><p>Learn how to leverage AI tools to ship faster without sacrificing quality.</p>")

P2=$(create_prod_post "Design Systems at Scale" "$CAT_DESIGN" \
  "<p>A well-crafted design system is the backbone of consistent, maintainable product UI. This post covers the principles behind building one that actually lasts.</p>")

P3=$(create_prod_post "WordPress Performance: From 3s to 300ms" "$CAT_TECH" \
  "<p>We reduced Time to First Byte from 3 seconds to under 300ms using static caching, CDN configuration, and database query optimization.</p>")

P4=$(create_prod_post "Typography Fundamentals for Digital Products" "$CAT_DESIGN" \
  "<p>Type is the voice of your UI. This guide covers line height, measure, scale, and the font choices that make interfaces legible and beautiful.</p>")

P5=$(create_prod_post "SaaS Pricing Psychology" "$CAT_BUSINESS" \
  "<p>The way you price your product affects how customers perceive its value. Explore the cognitive science behind pricing pages that convert.</p>")

P6=$(create_prod_post "Building a Developer-Friendly API" "$CAT_TECH" \
  "<p>Great APIs are discoverable, consistent, and forgiving. Here's a checklist for designing REST APIs that developers love to use.</p>")

P7=$(create_prod_post "Color Theory for UI Designers" "$CAT_DESIGN" \
  "<p>Understanding hue, saturation, and luminance is the foundation of accessible, beautiful color palettes. We walk through a practical framework.</p>")

P8=$(create_prod_post "The Compound Effect of Content Marketing" "$CAT_BUSINESS" \
  "<p>Content marketing rarely shows results in week one — but consistent publishing creates a compounding flywheel of organic traffic over time.</p>")

P9=$(create_prod_post "Docker for WordPress Development" "$CAT_TECH" \
  "<p>Running WordPress in Docker removes the 'works on my machine' problem and gives every team member a reproducible environment in minutes.</p>")

P10=$(create_prod_post "Accessible Forms: Beyond Basic Validation" "$CAT_DESIGN" \
  "<p>Forms are where users commit to your product. This post covers error states, inline validation, ARIA labels, and keyboard navigation.</p>")

ok "Created 10 production posts"

# Assign featured images to posts.
assign_feat() {
  local post_id="$1" media_id="$2"
  wpcli_prod post meta set "$post_id" _thumbnail_id "$media_id" 2>/dev/null || true
}
if [[ ${#PROD_MEDIA_IDS[@]} -ge 4 ]]; then
  assign_feat "$P1" "${PROD_MEDIA_IDS[0]}"
  assign_feat "$P2" "${PROD_MEDIA_IDS[1]}"
  assign_feat "$P3" "${PROD_MEDIA_IDS[2]}"
  assign_feat "$P4" "${PROD_MEDIA_IDS[3]}"
  [[ ${#PROD_MEDIA_IDS[@]} -ge 5 ]] && assign_feat "$P5" "${PROD_MEDIA_IDS[4]}"
  [[ ${#PROD_MEDIA_IDS[@]} -ge 6 ]] && assign_feat "$P6" "${PROD_MEDIA_IDS[5]}"
  ok "Assigned featured images"
fi

# ── 7. Seed staging: 5 posts (3 overlap with prod, 2 unique, 1 modified) ───────
info "Seeding staging content..."

# Import only 4 of the 8 images (creates a media diff).
STAGING_PICSUM_IDS=(10 20 30 40)
declare -a STAGING_MEDIA_IDS=()
info "Importing Picsum images to staging media library..."
for pid in "${STAGING_PICSUM_IDS[@]}"; do
  MID=$(wpcli_staging media import "https://picsum.photos/id/${pid}/1200/800.jpg" \
    --title="Photo ${pid}" --porcelain 2>/dev/null || echo "")
  if [[ -n "$MID" ]]; then
    STAGING_MEDIA_IDS+=("$MID")
    ok "  Imported picsum id=${pid} → attachment #${MID}"
  else
    info "  Skipped picsum id=${pid} (import failed)"
  fi
done

# 5 staging posts.
create_staging_post() {
  local title="$1" content="$2"
  wpcli_staging post create \
    --post_title="$title" \
    --post_content="$content" \
    --post_status=publish \
    --porcelain
}

S1=$(create_staging_post "The Future of AI in Web Development" \
  "<p>Artificial intelligence is reshaping how we build websites. From code completion to automated testing, the tools available to developers in 2024 are remarkable.</p><p>Learn how to leverage AI tools to ship faster without sacrificing quality.</p><p><em>[STAGING — draft edit: add case studies section]</em></p>")

S2=$(create_staging_post "Design Systems at Scale" \
  "<p>A well-crafted design system is the backbone of consistent, maintainable product UI. This post covers the principles behind building one that actually lasts.</p>")

S3=$(create_staging_post "WordPress Performance: From 3s to 300ms" \
  "<p>We reduced Time to First Byte from 3 seconds to under 300ms using static caching, CDN configuration, and database query optimization.</p>")

S4=$(create_staging_post "Staging-Only: UX Writing Principles" \
  "<p>Words are design. This post — only on staging — covers voice, tone, microcopy, and how to write error messages people actually understand.</p>")

S5=$(create_staging_post "Staging-Only: Component-Driven Development" \
  "<p>Building UI components in isolation using Storybook creates a shareable library and forces good API design. Here is how to get started.</p>")

ok "Created 5 staging posts"

# Assign featured images on staging.
if [[ ${#STAGING_MEDIA_IDS[@]} -ge 2 ]]; then
  wpcli_staging post meta set "$S1" _thumbnail_id "${STAGING_MEDIA_IDS[0]}" 2>/dev/null || true
  wpcli_staging post meta set "$S2" _thumbnail_id "${STAGING_MEDIA_IDS[1]}" 2>/dev/null || true
  ok "Assigned staging featured images"
fi

# ── 8. Get API keys ────────────────────────────────────────────────────────────
info "Fetching API keys..."
PROD_SETTINGS=$(wpcli_prod    option get acsync_settings --format=json 2>/dev/null || echo "{}")
STAGING_SETTINGS=$(wpcli_staging option get acsync_settings --format=json 2>/dev/null || echo "{}")

# Extract api_key — strips whitespace first so it handles both compact
# {"api_key":"val"} and spaced {"api_key": "val"} formats.
# || true prevents pipefail from triggering when grep finds no match.
extract_key() {
  echo "$1" | tr -d '[:space:]' | grep -o '"api_key":"[^"]*"' | cut -d'"' -f4 || true
}
PROD_API_KEY=$(extract_key "$PROD_SETTINGS")
STAGING_API_KEY=$(extract_key "$STAGING_SETTINGS")

if [[ -z "$PROD_API_KEY" || -z "$STAGING_API_KEY" ]]; then
  echo ""
  echo "  WARNING: Could not auto-read API keys."
  echo "  Visit ${PROD_URL}/wp-admin/ and ${STAGING_URL}/wp-admin/"
  echo "  Go to amplifi.studio > Sync to copy each site's API key."
  echo "  Then run: ./gen-config.sh <prod_key> <staging_key>"
  PROD_API_KEY="${PROD_API_KEY:-REPLACE_WITH_PROD_API_KEY}"
  STAGING_API_KEY="${STAGING_API_KEY:-REPLACE_WITH_STAGING_API_KEY}"
fi

# ── 9. Write amplifi-sync.json ─────────────────────────────────────────────────
CONFIG_DIR="$HOME/.amplifi-sync"
CONFIG_FILE="$CONFIG_DIR/amplifi-sync.json"
mkdir -p "$CONFIG_DIR"
chmod 700 "$CONFIG_DIR"

cat > "$CONFIG_FILE" <<JSON
{
  "version": 1,
  "backup_dir": "${CONFIG_DIR}/backups",
  "backup_retention": 10,
  "pairs": [
    {
      "name": "Dev Local",
      "prod": {
        "url": "${PROD_URL}",
        "api_key": "${PROD_API_KEY}",
        "sftp_host": "",
        "sftp_user": "",
        "ssh_key_path": ""
      },
      "staging": {
        "url": "${STAGING_URL}",
        "api_key": "${STAGING_API_KEY}",
        "sftp_host": "",
        "sftp_user": "",
        "ssh_key_path": ""
      }
    }
  ]
}
JSON
chmod 600 "$CONFIG_FILE"
ok "Wrote $CONFIG_FILE"

# ── 10. Summary ────────────────────────────────────────────────────────────────
echo ""
echo "  ────────────────────────────────────────────────────"
echo ""
echo "  Dev environment ready!"
echo ""
echo "  Production:  ${PROD_URL}/wp-admin/    (admin / password)"
echo "  Staging:     ${STAGING_URL}/wp-admin/  (admin / password)"
echo ""
echo "  Prod has:    10 posts, 8 images, 3 categories"
echo "  Staging has: 5 posts, 4 images (media diff, 2 unique posts)"
echo ""
echo "  Config written to: ${CONFIG_FILE}"
echo ""
echo "  Run the TUI from tools/sync-tui/:"
echo "    ./amplifi-sync"
echo ""
