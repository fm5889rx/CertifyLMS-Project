<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\Plan;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingCancelQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coach;

    private Meeting $meeting;

    private MeetingQuotaService $quotaService;

    /**
     * 各テストの初期状態セットアップ（物理制約の完全網羅）
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->quotaService = resolve(MeetingQuotaService::class);
        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ロールのアカウント生成
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);
        $this->coach = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'meeting_url' => 'https://zoom.us']);

        // プランを作成者履歴付きでインサート
        $plan = Plan::create([
            'id' => (string) Str::ulid(),
            'name' => 'テスト受講プラン',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
            'status' => 'published',
            'sort_order' => 0,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'status' => $inProgressStatus,
            'plan_id' => $plan->id,
        ]);

        // 外部キー制約を満たすため、本物の資格カテゴリと資格マスタをインサート
        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'test-meeting-cancel-cat-'.Str::random(5),
            'name' => 'テスト面談カテゴリ',
        ]);

        $certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => 'テスト面談資格マスタ',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 2. 受講登録マウント
        $enrollment = Enrollment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->student->id,
            'certification_id' => $certification->id,
        ]);

        // 3. 予約済み（Reserved）の面談データをインサート（開始前を表現するため2日後を設定）
        $this->meeting = Meeting::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $enrollment->id,
            'coach_id' => $this->coach->id,
            'student_id' => $this->student->id,
            'scheduled_at' => now()->addDays(2),
            'status' => MeetingStatus::Reserved->value,
            'topic' => 'バグ検証用面談',
            'meeting_url_snapshot' => $this->coach->meeting_url,
        ]);
    }

    /**
     * キャンセル時の面談残数返却の厳格検証
     */
    public function test_予約済み面談をキャンセルした際にステータスがキャンセルになり受講生の面談残数が1回分確実に返却されること(): void
    {
        // 監査用にキャンセル直前の「本物の現在の面談残数」をキープ
        $beforeRemaining = $this->quotaService->remaining($this->student);

        // コントローラー（cancel）で確認した本物のキャンセルエンドポイントへ POST リクエストを直撃！
        $response = $this->actingAs($this->student)
            ->post(route('meetings.cancel', $this->meeting));

        // 1. 詳細画面へ美しく 302 リダイレクトされ、成功メッセージが返ることを証明
        $response->assertStatus(302);
        $response->assertRedirect(route('meetings.show', $this->meeting));
        $response->assertSessionHas('success', '面談をキャンセルしました。面談回数を返却しました。');

        // 2. データベース側の面談ステータスが Canceled に遷移していることを証明
        $this->assertDatabaseHas('meetings', [
            'id' => $this->meeting->id,
            'status' => MeetingStatus::Canceled->value,
            'canceled_by_user_id' => $this->student->id,
        ]);

        // 3. キャンセル後の残数を確認し、キャンセル前から「確実に1回分増えて戻っていること」をアサート証明
        $afterRemaining = $this->quotaService->remaining($this->student);
        $this->assertEquals($beforeRemaining + 1, $afterRemaining);
    }
}
