<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Question;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QaReplyPostedNotification extends Notification
{
    use Queueable;

    private Question $thread;

    /**
     * 新しい通知インスタンスを生成
     */
    public function __construct(Question $thread)
    {
        $this->thread = $thread;
    }

    /**
     * 通知の配信チャンネルを「database」に指定
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * MailPitに届く本物の通知メールの本文・構造を定義
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【LMS通知】あなたの質問に新しい回答が投稿されました') // メールの件名
            ->greeting($notifiable->name.' さん') // 宛名（受講生名）
            ->line('質問掲示板に投稿したあなたの質問に対して、新着の回答（リリプライ）が届きました。')
            ->line('■ 質問タイトル: '.$this->thread->title)
            ->action('回答を確認する', route('qa-board.show', ['thread' => $this->thread->id])) // MailPit内の美しい青いボタン
            ->line('ご確認のほど、よろしくお願いいたします。');
    }

    /**
     * 配列構造（title と url）を渡す
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'あなたの質問に新しい回答が投稿されました。',
            'url' => route('qa-board.show', ['thread' => $this->thread->id]),
        ];
    }
}
