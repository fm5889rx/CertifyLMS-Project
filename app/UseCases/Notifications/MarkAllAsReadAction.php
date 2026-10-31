<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use Illuminate\Notifications\DatabaseNotification;

/**
 * ログイン中のユーザー宛てのすべての未読通知を一括で既読化するユースケース
 */
final class MarkAllAsReadAction
{
    /**
     * @param string $userId
     * @return int 既読化した件数
     */
    public function execute(string $userId): int
    {
        return DatabaseNotification::where('notifiable_id', $userId)
            ->where('notifiable_type', 'App\Models\User')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
