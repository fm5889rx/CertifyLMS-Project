<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification; // Laravel標準の通知モデル
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * 通知一覧静的画面の表示 (GET /notifications)
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        $tab = $request->query('tab', 'all');

        $query = $user->notifications();
        if ($tab === 'unread') {
            $query = $user->unreadNotifications();
        }

        $notifications = $query->orderBy('created_at', 'desc')->paginate(20);
        $unreadCount = $user->unreadNotifications()->count();

        // 31行目のx-tabsコンポーネントが要求する $tab 変数を確実に注入
        return view('notifications.index', compact('notifications', 'unreadCount', 'tab'));
    }

    /**
     * 通知詳細画面の表示 (GET /notifications/{notification})
     * 
     * 💡【ルート定義・型宣言は一切歪めず100%完全復元】
     */
    public function show(DatabaseNotification $notification): View
    {
        // 💡【実機404完全解決】不特定多数のアクターID（UUID/ULID）の型ブレを文字列キャストで完全ロック！
        if ((string) $notification->notifiable_id !== (string) auth()->id()) {
            abort(404, '通知が見つかりません。');
        }

        // 開いた瞬間に自動的に既読化
        $notification->markAsRead();

        return view('notifications.show', compact('notification'));
    }

    /**
     * Web画面からの単一既読化リクエスト (POST /notifications/{notification}/read)
     */
    public function read(DatabaseNotification $notification): RedirectResponse
    {
        // 💡【実機404完全解決】不特定多数のアクターID（UUID/ULID）の型ブレを文字列キャストで完全ロック！
        if ((string) $notification->notifiable_id !== (string) auth()->id()) {
            abort(403, 'この操作は許可されていません。');
        }

        $notification->markAsRead();

        $data = is_array($notification->data) ? $notification->data : [];

        // ログから確定した本物のキー名「url」を用いて302リダイレクトを完全貫通
        if (isset($data['url'])) {
            return redirect()->to((string) $data['url']);
        }

        $redirectUrl = $data['action_url'] ?? ($data['redirect_url'] ?? route('notifications.index'));
        return redirect()->to($redirectUrl);
    }

    /**
     * Web画面からの全件一括既読化リクエスト (POST /notifications/read-all)
     */
    public function readAll(): RedirectResponse
    {
        $user = auth()->user();
        $user->unreadNotifications()->get()->markAsRead();

        return redirect()->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
