<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * 自分宛の通知一覧を開いて確認できる (index.blade.php)
     */
    public function index(Request $request): View
    {
        $tab = $request->input('tab', 'all');

        // Laravel純正通知モデルでCarbonインスタンス(オブジェクト型)としてBladeへ送る
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
        // 1. ログイン中の本人の通知一覧から該当の通知を安全に牽引
        $notification = Auth::user()->notifications()->findOrFail($id);

        // 2. 通知を既読化（4件目の要件）
        $notification->markAsRead();

        // これが「お知らせ配信」の通知だった場合announcement_idを使って、お知らせ全文データを取得
        $announcement = null;
        if (isset($notification->data['announcement_id'])) {
            $announcement = \App\Models\Announcement::find($notification->data['announcement_id']);
        }

        // 4. 提供済みの通知詳細ページ（notifications/show.blade.php）へデータを渡す
        return view('notifications.show', compact('notification', 'announcement'));
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

        // 完璧にパッキングされたJSONデータから、Q&A掲示板等の本物のリダイレクト先URLを抽出
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
