<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\IndexMeetingAction;
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
 * 【T-A-02：1対1適合】IndexMeetingAction 専用の単体テスト。
 */
class IndexMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_受講生の指定条件に応じた面談履歴ページネータを正しく組み立てて返却すること(): void
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

        for ($i = 0; $i < 3; $i++) {
            Meeting::factory()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $student->id,
                'coach_id' => $coach->id,
                'scheduled_at' => now()->addDays(2)->addHour($i),
                'status' => MeetingStatus::Reserved,
            ]);
        }

        $action = new IndexMeetingAction;
        $result = $action($student, 'upcoming', 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertEquals(3, $result->total());
    }
}
