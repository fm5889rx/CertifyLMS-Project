<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 【T-A-02：空き枠予約カレンダーデータ吸引アクション】
 *
 * 予約画面の非同期JSフロントが要求する、特定日付・特定資格に紐づく「空き面談スロット枠」の
 * 算出ロジックを担う。内部の `MeetingAvailabilityService` に処理を引き継ぐ。
 */
final class FetchAvailabilityAction
{
    public function __construct(
        private MeetingAvailabilityService $availabilityService
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        return $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date
        );
    }
}
