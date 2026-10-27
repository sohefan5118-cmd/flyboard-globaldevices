<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/** Independent FlyBoard client accounts. Never reads/writes v2_user or v2_server. */
class FlyboardClientService
{
    public function create(array $data): int
    {
        return (int) DB::table('flyboard_clients')->insertGetId([
            'name' => trim((string) ($data['name'] ?? '客户端')) ?: '客户端',
            'token' => $data['token'] ?? Str::random(48),
            'enabled' => (int) ($data['enabled'] ?? 1),
            'expires_at' => $data['expires_at'] ?: null,
            'traffic_limit' => (int) ($data['traffic_limit'] ?? 0),
            'traffic_used' => (int) ($data['traffic_used'] ?? 0),
            'device_limit' => max(1, (int) ($data['device_limit'] ?? 1)),
            'device_window' => max(60, (int) ($data['device_window'] ?? 300)),
            'note' => $data['note'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function subscriptionUrl(object $client): string
    {
        $base = rtrim((string) admin_setting('app_url', request()->getSchemeAndHttpHost()), '/');
        return $base . '/flyboard/sub/' . rawurlencode($client->token);
    }

    public function authorize(object $client, Request $request): array
    {
        if (!(int) $client->enabled) return [false, '客户端已停用'];
        if ($client->expires_at && strtotime($client->expires_at) <= time()) return [false, '客户端已到期'];
        if ((int) $client->traffic_limit > 0 && (int) $client->traffic_used >= (int) $client->traffic_limit) return [false, '客户端流量额度已用尽'];

        $fingerprint = app(FlyboardService::class)->fingerprint($request);
        $key = 'flyboard:client:' . $client->id;
        try {
            $now = time();
            $lockKey = $key . ':lock';
            if (!Redis::set($lockKey, (string) $now, 'NX', 'EX', 3)) return [false, '请稍后重试'];
            try {
                $members = Redis::hgetall($key);
                foreach ($members as $member => $seenAt) {
                    if ($now - (int) $seenAt > max(60, (int) $client->device_window)) Redis::hdel($key, $member);
                }
                $members = Redis::hgetall($key);
                if (!array_key_exists($fingerprint, $members) && count($members) >= max(1, (int) $client->device_limit)) return [false, '客户端在线设备数已达上限'];
                Redis::hset($key, $fingerprint, $now);
                Redis::expire($key, max(60, (int) $client->device_window));
            } finally { Redis::del($lockKey); }
        } catch (\Throwable $e) {
            Log::error('FlyBoard client device check failed', ['error' => $e->getMessage()]);
            return [false, '设备限制服务不可用'];
        }
        DB::table('flyboard_clients')->where('id', $client->id)->update([
            'access_count' => DB::raw('access_count + 1'), 'last_access_at' => now(), 'updated_at' => now(),
        ]);
        return [true, null];
    }

    public function nodesForClient(object $client): array
    {
        return DB::table('flyboard_upstream_nodes')->where('enabled', 1)->orderBy('id')->get()->all();
    }

    public function body(array $nodes, string $format = 'base64'): string
    {
        $uris = [];
        foreach ($nodes as $node) {
            $config = json_decode((string) $node->config, true) ?: [];
            if (!empty($config['uri'])) $uris[] = trim((string) $config['uri']);
        }
        $text = implode("\r\n", array_filter($uris));
        if ($format === 'raw' || $format === 'clash') return $text . ($text !== '' ? "\r\n" : '');
        return base64_encode($text . ($text !== '' ? "\r\n" : ''));
    }

    public function log(object $client, Request $request, string $action, ?string $reason = null): void
    {
        try { DB::table('flyboard_access_logs')->insert([
            'user_id' => null, 'client_id' => $client->id, 'ip' => substr((string) $request->ip(), 0, 64),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'fingerprint' => app(FlyboardService::class)->fingerprint($request), 'action' => $action,
            'reason' => $reason, 'path' => substr((string) $request->path(), 0, 255), 'created_at' => now(),
        ]); } catch (\Throwable $e) { Log::warning('FlyBoard client log failed', ['error' => $e->getMessage()]); }
    }
}
