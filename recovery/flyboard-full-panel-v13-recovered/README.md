# FlyBoard A 基线完整新装包 v1

这是 A 当前实际运行 FlyBoard/XBoard 面板功能的**干净新服务器安装包**：固定基础镜像 digest，包含 26 个实际生效覆盖文件、v13 全局设备限制、严格 Subscription Owner Lock、FlyBoard 后台/上游/客户端/白名单/日志、Horizon、WebSocket、Laravel Scheduler 和所有 FlyBoard schema。

不包含 A 的 `.env`、APP_KEY、管理员密码、SQLite、Redis、用户、节点、订阅 URL、白名单 IP、日志或节点凭据。新服务器会生成自己的 APP_KEY、管理员随机密码、SQLite 与 Redis 状态。

## 前提

- 64 位 Linux（建议 Ubuntu/Debian）
- Docker Engine
- Docker Compose v2
- `curl`
- 面板端口未占用（默认 7001）
- 安装目标目录必须不存在（默认 `/opt/flyboard`），避免覆盖已有实例

## 一键安装

```bash
sudo ADMIN_EMAIL='admin@example.com' \
  PUBLIC_URL='https://panel.example.com' \
  bash install.sh
```

可选参数：

```bash
sudo INSTALL_DIR=/opt/flyboard PANEL_PORT=7001 PROJECT_NAME=flyboard \
  ADMIN_EMAIL='admin@example.com' PUBLIC_URL='http://SERVER_IP:7001' \
  PANEL_WHITELIST='203.0.113.10,2001:db8::/64' \
  bash install.sh
```

未设置 `PANEL_WHITELIST` 时，白名单功能完整保留但默认关闭，避免在新 IP 上把管理员锁在面板外；填写新服务器允许的 IP/CIDR 后会启用。不会复制 A 的生产白名单。

安装成功后：
- 摘要：`/opt/flyboard/INSTALLATION-SUMMARY.txt`
- 管理员随机密码：`/opt/flyboard/install-result.txt`
- 运行目录：`/opt/flyboard`

## 运维

```bash
cd /opt/flyboard
docker compose ps
docker compose logs -f --tail=200
docker compose restart
```

## 当前功能基线

- `flyboard_antishare_enable=1`
- `flyboard_antishare_device_limit=1`
- `flyboard_antishare_window=120`
- v13 核心来自 A 基线；`DeviceStateService.php` 与 `ServerService.php` 已合入后续 IP-window hotfix，`NodeWorker.php` 与 `NodeEventHandlers.php` 保持 A/v13 共同哈希
- 严格 Owner Lock `FlyboardService.php` 与 A 哈希一致
- 镜像固定：`ghcr.io/cedar2025/xboard@sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676`

外部 `xboard-node` 是独立组件，不在此面板包中；节点配置、ID、密钥必须在新节点上独立配置。

## 恢复审计合成说明（2026-10-27）

本隔离产物以通过归档、payload 哈希双重校验且两个 archive 副本逐字节一致的 `flyboard-full-panel-a-baseline-v1` 为完整基线，仅合入 v13 owner-lock IP-window hotfix 中两个后续修复：

- `DeviceStateService.php`：同节点变更 IP 不再在限制检查前删除旧槽位。
- `ServerService.php`：连接数遥测不再续期设备 claim。

刻意保留基线中更新、更严格的 `FlyboardService.php`（IP+UA owner fingerprint），未用 hotfix 包中的较早 V20 UA-first 文件覆盖；真实 `/s/{token}` 两个入口控制器本来就与 hotfix 字节一致。

这只是离线恢复候选，未部署、未启动容器、未操作数据库。详见同级 `RECOVERY-AUDIT.md`。
