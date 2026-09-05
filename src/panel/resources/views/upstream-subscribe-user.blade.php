<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{ $app_name ?? 'Flyboard' }} - 上游订阅</title>
  <style>
    :root{color-scheme:light dark;--bg:#f5f7fb;--card:#fff;--text:#1f2937;--muted:#6b7280;--line:#e5e7eb;--primary:#0f766e;--primary2:#14b8a6;--danger:#b91c1c}
    @media (prefers-color-scheme: dark){:root{--bg:#0b1220;--card:#111827;--text:#e5e7eb;--muted:#9ca3af;--line:#243044}}
    *{box-sizing:border-box} body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans SC",sans-serif;background:linear-gradient(135deg,var(--bg),#eefdfb);color:var(--text)}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:min(760px,100%);background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:0 20px 60px rgba(15,118,110,.16);padding:28px}.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.brand{font-size:26px;font-weight:800;color:var(--primary);margin:0}.sub{color:var(--muted);margin:8px 0 0}.status{display:inline-flex;align-items:center;border-radius:999px;padding:7px 12px;background:#dcfce7;color:#166534;font-size:13px;font-weight:700}.status.bad{background:#fee2e2;color:var(--danger)}
    .grid{display:grid;grid-template-columns:220px 1fr;gap:22px;margin-top:24px}.qr{background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px;text-align:center}.qr img{width:100%;height:auto;display:block}.info{display:grid;gap:12px}.row{padding:13px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(20,184,166,.035)}.label{font-size:12px;color:var(--muted);margin-bottom:6px}.value{font-weight:700;word-break:break-all}.urlbox{margin-top:22px}.url{width:100%;min-height:86px;border:1px solid var(--line);border-radius:14px;padding:14px;background:transparent;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;resize:vertical}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}button,a.btn{border:0;border-radius:12px;padding:12px 16px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}button.primary,a.primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff}button.secondary,a.secondary{background:#e6fffb;color:#0f766e}.hint{color:var(--muted);font-size:13px;line-height:1.7;margin-top:18px}.msg{margin-top:12px;font-weight:700}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}@media(max-width:720px){.top,.grid{display:block}.status{margin-top:14px}.qr{max-width:260px;margin:20px auto}.card{padding:20px}.actions>*{flex:1 1 150px}}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <div class="top">
        <div>
          <h1 class="brand">{{ $app_name ?? 'Flyboard' }}</h1>
          <p class="sub">上游订阅用户页面</p>
        </div>
        <span class="status{{ $available === true ? '' : ' bad' }}">{{ $available === true ? '可用' : $available }}</span>
      </div>
      <div class="grid">
        <div class="qr">
          <img src="{{ $qr_url }}" alt="订阅二维码">
          <div class="hint">扫码导入客户端</div>
        </div>
        <div class="info">
          <div class="row"><div class="label">用户</div><div class="value">{{ $user['email'] ?? '' }}</div></div>
          <div class="row"><div class="label">流量</div><div class="value">{{ $used_gb }} GB / {{ $total_gb > 0 ? $total_gb . ' GB' : '不限' }}</div></div>
          <div class="row"><div class="label">到期时间</div><div class="value">{{ $expire_text }}</div></div>
          <div class="row"><div class="label">在线设备限制</div><div class="value">{{ $online_count }} / {{ ($user['device_limit'] ?? 0) > 0 ? $user['device_limit'] : '不限' }}</div></div>
        </div>
      </div>
      <div class="urlbox">
        <div class="label">订阅地址</div>
        <textarea id="subUrl" class="url" readonly>{{ $subscribe_url }}</textarea>
        <div class="actions">
          <button class="primary" id="copyBtn">复制订阅地址</button>
          <a class="btn secondary" href="{{ $subscribe_url }}" target="_blank" rel="noopener">打开订阅</a>
          <a class="btn secondary" href="{{ $qr_url }}" target="_blank" rel="noopener">打开二维码</a>
        </div>
        <div id="msg" class="msg"></div>
      </div>
      <p class="hint">如果客户端更新订阅超时，请重新复制本页面地址导入；Flyboard 会优先返回已缓存的上游节点。</p>
    </section>
  </main>
<script>
async function copyText(text){
  try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; } }catch(e){}
  try{ const ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.left='-9999px'; document.body.appendChild(ta); ta.focus(); ta.select(); ta.setSelectionRange(0,ta.value.length); const ok=document.execCommand('copy'); document.body.removeChild(ta); if(ok) return true; }catch(e){}
  prompt('浏览器禁止自动复制，请手动复制订阅地址：', text); return false;
}
document.getElementById('copyBtn').onclick=async()=>{ const ok=await copyText(document.getElementById('subUrl').value.trim()); document.getElementById('msg').textContent=ok?'已复制订阅地址':'请手动复制订阅地址'; document.getElementById('msg').style.color=ok?'#0f766e':'#b91c1c'; };
</script>
</body>
</html>
