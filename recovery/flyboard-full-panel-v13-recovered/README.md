# FlyBoard A 基线完整新装包 v1

这是 A 当前实际运行 FlyBoard/XBoard 面板功能的**干净新服务器安装包**：固定基础镜像 digest，包含完整面板覆盖文件、v13 全局设备限制、严格 Subscription Owner Lock、FlyBoard 后台/上游/客户端/白名单/日志、Horizon、WebSocket、Laravel Scheduler 和所有 FlyBoard schema。

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
- 自有公开镜像：`ghcr.io/sohefan5118-cmd/xboard:896e4926`
- 安装器强制校验已登记的镜像内容 ID 或自有 GHCR manifest digest；兼容传统 Docker 镜像存储和 Docker 29/containerd 镜像存储。

外部 `xboard-node` 是独立组件，不在此面板包中。后台机器安装命令固定引用自有仓库提交 `09cf31b62d667bbfc55124f5d691a571c090f8c7`；该提交已验证支持 `curl | bash`。节点安装后，如需全局设备限制，再运行 `flyboard-globaldevices` 的节点补丁脚本。机器 Token 暴露后必须先在后台重置。

## 恢复审计合成说明（2026-10-27）

本隔离产物以通过归档、payload 哈希双重校验且两个 archive 副本逐字节一致的 `flyboard-full-panel-a-baseline-v1` 为完整基线，仅合入 v13 owner-lock IP-window hotfix 中两个后续修复：

- `DeviceStateService.php`：同节点变更 IP 不再在限制检查前删除旧槽位。
- `ServerService.php`：连接数遥测不再续期设备 claim。

刻意保留基线中更新、更严格的 `FlyboardService.php`（IP+UA owner fingerprint），未用 hotfix 包中的较早 V20 UA-first 文件覆盖；真实 `/s/{token}` 两个入口控制器本来就与 hotfix 字节一致。

这只是离线恢复候选，未部署、未启动容器、未操作数据库。详见同级 `RECOVERY-AUDIT.md`。


## 官方仓库或镜像被删除后的离线恢复

本安装包的 GitHub Release 同时保存固定 XBoard 镜像：

- `xboard-896e4926-linux-amd64.tar.gz`
- `xboard-896e4926-linux-amd64.tar.gz.sha256`

先验证并载入镜像（不会启动服务）：

```bash
sha256sum -c xboard-896e4926-linux-amd64.tar.gz.sha256
gzip -dc xboard-896e4926-linux-amd64.tar.gz | sudo docker load
sudo docker image inspect 'ghcr.io/sohefan5118-cmd/xboard:896e4926' --format '{{.Id}}'
```

不同 Docker 镜像存储可能显示以下任一已登记身份：

```text
sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676
sha256:3a432c8e3fd243f70afd885e3d4898343d0371971007def6c5f8e7b46991c0cf
sha256:8cc12dc32065e251987786b9b1abffa1014783e82a62d83548413f78395169e1
```

之后正常运行 `install.sh`。安装器在线时从自有公开 GHCR 拉取；检测到本机固定镜像后会跳过网络拉取，因此不再依赖官方 GitHub 仓库或官方 GHCR 镜像仍然存在。
