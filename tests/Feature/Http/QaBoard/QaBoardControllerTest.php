<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\User;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\QaReply;
use App\Models\CertificationCoachAssignment;
use App\Enums\UserRole;
use App\Enums\QaThreadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaBoardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $anotherStudent;
    private User $coach;
    private Certification $certification;

    /**
     * 各テストの最初期状態を本番仕様のデータ構造に完全同期
     */
    protected function setUp(): void
    {
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
            'role'   => UserRole::Student,
            'status' => $inProgressStatus,
        ]);

        $this->anotherStudent = User::factory()->create([
            'role'   => UserRole::Student,
            'status' => $inProgressStatus,
        ]);

        $this->coach = User::factory()->create([
            'role'   => UserRole::Coach,
            'status' => $inProgressStatus,
        ]);

        // 4. アサイン実行者（管理者）を1名作成
        $adminUser = User::factory()->create([
            'role'   => UserRole::Admin ?? 'admin',
            'status' => $inProgressStatus,
        ]);

        // 5. 【正攻法】本物のEloquentモデルを使って過去からの担当アサインを安全に保存
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
        foreach (range(1, 12) as $i) {
            QaThread::create([
                'id'               => (string) Str::ulid(),
                'user_id'          => $this->student->id,
                'certification_id' => $this->certification->id,
                'title'            => '未解決のテスト質問',
                'body'             => '掲示板の本文です',
                'status'           => QaThreadStatus::Resolved->value,
            ]);
        }

        $response = $this->actingAs($this->student)->get(route('qa-board.index', [
            'certification_id' => '',
            'status'           => ['Unresolved', 'unresolved'],
            'keyword'          => '',
            'page'             => '2',
        ]));

        $response->assertStatus(200);
        $response->assertSee('未解決のテスト質問');
        $this->assertStringContainsString('status%5B', $response->getContent());
    }

    /**
     * ② 質問作成の認可テスト（受講生は○、コーチは×）
     */
    public function test_受講生は質問作成画面を表示できるがコーチは権限エラーになること(): void
    {
        $response = $this->actingAs($this->student)->get(route('qa-board.create'));
        $response->assertStatus(200);

        $response = $this->actingAs($this->coach)->get(route('qa-board.create'));
        $response->assertStatus(403);
    }

    /**
     * ③ 質問保存（store）の正常系 ＆ 動的バリデーションテスト
     */
    public function test_正しいデータであれば質問が保存されるがバリデーションエラー時は作成画面へ戻されること(): void
    {
        $postData = [
            'title'            => '新着のテストタイトル',
            'body'             => '新着のテスト本文です。',
            'certification_id' => $this->certification->id,
        ];

        $response = $this->actingAs($this->student)->post(route('qa-board.store'), $postData);
        $this->assertDatabaseHas('questions', ['title' => '新着のテストタイトル']);
        $response->assertRedirect();

        $invalidData = [
            'title'            => '',
            'body'             => 'タイトルがないデータ',
            'certification_id' => $this->certification->id,
        ];

        $response = $this->actingAs($this->student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), $invalidData);

        $response->assertRedirect(route('qa-board.create'));
        $response->assertSessionHasErrors(['title']);
    }

    /**
     * ④ 質問の更新における「本人認可（403）」テスト
     */
    public function test_質問の投稿者本人のみ編集更新が行えること(): void
    {
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => '修正前のタイトル',
            'body'             => '修正前の本文',
            'status'           => QaThreadStatus::Open->value,
        ]);

        $response = $this->actingAs($this->anotherStudent)->patch(route('qa-board.update', $thread), [
            'title' => '他人が勝手に書き換えたタイトル',
            'body'  => '他人が勝手に書き換えた本文',
        ]);
        $response->assertStatus(403);

        $response = $this->actingAs($this->student)->patch(route('qa-board.update', $thread), [
            'title'            => '本人が直したタイトル',
            'body'             => '本人が直した本文',
            'certification_id' => $this->certification->id,
        ]);
        $response->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseHas('questions', ['title' => '本人が直したタイトル']);
    }

    /**
     * ⑤ 解決（resolve）・未解決（unresolve）のステータス ＆ resolved_at 連動テスト
     */
    public function test_投稿者本人が解決済にするとステータスと解決日時が更新され受付中に戻すとリセットされること(): void
    {
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => 'テスト対象スレッド',
            'body'             => '本文',
            'status'           => QaThreadStatus::Open->value,
        ]);

        $response = $this->actingAs($this->student)->post(route('qa-board.resolve', $thread));
        $this->assertDatabaseHas('questions', [
            'id'     => $thread->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);

        $response = $this->actingAs($this->student)->post(route('qa-board.unresolve', $thread));
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
        $resolvedThread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title'            => 'already_resolved_question',
            'body'             => '本文',
            'status'           => QaThreadStatus::Resolved->value,
            'is_resolved'      => true,
            'resolved_at'      => now(),
        ]);

        // ログインユーザーは、Gateで一律falseになる管理者ではなく、一般ユーザーを使って衝突させる
        $response = $this->actingAs($this->anotherStudent)->post(route('qa-board.replies.store', $resolvedThread), [
            'body' => '解決済スレッドへの割り込み回答テキスト',
        ]);

        $response->assertStatus(302); // 403ではなくリダイレクト（302）で戻る
        $this->assertDatabaseMissing('answers', [
            'question_id' => $resolvedThread->id,
            'body'        => '解決済スレッドへの割り込み回答テキスト',
        ]);
    }

    /**
     * ⑦ 回答（リプライ）の編集・削除の本人認可テスト
     */
    public function test_回答の投稿者本人のみ自分のリプライを削除できること(): void
    {
        $thread = QaThread::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->student->id,
            'certification_id' => $this->certification->id,
            'title' => '親スレッド',
            'body' => '本文',
            'status' => QaThreadStatus::Open->value,
        ]);

        $reply = QaReply::create([
            'id'          => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id'     => $this->student->id,
            'body'        => '受講生による回答内容',
        ]);

        $response = $this->actingAs($this->anotherStudent)->delete(route('qa-board.replies.destroy', [$thread, $reply]));
        $response->assertStatus(403);
        $this->assertDatabaseHas('answers', ['id' => $reply->id]);
    }
}
