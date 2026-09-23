<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\IndexCoachMeetingAction;
use App\Enums\CertificationDifficulty;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 【T-A-02：1対1適合】IndexCoachMeetingAction 専用の単体テスト。
 */
class IndexCoachMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_コーチの担当スコープに絞り込んだ履歴ページネータを正しく組み立てて返却すること(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        $category = CertificationCategory::create([
            'name' => 'Unitテスト資格カテゴリ',
            'slug' => 'test-certification-category',
        ]);

        $certification = Certification::create([
            'name' => 'Unitテスト資格',
            'category_id' => $category->id,
            'difficulty' => CertificationDifficulty::Intermediate,
            'created_by_user_id' => $student->id,
            'updated_by_user_id' => $coach->id,
        ]);

        $enrollment = Enrollment::create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning,
        ]);

        Meeting::factory()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'scheduled_at' => now()->addDays(1),
            'status' => MeetingStatus::Reserved,
        ]);

        $action = new IndexCoachMeetingAction;
        $result = $action($coach, ['filter' => 'upcoming'], 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertEquals(1, $result->total());
    }
}
