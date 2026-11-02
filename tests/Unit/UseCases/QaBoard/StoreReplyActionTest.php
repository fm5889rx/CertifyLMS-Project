<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyPostedNotification;
use App\UseCases\QaBoard\StoreReplyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoreReplyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_reply_and_notifies_thread_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->student()->inProgress()->create();
        $responder = User::factory()->coach()->inProgress()->create();
        $thread = $this->createThread($owner);

        $reply = (new StoreReplyAction)($thread, $responder, 'A useful answer');

        $this->assertDatabaseHas('answers', [
            'id' => $reply->id,
            'question_id' => $thread->id,
            'user_id' => $responder->id,
            'body' => 'A useful answer',
        ]);
        Notification::assertSentTo($owner, QaReplyPostedNotification::class);
        Notification::assertNotSentTo($responder, QaReplyPostedNotification::class);
    }

    public function test_does_not_notify_when_the_thread_owner_replies_to_their_own_question(): void
    {
        Notification::fake();
        $owner = User::factory()->student()->inProgress()->create();
        $thread = $this->createThread($owner);

        (new StoreReplyAction)($thread, $owner, 'An update from the author');

        Notification::assertNothingSent();
    }

    private function createThread(User $owner): QaThread
    {
        return QaThread::create([
            'id' => (string) Str::ulid(),
            'user_id' => $owner->id,
            'certification_id' => Certification::factory()->published()->create()->id,
            'title' => 'Question',
            'body' => 'Question body',
            'status' => QaThreadStatus::Open->value,
        ]);
    }
}
