<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $title }}</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f8fb; color: #162033; }
    a { color: #2563eb; text-decoration: none; }
    .wrap { max-width: 1180px; margin: 0 auto; padding: 24px; }
    .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
    .top h1 { margin: 0; font-size: 24px; }
    .top p { margin: 6px 0 0; color: #64748b; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; box-shadow: 0 8px 24px rgba(15,23,42,.05); margin-bottom: 16px; }
    .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .grid.two { grid-template-columns: 2fr 1fr 1fr 1fr; }
    label { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; }
    input, select, textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 14px; background: #fff; }
    textarea { min-height: 110px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    button { border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 650; cursor: pointer; background: #2563eb; color: #fff; }
    button.secondary { background: #e2e8f0; color: #0f172a; }
    button.danger { background: #dc2626; }
    button.small { padding: 6px 9px; font-size: 12px; }
    button:disabled { opacity: .55; cursor: not-allowed; }
    .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .msg { padding: 10px 12px; border-radius: 8px; margin: 10px 0 0; display: none; }
    .msg.ok { background: #dcfce7; color: #166534; display: block; }
    .msg.err { background: #fee2e2; color: #991b1b; display: block; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 10px 8px; vertical-align: top; }
    th { color: #475569; background: #f8fafc; position: sticky; top: 0; }
    pre { margin: 12px 0 0; padding: 12px; background: #0f172a; color: #dbeafe; border-radius: 8px; overflow: auto; max-height: 280px; font-size: 12px; display: none; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
    .muted { color: #64748b; }
    .pill { display: inline-flex; padding: 3px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 12px; }
    .pill.ok { background:#dcfce7; color:#166534; } .pill.err { background:#fee2e2; color:#991b1b; }
    .note { background: #eef6ff; color: #1e3a8a; border: 1px solid #bfdbfe; padding: 12px; border-radius: 8px; }
    @media (max-width: 900px) { .grid, .grid.two { grid-template-columns: 1fr 1fr; } .top { align-items: flex-start; flex-direction: column; } }
    @media (max-width: 620px) { .grid, .grid.two { grid-template-columns: 1fr; } .wrap { padding: 14px; } table { min-width: 820px; } .table-wrap { overflow:auto; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Flyboard 管理</h1>
        <p>{{ $app_name }} · 当前上游 Xboard 后台 dist 缺少 Flyboard 菜单，这里提供可用的 Flyboard 功能入口。</p>
      </div>
      <div class="row">
        <a href="/{{ $secure_path }}#/dashboard">返回原后台</a>
      </div>
    </div>

    <div class="card note">
      已实现：防分享配置/日志，以及上游订阅拉取、测试和通用订阅合并。当前第一版只合并 URI/base64 订阅；Clash、Sing-box 等结构化 YAML/JSON 会跳过合并，避免破坏配置文件。
    </div>

    <div class="card">
      <h2 style="margin-top:0">拉取上游订阅</h2>
      <div class="grid two">
        <div>
          <label>上游订阅 URL（兼容旧配置，保存多上游后会自动填入第一个）</label>
          <input id="upstream_subscribe_url" placeholder="https://example.com/sub?token={token}">
        </div>
        <div>
          <label>启用</label>
          <select id="upstream_subscribe_enable">
            <option value="1">启用</option>
            <option value="0">关闭</option>
          </select>
        </div>
        <div>
          <label>合并模式</label>
          <select id="upstream_subscribe_merge_mode">
            <option value="append">追加到本地后</option>
            <option value="prepend">放到本地前</option>
            <option value="replace">仅使用上游</option>
          </select>
        </div>
        <div>
          <label>超时（秒）</label>
          <input id="upstream_subscribe_timeout" type="number" min="1" max="30" value="10">
        </div>
      </div>
      <div class="grid" style="margin-top:12px">
        <div>
          <label>最大内容大小（字节）</label>
          <input id="upstream_subscribe_max_bytes" type="number" min="1024" max="1048576" value="524288">
        </div>
        <div style="grid-column: span 3">
          <label>User-Agent（可选）</label>
          <input id="upstream_subscribe_user_agent" placeholder="留空则沿用订阅客户端 UA">
        </div>
      </div>
      <div style="margin-top:14px">
        <label>多上游节点（每行一个：名称|URL|启用；也支持直接每行 URL。URL 支持 {token}、{user_id}、{email}、{uuid} 占位符）</label>
        <textarea id="upstream_profiles_text" placeholder="上游A|https://example.com/s/{token}|1&#10;上游B|https://example.net/sub?token={token}|1"></textarea>
      </div>
      <div class="table-wrap" style="margin-top:12px">
        <table>
          <thead><tr><th>名称</th><th>状态</th><th>URL</th><th>操作</th></tr></thead>
          <tbody id="upstreamProfilesBody"><tr><td colspan="4" class="muted">加载中...</td></tr></tbody>
        </table>
      </div>
      <div class="row" style="margin-top:14px">
        <button id="saveUpstreamBtn">保存上游配置</button>
        <button class="secondary" id="testUpstreamBtn">测试拉取</button>
        <button class="secondary" id="addUpstreamBtn">增加一行上游</button>
      </div>
      <div id="upstreamMsg" class="msg"></div>
      <pre id="upstreamPreview"></pre>
    </div>

    <div class="card">
      <h2 style="margin-top:0">上游用户管理</h2>
      <p class="muted">独立上游用户，不使用主用户表；只用于拉取上游订阅节点。在线设备显示使用独立 Redis key，不影响真实节点在线/设备限制。</p>
      <div class="row" style="margin-bottom:12px">
        <button id="newUpstreamUserBtn">+ 创建上游用户</button>
        <button class="secondary" id="upstreamUserSearchBtn">刷新/查询</button>
        <input id="upstream_user_keyword" style="max-width:260px" placeholder="搜索用户邮箱或 ID...">
        <span id="upstreamUserPageInfo" class="muted">第 1 页</span>
      </div>
      <div id="upstreamUserForm" class="note" style="display:none; margin-bottom:14px">
        <input id="upstream_edit_id" type="hidden">
        <div class="grid">
          <div><label>邮箱</label><input id="upstream_edit_email" placeholder="user@example.com"></div>
          <div><label>密码（新增可选；编辑留空不改）</label><input id="upstream_edit_password" type="password" placeholder="如需设置密码请输入"></div>
          <div><label>流量限制 GB</label><input id="upstream_edit_transfer" type="number" min="0" step="1" value="100"></div>
          <div><label>在线设备限制</label><input id="upstream_edit_device_limit" type="number" min="0" max="255" value="1"></div>
          <div><label>已用上行 GB</label><input id="upstream_edit_u" type="number" min="0" step="0.01" value="0"></div>
          <div><label>已用下行 GB</label><input id="upstream_edit_d" type="number" min="0" step="0.01" value="0"></div>
          <div><label>到期时间</label><input id="upstream_edit_expired_at" type="datetime-local"></div>
          <div><label>账户状态</label><select id="upstream_edit_banned"><option value="0">正常</option><option value="1">封禁</option></select></div>
          <div style="grid-column: span 4"><label>备注</label><input id="upstream_edit_remarks" placeholder="可选"></div>
        </div>
        <div class="row" style="margin-top:12px">
          <button id="saveUpstreamUserBtn">保存上游用户</button>
          <button class="secondary" id="cancelUpstreamUserBtn">取消</button>
        </div>
      </div>
      <div class="row" style="margin-top:8px">
        <button class="secondary" id="upstreamUserPrevBtn">上一页</button>
        <input id="upstream_user_page_size" style="max-width:90px" type="number" min="1" max="50" value="10">
        <button class="secondary" id="upstreamUserNextBtn">下一页</button>
        <button class="secondary" id="testUpstreamUserBtn">用选中用户测试拉取</button>
        <input id="upstream_test_user_id" style="max-width:150px" placeholder="选中用户ID">
      </div>
      <div id="upstreamUserMsg" class="msg"></div>
      <div class="table-wrap" style="margin-top:14px">
        <table>
          <thead><tr><th>ID</th><th>邮箱</th><th>在线设备</th><th>状态</th><th>流量</th><th>到期</th><th>订阅地址</th><th>操作</th></tr></thead>
          <tbody id="upstreamUsersBody"><tr><td colspan="8" class="muted">点击刷新/查询上游用户</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2 style="margin-top:0">防分享配置</h2>
      <div class="grid">
        <div>
          <label>启用防分享</label>
          <select id="subscribe_guard_enable">
            <option value="1">启用</option>
            <option value="0">关闭</option>
          </select>
        </div>
        <div>
          <label>统计窗口（小时）</label>
          <input id="subscribe_guard_window_hours" type="number" min="1" max="720" value="24">
        </div>
        <div>
          <label>窗口内允许 IP 数</label>
          <input id="subscribe_guard_max_ips" type="number" min="1" max="255" value="3">
        </div>
        <div>
          <label>超限动作</label>
          <select id="subscribe_guard_action">
            <option value="reject">拒绝订阅</option>
            <option value="log_only">只记录不拦截</option>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top:14px">
        <button id="saveGuardBtn">保存防分享配置</button>
        <button class="secondary" id="reloadBtn">重新读取</button>
      </div>
      <div id="configMsg" class="msg"></div>
    </div>

    <div class="card">
      <h2 style="margin-top:0">后台 IP 白名单</h2>
      <p class="muted">只限制后台页面和后台 API，不影响用户订阅 <span class="mono">/s/...</span>、节点连接和服务端上报。</p>
      <div class="grid">
        <div>
          <label>启用后台白名单</label>
          <select id="admin_ip_whitelist_enable">
            <option value="0">关闭</option>
            <option value="1">启用</option>
          </select>
        </div>
        <div style="grid-column: span 3">
          <label>备注</label>
          <input id="admin_ip_whitelist_note" placeholder="例如：192.168.31.81 是内网地址，仅备注，公网访问不会匹配">
        </div>
      </div>
      <div style="margin-top:12px">
        <label>允许访问后台的 IP / CIDR（每行一个，支持多个）</label>
        <textarea id="admin_ip_whitelist_entries" placeholder="198.23.227.132&#10;45.143.128.45&#10;13.229.106.35"></textarea>
      </div>
      <div class="row" style="margin-top:14px">
        <button id="saveWhitelistBtn">保存后台白名单</button>
        <button class="secondary" id="disableWhitelistBtn">关闭白名单</button>
      </div>
      <div id="whitelistMsg" class="msg"></div>
    </div>


    <div class="card">
      <h2 style="margin-top:0">后台白名单拦截记录</h2>
      <div class="grid">
        <div><label>IP</label><input id="whitelist_log_ip" placeholder="可选"></div>
        <div><label>每页数量</label><input id="whitelist_log_per_page" type="number" min="1" max="100" value="20"></div>
      </div>
      <div class="row" style="margin-top:14px">
        <button id="whitelistLogSearchBtn">查询拦截记录</button>
        <button class="secondary" id="whitelistLogPrevBtn">上一页</button>
        <span id="whitelistLogPageInfo" class="muted">第 1 页</span>
        <button class="secondary" id="whitelistLogNextBtn">下一页</button>
      </div>
      <div id="whitelistLogMsg" class="msg"></div>
      <div class="table-wrap" style="margin-top:14px">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>IP</th><th>路径</th><th>方法</th><th>X-Forwarded-For</th><th>User-Agent</th><th>时间</th>
            </tr>
          </thead>
          <tbody id="whitelistLogsBody"><tr><td colspan="7" class="muted">加载中...</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2 style="margin-top:0">访问日志</h2>
      <div class="grid">
        <div><label>用户 ID</label><input id="filter_user_id" placeholder="可选"></div>
        <div><label>IP</label><input id="filter_ip" placeholder="可选"></div>
        <div><label>Token</label><input id="filter_token" placeholder="可选"></div>
        <div><label>每页数量</label><input id="per_page" type="number" min="1" max="100" value="20"></div>
      </div>
      <div class="row" style="margin-top:14px">
        <button id="searchBtn">查询日志</button>
        <button class="secondary" id="prevBtn">上一页</button>
        <span id="pageInfo" class="muted">第 1 页</span>
        <button class="secondary" id="nextBtn">下一页</button>
        <button class="danger" id="clearBtn">清理 30 天前日志</button>
      </div>
      <div id="logMsg" class="msg"></div>
      <div class="table-wrap" style="margin-top:14px">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>用户</th><th>IP</th><th>次数</th><th>首次访问</th><th>最后访问</th><th>Token</th><th>User-Agent</th><th>操作</th>
            </tr>
          </thead>
          <tbody id="logsBody"><tr><td colspan="9" class="muted">加载中...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

<script>
(function () {
  const securePath = @json($secure_path);
  const apiBase = '/api/v2/' + securePath;
  let page = 1;
  let lastPage = 1;
  let whitelistLogPage = 1;
  let whitelistLogLastPage = 1;
  let upstreamUserPage = 1;
  let upstreamUserLastPage = 1;
  let upstreamProfiles = [];

  function normalizeToken(value) {
    if (!value || typeof value !== 'string') return '';
    value = value.trim();
    if (!value) return '';
    if (/^Bearer\s+/i.test(value)) return value;
    // Xboard 后台接口要求 Authorization 是 Bearer token；如果只读到裸 token，自动补齐。
    if (/^[A-Za-z0-9_\-\.\|:]{20,}$/.test(value)) return 'Bearer ' + value.replace(/^\d+\|/, '');
    return '';
  }

  function readStoredToken(key) {
    const raw = localStorage.getItem(key);
    if (!raw) return '';
    try {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        if (parsed.expire && Number(parsed.expire) <= Date.now()) return '';
        return normalizeToken(parsed.value || parsed.auth_data || parsed.token || '');
      }
      if (typeof parsed === 'string') return normalizeToken(parsed);
    } catch (e) {}
    return normalizeToken(raw);
  }

  function token() {
    // 当前 Xboard dist 实际保存为 Xboard_access_token，其它 key 用于兼容旧版/不同构建。
    const preferred = ['Xboard_access_token', 'access_token', 'xboard_access_token', 'auth_data', 'Xboard_auth_data'];
    for (const key of preferred) {
      const value = readStoredToken(key);
      if (value) return value;
    }
    // 兜底扫描 localStorage：有些构建会压缩/改名 token key。
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      const value = readStoredToken(key);
      if (value) return value;
    }
    return '';
  }

  function clearAuthState() {
    ['Xboard_access_token', 'access_token', 'xboard_access_token', 'auth_data', 'Xboard_auth_data'].forEach(key => localStorage.removeItem(key));
  }

  function loginUrl() {
    return '/' + securePath + '#/sign-in?redirect=' + encodeURIComponent('/' + securePath + '/flyboard');
  }

  function showLoginError(prefix) {
    const text = (prefix || '未检测到后台登录态') + '。请点击“去登录”重新登录后台，然后再从右下角“Flyboard 功能”进入本页。';
    ['upstreamMsg', 'upstreamUserMsg', 'configMsg', 'logMsg', 'whitelistMsg', 'whitelistLogMsg'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.innerHTML = text + ' <a href="' + loginUrl() + '" style="font-weight:700;color:#991b1b;text-decoration:underline">去登录</a>';
        el.className = 'msg err';
      }
    });
  }

  function ensureLoggedIn() {
    if (token()) return true;
    showLoginError('未检测到后台登录态');
    return false;
  }

  async function request(path, options = {}) {
    if (!ensureLoggedIn()) throw new Error('未登录');
    const headers = Object.assign({'Content-Type': 'application/json'}, options.headers || {});
    const accessToken = token();
    if (accessToken) headers.Authorization = accessToken;
    headers['Content-Language'] = localStorage.getItem('i18nextLng') || 'zh-CN';
    const resp = await fetch(apiBase + path, Object.assign({}, options, {headers}));
    const json = await resp.json().catch(() => ({}));
    if (resp.status === 401 || resp.status === 403) {
      clearAuthState();
      showLoginError('后台登录态无效或已过期');
      throw new Error('后台登录态无效或已过期，请重新登录后台后再进入 Flyboard 功能页。');
    }
    if (!resp.ok || json.status === 'fail') {
      throw new Error(json.message || json.error || ('HTTP ' + resp.status));
    }
    return json.data !== undefined ? json.data : json;
  }

  function msg(id, text, ok = true) {
    const el = document.getElementById(id);
    el.textContent = text;
    el.className = 'msg ' + (ok ? 'ok' : 'err');
  }

  function val(id) { return document.getElementById(id).value; }
  function setVal(id, value) { document.getElementById(id).value = value == null ? '' : String(value); }

  async function loadConfig() {
    try {
      const data = await request('/config/fetch?key=subscribe');
      const cfg = data.subscribe || data;
      setVal('subscribe_guard_enable', cfg.subscribe_guard_enable ? 1 : 0);
      setVal('subscribe_guard_window_hours', cfg.subscribe_guard_window_hours || 24);
      setVal('subscribe_guard_max_ips', cfg.subscribe_guard_max_ips || 3);
      setVal('subscribe_guard_action', cfg.subscribe_guard_action || 'reject');
      setVal('upstream_subscribe_enable', cfg.upstream_subscribe_enable ? 1 : 0);
      setVal('upstream_subscribe_url', cfg.upstream_subscribe_url || '');
      setVal('upstream_subscribe_merge_mode', cfg.upstream_subscribe_merge_mode || 'append');
      setVal('upstream_subscribe_timeout', cfg.upstream_subscribe_timeout || 10);
      setVal('upstream_subscribe_max_bytes', cfg.upstream_subscribe_max_bytes || 524288);
      setVal('upstream_subscribe_user_agent', cfg.upstream_subscribe_user_agent || '');
      upstreamProfiles = normalizeProfiles(cfg.upstream_subscribe_profiles || [], cfg.upstream_subscribe_url || '');
      renderUpstreamProfiles();
      const safeData = await request('/config/fetch?key=safe');
      const safe = safeData.safe || safeData;
      setVal('admin_ip_whitelist_enable', safe.admin_ip_whitelist_enable ? 1 : 0);
      setVal('admin_ip_whitelist_entries', Array.isArray(safe.admin_ip_whitelist_entries) ? safe.admin_ip_whitelist_entries.join('\n') : (safe.admin_ip_whitelist_entries || ''));
      setVal('admin_ip_whitelist_note', safe.admin_ip_whitelist_note || '');
      msg('configMsg', '配置已读取');
      msg('upstreamMsg', '上游配置已读取');
      msg('whitelistMsg', '后台白名单配置已读取');
    } catch (e) {
      msg('configMsg', '读取配置失败：' + e.message, false);
      msg('upstreamMsg', '读取配置失败：' + e.message, false);
      msg('whitelistMsg', '读取配置失败：' + e.message, false);
    }
  }

  async function saveConfig(extra, msgId, okText) {
    try {
      await request('/config/save', {method: 'POST', body: JSON.stringify(extra)});
      msg(msgId, okText);
    } catch (e) {
      msg(msgId, '保存失败：' + e.message, false);
    }
  }

  function guardPayload() {
    return {
      subscribe_guard_enable: Number(val('subscribe_guard_enable')),
      subscribe_guard_window_hours: Number(val('subscribe_guard_window_hours')),
      subscribe_guard_max_ips: Number(val('subscribe_guard_max_ips')),
      subscribe_guard_action: val('subscribe_guard_action')
    };
  }

  function normalizeProfiles(raw, legacyUrl) {
    let profiles = Array.isArray(raw) ? raw : [];
    profiles = profiles.map((p, idx) => ({
      id: String(p.id || ('upstream_' + (idx + 1))),
      name: String(p.name || ('上游 ' + (idx + 1))),
      url: String(p.url || '').trim(),
      enabled: p.enabled !== false && p.enabled !== 0 && p.enabled !== '0'
    })).filter(p => p.url);
    if (!profiles.length && legacyUrl) profiles.push({id:'legacy', name:'默认上游', url:String(legacyUrl), enabled:true});
    return profiles;
  }

  function profilesFromText() {
    const lines = val('upstream_profiles_text').split(/[\r\n]+/).map(v => v.trim()).filter(Boolean);
    return lines.map((line, idx) => {
      const parts = line.split('|');
      if (parts.length === 1) return {id:'upstream_' + (idx + 1), name:'上游 ' + (idx + 1), url:parts[0].trim(), enabled:true};
      return {
        id:'upstream_' + (idx + 1),
        name:(parts[0] || ('上游 ' + (idx + 1))).trim(),
        url:(parts[1] || '').trim(),
        enabled: !['0','false','关闭','停用'].includes(String(parts[2] || '1').trim().toLowerCase())
      };
    }).filter(p => p.url);
  }

  function profilesToText(profiles) {
    return (profiles || []).map(p => [p.name || '上游', p.url || '', p.enabled ? '1' : '0'].join('|')).join('\n');
  }

  function syncProfilesFromText() {
    upstreamProfiles = profilesFromText();
    renderUpstreamProfiles();
  }

  function renderUpstreamProfiles() {
    const text = document.getElementById('upstream_profiles_text');
    if (text) text.value = profilesToText(upstreamProfiles);
    const body = document.getElementById('upstreamProfilesBody');
    if (!body) return;
    body.innerHTML = upstreamProfiles.length ? upstreamProfiles.map((p, idx) => `
      <tr>
        <td>${fmt(p.name)}</td>
        <td><span class="pill ${p.enabled ? 'ok' : 'err'}">${p.enabled ? '启用' : '关闭'}</span></td>
        <td class="mono">${fmt(p.url)}</td>
        <td><button class="secondary small" data-toggle-upstream="${idx}">${p.enabled ? '关闭' : '启用'}</button> <button class="danger small" data-remove-upstream="${idx}">删除</button></td>
      </tr>`).join('') : '<tr><td colspan="4" class="muted">未配置多上游节点</td></tr>';
  }

  function upstreamPayload() {
    syncProfilesFromText();
    return {
      upstream_subscribe_enable: Number(val('upstream_subscribe_enable')),
      upstream_subscribe_url: upstreamProfiles[0] ? upstreamProfiles[0].url : val('upstream_subscribe_url'),
      upstream_subscribe_profiles: upstreamProfiles,
      upstream_subscribe_merge_mode: val('upstream_subscribe_merge_mode'),
      upstream_subscribe_timeout: Number(val('upstream_subscribe_timeout')),
      upstream_subscribe_max_bytes: Number(val('upstream_subscribe_max_bytes')),
      upstream_subscribe_user_agent: val('upstream_subscribe_user_agent')
    };
  }

  function whitelistEntries() {
    return val('admin_ip_whitelist_entries')
      .split(/[\r\n,，\s]+/)
      .map(v => v.trim())
      .filter(Boolean);
  }

  function whitelistPayload(forceDisable) {
    return {
      admin_ip_whitelist_enable: forceDisable ? 0 : Number(val('admin_ip_whitelist_enable')),
      admin_ip_whitelist_entries: whitelistEntries(),
      admin_ip_whitelist_note: val('admin_ip_whitelist_note')
    };
  }

  async function testUpstream() {
    try {
      await saveConfig(upstreamPayload(), 'upstreamMsg', '上游配置已保存，正在测试...');
      const uid = val('upstream_test_user_id');
      const data = await request('/upstream-subscribe/test', {method: 'POST', body: JSON.stringify(uid ? {upstream_user_id: Number(uid)} : {})});
      const preview = document.getElementById('upstreamPreview');
      preview.style.display = 'block';
      preview.textContent = JSON.stringify(data, null, 2);
      msg('upstreamMsg', '拉取成功：上游 ' + (data.source_count || 0) + ' 个，有用节点 ' + data.uri_count + ' 条，自动删除无用 ' + (data.useless_count || 0) + ' 条，大小 ' + data.bytes + ' 字节');
    } catch (e) {
      msg('upstreamMsg', '测试失败：' + e.message, false);
    }
  }

  function fmt(v) { return v || '<span class="muted">-</span>'; }

  async function loadLogs(nextPage) {
    page = Math.max(1, nextPage || 1);
    const params = new URLSearchParams({page, per_page: val('per_page') || 20});
    if (val('filter_user_id')) params.set('user_id', val('filter_user_id'));
    if (val('filter_ip')) params.set('ip', val('filter_ip'));
    if (val('filter_token')) params.set('token', val('filter_token'));
    try {
      const data = await request('/subscribe-guard/fetch?' + params.toString());
      const rows = data.data || [];
      lastPage = data.last_page || 1;
      document.getElementById('pageInfo').textContent = '第 ' + (data.current_page || page) + ' / ' + lastPage + ' 页，共 ' + (data.total || 0) + ' 条';
      document.getElementById('logsBody').innerHTML = rows.length ? rows.map(r => `
        <tr>
          <td>${r.id}</td>
          <td>${r.user_id}</td>
          <td class="mono">${fmt(r.ip)}</td>
          <td><span class="pill">${r.access_count || 0}</span></td>
          <td>${fmt(r.first_access_at)}</td>
          <td>${fmt(r.last_access_at)}</td>
          <td class="mono">${fmt(r.token)}</td>
          <td>${fmt(r.user_agent)}</td>
          <td><button class="danger" data-drop="${r.id}">删除</button></td>
        </tr>`).join('') : '<tr><td colspan="9" class="muted">没有日志</td></tr>';
      msg('logMsg', '日志已更新');
    } catch (e) {
      msg('logMsg', '读取日志失败：' + e.message, false);
    }
  }


  async function loadWhitelistLogs(nextPage) {
    whitelistLogPage = Math.max(1, nextPage || 1);
    const params = new URLSearchParams({page: whitelistLogPage, per_page: val('whitelist_log_per_page') || 20});
    if (val('whitelist_log_ip')) params.set('ip', val('whitelist_log_ip'));
    try {
      const data = await request('/config/adminIpWhitelistLogs?' + params.toString());
      const rows = data.data || [];
      whitelistLogLastPage = data.last_page || 1;
      document.getElementById('whitelistLogPageInfo').textContent = '第 ' + (data.current_page || whitelistLogPage) + ' / ' + whitelistLogLastPage + ' 页，共 ' + (data.total || 0) + ' 条';
      document.getElementById('whitelistLogsBody').innerHTML = rows.length ? rows.map(r => `
        <tr>
          <td>${r.id}</td>
          <td class="mono">${fmt(r.ip)}</td>
          <td class="mono">${fmt(r.uri)}</td>
          <td>${fmt(r.method)}</td>
          <td class="mono">${fmt(r.xff)}</td>
          <td>${fmt(r.user_agent)}</td>
          <td>${fmt(r.created_at)}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="muted">没有后台白名单拦截记录</td></tr>';
      msg('whitelistLogMsg', '后台白名单拦截记录已更新');
    } catch (e) {
      msg('whitelistLogMsg', '读取后台白名单拦截记录失败：' + e.message, false);
    }
  }

  function fmtBytes(bytes) {
    bytes = Number(bytes || 0);
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    return bytes + ' B';
  }

  function fmtTime(ts) {
    ts = Number(ts || 0);
    if (!ts) return '<span class="muted">长期</span>';
    try { return new Date(ts * 1000).toLocaleString(); } catch(e) { return String(ts); }
  }

  function toLocalDatetime(ts) {
    ts = Number(ts || 0);
    if (!ts) return '';
    const d = new Date(ts * 1000);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
  }

  function fromLocalDatetime(value) {
    return value ? Math.floor(new Date(value).getTime() / 1000) : 0;
  }

  function gb(bytes) { return Number(bytes || 0) / 1073741824; }

  function showUpstreamUserForm(user) {
    user = user || {};
    document.getElementById('upstreamUserForm').style.display = 'block';
    setVal('upstream_edit_id', user.id || '');
    setVal('upstream_edit_email', user.email || '');
    setVal('upstream_edit_password', '');
    setVal('upstream_edit_transfer', user.transfer_enable ? gb(user.transfer_enable).toFixed(0) : 100);
    setVal('upstream_edit_device_limit', user.device_limit == null ? 1 : user.device_limit);
    setVal('upstream_edit_u', user.u ? gb(user.u).toFixed(2) : 0);
    setVal('upstream_edit_d', user.d ? gb(user.d).toFixed(2) : 0);
    setVal('upstream_edit_expired_at', toLocalDatetime(user.expired_at || 0));
    setVal('upstream_edit_banned', user.banned ? 1 : 0);
    setVal('upstream_edit_remarks', user.remarks || '');
    if (!user.id) document.getElementById('upstream_edit_email').focus();
  }

  function hideUpstreamUserForm() {
    document.getElementById('upstreamUserForm').style.display = 'none';
  }

  function upstreamUserPayload() {
    const payload = {
      email: val('upstream_edit_email'),
      password: val('upstream_edit_password'),
      transfer_enable: Number(val('upstream_edit_transfer') || 0),
      u: Number(val('upstream_edit_u') || 0),
      d: Number(val('upstream_edit_d') || 0),
      expired_at: fromLocalDatetime(val('upstream_edit_expired_at')),
      device_limit: Number(val('upstream_edit_device_limit') || 0),
      banned: Number(val('upstream_edit_banned')),
      remarks: val('upstream_edit_remarks')
    };
    if (val('upstream_edit_id')) payload.id = Number(val('upstream_edit_id'));
    if (!payload.password) delete payload.password;
    return payload;
  }

  async function saveUpstreamUser() {
    try {
      const user = await request('/upstream-subscribe/users/save', {method:'POST', body: JSON.stringify(upstreamUserPayload())});
      hideUpstreamUserForm();
      setVal('upstream_test_user_id', user.id || '');
      msg('upstreamUserMsg', '上游用户已保存');
      await loadUpstreamUsers(upstreamUserPage);
    } catch(e) {
      msg('upstreamUserMsg', '保存上游用户失败：' + e.message, false);
    }
  }

  async function deleteUpstreamUser(id) {
    if (!confirm('删除上游用户 #' + id + '？')) return;
    try {
      await request('/upstream-subscribe/users/delete', {method:'POST', body: JSON.stringify({id: Number(id)})});
      msg('upstreamUserMsg', '上游用户已删除');
      await loadUpstreamUsers(upstreamUserPage);
    } catch(e) { msg('upstreamUserMsg', '删除失败：' + e.message, false); }
  }

  async function resetUpstreamUserToken(id) {
    if (!confirm('重置上游用户 #' + id + ' 的订阅 Token？旧订阅地址会失效。')) return;
    try {
      const user = await request('/upstream-subscribe/users/reset-token', {method:'POST', body: JSON.stringify({id: Number(id)})});
      msg('upstreamUserMsg', 'Token 已重置，新订阅地址：' + user.subscribe_url);
      await loadUpstreamUsers(upstreamUserPage);
    } catch(e) { msg('upstreamUserMsg', '重置失败：' + e.message, false); }
  }

  async function copyText(text) {
    text = String(text || '');
    if (!text) return false;
    if (navigator.clipboard && window.isSecureContext) {
      try { await navigator.clipboard.writeText(text); return true; } catch(e) {}
    }
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, ta.value.length);
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (ok) return true;
    } catch(e) {}
    prompt('浏览器禁止自动复制，请手动复制订阅地址：', text);
    return false;
  }

  async function loadUpstreamUsers(nextPage) {
    upstreamUserPage = Math.max(1, nextPage || 1);
    try {
      const data = await request('/upstream-subscribe/users', {method:'POST', body:JSON.stringify({current: upstreamUserPage, pageSize: Number(val('upstream_user_page_size') || 10), keyword: val('upstream_user_keyword').trim()})});
      const rows = data.data || [];
      upstreamUserLastPage = data.last_page || 1;
      document.getElementById('upstreamUserPageInfo').textContent = '第 ' + (data.current_page || upstreamUserPage) + ' / ' + upstreamUserLastPage + ' 页，共 ' + (data.total || 0) + ' 个上游用户';
      document.getElementById('upstreamUsersBody').innerHTML = rows.length ? rows.map(u => {
        const used = Number(u.u || 0) + Number(u.d || 0);
        const expired = Number(u.expired_at || 0) > 0 && Number(u.expired_at) <= Date.now() / 1000;
        const banned = Number(u.banned || 0) === 1;
        const status = banned ? '封禁' : (expired ? '到期' : '正常');
        return `
        <tr data-user-json="${encodeURIComponent(JSON.stringify(u))}">
          <td><span class="pill">${u.id}</span></td>
          <td>${fmt(u.email)}</td>
          <td><span class="pill">${Number(u.online_count || 0)} / ${Number(u.device_limit || 0) || '不限'}</span></td>
          <td><span class="pill ${status === '正常' ? 'ok' : 'err'}">${status}</span></td>
          <td>${fmtBytes(used)} / ${fmtBytes(u.transfer_enable)}</td>
          <td>${fmtTime(u.expired_at)}</td>
          <td class="mono">${fmt(u.subscribe_url)}</td>
          <td>
            <button class="secondary small" data-select-upstream-user="${u.id}">选择</button>
            <button class="secondary small" data-edit-upstream-user>编辑</button>
            <button class="secondary small" data-copy-upstream-url="${encodeURIComponent(u.subscribe_url || '')}">复制订阅</button>
            <a class="secondary small" href="/upstream-u/${encodeURIComponent(u.token || '')}" target="_blank" rel="noopener">用户页面</a>
            <a class="secondary small" href="/upstream-s/${encodeURIComponent(u.token || '')}/qr.svg" target="_blank" rel="noopener">二维码</a>
            <button class="secondary small" data-reset-upstream-token="${u.id}">重置Token</button>
            <button class="danger small" data-delete-upstream-user="${u.id}">删除</button>
          </td>
        </tr>`;
      }).join('') : '<tr><td colspan="8" class="muted">没有上游用户，点击“创建上游用户”新增</td></tr>';
      msg('upstreamUserMsg', '上游用户列表已更新');
    } catch(e) {
      msg('upstreamUserMsg', '读取上游用户失败：' + e.message, false);
    }
  }

  async function dropLog(id) {
    if (!confirm('删除日志 #' + id + '？')) return;
    try {
      await request('/subscribe-guard/drop', {method: 'POST', body: JSON.stringify({ids: [Number(id)]})});
      await loadLogs(page);
    } catch (e) { msg('logMsg', '删除失败：' + e.message, false); }
  }

  async function clearLogs() {
    const days = prompt('清理多少天以前的日志？', '30');
    if (!days) return;
    try {
      const data = await request('/subscribe-guard/clear', {method: 'POST', body: JSON.stringify({days: Number(days)})});
      msg('logMsg', '已清理 ' + (data.deleted || 0) + ' 条日志');
      await loadLogs(1);
    } catch (e) { msg('logMsg', '清理失败：' + e.message, false); }
  }

  document.getElementById('saveGuardBtn').onclick = () => saveConfig(guardPayload(), 'configMsg', '防分享配置已保存');
  document.getElementById('saveUpstreamBtn').onclick = () => saveConfig(upstreamPayload(), 'upstreamMsg', '上游订阅配置已保存');
  document.getElementById('saveWhitelistBtn').onclick = () => saveConfig(whitelistPayload(false), 'whitelistMsg', '后台白名单已保存');
  document.getElementById('disableWhitelistBtn').onclick = () => saveConfig(whitelistPayload(true), 'whitelistMsg', '后台白名单已关闭');
  document.getElementById('testUpstreamBtn').onclick = testUpstream;
  document.getElementById('reloadBtn').onclick = loadConfig;

  document.getElementById('whitelistLogSearchBtn').onclick = () => loadWhitelistLogs(1);
  document.getElementById('whitelistLogPrevBtn').onclick = () => loadWhitelistLogs(Math.max(1, whitelistLogPage - 1));
  document.getElementById('whitelistLogNextBtn').onclick = () => loadWhitelistLogs(Math.min(whitelistLogLastPage, whitelistLogPage + 1));
  document.getElementById('searchBtn').onclick = () => loadLogs(1);
  document.getElementById('prevBtn').onclick = () => loadLogs(Math.max(1, page - 1));
  document.getElementById('nextBtn').onclick = () => loadLogs(Math.min(lastPage, page + 1));
  document.getElementById('clearBtn').onclick = clearLogs;
  document.getElementById('addUpstreamBtn').onclick = () => { syncProfilesFromText(); upstreamProfiles.push({id:'upstream_' + (upstreamProfiles.length + 1), name:'上游 ' + (upstreamProfiles.length + 1), url:'', enabled:true}); renderUpstreamProfiles(); };
  document.getElementById('upstream_profiles_text').onchange = syncProfilesFromText;
  document.getElementById('upstreamProfilesBody').onclick = e => {
    const ds = e.target && e.target.dataset ? e.target.dataset : {};
    if (ds.toggleUpstream !== undefined) { syncProfilesFromText(); const i = Number(ds.toggleUpstream); upstreamProfiles[i].enabled = !upstreamProfiles[i].enabled; renderUpstreamProfiles(); }
    if (ds.removeUpstream !== undefined) { syncProfilesFromText(); upstreamProfiles.splice(Number(ds.removeUpstream), 1); renderUpstreamProfiles(); }
  };
  document.getElementById('newUpstreamUserBtn').onclick = () => showUpstreamUserForm();
  document.getElementById('saveUpstreamUserBtn').onclick = saveUpstreamUser;
  document.getElementById('cancelUpstreamUserBtn').onclick = hideUpstreamUserForm;
  document.getElementById('upstreamUserSearchBtn').onclick = () => loadUpstreamUsers(1);
  document.getElementById('upstreamUserPrevBtn').onclick = () => loadUpstreamUsers(Math.max(1, upstreamUserPage - 1));
  document.getElementById('upstreamUserNextBtn').onclick = () => loadUpstreamUsers(Math.min(upstreamUserLastPage, upstreamUserPage + 1));
  document.getElementById('testUpstreamUserBtn').onclick = testUpstream;
  document.getElementById('upstreamUsersBody').onclick = e => {
    const ds = e.target && e.target.dataset ? e.target.dataset : {};
    const row = e.target.closest ? e.target.closest('tr[data-user-json]') : null;
    const user = row ? JSON.parse(decodeURIComponent(row.dataset.userJson)) : null;
    if (ds.selectUpstreamUser) { setVal('upstream_test_user_id', ds.selectUpstreamUser); msg('upstreamUserMsg', '已选择上游用户 #' + ds.selectUpstreamUser + ' 用于测试拉取'); }
    if (ds.editUpstreamUser !== undefined && user) showUpstreamUserForm(user);
    if (ds.copyUpstreamUrl) copyText(decodeURIComponent(ds.copyUpstreamUrl)).then(ok => msg('upstreamUserMsg', ok ? '订阅地址已复制' : '浏览器禁止自动复制，请在弹窗中手动复制', ok));
    if (ds.resetUpstreamToken) resetUpstreamUserToken(ds.resetUpstreamToken);
    if (ds.deleteUpstreamUser) deleteUpstreamUser(ds.deleteUpstreamUser);
  };
  document.getElementById('logsBody').onclick = e => {
    const id = e.target && e.target.dataset ? e.target.dataset.drop : null;
    if (id) dropLog(id);
  };

  loadConfig();
  loadUpstreamUsers(1);
  loadWhitelistLogs(1);
  loadLogs(1);
})();
</script>
</body>
</html>
