<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\MockExam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MockExamValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Certification $certification;

    private MockExam $existingMockExam;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);

        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'test-mock-slug-'.Str::random(5),
            'name' => 'テスト模試カテゴリ',
        ]);

        $this->certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => 'テストバリデーション資格',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);

        // 更新（update）時のテスト用に既存模試レコードを1件作成
        $this->existingMockExam = MockExam::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $this->certification->id,
            'title' => '初期状態の既存模試',
            'description' => '説明文',
            'order' => 1,
            'passing_score' => 60,
            'is_published' => false,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }

    // ============================================================
    // 1. 新規作成時 (StoreRequest) の境界値検証（①〜⑤）
    // ============================================================

    public function test_1_新規作成時_1_上限境界値_o_k_100_は安全に通過すること(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $this->certification->id,
                'title' => '新規作成テスト1',
                'description' => '説明文',
                'order' => 1,
                'passing_score' => 100, // ①上限境界値OK
            ]);

        $response->assertStatus(302);
        $response->assertValid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['title' => '新規作成テスト1', 'passing_score' => 100]);
    }

    public function test_1_新規作成時_2_下限境界値_o_k_1_は安全に通過すること(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $this->certification->id,
                'title' => '新規作成テスト2',
                'description' => '説明文',
                'order' => 1,
                'passing_score' => 1, // ②下限境界値OK
            ]);

        $response->assertStatus(302);
        $response->assertValid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['title' => '新規作成テスト2', 'passing_score' => 1]);
    }

    public function test_1_新規作成時_3_上限境界値_n_g_101_はバリデーションエラーで弾かれること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.index'))
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $this->certification->id,
                'title' => '新規作成テスト3',
                'description' => '説明文',
                'order' => 1,
                'passing_score' => 101, // ③上限境界値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseMissing('mock_exams', ['title' => '新規作成テスト3']);
    }

    public function test_1_新規作成時_4_下限境界値_n_g_0_はバリデーションエラーで弾かれること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.index'))
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $this->certification->id,
                'title' => '新規作成テスト4',
                'description' => '説明文',
                'order' => 1,
                'passing_score' => 0, // ④下限境界値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseMissing('mock_exams', ['title' => '新規作成テスト4']);
    }

    public function test_1_新規作成時_5_sq_lエラーを誘発する値_n_g_256_は_d_b衝突前に最前線で弾かれること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.index'))
            ->post(route('admin.mock-exams.store'), [
                'certification_id' => $this->certification->id,
                'title' => '新規作成テスト5',
                'description' => '説明文',
                'order' => 1,
                'passing_score' => 256, // ⑤SQLエラー誘発値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseMissing('mock_exams', ['title' => '新規作成テスト5']);
    }

    // ============================================================
    // 🟨 2. 更新時 (UpdateRequest) の境界値検証（①〜⑤）
    // ============================================================

    public function test_2_更新時_1_上限境界値_o_k_100_は安全に更新保存されること(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('admin.mock-exams.update', $this->existingMockExam), [
                'title' => '更新テスト1',
                'description' => '説明更新',
                'order' => 1,
                'passing_score' => 100, // ①上限境界値OK
            ]);

        $response->assertStatus(302);
        $response->assertValid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['id' => $this->existingMockExam->id, 'title' => '更新テスト1', 'passing_score' => 100]);
    }

    public function test_2_更新時_2_下限境界値_o_k_1_は安全に更新保存されること(): void
    {
        $response = $this->actingAs($this->admin)
            ->put(route('admin.mock-exams.update', $this->existingMockExam), [
                'title' => '更新テスト2',
                'description' => '説明更新',
                'order' => 1,
                'passing_score' => 1, // ②下限境界値OK
            ]);

        $response->assertStatus(302);
        $response->assertValid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['id' => $this->existingMockExam->id, 'title' => '更新テスト2', 'passing_score' => 1]);
    }

    public function test_2_更新時_3_上限境界値_n_g_101_はバリデーションエラーで直撃遮断されること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.edit', $this->existingMockExam))
            ->put(route('admin.mock-exams.update', $this->existingMockExam), [
                'title' => '更新テスト3',
                'description' => '説明更新',
                'order' => 1,
                'passing_score' => 101, // ③上限境界値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['id' => $this->existingMockExam->id, 'passing_score' => 60]);
    }

    public function test_2_更新時_4_下限境界値_n_g_0_はバリデーションエラーで直撃遮断されること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.edit', $this->existingMockExam))
            ->put(route('admin.mock-exams.update', $this->existingMockExam), [
                'title' => '更新テスト4',
                'description' => '説明更新',
                'order' => 1,
                'passing_score' => 0, // ④下限境界値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['id' => $this->existingMockExam->id, 'passing_score' => 60]);
    }

    public function test_2_更新時_5_sq_lエラーを誘発する値_n_g_256_は_d_b衝突前に最前線で直撃遮断されること(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.mock-exams.edit', $this->existingMockExam))
            ->put(route('admin.mock-exams.update', $this->existingMockExam), [
                'title' => '更新テスト5',
                'description' => '説明更新',
                'order' => 1,
                'passing_score' => 256, // ⑤SQLエラー誘発値NG
            ]);

        $response->assertStatus(302);
        $response->assertInvalid(['passing_score']);
        $this->assertDatabaseHas('mock_exams', ['id' => $this->existingMockExam->id, 'passing_score' => 60]);
    }
}
