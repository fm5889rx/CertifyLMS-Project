<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;

final class MeetingService
{
    /**
     * 面談が進行中または有効な状態か判定する
     */
    public function isActive(Meeting $meeting): bool
    {
        return $meeting->status === \App\Enums\MeetingStatus::Reserved;
    }
}
