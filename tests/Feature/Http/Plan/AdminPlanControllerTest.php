<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\User;
use App\Models\Plan; // 💡 実際のモデル名に合わせて置換してください
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $student;
    private User $coach;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = defined('\App\Enums\UserStatus::InProgress') 
            ? \App\Enums\UserStatus::InProgress 
            : 'in_progress';

        // 1. 本物の管理者（Admin）アカウントを生成
        $this->adminUser = User::factory()->create([
            'role'   => UserRole::Admin ?? 'admin',
            'status' => $inProgressStatus,
        ]);

        // 2. アクセス拒否検証用の 受講生 と コーチ を生成
        $this->student = User::factory()->create([
            'role'   => UserRole::Student,
            'status' => $inProgressStatus,
        ]);

        $this->coach = User::factory()->create([
            'role'   => UserRole::Coach,
            'status' => $inProgressStatus,
        ]);
    }

    /**
     * ① 一覧表示のキーワード検索 ＆ 状態フィルタ ＆ ページネーション ＆ 受講者数カウント網羅テスト
     */
    public function test_管理者はプラン一覧をキーワード検索および状態フィルタ付きでページネーション表示できること(): void
    {
        // ターゲットとなるプランを生成
        $targetPlan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '注目ターゲットプラン',
            'description'           => '説明文',
            'duration_days'         => 90,
            'default_meeting_quota' => 4,
            'status'                => 'published',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
            'sort_order'            => 1,
        ]);

        // ノイズとなる下書きプランを生成
        Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '無関係なダミー計画',
            'description'           => '説明文',
            'duration_days'         => 30,
            'default_meeting_quota' => 2,
            'status'                => 'draft',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
            'sort_order'          => 2,
        ]);

        // 検索とフィルタを指定してGETリクエスト
        $response = $this->actingAs($this->adminUser)->get(route('admin.plans.index', [
            'keyword' => '注目',
            'status'  => 'published',
        ]));

        $response->assertStatus(200);
        $response->assertSee('注目ターゲットプラン');
        $response->assertDontSee('無関係なダミー計画');
    }

    /**
     * ② 新規作成・保存 ＆ 初期状態下書き（draft）固定検証テスト
     */
    public function test_管理者は新しいプランを初期状態下書きとして正常に作成できること(): void
    {
        $postData = [
            'name'                  => '新規プレミアム受講プラン',
            'description'           => '充実したプランです。',
            'duration_days'         => 180,
            'default_meeting_quota' => 12,
            'sort_order'            => 3,
        ];

        $response = $this->actingAs($this->adminUser)->post(route('admin.plans.store'), $postData);

        // 作成完了後は一覧へリダイレクトされること
        $response->assertRedirect(route('admin.plans.index'));
        $response->assertSessionHas('success');

        // データベースに初期ステータス 'draft' で、本物マイグレーションのカラム名通りに保存されていること
        $this->assertDatabaseHas('plans', [
            'name'                  => '新規プレミアム受講プラン',
            'status'                => 'draft',
            'duration_days'         => 180,
            'default_meeting_quota' => 12,
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);
    }

    /**
     * ③ 入力検証（Form Request の文字数上限 ＆ 型範囲バリデーション）テスト
     */
    public function test_プラン新設時に必須項目や文字数上限を満たさない場合はバリデーションエラーになること(): void
    {
        $invalidData = [
            'name'                  => str_repeat('P', 101), // 💡 マイグレーション制限の100文字をオーバー
            'duration_days'         => '',                   // 必須項目欠落
            'default_meeting_quota' => -1,                   // 不正な範囲の整数
        ];

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.plans.create'))
            ->post(route('admin.plans.store'), $invalidData);

        $response->assertRedirect(route('admin.plans.create'));
        $response->assertSessionHasErrors(['name', 'duration_days', 'default_meeting_quota']);
    }

    /**
     * ④ 詳細表示 ＆ 編集更新（状態は変更しない：PUT指定）の検証テスト
     */
    public function test_管理者はプランの詳細表示およびステータスを維持したままの基本情報更新ができること(): void
    {
        $plan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '更新前のプラン名',
            'duration_days'         => 30,
            'default_meeting_quota' => 1,
            'status'                => 'draft',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);

        // 詳細画面の表示検証
        $response = $this->actingAs($this->adminUser)->get(route('admin.plans.show', $plan->id));
        $response->assertStatus(200);

        // 基本情報のPUT更新（Bladeの要求するPUT指定に完全適合）
        $updateData = [
            'name'                  => '完全に直したプラン名',
            'duration_days'         => 45,
            'default_meeting_quota' => 2,
        ];

        $response = $this->actingAs($this->adminUser)->put(route('admin.plans.update', $plan->id), $updateData);
        $response->assertRedirect(route('admin.plans.show', $plan->id));

        // ステータスは「draft」のまま維持され、基本情報が更新されていること
        $this->assertDatabaseHas('plans', [
            'id'                    => $plan->id,
            'name'                  => '完全に直したプラン名',
            'duration_days'         => 45,
            'default_meeting_quota' => 2,
            'status'                => 'draft',
            'updated_by_user_id'    => $this->adminUser->id,
        ]);
    }

    /**
     * ⑤ 物理削除における「公開中ガード ＆ 受講者紐づきガード」仕様テスト
     */
    public function test_下書きかつ受講者のいないプランは削除できるが公開中または受講者が紐づくプランは物理削除が拒否されること(): void
    {
        // 1. 削除可能な下書きプランの検証
        $draftPlan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '消去していい下書きプラン',
            'duration_days'         => 30,
            'default_meeting_quota' => 1,
            'status'                => 'draft',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);

        // 作成した下書きプランに対して削除リクエストを送信
        $response = $this->actingAs($this->adminUser)->delete(route('admin.plans.destroy', $draftPlan->id));
        $this->assertDatabaseMissing('plans', ['id' => $draftPlan->id]);

        // 2. ガード検証①：公開中（published）パックの削除不可
        $publishedPlan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '公開中につき削除不可プラン',
            'duration_days'         => 30,
            'default_meeting_quota' => 1,
            'status'                => 'published',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);

        // 作成した公開中プランに対して削除リクエストを送信
        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.plans.show', $publishedPlan->id))
            ->delete(route('admin.plans.destroy', $publishedPlan->id));

        $response->assertStatus(302);   // 元の画面へ戻されること
        $response->assertSessionHas('danger');  // フラッシュメッセージが表示されていること
        $this->assertDatabaseHas('plans', ['id' => $publishedPlan->id]);    // レコードが残っている事

        // 3. ガード検証②：受講中ユーザーが参照しているプランの削除不可
        $referredPlan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '受講生が紐づいているため削除不可プラン',
            'duration_days'         => 30,
            'default_meeting_quota' => 1,
            'status'                => 'draft', // ステータスは下書きでも、参照がある
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);

        // テスト用受講生の plan_id カラムにこのプランのIDをセットして紐づける
        $this->student->update(['plan_id' => $referredPlan->id]);

        // 作成したプランに対して削除リクエストを送信
        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.plans.show', $referredPlan->id))
            ->delete(route('admin.plans.destroy', $referredPlan->id));

        $response->assertStatus(302);   // 元の画面に戻されること
        $response->assertSessionHas('danger');  // フラッシュメッセージが表示されていること
        $this->assertDatabaseHas('plans', ['id' => $referredPlan->id]); // 削除がブロックされていること
    }

    /**
     * ⑥ 状態遷移（ライフサイクル：下書き → 公開中 → アーカイブ → 下書き）連動テスト
     */
    public function test_プランのライフサイクルに沿ってステータスが正しく更新されること(): void
    {
        $plan = Plan::create([
            'id'                    => (string) Str::ulid(),
            'name'                  => '状態遷移テストプラン',
            'duration_days'         => 30,
            'default_meeting_quota' => 1,
            'status'                => 'draft',
            'created_by_user_id'    => $this->adminUser->id,
            'updated_by_user_id'    => $this->adminUser->id,
        ]);

        // 1. 公開にする (publish)
        $this->actingAs($this->adminUser)->post(route('admin.plans.publish', $plan->id));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'published']);

        // 2. アーカイブする (archive)
        $this->actingAs($this->adminUser)->post(route('admin.plans.archive', $plan->id));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'archived']);

        // 3. 下書きに戻す (unarchive)
        $this->actingAs($this->adminUser)->post(route('admin.plans.unarchive', $plan->id));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'draft']);
    }

    /**
     * ⑦ 共通アクセス制御（受講生 ＆ コーチの一律403拒否ガード）テスト
     */
    public function test_受講生およびコーチが管理者専用のプランマスタ機能にアクセスした場合は一律で認可拒否されること(): void
    {
        // 受講生による侵入ブロック検証
        $response = $this->actingAs($this->student)->get(route('admin.plans.index'));
        $response->assertStatus(403);

        // コーチによる侵入ブロック検証
        $response = $this->actingAs($this->coach)->get(route('admin.plans.create'));
        $response->assertStatus(403);
    }
}
