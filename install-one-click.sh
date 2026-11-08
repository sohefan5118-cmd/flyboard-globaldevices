#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO="${REPO:-sohefan5118-cmd/flyboard-globaldevices}"
RELEASE_TAG="${RELEASE_TAG:-v0.1.0-rc.2}"
PACKAGE_NAME="flyboard-rc2.tar.gz"
INSTALL_DIR="${INSTALL_DIR:-/opt/flyboard}"
FLYBOARD_PORT="${FLYBOARD_PORT:-${PANEL_PORT:-7001}}"
FLYBOARD_BIND="${FLYBOARD_BIND:-0.0.0.0}"
FLYBOARD_ADMIN_EMAIL="${FLYBOARD_ADMIN_EMAIL:-${ADMIN_EMAIL:-admin@localhost.invalid}}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
command -v curl >/dev/null || die "curl is required"
command -v tar >/dev/null || die "tar is required"
command -v sha256sum >/dev/null || die "sha256sum is required"
[[ "$FLYBOARD_PORT" =~ ^[0-9]+$ ]] && (( FLYBOARD_PORT >= 1 && FLYBOARD_PORT <= 65535 )) || die "invalid port: $FLYBOARD_PORT"

if [[ -n "${FLYBOARD_PUBLIC_HOST:-}" ]]; then
  public_host="$FLYBOARD_PUBLIC_HOST"
elif [[ -n "${PUBLIC_URL:-}" ]]; then
  public_host="${PUBLIC_URL#*://}"
  public_host="${public_host%%/*}"
  public_host="${public_host%%:*}"
else
  public_host=""
fi
[[ -z "${PUBLIC_URL:-}" || "$PUBLIC_URL" =~ ^https?:// ]] || die "PUBLIC_URL must start with http:// or https://"

if [[ -e "$INSTALL_DIR" ]] && find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
  die "install directory is not empty: $INSTALL_DIR"
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
base="https://github.com/$REPO/releases/download/$RELEASE_TAG"
curl -fL --retry 3 --retry-all-errors "$base/$PACKAGE_NAME" -o "$work/$PACKAGE_NAME"
curl -fL --retry 3 --retry-all-errors "$base/$PACKAGE_NAME.sha256" -o "$work/$PACKAGE_NAME.sha256"
(cd "$work" && sha256sum -c "$PACKAGE_NAME.sha256")

mkdir -p "$INSTALL_DIR"
tar -xzf "$work/$PACKAGE_NAME" -C "$INSTALL_DIR" --strip-components=1
[[ -f "$INSTALL_DIR/install.sh" ]] || die "release package is missing install.sh"
chmod 0755 "$INSTALL_DIR/install.sh"

if [[ -n "${PROJECT_NAME:-}" ]]; then
  [[ "$PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "invalid PROJECT_NAME"
  mkdir -p "$INSTALL_DIR/secrets"
  printf '%s\n' "$PROJECT_NAME" >"$INSTALL_DIR/secrets/project_name"
  chmod 0600 "$INSTALL_DIR/secrets/project_name"
fi

cd "$INSTALL_DIR"
export FLYBOARD_PORT FLYBOARD_BIND FLYBOARD_ADMIN_EMAIL
if [[ -n "$public_host" ]]; then export FLYBOARD_PUBLIC_HOST="$public_host"; fi
exec ./install.sh
