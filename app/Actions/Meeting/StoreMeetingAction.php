<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Enums\MeetingStatus; // 💡 Enum をインポート
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StoreMeetingAction
{
    public function __construct(
        protected MeetingQuotaService $quotaService,
        protected ConsumeQuotaAction $consumeQuotaAction
    ) {}

    /**
     * 受講生の予約申請の執行
     */
    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        // 1. 残面談回数の確認
        if ($this->quotaService->remaining($student) <= 0) {
            throw new InsufficientMeetingQuotaException();
        }

        return DB::transaction(function () use ($student, $enrollment, $scheduledAt, $topic) {
            // 2. 空き枠から実績最少コーチを自動割当して reserved で確定
            $assignedCoach = $enrollment->certification->coaches()
                ->withCount(['meetingsAsCoach' => function ($query) {
                    $query->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
                }])
                ->orderBy('meetings_as_coach_count', 'asc')
                ->first() ?? auth()->user();

            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $student->id,
                'coach_id' => $assignedCoach->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved, // 💡【解決】->value を削除し、Enumオブジェクトそのものを代入してテーブル規約に完全同期！
                'topic' => $topic,
            ]);

            // 3. 面談チケット（回数）の消費アクションを __invoke 形式で執行
            ($this->consumeQuotaAction)($student, $meeting->id);

            return $meeting;
        });
    }
}
