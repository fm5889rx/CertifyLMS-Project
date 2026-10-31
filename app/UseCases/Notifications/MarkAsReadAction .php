<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use Illuminate\Notifications\DatabaseNotification;

/**
 * 対象の通知を型安全に既読化し、内包されたリダイレクトURLを解決するユースケース
 */
final class MarkAsReadAction
{
    /**
     * @param DatabaseNotification $notification
     * @return string 遷移先リダイレクトURL
     */
    public function execute(DatabaseNotification $notification): string
    {
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $data = $notification->data;

        // 本物のJSON構造に適合する遷移先URLの優先抽出
        return $data['url'] ?? $data['path'] ?? route('dashboard.index');
    }
}
