<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\User;
use App\Models\MeetingPack;
use App\Enums\UserRole;
use App\Enums\MeetingPackStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMeetingPackControllerTest extends TestCase
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
     * ① 一覧表示のキーワード検索 ＆ 状態フィルタ ＆ ページネーション網羅テスト
     */
    public function test_管理者は面談パック一覧をキーワード検索および状態フィルタ付きでページネーション表示できること(): void
    {
        // ターゲットとなるパックを生成
        MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '注目ターゲットパック',
            'meeting_count'       => 5,
            'price'               => 5000,
            'status'              => MeetingPackStatus::Published->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
            'sort_order'          => 1,
        ]);

        // ノイズとなる下書きパックを生成
        MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '無関係なダミーパック',
            'meeting_count'       => 3,
            'price'               => 3000,
            'status'              => MeetingPackStatus::Draft->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
            'sort_order'          => 2,
        ]);

        // 検索とフィルタを指定してGETリクエスト（大文字小文字の罠を排除したクエリ送信）
        $response = $this->actingAs($this->adminUser)->get(route('admin.meeting-packs.index', [
            'keyword' => '注目',
            'status'  => 'published',
        ]));

        $response->assertStatus(200);
        $response->assertSee('注目ターゲットパック');
        $response->assertDontSee('無関係なダミーパック');
    }

    /**
     * ② 新規作成・保存 ＆ 初期状態下書き（Draft）固定検証テスト
     */
    public function test_管理者は新しい面談パックを初期状態下書きとして正常に作成できること(): void
    {
        $postData = [
            'name'            => '新規面談10回パック',
            'description'     => '贅沢なパックです。',
            'meeting_count'   => 10,
            'price'           => 30000,
            'stripe_price_id' => 'price_12345',
            'sort_order'      => 5,
        ];

        $response = $this->actingAs($this->adminUser)->post(route('admin.meeting-packs.store'), $postData);

        // 作成完了後は一覧へリダイレクトされること
        $response->assertRedirect(route('admin.meeting-packs.index'));
        $response->assertSessionHas('success');

        // データベースに初期ステータス 'draft' で作成者・更新者IDが自動マウントされて保存されていること
        $this->assertDatabaseHas('meeting_packs', [
            'name'                => '新規面談10回パック',
            'status'              => MeetingPackStatus::Draft->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);
    }

    /**
     * ③ 入力検証（Form Request の文字数上限 ＆ 型範囲バリデーション）テスト
     */
    public function test_面談パック新設時に必須項目や文字数上限を満たさない場合はバリデーションエラーになること(): void
    {
        $invalidData = [
            'name'            => str_repeat('A', 101), //  マイグレーション制限の100文字をオーバー
            'meeting_count'   => -5,                   // 不正な範囲の整数
            'price'           => -1000,                // 負の価格
        ];

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.meeting-packs.create'))
            ->post(route('admin.meeting-packs.store'), $invalidData);

        $response->assertRedirect(route('admin.meeting-packs.create'));
        $response->assertSessionHasErrors(['name', 'meeting_count', 'price']);
    }

    /**
     * ④ 詳細表示 ＆ 編集更新（状態は変更しない）の検証テスト
     */
    public function test_管理者は面談パックの詳細表示およびステータスを維持したままの基本情報更新ができること(): void
    {
        $pack = MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '更新前のパック名',
            'meeting_count'       => 1,
            'price'               => 1000,
            'status'              => MeetingPackStatus::Draft->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        // 詳細画面の表示検証
        $response = $this->actingAs($this->adminUser)->get(route('admin.meeting-packs.show', $pack->id));
        $response->assertStatus(200);

        // 基本情報のパッチ更新
        $updateData = [
            'name'          => '完全に新しく直したパック名',
            'meeting_count' => 1,
            'price'         => 1500,
        ];

        $response = $this->actingAs($this->adminUser)->patch(route('admin.meeting-packs.update', $pack->id), $updateData);
        $response->assertRedirect(route('admin.meeting-packs.show', $pack->id));

        // ステータスは「draft」のまま維持され、名前と価格が更新されていること
        $this->assertDatabaseHas('meeting_packs', [
            'id'    => $pack->id,
            'name'  => '完全に新しく直したパック名',
            'price' => 1500,
            'status' => MeetingPackStatus::Draft->value,
        ]);
    }

    /**
     * ⑤ 物理削除における「公開中（Published）パックの削除不可ガード」仕様テスト
     */
    public function test_下書きのパックは削除できるが公開中の面談パックは購入履歴保護のため物理削除が絶対に拒否されること(): void
    {
        // 1. 削除可能な下書きパックの検証
        $draftPack = MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '消去していい下書きパック',
            'meeting_count'       => 2,
            'price'               => 2000,
            'status'              => MeetingPackStatus::Draft->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->delete(route('admin.meeting-packs.destroy', $draftPack->id));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $draftPack->id]);

        // 2. 削除不可能な公開中パックのガード検証
        $publishedPack = MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '絶対に消してはいけない公開中パック',
            'meeting_count'       => 2,
            'price'               => 2000,
            'status'              => MeetingPackStatus::Published->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->delete(route('admin.meeting-packs.destroy', $publishedPack->id));

        // 403ではなく、元の画面（302リリダイレクト）に戻されて、データが「残っていること」を厳格に検証
        $response->assertStatus(302);
        $response->assertSessionHas('danger');
        $this->assertDatabaseHas('meeting_packs', ['id' => $publishedPack->id]);
    }

    /**
     * ⑥ 状態遷移（ライフサイクル：下書き → 公開中 → アーカイブ → 下書き）連動テスト
     */
    public function test_面談パックのライフサイクルに沿ってステータスが正しく更新されること(): void
    {
        $pack = MeetingPack::create([
            'id'                  => (string) Str::ulid(),
            'name'                => '状態遷移テスト用SKU',
            'meeting_count'       => 1,
            'price'               => 1000,
            'status'              => MeetingPackStatus::Draft->value,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        // 1. 公開にする (publish)
        $response = $this->actingAs($this->adminUser)->post(route('admin.meeting-packs.publish', $pack->id));
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id, 'status' => MeetingPackStatus::Published->value]);

        // 2. アーカイブする (archive)
        $response = $this->actingAs($this->adminUser)->post(route('admin.meeting-packs.archive', $pack->id));
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id, 'status' => MeetingPackStatus::Archived->value]);

        // 3. 下書きに戻す (unarchive)
        $response = $this->actingAs($this->adminUser)->post(route('admin.meeting-packs.unarchive', $pack->id));
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id, 'status' => MeetingPackStatus::Draft->value]);
    }

    /**
     * ⑦ 共通アクセス制御（受講生 ＆ コーチの一律403拒否ガード）テスト
     */
    public function test_受講生およびコーチが管理者専用の面談パックマスタ機能にアクセスした場合は一律で認可拒否されること(): void
    {
        // 受講生による一覧画面への侵入をテスト
        $response = $this->actingAs($this->student)->get(route('admin.meeting-packs.index'));
        $response->assertStatus(403);

        // コーチによる新規作成画面への侵入をテスト
        $response = $this->actingAs($this->coach)->get(route('admin.meeting-packs.create'));
        $response->assertStatus(403);
    }
}
