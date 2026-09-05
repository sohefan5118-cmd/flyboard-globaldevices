<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\Client\ClientController;
use App\Services\Flyboard\UpstreamSubscribeService;
use App\Services\Flyboard\NodeAuthAliasService;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class UpstreamSubscribeController extends Controller
{
    public function profiles(UpstreamSubscribeService $service)
    {
        return $this->success([
            'profiles' => $service->profiles(),
            'enabled_profiles' => $service->enabledProfiles(),
        ]);
    }

    public function saveProfiles(Request $request, UpstreamSubscribeService $service)
    {
        $validated = $request->validate([
            'profiles' => 'nullable|array|max:20',
            'profiles.*.id' => 'nullable|string|max:80',
            'profiles.*.name' => 'nullable|string|max:120',
            'profiles.*.url' => 'required|string|max:2048',
            'profiles.*.enabled' => 'nullable|boolean',
        ]);

        $profiles = $service->normalizeProfiles($validated['profiles'] ?? []);
        admin_setting(['upstream_subscribe_profiles' => $profiles]);
        if (!empty($profiles)) {
            admin_setting(['upstream_subscribe_url' => $profiles[0]['url']]);
        }

        return $this->success(['profiles' => $profiles]);
    }

    public function users(Request $request, UpstreamSubscribeService $service)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(1, min((int) $request->input('pageSize', 10), 50));
        $keyword = trim((string) $request->input('keyword', ''));

        $users = $service->upstreamUsers();
        if ($keyword !== '') {
            $users = array_values(array_filter($users, function ($user) use ($keyword) {
                return (string) ($user['id'] ?? '') === $keyword
                    || str_contains(strtolower((string) ($user['email'] ?? '')), strtolower($keyword));
            }));
        }

        usort($users, fn ($a, $b) => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        $total = count($users);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $current = min($current, $lastPage);
        $rows = array_slice($users, ($current - 1) * $pageSize, $pageSize);
        $rows = array_map(fn ($user) => $this->publicUser($user, $request, $service), $rows);

        return $this->success([
            'data' => $rows,
            'total' => $total,
            'current_page' => $current,
            'last_page' => $lastPage,
            'per_page' => $pageSize,
        ]);
    }

    public function saveUser(Request $request, UpstreamSubscribeService $service)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'email' => 'required|email|max:190',
            'password' => 'nullable|string|min:6|max:190',
            'transfer_enable' => 'nullable|numeric|min:0',
            'u' => 'nullable|numeric|min:0',
            'd' => 'nullable|numeric|min:0',
            'expired_at' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0|max:255',
            'banned' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:500',
        ]);

        $users = $service->upstreamUsers();
        $id = (int) ($data['id'] ?? 0);
        $idx = null;
        foreach ($users as $i => $user) {
            if ((int) ($user['id'] ?? 0) === $id) {
                $idx = $i;
                break;
            }
        }

        foreach ($users as $i => $user) {
            if ($idx !== $i && strtolower((string) ($user['email'] ?? '')) === strtolower($data['email'])) {
                return $this->fail([422, '上游用户邮箱已存在']);
            }
        }

        $now = time();
        $gb = 1024 * 1024 * 1024;
        $payload = [
            'email' => $data['email'],
            'transfer_enable' => (int) round(((float) ($data['transfer_enable'] ?? 100)) * $gb),
            'u' => (int) round(((float) ($data['u'] ?? 0)) * $gb),
            'd' => (int) round(((float) ($data['d'] ?? 0)) * $gb),
            'expired_at' => (int) ($data['expired_at'] ?? 0),
            'device_limit' => (int) ($data['device_limit'] ?? 1),
            'banned' => !empty($data['banned']) ? 1 : 0,
            'remarks' => (string) ($data['remarks'] ?? ''),
            'updated_at' => $now,
        ];

        if ($idx === null) {
            $nextId = empty($users) ? 1 : (max(array_map(fn ($u) => (int) ($u['id'] ?? 0), $users)) + 1);
            $payload = array_merge($payload, [
                'id' => $nextId,
                'token' => Str::random(32),
                'uuid' => (string) Str::uuid(),
                'created_at' => $now,
            ]);
            if (!empty($data['password'])) {
                $payload['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $users[] = $payload;
        } else {
            $payload = array_merge($users[$idx], $payload);
            if (!empty($data['password'])) {
                $payload['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $users[$idx] = $payload;
        }

        $service->saveUpstreamUsers($users);
        NodeSyncService::notifyUpstreamUsersUpdated();
        return $this->success($this->publicUser($payload, $request, $service));
    }

    public function deleteUser(Request $request, UpstreamSubscribeService $service)
    {
        $id = (int) $request->input('id');
        $users = array_values(array_filter($service->upstreamUsers(), fn ($user) => (int) ($user['id'] ?? 0) !== $id));
        $service->saveUpstreamUsers($users);
        Redis::del($service->deviceRedisKey($id));
        Redis::del('user_devices:' . $service->nodeUserId($id));
        Redis::del('user_reported_devices:' . $service->nodeUserId($id));
        Redis::del('user_device_auth_slots:' . $service->nodeUserId($id));
        NodeSyncService::notifyUpstreamUsersUpdated();
        return $this->success(true);
    }

    public function resetUserToken(Request $request, UpstreamSubscribeService $service)
    {
        $id = (int) $request->input('id');
        $users = $service->upstreamUsers();
        foreach ($users as &$user) {
            if ((int) ($user['id'] ?? 0) === $id) {
                $user['token'] = Str::random(32);
                $user['uuid'] = (string) Str::uuid();
                $user['updated_at'] = time();
                $service->saveUpstreamUsers($users);
                Redis::del($service->deviceRedisKey($id));
                Redis::del('user_devices:' . $service->nodeUserId($id));
                Redis::del('user_reported_devices:' . $service->nodeUserId($id));
                Redis::del('user_device_auth_slots:' . $service->nodeUserId($id));
                NodeSyncService::notifyUpstreamUsersUpdated();
                return $this->success($this->publicUser($user, $request, $service));
            }
        }

        return $this->fail([422, '上游用户不存在']);
    }

    public function test(Request $request, UpstreamSubscribeService $service)
    {
        $user = null;
        if ($request->filled('upstream_user_id') || $request->filled('user_id')) {
            $user = $service->findUpstreamUser((int) ($request->input('upstream_user_id') ?: $request->input('user_id')));
            if (!$user) {
                return $this->fail([422, '上游测试用户不存在']);
            }
            $available = $service->checkUpstreamUserAvailable($user);
            if ($available !== true) {
                return $this->fail([422, $available]);
            }
        }

        $result = $service->preview($request, $user);

        if (!($result['ok'] ?? false)) {
            return $this->fail([422, $result['message'] ?? '上游订阅拉取失败'], $result);
        }

        return $this->success($result);
    }

    public function subscribe(Request $request, string $token, UpstreamSubscribeService $service)
    {
        $user = $service->findUpstreamUserByToken($token);
        if (!$user) {
            return response('upstream user not found', 404, ['Content-Type' => 'text/plain']);
        }

        $available = $service->checkUpstreamUserAvailable($user);
        if ($available !== true) {
            return $this->emptySubscriptionResponse($user, $service, 'unavailable', (string) $available);
        }

        $deviceCheck = $service->trackUpstreamUserDevice($user, $request);
        if ($deviceCheck !== true) {
            return $this->emptySubscriptionResponse($user, $service, 'device-limit', (string) $deviceCheck);
        }

        $result = $service->fetchAllForUser($request, $user);
        $lines = array_values(array_filter($result['useful_lines'] ?? []));
        if (empty($lines)) {
            return response('no available upstream nodes', 502, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Subscription-Userinfo' => $service->subscriptionUserInfoHeader($user),
                'X-Flyboard-Upstream-Subscribe' => 'fetch-empty',
                'X-Flyboard-Upstream-Error' => Str::limit(implode('; ', $result['errors'] ?? []), 120, ''),
            ]);
        }

        $body = base64_encode(implode("\r\n", $lines) . "\r\n");
        $response = response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
        $response->headers->set('Subscription-Userinfo', $service->subscriptionUserInfoHeader($user));
        $response->headers->set('X-Flyboard-Upstream-Subscribe', 'raw-upstream');
        $response->headers->set('X-Flyboard-Upstream-Count', (string) count($lines));
        $response->headers->set('X-Flyboard-Upstream-Sources', (string) count($result['profiles'] ?? []));
        return $response;
    }

    private function emptySubscriptionResponse(array $user, UpstreamSubscribeService $service, string $reason, string $message)
    {
        // v2rayN and similar clients can keep old local nodes when a subscription update
        // returns a plain-text error. Return a valid empty base64 subscription instead,
        // so expired/banned/over-limit upstream users clear client-side nodes on update.
        return response(base64_encode("
"), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Subscription-Userinfo' => $service->subscriptionUserInfoHeader($user),
            'X-Flyboard-Upstream-Subscribe' => 'independent-user-empty',
            'X-Flyboard-Upstream-Count' => '0',
            'X-Flyboard-Upstream-Blocked-Reason' => $reason,
            'X-Flyboard-Upstream-Blocked-Message' => Str::limit($message, 120, ''),
        ]);
    }

    public function userPage(Request $request, string $token, UpstreamSubscribeService $service)
    {
        $user = $service->findUpstreamUserByToken($token);
        if (!$user) {
            abort(404, 'upstream user not found');
        }

        $subscribeUrl = $request->getSchemeAndHttpHost() . '/upstream-s/' . $token;
        $available = $service->checkUpstreamUserAvailable($user);
        $usedGb = round((((int) ($user['u'] ?? 0)) + ((int) ($user['d'] ?? 0))) / 1024 / 1024 / 1024, 2);
        $total = (int) ($user['transfer_enable'] ?? 0);
        $totalGb = $total > 0 ? round($total / 1024 / 1024 / 1024, 2) : 0;
        $expiredAt = (int) ($user['expired_at'] ?? 0);

        return view('upstream-subscribe-user', [
            'app_name' => admin_setting('app_name', 'Flyboard'),
            'user' => $user,
            'available' => $available,
            'subscribe_url' => $subscribeUrl,
            'qr_url' => $request->getSchemeAndHttpHost() . '/upstream-s/' . $token . '/qr.svg',
            'used_gb' => $usedGb,
            'total_gb' => $totalGb,
            'expire_text' => $expiredAt > 0 ? date('Y-m-d H:i:s', $expiredAt) : '不限',
            'online_count' => $service->upstreamOnlineCount((int) ($user['id'] ?? 0)),
        ]);
    }

    public function qr(Request $request, string $token, UpstreamSubscribeService $service)
    {
        $user = $service->findUpstreamUserByToken($token);
        if (!$user) {
            return response('upstream user not found', 404, ['Content-Type' => 'text/plain']);
        }

        $subscribeUrl = $request->getSchemeAndHttpHost() . '/upstream-s/' . $token;
        $renderer = new ImageRenderer(new RendererStyle(360, 2), new SvgImageBackEnd());
        $writer = new Writer($renderer);
        $svg = $writer->writeString($subscribeUrl);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function publicUser(array $user, Request $request, UpstreamSubscribeService $service): array
    {
        $id = (int) ($user['id'] ?? 0);
        $token = (string) ($user['token'] ?? '');
        return [
            'id' => $id,
            'email' => $user['email'] ?? '',
            'transfer_enable' => (int) ($user['transfer_enable'] ?? 0),
            'u' => (int) ($user['u'] ?? 0),
            'd' => (int) ($user['d'] ?? 0),
            'expired_at' => (int) ($user['expired_at'] ?? 0),
            'device_limit' => (int) ($user['device_limit'] ?? 0),
            'online_count' => $service->upstreamOnlineCount($id),
            'banned' => (int) ($user['banned'] ?? 0),
            'remarks' => $user['remarks'] ?? '',
            'token' => $token,
            'uuid' => $user['uuid'] ?? '',
            'subscribe_url' => $token !== '' ? $request->getSchemeAndHttpHost() . '/upstream-s/' . $token : '',
            'created_at' => (int) ($user['created_at'] ?? 0),
            'updated_at' => (int) ($user['updated_at'] ?? 0),
        ];
    }
}
