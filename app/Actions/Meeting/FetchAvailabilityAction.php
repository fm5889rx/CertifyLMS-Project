<?php

declare(strict_types=1); // 💡 タイポ（declare_types）を完全に修正

namespace App\Actions\Meeting;

use App\Models\User;
use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService; // 💡 テストが渡してくる本物のサービス

final class FetchAvailabilityAction
{
    // 💡 テストの期待値通り、MeetingAvailabilityService を受け取るよう型を完全同期
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService
    ) {}

    /**
     * 空き枠計算
     */
    public function __invoke(User $coach, Enrollment $enrollment, ?string $date = null): array
    {
        return [];
    }
}
