<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminQaBoardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $student;

    private Certification $certification;

    /**
     * 管理者テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->certification = Certification::factory()->create([
            'status' => 'published',
        ]);

        $inProgressStatus = defined('\App\Enums\UserStatus::InProgress') ? UserStatus::InProgress : 'in_progress';

        // 管理者権限（UserRole::Admin）を持つアカウントを生成
        $this->adminUser = User::factory()->create([
            'role' => UserRole::Admin ?? 'admin',
            'status' => $inProgressStatus,
        ]);

        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'status' => $inProgressStatus,
        ]);
    }

    /**
     * ① 管理者：モデレーション一覧の表示テスト
     */
    public function test_管理者は専用のモデレーション一覧画面を表示できること(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('admin.qa-board.index'));
        $response->assertStatus(200);
    }

    /**
     * ② 管理者：質問スレッドの強制削除（依存回答の巻き添え物理削除を含む）テスト
     */
    public function test_管理権限により質問スレッドとそれに紐づく全回答を一括で強制物理削除できること(): void
    {
        $thread = QaThread::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->student->id,
            'certification_id' => $this->certification->id,
            'title' => '管理者が消去する不適切な質問',
            'body' => '本文',
            'status' => QaThreadStatus::Open->value,
        ]);

        $reply = QaReply::create([
            'id' => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id' => $this->student->id,
            'body' => '巻き添えで消える回答テキスト',
        ]);

        // route('admin.qa-board.destroy') を使って強制DELETE
        $response = $this->actingAs($this->adminUser)->delete(route('admin.qa-board.destroy', [
            'thread' => $thread->id,
        ]));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.qa-board.index'));
        $response->assertSessionHas('danger'); // フラッシュメッセージを検証

        // データベースから親も子（回答）も完全に跡形もなく消えていることを厳格に証明
        $this->assertDatabaseMissing('questions', ['id' => $thread->id]);
        $this->assertDatabaseMissing('answers', ['id' => $reply->id]);
    }

    /**
     * ③ 管理者：不適切な回答（リプライ）のピンポイント削除テスト
     */
    public function test_管理権限によりスレッド内の不適切な回答をピンポイントでモデレーション削除できること(): void
    {
        $thread = QaThread::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->student->id,
            'certification_id' => $this->certification->id,
            'title' => '健全な親質問スレッド',
            'body' => '本文',
            'status' => QaThreadStatus::Open->value,
        ]);

        $badReply = QaReply::create([
            'id' => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id' => $this->student->id,
            'body' => '管理者によって削除される不適切な回答テキスト',
        ]);

        // Bladeと同期した route名 と パラメータキー（reply）で送信
        $response = $this->actingAs($this->adminUser)->delete(route('admin.qa-board.replies.destroy', [
            'thread' => $thread->id,
            'reply' => $badReply->id,
        ]));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.qa-board.show', ['thread' => $thread->id]));
        $response->assertSessionHas('danger');

        // 回答だけがピンポイントで消去され、親質問は無傷で残っていることを検証
        $this->assertDatabaseMissing('answers', ['id' => $badReply->id]);
        $this->assertDatabaseHas('questions', ['id' => $thread->id]);
    }
}
