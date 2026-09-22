#!/usr/bin/env bash
# Build one release APK locally (optional) and upload it to the Railway downloads volume.
# Web stays on Git deploy; APKs are NOT built on Railway and are NOT in the Docker image.
#
# Usage:
#   ./scripts/upload_apks.sh              # upload EbubeConnect.apk from downloads/apks/
#   ./scripts/upload_apks.sh build        # flutter build apk, then upload
#   ./scripts/upload_apks.sh upload       # upload only (same as default)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MODE="${1:-upload}"
API_BASE="${API_BASE:-https://ebubeconnect.com/backend}"
VOLUME_NAME="${RAILWAY_APK_VOLUME:-web-downloads-apks}"
APK_DIR="$ROOT/downloads/apks"
APK_NAME="EbubeConnect.apk"
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

build_apk() {
  if ! command -v flutter >/dev/null 2>&1; then
    echo "flutter not found. Set FLUTTER_BIN or add Flutter to PATH."
    exit 1
  fi

  echo "=== Flutter release APK ($API_BASE) ==="
  (
    cd "$ROOT/app"
    flutter build apk --release --dart-define="API_BASE=$API_BASE"
  )

  local out="$ROOT/app/build/app/outputs/flutter-apk"
  cp -f "$out/app-release.apk" "$APK_DIR/$APK_NAME"
  # Drop any leftover split APKs so only one file is published.
  rm -f "$APK_DIR"/EbubeConnect-arm*.apk
  du -sh "$APK_DIR/$APK_NAME"
}

seed_from_legacy_downloads() {
  if [[ -f "$APK_DIR/$APK_NAME" ]]; then
    return 0
  fi
  if [[ -f "$ROOT/downloads/$APK_NAME" ]]; then
    echo "Seeding $APK_DIR from downloads/$APK_NAME"
    cp -f "$ROOT/downloads/$APK_NAME" "$APK_DIR/"
  fi
}

upload_apk() {
  need_railway
  ensure_volume
  seed_from_legacy_downloads

  if [[ ! -f "$APK_DIR/$APK_NAME" ]]; then
    echo "No $APK_NAME in $APK_DIR"
    echo "Run: ./scripts/upload_apks.sh build"
    exit 1
  fi

  echo "=== Uploading $APK_NAME to volume $VOLUME_NAME ==="
  local htaccess="$APK_DIR/.htaccess"
  if [[ -f "$htaccess" ]]; then
    echo "  -> /.htaccess (Content-Disposition rules)"
    railway volume files --volume "$VOLUME_NAME" upload "$htaccess" "/.htaccess" --overwrite
  fi
  echo "  -> /$APK_NAME ($(du -h "$APK_DIR/$APK_NAME" | awk '{print $1}'))"
  railway volume files --volume "$VOLUME_NAME" upload "$APK_DIR/$APK_NAME" "/$APK_NAME" --overwrite

  # Remove old split APKs from the volume if present.
  for stale in EbubeConnect-arm64.apk EbubeConnect-arm.apk; do
    railway volume files --volume "$VOLUME_NAME" delete "/$stale" --yes >/dev/null 2>&1 || true
  done

  echo "Done."
  echo "  Page: https://ebubeconnect.com/downloads/"
  echo "  APK:  https://ebubeconnect.com/downloads/apks/$APK_NAME"
}

case "$MODE" in
  build|all)
    build_apk
    upload_apk
    ;;
  upload|push)
    upload_apk
    ;;
  *)
    echo "Usage: $0 [upload|build]"
    echo "  upload (default) — upload downloads/apks/$APK_NAME to Railway volume"
    echo "  build            — flutter build apk locally, then upload"
    exit 1
    ;;
esac
