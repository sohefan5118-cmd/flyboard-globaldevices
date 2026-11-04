# Flyboard v13 自有仓库安装教程

本教程只使用以下自有资源：

- GitHub Release：`sohefan5118-cmd/flyboard-globaldevices`
- GHCR 镜像：`ghcr.io/sohefan5118-cmd/xboard:896e4926`

安装过程不访问原版 XBoard GitHub 仓库或原版 XBoard GHCR。

## 一、只读检查

以下命令不会修改服务器：

```bash
uname -m
cat /etc/os-release
docker --version 2>/dev/null || true
docker compose version 2>/dev/null || true
sudo test ! -e /opt/flyboard && echo "安装目录可用" || echo "停止：/opt/flyboard 已存在"
sudo ss -Hlnt 'sport = :7001'
df -h /
free -h
```

正确情况：

- CPU 架构为 `x86_64` 或 `amd64`。
- Docker Engine 和 Docker Compose v2 能显示版本。
- `/opt/flyboard` 不存在。
- `sudo ss -Hlnt 'sport = :7001'` 没有输出。

## 二、下载自有仓库安装包

这部分只会在当前用户目录下载和解压文件，不会启动服务：

```bash
mkdir -p ~/flyboard-install
cd ~/flyboard-install

curl -fL \
  -o flyboard-full-panel-v13-recovered.tar.gz \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/flyboard-full-panel-v13-recovered.tar.gz'

curl -fL \
  -o flyboard-full-panel-v13-recovered.tar.gz.sha256 \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/flyboard-full-panel-v13-recovered.tar.gz.sha256'

sha256sum -c flyboard-full-panel-v13-recovered.tar.gz.sha256
tar -xzf flyboard-full-panel-v13-recovered.tar.gz
cd flyboard-full-panel-v13-recovered
```

预期校验结果：

```text
flyboard-full-panel-v13-recovered.tar.gz: OK
```

安装包 SHA-256：

```text
c343960d617ac87200ad4202d65e7081e5acefdcaba955119a392ec0d0bed5aa
```

## 三、安装前验证

以下命令不会安装服务：

```bash
cd ~/flyboard-install/flyboard-full-panel-v13-recovered

bash -n install.sh
sha256sum -c CANDIDATE-SHA256SUMS
(cd payload && sha256sum -c ../PAYLOAD-SHA256SUMS)

grep -RInE 'cedar2025|ghcr\.io/cedar2025|github\.com/cedar2025/[Xx]board' . \
  --exclude='CANDIDATE-SHA256SUMS' \
  && echo "停止：发现原版 XBoard 引用" \
  || echo "通过：没有原版 XBoard 仓库或镜像引用"

grep -RIn 'ghcr.io/sohefan5118-cmd/xboard:896e4926' install.sh payload
```

最后一条命令应显示安装器和两个 Compose 文件都使用自有 GHCR 镜像。

## 四、真正安装

下面的命令会创建 `/opt/flyboard`、Docker 容器、网络和 Redis 数据卷。

把管理员邮箱和域名改为自己的实际值：

```bash
cd ~/flyboard-install/flyboard-full-panel-v13-recovered

sudo env \
  INSTALL_DIR='/opt/flyboard' \
  PANEL_PORT='7001' \
  PROJECT_NAME='flyboard' \
  ADMIN_EMAIL='admin@example.com' \
  PUBLIC_URL='https://panel.example.com' \
  bash install.sh
```

安装器会从以下自有公开镜像拉取：

```text
ghcr.io/sohefan5118-cmd/xboard:896e4926
```

并强制校验 Docker 镜像 ID：

```text
sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676
```

镜像内容不一致时，安装器会立即停止。

## 五、安装后验证

以下命令只读取运行状态：

```bash
sudo cat /opt/flyboard/INSTALLATION-SUMMARY.txt

cd /opt/flyboard
sudo docker compose ps

sudo docker compose ps -q | xargs -r sudo docker inspect \
  --format '{{.Name}} image={{.Config.Image}} image_id={{.Image}}'

curl -sS -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1:7001/

sudo docker compose exec -T xboard redis-cli -s /data/redis.sock ping
sudo docker compose exec -T xboard php /www/artisan horizon:status
sudo docker compose exec -T xboard php /www/artisan schedule:list | grep 'cleanup:online-status'
```

正确结果应包括：

- `xboard` 和 `scheduler` 均为 `Up`。
- 两个容器的镜像都是 `ghcr.io/sohefan5118-cmd/xboard:896e4926`。
- 两个容器的镜像 ID 都是 `sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676`。
- HTTP 状态为 `200`、`301`、`302`、`401` 或 `403`。
- Redis 返回 `PONG`。
- Horizon 显示 `Horizon is running`。
- Scheduler 列表包含 `cleanup:online-status`。

管理员地址和初始随机密码位置：

```bash
sudo cat /opt/flyboard/INSTALLATION-SUMMARY.txt
sudo cat /opt/flyboard/install-result.txt
```

首次登录后立即修改管理员密码。

## 六、完全离线安装

如果 GHCR 无法访问，可以从同一个自有 Release 下载离线镜像：

```bash
cd ~/flyboard-install

curl -fL \
  -o xboard-896e4926-linux-amd64.tar.gz \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/xboard-896e4926-linux-amd64.tar.gz'

curl -fL \
  -o xboard-896e4926-linux-amd64.tar.gz.sha256 \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/xboard-896e4926-linux-amd64.tar.gz.sha256'

sha256sum -c xboard-896e4926-linux-amd64.tar.gz.sha256
gzip -dc xboard-896e4926-linux-amd64.tar.gz | sudo docker load

sudo docker image inspect 'ghcr.io/sohefan5118-cmd/xboard:896e4926' \
  --format '{{.Id}}'
```

离线镜像归档 SHA-256：

```text
02102b037147497e626ba0eaa2442b100a9d198ff9793cddd2c281500c9ca1d4
```

载入镜像后，再按第四节运行安装器。安装器会检测本机已有固定镜像并跳过远程拉取。

## 七、已完成的实装测试

2026-11-04 使用 Release 中实际下载的安装包完成了隔离新装测试：

- 独立安装目录、独立 Compose 项目、测试端口 `17001`。
- 安装包和内部两层 SHA-256 清单全部通过。
- 安装器完整执行成功。
- HTTP 返回 `200`。
- Redis 返回 `PONG`。
- Horizon 正常运行。
- Scheduler 正常注册 `cleanup:online-status`。
- 两个容器均使用自有 GHCR 镜像及预期镜像 ID。
- 安装目录未发现原版 XBoard 仓库或镜像引用。
- 测试结束后容器、网络、数据卷、文件和端口均已清理。
