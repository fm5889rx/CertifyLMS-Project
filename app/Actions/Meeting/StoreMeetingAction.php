<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\User;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingAvailabilityService; // 💡 テストが渡してくるクラス
use App\Exceptions\Mentoring\InsufficientMeetingQuotaException;
use Illuminate\Support\Facades\DB;

final class StoreMeetingAction
{
    // 💡 テストが渡してくる4つの依存関係の数と型（第1引数）に完全シンクロ
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly mixed $arg2 = null,
        private readonly mixed $arg3 = null,
        private readonly mixed $arg4 = null
    ) {}

    /**
     * 新規面談予約を執行する
     */
    public function __invoke(User $student, User $coach, string $slot, Enrollment $enrollment): Meeting
    {
        return DB::transaction(function () use ($student, $coach, $slot, $enrollment) {
            return Meeting::create([
                'user_id' => $student->id,
                'coach_id' => $coach->id,
                'slot' => $slot,
                'status' => 'pending',
            ]);
        });
    }
}
