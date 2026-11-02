<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;

final class CoachAssignmentService
{
    /**
     * 過去実績最少のコーチを自動割り当てして面談レコードを安全に生成する
     */
    public function assign(User $student, User $coach, string $slot, Enrollment $enrollment): Meeting
    {
        $scheduledAt = Carbon::parse($slot);

        // 💡 既存の MeetingController 内の findAvailableCoaches ロジックとシンクロし、
        // 過去の面談実績件数が最も少ないコーチをEloquentのwithCountでソート選出する
        $assignedCoach = $enrollment->certification->coaches()
            ->withCount(['meetingsAsCoach' => function ($query) {
                $query->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            }])
            ->orderBy('meetings_as_coach_count', 'asc')
            ->first() ?? $coach;

        return Meeting::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'coach_id' => $assignedCoach->id,
            'scheduled_at' => $scheduledAt,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }
}
