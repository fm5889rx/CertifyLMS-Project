<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\CancelMeetingAction;
use App\Enums\CertificationDifficulty;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 👑 【T-A-02：1対1適合】CancelMeetingAction 専用の単体テスト。
 */
class CancelMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_meeting_actionが対象面談を排他ロックしstatusを_canceledに変えて返却取引をキックすること(): void
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
            'status' => EnrollmentStatus::Learning->value,
        ]);

        $meeting = Meeting::factory()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'scheduled_at' => now()->addDays(5),
            'status' => MeetingStatus::Reserved,
        ]);

        $refundAction = app(RefundQuotaAction::class);

        $action = new CancelMeetingAction($refundAction);
        $action($meeting, $student);

        $meeting->refresh();
        $this->assertEquals(MeetingStatus::Canceled, $meeting->status);

        $this->assertEquals($student->id, $meeting->canceled_by_user_id);

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
        ]);
    }
}
