<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\UpsertMeetingMemoAction;
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
use Tests\TestCase;

/**
 * 👑 【T-A-02：1対1適合】UpsertMeetingMemoAction 専用の単体テスト。
 */
class UpsertMeetingMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_meeting_memo_actionが面談のステータスを検閲して指導カルテメモを正しく保存すること(): void
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
            'status' => MeetingStatus::Reserved->value,
        ]);

        $action = new UpsertMeetingMemoAction;
        $action($meeting, 'カルテメモデータ');

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => 'カルテメモデータ',
        ]);
    }
}
