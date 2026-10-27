<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    use QueryOperators;

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, '用户不存在']);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        $result = $user->save();

        if ($result) {
            HookManager::call('admin.user.secret.reset', [
                'user' => $user,
                'request' => $request,
            ]);
        }

        return $this->success($result);
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];
            $logic = strtolower($filter['logic'] ?? 'and');

            if ($logic === 'or') {
                $builder->orWhere(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            } else {
                $builder->where(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            }
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // 处理关联查询
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        // 处理数组值的 'in' 操作
        if (is_array($value)) {
            $query->whereIn($field === 'group_ids' ? 'group_id' : $field, $value);
            return;
        }

        // 处理基于运算符的过滤
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 转换数字字符串为适当的类型
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // 处理计算字段
        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            default => $field
        };

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }


    private function ensureUserWhitelistSchema(): void
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('flyboard_main_user_whitelist')) {
                DB::getSchemaBuilder()->create('flyboard_main_user_whitelist', function ($table) {
                    $table->unsignedBigInteger('user_id')->primary();
                    $table->boolean('enabled')->default(false);
                    $table->string('note', 500)->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }
            if (!DB::getSchemaBuilder()->hasTable('flyboard_main_user_whitelist_logs')) {
                DB::getSchemaBuilder()->create('flyboard_main_user_whitelist_logs', function ($table) {
                    $table->id();
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->unsignedBigInteger('admin_id')->nullable();
                    $table->string('action', 32);
                    $table->string('reason', 64)->nullable();
                    $table->string('ip', 64)->nullable();
                    $table->string('user_agent', 512)->nullable();
                    $table->string('path', 255)->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->index(['user_id', 'created_at'], 'fb_user_wl_logs_user_created_idx');
                    $table->index(['action', 'created_at'], 'fb_user_wl_logs_action_created_idx');
                });
            }
        } catch (\Throwable $e) {
            Log::warning('FlyBoard main user whitelist schema failed', ['error' => $e->getMessage()]);
        }
    }

    private function whitelistLog(Request $request, ?int $userId, string $action, ?string $reason = null): void
    {
        $this->ensureUserWhitelistSchema();
        try {
            DB::table('flyboard_main_user_whitelist_logs')->insert([
                'user_id' => $userId,
                'admin_id' => optional($request->user())->id,
                'action' => $action,
                'reason' => $reason,
                'ip' => substr((string) $request->ip(), 0, 64),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'path' => substr((string) $request->path(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('FlyBoard main user whitelist log failed', ['error' => $e->getMessage()]);
        }
    }

    private function whitelistMap(array $userIds): array
    {
        $this->ensureUserWhitelistSchema();
        if (empty($userIds)) {
            return [];
        }
        try {
            return DB::table('flyboard_main_user_whitelist')
                ->whereIn('user_id', $userIds)
                ->get()
                ->keyBy('user_id')
                ->map(fn ($row) => [
                    'enabled' => (bool) $row->enabled,
                    'note' => (string) ($row->note ?? ''),
                    'updated_at' => $row->updated_at,
                ])->all();
        } catch (\Throwable $e) {
            Log::warning('FlyBoard main user whitelist read failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function whitelistUpdate(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:App\\Models\\User,id',
            'enabled' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);
        $this->ensureUserWhitelistSchema();
        $enabled = $request->boolean('enabled');
        DB::table('flyboard_main_user_whitelist')->updateOrInsert(
            ['user_id' => (int) $data['id']],
            ['enabled' => $enabled ? 1 : 0, 'note' => trim((string) ($data['note'] ?? '')), 'updated_at' => now(), 'created_at' => now()]
        );
        $this->whitelistLog($request, (int) $data['id'], $enabled ? 'allowed' : 'disabled', $enabled ? 'main_user_whitelist' : 'main_user_whitelist_disabled');
        return $this->success(true);
    }

    public function whitelistLogs(Request $request)
    {
        $this->ensureUserWhitelistSchema();
        $limit = min(200, max(1, (int) $request->input('limit', 50)));
        $query = DB::table('flyboard_main_user_whitelist_logs as l')
            ->leftJoin('v2_user as u', 'u.id', '=', 'l.user_id')
            ->select('l.*', 'u.email')
            ->orderByDesc('l.id');
        if ($request->filled('user_id')) {
            $query->where('l.user_id', (int) $request->input('user_id'));
        }
        return $this->success($query->limit($limit)->get());
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $this->applyFiltersAndSorts($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $whitelist = $this->whitelistMap($users->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());
        $users->getCollection()->transform(function ($user) use ($whitelist): array {
            $data = self::transformUserData($user);
            $data['main_user_whitelist'] = $whitelist[(int) $user->id] ?? ['enabled' => false, 'note' => '', 'updated_at' => null];
            return $data;
        });

        return $this->paginate($users);
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'))->load('invite_user');
        $map = $this->whitelistMap([(int) $user->id]);
        $user->main_user_whitelist = $map[(int) $user->id] ?? ['enabled' => false, 'note' => '', 'updated_at' => null];
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        if (isset($params['email'])) {
            if (User::byEmail($params['email'])->first() && $user->email !== $params['email']) {
                return $this->fail([400201, '邮箱已被使用']);
            }
        }
        // 处理密码
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // 处理订阅计划
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->fail([400202, '订阅计划不存在']);
            }
            $params['group_id'] = $plan->group_id;
        }
        // 处理邀请用户
        if ($request->input('invite_user_email') && $inviteUser = User::byEmail($request->input('invite_user_email'))->first()) {
            $params['invite_user_id'] = $inviteUser->id;
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }

        $params = HookManager::filter('admin.user.update.params', $params, $request, $user);

        HookManager::call('admin.user.update.before', [
            'user' => $user,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            $user->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        ini_set('memory_limit', '-1');
        gc_enable(); // 启用垃圾回收

        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        // 优化查询：使用with预加载plan关系，避免N+1问题
        $query = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $this->applyFiltersAndSorts($request, $query);
        } // all: ignore filter/sort

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // 打开输出流
            $output = fopen('php://output', 'w');

            // 添加BOM标记，确保Excel正确显示中文
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 写入CSV头部
            fputcsv($output, [
                '邮箱',
                '余额',
                '推广佣金',
                '总流量',
                '剩余流量',
                '套餐到期时间',
                '订阅计划',
                '订阅地址'
            ]);

            // 分批处理数据以减少内存使用
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效',
                            $user->plan ? $user->plan->name : '无订阅',
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV导出错误: ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // 继续处理下一条记录
                    }
                }

                // 清理内存
                gc_collect_cycles();
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            // If generate_count is specified with email_prefix, generate multiple users with incremented emails
            if ($request->input('generate_count')) {
                return $this->multiGenerateWithPrefix($request);
            }
            
            // Single user generation with email_prefix
            $email = $request->input('email_prefix') . '@' . $request->input('email_suffix');

            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, '邮箱已存在于系统中']);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, '生成失败']);
            }
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $email = Helper::randomChar(6) . '@' . $request->input('email_suffix');
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }



        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    private function multiGenerateWithPrefix(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];
        $emailPrefix = $request->input('email_prefix');
        $emailSuffix = $request->input('email_suffix');
        $generateCount = $request->input('generate_count');

        // Check if any of the emails with prefix already exist
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            if (User::where('email', $email)->exists()) {
                return $this->fail([400201, '邮箱 ' . $email . ' 已存在于系统中']);
            }
        }

        // Generate user data for batch creation
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }

        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        ini_set('memory_limit', '-1');
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: apply filters/sort
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;

        $builder->chunk($chunkSize, function ($users) use ($subject, $content, $appName, $appUrl) {
            foreach ($users as $user) {
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $user->plan?->name ?? '',
                    'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                    'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                    'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                    'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
                ];

                $templateValue = [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    'vars' => $vars,
                    'content_mode' => 'text',
                ];

                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: keep current semantics
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        try {
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '处理失败']);
        }
        // Full refresh not implemented.
        return $this->success(true);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在'
        ]);
        $user = User::find($request->input('id'));
        HookManager::call('admin.user.destroy.before', [
            'user' => $user,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();

            HookManager::call('admin.user.destroy.after', [
                'user' => $user,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }
    }
}
