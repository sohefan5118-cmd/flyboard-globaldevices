#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO="${REPO:-sohefan5118-cmd/flyboard-globaldevices}"
RELEASE_TAG="${RELEASE_TAG:-flyboard-recovery-v13-offline-20261104}"
PACKAGE_NAME="flyboard-full-panel-v13-node-install-fixed.tar.gz"
PACKAGE_SHA256="cdc96cbbfd4ec437df3f22555849b37c3796550122ed4741ac5c80da554cc3aa"
PACKAGE_ASSET_ID="724191563"
IMAGE="ghcr.io/sohefan5118-cmd/xboard:896e4926"
INSTALL_DIR="${INSTALL_DIR:-/opt/flyboard}"
PANEL_PORT="${PANEL_PORT:-7001}"
PROJECT_NAME="${PROJECT_NAME:-flyboard}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
PUBLIC_URL="${PUBLIC_URL:-}"
DOWNLOAD_ROOT="${DOWNLOAD_ROOT:-$HOME/flyboard-install}"
AUTO_INSTALL_DOCKER="${AUTO_INSTALL_DOCKER:-1}"

say() { printf '\n[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
cleanup() {
  local rc=$?
  if [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR:-}" ]]; then
    rm -rf -- "$WORK_DIR"
  fi
  exit "$rc"
}
trap cleanup EXIT

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "请使用 sudo bash install-one-click.sh，或先切换到 root。"
command -v apt-get >/dev/null 2>&1 || die "当前系统没有 apt-get；一键脚本仅支持 Ubuntu/Debian。"
command -v curl >/dev/null 2>&1 || die "未找到 curl。"
command -v sha256sum >/dev/null 2>&1 || die "未找到 sha256sum。"
command -v tar >/dev/null 2>&1 || die "未找到 tar。"

arch="$(uname -m)"
[[ "$arch" == "x86_64" || "$arch" == "amd64" ]] || die "当前架构为 $arch；此 Release 仅支持 x86_64/amd64。"
[[ ! -e "$INSTALL_DIR" ]] || die "目标目录已存在：$INSTALL_DIR。为避免覆盖现有实例，脚本停止。"
[[ "$PANEL_PORT" =~ ^[0-9]+$ ]] && (( PANEL_PORT >= 1 && PANEL_PORT <= 65535 )) || die "PANEL_PORT 无效：$PANEL_PORT"
[[ "$PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "PROJECT_NAME 只能包含小写字母、数字、_、-。"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  [[ "$AUTO_INSTALL_DOCKER" == "1" ]] || die "未找到 Docker Compose v2；请安装 Docker 后重试，或设置 AUTO_INSTALL_DOCKER=1。"
  say "未检测到完整 Docker 环境，安装 Ubuntu/Debian Docker 软件包"
  apt-get update
  apt-get install -y ca-certificates curl docker.io
  if ! docker compose version >/dev/null 2>&1; then
    apt-get install -y docker-compose-v2 || apt-get install -y docker-compose-plugin
  fi
  systemctl enable --now docker
fi
command -v docker >/dev/null 2>&1 || die "Docker 安装后仍不可用。"
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 不可用。"

if command -v ss >/dev/null 2>&1 && ss -Hlnt "sport = :$PANEL_PORT" 2>/dev/null | grep -q .; then
  die "TCP 端口已被占用：$PANEL_PORT"
fi

if [[ -z "$ADMIN_EMAIL" ]]; then
  read -r -p '请输入管理员邮箱: ' ADMIN_EMAIL
fi
[[ "$ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || die "管理员邮箱格式无效。"

if [[ -z "$PUBLIC_URL" ]]; then
  read -r -p "请输入面板公网 URL（例如 http://203.0.113.10:7001）: " PUBLIC_URL
fi
[[ "$PUBLIC_URL" =~ ^https?:// ]] || die "PUBLIC_URL 必须以 http:// 或 https:// 开头。"

mkdir -p -- "$DOWNLOAD_ROOT"
WORK_DIR="$(mktemp -d "$DOWNLOAD_ROOT/.flyboard-one-click.XXXXXX")"
ARCHIVE_URL="https://github.com/$REPO/releases/download/$RELEASE_TAG/$PACKAGE_NAME"
ARCHIVE_API_URL="https://api.github.com/repos/$REPO/releases/assets/$PACKAGE_ASSET_ID"
ARCHIVE="$WORK_DIR/$PACKAGE_NAME"

say "从自有 GitHub Release 下载固定安装包"
if ! curl -fL --retry 3 --retry-all-errors -o "$ARCHIVE" "$ARCHIVE_URL"; then
  say "Release 下载链路不可用，切换到 GitHub Release Asset API"
  curl -fL --retry 3 --retry-all-errors \
    -H 'Accept: application/octet-stream' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    -o "$ARCHIVE" "$ARCHIVE_API_URL"
fi
printf '%s  %s\n' "$PACKAGE_SHA256" "$ARCHIVE" | sha256sum -c -

say "解压并验证安装包内部清单"
tar -xzf "$ARCHIVE" -C "$WORK_DIR"
PACKAGE_DIR="$WORK_DIR/flyboard-full-panel-v13-recovered"
[[ -x "$PACKAGE_DIR/install.sh" || -f "$PACKAGE_DIR/install.sh" ]] || die "安装包缺少 install.sh。"
[[ -f "$PACKAGE_DIR/payload/compose.yaml" ]] || die "安装包缺少 payload/compose.yaml。"
[[ -f "$PACKAGE_DIR/payload/compose.bootstrap.yaml" ]] || die "安装包缺少 payload/compose.bootstrap.yaml。"
[[ -f "$PACKAGE_DIR/payload/overrides/flyboard-restored/MachineController.php" ]] || die "安装包缺少 MachineController.php。"
(cd "$PACKAGE_DIR" && bash -n install.sh && sha256sum -c CANDIDATE-SHA256SUMS >/dev/null)
(cd "$PACKAGE_DIR/payload" && sha256sum -c ../PAYLOAD-SHA256SUMS >/dev/null)
grep -Fq './overrides/flyboard-restored/MachineController.php:/www/app/Http/Controllers/V2/Admin/Server/MachineController.php:ro' \
  "$PACKAGE_DIR/payload/compose.yaml" || die "compose.yaml 未挂载 MachineController.php。"
if grep -Fq '.env.install' "$PACKAGE_DIR/install.sh"; then
  die "安装器仍依赖可能未打包的 .env.install。"
fi

if grep -RInE 'cedar2025|ghcr\.io/cedar2025|github\.com/cedar2025/[Xx]board' \
  "$PACKAGE_DIR" --exclude='CANDIDATE-SHA256SUMS'; then
  die "安装包包含原版 XBoard 仓库或镜像引用。"
fi
grep -Fq "$IMAGE" "$PACKAGE_DIR/install.sh" || die "安装器没有使用预期的自有 GHCR 镜像。"

say "校验通过，调用原有正式安装器"
INSTALL_DIR="$INSTALL_DIR" \
PANEL_PORT="$PANEL_PORT" \
PROJECT_NAME="$PROJECT_NAME" \
ADMIN_EMAIL="$ADMIN_EMAIL" \
PUBLIC_URL="$PUBLIC_URL" \
bash "$PACKAGE_DIR/install.sh"

say "一键安装完成；请查看 $INSTALL_DIR/INSTALLATION-SUMMARY.txt 和 $INSTALL_DIR/install-result.txt"
