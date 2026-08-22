<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EnrollmentNoteSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress->value ?? 'in_progress';

        // 1. 各ロールのユーザーを安全に取得、無ければファクトリで生成
        $student = User::where('role', UserRole::Student->value ?? 'student')->first()
            ?? User::factory()->create(['role' => UserRole::Student->value ?? 'student', 'status' => $inProgressStatus, 'name' => '受講生花子']);

        $coachA = User::where('role', UserRole::Coach->value ?? 'coach')->first()
            ?? User::factory()->create(['role' => UserRole::Coach->value ?? 'coach', 'status' => $inProgressStatus, 'name' => 'コーチ太郎']);

        $coachB = User::where('role', UserRole::Coach->value ?? 'coach')->where('id', '!=', $coachA->id)->first()
            ?? User::factory()->create(['role' => UserRole::Coach->value ?? 'coach', 'status' => $inProgressStatus, 'name' => 'コーチ花子']);

        // 2. 5件目の外部キー制約を完璧にクリアするマスタデータの新設
        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'lms-note-category-' . Str::random(5),
            'name' => 'メモ実機検証用カテゴリ',
        ]);

        $certification = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => '最高峰インフラ資格マスタ',
            'difficulty'          => CertificationDifficulty::Intermediate->value,
            'status'              => CertificationStatus::Published->value,
            'created_by_user_id'  => $student->id,
            'updated_by_user_id'  => $student->id,
        ]);

        $enrollment = Enrollment::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $student->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
            'current_term'     => TermType::BasicLearning->value,
            'exam_date'        => now()->addMonths(3)->toDateString(),
        ]);

        // コーチAが書いたメモとコーチBが書いたメモを綺麗に混在させ、時系列で並べる
        for ($i = 1; $i <= 11; $i++) {
            // 奇数回はコーチ太郎、偶数回はコーチ花子が執筆したことにして、権限による編集ボタンの出し分けをブラウザで確認
            $writerId = ($i % 2 === 0) ? $coachB->id : $coachA->id;
            $writerName = ($i % 2 === 0) ? 'コーチ花子' : 'コーチ太郎';

            EnrollmentNote::create([
                'id'            => (string) Str::ulid(),
                'enrollment_id' => $enrollment->id,
                'user_id'       => $writerId,
                'body'          => "【時系列ログ No.{$i}】（記述者: {$writerName}）受講生の最近の進捗とchat応答速度に関する日常観察記録テキストです。",
                'created_at'    => now()->subHours(12 - $i), // 時系列順に美しく整列
                'updated_at'    => now()->subHours(12 - $i),
            ]);
        }
    }
}
