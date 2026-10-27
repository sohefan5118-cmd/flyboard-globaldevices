<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeviceStateService;
use Illuminate\Console\Command;

class CleanupOnlineStatus extends Command
{
    protected $signature = 'cleanup:online-status';

    protected $description = 'Sync/reset online_count using DeviceStateService display state';

    public function handle(): void
    {
        $service = app(DeviceStateService::class);
        $checked = 0;
        $synced = 0;
        $cleared = 0;

        User::query()
            ->where(function ($query) {
                $query->where('online_count', '>', 0)
                    ->orWhere('last_online_at', '>=', now()->subHours(6));
            })
            ->select(['id', 'online_count', 'last_online_at'])
            ->chunkById(200, function ($users) use ($service, &$checked, &$synced, &$cleared) {
                $ids = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
                $devicesByUser = $service->getUsersDisplayDevices($ids);

                foreach ($users as $user) {
                    $checked++;
                    $count = count($devicesByUser[(int) $user->id] ?? []);
                    $current = (int) ($user->online_count ?? 0);

                    if ($count > 0) {
                        if ($current !== $count) {
                            User::query()->whereKey($user->id)->update([
                                'online_count' => $count,
                                'last_online_at' => now(),
                            ]);
                            $synced++;
                        }
                        continue;
                    }

                    if ($current > 0 && ($user->last_online_at === null || $user->last_online_at < now()->subMinutes(10))) {
                        User::query()->whereKey($user->id)->update(['online_count' => 0]);
                        $cleared++;
                    }
                }
            });

        if ($synced > 0 || $cleared > 0) {
            $this->info("Checked {$checked} users; synced {$synced}; cleared {$cleared}.");
        }
    }
}
