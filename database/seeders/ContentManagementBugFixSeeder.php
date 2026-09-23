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
use App\Models\Part;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContentManagementBugFixSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ロールユーザーを安全に確保
        $coachA = User::where('role', UserRole::Coach)->first()
            ?? User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'name' => '担当コーチA']);

        $admin = User::where('role', UserRole::Admin)->first()
            ?? User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus, 'name' => '中央管理者']);

        // 親となるマスタカテゴリの生成
        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'bugfix-content-slug-'.Str::random(5),
            'name' => '教材管理バグ検証カテゴリ',
        ]);

        // 2. 担当資格 X の誕生
        $certificationX = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => '【担当】資格マスターX（閲覧編集OK）',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 必須中間テーブル属性の完全パッキング
        $certificationX->coaches()->syncWithoutDetaching([
            $coachA->id => [
                'id' => (string) Str::ulid(),
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now()->toDateTimeString(),
            ],
        ]);

        // 3. 担当外資格 Y の誕生
        $certificationY = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => '【担当外】資格マスターY（アクセス403制限）',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 4. 実機確認用に、それぞれの資格に教材Partを1件ずつ美しくインサート
        Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certificationX->id,
            'title' => '第1章：担当資格の導入セクション',
            'description' => 'コーチAが閲覧・編集・並び替えを実行できる本物の教材 Part データです。',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);

        Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certificationY->id,
            'title' => '第1章：担当外資格の秘匿セクション',
            'description' => 'コーチAがアクセスした際に厳格に403で弾かれなければならない防衛データです。',
            'order' => 1,
            'status' => ContentStatus::Published,
        ]);
    }
}
