<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 通知 JSON API コントローラー（S-A-05完全適合・最終解決版）
 */
class NotificationApiController extends Controller
{
    /**
     * 1. 認証ユーザー本人の通知一覧を取得 (GET /api/v1/notifications)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // 管理者は空配列を200成功で返す仕様に完全準拠
        if ($user->role === UserRole::Admin) {
            return response()->json([
                'notifications' => [], 
                'unread_count' => 0
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'tab' => 'nullable|string|in:all,unread',
            'per_page' => 'nullable|integer|between:1,50',
        ], [
            'tab.in' => 'タブ識別子は「all」または「unread」のいずれかを指定してください。',
            'per_page.between' => '1ページあたりの件数は1〜50の整数で指定してください。',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tab = $request->query('tab', 'all');
        $perPage = (int) $request->query('per_page', 20);

        $query = $user->notifications();
        if ($tab === 'unread') {
            $query = $user->unreadNotifications();
        }

        $notifications = $query->orderBy('created_at', 'desc')->take($perPage)->get();
        $unreadCount = $user->unreadNotifications()->count();

        // テストコード(assertJsonStructure)が要求する「本物のキー構造」へ100%完全同期
        $formattedNotifications = $notifications->map(function (DatabaseNotification $notification) {
            $data = is_array($notification->data) ? $notification->data : [];

            $actionUrl = $data['action_url'] ?? $data['redirect_url'] ?? route('notifications.show', $notification->id);

            $rowDate = $notification->created_at;
            $humanTime = '';
            if ($rowDate) {
                $humanTime = Carbon::parse($rowDate)->diffForHumans();
            }

            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? '通知',
                'message' => $data['message'] ?? ($data['body'] ?? $data['body_preview'] ?? ''),
                'time' => $humanTime,
                'is_unread' => $notification->read_at === null,
                'action_url' => $actionUrl,
            ];
        });

        return response()->json([
            'notifications' => $formattedNotifications,
            'unread_count' => $unreadCount,
        ], 200);
    }

    /**
     * 2. 単一通知の既読化 (POST /api/v1/notifications/{id}/read)
     * 
     * 💡【最重要解決】api.php のワイルドカード定義「{id}」と引数変数を1文字違わず完全同期！
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // 💡 引数の $id を用いて通知データを正しくピッキング
        $notification = DatabaseNotification::find($id);
        if (! $notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        // 他者通知のアクセスを 403 で厳格に弾き落とす防衛線
        if ($notification->notifiable_id !== $user->id || $notification->notifiable_type !== get_class($user)) {
            return response()->json(['error' => 'This action is unauthorized.'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'unread_count' => $user->unreadNotifications()->count(),
        ], 200);
    }

    /**
     * 3. 認証ユーザー本人の通知を全件一括既読化 (POST /api/v1/notifications/read-all)
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user->unreadNotifications()->get()->markAsRead();

        return response()->json([
            'status' => 'success',
            'unread_count' => 0,
        ], 200);
    }
}
