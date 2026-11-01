<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService; // 💡 本物の既存サービス
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FetchAvailabilityAction
{
    /**
     * 💡【本番仕様完全適合】本物のサービスをコンストラクタでインジェクション
     */
    public function __construct(
        protected MeetingAvailabilityService $availabilityService
    ) {}

    /**
     * 💡【本番仕様完全適合】Controller側の呼び出し形式「$action($enrollment, $date)」に合わせて __invoke を執行！
     */
    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        // 💡【解決】本物のサービスメソッド「slotsForCertification」へ、リレーション資格マスタを渡して計算リレー！
        return $this->availabilityService->slotsForCertification($enrollment->certification, $date);
    }
}
