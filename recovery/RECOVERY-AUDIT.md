# v13 完整面板恢复审计（隔离方案）

审计时间：2026-10-27 UTC  
范围：仅制作离线候选；未部署、未访问/修改生产容器或数据库、未推送。

## 结论

最可信的**完整原版面板基线**是：

`full-panel-package/flyboard-full-panel-a-baseline-v1`

理由：

1. 主目录与 `archive-extract-verify`、`archive-install-source` 两个副本 `diff -qr` 完全一致。
2. `flyboard-full-panel-a-baseline-v1.tar.gz.sha256` 校验通过。
3. payload 的 33 个文件全部通过 `PAYLOAD-SHA256SUMS`。
4. 它包含完整新装所需的 Compose、环境模板、4 个 migration、26 个实际功能覆盖文件、安装/卸载脚本；不是仅能覆盖现有安装的增量补丁。
5. `full-panel-package/inventory-report.md` 记录了这些文件来自 A 当时实际运行面板，并明确排除生产密钥、数据库、Redis、日志和节点凭据。

原始 `flyboard-panel-globaldevices-stablefix-v13-installer` 是 v13 增量核心来源；其核心四文件与完整基线一致。但后续 `v13-owner-lock-ip-window-hotfix` 在两个核心文件上修复了 IP 窗口绕过/续期问题，且带完整校验清单和测试，因此作为 v13 必需后续代码来源。

## 来源链

1. A 实际运行面板只读盘点 → `full-panel-package/inventory-report.md`。
2. 盘点产出 → `flyboard-full-panel-a-baseline-v1`（完整安装基线）。
3. 主树、归档解包验证树、归档安装源树三者逐字节一致；归档与 payload 哈希通过。
4. `stablefix-v13-installer` → 基线中的 v13 core：
   - `DeviceStateService.php`
   - `ServerService.php`
   - `NodeWorker.php`
   - `NodeEventHandlers.php`
5. `v13-owner-lock-ip-window-hotfix` → 在 v13 core 上新增：
   - 同节点变更 IP 不得先删除旧 claim 再绕过 `device_limit`；
   - 在线连接数遥测不得刷新设备 claim 的释放截止时间；
   - 同时携带 V20 owner-lock 文件和真实 `/s/{token}` 两入口控制器。
6. 本候选从完整基线复制，再只替换 hotfix 中确有后续修复的两个核心文件。

## 隔离候选改动清单

相对 `flyboard-full-panel-a-baseline-v1`：

- **替换** `payload/overrides/DeviceStateService.php`
  - 基线/v13：`13d6d90f...97ecb1b`
  - hotfix：`4f0a9f10...98aece`
  - 变化：保留同节点旧 IP 槽位直到动态 `flyboard_antishare_window` 到期或明确离线清理。
- **替换** `payload/overrides/ServerService.php`
  - 基线/v13：`fc298d1d...f81aca`
  - hotfix：`595989e7...2b234`
  - 变化：连接数 telemetry 不再调用 `refreshNodeUserActive`；device/alive 正向报告仍为权威续期来源。
- **保留**基线 `NodeWorker.php`、`NodeEventHandlers.php`：与 v13 和 hotfix 均字节一致。
- **保留**基线中真实 `/s/{token}` 入口：
  - `User/ServerController.php` 与 hotfix 字节一致；
  - `Client/AppController.php` 与 hotfix 字节一致。
- **刻意保留**基线 `FlyboardService.php`，不降级为 hotfix 包携带的较早 V20 文件：
  - 基线使用更严格 IP+UA owner fingerprint，并处理旧 UA owner 原子迁移及降限后的 effective-owner 选择；
  - hotfix/V20 为 UA-first，同 UA 的不同手机可能共享 fingerprint，策略更弱；
  - 因而 owner-lock 限制并未缺失，反而保留了更晚、更严格的实现。
- 更新 `VERSION.txt`、README 合成说明、重新生成候选 payload/全树哈希。

完整文本差异见 `evidence/candidate-vs-baseline.patch`。

## 安装、备份与回滚审计

### 完整基线 installer

- 新装目标必须不存在；固定镜像 digest；先验 payload 哈希。
- 失败时只清理本次新建 Compose 项目/卷和安装目录。
- 成功后 `uninstall.sh` 需输入 `DELETE`，会删除该新实例及其全新 DB/Redis。
- 风险：它是真正部署脚本，会 pull/start/migrate；本次审计**未运行**。

### hotfix installer

- 修改前备份每个目标文件和 Compose；记录原先不存在的文件。
- staging 后做 Compose 配置验证，再提交；失败自动恢复。
- 生成独立 `rollback.sh`，恢复文件与 Compose。
- 明确不 restart/deploy、不恢复 Redis RDB、不修改数据库。
- mock E2E 验证了服务范围隔离、故障注入自动回滚以及无 restart。

本候选是已合成完整新装树，不需要在候选上再执行增量 hotfix installer。

## 缺失与冲突

- **命名/叙述冲突**：原基线 README 称“v13 四核心哈希与 A 一致”；合入后续 hotfix 后两个文件必然不再等于旧 A 哈希，已在候选 README 修正。
- **owner-lock 策略冲突**：hotfix README 称其 owner-lock 与 V20 字节一致，但完整 A 基线已经是更严格的 IP+UA 实现。盲目覆盖会造成策略回退，因此没有覆盖 `FlyboardService.php`。
- **校验路径易错**：原 `PAYLOAD-SHA256SUMS` 路径相对 payload；必须在 `payload/` 内执行。证据中第一次错误调用保留在早期 evidence 文件，但最终验证按正确路径通过。
- **PHP 工具缺失**：审计主机没有 `php`，未安装系统包，因此无法本地运行 PHP lint 与 `ip-window-regression.php`；标记为未执行，而非通过。
- **未做运行态验证**：按隔离要求没有 pull 镜像、启动 Compose、迁移数据库、HTTP/WS/Horizon/Redis 验证。
- 基线固有风险仍存在：依赖远程固定镜像 digest；卸载是破坏性操作；首次部署仍应在 disposable host 完成全流程验收。

## 验证结果

通过：

- 基线 tar SHA-256。
- 基线 33 个 payload 文件 SHA-256。
- 三份基线树完全一致。
- hotfix tar SHA-256 与 `SHA256SUMS` 全部通过。
- 候选 33 个 payload 文件自校验通过。
- 候选 `install.sh` / `uninstall.sh` Bash 语法通过。
- hotfix `source-contract.sh`：`SOURCE_CONTRACT=PASS`。
- hotfix `installer-e2e.sh`：`ALL INSTALLER E2E TESTS PASSED`。

未执行：PHP lint、PHP IP-window regression、Compose/容器运行测试（原因见上）。

详细输出：`evidence/validation.txt`。

## 隔离产物

- 可审阅树：`/root/.openclaw/workspace-jucode3/isolated-work/v13-full-recovery/flyboard-full-panel-v13-recovered`
- 压缩包：`/root/.openclaw/workspace-jucode3/isolated-work/v13-full-recovery/flyboard-full-panel-v13-recovered.tar.gz`
- 压缩包 SHA-256：`29d34b8fbc886bb48b7849ee0a6eb2b994ca424adf914deb6e2082517bb7ce47`
- 审计证据：`/root/.openclaw/workspace-jucode3/isolated-work/v13-full-recovery/evidence`

此产物是**恢复候选，不是已部署版本**。
