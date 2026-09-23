<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

/**
 * 【T-A-02：担当コーチ専用面談メモ記録状態変更アクション】
 *
 * 担当コーチが面談詳細画面から記録する指導カルテメモの作成・更新処理を司るアクション。
 * すでにキャンセル済(canceled)の面談にメモが残されてしまうデグレを防ぐため、
 * 対象面談が `reserved` または `completed` の正常状態であるかをチェックし、
 * トランザクション保護下で `updateOrCreate` を安全に執行する。
 */
final class UpsertMeetingMemoAction
{
    public function __invoke(Meeting $meeting, string $body): void
    {
        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
