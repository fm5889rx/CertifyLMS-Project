<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress->value ?? 'in_progress';

        // 1. 各ロールユーザーを確保
        $studentA = User::where('role', UserRole::Student->value ?? 'student')->first()
            ?? User::factory()->create(['role' => UserRole::Student->value ?? 'student', 'status' => $inProgressStatus, 'name' => '受講生A']);

        $studentB = User::where('role', UserRole::Student->value ?? 'student')->where('id', '!=', $studentA->id)->first()
            ?? User::factory()->create(['role' => UserRole::Student->value ?? 'student', 'status' => $inProgressStatus, 'name' => '受講生B']);
        $admin = User::where('role', UserRole::Admin)->first()
            ?? User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus, 'name' => '本物管理者']);

        // 2. 親の多重外部キー制約を純粋Eloquentの鎖でマウント
        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'lms-announcement-category-'.Str::random(5),
            'name' => 'お知らせ検証用カテゴリ',
        ]);

        $certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => 'お知らせ対象IT資格マスタ',
            'difficulty' => CertificationDifficulty::Intermediate->value,
            'status' => CertificationStatus::Published->value,
            'created_by_user_id' => $studentA->id,
            'updated_by_user_id' => $studentA->id,
        ]);

        // 受講生Aを資格マスタへ受講登録
        $enrollmentA = Enrollment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $studentA->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning->value,
            'current_term' => TermType::BasicLearning->value,
            'exam_date' => now()->addMonths(3)->toDateString(),
        ]);

        // 3タイプ混在の11件ループ生成 ＆ 既存通知基盤連動データの同時投入
        for ($i = 1; $i <= 11; $i++) {
            $announcementId = (string) Str::ulid();

            // 3タイプの配信対象を美しく混在再現
            if ($i % 3 === 1) {
                $targetType = AnnouncementTargetType::AllStudents;
                $targetId = null;
                $dispatchedCount = 2; // 受講生A, Bの2人に届く想定
                $targetUsers = [$studentA, $studentB];
            } elseif ($i % 3 === 2) {
                $targetType = AnnouncementTargetType::Certification;
                $targetId = $certification->id;
                $dispatchedCount = 1; // 該当資格にいる受講生Aのみに届く想定
                $targetUsers = [$studentA];
            } else {
                $targetType = AnnouncementTargetType::User;
                $targetId = $studentA->id;
                $dispatchedCount = 1; // 受講生A単体に届く想定
                $targetUsers = [$studentA];
            }

            // ① 管理者側のお知らせ配信実績を生成
            $announcement = Announcement::create([
                'id' => $announcementId,
                'title' => "【重要連絡 No.{$i}】運営からのお知らせ配信タイトル",
                'body' => "これは第 {$i} 件目の運営配信メッセージ本文です。メンテナンス予告や学習キャンペーンなどのプレーンテキスト全文がここに記録され、受講生は通知詳細ページから閲覧可能です。",
                'target_type' => $targetType,
                'target_id' => $targetId,
                'dispatched_count' => $dispatchedCount,
                'created_by_user_id' => $admin->id,
                'dispatched_at' => now()->subHours(12 - $i),
                'created_at' => now()->subHours(12 - $i),
                'updated_at' => now()->subHours(12 - $i),
            ]);

            // ② 既存の通知基盤テーブル（notifications）へ、一斉配信された本物の新着レコードとしてマウント
            foreach ($targetUsers as $targetUser) {
                $targetUser->notify(new AdminAnnouncementNotification($announcement));
            }
        }
    }
}
