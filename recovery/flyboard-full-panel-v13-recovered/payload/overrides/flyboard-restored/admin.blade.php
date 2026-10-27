<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
    <script src="/assets/admin/locales/en-US.js"></script>
    <script src="/assets/admin/locales/zh-CN.js"></script>
    <script src="/assets/admin/locales/ko-KR.js"></script>
  @endif
  <style>
    #flyboard-launcher{position:fixed;right:22px;bottom:22px;z-index:2147483647;background:#159eaa;color:#fff;border:1px solid #67e8f9;border-radius:999px;padding:12px 18px;font:600 14px system-ui,sans-serif;text-decoration:none;box-shadow:0 8px 24px #0006}
    #flyboard-launcher:hover{background:#0e8290;transform:translateY(-1px)}
    #xboard-whitelist-launcher{position:fixed;right:22px;bottom:74px;z-index:2147483647;background:#1d4ed8;color:#fff;border:1px solid #93c5fd;border-radius:999px;padding:12px 18px;font:600 14px system-ui,sans-serif;text-decoration:none;box-shadow:0 8px 24px #0006;cursor:pointer}
    #xboard-whitelist-launcher:hover{background:#1e40af;transform:translateY(-1px)}
    #xboard-whitelist-modal{position:fixed;inset:0;z-index:2147483647;background:#0007;display:none;align-items:center;justify-content:center}
    #xboard-whitelist-card{width:min(760px,94vw);max-height:86vh;overflow:auto;background:#fff;color:#0f172a;border-radius:16px;padding:20px;box-shadow:0 24px 80px #0008;font:14px system-ui,sans-serif}
    #xboard-whitelist-card textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px;font:13px ui-monospace,monospace}
    #xboard-whitelist-card button{border:1px solid #cbd5e1;border-radius:9px;padding:8px 12px;background:#fff;cursor:pointer;margin-right:8px}
    #xboard-whitelist-card button.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
    #xboard-whitelist-card button.danger{background:#dc2626;color:#fff;border-color:#dc2626}
    #xboard-whitelist-card .muted{color:#64748b;font-size:12px}
    #xboard-whitelist-card .grid{display:grid;grid-template-columns:150px 1fr;gap:8px 12px;margin:12px 0}
  </style>

  <script>
    (function(){
      const apiBase=()=>'/' + ((window.settings&&window.settings.secure_path)||'').replace(/^\/+|\/+$/g,'') + '/api/v2/admin';
      async function post(path, body){
        const r=await fetch(apiBase()+path,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify(body||{})});
        const j=await r.json().catch(()=>({}));
        if(!r.ok || j.error || j.status==='fail' || j.data===false) throw new Error(j.message||j.error||('HTTP '+r.status));
        return j.data!==undefined?j.data:j;
      }
      async function get(path){
        const r=await fetch(apiBase()+path,{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        if(!r.ok || j.error || j.status==='fail' || j.data===false) throw new Error(j.message||j.error||('HTTP '+r.status));
        return j.data!==undefined?j.data:j;
      }
      function toast(msg, bad){
        let el=document.getElementById('oc-user-whitelist-toast');
        if(!el){el=document.createElement('div');el.id='oc-user-whitelist-toast';el.style.cssText='position:fixed;right:24px;top:24px;z-index:2147483647;padding:10px 14px;border-radius:10px;background:#111827;color:white;font:13px system-ui;box-shadow:0 8px 26px #0004';document.body.appendChild(el)}
        el.textContent=msg;el.style.background=bad?'#b91c1c':'#111827';clearTimeout(el._t);el._t=setTimeout(()=>el.remove(),2600);
      }
      function onUserManage(){return location.hash.includes('/user/manage')||location.pathname.includes('/user/manage')}
      function uidFromRow(row){
        const text=row.innerText||'';
        const m=text.match(/(?:^|\s|#)(\d{1,10})(?:\s|$)/);
        return m?parseInt(m[1],10):null;
      }
      async function enhance(){
        if(!onUserManage())return;
        document.querySelectorAll('table tbody tr').forEach(row=>{
          if(row.dataset.ocWhitelist)return;
          const id=uidFromRow(row); if(!id)return;
          row.dataset.ocWhitelist='1';
          const td=document.createElement('td');
          td.style.cssText='white-space:nowrap;padding:4px 6px';
          const btn=document.createElement('button');
          btn.type='button';btn.textContent='白名单';
          btn.style.cssText='border:1px solid #38bdf8;border-radius:7px;padding:4px 8px;background:#ecfeff;color:#0369a1;font-size:12px;cursor:pointer';
          btn.onclick=async()=>{
            const enabled=btn.dataset.enabled!=='1';
            const note=prompt((enabled?'加入':'移出')+'主用户白名单：可填写备注', btn.dataset.note||'')||'';
            try{await post('/user/whitelist/update',{id,enabled,note});btn.dataset.enabled=enabled?'1':'0';btn.textContent=enabled?'白名单✓':'白名单';btn.style.background=enabled?'#dcfce7':'#ecfeff';btn.style.color=enabled?'#166534':'#0369a1';btn.dataset.note=note;toast('主用户白名单已更新')}catch(e){toast(e.message,true)}
          };
          const log=document.createElement('button');
          log.type='button';log.textContent='记录';
          log.style.cssText='margin-left:4px;border:1px solid #cbd5e1;border-radius:7px;padding:4px 8px;background:white;color:#334155;font-size:12px;cursor:pointer';
          log.onclick=async()=>{try{const rows=await get('/user/whitelist/logs?user_id='+id+'&limit=20');alert((rows||[]).map(x=>`#${x.id} ${x.created_at||''} ${x.action} ${x.reason||''} ${x.ip||''}`).join('\n')||'暂无记录')}catch(e){toast(e.message,true)}};
          td.append(btn,log); row.appendChild(td);
        });
      }
      const mo=new MutationObserver(()=>enhance());
      window.addEventListener('load',()=>{mo.observe(document.body,{childList:true,subtree:true});setInterval(enhance,1500);enhance()});
    })();
  </script>


  <script>
    (function(){
      const xboardPanelApiBase=()=>'/api/v2/' + ((window.settings&&window.settings.secure_path)||'').replace(/^\/+|\/+$/g,'');
      function xboardAccessToken(){
        const keys=['XBOARD_ACCESS_TOKEN','Xboard_access_token','access_token','ACCESS_TOKEN'];
        for(const key of keys){
          try{
            const raw=localStorage.getItem(key);
            if(!raw)continue;
            const parsed=JSON.parse(raw);
            if(parsed && parsed.value)return String(parsed.value);
            if(typeof parsed==='string')return parsed;
            return String(raw).replace(/^"|"$/g,'');
          }catch(e){
            const raw=localStorage.getItem(key);
            if(raw)return String(raw).replace(/^"|"$/g,'');
          }
        }
        return '';
      }
      async function api(path, opts){
        const url=xboardPanelApiBase()+path;
        const headers={'Content-Type':'application/json','Accept':'application/json'};
        const token=xboardAccessToken();
        if(token) headers.Authorization=token;
        else throw new Error('未找到后台登录令牌：请重新登录 XBoard 后台后再保存');
        const r=await fetch(url,{credentials:'include',headers,...(opts||{})});
        const text=await r.text();let j={};try{j=text?JSON.parse(text):{}}catch(e){}
        const okJson = !j || j.status === 'success' || j.code === 0 || j.data !== false;
        if(!r.ok || !okJson || j.error){
          const detail=(j.message||j.error||text||'请求失败');
          throw new Error('HTTP '+r.status+' '+url+'：'+String(detail).slice(0,300));
        }
        return j.data!==undefined?j.data:j;
      }
      function esc(v){return String(v??'').replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));}
      function shortUa(v){v=String(v||'');return v.length>90?v.slice(0,90)+'…':v;}
      function renderLogs(rows){
        const body=document.getElementById('xw-log-body'); if(!body)return;
        rows=Array.isArray(rows)?rows:[];
        if(!rows.length){body.innerHTML='<tr><td colspan="5" style="padding:10px;color:#64748b;text-align:center">暂无拦截记录</td></tr>';return;}
        body.innerHTML=rows.map(x=>`<tr><td>${esc(x.created_at||'')}</td><td><b>${esc(x.ip||'')}</b></td><td>${esc(x.path||'')}</td><td>${esc(x.user_id||'-')}</td><td title="${esc(x.user_agent||'')}">${esc(shortUa(x.user_agent||''))}</td></tr>`).join('');
      }
      async function loadLogs(){
        const msg=document.getElementById('xw-log-msg'); if(msg)msg.textContent='读取记录中...';
        try{const rows=await api('/panel-whitelist/logs?limit=100');renderLogs(rows);if(msg)msg.textContent='';}
        catch(e){if(msg)msg.textContent='记录读取失败：'+e.message;}
      }
      async function clearLogs(){
        if(!confirm('确认清空 XBoard 主面板白名单拦截记录？只清 reason=xboard_panel_whitelist 的记录。'))return;
        const msg=document.getElementById('xw-log-msg'); if(msg)msg.textContent='清空中...';
        try{const r=await api('/panel-whitelist/logs/clear',{method:'POST',body:'{}'});if(msg)msg.textContent='已清空 '+(r.deleted||0)+' 条';await loadLogs();}
        catch(e){if(msg)msg.textContent='清空失败：'+e.message;}
      }
      function ensureModal(){
        let m=document.getElementById('xboard-whitelist-modal');
        if(m)return m;
        m=document.createElement('div');m.id='xboard-whitelist-modal';
        m.innerHTML=`<div id="xboard-whitelist-card"><h2 style="margin:0 0 8px">XBoard 主面板 IP 白名单</h2><div class="muted">保护当前后台地址：/${(window.settings&&window.settings.secure_path)||''}#/ 以及对应后台 API。先确认“实际识别 IP”在名单内，再开启。</div><div class="grid"><b>实际识别 IP</b><span id="xw-ip">-</span><b>REMOTE_ADDR</b><span id="xw-remote">-</span><b>X-Forwarded-For</b><span id="xw-xff">-</span><b>X-Real-IP</b><span id="xw-real">-</span><b>CF-Connecting-IP</b><span id="xw-cf">-</span><b>当前命中</b><span id="xw-match">-</span></div><label><input id="xw-enabled" type="checkbox"> 开启 XBoard 主面板白名单拦截</label><p class="muted">白名单：每行一个 IP 或 CIDR。例如使用 VPN 时，请确保“实际识别 IP”也在名单内。</p><textarea id="xw-list" rows="7" placeholder="203.0.113.10\n198.51.100.20"></textarea><p class="muted">纪律/备注</p><textarea id="xw-policy" rows="4"></textarea><div style="margin-top:14px"><button class="primary" id="xw-save">保存</button><button id="xw-refresh">刷新检测</button><button id="xw-close">关闭</button></div><div id="xw-msg" class="muted" style="margin-top:10px"></div><hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0"><div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><h3 style="margin:0;font-size:16px">白名单拦截记录</h3><button id="xw-log-refresh" type="button">刷新记录</button><button id="xw-log-clear" type="button" style="color:#dc2626">清空记录</button><span id="xw-log-msg" class="muted"></span></div><div style="max-height:260px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px"><table style="width:100%;border-collapse:collapse;font-size:12px"><thead style="position:sticky;top:0;background:#f8fafc"><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0">时间</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0">IP</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0">路径</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0">用户ID</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0">UA</th></tr></thead><tbody id="xw-log-body"><tr><td colspan="5" style="padding:10px;color:#64748b;text-align:center">未加载</td></tr></tbody></table></div></div>`;
        document.body.appendChild(m);
        m.querySelector('#xw-close').onclick=()=>m.style.display='none';
        m.querySelector('#xw-refresh').onclick=()=>load();
        m.querySelector('#xw-save').onclick=save;
        m.querySelector('#xw-log-refresh').onclick=loadLogs;
        m.querySelector('#xw-log-clear').onclick=clearLogs;
        return m;
      }
      async function load(){
        const m=ensureModal(), msg=m.querySelector('#xw-msg'); msg.textContent='读取中...';
        try{const d=await api('/panel-whitelist');
          m.querySelector('#xw-enabled').checked=!!d.enabled;
          m.querySelector('#xw-list').value=d.whitelist||'';
          m.querySelector('#xw-policy').value=d.block_policy||'';
          m.querySelector('#xw-ip').textContent=d.request_ip||'-';
          m.querySelector('#xw-remote').textContent=d.remote_addr||'-';
          m.querySelector('#xw-xff').textContent=d.x_forwarded_for||'-';
          m.querySelector('#xw-real').textContent=d.x_real_ip||'-';
          m.querySelector('#xw-cf').textContent=d.cf_connecting_ip||'-';
          m.querySelector('#xw-match').textContent=d.matched?'命中/允许':'未命中/会拦截';
          m.querySelector('#xw-match').style.color=d.matched?'#16a34a':'#dc2626';
          msg.textContent=''; await loadLogs();
        }catch(e){msg.textContent='读取失败：'+e.message;}
      }
      async function save(){
        const m=ensureModal(), msg=m.querySelector('#xw-msg');
        const body={enabled:m.querySelector('#xw-enabled').checked,whitelist:m.querySelector('#xw-list').value,block_policy:m.querySelector('#xw-policy').value};
        if(body.enabled && !confirm('确认开启 XBoard 主面板白名单？如果实际识别 IP 不在名单内，会被锁在后台外。')) return;
        msg.textContent='保存中...';
        try{await api('/panel-whitelist',{method:'POST',body:JSON.stringify(body)});msg.textContent='已保存';await load();}
        catch(e){msg.textContent='保存失败：'+e.message;}
      }
      window.ocOpenXboardWhitelist=function(){const m=ensureModal();m.style.display='flex';load();};
    })();
  </script>


</head>

<body>
  <div id="root"></div>
  <button id="xboard-whitelist-launcher" onclick="ocOpenXboardWhitelist()" aria-label="XBoard 主面板白名单">主面板白名单</button>
  <a id="flyboard-launcher" href="/flyboard" aria-label="打开 FlyBoard">FlyBoard 面板</a>
</body>

</html>
