<?php

namespace App\Http\Middleware;

use App\Services\FlyboardService;
use Closure;

class XBoardPanelWhitelist
{
    public function handle($request, Closure $next)
    {
        $service = app(FlyboardService::class);
        if (!$service->panelAllowed($request)) {
            $service->log($request->user(), $request, $service->fingerprint($request), 'blocked', 'xboard_panel_whitelist');
            return response()->json(['message' => 'XBoard panel access denied'], 403);
        }
        return $next($request);
    }
}
