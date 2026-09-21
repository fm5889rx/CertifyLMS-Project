<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================================
// Sanctum Cookie 認証ルート（S-A-05）
// ============================================================
use App\Http\Controllers\Api\v1\NotificationApiController;

Route::prefix('v1')->group(function () {
// auth:sanctum ミドルウェアの鎖で縛ることで、ログインしていない不正アクセスを401でシャットアウト
    Route::middleware(['auth:sanctum'])->group(function () {
        // 1. 通知一覧の非同期取得
        Route::get('/notifications', [NotificationApiController::class, 'index'])
            ->name('api.notifications.index');

        // 2. 単一通知の既読化（仕様書規約：/api/v1/notifications/{notification}/read）
        Route::post('/notifications/{id}/read', [NotificationApiController::class, 'markAsRead'])
            ->name('api.notifications.read');

        // 3. 全件既読化（仕様書規約：/api/v1/notifications/read-all）
        Route::post('/notifications/read-all', [NotificationApiController::class, 'markAllAsRead'])
            ->name('api.notifications.read_all');
    });
});
