#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo '请使用 sudo'; exit 1; }
INSTALL_DIR="${INSTALL_DIR:-/opt/flyboard}"
[[ -f "$INSTALL_DIR/INSTALLATION-SUMMARY.txt" && -f "$INSTALL_DIR/compose.yaml" ]] || { echo '未找到本安装包生成的实例，拒绝删除。'; exit 1; }
PROJECT_NAME="$(sed -n 's/^Project: //p' "$INSTALL_DIR/INSTALLATION-SUMMARY.txt")"
read -r -p "将删除项目 $PROJECT_NAME 及其全新数据库/Redis。输入 DELETE 确认: " answer
[[ "$answer" == DELETE ]] || exit 1
(cd "$INSTALL_DIR" && docker compose --project-name "$PROJECT_NAME" down -v --remove-orphans)
rm -rf -- "$INSTALL_DIR"
echo '已卸载。'
