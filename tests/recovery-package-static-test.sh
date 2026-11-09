#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGE_REL='recovery/flyboard-full-panel-v13-recovered'
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# Rebuild only from Git-tracked files so ignored local files cannot hide a broken package.
git -C "$ROOT_DIR" archive --format=tar HEAD "$PACKAGE_REL" | tar -xf - -C "$TMP_DIR"
PACKAGE_DIR="$TMP_DIR/$PACKAGE_REL"

bash -n "$PACKAGE_DIR/install.sh"
(cd "$PACKAGE_DIR" && sha256sum -c CANDIDATE-SHA256SUMS >/dev/null)
(cd "$PACKAGE_DIR/payload" && sha256sum -c ../PAYLOAD-SHA256SUMS >/dev/null)

test -f "$PACKAGE_DIR/payload/compose.yaml"
test -f "$PACKAGE_DIR/payload/compose.bootstrap.yaml"
test -f "$PACKAGE_DIR/payload/overrides/flyboard-restored/MachineController.php"
grep -Fq './overrides/flyboard-restored/MachineController.php:/www/app/Http/Controllers/V2/Admin/Server/MachineController.php:ro' \
  "$PACKAGE_DIR/payload/compose.yaml"
grep -Fq '09cf31b62d667bbfc55124f5d691a571c090f8c7/install.sh' \
  "$PACKAGE_DIR/payload/overrides/flyboard-restored/MachineController.php"

if grep -Fq '.env.install' "$PACKAGE_DIR/install.sh"; then
  echo 'installer must not depend on ignored .env.install' >&2
  exit 1
fi
grep -Fq ': > "$INSTALL_DIR/.env"' "$PACKAGE_DIR/install.sh"

# Exercise the failed stage with a fake Docker CLI. The installer must create .env,
# reach the image pull, then remove the isolated install directory on failure.
mkdir -p "$TMP_DIR/fake-bin"
cat > "$TMP_DIR/fake-bin/docker" <<'EOF'
#!/usr/bin/env bash
if [[ "$*" == 'compose version' ]]; then
  exit 0
fi
if [[ "${1:-}" == 'ps' ]]; then
  exit 0
fi
if [[ "$*" == image\ inspect* ]]; then
  exit 1
fi
if [[ "${1:-}" == 'pull' ]]; then
  exit 42
fi
exit 0
EOF
chmod +x "$TMP_DIR/fake-bin/docker"

SMOKE_DIR="$TMP_DIR/smoke-install"
set +e
PATH="$TMP_DIR/fake-bin:$PATH" \
INSTALL_DIR="$SMOKE_DIR" \
PANEL_PORT=57247 \
PROJECT_NAME=flyboard_test \
ADMIN_EMAIL=admin@example.com \
PUBLIC_URL=http://127.0.0.1:57247 \
bash "$PACKAGE_DIR/install.sh" >"$TMP_DIR/smoke.log" 2>&1
smoke_rc=$?
set -e

[[ "$smoke_rc" -ne 0 ]]
grep -Fq '镜像拉取失败' "$TMP_DIR/smoke.log"
if grep -Fq '.env.install' "$TMP_DIR/smoke.log"; then
  cat "$TMP_DIR/smoke.log" >&2
  exit 1
fi
[[ ! -e "$SMOKE_DIR" ]]

echo 'recovery package static tests passed'
