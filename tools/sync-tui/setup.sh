#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

# ── Colors ───────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

ok()   { printf "${GREEN}[ok]${NC}   %s\n" "$1"; }
warn() { printf "${YELLOW}[warn]${NC} %s\n" "$1"; }
fail() { printf "${RED}[fail]${NC} %s\n" "$1"; exit 1; }

echo ""
echo "  amplifi.sync — setup"
echo "  ────────────────────"
echo ""

# ── 1. Check Go ──────────────────────────────────────────────────────
if ! command -v go &>/dev/null; then
  fail "Go is not installed. Install it from https://go.dev/dl/"
fi

GO_VERSION=$(go version | grep -oE 'go[0-9]+\.[0-9]+' | head -1)
ok "Go found: $GO_VERSION"

# ── 2. Download dependencies ─────────────────────────────────────────
echo ""
echo "  Downloading dependencies..."
go mod download
ok "Dependencies downloaded"

# ── 3. Build ─────────────────────────────────────────────────────────
echo ""
echo "  Building..."
go build -o amplifi-sync .
ok "Binary built: ./amplifi-sync"

# ── 4. Run tests ─────────────────────────────────────────────────────
echo ""
echo "  Running tests..."
go test ./... -count=1
ok "All tests passed"

# ── 5. Set up .env ───────────────────────────────────────────────────
echo ""
if [ -f .env ]; then
  ok ".env already exists — skipping"
else
  cp .env.example .env
  warn "Created .env from .env.example — edit it with your site credentials"
fi

# ── 6. Check SSH key ────────────────────────────────────────────────
echo ""
DEFAULT_KEY="$HOME/.ssh/wpengine_rsa"
if [ -f "$DEFAULT_KEY" ]; then
  ok "SSH key found: $DEFAULT_KEY"
elif [ -f "$HOME/.ssh/id_rsa" ]; then
  warn "No WP Engine key at $DEFAULT_KEY, but ~/.ssh/id_rsa exists"
  warn "Update SSH_KEY_PATH in .env if needed"
else
  warn "No SSH key found — you'll need one for SFTP/SSH operations"
  warn "Generate with: ssh-keygen -t ed25519 -f ~/.ssh/wpengine_rsa"
fi

# ── 7. WP plugin reminder ───────────────────────────────────────────
echo ""
echo "  ────────────────────"
echo ""
echo "  Next steps:"
echo "    1. Install the ac-sync plugin on both prod and staging WordPress sites"
echo "    2. Copy the API key from each site's amplifi.studio > Sync settings page"
echo "    3. Edit .env with your site URLs, API keys, and SFTP credentials"
echo "    4. Run: ./amplifi-sync"
echo ""
echo "  Or skip step 3 — the TUI has a config wizard that runs on first launch."
echo ""
