# Flyboard Global Device Limit

这是给 Flyboard/XBoard + xboard-node 的全局设备数限制补丁包。

目标：当用户 `device_limit=1` 时，不管用户连接哪个节点，全局只允许 1 个公网 IP/设备可用；后续新增节点只要安装同一版补丁节点程序，就会自动参与全局限制。

## 包含内容

- `dist/xboard-node-global-device-linux-amd64.gz`  
  补丁版 xboard-node，amd64/x86_64，带 Reality 需要的 `with_utls`。
- `scripts/install-xboard-node-globaldevices2.sh`  
  新节点一键替换脚本。
- `scripts/install-panel-machine-devices.sh`  
  面板端 `/api/v2/server/devices` 兼容补丁安装脚本。
- `panel/`  
  面板最小补丁文件。
- `node/patches/`  
  xboard-node 源码补丁记录。

## 当前发布版本

```text
xboard-node v1.13-openclaw-globaldevices2 (built 2026-08-13T10:13:08Z)
```

SHA256：

```text
b27b5de949fafcdcdc8e700dd404b3fcf26aa4a7bb5420dedce32aeb7d7a5905  xboard-node-global-device-linux-amd64
0f23c6a5db1382f92f8d50cfc9ab8b893231a9dda3f0b9713401ff2622d20b92  xboard-node-global-device-linux-amd64.gz
```

## 新节点安装方式

先按 XBoard/Flyboard 正常流程添加节点、安装并配置 xboard-node，确认有：

```text
/etc/xboard-node/config.yml
/usr/local/bin/xboard-node
xboard-node.service
```

然后在新节点服务器上执行：

```bash
curl -fsSL https://raw.githubusercontent.com/sohefan5118/flyboard-globaldevices/main/scripts/install-xboard-node-globaldevices2.sh | bash
```

如果你的仓库名不同：

```bash
curl -fsSL https://raw.githubusercontent.com/YOUR_NAME/YOUR_REPO/main/scripts/install-xboard-node-globaldevices2.sh | REPO="YOUR_NAME/YOUR_REPO" bash
```

成功后应看到：

```text
xboard-node v1.13-openclaw-globaldevices2
active
```

## 面板端安装方式

如果是新的 Flyboard/XBoard 面板，也要先安装面板补丁：

```bash
curl -fsSL https://raw.githubusercontent.com/sohefan5118/flyboard-globaldevices/main/scripts/install-panel-machine-devices.sh | bash
```

默认容器名：`flyboard-xboard-1`，默认应用目录：`/www`。

如果容器名不同：

```bash
curl -fsSL https://raw.githubusercontent.com/YOUR_NAME/YOUR_REPO/main/scripts/install-panel-machine-devices.sh | REPO="YOUR_NAME/YOUR_REPO" CONTAINER="你的容器名" APP_DIR="/www" bash
```

## 使用规则

- 同一个 Flyboard 面板只需要打一次面板补丁。
- 每台节点服务器都要使用本仓库的补丁版 `xboard-node`。
- 后续新增节点：后台添加节点 → 正常配置 xboard-node → 执行节点一键脚本。
- amd64/x86_64 服务器可直接使用当前二进制；arm64 需要重新构建对应架构。

## 重要说明

xboard-node v1.13 当前按来源公网 IP 识别设备，不是按手机硬件指纹识别。多个手机在同一个 NAT/运营商出口后面，可能会被算作同一台设备。

## 验证日志

节点上看到这些日志，说明限制正在工作：

```text
singbox: device limit gate-keep, rejecting connection
singbox: closed connections outside global device whitelist
```

面板日志中看到这些，说明后台裁剪/同步链路在工作：

```text
[DeviceLimit] trimmed reported device IPs
[DeviceLimit] forced node user reconnect after trimming devices
[DeviceLimit] broadcast reconnect to all online nodes
[WS] Pushed sync.user.delta
```

## 回滚

节点脚本会自动备份旧二进制：

```bash
ls -lh /usr/local/bin/xboard-node.bak-globaldevices-*
cp -a /usr/local/bin/xboard-node.bak-globaldevices-YYYYMMDDHHMMSS /usr/local/bin/xboard-node
chmod 0755 /usr/local/bin/xboard-node
systemctl restart xboard-node.service
```

面板脚本会给三个文件生成 `.bak-globaldevices-时间戳` 备份。
