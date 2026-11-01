<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\StoreMeetingAction;
use App\Enums\CertificationDifficulty;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService; // 💡 本物
use App\UseCases\MeetingQuota\ConsumeQuotaAction; // 💡 本物
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException; // 💡 本物
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $coach;
    private Enrollment $enrollment;
    private Carbon $scheduledAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => UserRole::Student, 'max_meetings' => 3]);
        $this->coach = User::factory()->create(['role' => UserRole::Coach, 'meeting_url' => 'https://zoom.us']);

        $category = CertificationCategory::create([
            'name' => 'Unitテスト資格カテゴリ',
            'slug' => 'test-certification-category',
        ]);

        $certification = Certification::create([
            'name' => 'Unitテスト資格',
            'category_id' => $category->id,
            'difficulty' => CertificationDifficulty::Intermediate,
            'created_by_user_id' => $this->student->id,
            'updated_by_user_id' => $this->coach->id,
        ]);

        $certification->coaches()->attach($this->coach->id, [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'assigned_by_user_id' => $this->coach->id,
            'assigned_at' => now(),
        ]);

        $this->enrollment = Enrollment::create([
            'user_id' => $this->student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning,
        ]);

        $this->scheduledAt = Carbon::tomorrow()->setHour(10)->startOfHour();
    }

    /**
     * 正常系検証
     */
    public function test_store_meeting_actionが残数確認から自動コーチ割当までを執行し正常に面談予約が成立すること(): void
    {
        // 💡【解決】本物になった2つのサービスオブジェクトを確実にインジェクション同期
        $quotaService = app(MeetingQuotaService::class);
        $consumeQuotaAction = app(ConsumeQuotaAction::class);

        $action = new StoreMeetingAction($quotaService, $consumeQuotaAction);

        // 💡【解決】本番コントローラー通りの「$action($enrollment, $scheduledAt, $topic)」で同期実行！
        $meeting = $action($this->enrollment, $this->scheduledAt, '正常系の予約申請。');

        $this->assertInstanceOf(Meeting::class, $meeting);
        $this->assertEquals(MeetingStatus::Reserved, $meeting->status);
    }
}
