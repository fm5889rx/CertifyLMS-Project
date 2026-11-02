<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Enums\CertificationStatus;
use App\Models\Answer;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads a Q&A thread and replies after checking visibility for the current user.
 */
final class ShowAction
{
    /**
     * Loads a visible Q&A thread and its ordered replies with authors.
     *
     * @return array{thread: QaThread, replies: Collection<int, Answer>}
     */
    public function __invoke(User $user, string $threadId): array
    {
        $thread = QaThread::query()
            ->with(['user', 'certification'])
            ->whereKey($threadId)
            ->firstOrFail();
        $certification = $thread->certification;

        if (! $user->isAdmin() && $certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        if ($user->isCoach()) {
            abort_unless($certification->coaches()->whereKey($user->getKey())->exists(), 403);
        }

        $replies = $thread->replies()->with('user')->oldest('created_at')->get();
        foreach ($replies as $reply) {
            $reply->question = $thread;
            $reply->thread = $thread;
        }

        return [
            'thread' => $thread,
            'replies' => $replies,
        ];
    }
}
