#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# amplifi.plugins release script
#
# Usage:
#   ./scripts/release.sh <version>
#
# Examples:
#   ./scripts/release.sh 1.2.0
#
# What it does:
#   1. Validates version format (semver)
#   2. Zips ALL plugins (excluding dev files)
#   3. Generates changelog from git log since last tag
#   4. Creates a GitHub release with the zips, manifest, and changelog
#
# Note: Always releases all plugins. The dynamic plugin hub depends on
# every plugin zip being present in the latest release.
# ============================================================================

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS_DIR="${REPO_ROOT}/plugins"
DIST_DIR="${REPO_ROOT}/dist"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

die() { echo "ERROR: $*" >&2; exit 1; }

validate_version() {
    # Accepts semver with optional pre-release suffix: 1.2.0 or 1.2.0-beta.1 or 2.0.0-rc.2
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]] || die "Invalid version '$1'. Use semver (e.g. 1.2.0 or 2.0.0-beta.1)"
}

is_prerelease() {
    # True if version contains a pre-release suffix (has a hyphen).
    [[ "$1" == *-* ]]
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

bump_plugin_version() {
    local slug="$1"
    local version="$2"
    local main_file="${PLUGINS_DIR}/${slug}/${slug}.php"

    [[ -f "$main_file" ]] || return 0

    echo "  Bumping version in ${slug}/${slug}.php to ${version}..."

    # Update "Version: X.X.X[-suffix]" in the plugin file header.
    perl -i -pe "s/(Version:\s+)[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?/\${1}${version}/" "$main_file"

    # Update define( 'AC*_VERSION', 'X.X.X[-suffix]' ) constant.
    perl -i -pe "s/(define\(\s*'[A-Z_]+VERSION',\s*')[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?'/\${1}${version}'/" "$main_file"
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

[[ $# -eq 1 ]] || die "Usage: $0 <version>"

VERSION="$1"
validate_version "$VERSION"

# Always release all plugins.
PLUGINS=()
for d in "${PLUGINS_DIR}"/*/; do
    [[ -d "$d" ]] && PLUGINS+=("$(basename "$d")")
done

[[ ${#PLUGINS[@]} -gt 0 ]] || die "No plugins found in ${PLUGINS_DIR}"

echo "==> Releasing amplifi.plugins v${VERSION}"
echo "    Plugins: ${PLUGINS[*]}"
echo ""

# Bump version numbers in all plugin main PHP files.
echo "==> Bumping version numbers to ${VERSION}..."
for slug in "${PLUGINS[@]}"; do
    bump_plugin_version "$slug" "$VERSION"
done

# Commit the version bumps.
git add -A
if ! git diff --cached --quiet; then
    git commit -m "Bump version to ${VERSION}"
fi

# Clean and create dist dir.
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

# Build zips.
for slug in "${PLUGINS[@]}"; do
    build_plugin_zip "$slug" "$VERSION"
done

# Copy manifest into dist.
cp "${REPO_ROOT}/plugins-manifest.json" "${DIST_DIR}/plugins-manifest.json"
echo "  -> dist/plugins-manifest.json"

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
ASSET_ARGS=("${DIST_DIR}/plugins-manifest.json")
for slug in "${PLUGINS[@]}"; do
    ASSET_ARGS+=("${DIST_DIR}/${slug}-v${VERSION}.zip")
done

PRERELEASE_FLAG=()
if is_prerelease "$VERSION"; then
    PRERELEASE_FLAG=(--prerelease)
    echo "    (marking as prerelease — won't be served to the auto-updater)"
fi

gh release create "$TAG" \
    --title "amplifi.plugins ${TAG}" \
    --notes-file "${DIST_DIR}/CHANGELOG.md" \
    ${PRERELEASE_FLAG[@]+"${PRERELEASE_FLAG[@]}"} \
    "${ASSET_ARGS[@]}"

echo ""
echo "==> Done! Release: $(gh release view "$TAG" --json url -q .url)"
