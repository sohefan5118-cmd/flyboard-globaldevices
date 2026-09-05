<?php

namespace App\Services\Flyboard;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use App\Models\Server;
use App\Services\Flyboard\NodeAuthAliasService;
use App\Utils\Helper;

class UpstreamSubscribeService
{
    private const URI_PREFIX_PATTERN = '/^(ss|ssr|vmess|vless|trojan|hysteria|hysteria2|hy2|tuic|socks|socks5|http|https):\/\//i';
    private const NODE_USER_ID_OFFSET = 900000000;

    public function isEnabled(): bool
    {
        return (bool) admin_setting('upstream_subscribe_enable', 0)
            && !empty($this->enabledProfiles());
    }

    public function mergeIntoResponse(Response $response, Request $request, mixed $user): Response
    {
        if (!$this->isEnabled()) {
            return $response;
        }

        $localBody = (string) $response->getContent();
        $localParsed = $this->parseSubscriptionBody($localBody);

        if ($localParsed['format'] === 'structured') {
            $response->headers->set('X-Flyboard-Upstream-Subscribe', 'skipped-structured-format');
            return $response;
        }

        $fetch = $this->fetchAllForUser($request, $user);
        $upstreamLines = $fetch['useful_lines'];
        if (empty($upstreamLines)) {
            $response->headers->set('X-Flyboard-Upstream-Subscribe', 'fetch-empty');
            if (!empty($fetch['errors'])) {
                $response->headers->set('X-Flyboard-Upstream-Error', Str::limit(implode('; ', $fetch['errors']), 120, ''));
            }
            return $response;
        }

        $localLines = $this->extractUriLines($localParsed['decoded']);
        $mergedLines = $this->mergeLines($localLines, $upstreamLines);

        if (empty($mergedLines)) {
            $response->headers->set('X-Flyboard-Upstream-Subscribe', 'skipped-empty');
            return $response;
        }

        $merged = implode("\r\n", $mergedLines) . "\r\n";
        $response->setContent($localParsed['format'] === 'base64' ? base64_encode($merged) : $merged);
        $response->headers->set('content-type', 'text/plain');
        $response->headers->set('X-Flyboard-Upstream-Subscribe', 'merged');
        $response->headers->set('X-Flyboard-Upstream-Count', (string) count($upstreamLines));
        $response->headers->set('X-Flyboard-Upstream-Sources', (string) count($fetch['profiles']));
        $response->headers->set('X-Flyboard-Upstream-Deleted-Useless', (string) $fetch['useless_count']);

        return $response;
    }

    public function preview(Request $request, mixed $user = null): array
    {
        $result = $this->fetchAllForUser($request, $user);

        return [
            'ok' => !empty($result['useful_lines']),
            'message' => empty($result['useful_lines']) ? (implode('; ', $result['errors']) ?: '没有识别到可用节点') : 'ok',
            'format' => 'multi',
            'source_count' => count($result['profiles']),
            'raw_uri_count' => $result['raw_uri_count'],
            'uri_count' => count($result['useful_lines']),
            'useless_count' => $result['useless_count'],
            'bytes' => $result['bytes'],
            'sources' => $result['sources'],
            'preview' => array_slice($result['useful_lines'], 0, 30),
            'errors' => $result['errors'],
        ];
    }

    public function fetchAllForUser(Request $request, mixed $user = null): array
    {
        $profiles = $this->enabledProfiles();
        $allUseful = [];
        $sources = [];
        $errors = [];
        $bytes = 0;
        $rawUriCount = 0;
        $uselessCount = 0;

        foreach ($profiles as $profile) {
            $result = $this->fetchProfileForUser($profile, $request, $user);
            if (!($result['ok'] ?? false)) {
                $errors[] = ($profile['name'] ?? '上游') . ': ' . ($result['message'] ?? '拉取失败');
                $sources[] = array_merge($this->publicProfile($profile), [
                    'ok' => false,
                    'message' => $result['message'] ?? '拉取失败',
                ]);
                continue;
            }

            $parsed = $this->parseSubscriptionBody($result['body']);
            $rawLines = in_array($parsed['format'], ['base64', 'plain'], true) ? $this->extractUriLines($parsed['decoded']) : [];
            $usefulLines = $this->filterUsefulUriLines($rawLines);
            $deleted = count($rawLines) - count($usefulLines);

            $rawUriCount += count($rawLines);
            $uselessCount += $deleted;
            $bytes += (int) ($result['bytes'] ?? 0);
            $allUseful = array_merge($allUseful, $usefulLines);

            $sources[] = array_merge($this->publicProfile($profile), [
                'ok' => true,
                'message' => 'ok',
                'url' => $result['url'] ?? null,
                'status' => $result['status'] ?? null,
                'format' => $parsed['format'],
                'bytes' => $result['bytes'] ?? 0,
                'raw_uri_count' => count($rawLines),
                'uri_count' => count($usefulLines),
                'useless_count' => $deleted,
            ]);
        }

        return [
            'profiles' => $profiles,
            'sources' => $sources,
            'errors' => $errors,
            'bytes' => $bytes,
            'raw_uri_count' => $rawUriCount,
            'useless_count' => $uselessCount,
            'useful_lines' => array_values(array_unique($allUseful)),
        ];
    }

    public function upstreamUsers(): array
    {
        $users = admin_setting('upstream_subscribe_users', []);
        if (is_string($users)) {
            $decoded = json_decode($users, true);
            $users = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($users)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($user) {
            if (!is_array($user) || empty($user['id']) || empty($user['email']) || empty($user['token'])) {
                return null;
            }
            return [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'token' => (string) $user['token'],
                'uuid' => (string) ($user['uuid'] ?? ''),
                'transfer_enable' => (int) ($user['transfer_enable'] ?? 0),
                'u' => (int) ($user['u'] ?? 0),
                'd' => (int) ($user['d'] ?? 0),
                'expired_at' => (int) ($user['expired_at'] ?? 0),
                'device_limit' => (int) ($user['device_limit'] ?? 0),
                'banned' => (int) ($user['banned'] ?? 0),
                'remarks' => (string) ($user['remarks'] ?? ''),
                'created_at' => (int) ($user['created_at'] ?? 0),
                'updated_at' => (int) ($user['updated_at'] ?? 0),
                'password' => (string) ($user['password'] ?? ''),
            ];
        }, $users)));
    }

    public function saveUpstreamUsers(array $users): void
    {
        $safe = array_slice(array_values(array_map(function ($user) {
            return [
                'id' => (int) ($user['id'] ?? 0),
                'email' => (string) ($user['email'] ?? ''),
                'token' => (string) ($user['token'] ?? ''),
                'uuid' => (string) ($user['uuid'] ?? ''),
                'transfer_enable' => (int) ($user['transfer_enable'] ?? 0),
                'u' => (int) ($user['u'] ?? 0),
                'd' => (int) ($user['d'] ?? 0),
                'expired_at' => (int) ($user['expired_at'] ?? 0),
                'device_limit' => (int) ($user['device_limit'] ?? 0),
                'banned' => (int) ($user['banned'] ?? 0),
                'remarks' => (string) ($user['remarks'] ?? ''),
                'created_at' => (int) ($user['created_at'] ?? time()),
                'updated_at' => (int) ($user['updated_at'] ?? time()),
                'password' => (string) ($user['password'] ?? ''),
            ];
        }, $users)), 0, 1000);

        admin_setting(['upstream_subscribe_users' => $safe]);
    }

    public function findUpstreamUser(int $id): ?array
    {
        foreach ($this->upstreamUsers() as $user) {
            if ((int) ($user['id'] ?? 0) === $id) {
                return $user;
            }
        }
        return null;
    }

    public function findUpstreamUserByToken(string $token): ?array
    {
        foreach ($this->upstreamUsers() as $user) {
            if (hash_equals((string) ($user['token'] ?? ''), $token)) {
                return $user;
            }
        }
        return null;
    }

    public function checkUpstreamUserAvailable(array $user): true|string
    {
        if ((int) ($user['banned'] ?? 0) === 1) {
            return '上游用户已封禁';
        }

        $expiredAt = (int) ($user['expired_at'] ?? 0);
        if ($expiredAt > 0 && $expiredAt <= time()) {
            return '上游用户已到期';
        }

        $transfer = (int) ($user['transfer_enable'] ?? 0);
        $used = (int) ($user['u'] ?? 0) + (int) ($user['d'] ?? 0);
        if ($transfer > 0 && $used >= $transfer) {
            return '上游用户流量已用尽';
        }

        return true;
    }

    public function nodeUserId(int $upstreamUserId): int
    {
        return self::NODE_USER_ID_OFFSET + $upstreamUserId;
    }

    public function isNodeUserId(int $nodeUserId): bool
    {
        return $nodeUserId > self::NODE_USER_ID_OFFSET;
    }

    public function upstreamUserIdFromNodeUserId(int $nodeUserId): int
    {
        return $nodeUserId - self::NODE_USER_ID_OFFSET;
    }

    public function nodeDeviceLimit(int $nodeUserId): int
    {
        $user = $this->findUpstreamUser($this->upstreamUserIdFromNodeUserId($nodeUserId));
        return $user ? (int) ($user['device_limit'] ?? 0) : 0;
    }

    public function nodeUsersForNode(Server $node): Collection
    {
        return collect($this->upstreamUsers())
            ->filter(fn (array $user) => $this->checkUpstreamUserAvailable($user) === true)
            ->map(function (array $user) {
                $nodeUser = $this->nodeUserForAuth($user);
                if (!$nodeUser) {
                    return null;
                }

                // Upstream virtual users are already isolated into the reserved
                // node-user id range. Keep one stable auth UUID and disable the
                // node-side/global device gate for them: sync.devices lag or a
                // missing allowlist must not intermittently reject otherwise
                // valid TikTok/YouTube/Google traffic.
                $nodeUser['uuid'] = $this->nodeAuthUuid($nodeUser);
                $nodeUser['auth_aliases'] = [];
                $nodeUser['device_limit'] = 0;

                return $nodeUser;
            })
            ->filter()
            ->values();
    }

    public function nodeUserForAuth(array $user): ?\ArrayObject
    {
        if ($this->checkUpstreamUserAvailable($user) !== true) {
            return null;
        }

        $id = (int) ($user['id'] ?? 0);
        $uuid = (string) ($user['uuid'] ?? '');
        if ($id <= 0 || $uuid === '') {
            return null;
        }

        return new \ArrayObject([
            'id' => $this->nodeUserId($id),
            'uuid' => $uuid,
            'speed_limit' => 0,
            'device_limit' => (int) ($user['device_limit'] ?? 0),
        ], \ArrayObject::ARRAY_AS_PROPS);
    }

    public function nodeUserForSubscribe(array $user): ?\ArrayObject
    {
        $nodeUser = $this->nodeUserForAuth($user);
        if (!$nodeUser) {
            return null;
        }

        $nodeUser['email'] = (string) ($user['email'] ?? '');
        $nodeUser['token'] = (string) ($user['token'] ?? '');
        $nodeUser['transfer_enable'] = (int) ($user['transfer_enable'] ?? 0);
        $nodeUser['u'] = (int) ($user['u'] ?? 0);
        $nodeUser['d'] = (int) ($user['d'] ?? 0);
        $nodeUser['expired_at'] = (int) ($user['expired_at'] ?? 0);
        $nodeUser['uuid'] = $this->nodeAuthUuid($nodeUser);
        $nodeUser['auth_aliases'] = [];
        $nodeUser['device_limit'] = 0;
        return $nodeUser;
    }

    private function nodeAuthUuid(mixed $nodeUser): string
    {
        $uuid = app(NodeAuthAliasService::class)->slotUuid($nodeUser, 1);
        return $uuid !== '' ? $uuid : (string) data_get($nodeUser, 'uuid', '');
    }

    public function localServersForUser(mixed $nodeUser): array
    {
        // Local relay is intentionally disabled: upstream subscriptions must
        // expose upstream nodes directly instead of converting every line into
        // this panel server's egress network.
        return [];
    }

    /**
     * Nodes that are actually exposed in local-controlled upstream subscriptions.
     * Keep sync.users / sync.devices fan-out aligned with localServersForUser()
     * so upstream virtual users are not pushed to unrelated nodes.
     */
    public function localControlledNodeIds(): array
    {
        // Local-controlled upstream mode makes every upstream line exit through
        // this panel's own node network (for example 43.245.60.197). That breaks
        // the expected upstream-node egress and makes TikTok/YouTube/Google all
        // depend on the local Singapore server. Keep it disabled unless a future
        // explicit setting intentionally re-enables it.
        return [];
    }

    public function isLocalControlledNode(Server $node): bool
    {
        // Do not inject upstream virtual users into local nodes by default.
        // /upstream-s/... and merged subscriptions should use raw upstream URIs.
        return false;
    }

    public function processNodeTraffic(array $traffic, float|int $rate = 1): array
    {
        $updates = [];
        foreach ($traffic as $nodeUserId => $value) {
            $nodeUserId = (int) $nodeUserId;
            if (!$this->isNodeUserId($nodeUserId) || !is_array($value) || count($value) !== 2) {
                continue;
            }
            $updates[$this->upstreamUserIdFromNodeUserId($nodeUserId)] = [
                (int) (((float) $value[0]) * (float) $rate),
                (int) (((float) $value[1]) * (float) $rate),
            ];
        }

        if (empty($updates)) {
            return [];
        }

        $users = $this->upstreamUsers();
        $changed = [];
        foreach ($users as &$user) {
            $id = (int) ($user['id'] ?? 0);
            if (!isset($updates[$id])) {
                continue;
            }
            $user['u'] = (int) ($user['u'] ?? 0) + $updates[$id][0];
            $user['d'] = (int) ($user['d'] ?? 0) + $updates[$id][1];
            $user['updated_at'] = time();
            $changed[] = $id;
        }
        unset($user);

        if (!empty($changed)) {
            $this->saveUpstreamUsers($users);
        }

        return $changed;
    }

    public function upstreamOnlineCount(int $userId): int
    {
        $key = 'user_reported_devices:' . $this->nodeUserId($userId);
        $now = time();
        $devices = [];
        try {
            foreach (Redis::hgetall($key) ?: [] as $field => $timestamp) {
                if ($now - (int) $timestamp <= 300) {
                    $device = substr((string) $field, strpos((string) $field, ':') + 1);
                    $devices[$device] = true;
                }
            }
            return count($devices);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function deviceRedisKey(int $userId): string
    {
        return 'flyboard:upstream_user_devices:' . $userId;
    }

    public function trackUpstreamUserDevice(array $user, Request $request): true|string
    {
        $limit = (int) ($user['device_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }

        $userId = (int) ($user['id'] ?? 0);
        $key = $this->deviceRedisKey($userId);
        $now = time();
        // Subscription clients such as v2rayN may fetch the same URL several times with different
        // User-Agent values (browser preview, QR scan, import_sub update). Counting User-Agent as a
        // different device makes a valid subscription become a 403 plain-text error, which clients
        // report as “invalid subscription content”. For the independent upstream subscription gate,
        // count one source IP as one subscription device and keep this isolated from real node Redis.
        $fingerprint = sha1($request->ip() ?: '0.0.0.0');

        try {
            Redis::zremrangebyscore($key, '-inf', $now - 300);
            Redis::zadd($key, $now, $fingerprint);
            Redis::expire($key, 600);
            if ((int) Redis::zcard($key) > $limit) {
                Redis::zrem($key, $fingerprint);
                return '上游用户在线设备超过限制';
            }
        } catch (\Throwable $e) {
            return '上游用户设备检查失败';
        }

        return true;
    }

    public function subscriptionUserInfoHeader(array $user): string
    {
        return sprintf(
            'upload=%d; download=%d; total=%d; expire=%d',
            (int) ($user['u'] ?? 0),
            (int) ($user['d'] ?? 0),
            (int) ($user['transfer_enable'] ?? 0),
            (int) ($user['expired_at'] ?? 0)
        );
    }

    public function subscriptionCacheKey(int $userId): string
    {
        return 'flyboard:upstream_user_sub_cache:' . $userId;
    }

    public function readSubscriptionCache(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        try {
            $cached = Redis::hgetall($this->subscriptionCacheKey($userId));
            if (empty($cached['body'])) {
                return null;
            }

            return [
                'body' => (string) $cached['body'],
                'count' => (int) ($cached['count'] ?? 0),
                'useless_count' => (int) ($cached['useless_count'] ?? 0),
                'cached_at' => (int) ($cached['cached_at'] ?? 0),
                'bytes' => strlen((string) $cached['body']),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function writeSubscriptionCache(array $user, array $result): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        $lines = array_values(array_filter($result['useful_lines'] ?? []));
        if ($userId <= 0 || empty($lines)) {
            return null;
        }

        $plain = implode("
", $lines) . "
";
        $body = base64_encode($plain);
        $payload = [
            'body' => $body,
            'count' => count($lines),
            'useless_count' => (int) ($result['useless_count'] ?? 0),
            'cached_at' => time(),
        ];

        try {
            Redis::hmset($this->subscriptionCacheKey($userId), $payload);
            Redis::expire($this->subscriptionCacheKey($userId), 86400);
        } catch (\Throwable) {
            return null;
        }

        return [
            'body' => $body,
            'count' => $payload['count'],
            'useless_count' => $payload['useless_count'],
            'cached_at' => $payload['cached_at'],
            'bytes' => strlen($body),
        ];
    }

    public function profiles(): array
    {
        $profiles = $this->decodeProfiles(admin_setting('upstream_subscribe_profiles', []));
        if (!empty($profiles)) {
            return $profiles;
        }

        $legacyUrl = trim((string) admin_setting('upstream_subscribe_url', ''));
        if ($legacyUrl === '') {
            return [];
        }

        return [[
            'id' => 'legacy',
            'name' => '默认上游',
            'url' => $legacyUrl,
            'enabled' => true,
        ]];
    }

    public function enabledProfiles(): array
    {
        return array_values(array_filter($this->profiles(), fn ($profile) => !empty($profile['enabled']) && trim((string) ($profile['url'] ?? '')) !== ''));
    }

    public function normalizeProfiles(mixed $profiles): array
    {
        return $this->decodeProfiles($profiles);
    }

    private function decodeProfiles(mixed $profiles): array
    {
        if (is_string($profiles)) {
            $decoded = json_decode($profiles, true);
            $profiles = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($profiles)) {
            return [];
        }

        $result = [];
        foreach ($profiles as $idx => $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $url = trim((string) ($profile['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $result[] = [
                'id' => (string) ($profile['id'] ?? ('upstream_' . ($idx + 1))),
                'name' => trim((string) ($profile['name'] ?? ('上游 ' . ($idx + 1)))) ?: ('上游 ' . ($idx + 1)),
                'url' => $url,
                'enabled' => array_key_exists('enabled', $profile) ? (bool) $profile['enabled'] : true,
            ];
        }

        return array_slice($result, 0, 20);
    }

    public function fetchProfileForUser(array $profile, Request $request, mixed $user = null): array
    {
        $url = $this->buildUrl((string) ($profile['url'] ?? ''), $request, $user);
        $validation = $this->validateUrl($url);
        if ($validation !== true) {
            return ['ok' => false, 'message' => $validation];
        }

        $timeout = max(1, min((int) admin_setting('upstream_subscribe_timeout', 10), 30));
        $maxBytes = max(1024, min((int) admin_setting('upstream_subscribe_max_bytes', 524288), 1048576));
        $userAgent = trim((string) admin_setting('upstream_subscribe_user_agent', ''));

        try {
            $fetched = $this->fetchUrlWithStream(
                $url,
                $timeout,
                $userAgent !== '' ? $userAgent : ($request->userAgent() ?: 'Flyboard-UpstreamSubscribe/1.0')
            );
            if (isset($fetched['error'])) {
                return ['ok' => false, 'message' => '上游请求失败：' . $fetched['error']];
            }

            $status = (int) ($fetched['status'] ?? 0);
            $body = (string) ($fetched['body'] ?? '');
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'message' => '上游返回 HTTP ' . $status, 'status' => $status];
            }
            if ($body === '') {
                return ['ok' => false, 'message' => '上游订阅内容为空'];
            }
            if (strlen($body) > $maxBytes) {
                return ['ok' => false, 'message' => '上游订阅超过大小限制'];
            }

            return [
                'ok' => true,
                'message' => 'ok',
                'url' => $this->maskUrl($url),
                'status' => $status,
                'bytes' => strlen($body),
                'content_type' => $fetched['content_type'] ?? null,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '上游处理失败：' . $e->getMessage()];
        }
    }

    private function fetchUrlWithStream(string $url, int $timeout, string $userAgent): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: text/plain, application/octet-stream, */*',
                    'User-Agent: ' . $userAgent,
                ]),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            return ['error' => $error['message'] ?? '无法读取上游订阅'];
        }

        $status = 0;
        $contentType = null;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $headerLine, $matches)) {
                $status = (int) $matches[1];
            }
            if (stripos($headerLine, 'content-type:') === 0) {
                $contentType = trim(substr($headerLine, strlen('content-type:')));
            }
        }

        return [
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
        ];
    }

    private function buildUrl(string $template, Request $request, mixed $user = null): string
    {
        $replacements = [
            '{token}' => (string) data_get($user, 'token', $request->route('token', '')),
            '{user_id}' => (string) data_get($user, 'id', ''),
            '{email}' => rawurlencode((string) data_get($user, 'email', '')),
            '{uuid}' => (string) data_get($user, 'uuid', ''),
            '{flag}' => rawurlencode((string) ($request->input('flag') ?? $request->header('User-Agent', ''))),
        ];

        return strtr($template, $replacements);
    }

    private function validateUrl(string $url): true|string
    {
        if ($url === '') {
            return '未配置上游订阅 URL';
        }

        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return '上游订阅 URL 必须是 http(s) 地址';
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (empty($ips)) {
            return '上游订阅域名无法解析';
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return '上游订阅不能指向内网或保留地址';
            }
        }

        return true;
    }

    private function parseSubscriptionBody(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return ['format' => 'empty', 'decoded' => ''];
        }

        if ($this->looksStructured($trimmed)) {
            return ['format' => 'structured', 'decoded' => $body];
        }

        $decoded = base64_decode($this->normalizeBase64($trimmed), true);
        if (is_string($decoded) && $decoded !== '' && $this->hasUriLine($decoded)) {
            return ['format' => 'base64', 'decoded' => $decoded];
        }

        if ($this->hasUriLine($body)) {
            return ['format' => 'plain', 'decoded' => $body];
        }

        return ['format' => 'unknown', 'decoded' => $body];
    }

    private function looksStructured(string $body): bool
    {
        $head = ltrim($body);
        return str_starts_with($head, '{')
            || str_starts_with($head, '[')
            || preg_match('/^(proxies|proxy-groups|rules|mixed-port|port|dns|outbounds|inbounds):/mi', $head);
    }

    private function normalizeBase64(string $body): string
    {
        $clean = preg_replace('/\s+/', '', $body) ?? '';
        $clean = strtr($clean, '-_', '+/');
        $pad = strlen($clean) % 4;
        return $pad ? $clean . str_repeat('=', 4 - $pad) : $clean;
    }

    private function hasUriLine(string $body): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (preg_match(self::URI_PREFIX_PATTERN, trim($line))) {
                return true;
            }
        }
        return false;
    }

    private function extractUriLines(string $body): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $body) ?: [])
            ->map(fn($line) => trim((string) $line))
            ->filter(fn($line) => $line !== '' && preg_match(self::URI_PREFIX_PATTERN, $line))
            ->values()
            ->all();
    }

    private function filterUsefulUriLines(array $lines): array
    {
        return collect($lines)
            ->filter(fn ($line) => !$this->isUselessNodeLine((string) $line))
            ->values()
            ->all();
    }

    private function isUselessNodeLine(string $line): bool
    {
        $name = mb_strtolower($this->uriDisplayName($line));
        if ($name === '') {
            return false;
        }

        foreach ([
            '剩余流量', '已用流量', '套餐到期', '到期时间', '过期时间', '官网', '官方', '网站', '订阅', '更新', '重置流量', '流量重置',
            'traffic', 'expire', 'expires', 'expired', 'remaining', 'upload', 'download', 'total', 'reset', '官网地址', '用户信息', '账户信息',
        ] as $keyword) {
            if (str_contains($name, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function uriDisplayName(string $line): string
    {
        $fragment = parse_url($line, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            return rawurldecode($fragment);
        }

        $query = parse_url($line, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            foreach (['remarks', 'remark', 'peer', 'name'] as $key) {
                if (!empty($params[$key]) && is_string($params[$key])) {
                    return rawurldecode($params[$key]);
                }
            }
        }

        return '';
    }

    private function mergeLines(array $localLines, array $upstreamLines): array
    {
        $mode = admin_setting('upstream_subscribe_merge_mode', 'append');
        $lines = match ($mode) {
            'prepend' => array_merge($upstreamLines, $localLines),
            'replace' => $upstreamLines,
            default => array_merge($localLines, $upstreamLines),
        };

        return array_values(array_unique($lines));
    }

    private function publicProfile(array $profile): array
    {
        return [
            'id' => $profile['id'] ?? null,
            'name' => $profile['name'] ?? '上游',
            'enabled' => (bool) ($profile['enabled'] ?? true),
        ];
    }

    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach (['token', 'key', 'access_token', 'password', 'passwd'] as $key) {
            if (isset($query[$key])) {
                $query[$key] = '***';
            }
        }

        $masked = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $masked .= ':' . $parts['port'];
        }
        $masked .= $parts['path'] ?? '';
        $masked .= '?' . http_build_query($query);
        if (isset($parts['fragment'])) {
            $masked .= '#' . $parts['fragment'];
        }

        return $masked;
    }
}
