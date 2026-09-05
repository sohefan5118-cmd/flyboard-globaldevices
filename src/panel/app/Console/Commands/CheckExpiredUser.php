<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckExpiredUser extends Command
{
    protected $signature = 'check:expired-user';

    protected $description = '过期用户失效任务';

    public function handle()
    {
        ini_set('memory_limit', -1);

        $now = time();
        $count = 0;

        User::query()
            ->select(['id', 'group_id', 'expired_at'])
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', 0)
            ->where('expired_at', '<=', $now)
            ->chunkById(200, function ($users) use (&$count) {
                $deviceStateService = app(DeviceStateService::class);
                $affectedGroups = [];

                foreach ($users as $user) {
                    Redis::del('user_devices:' . $user->id);
                    Redis::del('user_reported_devices:' . $user->id);
                    Redis::del('user_device_auth_slots:' . $user->id);

                    $deviceStateService->notifyUpdate((int) $user->id);
                    if (!empty($user->group_id)) {
                        NodeSyncService::notifyUserRemovedFromGroup((int) $user->id, (int) $user->group_id);
                        $affectedGroups[(int) $user->group_id] = true;
                    }
                    $count++;
                }

                foreach (array_keys($affectedGroups) as $groupId) {
                    NodeSyncService::notifyUsersUpdatedByGroup((int) $groupId);
                }
            });

        $this->info("已清理并失效 {$count} 位到期用户");
    }
}
