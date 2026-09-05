<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminIpWhitelist
{
    /**
     * Restrict management entry points to configured IP/CIDR entries.
     *
     * This middleware is intentionally attached only to admin web/API routes;
     * subscription routes and server/node routes must remain public.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!(bool) admin_setting('admin_ip_whitelist_enable', false)) {
            return $next($request);
        }

        $clientIp = $request->getClientIp();
        $entries = $this->entries(admin_setting('admin_ip_whitelist_entries', []));

        if ($this->isAllowed($clientIp, $entries)) {
            return $next($request);
        }

        $this->logBlocked($request, $clientIp, $entries);

        return response()->json([
            'message' => '当前 IP 不在后台白名单中',
        ], Response::HTTP_FORBIDDEN);
    }

    private function entries($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,，\s]+/', (string) $value) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $items), function ($item) {
            return $item !== '' && !$this->isComment($item);
        })));
    }

    private function isComment(string $entry): bool
    {
        return str_starts_with($entry, '#') || str_starts_with($entry, '//');
    }

    private function isAllowed(?string $ip, array $entries): bool
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Local loopback remains allowed as a safety valve for same-host maintenance.
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach ($entries as $entry) {
            if ($this->matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $ip, string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return $ip === $entry;
        }

        if (!str_contains($entry, '/')) {
            return false;
        }

        [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $network = trim((string) $network);
        $prefix = trim((string) $prefix);

        if (!ctype_digit($prefix) || !filter_var($network, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);
        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        $prefixLength = (int) $prefix;
        if ($prefixLength < 0 || $prefixLength > $bits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }

    private function hasLogTable(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('v2_log');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function logBlocked(Request $request, ?string $clientIp, array $entries): void
    {
        try {
            $context = [
                'client_ip' => $clientIp,
                'path' => $request->path(),
                'method' => $request->method(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'allowed_entries' => $entries,
                'xff' => $request->header('X-Forwarded-For'),
                'host' => $request->getHost(),
            ];

            if ($this->hasLogTable()) {
                DB::table('v2_log')->insert([
                    'title' => 'Admin IP whitelist blocked request',
                    'level' => 'warning',
                    'host' => $request->getHost(),
                    'uri' => '/' . ltrim($request->path(), '/'),
                    'method' => $request->method(),
                    'data' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ip' => $clientIp,
                    'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                Log::warning('Admin IP whitelist blocked request', $context);
            }
        } catch (\Throwable $e) {
            // Never let logging failure affect access-control response.
        }
    }
}
