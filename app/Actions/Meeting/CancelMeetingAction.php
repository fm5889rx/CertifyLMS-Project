<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 【T-A-02：面談予約キャンセル・返却手続き状態変更アクション】
 *
 * 面談キャンセルの状態変更系ライフサイクルを実行する。
 * 1. 対象面談レコードのデータベース物理層における排他ロック(`lockForUpdate`)の実行
 * 2. ステータスが `reserved` かつ面談開始前（現在時刻より未来）であるかどうかの厳格なステータス検閲
 * 3. キャンセル実行者（actor）のIDおよび時刻の永続化書き込み
 * 4. 予約時に消費された受講生の面談回数1回分をプラス(Refunded)で安全に返却させる手続きの自動連動
 */
final class CancelMeetingAction
{
    public function __construct(
        private RefundQuotaAction $refundAction
    ) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($meeting->student, $meeting->id);
        });
    }
}
