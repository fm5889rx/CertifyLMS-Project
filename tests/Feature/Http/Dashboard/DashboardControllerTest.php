<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Plan;
use App\Models\Section;
use App\Models\User;
use App\Services\EnrollmentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_admin_user_sees_admin_dashboard_blade(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $response = $this->actingAs($admin)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.admin');
    }

    public function test_coach_user_sees_coach_dashboard_blade(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.coach');
    }

    public function test_student_user_sees_student_dashboard_blade(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.student');
    }

    public function test_graduated_student_sees_graduated_dashboard_blade(): void
    {
        $graduated = User::factory()->student()->graduated()->create();

        $response = $this->actingAs($graduated)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.graduated');
    }

    public function test_student_dashboard_renders_resume_card_with_last_viewed_section(): void
    {
        // Arrange: 学習中資格 + 公開 Section + 直近の学習履歴
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->student()->inProgress()->withPlan($plan)->create();
        $cert = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($cert)->learning()->create();
        $part = Part::factory()->forCertification($cert)->published()->create(['order' => 1]);
        $chapter = Chapter::factory()->forPart($part)->published()->create(['order' => 1]);
        $section = Section::factory()->forChapter($chapter)->published()->create([
            'order' => 1,
            'title' => '前回見たセクション',
        ]);
        LearningSession::factory()->forEnrollment($enrollment)->forSection($section)->create([
            'started_at' => now()->subMinutes(5),
        ]);

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert: 前回の続きカードが最後に開いた Section へのリンクとして描画される
        $response->assertOk();
        $response->assertSee('前回の続き');
        $response->assertSee('前回見たセクション');
        $response->assertSee(route('learning.sections.show', $section->id));
    }

    public function test_student_dashboard_passed_section_links_to_enrollment_review(): void
    {
        // Arrange: 修了済資格 + 発行済み修了証
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->student()->inProgress()->withPlan($plan)->create();
        $cert = Certification::factory()->published()->create(['name' => '基本情報技術者']);
        $enrollment = Enrollment::factory()->for($student)->for($cert)->passed()->create([
            'passed_at' => now()->subDay(),
        ]);
        Certificate::factory()->for($student)->for($enrollment)->for($cert)->create();

        // Act
        $response = $this->actingAs($student)->get(route('dashboard.index'));

        // Assert: 修了済セクションが 資格名→受講登録詳細 / 復習→教材 の導線を描画
        $response->assertOk();
        $response->assertSee(route('enrollments.show', $enrollment->id));
        $response->assertSee(route('learning.enrollments.show', $enrollment->id));
    }

    public function test_admin_dashboard_renders_even_when_kpi_service_throws(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $mock = Mockery::mock(EnrollmentStatsService::class);
        $mock->shouldReceive('adminKpi')->andThrow(new \RuntimeException('boom'));
        $mock->shouldReceive('completionRateByCertification')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(EnrollmentStatsService::class, $mock);

        $response = $this->actingAs($admin)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.admin');
        $response->assertSee('まずはプランを作成してユーザーを招待してください');
    }
    /**
     * T-B-01 パフォーマンス改善・振る舞い不変テスト
     * コーチダッシュボードの担当受講生クエリを最適化（with/withMaxによるN+1完全根絶）した前後において、
     * 画面に渡される ViewModel 内の各行の関連情報（受講生氏名・担当資格名・最終活動日時）が
     * 正確に抽出され、元の振る舞いが完全に維持されていることを検証
     */
    public function test_コーチダッシュボードの担当受講生一覧においてクエリ最適化後も受講生氏名と担当資格名と最終活動日時のデータ形状が完璧に維持されていること(): void
    {
        // 1. 各ロールのアカウント生成（管理スコープ用の管理者、コーチ、および担当受講生）
        $admin = User::factory()->admin()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create(['name' => '最適化検証受講生']);

        // 2. コーチが担当する公開資格マスタの生成
        $certification = Certification::factory()->published()->create([
            'name' => 'パフォーマンスチューニング資格',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 3. 多対多の結合テーブルモデルに、コーチと資格の割り当て関係をマウント
        $coach->assignedCertifications()->attach($certification->id, [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        // 4. 受講生をその資格に受講登録（Enrollment）させる
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        // 5. N+1問題の引き金になっていた、最終学習セッション履歴を挿入
        $targetTime = now()->subHours(2);
        LearningSession::factory()->for($enrollment)->create([
            'started_at' => $targetTime,
        ]);

        // 6. コーチとしてダッシュボードエンドポイントへ GET リクエストを発行
        $response = $this->actingAs($coach)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertViewIs('dashboard.coach');

        // 7. クエリをサブクエリ化したにもかかわらず、ViewModel の中の担当受講生データに、期待する結果である
        // 「受講生氏名」「資格名」そして「最終活動日時」がパッキングされていることを検証
        $response->assertViewHas('viewModel', function ($viewModel) use ($student, $certification, $targetTime) {
            $enrollments = $viewModel->assignedEnrollments;
            $first = $enrollments->first();

            return $first !== null
                && $first->user->name === $student->name
                && $first->certification->name === $certification->name
                && $first->last_activity_at === $targetTime->toDateTimeString();
        });
    }
}
