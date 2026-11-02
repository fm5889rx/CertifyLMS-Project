<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\NotificationIndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Notification JSON API endpoints.
 */
class NotificationApiController extends Controller
{
    public function index(NotificationIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($user->role === UserRole::Admin) {
            return response()->json([
                'notifications' => [],
                'unread_count' => 0,
            ], 200);
        }

        $validated = $request->validated();
        $tab = $validated['tab'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = $user->notifications();
        if ($tab === 'unread') {
            $query = $user->unreadNotifications();
        }

        $notifications = $query->orderBy('created_at', 'desc')->take($perPage)->get();
        $unreadCount = $user->unreadNotifications()->count();

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

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $notification = DatabaseNotification::find($id);
        if (! $notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        if ($notification->notifiable_id !== $user->id || $notification->notifiable_type !== get_class($user)) {
            return response()->json(['error' => 'This action is unauthorized.'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'unread_count' => $user->unreadNotifications()->count(),
        ], 200);
    }

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
