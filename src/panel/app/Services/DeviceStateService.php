<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Flyboard\UpstreamSubscribeService;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const REPORTED_PREFIX = 'user_reported_devices:';
    private const TTL = 300;                     // device state ttl
    private const DB_THROTTLE = 10;             // update db throttle

    /**
     * 移除 Redis key 的前缀
     */
    private function removeRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix ? substr($key, strlen($prefix)) : $key;
    }

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $lockKey = "device:set_lock:{$userId}";
        $locked = false;

        try {
            $locked = Redis::set($lockKey, (string) $nodeId, 'NX', 'EX', 8) === true;
            if (!$locked) {
                Log::debug('[DeviceLimit] skipped concurrent device update', [
                    'user_id' => $userId,
                    'node_id' => $nodeId,
                ]);
                return;
            }

            $key = self::PREFIX . $userId;
            $timestamp = time();

            // Capture already-allowed devices before removing this node's
            // previous fields. This makes the limit first-come-first-served:
            // if panel limit is 1 and the first device is already allowed,
            // a later second device cannot replace it just because it appears
            // in a newer node report.
            $preferredAllowedIps = $this->getAllowedDevices($userId);

            $this->removeNodeDevices($nodeId, $userId);

            // Normalize: strip port suffix and deduplicate, then strictly apply device_limit.
            $reportedIps = array_values(array_unique(array_map([self::class, 'normalizeIP'], $ips)));
            $this->removeNodeReportedDevices($nodeId, $userId);
            if (!empty($reportedIps)) {
                $reportedFields = [];
                foreach ($reportedIps as $ip) {
                    $reportedFields["{$nodeId}:{$ip}"] = $timestamp;
                }
                Redis::hMset(self::REPORTED_PREFIX . $userId, $reportedFields);
                Redis::expire(self::REPORTED_PREFIX . $userId, self::TTL);
            }
            $ips = $this->limitDevices($userId, $reportedIps, null, $preferredAllowedIps);

            if (!empty($ips)) {
                $fields = [];
                foreach ($ips as $ip) {
                    $fields["{$nodeId}:{$ip}"] = $timestamp;
                }
                Redis::hMset($key, $fields);
                Redis::expire($key, self::TTL);
            }

            // If this node reported extra devices, keep existing global winners
            // stable and push the authoritative global allowlist to every online
            // node serving this user. Nodes close/reject only devices outside the
            // allowlist; never remove/add the whole user across all nodes.
            if (count($reportedIps) > count($ips)) {
                Log::info('[DeviceLimit] trimmed reported devices', [
                    'user_id' => $userId,
                    'node_id' => $nodeId,
                    'reported_devices' => $reportedIps,
                    'allowed_on_reporting_node' => $ips,
                    'global_allowed_devices' => $this->getAllowedDevices($userId),
                ]);
                $this->queueDevicePushForUser($userId);
            }

            $this->notifyUpdate($userId);
        } finally {
            if ($locked) {
                Redis::del($lockKey);
            }
        }
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getNodeDevices(int $nodeId): array
    {
        $keys = Redis::keys(self::PREFIX . '*');
        $prefix = "{$nodeId}:";
        $result = [];
        foreach ($keys as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $uid = (int) substr($actualKey, strlen(self::PREFIX));
            $data = Redis::hgetall($actualKey);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix)) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uid][] = $ip;
                }
            }
        }

        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";

        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }
    }

    public function removeNodeReportedDevices(int $nodeId, int $userId): void
    {
        $key = self::REPORTED_PREFIX . $userId;
        $prefix = "{$nodeId}:";
        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        $prefix = "{$nodeId}:";

        foreach ($oldDevices as $userId => $ips) {
            $key = self::PREFIX . $userId;
            foreach (Redis::hkeys($key) as $field) {
                if (str_starts_with($field, $prefix)) {
                    Redis::hdel($key, $field);
                }
            }
            $this->removeNodeReportedDevices($nodeId, $userId);
            $this->notifyUpdate($userId);
        }

        return array_keys($oldDevices);
    }

    /**
     * Synchronize the full alive device snapshot for one node.
     *
     * This replaces the node's previous per-user device state with the
     * current payload, so users that disappeared from the report are removed
     * from both the allowed-device and reported-device stores.
     */
    public function syncNodeDevices(int $nodeId, array $alive): void
    {
        $currentUsers = $this->getNodeDevices($nodeId);

        $nextUsers = [];
        foreach ($alive as $userId => $ips) {
            if (!is_numeric($userId) || !is_array($ips)) {
                continue;
            }

            $nextUsers[(int) $userId] = $ips;
        }

        $missingUserIds = array_diff(array_keys($currentUsers), array_keys($nextUsers));
        foreach ($missingUserIds as $userId) {
            $this->removeNodeDevices($nodeId, (int) $userId);
            $this->removeNodeReportedDevices($nodeId, (int) $userId);
            $this->notifyUpdate((int) $userId);
        }

        foreach ($nextUsers as $userId => $ips) {
            $this->setDevices((int) $userId, $nodeId, $ips);
        }
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        $data = Redis::hgetall(self::REPORTED_PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return count(array_unique($ips));
    }

    /**
     * Apply current online counts to an admin list collection in memory only.
     */
    public function applyOnlineCounts(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
            $count = $this->getDeviceCount((int) $user->id);
            $limit = (int) ($user->device_limit ?? 0);
            if ($limit > 0) {
                $count = min($count, $limit);
            }
            $user->online_count = $count;
        }
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            $limit = (int) ($user->device_limit ?? 0);
            if ($limit > 0) {
                $count = min($count, $limit);
            }
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds, array $limits = []): array
    {
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - $timestamp <= self::TTL) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $limit = array_key_exists($userId, $limits) ? (int) $limits[$userId] : null;
                    $allowed = $this->limitDevices($userId, array_values(array_unique($ips)), $limit);

                    // Compatibility for nodes not yet upgraded with the
                    // globaldevices5 bootstrap fix: if the panel sends a
                    // partial strict whitelist (for example 1 of 2 slots), old
                    // nodes treat it as complete and reject the second device
                    // before it can connect/report. Keep device_limit=1 strict,
                    // but omit incomplete multi-slot snapshots until the global
                    // allowlist reaches the configured limit.
                    if ($limit !== null && $limit > 1 && count($allowed) > 0 && count($allowed) < $limit) {
                        continue;
                    }

                    $result[$userId] = $allowed;
                }
            }
        }

        return $result;
    }

    /**
     * Enforce configured device_limit by keeping only the allowed devices.
     * Existing allowed devices are preferred so the first online device remains stable;
     * extra newly reported devices are not saved and will not be pushed back to nodes.
     */
    private function limitDevices(int $userId, array $ips, ?int $configuredLimit = null, ?array $preferredAllowedIps = null): array
    {
        if ($configuredLimit !== null) {
            $limit = $configuredLimit;
        } else {
            $upstream = app(UpstreamSubscribeService::class);
            $limit = $upstream->isNodeUserId($userId)
                ? $upstream->nodeDeviceLimit($userId)
                : (int) (User::query()->whereKey($userId)->value('device_limit') ?? 0);
        }
        if ($limit <= 0) {
            return $ips;
        }

        // setDevices() removes this node's old records before calling here.
        // When setDevices() passed a pre-removal preferred list, keep those
        // already-allowed winners stable and only use this report to fill empty
        // slots. This prevents a newly connecting second device from replacing
        // the first allowed device before both can coexist in the global list.
        $reportedAlive = $this->getReportedDevices($userId);
        $allowedGlobal = [];
        foreach (($preferredAllowedIps ?? $this->getAllowedDevices($userId)) as $ip) {
            // setDevices() captures the already-allowed devices before replacing
            // this node's latest report. Keep those winners stable even if this
            // report temporarily contains only a newly connecting device;
            // otherwise device_limit=2 can collapse to one device by replacing
            // the first allowed device with the second.
            if ($preferredAllowedIps === null && !in_array($ip, $reportedAlive, true)) {
                continue;
            }
            if (!in_array($ip, $allowedGlobal, true)) {
                $allowedGlobal[] = $ip;
            }
            if (count($allowedGlobal) >= $limit) {
                break;
            }
        }

        foreach ($ips as $ip) {
            if (count($allowedGlobal) >= $limit) {
                break;
            }
            if (!in_array($ip, $reportedAlive, true)) {
                continue;
            }
            if (!in_array($ip, $allowedGlobal, true)) {
                $allowedGlobal[] = $ip;
            }
        }

        // Save/push only the IPs from this node that are part of the global
        // allowed set. If another node already uses all slots, this node gets
        // an empty device list and its extra connections are kicked.
        return array_values(array_filter($ips, fn ($ip) => in_array($ip, $allowedGlobal, true)));
    }


    /**
     * Queue authoritative sync.devices push for every online node that can serve this user.
     */
    private function queueDevicePushForUser(int $userId): void
    {
        $upstream = app(UpstreamSubscribeService::class);
        if ($upstream->isNodeUserId($userId)) {
            $nodeIds = collect($upstream->localControlledNodeIds())
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => NodeSyncService::isNodeOnline($id))
                ->values()
                ->all();
        } else {
            $user = User::query()->select(['id', 'group_id'])->find($userId);
            if (!$user) {
                return;
            }

            $nodeIds = \App\Models\Server::query()
                ->where('enabled', 1)
                ->whereJsonContains('group_ids', (string) $user->group_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => NodeSyncService::isNodeOnline($id))
                ->values()
                ->all();
        }

        foreach ($nodeIds as $nodeId) {
            Redis::sadd('device:push_pending_nodes', $nodeId);
        }

        Log::info('[DeviceLimit] queued global device allowlist push', [
            'user_id' => $userId,
            'node_ids' => $nodeIds,
        ]);
    }

    /**
     * Return current non-expired reported devices for a user, deduplicated.
     * This is the live source of truth used to avoid stale allowed devices
     * occupying global slots after they disappeared from every node report.
     */
    private function getReportedDevices(int $userId): array
    {
        $data = Redis::hgetall(self::REPORTED_PREFIX . $userId);
        $now = time();
        $devices = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $device = substr($field, strpos($field, ':') + 1);
                if (!in_array($device, $devices, true)) {
                    $devices[] = $device;
                }
            }
        }

        return $devices;
    }

    /**
     * Return current non-expired allowed devices for a user, deduplicated by IP.
     */
    private function getAllowedDevices(int $userId): array
    {
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $ip = substr($field, strpos($field, ':') + 1);
                if (!in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * notify update (throttle control)
     */
    public function notifyUpdate(int $userId): void
    {
        $dbThrottleKey = "device:db_throttle:{$userId}";

        // if (Redis::setnx($dbThrottleKey, 1)) {
        //     Redis::expire($dbThrottleKey, self::DB_THROTTLE);

            if (!app(UpstreamSubscribeService::class)->isNodeUserId($userId)) {
                User::query()
                    ->whereKey($userId)
                    ->update([
                        'online_count' => $this->getDeviceCount($userId),
                        'last_online_at' => now(),
                    ]);
            }
        // }
    }
}
