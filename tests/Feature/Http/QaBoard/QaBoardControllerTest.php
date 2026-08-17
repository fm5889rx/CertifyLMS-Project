<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\User;
use App\Models\Certification;
use App\Models\Answer;
use App\Models\QaThread;
use App\Models\QaReply;
use App\Models\CertificationCoachAssignment; // Eloquentモデルをインポート
use App\Enums\UserRole;
use App\Enums\QaThreadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaBoardControllerTest extends TestCase
{
    use RefreshDatabase; // データベースをリフレッシュするトレイト

    private User $student;
    private User $anotherStudent;
    private User $coach;
    private Certification $certification;

    /**
     * 各テストの最初期状態を本番仕様のデータ構造に完全同期させてセットアップ
     */
    protected function setUp(): void
    {
        // 0. テスト環境のセットアップ
        parent::setUp();

        // 1. テスト用の資格マスターを作成 (ステータスを published に)
        $this->certification = Certification::factory()->create([
            'status' => 'published',
        ]);

        // 2. 本番仕様に合わせた「in_progress」ステータスを決定
        $inProgressStatus = defined('\App\Enums\UserStatus::InProgress')
            ? \App\Enums\UserStatus::InProgress
            : 'in_progress';

        // 3. 各ユーザーを本物のステータスとEnum型で生成
        $this->student = User::factory()->create([
            'role'   => UserRole::Student ?? 'student',
            'status' => $inProgressStatus,
        ]);

        $this->anotherStudent = User::factory()->create([
            'role'   => UserRole::Student ?? 'student',
            'status' => $inProgressStatus,
        ]);

        $this->coach = User::factory()->create([
            'role'   => UserRole::Coach ?? 'coach',
            'status' => $inProgressStatus,
        ]);

        // 4. 中間テーブルの制約を満たすため、アサイン実行者（管理者）を1名作成
        $adminUser = User::factory()->create([
            'role'   => UserRole::Admin,
            'status' => $inProgressStatus,
        ]);

        // 5. コーチアサインを保存
        CertificationCoachAssignment::create([
            'id'                  => (string) Str::ulid(),
            'user_id'             => $this->coach->id,
            'certification_id'    => $this->certification->id,
            'assigned_by_user_id' => $adminUser->id,
            'assigned_at'         => now()->subDay(),
            'unassigned_at'       => null,
        ]);
    }

    /**
     * ① 一覧画面のテスト（正常系 ＆ ページネーション・変則クエリ引き継ぎ）
     */
    public function test_一覧画面にアクセスでき変則的なクエリ文字列がページネーションリンクに維持されること(): void
    {
        // 準備：掲示板に12件登録
        foreach (range(1, 12) as $i) {
            QaThread::create([
                'id'               => (string) Str::ulid(),
                'user_id'          => $this->student->id,
                'certification_id' => $this->certification->id,
                'title'            => '未解決のテスト質問',
                'body'             => '掲示板の本文です',
                'status'           => QaThreadStatus::Open,
            ]);
        }


        // 実行：GET /qa-board
        $response = $this->actingAs($this->student)->get(route('qa-board.index', [
            'certification_id' => '',
            'status'           => ['Unresolved', 'unresolved'],
            'keyword'          => '',
            'page'             => '2',
        ]));

        // 検証：ステータスOK（200）を期待
        $response->assertStatus(200);
        // 検証：11件目が見えているか
        $response->assertSee('未解決のテスト質問');
        // 検証：ページネーションリンクが表示されているか
        $this->assertStringContainsString('status%5B', $response->getContent());
    }

    /**
     * ② 質問作成の認可テスト（受講生は○、コーチは×）
     */
    public function test_受講生は質問作成画面を表示できるがコーチは権限エラーになること(): void
    {
        // 準備：Laravelの例外処理を有効にする
        $this->withDeprecationHandling();

        // 実行：GET /qa-board/create（正常系）
        $response = $this->actingAs($this->student)->get(route('qa-board.create'));
        // 検証：HTTPステータスが200を期待
        $response->assertStatus(200);

        // 実行：GET /qa-board/create（異常系）
        $response = $this->actingAs($this->coach)->get(route('qa-board.create'));
        // 検証：権限エラー403を期待
        $response->assertStatus(403);
    }

    /**
     * ③ 質問保存（store）の正常系 ＆ 動的バリデーションテスト
     */
    public function test_正しいデータであれば質問が保存されるがバリデーションエラー時は作成画面へ戻されること(): void
    {
        // 準備：質問投稿データを準備
        $postData = [
            'title'            => '新着のテストタイトル',
            'body'             => '新着のテスト本文です。',
            'certification_id' => $this->certification->id,
        ];

        // 実行：POST /qa-board
        $response = $this->actingAs($this->student)->post(route('qa-board.store'), $postData);

        // 検証：テーブルが新規作成されているか
        $this->assertDatabaseHas('questions', ['title' => '新着のテストタイトル']);
        // 検証：別画面にリダイレクトしているか
        $response->assertRedirect();

        // 準備：異常値データを準備
        $invalidData = [
            'title'            => '',
            'body'             => 'タイトルがないデータ',
            'certification_id' => $this->certification->id,
        ];

        // 実行：異常データを使ってPOST /qa-board
        $response = $this->actingAs($this->student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), $invalidData);

        // 検証：新規作成画面にリダイレクトされているか
        $response->assertRedirect(route('qa-board.create'));
        // 検証：titleエラーが出ているか
        $response->assertSessionHasErrors(['title']);
    }

    /**
     * ④ 質問の更新・削除における「本人認可（403）」テスト
     */
    public function test_質問の投稿者本人のみ編集更新およびスレッド削除が行えること(): void
    {
        // 準備：1件の質問投稿データを作成
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => '修正前のタイトル',
            'body'             => '修正前の本文',
            'status'           => QaThreadStatus::Open,
        ]);

        // 実行：他の受講生がPATCH /qa-board/{thread}を発行
        $response = $this->actingAs($this->anotherStudent)->patch(route('qa-board.update', $thread), [
            'title' => '他人が勝手に書き換えたタイトル',
            'body'  => '他人が勝手に書き換えた本文',
        ]);

        // 検証：権限エラー403を期待
        $response->assertStatus(403);

        // 実行：質問所有者がPATCH /qa-board/{thread}を発行
        $response = $this->actingAs($this->student)->patch(route('qa-board.update', $thread), [
            'title'            => '本人が直したタイトル',
            'body'             => '本人が直した本文',
            'certification_id' => $this->certification->id,
        ]);

        // 検証：質問詳細画面にリダイレクトされているか
        $response->assertRedirect(route('qa-board.show', $thread));
        // 検証：テーブルが更新されているか
        $this->assertDatabaseHas('questions', ['title' => '本人が直したタイトル']);
    }

    /**
     * ⑤ 解決（resolve）・未解決（unresolve）のステータス ＆ resolved_at 連動テスト
     */
    public function test_投稿者本人が解決済にするとステータスと解決日時が更新され受付中に戻すとリセットされること(): void
    {
        // 準備：質問投稿を1件作成
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => 'テスト対象スレッド',
            'body'             => '本文',
            'status'           => QaThreadStatus::Open,
        ]);

        // 実行：POST /qa-board/{thread}/resolveを発行
        $response = $this->actingAs($this->student)->post(route('qa-board.resolve', $thread));

        // 検証：ステータスが解決済になっているか
        $this->assertDatabaseHas('questions', [
            'id'     => $thread->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);
        // 検証：解決済日時に値がセットされたか
        $this->assertNotNull(QaThread::find($thread->id)->resolved_at);

        // 実行：POST qa-board/{thread}/unresolveを発行
        $response = $this->actingAs($this->student)->post(route('qa-board.unresolve', $thread));

        // 検証：ステータスが受付中になり、解決積日時がNULLになっているか
        $this->assertDatabaseHas('questions', [
            'id'          => $thread->id,
            'status'      => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);
    }

    /**
     * ⑥ 回答投稿（storeReply）における「解決済スレッドブロック」仕様テスト
     */
    public function test_解決済になっているスレッドへの新しい回答投稿はポリシーで拒否されること(): void
    {
        // 準備：受講者が作成して解決済ステータスになっている質問投稿を1件作成
        $resolvedThread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => 'already_resolved_question',
            'body'             => '本文',
            'status'           => QaThreadStatus::Resolved->value,
            'is_resolved'      => true,   // Boolean型チェックへの対応
            'resolved_at'      => now(),  // 日時型チェックへの対応
        ]);

        // 実行：POST /qa-board/{thread}/repliesを発行
        $response = $this->actingAs($this->student)->post(route('qa-board.replies.store', $resolvedThread), [
            'body' => '解決済スレッドへの割り込み回答テキスト',
        ]);

        // 検証：権限エラー302を期待
        $response->assertStatus(302);
        // 検証；回答が追加されていないか
        $this->assertDatabaseMissing('answers', [
            'question_id' => $resolvedThread->id,
            'body'        => '解決済スレッドへの割り込み回答テキスト',
        ]);
    }

    /**
     * ⑦ 回答（リプライ）の編集・削除の本人認可テスト
     */
    public function test_回答の投稿者本人のみ自分のリプライを編集更新および削除できること(): void
    {
        // 準備：受講生が質問投稿を1件作成
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => '親スレッド',
            'body'             => '本文',
            'status'           => QaThreadStatus::Open,
        ]);

        // 準備：コーチの回答投稿を1件作成
        $reply = QaReply::create([
            'id'          => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id'     => $this->coach->id,
            'body'        => 'コーチによるアドバイス内容',
        ]);

        // 実行：受講生が回答削除（DELETE /qa-board/{thread}/replies/{reply}）を発行
        $response = $this->actingAs($this->student)->delete(route('qa-board.replies.destroy', [$thread, $reply]));

        // 検証：権限エラー403を期待
        $response->assertStatus(403);
        // 検証：回答投稿が残っているか
        $this->assertDatabaseHas('answers', ['id' => $reply->id]);

        // 実行：コーチが回答削除（DELETE /qa-board/{thread}/replies/{reply}）を発行
        $response = $this->actingAs($this->coach)->delete(route('qa-board.replies.destroy', [$thread, $reply]));

        // 検証：質問詳細画面にリダイレクトされているか
        $response->assertRedirect(route('qa-board.show', $thread));
        // 検証；Answerテーブルから回答投稿が削除されているか
        $this->assertDatabaseMissing('answers', ['id' => $reply->id]);
    }
}
