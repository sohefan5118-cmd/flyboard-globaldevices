<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Global, cross-node device state.
 *
 * Device limit 1 is deliberately enforced here, before a report is written:
 * the first active device keeps ownership; a later device is rejected and
 * never replaces or removes the first one.
 */
class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const DISPLAY_PREFIX = 'user_devices_seen:';
    private const REPORT_SEEN_PREFIX = 'device_report_seen:';
    private const REPORT_MISSING_PREFIX = 'device_report_missing:';
    // Require repeated explicit absence before starting stale grace. One empty
    // or partial snapshot can be reporter jitter; two consecutive absences are
    // enough to start checking release eligibility. Actual release still waits
    // for flyboard_antishare_window since the last positive confirmation.
    private const MISSING_OFFLINE_THRESHOLD = 2;
    // Defaults only. The effective window follows FlyBoard's configurable
    // device memory window so subscription release, node slots, and UI display
    // all expire together.
    private const DEFAULT_DEVICE_WINDOW = 300;
    private const MIN_DEVICE_WINDOW = 60;
    private const LOCK_TTL = 5;
    private const LOCK_WAIT_MS = 2000;

    private function removeRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix ? substr($key, strlen($prefix)) : $key;
    }

    private function displayKey(int $userId): string
    {
        return self::DISPLAY_PREFIX . $userId;
    }

    private function reportSeenKey(int $nodeId): string
    {
        return self::REPORT_SEEN_PREFIX . $nodeId;
    }

    private function reportMissingKey(int $nodeId): string
    {
        return self::REPORT_MISSING_PREFIX . $nodeId;
    }

    private function clearNodeUserMissing(int $nodeId, int $userId): void
    {
        Redis::hdel($this->reportMissingKey($nodeId), (string) $userId);
    }

    private function noteNodeUserMissing(int $nodeId, int $userId): int
    {
        $key = $this->reportMissingKey($nodeId);
        $count = (int) Redis::hincrby($key, (string) $userId, 1);
        Redis::expire($key, max(3600, $this->deviceWindow() * 10));
        return $count;
    }

    public function markNodeUserReportSeen(int $nodeId, int $userId): void
    {
        Redis::hset($this->reportSeenKey($nodeId), (string) $userId, time());
        Redis::expire($this->reportSeenKey($nodeId), max(3600, $this->deviceWindow() * 10));
        $this->clearNodeUserMissing($nodeId, $userId);
    }

    /**
     * Process a complete node alive/device snapshot. Used by HTTP /alive, V2
     * /report alive, and can mirror WS semantics. Present users refresh active
     * claims; users previously active on this node but missing from repeated
     * snapshots are released only after the configured anti-share window.
     */
    public function processNodeAliveSnapshot(int $nodeId, array $alive): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        $newDevices = [];

        foreach ($alive as $userId => $ips) {
            if (!is_numeric($userId)) continue;
            if (is_string($ips)) $ips = [$ips];
            if (!is_array($ips)) continue;
            $ips = array_values(array_filter($ips, 'is_string'));
            if (!empty($ips)) {
                $newDevices[(int) $userId] = $ips;
            }
        }

        $accepted = [];
        $rejected = [];
        foreach ($newDevices as $userId => $ips) {
            if ($this->setDevices((int) $userId, $nodeId, $ips)) {
                $accepted[] = (int) $userId;
            } else {
                $rejected[] = (int) $userId;
            }
        }

        $stale = [];
        foreach (array_keys($oldDevices) as $userId) {
            $userId = (int) $userId;
            if (array_key_exists($userId, $newDevices)) {
                $this->clearNodeUserMissing($nodeId, $userId);
                continue;
            }

            $missingCount = $this->noteNodeUserMissing($nodeId, $userId);
            if ($missingCount >= self::MISSING_OFFLINE_THRESHOLD) {
                if ($this->markNodeDevicesStale($nodeId, $userId, false)) {
                    $stale[] = $userId;
                }
            }
        }

        return [
            'accepted' => array_values(array_unique($accepted)),
            'rejected' => array_values(array_unique($rejected)),
            'stale' => array_values(array_unique($stale)),
            'present' => array_keys($newDevices),
        ];
    }

    private function deviceWindow(): int
    {
        try {
            return max(self::MIN_DEVICE_WINDOW, (int) admin_setting(FlyboardService::SETTING_WINDOW, self::DEFAULT_DEVICE_WINDOW));
        } catch (\Throwable $e) {
            return self::DEFAULT_DEVICE_WINDOW;
        }
    }

    /** Remember accepted devices for UI display only; not used for enforcement. */
    private function rememberDisplayDevices(int $userId, array $ips): void
    {
        $ips = array_values(array_unique(array_filter($ips, fn($ip) => $ip !== '')));
        if (empty($ips)) return;

        $key = $this->displayKey($userId);
        $now = time();
        foreach ($ips as $ip) {
            Redis::hset($key, $ip, $now);
        }
        Redis::expire($key, $this->deviceWindow());
    }

    private function pruneDisplayDevices(int $userId): array
    {
        $key = $this->displayKey($userId);
        $now = time();
        $ttl = $this->deviceWindow();
        $ips = [];
        foreach (Redis::hgetall($key) as $ip => $timestamp) {
            if ($now - (int) $timestamp >= $ttl) {
                Redis::hdel($key, $ip);
                continue;
            }
            $ips[] = $ip;
        }
        if (empty($ips)) {
            Redis::del($key);
        }
        return array_values(array_unique($ips));
    }

    /**
     * Set the reporting node's device state, enforcing the user's global limit.
     * Returns false when this report contains a distinct device beyond the
     * configured limit. A zero/null limit keeps the legacy unlimited behavior.
     */
    public function setDevices(int $userId, int $nodeId, array $ips): bool
    {
        $ips = array_values(array_unique(array_filter(array_map([self::class, 'normalizeIP'], $ips), fn($ip) => $ip !== '')));
        $user = User::query()->find($userId);
        $deviceLimit = $user?->device_limit;
        $deviceLimit = $deviceLimit === null ? 0 : (int) $deviceLimit;
        $key = self::PREFIX . $userId;
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) {
            // Do not overwrite the winner during a contention window.
            return false;
        }

        try {
            // Reap claims that were explicitly marked stale by a node report
            // and have exceeded the 5-minute offline grace period.
            $this->pruneExpiredDevicesUnlocked($userId);
            $fields = Redis::hgetall($key);
            // Heal keys created by the old implementation, which may still
            // carry a Redis key TTL after the code has been upgraded.
            if (!empty($fields)) {
                Redis::persist($key);
            }

            if (empty($ips)) {
                // Explicit empty snapshot means this node currently does not
                // see this user, but release must still honor the configured
                // FlyBoard anti-share window. Otherwise two empty/missing
                // reports at ~60s intervals look like a hard-coded 120s window
                // even when flyboard_antishare_window is 300.
                if (!$this->nodeUserReleaseDue($nodeId, $userId)) {
                    return true;
                }
                $removed = $this->removeNodeDevicesUnlocked($userId, $nodeId);
                if ($removed) {
                    $this->syncOnlineCount($userId);
                }
                return true;
            }

            // A positive report must never replace an existing slot merely
            // because it came from the same node with a different source IP.
            // Keep the old identity occupying capacity until the configured
            // anti-share window expires (or explicit offline cleanup is due),
            // otherwise an IP change can bypass device_limit in one request.

            // Only positive active claims count against the global limit.
            // Negative stale claims are legacy leftovers and are removed by
            // pruneExpiredDevicesUnlocked(); keeping them would make panel 0 and
            // enforcement availability drift apart.
            $active = [];
            foreach ($fields as $field => $timestamp) {
                if ((int) $timestamp > 0) {
                    $active[$field] = (int) $timestamp;
                }
            }

            $existingIps = [];
            foreach (array_keys($active) as $field) {
                $existingIps[] = substr($field, strpos($field, ':') + 1);
            }
            $existingIps = array_values(array_unique($existingIps));

            // A zero limit means unlimited, otherwise admit only the first
            // device_limit distinct IP identities globally. Repeated reports
            // refresh existing claims; excess identities never replace them.
            $knownIps = array_fill_keys($existingIps, true);
            $acceptedIps = [];
            $rejected = false;
            foreach ($ips as $ip) {
                if (isset($knownIps[$ip])) {
                    $acceptedIps[] = $ip;
                    continue;
                }

                // Node-reported active IPs are authoritative for global
                // device slots. Do not require Flyboard subscription trusted-IP
                // here, otherwise /api/v2/server/devices stays empty when the
                // client did not recently refresh subscription or its mobile
                // network IP changed.

                if ($deviceLimit > 0 && count($knownIps) >= $deviceLimit) {
                    $rejected = true;
                    continue;
                }
                $knownIps[$ip] = true;
                $acceptedIps[] = $ip;
            }

            // Refresh existing claims and add newly admitted identities. Keep
            // node-specific fields so a report from another node cannot erase
            // the original node's ownership.
            $timestamp = time();
            foreach ($acceptedIps as $ip) {
                Redis::hset($key, "{$nodeId}:{$ip}", $timestamp);
            }
            if (!empty($acceptedIps)) {
                // setDevices() is used by both the WebSocket device report path
                // and the HTTP /api/v2/server/devices path. Refresh the
                // node:user confirmation here so cleanup does not mark a just
                // accepted active claim stale merely because that transport did
                // not call NodeEventHandlers::markNodeUserReportSeen().
                $this->markNodeUserReportSeen($nodeId, $userId);
            }
            $this->rememberDisplayDevices($userId, $acceptedIps);
            $this->notifyUpdate($userId);
            return !$rejected;
        } finally {
            // Compare-and-delete so a delayed worker cannot release another
            // worker's lock after the short lease expires.
            $this->releaseLock($lockKey, $token);
        }
    }

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

    public function removeNodeDevices(int $nodeId, int $userId): bool
    {
        $key = self::PREFIX . $userId;
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) {
            return false;
        }

        try {
            $removed = $this->removeNodeDevicesUnlocked($userId, $nodeId);
            if ($removed) {
                $this->notifyUpdate($userId);
            }
            return $removed;
        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    /** Caller must hold the per-user device lock. */
    private function removeNodeDevicesUnlocked(int $userId, int $nodeId): bool
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";
        $removed = false;
        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
                $removed = true;
            }
        }
        return $removed;
    }

    /** Refresh existing claims for a node when its report is transiently empty. */
    public function refreshNodeClaims(int $nodeId): array
    {
        $affected = [];
        $prefix = "{$nodeId}:";
        foreach (Redis::keys(self::PREFIX . '*') as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $userId = (int) substr($actualKey, strlen(self::PREFIX));
            if ($userId <= 0) continue;

            $lockKey = 'device:lock:' . $userId;
            $token = $this->acquireLock($lockKey);
            if ($token === null) continue;
            try {
                $now = time();
                $touched = false;
                foreach (Redis::hkeys($actualKey) as $field) {
                    if (str_starts_with($field, $prefix)) {
                        Redis::hset($actualKey, $field, $now);
                        $touched = true;
                    }
                }
            } finally {
                $this->releaseLock($lockKey, $token);
            }

            if ($touched) {
                $affected[] = $userId;
            }
        }
        return array_values(array_unique($affected));
    }

    /** Refresh an existing active claim from non-device online signals. */
    public function refreshNodeUserActive(int $nodeId, int $userId): bool
    {
        $key = self::PREFIX . $userId;
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) {
            return false;
        }

        $changed = false;
        try {
            $prefix = "{$nodeId}:";
            $now = time();
            foreach (Redis::hgetall($key) as $field => $timestamp) {
                if (str_starts_with($field, $prefix) && (int) $timestamp > 0) {
                    Redis::hset($key, $field, $now);
                    $changed = true;
                }
            }
        } finally {
            $this->releaseLock($lockKey, $token);
        }

        if ($changed) {
            $this->markNodeUserReportSeen($nodeId, $userId);
        }
        return $changed;
    }

    /** Caller should hold the user lock when using this to gate removal. */
    private function nodeUserReleaseDue(int $nodeId, int $userId): bool
    {
        $now = time();
        $window = $this->deviceWindow();
        $latest = 0;
        $prefix = "{$nodeId}:";
        foreach (Redis::hgetall(self::PREFIX . $userId) as $field => $timestamp) {
            $ts = (int) $timestamp;
            if ($ts > 0 && str_starts_with($field, $prefix)) {
                $latest = max($latest, $ts);
            }
        }
        $lastSeen = (int) (Redis::hget($this->reportSeenKey($nodeId), (string) $userId) ?: 0);
        $latest = max($latest, $lastSeen);

        // If we have no positive evidence timestamp left, there is nothing to
        // protect. Legacy negative/stale leftovers are pruned elsewhere.
        if ($latest <= 0) {
            return true;
        }
        return ($now - $latest) >= $window;
    }

    /** Release one user's devices from a node after explicit offline evidence. */
    public function markNodeDevicesStale(int $nodeId, int $userId, bool $respectRecentSeen = true): bool
    {
        $key = self::PREFIX . $userId;
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) {
            return false;
        }

        try {
            $changed = $this->pruneExpiredDevicesUnlocked($userId);
            // All explicit-offline removal paths must honor the configured
            // window. The $respectRecentSeen parameter is retained for older
            // callers, but no caller may bypass flyboard_antishare_window.
            if (!$this->nodeUserReleaseDue($nodeId, $userId)) {
                return $changed;
            }
            if ($this->removeNodeDevicesUnlocked($userId, $nodeId)) {
                $changed = true;
            }
            if ($changed) {
                $this->notifyUpdate($userId);
            }
            return $changed;
        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    public function clearAllNodeDevices(int $nodeId): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        foreach ($oldDevices as $userId => $ips) {
            // Preserve the same 5-minute offline grace instead of deleting
            // immediately. This method is retained for compatibility only;
            // NodeWorker no longer calls it on socket reconnect/close.
            $this->markNodeDevicesStale($nodeId, (int) $userId, false);
        }
        return array_keys($oldDevices);
    }

    /** Acquire a short per-user lock shared by reports and disconnect cleanup. */
    private function acquireLock(string $lockKey): ?string
    {
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + (self::LOCK_WAIT_MS / 1000);

        do {
            if (Redis::set($lockKey, $token, 'NX', 'EX', self::LOCK_TTL)) {
                return $token;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** Release only our own lock; never delete a later owner's lock. */
    private function releaseLock(string $lockKey, string $token): void
    {
        Redis::eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
            1,
            $lockKey,
            $token
        );
    }

    /** Remove expired stale claims belonging to one node. */
    public function pruneExpiredNodeDevices(int $userId, int $nodeId, ?array $fields = null): void
    {
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) {
            return;
        }

        try {
            // Re-read under the lock; the caller's pre-lock snapshot may be stale.
            $this->pruneExpiredNodeDevicesUnlocked($userId, $nodeId);
        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    /** Caller must hold the per-user device lock. */
    private function pruneExpiredNodeDevicesUnlocked(int $userId, int $nodeId, ?array $fields = null): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";
        $now = time();
        foreach (($fields ?? Redis::hgetall($key)) as $field => $timestamp) {
            $ts = (int) $timestamp;
            if (str_starts_with($field, $prefix) && $this->claimExpired($ts, $now)) {
                Redis::hdel($key, $field);
            }
        }
    }

    /** Caller must hold the per-user device lock. */
    private function pruneExpiredDevicesUnlocked(int $userId): bool
    {
        $key = self::PREFIX . $userId;
        $now = time();
        $changed = false;
        foreach (Redis::hgetall($key) as $field => $timestamp) {
            $ts = (int) $timestamp;
            if ($this->claimExpired($ts, $now)) {
                Redis::hdel($key, $field);
                $changed = true;
            }
        }
        return $changed;
    }

    private function claimExpired(int $timestamp, ?int $now = null): bool
    {
        $now ??= time();

        // Positive active claims expire after the configured FlyBoard window,
        // and are refreshed by reliable online evidence paths: device/alive
        // reports and online connection reports. Traffic reports are not used
        // for presence because delayed/batched traffic can keep users falsely
        // online after disconnect. Negative stale values are legacy leftovers
        // and are removed immediately.
        if ($timestamp <= 0) {
            return true;
        }
        return ($now - $timestamp) >= $this->deviceWindow();
    }


    private function pruneExpiredDevicesBestEffort(int $userId): void
    {
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) return;
        $changed = false;
        try {
            $changed = $this->pruneExpiredDevicesUnlocked($userId);
        } finally {
            $this->releaseLock($lockKey, $token);
        }
        if ($changed) {
            $this->syncOnlineCount($userId);
        }
    }

    /** Reap stale claims for a node, including users omitted by a report. */
    public function pruneExpiredNode(int $nodeId): array
    {
        $affected = [];
        foreach (Redis::keys(self::PREFIX . '*') as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $userId = (int) substr($actualKey, strlen(self::PREFIX));
            $before = Redis::hgetall($actualKey);
            $this->pruneExpiredNodeDevices($userId, $nodeId);
            if (count($before) !== count(Redis::hgetall($actualKey))) {
                $affected[] = $userId;
                $this->notifyUpdate($userId);
            }
        }
        return array_values(array_unique($affected));
    }

    /**
     * Mark active claims stale when their owning node has not confirmed that
     * specific user for longer than the device window. This is not active
     * deletion: it only starts the stale grace path, and stale claims can be
     * replaced by the next non-empty report for the same user.
     */
    public function markSilentActiveClaimsStale(): array
    {
        $affected = [];
        $now = time();
        $window = $this->deviceWindow();

        foreach (Redis::keys(self::PREFIX . '*') as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $userId = (int) substr($actualKey, strlen(self::PREFIX));
            if ($userId <= 0) continue;

            $lockKey = 'device:lock:' . $userId;
            $token = $this->acquireLock($lockKey);
            if ($token === null) continue;

            $changed = false;
            try {
                foreach (Redis::hgetall($actualKey) as $field => $timestamp) {
                    $timestamp = (int) $timestamp;
                    if ($timestamp < 0) continue;

                    $colon = strpos($field, ':');
                    if ($colon === false) continue;
                    $nodeId = (int) substr($field, 0, $colon);
                    if ($nodeId <= 0) continue;

                    $lastSeen = (int) (Redis::hget($this->reportSeenKey($nodeId), (string) $userId) ?: 0);
                    if ($lastSeen > 0 && ($now - $lastSeen) < $window) {
                        continue;
                    }

                    Redis::hset($actualKey, $field, -$now);
                    $changed = true;
                }
            } finally {
                $this->releaseLock($lockKey, $token);
            }

            if ($changed) {
                $affected[] = $userId;
                $this->notifyUpdate($userId);
            }
        }

        return array_values(array_unique($affected));
    }

    /**
     * Reap expired stale claims even when the node has stopped sending reports.
     * A disconnected phone cannot send a final report, so cleanup must not
     * depend on a later report from the same node.
     */
    /** Compatibility wrapper: active expiry now releases directly in pruneExpiredAll(). */
    public function markExpiredActiveClaimsStale(): array
    {
        return [];
    }

    public function pruneExpiredAll(): array
    {
        $affected = [];
        foreach (Redis::keys(self::PREFIX . '*') as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $userId = (int) substr($actualKey, strlen(self::PREFIX));
            if ($userId <= 0) continue;

            $lockKey = 'device:lock:' . $userId;
            $token = $this->acquireLock($lockKey);
            if ($token === null) continue;
            try {
                $before = Redis::hgetall($actualKey);
                $this->pruneExpiredDevicesUnlocked($userId);
                $after = Redis::hgetall($actualKey);
            } finally {
                $this->releaseLock($lockKey, $token);
            }

            if (count($before) !== count($after)) {
                $affected[] = $userId;
                $this->notifyUpdate($userId);
            }
        }

        // Redis display keys may expire by TTL without passing through the
        // PHP cleanup path. Reconcile non-zero DB counters against current
        // Redis state so the panel cannot stay stuck at online_count=1 after
        // all enforcement/display slots have already gone away.
        foreach (User::query()->where('online_count', '>', 0)->pluck('id') as $userId) {
            $userId = (int) $userId;
            if ($this->reconcileOnlineCount($userId)) {
                $affected[] = $userId;
            }
        }

        return array_values(array_unique($affected));
    }

    public function getDeviceCount(int $userId): int
    {
        $this->pruneExpiredDevicesBestEffort($userId);
        $data = Redis::hgetall(self::PREFIX . $userId);
        $ips = [];
        foreach ($data as $field => $timestamp) {
            $ips[] = substr($field, strpos($field, ':') + 1);
        }
        return count(array_unique($ips));
    }

    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) return [];
        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) $result[$user->id] = $count;
        }
        return $result;
    }

    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $userId) {
            $this->pruneExpiredDevicesBestEffort((int) $userId);
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (empty($data)) continue;
            $ips = [];
            foreach ($data as $field => $timestamp) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
            if (!empty($ips)) $result[$userId] = array_values(array_unique($ips));
        }
        return $result;
    }

    /** Devices for Flyboard display: active enforcement slots plus recent seen cache. */
    public function getUsersDisplayDevices(array $userIds): array
    {
        $active = $this->getUsersDevices($userIds);
        $result = $active;

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $ips = $active[$userId] ?? [];
            foreach ($this->pruneDisplayDevices($userId) as $ip) {
                $ips[] = $ip;
            }
            $ips = array_values(array_unique($ips));
            if (!empty($ips)) {
                $result[$userId] = $ips;
            }
        }

        return $result;
    }

    public function getDisplayDeviceCount(int $userId): int
    {
        $this->pruneExpiredDevicesBestEffort($userId);
        return $this->computeDisplayDeviceCount($userId);
    }

    /** Clear every node claim for one user while holding the same lock as reports. */
    public function clearUserDevices(int $userId): int
    {
        $key = self::PREFIX . $userId;
        $lockKey = 'device:lock:' . $userId;
        $token = $this->acquireLock($lockKey);
        if ($token === null) return 0;

        try {
            $count = (int) Redis::hlen($key);
            $displayCount = (int) Redis::hlen($this->displayKey($userId));
            if ($count > 0 || $displayCount > 0) {
                Redis::del($key);
                Redis::del($this->displayKey($userId));
                $this->syncOnlineCount($userId);
            } else {
                Redis::del($this->displayKey($userId));
            }
            return $count;
        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    private static function normalizeIP(string $ip): string
    {
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) return $m[1];
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) return $m[1];
        return $ip;
    }

    private function computeDisplayDeviceCount(int $userId): int
    {
        // Panel online_count must represent users currently active online, not
        // enforcement grace. Negative stale claims intentionally keep occupying
        // the global device slot for anti-share grace, but they should display
        // as offline. The seen/display cache is only for device/IP display and
        // must not keep online_count at 1 after a user disconnects.
        $ips = [];
        foreach (Redis::hgetall(self::PREFIX . $userId) as $field => $timestamp) {
            if ((int) $timestamp <= 0) {
                continue;
            }
            $ips[] = substr($field, strpos($field, ':') + 1);
        }
        return count(array_unique(array_filter($ips, fn($ip) => $ip !== '')));
    }

    private function syncOnlineCount(int $userId): void
    {
        User::query()->whereKey($userId)->update([
            'online_count' => $this->computeDisplayDeviceCount($userId),
            'last_online_at' => now(),
        ]);
    }

    private function reconcileOnlineCount(int $userId): bool
    {
        $count = $this->computeDisplayDeviceCount($userId);
        $current = User::query()->whereKey($userId)->value('online_count');
        if ($current === null || (int) $current === $count) {
            return false;
        }
        User::query()->whereKey($userId)->update(['online_count' => $count]);
        return true;
    }

    public function notifyUpdate(int $userId): void
    {
        $this->syncOnlineCount($userId);
    }
}
