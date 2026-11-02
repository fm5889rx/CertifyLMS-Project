<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use App\Notifications\QaReplyPostedNotification;
use Illuminate\Support\Str;

/**
 * Persists a Q&A reply and notifies the thread owner when appropriate.
 */
final class StoreReplyAction
{
    /**
     * Stores a reply and notifies the thread owner unless they wrote the reply.
     */
    public function __invoke(Question $thread, User $author, string $body): Answer
    {
        $reply = Answer::create([
            'id' => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        if ($thread->user_id !== $author->id) {
            $thread->user->notify(new QaReplyPostedNotification($thread));
        }

        return $reply;
    }
}
