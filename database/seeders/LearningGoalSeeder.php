<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\LearningGoal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LearningGoalSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::where('role', UserRole::Student)->first() ?? User::factory()->create(['role' => UserRole::Student, 'name' => '受講生A']);

        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'pagination-goal-category-'.Str::random(5),
            'name' => '目標テスト用ITカテゴリ',
        ]);

        $certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => '最高峰クラウド資格マスタ',
            'difficulty' => CertificationDifficulty::Intermediate->value,
            'status' => CertificationStatus::Published->value,
            'created_by_user_id' => $student->id,
            'updated_by_user_id' => $student->id,
        ]);

        $enrollment = Enrollment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning->value,
            'current_term' => TermType::BasicLearning->value,
            'exam_date' => now()->addMonths(3)->toDateString(),
        ]);

        // 目標データの11件ループ生成
        // 達成状況が分かりやすい順序（achieved_atの有無）で配置
        for ($i = 1; $i <= 11; $i++) {
            LearningGoal::create([
                'id' => (string) Str::ulid(),
                'enrollment_id' => $enrollment->id,
                'title' => "【目標No.{$i}】資格取得に向けたステップタスク",
                'description' => "実機テストのためのダミー詳細文章です（第 {$i} 章分）。",
                'target_date' => now()->addDays($i)->toDateString(),
                // 7件は達成済み、4件は未達成にして混在させます
                'achieved_at' => $i <= 7 ? now()->subDays($i) : null,
            ]);
        }
    }
}
