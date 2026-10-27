<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use App\Services\FlyboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/



function xboard_panel_whitelist_guard(Request $request): void
{
    $service = app(FlyboardService::class);
    if (!$service->panelAllowed($request)) {
        $service->log($request->user(), $request, $service->fingerprint($request), 'blocked', 'xboard_panel_whitelist');
        abort(403, 'XBoard panel access denied');
    }
}

Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function (Request $request) {
    xboard_panel_whitelist_guard($request);
    return response()->view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
});


// Compatibility for older injected whitelist UI versions that called /{secure_path}/api/v2/admin/flyboard/panel-whitelist.
// This only exposes the XBoard main panel whitelist configuration endpoints and is protected by normal admin auth.
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/api/v2/admin/flyboard/panel-whitelist', [\App\Http\Controllers\V2\Admin\FlyboardController::class, 'panelWhitelistStatus'])
    ->middleware(['xboard.panel.whitelist', 'admin', 'log']);
Route::post('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/api/v2/admin/flyboard/panel-whitelist', [\App\Http\Controllers\V2\Admin\FlyboardController::class, 'savePanelWhitelist'])
    ->middleware(['xboard.panel.whitelist', 'admin', 'log']);

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');

// FlyBoard shell only; all data/actions require the existing admin middleware.
Route::get('/flyboard', function (Request $request) {
    xboard_panel_whitelist_guard($request);
    return response()->view('flyboard', [
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
    ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
});

// Independent, high-entropy bearer-token client subscriptions; main subscriptions unchanged.
Route::get('/flyboard/sub/{token}/qr', [\App\Http\Controllers\V1\Client\FlyboardClientController::class, 'qr'])->middleware('throttle:60,1');
Route::get('/flyboard/sub/{token}', [\App\Http\Controllers\V1\Client\FlyboardClientController::class, 'subscribe'])->middleware('throttle:60,1');
