<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Models\User;
use App\Models\Enrollment;
use App\Models\Certification;
use App\Models\Meeting;
use App\Enums\UserRole;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Models\CertificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests\Feature\MeetingConcurrencyBugFixTest
 * 
 * 【B-A-01 最終適合テスト】面談同一コーチ・同一時刻枠二重予約バグフィックス検証。
 * 元のマイグレーションに直接修正を加え、MySQL物理層で二重インサートが
 * 絶対に拒絶（Unique制約違反）されることを Eloquent 空間で検証します。
 */
class MeetingConcurrencyBugFixTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;
    private User $studentB;
    private User $coach;
    private Enrollment $enrollmentA;
    private Enrollment $enrollmentB;
    private Carbon $scheduledAt;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. テストに必要な受講生2名、および共通の担当コーチを1名生成（ファクトリ）
        $this->studentA = User::factory()->create(['role' => UserRole::Student]);
        $this->studentB = User::factory()->create(['role' => UserRole::Student]);
        $this->coach    = User::factory()->create(['role' => UserRole::Coach]);

        // 2. 視覚化カタログを Eloquent で生成
        $category = CertificationCategory::create([
            'name' => 'テストカテゴリ',
            'slug' => 'test-category',
        ]);

        // 3. 資格マスタを Eloquent で生成
        $certification = Certification::create([
            'name'               => '並行性テスト対象資格',
            'category_id'        => $category->id,
            'difficulty'         => CertificationDifficulty::Intermediate,
            'status'             => CertificationStatus::Published,
            'created_by_user_id' => $this->coach->id,
            'updated_by_user_id' => $this->coach->id,
        ]);

        // コーチを資格マスタへ多対多リレーション結合（アタッチ）
        $certification->coaches()->attach($this->coach->id, [
            'assigned_by_user_id' => $this->coach->id,
            'assigned_at'         => now(),
        ]);

        // 4. 各受講生の受講登録（Enrollment）を Learning 状態でマウント
        $this->enrollmentA = Enrollment::create([
            'user_id'          => $this->studentA->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
        ]);

        $this->enrollmentB = Enrollment::create([
            'user_id'          => $this->studentB->id,
            'certification_id' => $certification->id,
            'status'           => EnrollmentStatus::Learning->value,
        ]);

        // 面談の予約対象時刻を「明日の午前10時」に型ロック設定
        $this->scheduledAt = Carbon::tomorrow()->setHour(10)->startOfHour();
    }

    /**
     * 二重予約（ダブルブッキング）封殺検証
     */
    public function test_同じコーチの同じ時刻枠に対して2行目のインサートが走った瞬間にMySQLの複合ユニーク制約により物理的に二重予約を拒絶すること(): void
    {
        // 1件目の予約レコードを Eloquent で正常にインサート（受講生Aが枠を確保）
        Meeting::create([
            'enrollment_id'        => $this->enrollmentA->id,
            'coach_id'             => $this->coach->id,
            'student_id'           => $this->studentA->id,
            'scheduled_at'         => $this->scheduledAt,
            'status'               => MeetingStatus::Reserved->value,
            'topic'                => '受講生AのStripe実装に関する面談相談。',
            'meeting_url_snapshot' => $this->coach->meeting_url,
        ]);

        // 2件目として「全く同じコーチ（coach_id）」かつ「全く同じ時刻（scheduled_at）」で
        // 受講生Bがインサートを行った瞬間、MySQLの一意制約により例外が発生するか検証
        //
        $this->expectException(UniqueConstraintViolationException::class);

        Meeting::create([
            'enrollment_id'        => $this->enrollmentB->id,
            'coach_id'             => $this->coach->id,     // 同じコーチ
            'student_id'           => $this->studentB->id,
            'scheduled_at'         => $this->scheduledAt,   // 同時刻の重複
            'status'               => MeetingStatus::Reserved->value,
            'topic'                => '受講生Bが同時にボタンを押して滑り込んできたリクエスト。',
            'meeting_url_snapshot' => $this->coach->meeting_url,
        ]);
    }
}
