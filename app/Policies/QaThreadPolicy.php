<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QaThread;
use App\Models\User;

class QaThreadPolicy
{
    /**
     * 質問を作成できるのは「受講生」のみ
     */
    public function create(User $user): bool
    {
        return $user->isStudent();
    }

    /**
     * 質問を編集・更新できるのは「質問の投稿者本人」のみ
     */
    public function update(User $user, QaThread $thread): bool
    {
        return $user->id === $thread->user_id;
    }

    /**
     * 質問を削除できるのは「質問の投稿者本人」のみ
     */
    public function delete(User $user, QaThread $thread): bool
    {
        return $user->id === $thread->user_id;
    }

    /**
     * 質問を解決済にできるのは「質問の投稿者本人」のみ
     */
    public function resolve(User $user, QaThread $thread): bool
    {
        return $user->id === $thread->user_id;
    }

    /**
     * 質問を受付中に戻せるのは「質問の投稿者本人」のみ
     */
    public function unresolve(User $user, QaThread $thread): bool
    {
        return $user->id === $thread->user_id;
    }
}
