<?php

declare(strict_types=1);

namespace Tests\Feature\Http\ContentManagement;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\Chapter\ShowAction as ChapterShowAction;
use App\UseCases\Part\IndexAction;
use App\UseCases\Part\ShowAction as PartShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentManagementSortOrder extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Certification $certification;

    private Part $partFirst;

    private Part $partSecond;

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
            'slug' => 'test-sort-slug-'.Str::random(5),
            'name' => 'テストソートカテゴリ',
        ]);

        $this->certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => 'テストソート資格',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);

        // 録順とは「あえて逆」の順序（order）で物理インサートして罠を仕込みます
        $this->partSecond = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $this->certification->id,
            'title' => '後から並ぶべきPart',
            'description' => 'テスト',
            'order' => 2, // 👈 登録1番目・順序2番目
            'status' => ContentStatus::Published,
        ]);

        $this->partFirst = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $this->certification->id,
            'title' => '最初に並ぶべきPart',
            'description' => 'テスト',
            'order' => 1, // 👈 登録2番目・順序1番目
            'status' => ContentStatus::Published,
        ]);
    }

    /**
     * ① Part一覧画面（IndexAction）のソート順検証
     */
    public function test_part一覧取得ユースケースは登録順ではなく順序番号orderの昇順で正しくソートして返却すること(): void
    {
        $action = resolve(IndexAction::class);
        $result = $action($this->certification);

        // コレクションの0番目（最上位）が、確実に order=1 のオブジェクトであることを検証
        $this->assertEquals($this->partFirst->id, $result->first()->id);
        $this->assertEquals(1, $result->first()->order);
        $this->assertEquals(2, $result->last()->order);
    }

    /**
     * ② Part詳細画面（Part\ShowAction）内の Chapter一覧ソート順検証
     */
    public function test_part詳細取得ユースケースは紐づく_chapter一覧を順序番号orderの昇順で_eager_loadすること(): void
    {
        // 登録順とは逆の順序でChapterをインサート
        $chapterSecond = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $this->partFirst->id,
            'title' => '後から並ぶべきChapter',
            'description' => 'テスト',
            'order' => 2,
            'status' => ContentStatus::Published,
        ]);

        $chapterFirst = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $this->partFirst->id,
            'title' => '最初に並ぶべきChapter',
            'description' => 'テスト',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);

        $action = resolve(PartShowAction::class);
        $result = $action($this->partFirst);

        // Eager Load された chapters の並び順がソート順（1 ➡ 2）であることを検証
        $this->assertEquals($chapterFirst->id, $result->chapters->first()->id);
        $this->assertEquals(1, $result->chapters->first()->order);
    }

    /**
     * ③ Chapter詳細画面（Chapter\ShowAction）内の Section一覧ソート順検証
     */
    public function test_chapter詳細取得ユースケースは紐づく_section一覧を順序番号orderの昇順で_eager_loadすること(): void
    {
        $chapter = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $this->partFirst->id,
            'title' => 'ベースChapter',
            'description' => 'テスト',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);

        // 登録順とは逆の順序でSectionをインサート
        $sectionSecond = Section::create([
            'id' => (string) Str::ulid(),
            'chapter_id' => $chapter->id,
            'title' => '後から並ぶべきSection',
            'description' => 'テスト',
            'body' => '本文',
            'order' => 2,
            'status' => ContentStatus::Published,
        ]);

        $sectionFirst = Section::create([
            'id' => (string) Str::ulid(),
            'chapter_id' => $chapter->id,
            'title' => '最初に並ぶべきSection',
            'description' => 'テスト',
            'body' => '本文',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);

        $action = resolve(ChapterShowAction::class);
        $result = $action($chapter);

        // Eager Load された sections の並び順がソート順（1 ➡ 2）であることを検証
        $this->assertEquals($sectionFirst->id, $result->sections->first()->id);
        $this->assertEquals(1, $result->sections->first()->order);
    }
}
