<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;             // T-A-05で追加
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

// 👑 【T-A-05：ShouldQueue 契約の締結による非同期通知化大執行】
// 💡 クラスに implements ShouldQueue を宣言することにより、
//    Laravelの通知基盤は発火元リクエストを一切ブロックせず、database / mail 送信処理のすべてを
//    バックグラウンドのキューへ瞬時に逃がして worker に非同期分散処理させます！！！
class AdminAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;    // T-A-05：SerializesModels トレイル追加

    // T-A-05：一時的な送信失敗時に30秒の段階的待機を挟んで自動リトライさせる鉄壁の動的プロパティ
    public int $tries = 3;    // 最大3回リトライ
    public int $backoff = 30; // 失敗時は30秒バックオフを挟んで安全に再試行

    private Announcement $announcement;

    public function __construct(Announcement $announcement)
    {
        $this->announcement = $announcement;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【運営連絡】' . $this->announcement->title)
            ->greeting($notifiable->name . ' 様')
            ->line($this->announcement->body);
    }

    /**
     * 💡 ⭕【正攻法の最終決定打：リダイレクト先を通知詳細画面へ完全適合！】
     * 他メンバーの既読化ロジック（82行目）が検知するリダイレクト先URLを、
     * 管理者画面ではなく、受講生自身が403エラーにならずに安全に開くことができる
     * 本物の通知詳細表示（GET /notifications/{notification}）のルートへ正確に書き換えます！
     */
    public function toDatabase(object $notifiable): array
    {
        // 💡 ⭕ 修正後：受講生自身が安全に着地できる、自分宛ての「通知詳細URL」を生成！
        // ※Laravelの通知インスタンス（DatabaseNotification）のID（$this->id）をバインドさせます。
        $targetUrl = route('notifications.show', ['notification' => $this->id]);

        return [
            // 💡 ⭕ 82行目の要求を120点満点でクリアする両建て直球プレーンパッキング！
            'url'               => $targetUrl,
            'path'              => $targetUrl,

            // 提供済みBlade（show.blade.php）が14〜17行目で要求しているキー名も同一階層にマウント！
            'title'             => $this->announcement->title,
            'body'              => $this->announcement->body,
            'message'           => $this->announcement->body,
            'notification_type' => 'admin_announcement', // メガホンアイコン用区分
            'announcement_id'   => $this->announcement->id,
        ];
    }

    /**
     * 共通のフォールバック定義
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
