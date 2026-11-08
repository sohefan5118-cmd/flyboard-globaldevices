# Flyboard v13 自有仓库安装教程

本教程只使用以下自有资源：

- GitHub Release：`sohefan5118-cmd/flyboard-globaldevices`
- GHCR 镜像：`ghcr.io/sohefan5118-cmd/xboard:896e4926`

安装过程不访问原版 XBoard GitHub 仓库或原版 XBoard GHCR。

## 一键安装入口

原来的分步安装方法仍然保留，适合需要逐项检查的场景。新服务器也可以使用下面的一键入口；它会自动串联环境检查、Docker 安装、固定 Release 下载、外层 SHA-256 校验、安装包内部校验、原版引用审计，并最终调用同一个正式 `install.sh`。

一键脚本只支持 Ubuntu/Debian 的 `apt-get` 和 `x86_64/amd64`，并且不会覆盖已存在的 `/opt/flyboard` 或占用中的 TCP `7001`。

先只读检查服务器架构和目标目录：

```bash
uname -m
test ! -e /opt/flyboard && echo "通过：安装目录不存在" || echo "停止：/opt/flyboard 已存在"
```

确认无误后，下面命令会真正修改服务器并开始安装。执行过程中需要输入真实管理员邮箱和公网 URL：

```bash
curl -fsSL \
  'https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/flyboard-recovery-v13-offline-20261104/recovery/install-one-click.sh' \
  | bash
```

脚本默认使用 `/opt/flyboard`、TCP `7001` 和 Compose 项目名 `flyboard`。如需自定义，可在命令前设置变量：

```bash
curl -fsSL \
  'https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/flyboard-recovery-v13-offline-20261104/recovery/install-one-click.sh' \
  -o /tmp/flyboard-install-one-click.sh
env \
  INSTALL_DIR='/opt/flyboard-2' \
  PANEL_PORT='7002' \
  PROJECT_NAME='flyboard_2' \
  bash /tmp/flyboard-install-one-click.sh
rm -f /tmp/flyboard-install-one-click.sh
```

一键脚本下载的仍是本 Release 的固定安装包，并最终调用其中经过校验的正式安装器；离线导入镜像、旧的手动分步安装和安装后验证方法见下文，均未删除。

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
  -o flyboard-full-panel-v13-node-install-fixed.tar.gz \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/flyboard-full-panel-v13-node-install-fixed.tar.gz'

curl -fL \
  -o flyboard-full-panel-v13-node-install-fixed.tar.gz.sha256 \
  'https://github.com/sohefan5118-cmd/flyboard-globaldevices/releases/download/flyboard-recovery-v13-offline-20261104/flyboard-full-panel-v13-node-install-fixed.tar.gz.sha256'

sha256sum -c flyboard-full-panel-v13-node-install-fixed.tar.gz.sha256
tar -xzf flyboard-full-panel-v13-node-install-fixed.tar.gz
cd flyboard-full-panel-v13-recovered
```

预期校验结果：

```text
flyboard-full-panel-v13-node-install-fixed.tar.gz: OK
```

安装包 SHA-256：

```text
c3536d63032e2bee5560e9fcfcf3833fb992ea7c3be4c22dfef280e29fcbe4bc
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

并强制校验已登记的镜像身份。不同 Docker 镜像存储可能显示以下任一值：

```text
sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676
sha256:3a432c8e3fd243f70afd885e3d4898343d0371971007def6c5f8e7b46991c0cf
sha256:8cc12dc32065e251987786b9b1abffa1014783e82a62d83548413f78395169e1
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
- 两个容器使用同一个已登记镜像身份；Docker 29/containerd 通常显示 `sha256:3a432c8e3fd243f70afd885e3d4898343d0371971007def6c5f8e7b46991c0cf`，传统 Docker 镜像存储通常显示 `sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676`。
- HTTP 状态为 `200`、`301`、`302`、`401` 或 `403`。
- Redis 返回 `PONG`。
- Horizon 显示 `Horizon is running`。
- Scheduler 列表包含 `cleanup:online-status`。

## 六、安装节点

面板安装和节点安装是两个独立步骤。先在后台创建机器并重置机器 Token，再复制后台生成的命令到节点服务器执行。后台命令固定引用：

```text
https://raw.githubusercontent.com/sohefan5118-cmd/xboard-node/09cf31b62d667bbfc55124f5d691a571c090f8c7/install.sh
```

该提交已验证支持 `curl | bash`，不会再触发 `BASH_SOURCE[0]: unbound variable`。

节点安装后的只读验证：

```bash
sudo systemctl is-active xboard-node.service
sudo /usr/local/bin/xboard-node -v
sudo test -f /etc/xboard-node/config.yml && echo "配置文件存在"
```

如果需要本仓库的全局设备限制，再执行第二步补丁安装：

```bash
curl -fsSL \
  'https://raw.githubusercontent.com/sohefan5118-cmd/flyboard-globaldevices/main/scripts/install-xboard-node-globaldevices2.sh' \
  | sudo bash
```

机器 Token 属于凭据，不要发到聊天或提交到仓库。Token 暴露后必须先在后台重置，再使用新命令。

管理员地址和初始随机密码位置：

```bash
sudo cat /opt/flyboard/INSTALLATION-SUMMARY.txt
sudo cat /opt/flyboard/install-result.txt
```

首次登录后立即修改管理员密码。

## 七、完全离线安装

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

## 八、已完成的实装测试

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
