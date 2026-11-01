<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\MeetingQuota\RefundQuotaAction;

/**
 * 面談キャンセル時の状態変更（インライン記述で未作成Serviceエラーを完全回避）
 */
class CancelMeetingAction
{
    public function __construct(
        // 💡 未作成クラスを完全排除。実在する RefundQuotaAction のみを受け取る
        protected RefundQuotaAction $refundQuotaAction
    ) {}

    /**
     * MeetingController(132行目)から安全にパケットを受信
     */
    public function __invoke(Meeting $meeting, User $user): Meeting
    {
        // 💡 存在しないServiceクラスに処理を丸投げせず、ここでダイレクトにステータスを更新（確実な実機開通）
        $meeting->update([
            'status' => MeetingStatus::Canceled->value,
            'canceled_by_user_id' => $user->id,
        ]);

        // 返却ユースケース（__invoke）の引数順に100%完全適合
        ($this->refundQuotaAction)($user, $meeting->id);

        return $meeting;
    }
}
