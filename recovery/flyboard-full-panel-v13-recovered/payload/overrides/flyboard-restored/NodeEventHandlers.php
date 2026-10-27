<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    // Empty/missing snapshots can be emitted while a phone toggles airplane mode
    // or the node reporter races with live traffic. They are not proof that users
    // are permanently offline until repeated. Require consecutive evidence before
    // starting stale grace, otherwise normal report jitter can let another device
    // steal the active slot.
    private const EMPTY_OFFLINE_THRESHOLD = 2;
    private const MISSING_OFFLINE_THRESHOLD = 2;

    /** @var array<int, int> consecutive empty reports by node */
    private static array $emptyReports = [];

    /** @var array<string, int> consecutive reports omitting node:user */
    private static array $missingReports = [];

    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node.
     *
     * Supported data shapes:
     * - {userId: [ip1, ip2, ...], ...}
     * - {devices: {userId: [...]}}
     * - {users: {userId: [...]}}
     * - {alive: {userId: [...]}}
     * - {data: {users/devices/alive: {...}}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);

        $report = $data;
        $reportWrapper = null;
        if (isset($report['data']) && is_array($report['data'])) {
            $report = $report['data'];
            $reportWrapper = 'data';
        }
        foreach (['users', 'devices', 'alive'] as $wrapper) {
            if (isset($report[$wrapper]) && is_array($report[$wrapper])) {
                $report = $report[$wrapper];
                $reportWrapper = $reportWrapper ? $reportWrapper . '.' . $wrapper : $wrapper;
                break;
            }
        }

        $oldDevices = $service->getNodeDevices($nodeId);
        $newDevices = [];
        foreach ($report as $userId => $ips) {
            if (!is_numeric($userId)) continue;
            if (is_string($ips)) $ips = [$ips];
            if (!is_array($ips)) continue;
            $ips = array_values(array_filter($ips, 'is_string'));
            if (!empty($ips)) {
                $newDevices[(int) $userId] = $ips;
                $service->markNodeUserReportSeen($nodeId, (int) $userId);
            }
        }

        if ($reportWrapper !== null) {
            Log::debug("[WS] Node#{$nodeId} device report unwrapped {$reportWrapper}");
        }

        if (empty($newDevices)) {
            self::$emptyReports[$nodeId] = (self::$emptyReports[$nodeId] ?? 0) + 1;
            $emptyCount = self::$emptyReports[$nodeId];

            // Do not refresh old active claims on empty reports. Also do
            // not mark stale on the first empty snapshot: require repeated
            // evidence so reporter jitter cannot start the steal window.
            $affected = 0;
            if ($emptyCount >= self::EMPTY_OFFLINE_THRESHOLD) {
                foreach (array_keys($oldDevices) as $userId) {
                    if ($service->markNodeDevicesStale($nodeId, (int) $userId, false)) {
                        $affected++;
                    }
                }
            }

            foreach (NodeRegistry::getConnectedNodeIds() as $targetNodeId) {
                self::pushDeviceStateToNode((int) $targetNodeId, $service);
            }

            Log::debug("[WS] Node#{$nodeId} empty device snapshot marked stale ({$emptyCount}/" . self::EMPTY_OFFLINE_THRESHOLD . "), users={$affected}");
            return;
        }

        self::$emptyReports[$nodeId] = 0;

        // Users omitted from a non-empty node report are offline candidates, not
        // deleted immediately. Mark stale once so the old IP can expire, while a
        // later non-empty report for the same user restores active ownership.
        foreach (array_keys($oldDevices) as $userId) {
            $missingKey = "{$nodeId}:{$userId}";
            if (array_key_exists($userId, $newDevices)) {
                unset(self::$missingReports[$missingKey]);
            } else {
                self::$missingReports[$missingKey] = (self::$missingReports[$missingKey] ?? 0) + 1;
                if (self::$missingReports[$missingKey] >= self::MISSING_OFFLINE_THRESHOLD) {
                    $service->markNodeDevicesStale($nodeId, (int) $userId, false);
                }
            }
        }

        $rejected = [];
        foreach ($newDevices as $userId => $ips) {
            if (!$service->setDevices($userId, $nodeId, $ips)) {
                $rejected[] = $userId;
            }
        }

        // Push current global device state to every connected node, not only
        // the reporting node. Device limits are shared across A/B/C nodes.
        foreach (NodeRegistry::getConnectedNodeIds() as $targetNodeId) {
            self::pushDeviceStateToNode((int) $targetNodeId, $service);
        }

        Log::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, missing " . count(self::$missingReports) . ", rejected " . count(array_unique($rejected)));
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices,
        ]);

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Push device state to node
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    /**
     * Push full config + users to newly connected node
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;

        // Push config
        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        // Push users
        $userCollection = ServerService::getAvailableUsers($node);
        $users = $userCollection->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }
}
