<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Notifications\DatabaseNotification; // 💡 ⭕ 純正モデルをダイレクトにインポート！

class NotificationController extends Controller
{
    /**
     * 自分宛の通知一覧を開いて確認できる (index.blade.php)
     */
    public function index(Request $request): View
    {
        $tab = $request->input('tab', 'all');

        // 💡 ⭕ 修正の命：手動DBを廃止し、Laravel純正通知モデルを直撃！
        // これにより、created_at や read_at が100%完璧に Carbon インスタンス(オブジェクト型)としてBladeへ渡ります。
        $query = DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User');

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        // 共通Blade（16行目）が要求する未読件数を正確にカウント
        $unreadCount = DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->count();

        return view('notifications.index', compact('notifications', 'unreadCount', 'tab'));
    }

    /**
     * 通知の詳細表示 (show.blade.php)
     */
    public function show(string $id): View
    {
        // 💡 純正モデルから1件を確実に特定（なければ404）
        $notification = DatabaseNotification::where('id', $id)->firstOrFail();

        if ((string)$notification->notifiable_id !== (string)Auth::id()) {
            abort(403, 'この通知を閲覧する権限がありません。');
        }

        // 詳細を開いた瞬間に自動で既読化（純正モデルが持つmarkAsReadメソッドで美しく更新）
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $unreadCount = DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->count();

        // 💡 純正オブジェクトの属性に合わせ、Bladeが扱いやすいようにデータをマウント
        $notificationData = $notification->data;

        return view('notifications.show', compact('notification', 'notificationData', 'unreadCount'));
    }

    /**
     * 通知を1件クリック操作で既読にして、関連する業務画面へリダイレクト移動する (markAsRead)
     */
    public function read(string $id): RedirectResponse
    {
        $notification = DatabaseNotification::where('id', $id)->firstOrFail();

        if ((string)$notification->notifiable_id !== (string)Auth::id()) {
            abort(403, 'この通知を操作する権限がありません。');
        }

        // 既読化を実行
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        // 💡 完璧にパッキングされたJSONデータから、Q&A掲示板等の本物のリダイレクト先URLを抽出
        $data = $notification->data;
        $redirectUrl = $data['url'] ?? $data['path'] ?? route('dashboard.index');

        return redirect($redirectUrl);
    }

    /**
     * まとめて既読にする操作（一括既読）
     */
    public function readAll(Request $request): RedirectResponse
    {
        // 自分宛の未読通知を一括で既読にアップデート
        DatabaseNotification::where('notifiable_id', Auth::id())
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now()
            ]);

        return redirect()->route('notifications.index')
            ->with('success', 'すべての未読通知を既読にしました。');
    }
}
