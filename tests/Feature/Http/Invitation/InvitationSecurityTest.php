<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Invitation;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Auth\OnboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Plan $studentPlan;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $inProgressStatus = UserStatus::InProgress;
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);

        $this->studentPlan = Plan::create([
            'id' => (string) Str::ulid(),
            'name' => 'テスト受講プラン',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
            'status' => 'published',
            'sort_order' => 0,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }

    // ============================================================
    // 1. 受講生 (Student) ルートのトークン使い回しストーリー検証
    // ============================================================

    public function test_1_受講生招待_ur_lは一度オンボーディングを完了した後は使い回しが完全に封殺され無効化画面が返ること(): void
    {
        $studentUser = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::Invited, 'plan_id' => $this->studentPlan->id]);
        $invitation = $this->createTestInvitation($studentUser, UserRole::Student, InvitationStatus::Pending);

        // 本物の有効期限を持った正規の署名URLを動的発行
        $signedUrl = URL::temporarySignedRoute('onboarding.show', $invitation->expires_at, ['invitation' => $invitation->id]);

        // ① 最初のアクセス：未使用状態なので、正規の署名URLにより verify() を安全に突破して正常画面（200 OK）が開くことを証明！
        $this->assertTrue($invitation->isUsable());
        $response1 = $this->withoutMiddleware([ValidateSignature::class])
            ->get($signedUrl);
        $response1->assertStatus(200);

        // ② オンボーディングの実行：登録を完了（Acceptedへ更新）
        $action = resolve(OnboardAction::class);
        $action($invitation, [
            'name' => 'セットアップ完了受講生',
            'password' => 'password123',
        ]);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => InvitationStatus::Accepted->value]);

        // ③ 2回目の再アクセス：
        // データベース側がすでに Accepted に変わっているため、OnboardingControllerの1行目で
        // 200 OK を伴って「auth.invitation-invalid」の画面が出力される事を検証
        $response2 = $this->withoutMiddleware([ValidateSignature::class])
            ->get($signedUrl);

        $response2->assertStatus(200);
        $response2->assertSee('招待リンクが無効または期限切れです');
    }

    // ============================================================
    // 2. コーチ (Coach) ルートのトークン使い回しストーリー検証
    // ============================================================

    public function test_2_コーチ招待_ur_lは一度オンボーディングを完了した後は使い回しが完全に封殺され無効化画面が返ること(): void
    {
        $coachUser = User::factory()->create(['role' => UserRole::Coach, 'status' => UserStatus::Invited]);
        $invitation = $this->createTestInvitation($coachUser, UserRole::Coach, InvitationStatus::Pending);

        // 有効な署名URLを発行
        $signedUrl = URL::temporarySignedRoute('onboarding.show', $invitation->expires_at, ['invitation' => $invitation->id]);

        // ① 最初のアクセス：未使用状態なので正常表示（200 OK）
        $this->assertTrue($invitation->isUsable());
        $response1 = $this->withoutMiddleware([ValidateSignature::class])
            ->get($signedUrl);
        $response1->assertStatus(200);

        // ② オンボーディングの実行：コーチ必須の meeting_url を含めて登録完了
        $action = resolve(OnboardAction::class);
        $action($invitation, [
            'name' => 'セットアップ完了コーチ',
            'password' => 'password123',
            'meeting_url' => 'https://zoom.us',
        ]);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => InvitationStatus::Accepted->value]);

        // ③ 2回目の再アクセス：同じく200 OKでの無効画面表示と、本物のメッセージ文言を厳格アサート！
        $response2 = $this->withoutMiddleware([ValidateSignature::class])
            ->get($signedUrl);

        $response2->assertStatus(200);
        $response2->assertSee('招待リンクが無効または期限切れです');
    }

    /**
     * テスト用インビテーション生成のヘルパーメソッド
     */
    private function createTestInvitation(User $user, UserRole $role, InvitationStatus $status): Invitation
    {
        return Invitation::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $role->value,
            'invited_by_user_id' => $this->admin->id,
            'expires_at' => now()->addDays(7),
            'status' => $status->value,
        ]);
    }
}
