#!/usr/bin/env bash
# Deploy EbubeConnect to Railway (web service).
# Requires: flutter on PATH (or FLUTTER_BIN), railway CLI logged in.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

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

API_BASE="${API_BASE:-https://ebubeconnect.com/backend}"
SERVICE="${RAILWAY_SERVICE:-web}"

echo "=== Flutter web ($API_BASE) ==="
(
  cd app
  # CanvasKit via Google CDN (~37MB not shipped in the image) → much faster first paint.
  flutter build web --release --base-href /agent/ \
    --web-resources-cdn \
    --pwa-strategy=none \
    --no-wasm-dry-run \
    --dart-define="API_BASE=$API_BASE"
  # Build still copies canvaskit/; drop it so Docker/Railway upload stays ~7MB.
  rm -rf build/web/canvaskit
  du -sh build/web
)

echo "=== railway up --service $SERVICE ==="
railway up --service "$SERVICE" --detach

echo "Done. Site: https://ebubeconnect.com/agent/"
echo "Health: https://ebubeconnect.com/backend/api_check.php"
