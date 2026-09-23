<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Mentoring;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coachA;

    private User $coachB;

    private User $adminUser;

    private Enrollment $enrollment;

    /**
     * 各テストの初期状態セットアップ（昨夜磨き上げたEnum->value完全同期仕様）
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ユーザーの生成
        $this->student = User::factory()->student()->create(['status' => $inProgressStatus]);
        $this->coachA = User::factory()->coach()->create(['status' => $inProgressStatus]);
        $this->coachB = User::factory()->coach()->create(['status' => $inProgressStatus]);
        $this->adminUser = User::factory()->admin()->create(['status' => $inProgressStatus]);

        // 2. 多重外部キー制約をマウント
        $category = CertificationCategory::create([
            'id' => (string) Str::ulid(),
            'slug' => 'test-memo-category-'.Str::random(5),
            'name' => 'メモ検証用カテゴリ',
        ]);

        $certification = Certification::create([
            'id' => (string) Str::ulid(),
            'category_id' => $category->id,
            'name' => 'メモ対象IT資格',
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $this->adminUser->id,
            'updated_by_user_id' => $this->adminUser->id,
        ]);

        $this->enrollment = Enrollment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning,
            'current_term' => TermType::BasicLearning,
            'exam_date' => now()->addMonths(3)->toDateString(),
        ]);
    }

    /**
     * ① メモの追加（store）＆ 担当コーチ認可テスト
     */
    public function test_担当コーチは受講生の受講登録に対して新しい観察メモを正常に追加できること(): void
    {
        $postData = ['body' => '最近Slackの反応が24時間以上遅れている。次回面談で体調を確認する。'];

        $response = $this->actingAs($this->coachA)
            ->from(route('enrollment-notes.store', $this->enrollment->id))
            ->post(route('enrollment-notes.store', $this->enrollment->id), $postData);

        $response->assertStatus(302);

        // データベースに執筆者ID付きで正しく実在することを証明
        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $this->enrollment->id,
            'user_id' => $this->coachA->id,
            'body' => '最近Slackの反応が24時間以上遅れている。次回面談で体調を確認する。',
        ]);
    }

    /**
     * ② 編集画面表示 ＆ 執筆者本人以外の変更操作拒否テスト
     */
    public function test_コーチは自分が作成したメモのみ編集画面を表示でき他コーチのメモの変更は403遮断されること(): void
    {
        // コーチAがメモを執筆
        $note = EnrollmentNote::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'user_id' => $this->coachA->id,
            'body' => 'コーチAが書いた秘密のメモ',
        ]);

        // 1. 作成者本人は正常に編集ページを表示できる（200OK）
        $response = $this->actingAs($this->coachA)->get(route('enrollment-notes.edit', $note->id));
        $response->assertStatus(200);

        // 2. 他のコーチBが勝手にその編集ページに侵入しようとした場合は403直撃遮断！
        $response = $this->actingAs($this->coachB)->get(route('enrollment-notes.edit', $note->id));
        $response->assertStatus(403);

        // 3. 他のコーチBによる勝手なPATCH更新リクエストも一律で403拒否！
        $response = $this->actingAs($this->coachB)->patch(route('enrollment-notes.update', $note->id), ['body' => '改ざん本文']);
        $response->assertStatus(403);
    }

    /**
     * ③ 管理者による全越境操作（編集・削除）の特権テスト
     */
    public function test_管理者は他人が作成した任意の受講生メモを越境して編集および削除できること(): void
    {
        $note = EnrollmentNote::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'user_id' => $this->coachA->id,
            'body' => 'コーチAの生データ',
        ]);

        // 1. 管理者は他人のメモでも安全に更新可能
        $response = $this->actingAs($this->adminUser)
            ->from(route('enrollment-notes.edit', $note->id))
            ->patch(route('enrollment-notes.update', $note->id), ['body' => '管理者が修正したクリーンな本文']);

        $response->assertStatus(302);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '管理者が修正したクリーンな本文']);

        // 2. 管理者は他人のメモでも安全に物理削除可能
        $response = $this->actingAs($this->adminUser)->delete(route('enrollment-notes.destroy', $note->id));
        $response->assertStatus(302);

        // 物理削除のため missing を厳格に証明
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    /**
     * ④ 受講生本人に対する完全隠蔽・アクセス遮断（403）テスト
     */
    public function test_受講生本人は自分の受講登録に対するメモの追加や変更を一切実行できず403拒否されること(): void
    {
        // 1. 受講生による勝手な追加の遮断検証
        $response = $this->actingAs($this->student)->post(route('enrollment-notes.store', $this->enrollment->id), ['body' => '自分で書いたメモ']);
        $response->assertStatus(403);

        $note = EnrollmentNote::create([
            'id' => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'user_id' => $this->coachA->id,
            'body' => '社外秘の観察ログ',
        ]);

        // 2. 受講生による勝手な編集画面への侵入も403直撃拒否！
        $response = $this->actingAs($this->student)->get(route('enrollment-notes.edit', $note->id));
        $response->assertStatus(403);
    }
}
