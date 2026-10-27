<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\FlyboardClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FlyboardClientController extends Controller
{
    public function subscribe(Request $request, string $token)
    {
        // This is a public subscription endpoint. Do not let the web
        // middleware turn bad query parameters into a redirect to `/`.
        $validation = Validator::make($request->query(), [
            'flag' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'in:base64,raw,clash'],
        ]);
        if ($validation->fails()) {
            return response()->json([
                'message' => 'invalid subscription parameters',
                'errors' => $validation->errors(),
            ], 422);
        }
        $client = DB::table('flyboard_clients')->where('token', $token)->first();
        if (!$client) return response('subscription not found', 404, ['Content-Type' => 'text/plain']);

        $service = app(FlyboardClientService::class);
        [$allowed, $reason] = $service->authorize($client, $request);
        if (!$allowed) {
            $service->log($client, $request, 'blocked', $reason);
            return response('subscription access blocked: ' . $reason, 403, ['Content-Type' => 'text/plain']);
        }
        $service->log($client, $request, 'allowed');

        $nodes = $service->nodesForClient($client);
        $flag = strtolower((string) ($request->input('flag') ?: $request->header('User-Agent', '')));
        $format = $request->input('format');
        if (!$format) $format = str_contains($flag, 'clash') || str_contains($flag, 'sing-box') ? 'raw' : 'base64';
        $body = $service->body($nodes, in_array($format, ['raw', 'clash'], true) ? 'raw' : 'base64');
        $used = (int) $client->traffic_used;
        $limit = (int) $client->traffic_limit;
        $expire = $client->expires_at ? strtotime($client->expires_at) : 0;

        return response($body)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('subscription-userinfo', "upload=0; download={$used}; total={$limit}; expire={$expire}")
            ->header('profile-update-interval', '24')
            ->header('profile-title', 'base64:' . base64_encode((string) $client->name));
    }

    public function qr(Request $request, string $token)
    {
        $client = DB::table('flyboard_clients')->where('token', $token)->first();
        if (!$client) abort(404);
        $url = app(FlyboardClientService::class)->subscriptionUrl($client);
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(360),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $svg = (new \BaconQrCode\Writer($renderer))->writeString($url);
        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
