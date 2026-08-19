<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    // 受講生とコーチが閲覧可能 (GET /qa-board, GET /qa-board/{thread})
    public function view(User $user, Question $question): bool
    {
        return $user->isStudent() || $user->isCoach();
    }

    // 投稿者本人のみが編集・削除・解決状態の変更が可能
    public function update(User $user, Question $question): bool
    {
        return $user->id === $question->user_id;
    }

    // 管理者のみが削除可能 (DELETE /admin/qa-board/{thread})
    public function forceDelete(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }
}
