<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StoreMeetingAction
{
    public function __construct(
        protected MeetingQuotaService $quotaService,
        protected ConsumeQuotaAction $consumeQuotaAction
    ) {}

    /**
     * Reserves a meeting, assigns a coach, and consumes one meeting quota.
     *
     * @throws InsufficientMeetingQuotaException When the student has no meeting quota remaining.
     */
    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        // 1. 残面談回数の確認
        if ($this->quotaService->remaining($student) <= 0) {
            throw new InsufficientMeetingQuotaException;
        }

        return DB::transaction(function () use ($student, $enrollment, $scheduledAt, $topic): Meeting {
            // 2. 空き枠から実績最少コーチを自動割当して reserved で確定
            $assignedCoach = $enrollment->certification->coaches()
                ->withCount(['meetingsAsCoach' => function (Builder $query): void {
                    $query->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
                }])
                ->orderBy('meetings_as_coach_count', 'asc')
                ->first() ?? auth()->user();

            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $student->id,
                'coach_id' => $assignedCoach->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved,
                'topic' => $topic,
            ]);

            // 3. 面談チケット（回数）の消費アクションを __invoke 形式で執行
            ($this->consumeQuotaAction)($student, $meeting->id);

            return $meeting;
        });
    }
}
