<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests\Feature\Http\Certificate\CertificateDownloadIntegrationTest
 *
 * 【S-A-04 最終監査テスト】修了証PDF出力・マルチロール認可統合Featureテスト。
 */
class CertificateDownloadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $assignedCoach;

    private User $otherCoach;

    private User $admin;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. 物理ディスク（public/private）をテスト空間へ安全にフェイク隔離
        Storage::fake('public');
        Storage::fake('private');

        // 2. ロールマトリクス検証用の全アクターを生成（他メンバーのモデルファクトリ）
        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'status' => UserStatus::InProgress,
        ]);

        $this->assignedCoach = User::factory()->create(['role' => UserRole::Coach]);
        $this->otherCoach = User::factory()->create(['role' => UserRole::Coach]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        // 3. 親カテゴリモデル（CertificationCategory）を新規生成
        $category = CertificationCategory::create([
            'name' => 'テスト対象カテゴリ区分',
            'slug' => 'test-certification-category-slug-'.Str::ulid(),
        ]);

        // 4. 資格マスタを Eloquent で新規生成
        $certification = Certification::create([
            'name' => 'テスト対象資格マスタ',
            'category_id' => $category->id, // 👑 生成した本物の親マスタモデルのIDを美しく結合！！！
            'difficulty' => CertificationDifficulty::Intermediate, // 👑 の本物Enumオブジェクトをダイレクト注入！
            'status' => CertificationStatus::Published->value,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);

        // 5. 資格情報に担当コーチをバインド
        $certification->coaches()->attach($this->assignedCoach->id, [
            'assigned_by_user_id' => $this->admin->id,
            'assigned_at' => now(),
        ]);

        // 受講登録（Enrollment）を Eloquent で生成
        $enrollment = Enrollment::create([
            'user_id' => $this->student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Passed->value,  // 修了済
            'current_term' => TermType::BasicLearning->value,   // 基礎ターム
            'passed_at' => now(),
        ]);

        // . 他メンバーの実在する修了証（Certificate）モデルのレコードを生成
        $this->certificate = Certificate::create([
            'user_id' => $this->student->id,
            'enrollment_id' => $enrollment->id,
            'certification_id' => $certification->id,
            'pdf_path' => '',
            'issued_at' => now(),
        ]);
    }

    /**
     * 1. 受講生本人の認可検証
     */
    public function test_受講生本人が自分の修了証ダウンロード要求を送信した際に_policyをノーエラー通過してファイル添付形式で受信できること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('certificates.download', $this->certificate->id));

        // 添付形式（Attachment）のBinaryFileResponseヘッダーを厳格アサーション
        $response->assertStatus(200);
        $this->assertTrue($response->headers->has('content-disposition'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));

        // 物理ディスク（publicディスク）の certificates/ フォルダ配下に、本物のPDF実体が全自動生成されているか
        $this->certificate->refresh();
        $this->assertNotEmpty($this->certificate->pdf_path);
        Storage::disk('public')->assertExists($this->certificate->pdf_path);
    }

    /**
     * 2. 担当外の受講生によるハッキング拒絶検証
     */
    public function test_他人の修了証をダウンロードしようとした不正な受講生アカウントからの要求は_policyによって403認可拒絶されること(): void
    {
        $maliciousStudent = User::factory()->create(['role' => UserRole::Student]);

        $response = $this->actingAs($maliciousStudent)
            ->get(route('certificates.download', $this->certificate->id));

        $response->assertStatus(403); // 鉄壁のブロック！
    }

    /**
     * 3. 担当コーチの認可通過検証
     */
    public function test_資格の担当コーチが所属受講生の修了証ダウンロード要求を送信した際に中間テーブルの割当を検知して正常にダウンロードを許可すること(): void
    {
        $response = $this->actingAs($this->assignedCoach)
            ->get(route('certificates.download', $this->certificate->id));

        $response->assertStatus(200);
    }

    /**
     * 4. 担当外コーチの認可拒絶検証（プライバシー要件）
     */
    public function test_担当外資格の修了証をダウンロードしようとした別コーチからの要求は他コーチの領域へのプライバシー尊重のため403で厳格に弾き落とすこと(): void
    {
        $response = $this->actingAs($this->otherCoach)
            ->get(route('certificates.download', $this->certificate->id));

        $response->assertStatus(403); // 鉄壁のブロック！
    }

    /**
     * 5. 管理者の全件参照認可検証
     */
    public function test_システム管理者がダウンロード要求を送信した際は運用および監査対応のため無条件でダウンロードを貫通許可すること(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('certificates.download', $this->certificate->id));

        $response->assertStatus(200);
    }
}
