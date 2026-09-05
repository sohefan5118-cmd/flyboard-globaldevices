<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V2\Admin\UpstreamSubscribeController;
use App\Models\User;
use App\Services\Flyboard\UpstreamSubscribeService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    public function testExpiredUserIsNotAvailable(): void
    {
        $user = new User([
            'banned' => 0,
            'transfer_enable' => 1,
            'expired_at' => time() - 60,
        ]);

        $this->assertFalse((new UserService())->isAvailable($user));
    }

    public function testActiveUserIsAvailable(): void
    {
        $user = new User([
            'banned' => 0,
            'transfer_enable' => 1,
            'expired_at' => time() + 3600,
        ]);

        $this->assertTrue((new UserService())->isAvailable($user));
    }

    public function testExpiredUserCannotSubscribe(): void
    {
        $user = new User([
            'banned' => 0,
            'transfer_enable' => 1,
            'expired_at' => time() - 60,
        ]);

        $request = Request::create('/s/test-token', 'GET');
        $request->user = $user;

        $response = app(ClientController::class)->subscribe($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('account expired', $response->getContent());
    }

    public function testExpiredUpstreamUserGetsEmptySubscription(): void
    {
        $user = [
            'id' => 101,
            'token' => 'expired-token',
            'banned' => 0,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() - 60,
            'device_limit' => 1,
            'remarks' => '',
        ];

        $service = $this->createMock(UpstreamSubscribeService::class);
        $service->method('findUpstreamUserByToken')->with('expired-token')->willReturn($user);
        $service->method('checkUpstreamUserAvailable')->with($user)->willReturn('上游用户已到期');

        $request = Request::create('/upstream-s/expired-token', 'GET');

        $response = app(UpstreamSubscribeController::class)->subscribe($request, 'expired-token', $service);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('independent-user-empty', $response->headers->get('X-Flyboard-Upstream-Subscribe'));
        $this->assertSame('unavailable', $response->headers->get('X-Flyboard-Upstream-Blocked-Reason'));
        $this->assertSame(base64_encode("\n"), $response->getContent());
    }
}
