<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Enrollment\IndexManagementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndexManagementActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_filters_enrollments_and_can_include_soft_deleted_records(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create(['name' => 'Matching Student']);
        $otherStudent = User::factory()->student()->inProgress()->create(['name' => 'Other Student']);
        $certification = Certification::factory()->published()->create();
        $deletedCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $active = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();
        $deleted = Enrollment::factory()
            ->for($student)
            ->for($deletedCertification)
            ->learning()
            ->create();
        $deleted->delete();
        Enrollment::factory()
            ->for($otherStudent)
            ->for($otherCertification)
            ->learning()
            ->create();

        $result = (new IndexManagementAction)($admin, [
            'status' => EnrollmentStatus::Learning->value,
            'keyword' => 'Matching',
            'with_trashed' => true,
        ]);

        $this->assertEqualsCanonicalizing(
            [$active->id, $deleted->id],
            collect($result['enrollments']->items())->pluck('id')->all(),
        );
        $this->assertSame(EnrollmentStatus::Learning->value, $result['status']);
        $this->assertSame('Matching', $result['keyword']);
        $this->assertTrue($result['withTrashed']);
    }

    public function test_coach_only_receives_enrollments_and_filter_options_for_assigned_certifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $assignedCertification = Certification::factory()->published()->create();
        $unassignedCertification = Certification::factory()->published()->create();
        $coach->assignedCertifications()->attach($assignedCertification->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        $assignedEnrollment = Enrollment::factory()->for($assignedCertification)->create();
        Enrollment::factory()->for($unassignedCertification)->create();

        $result = (new IndexManagementAction)($coach, []);

        $this->assertSame(
            [$assignedEnrollment->id],
            collect($result['enrollments']->items())->pluck('id')->all(),
        );
        $this->assertSame(
            [$assignedCertification->id],
            $result['certifications']->pluck('id')->all(),
        );
    }
}
