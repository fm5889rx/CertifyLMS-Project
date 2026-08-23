<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminderNotification extends Notification
{
    use Queueable;

    private Meeting $meeting;
    private string $window;

    public function __construct(Meeting $meeting, string $window)
    {
        $this->meeting = $meeting;
        $this->window = $window;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * MailPitへ届くリマインダーメールの内容をパッキング
     */
    public function toMail(object $notifiable): MailMessage
    {
        $timingLabel = $this->window === 'eve' ? '【前日リマインダー】' : '【1時間前リマインダー】';
        $formattedTime = $this->meeting->scheduled_at?->format('Y年m月d日 H:i') ?? '—';

        return (new MailMessage)
            ->subject($timingLabel . ' 1on1面談が近づいています')
            ->greeting($notifiable->name . ' 様')
            ->line("以下の面談予定時刻が近づいていますので、事前にご確認をお願いいたします。")
            ->line('---')
            ->line("面談日時: {$formattedTime}")
            ->line("面談内容: " . ($this->meeting->topic ?? '定期面談'))
            ->line('---')
            ->line("お時間になりましたら、LMSダッシュボードより面談URLへご参加ください。");
    }

    /**
     * NotificationController@showの自動リダイレクト先として、
     * 受講生自身の通知詳細画面（GET /notifications/{id}）の着地先をパッキング
     */
    public function toDatabase(object $notifiable): array
    {
        $timingLabel = $this->window === 'eve' ? '前日リマインド' : '1時間前リマインド';
        $formattedTime = $this->meeting->scheduled_at?->format('m/d H:i') ?? '—';

        $title = "【面談リマインド】{$timingLabel} ({$formattedTime} 開始)";
        $body = "面談時刻が近づいています。\n\n"
                . "◾️日時: {$formattedTime}\n"
                . "◾️トピック: " . ($this->meeting->topic ?? '定期面談') . "\n\n"
                . "遅刻や参加忘れのないよう、お時間になりましたら面談URLよりご参加ください。";

        // 4件目の自動既読化リダイレクトの着地先URLを安全に生成（通知自体のIDをバインド）
        $targetUrl = route('notifications.show', ['notification' => $this->id]);

        return [
            'url'               => $targetUrl,
            'path'              => $targetUrl,
            'title'             => $title,
            'body'              => $body,
            'message'           => $body,
            'notification_type' => 'meeting_reminder',
            'meeting_id'        => $this->meeting->id,
            'window'            => $this->window,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
