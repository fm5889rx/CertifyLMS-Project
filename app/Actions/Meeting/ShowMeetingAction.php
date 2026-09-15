<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Meeting;

/**
 * 【T-A-02：面談詳細データロードリファクタリングアクション】
 *
 * 1on1面談詳細画面の描画に不可欠な、関連リレーション(所属資格/担当コーチ/受講生/キャンセル実行者/コーチメモ)の
 * 一括ロード(loadMissing)を型安全に執行し、Controllerのデータ責務を薄型ラッパー化させる。
 */
final class ShowMeetingAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);

        return $meeting;
    }
}
