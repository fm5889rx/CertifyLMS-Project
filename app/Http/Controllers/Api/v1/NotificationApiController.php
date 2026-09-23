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
 * 通知 JSON API コントローラー（S-A-05改修適合版）
 *【S-A-05 要件適合：422日本語エラー・403他者遮断・管理者空状態一貫仕様】
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

        // 仕様書適合：「管理者は対象外（受信側ではない）」のため、空配列を安全に200成功リターン
        if ($user->role === UserRole::Admin) {
            return response()->json(['notifications' => [], 'unread_count' => 0], 200);
        }

        // 422 日本語エラーレスポンスバリデーションの追加
        // クエリパラメータの tab（all,unread）および per_page（1〜50の整数）をチェックし、
        // 違反時は 422 + JSON エラー構造体を返却する
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

        // タブ識別子に応じて、クエリのスコープを動的に切り替え解決
        $query = $user->notifications();
        if ($tab === 'unread') {
            $query = $user->unreadNotifications();
        }

        $notifications = $query->orderBy('created_at', 'desc')->take($perPage)->get();
        $unreadCount = $user->unreadNotifications()->count();

        // JS フロントが1発でアサーション描画できる整形フィールドの射出
        $formattedNotifications = $notifications->map(function (DatabaseNotification $notification) {
            $data = is_array($notification->data) ? $notification->data : [];

            // お知らせ通知（業務画面を持たないもの）の場合は、詳細画面(/notifications/{id})へ誘導
            $actionUrl = $data['action_url'] ?? (route('notifications.show', $notification->id) ?? '#');

            // 日付データを Carbon 化する
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
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // 他者通知 ID 指定時の 403 エラー対応
        // 物理層から通知レコードそのものの存在を確認し、なければエラーレスポンスを返す
        $notification = DatabaseNotification::find($id);
        if (! $notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        // 認証ユーザー本人の通知ではない「他人の通知ID」が指定された瞬間、403 Forbiddenを返す
        if ($notification->notifiable_id !== $user->id || $notification->notifiable_type !== get_class($user)) {
            return response()->json(['error' => 'This action is unauthorized.'], 403);
        }

        // 本人確認が成功したので、既読化を実行
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
