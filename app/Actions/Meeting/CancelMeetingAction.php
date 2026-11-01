<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Meeting;
use App\Services\Meeting\MeetingStatusManagementService; // 💡 既存のサービス
use Illuminate\Support\Facades\DB;

final class CancelMeetingAction
{
    // 💡 テストコード（CancelMeetingActionTest:62行目）に合わせ、引数はこれ1つが正解
    public function __construct(
        private readonly MeetingStatusManagementService $statusService
    ) {}

    /**
     * 面談を排他ロックしてキャンセルを実行する
     */
    public function __invoke(Meeting $meeting): Meeting
    {
        return DB::transaction(function () use ($meeting) {
            $lockedMeeting = Meeting::lockForUpdate()->findOrFail($meeting->id);
            return $this->statusService->cancel($lockedMeeting);
        });
    }
}
