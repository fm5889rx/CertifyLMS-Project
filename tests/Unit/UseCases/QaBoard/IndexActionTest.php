<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaBoard\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_listing_filters_status_and_keyword_without_exposing_unpublished_certifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $matching = $this->createThread($student, $published, 'Target title', 'Needle in body', QaThreadStatus::Resolved);
        $this->createThread($student, $published, 'Other title', 'Different body', QaThreadStatus::Open);
        $hidden = $this->createThread($student, $draft, 'Target title', 'Needle in body', QaThreadStatus::Resolved);

        $result = (new IndexAction)($student, [
            'status' => 'resolved',
            'keyword' => 'Needle',
        ]);

        $this->assertSame(
            [$matching->id],
            collect($result['threads']->items())->pluck('id')->all(),
        );
        $this->assertFalse($result['certifications']->contains('id', $draft->id));
        $this->assertFalse(collect($result['threads']->items())->contains('id', $hidden->id));
        $this->assertSame('qa-board.index', $result['indexRoute']);
    }

    public function test_coach_listing_is_limited_to_published_assigned_certifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $assigned = Certification::factory()->published()->create();
        $unassigned = Certification::factory()->published()->create();
        $draftAssigned = Certification::factory()->draft()->create();
        $coach->assignedCertifications()->attach([$assigned->id, $draftAssigned->id], [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $visible = $this->createThread($admin, $assigned, 'Visible', 'Body', QaThreadStatus::Open);
        $this->createThread($admin, $unassigned, 'Unassigned', 'Body', QaThreadStatus::Open);
        $this->createThread($admin, $draftAssigned, 'Draft', 'Body', QaThreadStatus::Open);

        $result = (new IndexAction)($coach, []);

        $this->assertSame([$visible->id], collect($result['threads']->items())->pluck('id')->all());
        $this->assertSame([$assigned->id], $result['certifications']->pluck('id')->all());
    }

    private function createThread(
        User $author,
        Certification $certification,
        string $title,
        string $body,
        QaThreadStatus $status,
    ): QaThread {
        return QaThread::create([
            'id' => (string) Str::ulid(),
            'user_id' => $author->id,
            'certification_id' => $certification->id,
            'title' => $title,
            'body' => $body,
            'status' => $status->value,
        ]);
    }
}
