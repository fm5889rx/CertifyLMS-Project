<?php

declare(strict_types=1);

namespace Tests\Feature\ContentManagement;

use App\Models\User;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Part;
use App\Models\Chapter;
use App\Models\Section;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentManagementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $coachA;
    private User $coachB;
    private User $admin;
    private Certification $certificationX;
    private Certification $certificationY;
    private Part $partX;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;

        // 1. 各検証ユーザーの確保
        $this->coachA = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'name' => '担当コーチA']);
        $this->coachB = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'name' => '担当外コーチB']);
        $this->admin  = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus, 'name' => '中央管理者']);

        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'test-policy-slug-' . Str::random(5),
            'name' => 'テスト認可カテゴリ',
        ]);

        // 2. 資格X（コーチAが担当）の生成
        $this->certificationX = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => '担当資格X',
            'difficulty'          => CertificationDifficulty::Intermediate,
            'status'              => CertificationStatus::Published,
            'created_by_user_id'  => $this->admin->id,
            'updated_by_user_id'  => $this->admin->id,
        ]);

        // 中間テーブルへの担当割当（ピボット必須属性を完璧に網羅）
        $this->certificationX->coaches()->syncWithoutDetaching([
            $this->coachA->id => [
                'id'                  => (string) Str::ulid(),
                'assigned_by_user_id' => $this->admin->id,
                'assigned_at'         => now()->toDateTimeString(),
            ]
        ]);

        // 3. 資格Y（コーチAは担当外、コーチBが担当）の生成
        $this->certificationY = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => '担当外資格Y',
            'difficulty'          => CertificationDifficulty::Intermediate,
            'status'              => CertificationStatus::Published,
            'created_by_user_id'  => $this->admin->id,
            'updated_by_user_id'  => $this->admin->id,
        ]);

        // 4. 検証用の親Partデータを生成
        $this->partX = Part::create([
            'id'               => (string) Str::ulid(),
            'certification_id' => $this->certificationX->id,
            'title'            => 'テストPartX',
            'description' => '説明',
            'order'            => 1,
            'status'           => ContentStatus::Published,
        ]);
    }

    /**
     * ① 担当資格配下へのアクセス解放検証（B-B-01の主目的クリア証明）
     */
    public function test_担当コーチは自身がアサインされている資格配下の教材管理画面へ正常に進入できること(): void
    {
        $response = $this->actingAs($this->coachA)
            ->get(route('admin.certifications.parts.index', $this->certificationX));

        $response->assertStatus(200);
    }

    /**
     * ② 担当外資格への厳格な403遮断検証（防衛線の維持証明）
     */
    public function test_担当外の資格配下の教材管理画面にアクセスした場合は一律で403補正遮断されること(): void
    {
        $response = $this->actingAs($this->coachA)
            ->get(route('admin.certifications.parts.index', $this->certificationY));

        $response->assertStatus(403);
    }

    /**
     * ③ 修正実装：マークダウンプレビュー（PreviewRequest）の認可連動検証
     */
    public function test_担当コーチは自身が担当する資格配下のセクションであれば正常にマークダウンプレビューを実行できること(): void
    {
        $chapter = Chapter::create([
            'id'      => (string) Str::ulid(),
            'part_id' => $this->partX->id,
            'title'   => 'テスト章',
            'order'   => 1,
            'status'  => ContentStatus::Published,
        ]);

        $section = Section::create([
            'id'         => (string) Str::ulid(),
            'chapter_id' => $chapter->id,
            'title'      => 'テスト節',
            'body'       => '旧本文',
            'order'      => 1,
            'status'     => ContentStatus::Published,
        ]);

        $response = $this->actingAs($this->coachA)
            ->post(route('admin.sections.preview', $section), [
                'body' => '# 修正後のマークダウンタイトル'
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['html']);
    }
}
