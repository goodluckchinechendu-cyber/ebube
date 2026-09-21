#!/usr/bin/env bash
# Deploy EbubeConnect.
#
# Preferred: Git deploy — commit + push; Railway builds Flutter web in Docker.
# Fallback: local Flutter build + `railway up` (CLI).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MODE="${1:-git}"
API_BASE="${API_BASE:-https://ebubeconnect.com/backend}"
SERVICE="${RAILWAY_SERVICE:-web}"

if [[ "$MODE" == "git" || "$MODE" == "push" ]]; then
  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Not a git repo. Initialize git first."
    exit 1
  fi
  if [[ -z "$(git remote 2>/dev/null)" ]]; then
    echo "No git remote configured. Add GitHub origin, then: git push -u origin main"
    exit 1
  fi
  BRANCH="$(git rev-parse --abbrev-ref HEAD)"
  echo "=== Git push ($BRANCH) → Railway builds Dockerfile ==="
  git push -u origin "$BRANCH"
  echo "Done. Railway will build Flutter web + PHP from Git."
  echo "Site: https://ebubeconnect.com/agent/"
  exit 0
fi

if [[ "$MODE" != "cli" && "$MODE" != "up" ]]; then
  echo "Usage: $0 [git|cli]"
  echo "  git (default) — push to origin; Railway builds"
  echo "  cli           — local flutter build + railway up"
  exit 1
fi

FLUTTER_BIN="${FLUTTER_BIN:-$HOME/Development/flutter/bin}"
export PATH="$FLUTTER_BIN:$PATH"

if ! command -v flutter >/dev/null 2>&1; then
  echo "flutter not found. Set FLUTTER_BIN or add Flutter to PATH."
  exit 1
fi
if ! command -v railway >/dev/null 2>&1; then
  echo "railway CLI not found. Install: https://docs.railway.com/guides/cli"
  exit 1
fi

echo "=== Flutter web ($API_BASE) ==="
(
  cd app
  flutter build web --release --base-href /agent/ \
    --web-resources-cdn \
    --dart-define="API_BASE=$API_BASE"
  rm -rf build/web/canvaskit
  du -sh build/web
)

echo "=== railway up --service $SERVICE ==="
railway up --service "$SERVICE" --detach

echo "Done. Site: https://ebubeconnect.com/agent/"
echo "Health: https://ebubeconnect.com/backend/api_check.php"
