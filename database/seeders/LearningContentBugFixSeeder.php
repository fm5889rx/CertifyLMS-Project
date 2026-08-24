<?php

declare(strict_types=1);

namespace Database\Seeders;

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
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LearningContentBugFixSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ロール・検証ユーザーの安全確保（受講生と管理者）
        $studentA = User::where('role', UserRole::Student)->first()
            ?? User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus, 'name' => 'バグ検証受講生A']);

        $admin = User::where('role', UserRole::Admin)->first()
            ?? User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus, 'name' => '教材統括管理者']);

        // 親となるマスタカテゴリの生成
        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'learning-bugfix-slug-' . Str::random(5),
            'name' => '受講生閲覧バグ検証カテゴリ',
        ]);

        // 2. 管理者が「公開停止（アーカイブ）」した資格 X
        // ※ 既存のEnum（CertificationStatus::Archivedに適合させる
        $statusArchived = defined('\App\Enums\CertificationStatus::Archived')
            ? CertificationStatus::Archived
            : (defined('\App\Enums\CertificationStatus::Private') ? CertificationStatus::Private : 'archived');

        $certificationX = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => '【公開停止中】資格マスターX（受講生は404になるべき資格）',
            'difficulty'          => CertificationDifficulty::Intermediate,
            'status'              => $statusArchived, // 👈 門番となる「公開停止・アーカイブ」状態
            'created_by_user_id'  => $admin->id,
            'updated_by_user_id'  => $admin->id,
        ]);

        // 3. 受講生 A は資格 X に受講登録済み（受講中）。本物の受講登録モデル（Enrollment）を使用する
        Enrollment::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $studentA->id,
            'certification_id' => $certificationX->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // 4. 検証用の教材子リソースを1件ずつ生成
        $part = Part::create([
            'id'               => (string) Str::ulid(),
            'certification_id' => $certificationX->id,
            'title'            => '非公開資格の隠蔽Part',
            'description'      => 'バグ検証データ',
            'order'            => 1,
            'status'           => ContentStatus::Published,
        ]);

        $chapter = Chapter::create([
            'id'           => (string) Str::ulid(),
            'part_id'      => $part->id,
            'title'        => '非公開資格の隠蔽Chapter',
            'description'  => 'バグ検証データ',
            'order'        => 1,
            'status'       => ContentStatus::Published,
        ]);

        Section::create([
            'id'           => (string) Str::ulid(),
            'chapter_id'   => $chapter->id,
            'title'        => '非公開資格の隠蔽Section',
            'description'  => 'バグ検証データ',
            'body'         => '## この本文は資格が公開停止のため、受講生に絶対に読まれてはなりません。',
            'order'        => 1,
            'status'       => ContentStatus::Published,
        ]);
    }
}
