<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Models\User;
use App\Models\Enrollment;
use App\Models\LearningGoal;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Http\Requests\LearningGoal\LearningGoalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $anotherStudent;
    private User $coach;
    private User $adminUser;
    private Enrollment $enrollment;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress->value ?? 'in_progress';

        $this->student = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->anotherStudent = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->coach = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus]);
        $this->adminUser = User::factory()->create(['role' => UserRole::Admin ?? 'admin', 'status' => $inProgressStatus]);

        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'test-it-category-' . Str::random(5),
            'name' => 'テスト対象ITカテゴリ',
        ]);

        $certification = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => 'テスト対象IT資格',
            'difficulty'          => CertificationDifficulty::Intermediate->value,
            'status'              => CertificationStatus::Published->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        $this->enrollment = Enrollment::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
            'current_term'     => TermType::BasicLearning->value,
            'exam_date'        => now()->addMonths(3)->toDateString(),
        ]);
    }

    /**
     * ① 目標の追加
     */
    public function test_受講生本人は自分の受講登録に対して新しい学習目標を未達成状態で追加できること(): void
    {
        $postData = [
            'title'       => '1ヶ月目で基礎編を終わらせる',
            'description' => 'テキストの第5章までを徹底的に周回する。',
            'target_date' => now()->addDays(30)->toDateString(),
        ];

        $response = $this->actingAs($this->student)
            ->from(route('enrollment-goals.store', $this->enrollment->id))
            ->post(route('enrollment-goals.store', $this->enrollment->id), $postData);

        $response->assertRedirect(route('enrollment-goals.store', $this->enrollment->id));

        $this->assertDatabaseHas('learning_goals', [
            'enrollment_id' => $this->enrollment->id,
            'title'         => '1ヶ月目で基礎編を終わらせる',
            'achieved_at'   => null,
        ]);
    }

    /**
     * ② 入力検証
     */
    public function test_目標追加時に必須項目が欠落しているか過去の日付を指定した場合はバリデーションエラーになること(): void
    {
        $invalidData = [
            'title'       => '',
            'target_date' => now()->subDay()->toDateString(),
        ];

        $response = $this->actingAs($this->student)
            ->post(route('enrollment-goals.store', $this->enrollment->id), $invalidData);

        $response->assertSessionHasErrors(['title', 'target_date']);
    }

    /**
     * ③ 目標の編集画面表示 ＆ 基本情報更新
     */
    public function test_受講生本人は自分の目標の編集画面を表示し基本情報をPATCHメソッドで更新できること(): void
    {
        $goal = LearningGoal::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'title'         => '修正前の目標',
            'description'   => '古い本文',
            'target_date'   => now()->addDays(10)->toDateString(),
            'achieved_at'   => null,
        ]);

        $response = $this->actingAs($this->student)->get(route('enrollment-goals.edit', $goal->id));
        $response->assertStatus(200);

        $updateData = [
            'title'       => '完全に新しく書き換えた目標タイトル',
            'description' => '新しい本文',
            'target_date' => now()->addDays(15)->toDateString(),
        ];

        $response = $this->actingAs($this->student)
            ->from(route('enrollment-goals.edit', $goal->id))
            ->patch(route('enrollment-goals.update', $goal->id), $updateData);

        $response->assertRedirect(route('enrollment-goals.edit', $goal->id));

        $this->assertDatabaseHas('learning_goals', [
            'id'    => $goal->id,
            'title' => '完全に新しく書き換えた目標タイトル',
        ]);
    }

    /**
     * ④ 目標の削除
     */
    public function test_受講生本人は自分の目標を履歴を残さずに物理削除できること(): void
    {
        $goal = LearningGoal::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'title'         => '消去される運命の目標',
            'target_date'   => now()->addDays(5)->toDateString(),
            'achieved_at'   => null,
        ]);

        $response = $this->actingAs($this->student)
            ->from(route('enrollment-goals.edit', $goal->id))
            ->delete(route('enrollment-goals.destroy', $goal->id));

        $response->assertRedirect(route('enrollment-goals.edit', $goal->id));

        $this->assertDatabaseMissing('learning_goals', ['id' => $goal->id]);
    }

    /**
     * ⑤ 達成マークと達成解除の撃ち分けテスト
     */
    public function test_目標の達成マーク付与と解除のHTTPメソッド撃ち分けが仕様通りに連動すること(): void
    {
        $goal = LearningGoal::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'title'         => 'ステータス連動テスト目標',
            'target_date'   => now()->addDays(5)->toDateString(),
            'achieved_at'   => null,
        ]);

        $response = $this->actingAs($this->student)
            ->from(route('enrollment-goals.store', $this->enrollment->id))
            ->post(route('enrollment-goals.achieve', $goal->id));
        $response->assertStatus(302);
        $this->assertNotNull($goal->refresh()->achieved_at);

        $response = $this->actingAs($this->student)
            ->from(route('enrollment-goals.store', $this->enrollment->id))
            ->delete(route('enrollment-goals.unachieve', $goal->id));

        $response->assertStatus(302);
        $this->assertNull($goal->refresh()->achieved_at);
    }

    /**
     * ⑥ 特権アクセス・セキュリティガード
     */
    public function test_コーチや管理者や他受講生が目標の追加や変更を試みた場合は一律で認可拒否されること(): void
    {
        $response = $this->actingAs($this->coach)->post(route('enrollment-goals.store', $this->enrollment->id), [
            'title'       => 'コーチが勝手に入れた目標',
            'target_date' => now()->addDays(5)->toDateString(),
        ]);
        $response->assertStatus(403);

        $goal = LearningGoal::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'title' => '本本の目標',
            'target_date' => now()->addDays(5)->toDateString(),
            'achieved_at' => null,
        ]);

        $response = $this->actingAs($this->adminUser)->delete(route('enrollment-goals.destroy', $goal->id));
        $response->assertStatus(403);

        $response = $this->actingAs($this->anotherStudent)->post(route('enrollment-goals.achieve', $goal->id));
        $response->assertStatus(403);
    }
}
