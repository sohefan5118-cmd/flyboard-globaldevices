<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/** FlyBoard-only policy and upstream storage helpers. Never writes v2_server. */
class FlyboardService
{
    public const SETTING_ENABLED = 'flyboard_antishare_enable';
    public const SETTING_LIMIT = 'flyboard_antishare_device_limit';
    public const SETTING_WINDOW = 'flyboard_antishare_window';
    public const SETTING_WHITELIST = 'xboard_panel_whitelist';
    public const SETTING_PANEL_WHITELIST_ENABLED = 'xboard_panel_whitelist_enabled';
    public const SETTING_PANEL_BLOCK_POLICY = 'xboard_panel_block_policy';

    public function enabled(): bool
    {
        return (bool) admin_setting(self::SETTING_ENABLED, 0);
    }

    /**
     * Strict subscription-owner fingerprint.
     *
     * User-Agent alone is shared by different phones running the same client
     * build. Bind ownership to canonical IP + UA so such a second phone cannot
     * inherit the first phone's permanent owner record and receive nodes.
     */
    public function fingerprint(Request $request): string
    {
        $ua = trim(substr((string) $request->userAgent(), 0, 255));
        $ip = $this->canonicalIp((string) $request->ip());
        return hash('sha256', 'v2|ip:' . $ip . '|ua:' . $ua);
    }

    /** Previous UA-first fingerprint, used only for one-time owner migration. */
    private function legacyFingerprint(Request $request): string
    {
        $ua = trim(substr((string) $request->userAgent(), 0, 255));
        if ($ua !== '') {
            return hash('sha256', 'ua:' . $ua);
        }
        return hash('sha256', 'ip:' . (string) $request->ip());
    }

    private function canonicalIp(string $ip): string
    {
        $ip = trim(preg_replace('/%.+$/', '', $ip));
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid';
        }

        // Collapse IPv4-mapped IPv6 to the same canonical IPv4 identity.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return strtolower((string) inet_ntop($packed));
    }

    private function subscriptionIdentity(User $user): string
    {
        $token = trim((string) ($user->token ?? ''));
        if ($token !== '') {
            return hash('sha256', 'token:' . $token);
        }
        return 'user:' . (int) $user->id;
    }

    /**
     * Return false when this token has exceeded its configured subscription client count.
     *
     * $claimOwner=true is for real subscription links (/s/{token}); it permanently
     * binds the first successful subscription client fingerprint, so a second phone
     * cannot wait for a Redis TTL and later receive nodes.
     *
     * $claimOwner=false is for logged-in node-list/config endpoints. Those endpoints
     * must not steal the owner before the phone's first subscription pull. If an
     * owner already exists they are still checked and blocked when different.
     */
    public function allowSubscription(User $user, Request $request, bool $claimOwner = true): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $fingerprint = $this->fingerprint($request);
        $limit = max(1, (int) admin_setting(self::SETTING_LIMIT, 1));
        $identity = $this->subscriptionIdentity($user);
        $ownersKey = 'flyboard:sub:owners:' . $identity;
        $seenKey = 'flyboard:sub:seen:' . $identity;
        $now = time();
        $allowed = false;
        $reason = null;

        try {
            $lockKey = 'flyboard:lock:' . $user->id;
            $lock = false;
            for ($i = 0; $i < 5; $i++) {
                if (Redis::setnx($lockKey, (string) $now)) {
                    Redis::expire($lockKey, 3);
                    $lock = true;
                    break;
                }
                usleep(100000);
            }
            if (!$lock) {
                $this->log($user, $request, $fingerprint, 'blocked', 'concurrent_check');
                return false;
            }

            try {
                $owners = Redis::hgetall($ownersKey);
                asort($owners, SORT_NUMERIC);

                // A lower configured limit takes effect immediately even when
                // older permanent records exceed it. Only the earliest N owners
                // are effective; later historical owners cannot receive nodes.
                $effectiveOwners = array_slice(array_keys($owners), 0, $limit);
                $known = in_array($fingerprint, $effectiveOwners, true);

                // Convert an effective legacy UA-only owner on its first request.
                // The Redis lock makes this migration atomic: the first IP wins,
                // and another phone with the same UA is rejected afterwards.
                $legacyFingerprint = $this->legacyFingerprint($request);
                if (!$known && in_array($legacyFingerprint, $effectiveOwners, true)) {
                    $claimedAt = (int) ($owners[$legacyFingerprint] ?? $now);
                    Redis::hdel($ownersKey, $legacyFingerprint);
                    Redis::hset($ownersKey, $fingerprint, $claimedAt);
                    unset($owners[$legacyFingerprint]);
                    $owners[$fingerprint] = $claimedAt;
                    $known = true;
                }

                if ($known) {
                    $allowed = true;
                } elseif ($claimOwner && count($owners) < $limit) {
                    // First real subscription pull claims immediately and permanently.
                    Redis::hset($ownersKey, $fingerprint, $now);
                    $allowed = true;
                } elseif (!$claimOwner && empty($owners)) {
                    // Do not let web/app node-list requests steal first ownership.
                    // Allow this read when there is no owner yet, but do not bind it.
                    $allowed = true;
                    $reason = 'non_claiming_pre_owner_read';
                } else {
                    $allowed = false;
                    $reason = 'client_limit';
                }

                if ($allowed) {
                    Redis::hset($seenKey, $fingerprint, $now);
                }
            } finally {
                Redis::del($lockKey);
            }
        } catch (\Throwable $e) {
            // Fail closed only when the feature is explicitly enabled.
            Log::error('FlyBoard anti-share storage failure', ['error' => $e->getMessage()]);
            $allowed = false;
            $reason = 'storage_failure';
        }

        $this->log($user, $request, $fingerprint, $allowed ? 'allowed' : 'blocked', $allowed ? $reason : ($reason ?: 'client_limit'));
        return $allowed;
    }

    private function clearNodeDeviceSlotsForReleasedSubscription(User $user): void
    {
        try {
            $deviceService = app(DeviceStateService::class);
            $cleared = $deviceService->clearUserDevices((int) $user->id);
            if ($cleared <= 0) {
                return;
            }

            foreach (Server::query()->where('enabled', 1)->where('show', 1)->get() as $node) {
                $users = ServerService::getAvailableUsers($node);
                $devices = $deviceService->getUsersDevices($users->pluck('id')->toArray());
                NodeSyncService::push((int) $node->id, 'sync.devices', ['users' => $devices]);
            }

            Log::info('FlyBoard subscription window released node device slots', [
                'user_id' => (int) $user->id,
                'cleared' => $cleared,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FlyBoard subscription/device sync failed', [
                'user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function log(?User $user, Request $request, string $fingerprint, string $action, ?string $reason = null): void
    {
        try {
            DB::table('flyboard_access_logs')->insert([
                'user_id' => $user?->id,
                'ip' => substr((string) $request->ip(), 0, 64),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'fingerprint' => $fingerprint,
                'action' => $action,
                'reason' => $reason,
                'path' => substr((string) $request->path(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FlyBoard access log failed', ['error' => $e->getMessage()]);
        }
    }


    public function whitelist(Request $request): array
    {
        $raw = (string) admin_setting(self::SETTING_WHITELIST, '');
        $items = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw))));
        $ip = (string) $request->ip();
        return [$items, $ip];
    }

    public function panelIpAllowed(Request $request): bool
    {
        [$items, $ip] = $this->whitelist($request);
        return empty($items) || collect($items)->contains(fn ($rule) => $this->ipMatches($ip, (string) $rule));
    }

    public function panelAllowed(Request $request): bool
    {
        if (!(bool) admin_setting(self::SETTING_PANEL_WHITELIST_ENABLED, 0)) {
            return true;
        }
        return $this->panelIpAllowed($request);
    }


    private function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') return false;
        if ($ip === $rule) return true;
        if (!str_contains($rule, '/')) return false;
        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, null);
        if ($bits === null || !is_numeric($bits)) return false;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) return false;
        $bits = max(0, min((int) $bits, strlen($ipBin) * 8));
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) return false;
        if ($remainder === 0) return true;
        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** Human-readable admin panel block discipline shown in FlyBoard. */
    public function panelBlockPolicy(): string
    {
        $policy = trim((string) admin_setting(self::SETTING_PANEL_BLOCK_POLICY, ''));
        return $policy !== '' ? $policy : implode("\n", [
            '1. XBoard 面板白名单保护整个管理后台入口和后台 API，不参与订阅/节点设备限制。',
            '2. 白名单开关关闭时不限制后台面板访问；开启且名单非空时只有命中的来源 IP/CIDR 可访问 XBoard 后台。',
            '3. 未命中白名单的后台请求立即返回 403，并写入拦截日志 reason=xboard_panel_whitelist。',
            '4. 不在面板白名单里加入用户、节点或代理出口 IP，避免误以为会放行订阅设备限制。',
            '5. 修改白名单前先确认当前公网出口 IP，避免把自己锁在整个后台外。',
        ]);
    }
}
