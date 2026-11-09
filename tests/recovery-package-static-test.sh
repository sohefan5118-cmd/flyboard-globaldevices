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

echo 'recovery package static tests passed'
