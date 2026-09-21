#!/usr/bin/env bash
# Build APKs locally (optional) and upload them to the Railway downloads volume.
# Web stays on Git deploy; APKs are NOT built on Railway and are NOT in the Docker image.
#
# Usage:
#   ./scripts/upload_apks.sh              # upload existing files in downloads/apks/
#   ./scripts/upload_apks.sh build        # flutter build apk(s), then upload
#   ./scripts/upload_apks.sh upload       # upload only (same as default)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MODE="${1:-upload}"
API_BASE="${API_BASE:-https://ebubeconnect.com/backend}"
VOLUME_NAME="${RAILWAY_APK_VOLUME:-web-downloads-apks}"
APK_DIR="$ROOT/downloads/apks"
MOUNT_PATH="/var/www/html/downloads/apks"
SERVICE="${RAILWAY_SERVICE:-web}"
PROJECT_ID="${RAILWAY_PROJECT_ID:-273654ef-2d18-4889-9626-5b645a3b4f89}"
ENVIRONMENT_ID="${RAILWAY_ENVIRONMENT_ID:-a7373e2d-5318-4f2c-a818-d66985f50f68}"
SERVICE_ID="${RAILWAY_SERVICE_ID:-4a0b8cb6-ff2e-4904-b256-e1ccb827b40b}"

FLUTTER_BIN="${FLUTTER_BIN:-$HOME/Development/flutter/bin}"
export PATH="$FLUTTER_BIN:$PATH"

mkdir -p "$APK_DIR"

need_railway() {
  if ! command -v railway >/dev/null 2>&1; then
    echo "railway CLI not found. Install: https://docs.railway.com/guides/cli"
    exit 1
  fi
}

resolve_volume_name() {
  railway volume list --json | python3 -c "
import json,sys
data=json.load(sys.stdin)
for v in data.get('volumes') or []:
  mount=(v.get('mountPath') or '').rstrip('/')
  name=v.get('name') or ''
  if mount == '${MOUNT_PATH}'.rstrip('/') or name == '${VOLUME_NAME}':
    print(name)
    break
"
}

ensure_volume() {
  need_railway
  local found
  found="$(resolve_volume_name || true)"
  if [[ -n "${found:-}" ]]; then
    VOLUME_NAME="$found"
    echo "Using volume: $VOLUME_NAME"
    return 0
  fi

  echo "Creating Railway volume at $MOUNT_PATH (via API)..."
  # CLI 'volume add' currently panics in some versions; GraphQL is reliable.
  railway api --raw-var projectId="$PROJECT_ID" \
    --raw-var environmentId="$ENVIRONMENT_ID" \
    --raw-var serviceId="$SERVICE_ID" \
    --raw-var mountPath="$MOUNT_PATH" \
    'mutation($projectId: String!, $environmentId: String!, $serviceId: String!, $mountPath: String!) {
      volumeCreate(input: {
        projectId: $projectId
        environmentId: $environmentId
        serviceId: $serviceId
        mountPath: $mountPath
      }) { id name }
    }' >/tmp/ebube-apk-volume.json

  found="$(python3 -c "import json; print(json.load(open('/tmp/ebube-apk-volume.json'))['data']['volumeCreate']['name'])")"
  if [[ -n "$found" && "$found" != "$VOLUME_NAME" ]]; then
    railway volume update --volume "$found" --name "$VOLUME_NAME" >/dev/null 2>&1 || true
  fi
  VOLUME_NAME="$(resolve_volume_name || echo "$VOLUME_NAME")"
  echo "Created volume: $VOLUME_NAME — waiting for service redeploy..."
  sleep 60
}

build_apks() {
  if ! command -v flutter >/dev/null 2>&1; then
    echo "flutter not found. Set FLUTTER_BIN or add Flutter to PATH."
    exit 1
  fi

  echo "=== Flutter release APKs ($API_BASE) ==="
  (
    cd "$ROOT/app"
    flutter build apk --release --dart-define="API_BASE=$API_BASE"
    flutter build apk --release --split-per-abi --dart-define="API_BASE=$API_BASE"
  )

  local out="$ROOT/app/build/app/outputs/flutter-apk"
  cp -f "$out/app-release.apk" "$APK_DIR/EbubeConnect.apk"
  cp -f "$out/app-arm64-v8a-release.apk" "$APK_DIR/EbubeConnect-arm64.apk"
  cp -f "$out/app-armeabi-v7a-release.apk" "$APK_DIR/EbubeConnect-arm.apk"
  du -sh "$APK_DIR"/EbubeConnect*.apk
}

seed_from_legacy_downloads() {
  if compgen -G "$APK_DIR/EbubeConnect*.apk" >/dev/null; then
    return 0
  fi
  if [[ -f "$ROOT/downloads/EbubeConnect.apk" ]]; then
    echo "Seeding $APK_DIR from downloads/*.apk"
    cp -f "$ROOT/downloads/EbubeConnect.apk" "$APK_DIR/" 2>/dev/null || true
    cp -f "$ROOT/downloads/EbubeConnect-arm64.apk" "$APK_DIR/" 2>/dev/null || true
    cp -f "$ROOT/downloads/EbubeConnect-arm.apk" "$APK_DIR/" 2>/dev/null || true
  fi
}

upload_apks() {
  need_railway
  ensure_volume
  seed_from_legacy_downloads

  if ! compgen -G "$APK_DIR/EbubeConnect*.apk" >/dev/null; then
    echo "No APKs in $APK_DIR"
    echo "Run: ./scripts/upload_apks.sh build"
    exit 1
  fi

  echo "=== Uploading APKs to volume $VOLUME_NAME ==="
  local f base
  for f in "$APK_DIR"/EbubeConnect*.apk; do
    base="$(basename "$f")"
    echo "  -> /$base ($(du -h "$f" | awk '{print $1}'))"
    # -v before `files` avoids an interactive volume picker on some CLI versions.
    railway volume -v "$VOLUME_NAME" files upload "$f" "/$base" --overwrite
  done

  echo "Done."
  echo "  Page: https://ebubeconnect.com/downloads/"
  echo "  Fat:  https://ebubeconnect.com/downloads/apks/EbubeConnect.apk"
}

case "$MODE" in
  build|all)
    build_apks
    upload_apks
    ;;
  upload|push)
    upload_apks
    ;;
  *)
    echo "Usage: $0 [upload|build]"
    echo "  upload (default) — upload downloads/apks/*.apk to Railway volume"
    echo "  build            — flutter build apk locally, then upload"
    exit 1
    ;;
esac
