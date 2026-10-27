<?php
namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\FlyboardService;
use App\Services\FlyboardClientService;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class FlyboardController extends Controller
{
    private function guard(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $service = app(FlyboardService::class);
        if (!$service->panelAllowed($request)) {
            $service->log($request->user(), $request, $service->fingerprint($request), 'blocked', 'panel_whitelist');
            return $this->fail([403, 'FlyBoard panel access denied']);
        }
        return null;
    }

    public function settings(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        return $this->success([
            'enabled' => (bool) admin_setting(FlyboardService::SETTING_ENABLED, 0),
            'device_limit' => (int) admin_setting(FlyboardService::SETTING_LIMIT, 1),
            'window' => (int) admin_setting(FlyboardService::SETTING_WINDOW, 300),
            'panel_whitelist_enabled' => (bool) admin_setting(FlyboardService::SETTING_PANEL_WHITELIST_ENABLED, 0),
            'whitelist' => (string) admin_setting(FlyboardService::SETTING_WHITELIST, ''),
            'block_policy' => app(FlyboardService::class)->panelBlockPolicy(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate(['enabled'=>'required|boolean','device_limit'=>'required|integer|min:1|max:50','window'=>'required|integer|min:60|max:2592000','panel_whitelist_enabled'=>'nullable|boolean','whitelist'=>'nullable|string|max:10000','block_policy'=>'nullable|string|max:10000']);
        admin_setting([FlyboardService::SETTING_ENABLED=>(int)$data['enabled'],FlyboardService::SETTING_LIMIT=>(int)$data['device_limit'],FlyboardService::SETTING_WINDOW=>(int)$data['window'],FlyboardService::SETTING_PANEL_WHITELIST_ENABLED=>(int)($data['panel_whitelist_enabled']??0),FlyboardService::SETTING_WHITELIST=>trim((string)($data['whitelist']??'')),FlyboardService::SETTING_PANEL_BLOCK_POLICY=>trim((string)($data['block_policy']??''))]);
        return $this->success(true);
    }

    public function upstreams(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $users = DB::table('flyboard_upstream_users')->orderByDesc('id')->get();
        return $this->success($users);
    }

    public function nodes(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $q = DB::table('flyboard_upstream_nodes as n')->join('flyboard_upstream_users as u','u.id','=','n.upstream_user_id')->select('n.*','u.email','u.name as upstream_name');
        if ($request->filled('upstream_user_id')) $q->where('n.upstream_user_id',$request->integer('upstream_user_id'));
        return $this->success($q->orderByDesc('n.id')->get());
    }

    public function saveUpstream(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate([
            'id'=>'nullable|integer','name'=>'nullable|string|max:128','email'=>'nullable|email|max:255',
            'subscribe_url'=>'required|url|max:2000','token'=>'nullable|string|max:512','user_id'=>'nullable|string|max:128','uuid'=>'nullable|string|max:128',
            'user_agent'=>'nullable|string|max:512','enabled'=>'nullable|boolean',
        ]);
        $data['name'] = trim((string)($data['name'] ?? '')) ?: ($data['email'] ?? '上游用户');
        $data['enabled'] = $data['enabled'] ?? true;
        $id = $data['id'] ?? null; unset($data['id']);
        if ($id) DB::table('flyboard_upstream_users')->where('id',$id)->update(array_merge($data,['updated_at'=>now()]));
        else $id = DB::table('flyboard_upstream_users')->insertGetId(array_merge($data,['created_at'=>now(),'updated_at'=>now()]));
        return $this->success(['id'=>$id]);
    }

    public function dropUpstream(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $request->validate(['id'=>'required|integer']);
        DB::table('flyboard_upstream_users')->where('id',$request->integer('id'))->delete();
        return $this->success(true);
    }

    public function pullUpstream(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate(['id'=>'nullable|integer','all'=>'nullable|boolean']);
        $query = DB::table('flyboard_upstream_users')->where('enabled',1);
        if (!empty($data['id'])) $query->where('id',(int)$data['id']);
        $users = $query->get();
        if ($users->isEmpty()) return $this->fail([404,'没有启用的上游用户']);
        $results=[]; $totalNodes=0;
        foreach ($users as $user) {
            try {
                $url=$this->replacePlaceholders($user->subscribe_url,$user);
                // Use the bounded upstream fetcher instead of Laravel Http::get():
                // it allows only public IPv4 HTTP/HTTPS on ports 80/443, disables
                // redirects/proxies, pins curl to the resolved public IP, enforces
                // TLS verification, wall-clock timeout, and response size limit.
                $r=app(\App\Services\FlyboardUpstreamFetcher::class)->fetch($url,(string)$user->user_agent,30,524288);
                $meta=$this->subscriptionMeta($r['meta'] ?? null);
                $parsedNodes=$this->parseNodes((string)($r['body'] ?? ''));
                // Do not actively probe parsed node host:port pairs from upstream
                // subscriptions. Probing arbitrary addresses can become internal
                // network scanning/SSRF if an admin imports an untrusted upstream.
                $nodes=$this->filterReachableNodes($parsedNodes);
                $rejected=count($parsedNodes)-count($nodes);
                DB::transaction(function() use($user,$nodes,$meta) {
                    DB::table('flyboard_upstream_nodes')->where('upstream_user_id',$user->id)->delete();
                    foreach ($nodes as $node) DB::table('flyboard_upstream_nodes')->insert(array_merge($node,['upstream_user_id'=>$user->id,'created_at'=>now(),'updated_at'=>now()]));
                    DB::table('flyboard_upstream_users')->where('id',$user->id)->update(array_merge($meta,['last_pulled_at'=>now(),'last_error'=>null,'last_node_count'=>count($nodes),'updated_at'=>now()]));
                });
                $totalNodes+=count($nodes); $results[]=['id'=>$user->id,'name'=>$user->name,'raw_nodes'=>count($parsedNodes),'nodes'=>count($nodes),'rejected_nodes'=>$rejected,'status'=>'success','bytes'=>strlen((string)($r['body'] ?? ''))];
            } catch (\Throwable $e) {
                DB::table('flyboard_upstream_users')->where('id',$user->id)->update(['last_error'=>substr($e->getMessage(),0,1000),'updated_at'=>now()]);
                $results[]=['id'=>$user->id,'name'=>$user->name,'nodes'=>0,'status'=>'error','error'=>$e->getMessage()];
            }
        }
        return $this->success(['users'=>count($users),'nodes'=>$totalNodes,'results'=>$results]);
    }

    private function replacePlaceholders(string $url, object $u): string
    {
        return strtr($url,['{token}'=>$u->token??'','{userid}'=>$u->user_id??'','{email}'=>$u->email??'','{uuid}'=>$u->uuid??'']);
    }
    private function subscriptionMeta(?string $raw): array
    {
        $out=[]; foreach (preg_split('/[,;]+/',(string)$raw) as $part) { [$k,$v]=array_pad(explode('=',$part,2),2,null); if($k===null) continue; $k=strtolower(trim($k)); $v=(int)trim((string)$v); if($k==='upload')$out['upload']=$v; if($k==='download')$out['download']=$v; if($k==='total')$out['transfer_enable']=$v; if($k==='expire' && $v>0)$out['expired_at']=date('Y-m-d H:i:s',$v); } return $out;
    }

    /**
     * Keep parsed upstream nodes without making outbound reachability probes.
     *
     * Earlier code used stream_socket_client() against every imported node
     * address:port. That is not necessary for subscription import and can be
     * abused as internal-network probing if a malicious upstream is imported.
     */
    private function filterReachableNodes(array $nodes): array
    {
        return $nodes;
    }

    private function parseNodes(string $body): array
    {
        $text = trim($body);
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded !== false && preg_match('/(?:[a-z][a-z0-9+.-]*:\/\/)/i', $decoded)) {
            $text = $decoded;
        }

        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if (!$line || str_starts_with($line, '#')) continue;
            $p = parse_url($line);
            if (!$p || empty($p['scheme'])) continue;
            $scheme = strtolower($p['scheme']);
            if (!in_array($scheme, ['vmess', 'vless', 'trojan', 'ss', 'ssr', 'hysteria', 'hysteria2', 'tuic'], true)) continue;

            $name = !empty($p['fragment']) ? urldecode($p['fragment']) : '';
            $host = $p['host'] ?? '';
            $port = isset($p['port']) ? (int) $p['port'] : null;
            $details = ['uri' => $line];

            // vmess links contain a base64-encoded JSON object rather than a
            // normal authority component. Decode it for usable node metadata,
            // while retaining the original URI in config for round-tripping.
            if ($scheme === 'vmess') {
                $payload = substr($line, strlen('vmess://'));
                $payload = explode('#', $payload, 2)[0];
                $json = base64_decode(strtr($payload, '-_', '+/'), true);
                $vmess = $json !== false ? json_decode($json, true) : null;
                if (is_array($vmess)) {
                    $host = (string) ($vmess['add'] ?? $vmess['address'] ?? '');
                    $port = isset($vmess['port']) ? (int) $vmess['port'] : null;
                    $name = $name ?: (string) ($vmess['ps'] ?? '');
                    $details['vmess'] = $vmess;
                }
            }

            if (!$host) continue;
            $out[] = [
                'name' => mb_substr($name ?: $scheme . '://' . $host, 0, 255),
                'type' => $scheme,
                'address' => $host,
                'port' => $port,
                'config' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'enabled' => 1,
            ];
        }
        return $out;
    }


    private function ensureMainUserWhitelistSchema(): void
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('flyboard_main_user_whitelist')) {
                DB::getSchemaBuilder()->create('flyboard_main_user_whitelist', function ($table) {
                    $table->unsignedBigInteger('user_id')->primary();
                    $table->boolean('enabled')->default(false);
                    $table->string('note', 500)->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }
            if (!DB::getSchemaBuilder()->hasTable('flyboard_main_user_whitelist_logs')) {
                DB::getSchemaBuilder()->create('flyboard_main_user_whitelist_logs', function ($table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->unsignedBigInteger('admin_id')->nullable();
                    $table->string('action', 32);
                    $table->string('reason', 64)->nullable();
                    $table->string('ip', 64)->nullable();
                    $table->string('user_agent', 512)->nullable();
                    $table->string('path', 255)->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->index(['user_id', 'created_at'], 'fb_user_wl_logs_user_created_idx');
                    $table->index(['action', 'created_at'], 'fb_user_wl_logs_action_created_idx');
                });
            }
        } catch (\Throwable $e) {
            \Log::warning('FlyBoard main user whitelist schema failed', ['error' => $e->getMessage()]);
        }
    }

    private function mainUserWhitelistMap(array $userIds): array
    {
        $this->ensureMainUserWhitelistSchema();
        if (empty($userIds)) return [];
        try {
            return DB::table('flyboard_main_user_whitelist')
                ->whereIn('user_id', array_map('intval', $userIds))
                ->get()
                ->keyBy('user_id')
                ->map(fn ($row) => [
                    'enabled' => (bool) $row->enabled,
                    'note' => (string) ($row->note ?? ''),
                    'updated_at' => $row->updated_at,
                ])->all();
        } catch (\Throwable $e) {
            \Log::warning('FlyBoard main user whitelist read failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function logMainUserWhitelist(Request $request, int $userId, string $action, ?string $reason = null): void
    {
        $this->ensureMainUserWhitelistSchema();
        try {
            DB::table('flyboard_main_user_whitelist_logs')->insert([
                'user_id' => $userId,
                'admin_id' => optional($request->user())->id,
                'action' => $action,
                'reason' => $reason,
                'ip' => substr((string) $request->ip(), 0, 64),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'path' => substr((string) $request->path(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('FlyBoard main user whitelist log failed', ['error' => $e->getMessage()]);
        }
    }

    /** Build a browser-reachable subscription URL from the current panel host. */
    private function mainUserSubscribeUrl(Request $request, string $token): string
    {
        $path = route('client.subscribe', ['token' => $token], false);
        $forwardedHost = trim((string) $request->header('X-Forwarded-Host'));
        $host = $forwardedHost !== '' ? $forwardedHost : $request->getHttpHost();
        $forwardedProto = trim((string) $request->header('X-Forwarded-Proto'));
        $scheme = $forwardedProto !== '' ? strtolower(explode(',', $forwardedProto)[0]) : $request->getScheme();
        if (!in_array($scheme, ['http', 'https'], true)) $scheme = $request->getScheme();
        return $scheme . '://' . $host . $path;
    }

    /** Main users whose subscription contains the normal XBoard nodes. */
    public function mainUsers(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $q = trim((string) $request->input('q', ''));
        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $users = User::query()->select([
            'id', 'email', 'token', 'plan_id', 'device_limit', 'online_count',
            'banned', 'expired_at', 'transfer_enable', 'u', 'd',
        ])->when($q !== '', function ($query) use ($q) {
            $query->where(function ($where) use ($q) {
                if (ctype_digit($q)) $where->orWhere('id', (int) $q);
                $where->orWhere('email', 'like', '%' . addcslashes($q, '%_') . '%');
            });
        })->orderByDesc('id')->limit($limit)->get();

        $deviceService = app(DeviceStateService::class);
        $whitelist = $this->mainUserWhitelistMap($users->pluck('id')->map(fn ($id) => (int) $id)->all());
        return $this->success($users->map(function ($user) use ($deviceService, $request, $whitelist) {
            $devices = $deviceService->getUsersDevices([(int) $user->id])[(int) $user->id] ?? [];
            $servers = ServerService::getAvailableServers($user);
            $nodeNames = collect($servers)->map(function ($server) {
                return (string) ($server->name ?? $server['name'] ?? '');
            })->filter()->values()->all();
            return [
                'id' => (int) $user->id,
                'email' => $user->email,
                'plan_id' => $user->plan_id,
                'device_limit' => $user->device_limit === null ? null : (int) $user->device_limit,
                'online_count' => count($devices),
                'devices' => $devices,
                'banned' => (bool) $user->banned,
                'expired_at' => $user->expired_at,
                'subscribe_url' => $this->mainUserSubscribeUrl($request, $user->token),
                'node_count' => count($nodeNames),
                'node_names' => $nodeNames,
                'main_user_whitelist' => $whitelist[(int) $user->id] ?? ['enabled' => false, 'note' => '', 'updated_at' => null],
            ];
        }));
    }

    /** Enable/disable a main user whitelist entry from FlyBoard. */
    public function saveMainUserWhitelist(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);
        $user = User::find((int) $data['id']);
        if (!$user) return $this->fail([404, '主用户不存在']);
        $this->ensureMainUserWhitelistSchema();
        $enabled = $request->boolean('enabled');
        DB::table('flyboard_main_user_whitelist')->updateOrInsert(
            ['user_id' => (int) $user->id],
            ['enabled' => $enabled ? 1 : 0, 'note' => trim((string) ($data['note'] ?? '')), 'created_at' => now(), 'updated_at' => now()]
        );
        $this->logMainUserWhitelist($request, (int) $user->id, $enabled ? 'enabled' : 'disabled', $enabled ? 'main_user_whitelist' : 'main_user_whitelist_disabled');
        return $this->success(['id' => (int) $user->id, 'enabled' => $enabled]);
    }

    /** Recent whitelist operation records for FlyBoard main users. */
    public function mainUserWhitelistLogs(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $this->ensureMainUserWhitelistSchema();
        $limit = min(200, max(1, (int) $request->input('limit', 50)));
        $q = DB::table('flyboard_main_user_whitelist_logs as l')
            ->leftJoin('v2_user as u', 'u.id', '=', 'l.user_id')
            ->select('l.*', 'u.email')
            ->latest('l.id')
            ->limit($limit);
        if ($request->filled('user_id')) $q->where('l.user_id', (int) $request->input('user_id'));
        return $this->success($q->get());
    }

    /** Set the cross-node limit used by every node in the user's subscription. */
    public function saveMainUser(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'device_limit' => 'required|integer|min:0|max:100',
        ]);
        $user = User::find((int) $data['id']);
        if (!$user) return $this->fail([404, '主用户不存在']);
        $user->device_limit = (int) $data['device_limit'];
        $user->save();
        return $this->success(['id' => (int) $user->id, 'device_limit' => (int) $user->device_limit]);
    }

    /** Release current global device claims; useful after a device is lost or replaced. */
    public function clearMainUserDevices(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate(['id' => 'required|integer|min:1']);
        $user = User::find((int) $data['id']);
        if (!$user) return $this->fail([404, '主用户不存在']);
        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices([(int) $user->id])[(int) $user->id] ?? [];
        $service->clearUserDevices((int) $user->id);
        return $this->success(['cleared' => count($devices)]);
    }


    public function panelWhitelistStatus(Request $request)
    {
        // XBoard main panel whitelist status must stay reachable from the XBoard admin panel itself.
        // Do not use FlyBoard's panel guard here, otherwise the user can lock themselves out of saving/fixing it.
        $service = app(FlyboardService::class);
        [$items, $ip] = $service->whitelist($request);
        return $this->success([
            'enabled' => (bool) admin_setting(FlyboardService::SETTING_PANEL_WHITELIST_ENABLED, 0),
            'whitelist' => (string) admin_setting(FlyboardService::SETTING_WHITELIST, ''),
            'block_policy' => $service->panelBlockPolicy(),
            'request_ip' => $ip,
            'remote_addr' => (string) $request->server('REMOTE_ADDR', ''),
            'x_forwarded_for' => (string) $request->header('X-Forwarded-For', ''),
            'x_real_ip' => (string) $request->header('X-Real-IP', ''),
            'cf_connecting_ip' => (string) $request->header('CF-Connecting-IP', ''),
            'matched' => empty($items) || $service->panelIpAllowed($request),
            'items' => $items,
        ]);
    }

    public function savePanelWhitelist(Request $request)
    {
        // Same as status: protected by normal XBoard admin auth middleware only.
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'whitelist' => 'nullable|string|max:10000',
            'block_policy' => 'nullable|string|max:10000',
        ]);
        admin_setting([
            FlyboardService::SETTING_PANEL_WHITELIST_ENABLED => (int) $data['enabled'],
            FlyboardService::SETTING_WHITELIST => trim((string) ($data['whitelist'] ?? '')),
            FlyboardService::SETTING_PANEL_BLOCK_POLICY => trim((string) ($data['block_policy'] ?? '')),
            'flyboard_panel_whitelist_enabled' => 0,
        ]);
        return $this->panelWhitelistStatus($request);
    }


    public function panelWhitelistLogs(Request $request)
    {
        // XBoard main panel whitelist block records. Protected by normal admin auth + panel whitelist middleware.
        $limit = min(500, max(1, (int) $request->input('limit', 100)));
        $rows = DB::table('flyboard_access_logs')
            ->where('reason', 'xboard_panel_whitelist')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'ip', 'user_agent', 'fingerprint', 'action', 'reason', 'path', 'created_at']);
        return $this->success($rows);
    }

    public function clearPanelWhitelistLogs(Request $request)
    {
        // Only clear XBoard main panel whitelist records, not FlyBoard subscription/client logs.
        $deleted = DB::table('flyboard_access_logs')
            ->where('reason', 'xboard_panel_whitelist')
            ->delete();
        return $this->success(['deleted' => (int) $deleted]);
    }

    public function clients(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $service = app(FlyboardClientService::class);
        $rows = DB::table('flyboard_clients')->orderByDesc('id')->get();
        return $this->success($rows->map(function ($client) use ($service) {
            $client->subscription_url = $service->subscriptionUrl($client);
            $client->qr_url = $client->subscription_url . '/qr';
            return $client;
        }));
    }

    public function saveClient(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $data = $request->validate([
            'id'=>'nullable|integer', 'name'=>'required|string|max:128', 'enabled'=>'nullable|boolean',
            'expires_at'=>'nullable|date', 'traffic_limit'=>'nullable|integer|min:0',
            'traffic_used'=>'nullable|integer|min:0', 'device_limit'=>'required|integer|min:1|max:100',
            'device_window'=>'required|integer|min:60|max:2592000', 'note'=>'nullable|string|max:2000',
        ]);
        $data['enabled'] = $data['enabled'] ?? true;
        $data['traffic_limit'] = (int) ($data['traffic_limit'] ?? 0);
        $data['device_window'] = (int) ($data['device_window'] ?? 300);
        $id = $data['id'] ?? null; unset($data['id']);
        if ($id) {
            if (!DB::table('flyboard_clients')->where('id', $id)->exists()) {
                return $this->fail([404, '客户端不存在']);
            }
            // Runtime accounting must not be reset by the edit form.
            unset($data['traffic_used']);
            DB::table('flyboard_clients')->where('id', $id)->update(array_merge($data, ['updated_at'=>now()]));
        } else {
            $data['token'] = Str::random(48);
            $data['traffic_used'] = (int) ($data['traffic_used'] ?? 0);
            $data['created_at'] = now(); $data['updated_at'] = now();
            $id = DB::table('flyboard_clients')->insertGetId($data);
        }
        return $this->success(['id'=>(int)$id]);
    }

    public function dropClient(Request $request)
    {
        if ($response = $this->guard($request)) return $response;
        $request->validate(['id'=>'required|integer']);
        DB::table('flyboard_clients')->where('id', $request->integer('id'))->delete();
        Redis::del('flyboard:client:' . $request->integer('id'));
        return $this->success(true);
    }

    public function logs(Request $request)
    {
        if($response=$this->guard($request))return $response;
        $q = DB::table('flyboard_access_logs as l')->leftJoin('flyboard_clients as c','c.id','=','l.client_id')
            ->select('l.*','c.name as client_name');
        return $this->success($q->latest('l.id')->limit(min(500,max(1,(int)$request->input('limit',100))))->get());
    }

    public function dropLogs(Request $request)
    {
        if($response=$this->guard($request))return $response;
        $data = $request->validate(['ids'=>'required|array|min:1','ids.*'=>'required|integer|min:1']);
        $deleted = DB::table('flyboard_access_logs')->whereIn('id', array_map('intval', $data['ids']))->delete();
        return $this->success(['deleted'=>(int)$deleted]);
    }

    public function clearLogs(Request $request)
    {
        if($response=$this->guard($request))return $response;
        $deleted = DB::table('flyboard_access_logs')->delete();
        return $this->success(['deleted'=>(int)$deleted]);
    }
}
