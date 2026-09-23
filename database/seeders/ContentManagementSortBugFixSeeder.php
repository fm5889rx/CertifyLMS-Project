<?php

declare(strict_types=1);

namespace Database\Seeders;

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
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContentManagementSortBugFixSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ロール・検証ユーザーの安全確保
        $admin = User::where('role', UserRole::Admin)->first()
            ?? User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus, 'name' => 'ソート検証管理者']);

        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'sort-bugfix-slug-'.Str::random(5),
            'name' => 'ソート不具合検証カテゴリ',
        ]);

        $certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => '教材ソート検証用資格',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 2. 登録順とは「あえて逆」の順序番号（order）を持つPartを2件インサート
        // 期待値: 画面上では 順序2 ➡ 順序5 の順番で正しく並ぶこと
        $partSecond = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'title' => '【順序2】後から表示されるべきPart',
            'description' => '検証データ',
            'order' => 2, // 👈 登録は1番目だが、並び順は2番目
            'status' => ContentStatus::Published,
        ]);

        $partFirst = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'title' => '【順序1】最初に表示されるべきPart',
            'description' => '検証データ',
            'order' => 1, // 👈 登録は2番目だが、並び順は1番目
            'status' => ContentStatus::Published,
        ]);

        // 3. 登録順とは「あえて逆」の順序番号を持つChapterを2件インサート
        $chapterSecond = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $partFirst->id,
            'title' => '【順序2】後から表示されるべきChapter',
            'description' => '検証データ',
            'order' => 2,
            'status' => ContentStatus::Published,
        ]);

        $chapterFirst = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $partFirst->id,
            'title' => '【順序1】最初に表示されるべきChapter',
            'description' => '検証データ',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);

        // 4. 登録順とは「あえて逆」の順序番号を持つSectionを2件インサート
        Section::create([
            'id' => (string) Str::ulid(),
            'chapter_id' => $chapterFirst->id,
            'title' => '【順序2】後から表示されるべきSection',
            'description' => '検証データ',
            'body' => '本文',
            'order' => 2,
            'status' => ContentStatus::Published,
        ]);

        Section::create([
            'id' => (string) Str::ulid(),
            'chapter_id' => $chapterFirst->id,
            'title' => '【順序1】最初に表示されるべきSection',
            'description' => '検証データ',
            'body' => '本文',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);
    }
}
