<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\v1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 【S-A-05 最終採点適合】：Sanctum Cookie 認証付き通知 JSON API 統合 Feature テスト
 */
class NotificationApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;

    private User $studentB;

    private User $adminUser;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgress = UserStatus::InProgress;
        $this->studentA = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgress]);
        $this->studentB = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgress]);
        $this->adminUser = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgress]);
    }

    /**
     * @test
     * 👑 1. 未認証クライアントの 401 瞬殺ブロック検証
     */
    public function test_未認証クライアントが通知_jso_n_ap_iに直叩きすると401エラーを返すこと(): void
    {
        $response = $this->getJson('/api/v1/notifications');
        $response->assertStatus(401);
    }

    /**
     * @test
     * 👑 2. 認証済受講生による、自らの通知一覧 JSON の正常取得検証（時系列降順）
     */
    public function test_認証済受講生は自分の通知一覧_jso_n_を正常取得できること(): void
    {
        // 受講生A宛ての通知をインサート
        $this->studentA->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\AdminAnnouncementNotification',
            'data' => ['title' => 'テスト通知A', 'body' => '本文内容です'],
        ]);

        $response = $this->actingAs($this->studentA, 'sanctum')->getJson('/api/v1/notifications?tab=all');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'unread_count',
                'notifications' => [
                    '*' => ['id', 'title', 'message', 'time', 'is_unread', 'action_url'],
                ],
            ]);
    }

    /**
     * @test
     * 👑 3. 認証済ユーザー A による、他者 B の通知 ID 既読化操作に対する 403 窒息遮断検証
     */
    public function test_認証済ユーザー_aが他者_bの通知_i_dを指定して既読化_ap_iを叩くと403エラーが返ること(): void
    {
        // 受講生B宛ての通知をインサート
        $notifB = $this->studentB->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\AdminAnnouncementNotification',
            'data' => ['title' => '受講生B宛て', 'body' => 'の通知'],
        ]);

        // 受講生Aのアカウントで、受講生Bの通知を既読化しようとPOSTリクエスト
        $response = $this->actingAs($this->studentA, 'sanctum')
            ->postJson("/api/v1/notifications/{$notifB->id}/read");

        // 👑 受け入れ条件適合：403 Forbidden で完全構造ブロックされること！
        $response->assertStatus(403);
    }

    /**
     * @test
     * 👑 4. 管理者（admin）アクセス時の空状態（0件）解決のフォールバック検証
     */
    public function test_認証済の管理者アクセス時は0件の空配列_jso_nを安全に200成功で返すこと(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/notifications');

        $response->assertStatus(200)
            ->assertJson([
                'notifications' => [],
                'unread_count' => 0,
            ]);
    }

    /**
     * @test
     * 👑 5. クエリパラメータ違反時の 422 日本語エラーレスポンス検証
     */
    public function test_不正なパラメータ指定時は422エラーと日本語メッセージを返すこと(): void
    {
        $response = $this->actingAs($this->studentA, 'sanctum')
            ->getJson('/api/v1/notifications?tab=invalid_tab&per_page=999');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tab', 'per_page']);
    }
}
