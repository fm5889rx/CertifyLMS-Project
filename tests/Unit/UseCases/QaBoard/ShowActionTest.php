<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Answer;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaBoard\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_thread_and_ordered_replies_with_authors(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createThread($student, Certification::factory()->published()->create());
        $firstAuthor = User::factory()->coach()->inProgress()->create();
        $secondAuthor = User::factory()->student()->inProgress()->create();
        $firstReply = Answer::create([
            'question_id' => $thread->id,
            'user_id' => $firstAuthor->id,
            'body' => 'First reply',
            'created_at' => now()->subMinute(),
        ]);
        $secondReply = Answer::create([
            'question_id' => $thread->id,
            'user_id' => $secondAuthor->id,
            'body' => 'Second reply',
            'created_at' => now(),
        ]);

        $result = (new ShowAction)($student, $thread->id);

        $this->assertSame($thread->id, $result['thread']->id);
        $this->assertCount(2, $result['replies']);
        $this->assertSame([$firstReply->id, $secondReply->id], $result['replies']->modelKeys());
        $this->assertSame($firstAuthor->id, $result['replies']->first()->user->id);
        $this->assertSame($thread->id, $result['replies']->first()->thread->id);
        $this->assertSame($secondAuthor->id, $result['replies']->last()->user->id);
    }

    public function test_non_admin_cannot_view_a_thread_for_an_unpublished_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createThread($student, Certification::factory()->draft()->create());

        $this->expectException(HttpException::class);
        (new ShowAction)($student, $thread->id);
    }

    public function test_coach_cannot_view_a_thread_for_an_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $thread = $this->createThread($coach, Certification::factory()->published()->create());

        $this->expectException(HttpException::class);
        (new ShowAction)($coach, $thread->id);
    }

    public function test_assigned_coach_can_view_a_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($student, $certification);
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $coach->id,
            'assigned_at' => now(),
        ]);

        $result = (new ShowAction)($coach, $thread->id);

        $this->assertSame($thread->id, $result['thread']->id);
    }

    private function createThread(User $owner, Certification $certification): QaThread
    {
        return QaThread::create([
            'id' => (string) Str::ulid(),
            'user_id' => $owner->id,
            'certification_id' => $certification->id,
            'title' => 'Question',
            'body' => 'Question body',
            'status' => QaThreadStatus::Open->value,
        ]);
    }
}
