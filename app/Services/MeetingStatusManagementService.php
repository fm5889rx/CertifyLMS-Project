<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use App\Enums\MeetingStatus;

final class MeetingStatusManagementService
{
    /**
     * 指定された面談をキャンセル状態に遷移させる
     */
    public function cancel(Meeting $meeting): Meeting
    {
        $meeting->update([
            'status' => MeetingStatus::Canceled->value,
        ]);

        return $meeting;
    }
}
