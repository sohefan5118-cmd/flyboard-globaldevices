<?php

namespace App\Services\Flyboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use App\Services\Flyboard\UpstreamSubscribeService;

class NodeAuthAliasService
{
    private const DEVICE_TTL = 2592000; // 30 days

    public function enabled(): bool
    {
        return (int) admin_setting('subscribe_guard_enable', 1) === 1;
    }

    public function activeForUser(mixed $user): bool
    {
        return $this->enabled() && $this->deviceLimit($user) > 0;
    }

    public function deviceLimit(mixed $user): int
    {
        $limit = (int) data_get($user, 'device_limit', 0);
        if ($limit <= 0) {
            return 0;
        }

        $guardLimit = max(1, min((int) admin_setting('subscribe_guard_max_ips', 3), 255));
        return min($limit, $guardLimit);
    }

    public function authAliases(mixed $user): array
    {
        $limit = $this->deviceLimit($user);
        if (!$this->enabled() || $limit <= 1) {
            return [];
        }

        $aliases = [];
        for ($slot = 2; $slot <= $limit; $slot++) {
            $uuid = $this->slotUuid($user, $slot);
            if ($uuid !== '') {
                $aliases[] = $uuid;
            }
        }
        return $aliases;
    }

    public function userForNode(mixed $user): mixed
    {
        if (!$this->activeForUser($user)) {
            return $user;
        }

        $copy = $this->copyUser($user);
        $uuid = $this->slotUuid($user, 1);
        if ($uuid !== '') {
            data_set($copy, 'uuid', $uuid);
            data_set($copy, 'auth_aliases', $this->authAliases($user));
            data_set($copy, 'device_limit', $this->deviceLimit($user));
        }
        return $copy;
    }

    /**
     * Pick a stable device credential for this subscription client. The node
     * then treats the credential itself as the device id, so two different
     * issued credentials count as two devices even behind the same NAT/IP.
     *
     * Returns null when all device slots are already assigned to other clients.
     */
    public function userForSubscribe(mixed $user, ?Request $request = null): mixed
    {
        if (!$this->activeForUser($user)) {
            return $user;
        }

        $slot = $this->slotForRequest($user, $request);
        if ($slot <= 0) {
            return null;
        }

        $copy = $this->copyUser($user);
        data_set($copy, 'uuid', $this->slotUuid($user, $slot));
        data_set($copy, 'device_limit', $this->deviceLimit($user));
        data_set($copy, 'device_slot', $slot);
        return $copy;
    }

    public function serverPassword(mixed $server, mixed $user): string
    {
        // Independent upstream users are lightweight objects/arrays, not v2_user models.
        // For non-main users, keep subscription credentials and node auth credentials identical
        // without touching the main user table.
        if (!($user instanceof \App\Models\User)) {
            return (string) data_get($user, 'uuid', '');
        }

        return $server->generateServerPassword($user);
    }

    public function slotUuid(mixed $user, int $slot): string
    {
        $userId = (string) data_get($user, 'id', '');
        $uuid = (string) data_get($user, 'uuid', '');
        if ($userId === '' || $uuid === '' || $slot <= 0) {
            return '';
        }

        $raw = hash_hmac('sha256', implode('|', ['flyboard-device-auth-v1', $userId, $uuid, $slot]), $this->secret(), true);
        $bytes = array_values(unpack('C16', substr($raw, 0, 16)));
        $bytes[6] = ($bytes[6] & 0x0f) | 0x40;
        $bytes[8] = ($bytes[8] & 0x3f) | 0x80;
        $hex = implode('', array_map(fn ($byte) => sprintf('%02x', $byte), $bytes));

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function slotForRequest(mixed $user, ?Request $request): int
    {
        $limit = $this->deviceLimit($user);
        if ($limit <= 0) {
            return 0;
        }

        $userId = (int) data_get($user, 'id');
        $fingerprint = app(UpstreamSubscribeService::class)->isNodeUserId($userId)
            ? hash('sha256', (string) ($request?->ip() ?: '0.0.0.0'))
            : $this->fingerprint($request);
        $mapKey = $this->deviceMapKey($userId);
        $existing = (int) Redis::hget($mapKey, $fingerprint);
        if ($existing >= 1 && $existing <= $limit) {
            Redis::expire($mapKey, self::DEVICE_TTL);
            return $existing;
        }

        $used = array_map('intval', array_values(Redis::hgetall($mapKey) ?: []));
        for ($slot = 1; $slot <= $limit; $slot++) {
            if (!in_array($slot, $used, true)) {
                Redis::hset($mapKey, $fingerprint, $slot);
                Redis::expire($mapKey, self::DEVICE_TTL);
                return $slot;
            }
        }

        return 0;
    }

    private function fingerprint(?Request $request): string
    {
        if (!$request) {
            return 'no-request';
        }

        $parts = [
            $request->input('device_id', ''),
            $request->input('device', ''),
            $request->input('flag', ''),
            $request->header('User-Agent', ''),
            $request->header('X-Client-Device-Id', ''),
            $request->header('X-Device-Id', ''),
            $request->ip(),
        ];

        return hash('sha256', implode('|', array_map(fn ($v) => (string) $v, $parts)));
    }

    private function deviceMapKey(int $userId): string
    {
        return "user_device_auth_slots:{$userId}";
    }

    private function copyUser(mixed $user): mixed
    {
        if (is_object($user) && method_exists($user, 'replicate')) {
            $copy = $user->replicate();
            $copy->exists = $user->exists ?? true;
            $copy->id = $user->id;
            return $copy;
        }

        if (is_object($user)) {
            return clone $user;
        }

        if (is_array($user)) {
            return $user;
        }

        return $user;
    }

    private function secret(): string
    {
        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $appKey !== '' ? $appKey : 'flyboard-device-auth-fallback';
    }
}
