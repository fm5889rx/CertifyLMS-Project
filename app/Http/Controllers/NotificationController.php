<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 全ロール共通 アプリ内通知一覧・既読化ハンドリングコントローラ（動作実績完全Fix版）
 */
class NotificationController extends Controller
{
    /**
     * 自分宛の通知一覧を確認
     */
    public function index(Request $request): View
    {
        $tab = $request->input('tab', 'all');

        $query = DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User');

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        $unreadCount = DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->count();

        return view('notifications.index', compact('notifications', 'unreadCount', 'tab'));
    }

    /**
     * 通知の詳細表示
     */
    public function show(string $id): View
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $announcement = null;
        if (isset($notification->data['announcement_id'])) {
            $announcementId = strtoupper((string) $notification->data['announcement_id']);
            $announcement = Announcement::find($announcementId);
        }

        return view('notifications.show', compact('notification', 'announcement'));
    }

    /**
     * 単一の通知を行クリック操作で既読にして関連業務画面へリダイレクト
     */
    public function read(string $id): RedirectResponse
    {
        $notification = DatabaseNotification::where('id', $id)->firstOrFail();

        if ((string) $notification->notifiable_id !== (string) Auth::id()) {
            abort(403, 'この通知を操作する権限がありません。');
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $data = $notification->data;
        $redirectUrl = $data['url'] ?? $data['path'] ?? route('dashboard.index');

        return redirect($redirectUrl);
    }

    /**
     * まとめて既読にする操作（一括既読）
     */
    public function readAll(): RedirectResponse
    {
        DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return redirect()->route('notifications.index')
            ->with('success', 'すべての未読通知を既読にしました。');
    }
}
