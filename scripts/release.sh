#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# amplifi.plugins release script
#
# Usage:
#   ./scripts/release.sh <version>
#
# Builds the single combined amplifi-plugins zip and creates a GitHub release.
# ============================================================================

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}/plugins/amplifi-plugins"
DIST_DIR="${REPO_ROOT}/dist"
SLUG="amplifi-plugins"

die() { echo "ERROR: $*" >&2; exit 1; }

validate_version() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]] || die "Invalid version '$1'. Use semver (e.g. 3.0.0 or 3.1.0-beta.1)"
}

is_prerelease() {
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

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

[[ $# -eq 1 ]] || die "Usage: $0 <version>"

VERSION="$1"
validate_version "$VERSION"

[[ -d "$PLUGIN_DIR" ]] || die "Combined plugin not found at ${PLUGIN_DIR}"

echo "==> Releasing amplifi.plugins v${VERSION}"
echo ""

# Bump version in the master bootstrap.
MAIN_FILE="${PLUGIN_DIR}/${SLUG}.php"
echo "==> Bumping version to ${VERSION}..."
perl -i -pe "s/(Version:\s+)[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?/\${1}${VERSION}/" "$MAIN_FILE"
perl -i -pe "s/(define\(\s*'[A-Z_]+VERSION',\s*')[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?'/\${1}${VERSION}'/" "$MAIN_FILE"

# Also bump version in each feature's main file (for internal consistency).
for feature_dir in "${PLUGIN_DIR}"/features/*/; do
    for f in "${feature_dir}"*.php; do
        [[ -f "$f" ]] || continue
        perl -i -pe "s/(define\(\s*'[A-Z_]+VERSION',\s*')[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?'/\${1}${VERSION}'/" "$f"
    done
done

git add -A
if ! git diff --cached --quiet; then
    git commit -m "Bump version to ${VERSION}"
fi

# Clean and create dist dir.
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

# Build the single combined zip.
echo "==> Packaging ${SLUG}..."
STAGING="$(mktemp -d)"
cp -R "${PLUGIN_DIR}" "${STAGING}/${SLUG}"

# Remove dev files.
rm -rf "${STAGING}/${SLUG}/docker-compose.yml" \
       "${STAGING}/${SLUG}/.git" \
       "${STAGING}/${SLUG}/.env"
find "${STAGING}/${SLUG}" -name 'node_modules' -type d -exec rm -rf {} + 2>/dev/null || true
find "${STAGING}/${SLUG}" -name 'vendor' -type d -exec rm -rf {} + 2>/dev/null || true
find "${STAGING}/${SLUG}" -name 'tests' -type d -exec rm -rf {} + 2>/dev/null || true
find "${STAGING}/${SLUG}" -name 'composer.json' -delete 2>/dev/null || true
find "${STAGING}/${SLUG}" -name 'composer.lock' -delete 2>/dev/null || true
find "${STAGING}/${SLUG}" -name 'phpunit.xml.dist' -delete 2>/dev/null || true
find "${STAGING}/${SLUG}" -name '.phpunit.result.cache' -delete 2>/dev/null || true

# Copy LICENSE into the zip root.
cp "${REPO_ROOT}/LICENSE" "${STAGING}/${SLUG}/LICENSE"

# Build the zip.
ZIP_NAME="${SLUG}-v${VERSION}.zip"
(cd "${STAGING}" && zip -r "${DIST_DIR}/${ZIP_NAME}" "${SLUG}" -x "*.DS_Store" "*/.*")
rm -rf "${STAGING}"
echo "  -> dist/${ZIP_NAME}"

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

PRERELEASE_FLAG=()
if is_prerelease "$VERSION"; then
    PRERELEASE_FLAG=(--prerelease)
    echo "    (marking as prerelease)"
fi

gh release create "$TAG" \
    --title "amplifi.plugins ${TAG}" \
    --notes-file "${DIST_DIR}/CHANGELOG.md" \
    ${PRERELEASE_FLAG[@]+"${PRERELEASE_FLAG[@]}"} \
    "${DIST_DIR}/${ZIP_NAME}" \
    "${DIST_DIR}/plugins-manifest.json"

echo ""
echo "==> Done! Release: $(gh release view "$TAG" --json url -q .url)"
