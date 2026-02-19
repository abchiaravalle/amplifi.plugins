#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# amplifi.plugins release script
#
# Usage:
#   ./scripts/release.sh <version> [plugin-slug ...]
#
# Examples:
#   ./scripts/release.sh 1.2.0                      # Release all plugins
#   ./scripts/release.sh 1.2.0 ac-wp-translator     # Release one plugin
#
# What it does:
#   1. Validates version format (semver)
#   2. Generates changelog from git log since last tag
#   3. Zips each plugin (excluding dev files)
#   4. Creates a GitHub release with the zips and changelog
# ============================================================================

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS_DIR="${REPO_ROOT}/plugins"
DIST_DIR="${REPO_ROOT}/dist"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

die() { echo "ERROR: $*" >&2; exit 1; }

validate_version() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Invalid version '$1'. Use semver (e.g. 1.2.0)"
}

get_last_tag() {
    git describe --tags --abbrev=0 2>/dev/null || echo ""
}

generate_changelog() {
    local last_tag="$1"
    local version="$2"

    echo "# amplifi.plugins v${version}"
    echo ""
    echo "Released: $(date +%Y-%m-%d)"
    echo ""

    if [[ -n "$last_tag" ]]; then
        echo "## Changes since ${last_tag}"
        echo ""
        git log "${last_tag}..HEAD" --pretty=format:"- %s" --no-merges | grep -v "Co-Authored-By" || true
    else
        echo "## Initial Release"
        echo ""
        git log --pretty=format:"- %s" --no-merges | grep -v "Co-Authored-By" || true
    fi

    echo ""
    echo ""
    echo "---"
    echo "Built by [amplifi.studio](https://amplifi.studio)"
}

build_plugin_zip() {
    local slug="$1"
    local version="$2"
    local plugin_dir="${PLUGINS_DIR}/${slug}"
    local zip_name="${slug}-v${version}.zip"

    [[ -d "$plugin_dir" ]] || die "Plugin '${slug}' not found at ${plugin_dir}"

    echo "  Packaging ${slug}..."

    # Create a temp staging dir so the zip root is the plugin slug.
    local staging
    staging="$(mktemp -d)"
    cp -R "${plugin_dir}" "${staging}/${slug}"

    # Remove dev files from the copy.
    rm -rf "${staging}/${slug}/docker-compose.yml" \
           "${staging}/${slug}/.git" \
           "${staging}/${slug}/node_modules" \
           "${staging}/${slug}/tests" \
           "${staging}/${slug}/.env"

    # Copy shared framework into plugin.
    cp "${REPO_ROOT}/shared/amplifi-framework.php" "${staging}/${slug}/includes/amplifi-framework.php"

    # Copy LICENSE and README into the plugin zip.
    cp "${REPO_ROOT}/LICENSE" "${staging}/${slug}/LICENSE"
    [[ -f "${plugin_dir}/README.md" ]] && cp "${plugin_dir}/README.md" "${staging}/${slug}/README.md"

    # Build the zip.
    (cd "${staging}" && zip -r "${DIST_DIR}/${zip_name}" "${slug}" -x "*.DS_Store" "*/.*")

    rm -rf "${staging}"
    echo "  -> dist/${zip_name}"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

[[ $# -ge 1 ]] || die "Usage: $0 <version> [plugin-slug ...]"

VERSION="$1"
shift
validate_version "$VERSION"

# Determine which plugins to release.
if [[ $# -gt 0 ]]; then
    PLUGINS=("$@")
else
    PLUGINS=()
    for d in "${PLUGINS_DIR}"/*/; do
        [[ -d "$d" ]] && PLUGINS+=("$(basename "$d")")
    done
fi

[[ ${#PLUGINS[@]} -gt 0 ]] || die "No plugins found in ${PLUGINS_DIR}"

echo "==> Releasing amplifi.plugins v${VERSION}"
echo "    Plugins: ${PLUGINS[*]}"
echo ""

# Clean and create dist dir.
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

# Build zips.
for slug in "${PLUGINS[@]}"; do
    build_plugin_zip "$slug" "$VERSION"
done

# Generate changelog.
LAST_TAG="$(get_last_tag)"
CHANGELOG="$(generate_changelog "$LAST_TAG" "$VERSION")"
echo "$CHANGELOG" > "${DIST_DIR}/CHANGELOG.md"
echo ""
echo "==> Changelog:"
echo "$CHANGELOG"
echo ""

# Create git tag and GitHub release.
echo "==> Creating GitHub release v${VERSION}..."

TAG="v${VERSION}"
git tag -a "$TAG" -m "Release ${TAG}"
git push origin "$TAG"

# Build the gh release command with all zip assets.
ASSET_ARGS=()
for slug in "${PLUGINS[@]}"; do
    ASSET_ARGS+=("${DIST_DIR}/${slug}-v${VERSION}.zip")
done

gh release create "$TAG" \
    --title "amplifi.plugins ${TAG}" \
    --notes-file "${DIST_DIR}/CHANGELOG.md" \
    "${ASSET_ARGS[@]}"

echo ""
echo "==> Done! Release: $(gh release view "$TAG" --json url -q .url)"
