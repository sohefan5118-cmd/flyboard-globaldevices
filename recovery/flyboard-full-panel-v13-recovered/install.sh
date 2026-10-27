#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PACKAGE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${INSTALL_DIR:-/opt/flyboard}"
PANEL_PORT="${PANEL_PORT:-7001}"
PROJECT_NAME="${PROJECT_NAME:-flyboard}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
PUBLIC_URL="${PUBLIC_URL:-}"
PANEL_WHITELIST="${PANEL_WHITELIST:-}"
IMAGE='ghcr.io/cedar2025/xboard@sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676'
CREATED_DIR=0
INSTALLED_OK=0

say() { printf '\n[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
rollback() {
  local rc=$?
  if (( rc != 0 )) && (( CREATED_DIR == 1 )) && (( INSTALLED_OK == 0 )); then
    say "安装失败，清理本次新建的隔离容器/卷（不会触碰其他 Compose 项目）"
    if [[ -f "$INSTALL_DIR/compose.yaml" ]] && command -v docker >/dev/null 2>&1; then
      (cd "$INSTALL_DIR" && docker compose -f compose.yaml --project-name "$PROJECT_NAME" down -v --remove-orphans) >/dev/null 2>&1 || true
    fi
    rm -rf -- "$INSTALL_DIR"
  fi
  exit "$rc"
}
trap rollback EXIT

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "请使用 sudo bash install.sh"
command -v docker >/dev/null 2>&1 || die "未找到 Docker Engine。请先安装 Docker 与 Compose v2。"
docker compose version >/dev/null 2>&1 || die "未找到 docker compose v2。"
command -v curl >/dev/null 2>&1 || die "未找到 curl。"
command -v sha256sum >/dev/null 2>&1 || die "未找到 sha256sum。"
[[ "$PANEL_PORT" =~ ^[0-9]+$ ]] && (( PANEL_PORT >= 1 && PANEL_PORT <= 65535 )) || die "PANEL_PORT 无效"
[[ "$PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "PROJECT_NAME 只能包含小写字母、数字、_、-"
if [[ -z "$ADMIN_EMAIL" ]]; then
  if [[ -t 0 ]]; then read -r -p '管理员邮箱: ' ADMIN_EMAIL; else die "非交互安装必须设置 ADMIN_EMAIL"; fi
fi
[[ "$ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || die "管理员邮箱格式无效"
if [[ -z "$PUBLIC_URL" ]]; then PUBLIC_URL="http://127.0.0.1:${PANEL_PORT}"; fi
[[ "$PUBLIC_URL" =~ ^https?:// ]] || die "PUBLIC_URL 必须以 http:// 或 https:// 开头"

[[ -d "$PACKAGE_DIR/payload" ]] || die "安装包缺少 payload"
(cd "$PACKAGE_DIR/payload" && sha256sum -c "$PACKAGE_DIR/PAYLOAD-SHA256SUMS") >/dev/null || die "安装包 SHA256 校验失败"
[[ ! -e "$INSTALL_DIR" ]] || die "目标目录已存在：$INSTALL_DIR"
mkdir -p "$INSTALL_DIR"; CREATED_DIR=1
if docker ps -a --format '{{.Label "com.docker.compose.project"}}' | grep -Fxq "$PROJECT_NAME"; then
  die "Compose 项目名已存在：$PROJECT_NAME"
fi
if command -v ss >/dev/null 2>&1 && ss -Hln "sport = :$PANEL_PORT" 2>/dev/null | grep -q .; then
  die "端口已被占用：$PANEL_PORT"
fi

say "复制经校验的 A 基线代码（不含 A 的数据库、Redis、密钥和业务数据）"
cp -a "$PACKAGE_DIR/payload/." "$INSTALL_DIR/"
mv "$INSTALL_DIR/.env.install" "$INSTALL_DIR/.env"
mkdir -p "$INSTALL_DIR/.docker/.data" "$INSTALL_DIR/storage/logs" "$INSTALL_DIR/storage/theme" "$INSTALL_DIR/plugins"
touch "$INSTALL_DIR/.docker/.data/database.sqlite"
chmod 600 "$INSTALL_DIR/.env" "$INSTALL_DIR/.docker/.data/database.sqlite"

cd "$INSTALL_DIR"
say "拉取固定镜像 digest"
docker pull "$IMAGE"
actual_id="$(docker image inspect "$IMAGE" --format '{{.Id}}')"
[[ "$actual_id" == 'sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676' ]] || die "镜像 ID 不匹配：$actual_id"

say "初始化全新 SQLite、APP_KEY、Redis 配置和管理员"
install_log="$INSTALL_DIR/install-result.txt"
PANEL_PORT="$PANEL_PORT" docker compose -f compose.bootstrap.yaml --project-name "$PROJECT_NAME" run --rm \
  -e ENABLE_SQLITE=true -e ENABLE_REDIS=true -e ADMIN_ACCOUNT="$ADMIN_EMAIL" \
  xboard php /www/artisan xboard:install --no-interaction | tee "$install_log"
grep -q '^INSTALLED=1\|^INSTALLED=true' .env || die "xboard:install 未完成"
chmod 600 .env "$install_log"
cat >> .env <<EOF

# Compose/install metadata (non-secret)
COMPOSE_PROJECT_NAME=${PROJECT_NAME}
PANEL_PORT=${PANEL_PORT}
PUBLIC_URL=${PUBLIC_URL}
EOF

say "写入 A 的功能基线设置：反共享开启、限制 1、窗口 120 秒"
# Boot Laravel against the new database and insert only non-secret feature settings.
PANEL_PORT="$PANEL_PORT" docker compose -f compose.yaml --project-name "$PROJECT_NAME" run --rm \
  -e CACHE_DRIVER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e FB_WL_ENABLED="$([[ -n "$PANEL_WHITELIST" ]] && echo 1 || echo 0)" \
  -e FB_WL="$PANEL_WHITELIST" -e FB_PUBLIC_URL="$PUBLIC_URL" \
  xboard php -r '
require "/www/vendor/autoload.php";
$app=require "/www/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=[
 "flyboard_antishare_enable"=>"1",
 "flyboard_antishare_device_limit"=>"1",
 "flyboard_antishare_window"=>"120",
 "xboard_panel_whitelist_enabled"=>getenv("FB_WL_ENABLED") ?: "0",
 "xboard_panel_whitelist"=>getenv("FB_WL") ?: "",
 "xboard_panel_block_policy"=>"XBoard main panel whitelist all paths verified",
 "app_url"=>getenv("FB_PUBLIC_URL") ?: "",
];
foreach($settings as $name=>$value){Illuminate\Support\Facades\DB::table("v2_settings")->updateOrInsert(["name"=>$name],["value"=>$value,"updated_at"=>now(),"created_at"=>now()]);}
'

say "启动完整面板"
PANEL_PORT="$PANEL_PORT" docker compose --project-name "$PROJECT_NAME" up -d

say "等待 HTTP 就绪"
ready=0
for _ in $(seq 1 90); do
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:${PANEL_PORT}/" 2>/dev/null || true)"
  if [[ "$code" =~ ^(200|301|302|401|403)$ ]]; then ready=1; break; fi
  sleep 2
done
(( ready == 1 )) || { docker compose --project-name "$PROJECT_NAME" logs --tail=200 >&2; die "面板 HTTP 未就绪"; }

say "执行新装完整性检查"
docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan migrate --force >/dev/null
docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan config:clear >/dev/null
docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan cache:clear >/dev/null
verify="$(docker compose --project-name "$PROJECT_NAME" exec -T xboard php -r '
require "/www/vendor/autoload.php";$app=require "/www/bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$need=["flyboard_upstream_users","flyboard_upstream_nodes","flyboard_access_logs","flyboard_clients","flyboard_main_user_whitelist","flyboard_main_user_whitelist_logs"];
foreach($need as $t){if(!Illuminate\Support\Facades\Schema::hasTable($t)){fwrite(STDERR,"missing:$t\n");exit(2);}}
$s=Illuminate\Support\Facades\DB::table("v2_settings")->whereIn("name",["flyboard_antishare_enable","flyboard_antishare_device_limit","flyboard_antishare_window"])->pluck("value","name")->all();
if(($s["flyboard_antishare_enable"]??null)!="1"||($s["flyboard_antishare_device_limit"]??null)!="1"||($s["flyboard_antishare_window"]??null)!="120"){fwrite(STDERR,"settings mismatch\n");exit(3);} echo "SCHEMA_SETTINGS_OK";
')"
[[ "$verify" == *SCHEMA_SETTINGS_OK* ]] || die "数据库结构/功能设置检查失败"
docker compose --project-name "$PROJECT_NAME" exec -T xboard redis-cli -s /data/redis.sock ping | grep -q PONG || die "Redis 检查失败"
artisan_commands="$(docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan list --raw)"
[[ "$artisan_commands" == *cleanup:online-status* ]] || die "清理命令未注册"
horizon_status="$(docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan horizon:status)"
[[ "$horizon_status" == *'Horizon is running'* ]] || die "Horizon 未运行"
schedule_list="$(docker compose --project-name "$PROJECT_NAME" exec -T xboard php /www/artisan schedule:list)"
[[ "$schedule_list" == *cleanup:online-status* ]] || die "Scheduler 任务未注册"
running_services="$(docker compose --project-name "$PROJECT_NAME" ps --status running --services)"
[[ "$running_services" == *scheduler* ]] || die "Scheduler 服务未运行"

# Verify protected A baseline and all active package files inside the live container.
while read -r sum rel; do
  case "$rel" in
    compose.yaml|compose.bootstrap.yaml|.env.install|database/migrations/*) continue ;;
  esac
  target=''
  case "$rel" in
    overrides-UniProxyController.php) target=/www/app/Http/Controllers/V1/Server/UniProxyController.php ;;
    overrides-ServerRoute.php) target=/www/app/Http/Routes/V2/ServerRoute.php ;;
    overrides/DeviceStateService.php) target=/www/app/Services/DeviceStateService.php ;;
    overrides/ServerService.php) target=/www/app/Services/ServerService.php ;;
    overrides/NodeWorker.php) target=/www/app/WebSocket/NodeWorker.php ;;
    overrides/console-commands/CleanupOnlineStatus.php) target=/www/app/Console/Commands/CleanupOnlineStatus.php ;;
    overrides/flyboard-restored/admin.blade.php) target=/www/resources/views/admin.blade.php ;;
    overrides/flyboard-restored/flyboard.blade.php) target=/www/resources/views/flyboard.blade.php ;;
    overrides/flyboard-restored/web.php) target=/www/routes/web.php ;;
    overrides/flyboard-restored/AdminRoute.php) target=/www/app/Http/Routes/V2/AdminRoute.php ;;
    overrides/flyboard-restored/Kernel.php) target=/www/app/Http/Kernel.php ;;
    overrides/flyboard-restored/Middleware/XBoardPanelWhitelist.php) target=/www/app/Http/Middleware/XBoardPanelWhitelist.php ;;
    overrides/flyboard-restored/FlyboardController.php) target=/www/app/Http/Controllers/V2/Admin/FlyboardController.php ;;
    overrides/flyboard-restored/UserController.php) target=/www/app/Http/Controllers/V2/Admin/UserController.php ;;
    overrides/flyboard-restored/NodeEventHandlers.php) target=/www/app/WebSocket/NodeEventHandlers.php ;;
    overrides/flyboard-restored/FlyboardService.php) target=/www/app/Services/FlyboardService.php ;;
    overrides/flyboard-restored/FlyboardClientService.php) target=/www/app/Services/FlyboardClientService.php ;;
    overrides/flyboard-restored/FlyboardUpstreamFetcher.php) target=/www/app/Services/FlyboardUpstreamFetcher.php ;;
    overrides/flyboard-restored/FlyboardPullJob.php) target=/www/app/Services/FlyboardPullJob.php ;;
    overrides/flyboard-restored/FlyboardTransport.php) target=/www/app/Services/FlyboardTransport.php ;;
    overrides/flyboard-restored/FlyboardClientController.php) target=/www/app/Http/Controllers/V1/Client/FlyboardClientController.php ;;
    overrides/flyboard-restored/ClientController.php) target=/www/app/Http/Controllers/V1/Client/ClientController.php ;;
    overrides/flyboard-restored/User/ServerController.php) target=/www/app/Http/Controllers/V1/User/ServerController.php ;;
    overrides/flyboard-restored/Client/AppController.php) target=/www/app/Http/Controllers/V1/Client/AppController.php ;;
    overrides/flyboard-restored/horizon.php) target=/www/config/horizon.php ;;
    overrides/flyboard-restored/flyboard.php) target=/www/config/flyboard.php ;;
  esac
  [[ -z "$target" ]] || [[ "$(docker compose --project-name "$PROJECT_NAME" exec -T xboard sha256sum "$target" | awk '{print $1}')" == "$sum" ]] || die "运行文件哈希不匹配：$rel"
done < "$PACKAGE_DIR/PAYLOAD-SHA256SUMS"

admin_path="$(docker compose --project-name "$PROJECT_NAME" exec -T xboard php -r 'require "/www/vendor/autoload.php";$app=require "/www/bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo admin_setting("secure_path",admin_setting("frontend_admin_path",hash("crc32b",config("app.key"))));')"
cat > "$INSTALL_DIR/INSTALLATION-SUMMARY.txt" <<EOF
Installed UTC: $(date -u +%FT%TZ)
Image: $IMAGE
Project: $PROJECT_NAME
Panel local check URL: http://127.0.0.1:$PANEL_PORT/
Public URL: $PUBLIC_URL
Admin URL: ${PUBLIC_URL%/}/$admin_path
Anti-share: enabled
Device/owner limit: 1
Window: 120
Panel whitelist: $([[ -n "$PANEL_WHITELIST" ]] && echo enabled || echo disabled)
EOF
chmod 600 "$INSTALL_DIR/INSTALLATION-SUMMARY.txt"
INSTALLED_OK=1
trap - EXIT
say "安装成功"
cat "$INSTALL_DIR/INSTALLATION-SUMMARY.txt"
printf '\n管理员随机密码保存在：%s\n' "$install_log"
printf '请立即登录并修改管理员密码。\n'
