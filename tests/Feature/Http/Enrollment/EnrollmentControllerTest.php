<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生向け EnrollmentController の HTTP 統合テスト。
 * 認可漏れ / FormRequest バリデーション失敗 / 代表的な正常系を網羅する。
 */
class EnrollmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_student_enrollments_only(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $ownEnrollment = Enrollment::factory()->for($student)->create();
        $otherEnrollment = Enrollment::factory()->create();

        $response = $this->actingAs($student)->get(route('enrollments.index'));

        $response->assertOk();
        $response->assertViewIs('enrollment.index');
        $response->assertViewHas('enrollments', function ($enrollments) use ($ownEnrollment, $otherEnrollment) {
            return $enrollments->pluck('id')->contains($ownEnrollment->id)
                && ! $enrollments->pluck('id')->contains($otherEnrollment->id);
        });
    }

    public function test_show_allows_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();

        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        $response->assertOk();
        $response->assertViewIs('enrollment.show');
    }

    public function test_show_forbids_other_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherEnrollment = Enrollment::factory()->create();

        $response = $this->actingAs($student)->get(route('enrollments.show', $otherEnrollment));

        $response->assertForbidden();
    }

    public function test_store_creates_enrollment_for_published_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('enrollments.store'), [
            'certification_id' => $certification->id,
            'exam_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning->value,
            'current_term' => 'basic_learning',
        ]);
        $this->assertDatabaseHas('enrollment_status_logs', [
            'changed_reason' => '新規登録',
            'changed_by_user_id' => $student->id,
        ]);
    }

    public function test_store_returns_404_for_draft_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $response = $this->actingAs($student)->postJson(route('enrollments.store'), [
            'certification_id' => $certification->id,
        ]);

        // FormRequest の exists ルールで弾かれる
        $this->assertContains($response->status(), [404, 422]);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_store_returns_409_for_duplicate_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->for($student)->for($certification)->create();

        $response = $this->actingAs($student)->postJson(route('enrollments.store'), [
            'certification_id' => $certification->id,
        ]);

        $this->assertSame(409, $response->status());
    }

    public function test_store_returns_422_when_exam_date_is_today_or_before(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->postJson(route('enrollments.store'), [
            'certification_id' => $certification->id,
            'exam_date' => now()->toDateString(),
        ]);

        $this->assertSame(422, $response->status());
    }

    public function test_store_accepts_nullable_exam_date(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('enrollments.store'), [
            'certification_id' => $certification->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'exam_date' => null,
        ]);
    }

    public function test_graduated_student_cannot_use_student_only_routes(): void
    {
        // Arrange
        $graduated = User::factory()->student()->graduated()->create();
        $certification = Certification::factory()->published()->create();

        // Act: 自己登録は student + active-learning 専用 route
        $response = $this->actingAs($graduated)->postJson(route('enrollments.store'), [
            'certification_id' => $certification->id,
        ]);

        // Assert: active-learning Middleware で 403
        $response->assertForbidden();
    }

    public function test_coach_cannot_use_student_only_routes(): void
    {
        // Arrange
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        // Act: 自己登録は student 専用 route
        $response = $this->actingAs($coach)->postJson(route('enrollments.store'), [
            'certification_id' => $certification->id,
        ]);

        // Assert: role:student Middleware で 403
        $response->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('enrollments.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_destroy_soft_deletes_own_learning_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment));

        $response->assertRedirect(route('enrollments.index'));
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
    }

    public function test_destroy_rejects_other_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherEnrollment = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($student)->deleteJson(route('enrollments.destroy', $otherEnrollment));

        $response->assertForbidden();
    }

    public function test_destroy_rejects_passed_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();

        $response = $this->actingAs($student)->deleteJson(route('enrollments.destroy', $enrollment));

        // Policy::delete で status=Learning に絞っているため 403
        $response->assertForbidden();
    }

    public function test_resume_transitions_failed_to_learning_for_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->failed()->create();

        $response = $this->actingAs($student)->post(route('enrollments.resume', $enrollment));

        $response->assertRedirect();
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->fresh()->status);
        $this->assertDatabaseHas('enrollment_status_logs', [
            'enrollment_id' => $enrollment->id,
            'from_status' => EnrollmentStatus::Failed->value,
            'to_status' => EnrollmentStatus::Learning->value,
            'changed_by_user_id' => $student->id,
            'changed_reason' => '再挑戦',
        ]);
    }

    public function test_resume_rejects_learning_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)->postJson(route('enrollments.resume', $enrollment));

        // Policy::resume で status=Failed に絞っているため 403
        $response->assertForbidden();
    }

    public function test_receive_certificate_succeeds_when_eligible(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        $response = $this->actingAs($student)->post(route('enrollments.receiveCertificate', $enrollment));

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
        $this->assertDatabaseHas('certificates', ['enrollment_id' => $enrollment->id]);
    }

    public function test_receive_certificate_returns_409_when_not_eligible(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        // 公開模試 0 件で eligibility false

        $response = $this->actingAs($student)->postJson(route('enrollments.receiveCertificate', $enrollment));

        $this->assertSame(409, $response->status());
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->fresh()->status);
    }

    public function test_receive_certificate_returns_403_for_non_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherEnrollment = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($student)->postJson(route('enrollments.receiveCertificate', $otherEnrollment));

        $response->assertForbidden();
    }

    public function test_receive_certificate_returns_403_for_graduated_student(): void
    {
        $graduated = User::factory()->student()->graduated()->create();
        $enrollment = Enrollment::factory()->for($graduated)->learning()->create();

        $response = $this->actingAs($graduated)->postJson(route('enrollments.receiveCertificate', $enrollment));

        // active-learning Middleware で 403
        $response->assertForbidden();
    }

    /**
     * B-B-15 認可境界値拡張テスト
     * コーチが受講登録一覧を開いた際、自分が担当として割り当てられた資格の受講生のみが抽出され、
     * 担当外の資格に属する受講生データが一覧からシャットアウトされることを検証する
     */
    public function test_コーチの受講登録管理一覧には自分が担当として割り当てられた資格の受講生のみが表示され担当外の受講生は完全に除外されること(): void
    {
        // 1. 各ロールのアカウント生成
        $coach = User::factory()->coach()->inProgress()->create();
        $studentA = User::factory()->student()->inProgress()->create();
        $studentB = User::factory()->student()->inProgress()->create();

        // 2. コーチが担当する資格X と、担当しない資格Y をそれぞれ生成
        $assignedCert = Certification::factory()->published()->create(['name' => '担当資格X']);
        $unassignedCert = Certification::factory()->published()->create(['name' => '担当外資格Y']);

        // 3. 多対多の結合テーブルモデルに、コーチと資格X の割り当て関係をマウント
        $coach->assignedCertifications()->attach($assignedCert->id, [
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);

        // 4. それぞれの資格に受講登録（Enrollment）データを生成
        $ownEnrollment = Enrollment::factory()->for($studentA)->for($assignedCert)->create();
        $otherEnrollment = Enrollment::factory()->for($studentB)->for($unassignedCert)->create();

        // 5. コーチとして受講登録の管理一覧エンドポイントへ GET リクエストを直撃！
        $response = $this->actingAs($coach)->get(route('enrollments.index'));

        $response->assertStatus(200);
        $response->assertViewIs('enrollment.index');

        // 6. 画面に流し込まれた一覧データ（enrollments）の集合の中に、
        // 担当資格のデータ（$ownEnrollment->id）は確実に含まれており、
        // かつ担当外のデータ（$otherEnrollment->id）は 100% 完全に除外されている事実を検証
        $response->assertViewHas('enrollments', function ($enrollments) use ($ownEnrollment, $otherEnrollment) {
            $ids = collect($enrollments->items())->pluck('id');
            return $ids->contains($ownEnrollment->id) && ! $ids->contains($otherEnrollment->id);
        });
    }
}
