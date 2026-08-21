<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminAnnouncementNotification extends Notification
{
    use Queueable;

    private Announcement $announcement;

    /**
     * コンストラクタでお知らせモデルをインポート
     */
    public function __construct(Announcement $announcement)
    {
        $this->announcement = $announcement;
    }

    /**
     * アプリ内通知(database)とメール(mail)の双方へ同時に配信を命令
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * MailPitへ届くメールの内容を正攻法でパッキング
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【運営連絡】' . $this->announcement->title)
            ->greeting($notifiable->name . ' 様')
            ->line('運営から新しい重要なお知らせが届いています。')
            ->line('---')
            ->line($this->announcement->body)
            ->line('---')
            ->action('LMSで詳細を確認する', url('/settings/profile'));
    }

    /**
     * notifications テーブルの data カラムに自動保存される配列定義
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'             => '【運営連絡】' . $this->announcement->title,
            'body'              => $this->announcement->body,
            'message'           => $this->announcement->body,
            'notification_type' => 'admin_announcement',
            'announcement_id'   => $this->announcement->id,
        ];
    }
}
