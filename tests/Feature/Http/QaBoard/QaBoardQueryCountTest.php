<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaBoardQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_question_list_query_count_does_not_grow_with_thread_count(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->createThreads($student, $certification, 1);
        $this->actingAs($student);

        $baseline = $this->countQueriesFor(fn () => $this->get(route('qa-board.index'))->assertOk());
        $this->createThreads($student, $certification, 19);
        $scaled = $this->countQueriesFor(fn () => $this->get(route('qa-board.index'))->assertOk());

        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "Q&A question list query count grew with thread count ({$baseline} → {$scaled}); eager-load relations and reply counts.",
        );
    }

    private function createThreads(User $student, Certification $certification, int $count): void
    {
        foreach (range(1, $count) as $index) {
            QaThread::create([
                'id' => (string) Str::ulid(),
                'user_id' => $student->id,
                'certification_id' => $certification->id,
                'title' => "Question {$index}",
                'body' => 'Question body',
                'status' => QaThreadStatus::Open->value,
            ]);
        }
    }

    private function countQueriesFor(\Closure $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $request();

        return $count;
    }
}
