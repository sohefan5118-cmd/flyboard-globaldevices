#!/usr/bin/env bash
set -euo pipefail

# XBoard node global device-limit patched binary installer/replacer.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/OWNER/REPO/main/scripts/install-xboard-node-globaldevices2.sh | bash
# Optional env:
#   REPO="OWNER/REPO" BRANCH="main" SERVICE="xboard-node.service" bash install-xboard-node-globaldevices2.sh

REPO="${REPO:-sohefan5118-cmd/flyboard-globaldevices}"
BRANCH="${BRANCH:-main}"
SERVICE="${SERVICE:-xboard-node.service}"
BIN="${BIN:-/usr/local/bin/xboard-node}"
CONFIG="${CONFIG:-/etc/xboard-node/config.yml}"
EXPECTED_SHA256="${EXPECTED_SHA256:-30bb575783e802f185aaa40759ead30e16d7b695e989686cb9270b2be12c260b}"
VERSION_EXPECT="xboard-node globaldevices5-device-limit2"
TMP_DIR="$(mktemp -d)"
TMP_GZ="$TMP_DIR/xboard-node-global-device-linux-amd64.gz"
TMP_BIN="$TMP_DIR/xboard-node"
TS="$(date +%Y%m%d%H%M%S)"
URL="https://raw.githubusercontent.com/${REPO}/${BRANCH}/dist/xboard-node-global-device-linux-amd64.gz"

cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "ERROR: missing command: $1" >&2; exit 1; }; }
need_cmd uname
need_cmd sha256sum
need_cmd gzip
need_cmd systemctl
if command -v curl >/dev/null 2>&1; then
  DL_TOOL="curl"
elif command -v wget >/dev/null 2>&1; then
  DL_TOOL="wget"
else
  echo "ERROR: need curl or wget" >&2
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run as root" >&2
  exit 1
fi

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) ;;
  *) echo "ERROR: this binary is amd64/x86_64 only, current arch: $ARCH" >&2; exit 1 ;;
esac

if [ ! -f "$CONFIG" ]; then
  echo "ERROR: $CONFIG not found. Install/configure xboard-node against your Flyboard panel first." >&2
  exit 1
fi

if [ ! -x "$BIN" ]; then
  echo "ERROR: $BIN not found/executable. Install xboard-node first, then run this replacer." >&2
  exit 1
fi

PANEL_URL="$(sed -n 's/^[[:space:]]*url:[[:space:]]*//p' "$CONFIG" | head -n1)"
MACHINE_ID="$(sed -n 's/^[[:space:]]*machine_id:[[:space:]]*//p' "$CONFIG" | head -n1 | tr -d '"')"
TOKEN="$(sed -n 's/^[[:space:]]*token:[[:space:]]*//p' "$CONFIG" | head -n1 | tr -d '"')"
if [ -z "$PANEL_URL" ] || [ -z "$MACHINE_ID" ] || [ -z "$TOKEN" ]; then
  echo "ERROR: unable to parse panel url / machine_id / token from $CONFIG" >&2
  exit 1
fi
DEVICE_URL="${PANEL_URL%/}/api/v2/server/devices?machine_id=${MACHINE_ID}&node_id=${MACHINE_ID}&token=${TOKEN}"
if [ "$DL_TOOL" = "curl" ]; then
  DL=(curl -fL --retry 3 --connect-timeout 15 -o "$TMP_GZ" "$URL")
  FETCH_JSON=(curl -fsS --max-time 15 -o "$TMP_DIR/preflight-devices.json" "$DEVICE_URL")
else
  DL=(wget -O "$TMP_GZ" "$URL")
  FETCH_JSON=(wget -qO "$TMP_DIR/preflight-devices.json" "$DEVICE_URL")
fi

echo "== Preflight panel devices route =="
if ! "${FETCH_JSON[@]}"; then
  echo "ERROR: cannot reach $DEVICE_URL" >&2
  echo "ERROR: this usually means the running panel is missing /api/v2/server/devices or the token is invalid." >&2
  exit 1
fi

echo "== Current xboard-node version =="
"$BIN" -v 2>/dev/null || true

echo "== Download patched binary =="
echo "$URL"
"${DL[@]}"
gzip -dc "$TMP_GZ" > "$TMP_BIN"
chmod 0755 "$TMP_BIN"

ACTUAL_SHA256="$(sha256sum "$TMP_BIN" | awk '{print $1}')"
echo "actual:   $ACTUAL_SHA256"
echo "expected: $EXPECTED_SHA256"
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
  echo "ERROR: SHA256 mismatch. Abort." >&2
  exit 1
fi

echo "== Verify patched version =="
NEW_VERSION="$($TMP_BIN -v 2>/dev/null || true)"
echo "$NEW_VERSION"
case "$NEW_VERSION" in
  *"$VERSION_EXPECT"*) ;;
  *) echo "ERROR: unexpected version output" >&2; exit 1 ;;
esac

echo "== Backup current binary =="
cp -a "$BIN" "${BIN}.bak-globaldevices-${TS}"

echo "== Install patched binary =="
install -m 0755 "$TMP_BIN" "$BIN"

echo "== Restart service =="
systemctl daemon-reload
systemctl restart "$SERVICE"

echo "== Service status =="
systemctl is-active "$SERVICE"
systemctl status "$SERVICE" --no-pager -l | sed -n '1,30p'

echo "== Post-install self-check =="
sleep 8
RECENT_LOGS="$(journalctl -u "$SERVICE" --since '2 minutes ago' --no-pager || true)"
if printf '%s\n' "$RECENT_LOGS" | grep -q 'api/v2/server/devices could not be found'; then
  echo "ERROR: node still reports /api/v2/server/devices as missing after install." >&2
  echo "ERROR: the panel-side /api/v2/server/devices route is not available to this node yet." >&2
  exit 1
fi

echo "== Recent logs =="
printf '%s\n' "$RECENT_LOGS" | tail -n 80

echo "DONE: installed $VERSION_EXPECT"
echo "Rollback example: cp -a ${BIN}.bak-globaldevices-${TS} ${BIN} && systemctl restart ${SERVICE}"
