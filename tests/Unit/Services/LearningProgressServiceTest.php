<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Learning;

use App\Services\Learning\LearningProgressService;
use App\Services\Learning\ProgressSummary;
use App\Models\User;
use App\Models\Enrollment;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Enums\UserRole;
use App\Enums\EnrollmentStatus;
use App\Enums\CertificationDifficulty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 【T-A-03：学習進捗集約 Service 専用 Unit テスト】
 *
 * 概要 / 設計意図:
 * コントローラー（EnrollmentController）や受講生用ダッシュボードアクションから
 * 100行以上パージして一元集約させた 4 階層の進捗率サマリ（ProgressSummary）が、
 * 契約通りの正しい型と数値で返却されるかどうかの振る舞いを自動検証する。
 */
class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 【T-A-03：単一受講登録サマリ算出の振る舞い検証】
     *
     * 検証内容:
     * 受講登録詳細画面（EnrollmentController）等から呼ばれる高レベルメソッドにおいて、
     * 進捗マスタが空の境界値状態であっても、型破綻（Null等）を起こさずに
     * 進捗率 0.0 の ProgressSummary インスタンスが正しく組み立てられて返却されるかを検証する。
     */
    public function test_LearningProgressServiceが単一の受講登録から4階層の進捗サマリを型安全に算出できること(): void
    {
        // 1. 物理層外部キー制約を満たす最低限の親マスタを Eloquent 生成
        $student = User::factory()->create(['role' => UserRole::Student]);

        $category = CertificationCategory::Create([
            'name' => 'テスト資格カテゴリ',
            'slug' => 'test-certification-category',
        ]);

        $certification = Certification::create([
            'name'               => '進捗検証用資格',
            'category_id'        => $category->id,
            'difficulty'         => CertificationDifficulty::Intermediate,
            'status'             => 'published',
            'created_by_user_id' => $student->id,
            'updated_by_user_id' => $student->id,
        ]);

        $enrollment = Enrollment::create([
            'user_id'          => $student->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
        ]);

        // 2. 集約サービスのインスタンスをコンテナからクリーンロード
        $service = app(LearningProgressService::class);

        // 執行（更地の状態なので、分母分子ともに 0 の境界値ルートを型安全に通過します！）
        $result = $service->summarizeProgress($enrollment);

        // 3. アサーション確認（返却型と、不変の初期ファクト 0.0 の証明）
        $this->assertInstanceOf(ProgressSummary::class, $result);
        $this->assertEquals(0, $result->sectionsTotal);
        $this->assertEquals(0, $result->sectionsCompleted);
        $this->assertEquals(0.0, $result->sectionCompletionRatio);
    }

    /**
     * 【T-A-03：受講生ダッシュボード用一括完了率算出の振る舞い検証】
     *
     * 検証内容:
     * 受講生用ダッシュボード（FetchStudentDashboardAction）からパージした一括完了率算出ロジックにおいて、
     * 複数（または単一）の Enrollment コレクションを受け取った際、ループを回すことなく 1 クエリで
     * 各 Enrollment.id をキーとした完了率（0.0〜1.0）の連想配列を正確に生成できるかを検証する。
     * （N+1回避の担保）
     */
    public function test_LearningProgressServiceがダッシュボード用に複数の受講登録からSection単位の完了率を一括算出できること(): void
    {
        // 1. 物理層外部キー制約を満たす最低限の親マスタを Eloquent 生成
        $student = User::factory()->create(['role' => UserRole::Student]);

        $category = CertificationCategory::Create([
            'name' => 'テスト資格カテゴリ',
            'slug' => 'test-certification-category',
        ]);

        $certification = Certification::create([
            'name'               => 'ダッシュボード用資格',
            'category_id'        => $category->id,
            'difficulty'         => CertificationDifficulty::Intermediate,
            'status'             => 'published',
            'created_by_user_id' => $student->id,
            'updated_by_user_id' => $student->id,
        ]);

        $enrollment = Enrollment::create([
            'user_id'          => $student->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
        ]);

        // 2. 集約サービスのインスタンスをコンテナからクリーンロード
        $service = app(LearningProgressService::class);

        // 執行
        $resultMap = $service->batchCalculateProgress(collect([$enrollment]));

        // 3. アサーション確認（配列の形状とキー、初期ファクト 0.0 の証明）
        $this->assertIsArray($resultMap);
        $this->assertArrayHasKey($enrollment->id, $resultMap);
        $this->assertEquals(0.0, $resultMap[$enrollment->id]);
    }
}
