#!/usr/bin/env bash
set -euo pipefail

# Install the Flyboard/XBoard panel-side machine /devices compatibility patch.
# Run on the panel host (the server that has the XBoard Docker container).
# Optional env:
#   REPO="OWNER/REPO" BRANCH="main" CONTAINER="flyboard-xboard-1" APP_DIR="/www" bash install-panel-machine-devices.sh

REPO="${REPO:-sohefan5118/flyboard-globaldevices}"
BRANCH="${BRANCH:-main}"
CONTAINER="${CONTAINER:-flyboard-xboard-1}"
APP_DIR="${APP_DIR:-/www}"
TS="$(date +%Y%m%d%H%M%S)"
TMP_DIR="$(mktemp -d)"
BASE_URL="https://raw.githubusercontent.com/${REPO}/${BRANCH}/panel"
FILES=(
  "DeviceStateService.php:app/Services/DeviceStateService.php"
  "ServerController.php:app/Http/Controllers/V2/Server/ServerController.php"
  "V2ServerRoute.php:app/Http/Routes/V2/ServerRoute.php"
)

cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "ERROR: missing command: $1" >&2; exit 1; }; }
need_cmd docker
if command -v curl >/dev/null 2>&1; then
  fetch() { curl -fL --retry 3 --connect-timeout 15 -o "$1" "$2"; }
elif command -v wget >/dev/null 2>&1; then
  fetch() { wget -O "$1" "$2"; }
else
  echo "ERROR: need curl or wget" >&2
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run as root" >&2
  exit 1
fi

docker inspect "$CONTAINER" >/dev/null

echo "== Download panel patch files =="
for item in "${FILES[@]}"; do
  src="${item%%:*}"
  fetch "$TMP_DIR/$src" "$BASE_URL/$src"
done

echo "== Backup current panel files =="
for item in "${FILES[@]}"; do
  src="${item%%:*}"
  dst="${item#*:}"
  docker exec "$CONTAINER" sh -lc "test -f '$APP_DIR/$dst' && cp -a '$APP_DIR/$dst' '$APP_DIR/$dst.bak-globaldevices-$TS' || true"
  docker cp "$TMP_DIR/$src" "$CONTAINER:$APP_DIR/$dst"
done

echo "== PHP syntax check =="
docker exec "$CONTAINER" sh -lc "php -l '$APP_DIR/app/Services/DeviceStateService.php' && php -l '$APP_DIR/app/Http/Controllers/V2/Server/ServerController.php' && php -l '$APP_DIR/app/Http/Routes/V2/ServerRoute.php'"

echo "== Clear cache / reload Octane if available =="
docker exec "$CONTAINER" sh -lc "cd '$APP_DIR' && php artisan optimize:clear || true; php artisan octane:reload || true"

echo "== Verify route =="
docker exec "$CONTAINER" sh -lc "cd '$APP_DIR' && php artisan route:list --path=api/v2/server/devices || true"

echo "DONE: panel patch installed. Backup suffix: .bak-globaldevices-$TS"
