<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Answer;
use App\Models\User;
use App\Models\QaThread;
use App\Enums\QaThreadStatus;
use App\Enums\CertificationStatus;

class QaReplyPolicy
{
    /**
     * 回答投稿の認可チェック
     */
    public function create(User $user, QaThread $thread): bool
    {
        // 条件1: 受講中・担当中のアクティブユーザーでなければfalse
        if (!$user->isActiveUser()) {
            return false;
        }

        // 条件2: 紐づく資格マスターを取得し、公開中でない（Published以外）ならfalse
        // 仕様書：「公開停止中の資格のスレッドは受講生・コーチには見えない」
        $certification = $thread->certification;
        if (!$certification || $certification->status !== CertificationStatus::Published)
        {
            return false;
        }

        // 条件3: すでにスレッドが「解決済（Resolved）」になっていた場合は回答不可
        if ($thread->status === QaThreadStatus::Resolved) {
            return false;
        }

        // 条件4: ユーザーの権限（role）に応じた個別制御
        // 仕様書：「受講生は公開済資格すべてのスレッドを閲覧・投稿できる」
        if ($user->isStudent()) {
            return true;
        }

        // 仕様書：「コーチは担当資格のスレッドのみ閲覧・回答でき、担当外の資格は操作できない」
        if ($user->isCoach()) {
            return $user->isAssignedToCertification($thread->certification_id);
        }

        return false;
    }

    /**
     * 回答を編集・更新できるのは「回答の投稿者本人」のみ
     */
    public function update(User $user, Answer $reply): bool
    {
        return $user->id === $reply->user_id;
    }

    /**
     * 回答を削除できるのは「回答の投稿者本人」のみ
     */
    public function delete(User $user, Answer $reply): bool
    {
        return $user->id === $reply->user_id;
    }
}
