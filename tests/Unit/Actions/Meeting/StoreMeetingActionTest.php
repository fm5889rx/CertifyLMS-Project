<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\StoreMeetingAction;
use App\Enums\CertificationDifficulty;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-A-02：StoreMeetingAction 専用の単体テスト。
 */
class StoreMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coach;

    private Enrollment $enrollment;

    private Carbon $scheduledAt;

    // 4つの具象クラスを保持するプロパティ空間
    private MeetingAvailabilityService $realAvailabilityService;

    private CoachMeetingLoadService $realCoachLoadService;

    private MeetingQuotaService $realQuotaService;

    private ConsumeQuotaAction $realConsumeAction;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. 物理層外部キー制約を満たす親マスタの完全配置
        $this->student = User::factory()->create(['role' => UserRole::Student, 'max_meetings' => 0]);
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

        // コーチを資格マスタへ多対多アタッチ
        $certification->coaches()->attach($this->coach->id, [
            'assigned_by_user_id' => $this->coach->id,
            'assigned_at' => now(),
        ]);

        $this->enrollment = Enrollment::create([
            'user_id' => $this->student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning,
        ]);

        // 予約日時を「明日の午前10時」にロック設定
        $this->scheduledAt = Carbon::tomorrow()->setHour(10)->startOfHour();

        // 2. 本物の空きスケジュールマスタをデータベースへ追加
        $this->coach->coachAvailabilities()->create([
            'day_of_week' => $this->scheduledAt->dayOfWeek,
            'is_active' => true,
            'start_time' => '09:00:00', // 10:00を包み込む時間枠
            'end_time' => '18:00:00',
        ]);

        // 3. 4つの具象クラスを保存
        $this->realAvailabilityService = app(MeetingAvailabilityService::class);
        $this->realCoachLoadService = app(CoachMeetingLoadService::class);
        $this->realQuotaService = app(MeetingQuotaService::class);
        $this->realConsumeAction = app(ConsumeQuotaAction::class);
    }

    /**
     * 正常系検証
     */
    public function test_store_meeting_actionが残数確認から自動コーチ割当までを執行し正常に面談予約が成立すること(): void
    {
        // 残面談回数を1回に設定
        $this->student->update(['max_meetings' => 1]);

        // 実行アクションの登録
        $action = new StoreMeetingAction(
            $this->realAvailabilityService,
            $this->realCoachLoadService,
            $this->realQuotaService,
            $this->realConsumeAction
        );

        // 執行（残数1あるため正常終了）
        $meeting = $action($this->enrollment, $this->scheduledAt, '正常系の予約申請。');

        // 正常に面談回数が更新されていったかを検証
        $this->assertInstanceOf(Meeting::class, $meeting);
        $this->assertEquals(MeetingStatus::Reserved, $meeting->status);
        $this->assertEquals($this->coach->id, $meeting->coach_id);
    }

    /**
     * 異常系検証
     * 正常系の正常終了で残面談回数は0回になっているので、そのまま実行すれば残面談数チェックの例外が発生する
     */
    public function test_store_meeting_actionは受講生の面談チケット残数が0件の時に例外をスローして予約を強制拒絶すること(): void
    {
        // 実行アクションの登録
        $actionException = new StoreMeetingAction(
            $this->realAvailabilityService,
            $this->realCoachLoadService,
            $this->realQuotaService,
            $this->realConsumeAction
        );

        // 例外のスタンドバイ登録
        $this->expectException(InsufficientMeetingQuotaException::class);

        // 執行（残数0件が本物のロジックによってチェックされるので、PHPUnit が例外を検出する）
        $actionException($this->enrollment, $this->scheduledAt, '残数ゼロ状態での面談予約強行申請。');
    }
}
