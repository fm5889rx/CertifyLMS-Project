<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Answer;
use App\Models\User;

class AnswerPolicy
{
    public function create(User $user): bool
    {
        // 受講生かコーチのみが回答の作成が可能
        return $user->isStudent() || $user->isCoach();
    }

    // 投稿者本人のみが回答の編集が可能
    public function update(User $user, Answer $answer): bool
    {
        return (new QaReplyPolicy)->update($user, $answer);
    }

    // 投稿者本人のみが回答の削除が可能
    public function delete(User $user, Answer $answer): bool
    {
        return (new QaReplyPolicy)->update($user, $answer);
    }

    // 管理者のみが回答を削除可能 (DELETE /admin/qa-board/.../replies/{reply})
    public function forceDelete(User $user, Answer $answer): bool
    {
        return $user->isAdmin();
    }
}
