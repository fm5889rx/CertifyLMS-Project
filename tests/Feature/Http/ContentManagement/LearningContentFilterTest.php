<?php

declare(strict_types=1);

namespace Tests\Feature\Http\ContentManagement;

use App\Models\User;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
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

class LearningContentFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;
    private Certification $certificationX;
    private Enrollment $enrollmentX;
    private Part $partX;
    private Chapter $chapterX;
    private Section $sectionX;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;
        $this->studentA = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $admin          = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);

        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'test-filter-slug-' . Str::random(5),
            'name' => 'テストフィルタカテゴリ',
        ]);

        // 初期状態は「公開停止（Private / Archived）」状態の資格をマウント
        $statusArchived = defined('\App\Enums\CertificationStatus::Archived')
            ? CertificationStatus::Archived
            : (defined('\App\Enums\CertificationStatus::Private') ? CertificationStatus::Private : 'archived');

        $this->certificationX = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => '公開停止されたテスト資格X',
            'difficulty'          => CertificationDifficulty::Intermediate,
            'status'              => $statusArchived, // 👈 公開停止状態
            'created_by_user_id'  => $admin->id,
            'updated_by_user_id'  => $admin->id,
        ]);

        // 受講生Aをこの資格へ受講登録（受講中状態）
        $this->enrollmentX = Enrollment::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->studentA->id,
            'certification_id' => $this->certificationX->id,
        ]);

        // 配下の3大教材リソースをすべて Published で安全に生成
        $this->partX = Part::create([
            'id'               => (string) Str::ulid(),
            'certification_id' => $this->certificationX->id,
            'title'            => 'テストPart',
            'description'      => 'テスト',
            'order'            => 1,
            'status'           => ContentStatus::Published,
        ]);

        $this->chapterX = Chapter::create([
            'id'           => (string) Str::ulid(),
            'part_id'      => $this->partX->id,
            'title'        => 'テストChapter',
            'description'  => 'テスト',
            'order'        => 1,
            'status'       => ContentStatus::Published,
        ]);

        $this->sectionX = Section::create([
            'id'           => (string) Str::ulid(),
            'chapter_id'   => $this->chapterX->id,
            'title'        => 'テストSection',
            'description'  => 'テスト',
            'body'         => '## テスト本文',
            'order'        => 1,
            'status'       => ContentStatus::Published,
        ]);
    }

    /**
     * ① Enrollment目次画面の404ガード検証
     */
    public function test_受講登録が残っていても資格が公開停止された場合は受講生向け目次画面へのアクセスが404で遮断されること(): void
    {
        $response = $this->actingAs($this->studentA)
            ->get(route('learning.enrollments.show', $this->enrollmentX));

        $response->assertStatus(404);
    }

    /**
     * ② Part詳細画面の404ガード検証
     */
    public function test_受講登録が残っていても資格が公開停止された場合は受講生向けPart詳細画面へのアクセスが404で遮断されること(): void
    {
        $response = $this->actingAs($this->studentA)
            ->get(route('learning.parts.show', $this->partX));

        $response->assertStatus(404);
    }

    /**
     * ③ Chapter詳細画面の404ガード検証
     */
    public function test_受講登録が残っていても資格が公開停止された場合は受講生向けChapter詳細画面へのアクセスが404で遮断されること(): void
    {
        $response = $this->actingAs($this->studentA)
            ->get(route('learning.chapters.show', $this->chapterX));

        $response->assertStatus(404);
    }

    /**
     * ④ Section詳細画面の404ガード検証
     */
    public function test_受講登録が残っていても資格が公開停止された場合は受講生向けSection詳細画面へのアクセスが404で遮断されること(): void
    {
        $response = $this->actingAs($this->studentA)
            ->get(route('learning.sections.show', $this->sectionX));

        $response->assertStatus(404);
    }

    /**
     * ⑤ 境界値監査（資格が公開中の場合は従来通り正常閲覧できることの証明）
     */
    public function test_資格が正常に公開中の場合は受講登録済みの受講生は教材詳細を200で正常に閲覧できること(): void
    {
        // 資格を「公開中（Published）」へと上書きアジャスト
        $this->certificationX->update(['status' => CertificationStatus::Published]);

        // Section詳細を叩く
        $response = $this->actingAs($this->studentA)
            ->get(route('learning.sections.show', $this->sectionX));

        // 公開中の場合は 200 OK になることを検証
        $response->assertStatus(200);
    }
}
