<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notifications;

use App\Models\User;
use App\Models\Question;
use App\Notifications\QaReplyPostedNotification;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $anotherStudent;
    private User $coach;
    private Question $thread;

    /**
     * 各テストの初期状態セットアップ
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = defined('\App\Enums\UserStatus::InProgress') ? \App\Enums\UserStatus::InProgress : 'in_progress';

        // 1. 各ロールのアカウントを生成
        $this->student = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->anotherStudent = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->coach = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus]);

        // 2. 掲示板の親質問スレッド（Question）を生成
        $this->thread = Question::create([
            'id'                    => (string) Str::ulid(),
            'user_id'               => $this->student->id,
            'title'                 => 'テスト用質問スレッド',
            'body'                  => '本文です',
            'status'                => QaThreadStatus::Open->value,
            'created_by_user_id'    => $this->student->id,
            'updated_by_user_id'    => $this->student->id,
        ]);
    }

    /**
     * ① 一覧表示の未読カウント ＆ タブ切り替え（all / unread）表示網羅テスト
     */
    public function test_ユーザーは自分宛の通知一覧を未読カウントおよびタブ切り替え付きで正常に確認できること(): void
    {
        // 自分宛の未読通知を発火してインサート
        $this->student->notify(new QaReplyPostedNotification($this->thread));

        // 自分宛の既読通知を生成
        $readNotification = DatabaseNotification::where('notifiable_id', $this->student->id)->first();
        $readNotification->update(['read_at' => now()]);

        // もう一つ新しく未読通知を発火
        $this->student->notify(new QaReplyPostedNotification($this->thread));

        // 1. 「全件（all）」タブの表示検証
        $response = $this->actingAs($this->student)->get(route('notifications.index', ['tab' => 'all']));
        $response->assertStatus(200);
        $response->assertSee('あなたの質問に新しい回答が投稿されました。');
        // 💡 4件目の命：未読数が「1件」として Blade にパッキングされていることを証明
        $response->assertViewHas('unreadCount', 1);

        // 2. 「未読のみ（unread）」タブの表示検証
        $response = $this->actingAs($this->student)->get(route('notifications.index', ['tab' => 'unread']));
        $response->assertStatus(200);
        $response->assertViewHas('tab', 'unread');
    }

    /**
     * ② 詳細表示（show）時の自動既読化（read_at更新）検証テスト
     */
    public function test_通知の詳細画面を開いた瞬間にその通知が自動的に既読化されること(): void
    {
        $this->student->notify(new QaReplyPostedNotification($this->thread));
        $notification = DatabaseNotification::where('notifiable_id', $this->student->id)->first();

        $this->assertNull($notification->read_at);

        // 詳細画面（show）へアクセス
        $response = $this->actingAs($this->student)->get(route('notifications.show', $notification->id));
        $response->assertStatus(200);

        // 検証：開いた瞬間に、データベース側の read_at に現在日時が刻まれていること
        $this->assertNotNull($notification->refresh()->read_at);
    }

    /**
     * ③ 単体クリック時の既読化 ＆ 関連業務画面へのリダイレクト遷移テスト
     */
    public function test_通知の行をクリックした際に既読化と同時に関連する業務画面へリダイレクトされること(): void
    {
        $this->student->notify(new QaReplyPostedNotification($this->thread));
        $notification = DatabaseNotification::where('notifiable_id', $this->student->id)->first();

        // 共通Bladeが要求する POST（markAsRead）ルートへリクエストを送信
        $response = $this->actingAs($this->student)->post(route('notifications.markAsRead', $notification->id));

        // 💡 UX要件：Q&A掲示板の詳細画面（qa-board.show）へ美しくリダイレクトされることを保証！
        $response->assertRedirect(route('qa-board.show', ['thread' => $this->thread->id]));
        $this->assertNotNull($notification->refresh()->read_at);
    }

    /**
     * ④ まとめて既読にする操作（markAllAsRead）の一括更新テスト
     */
    public function test_まとめて既読にするボタンを押した際に自分宛のすべての未読通知が一括で既読化されること(): void
    {
        // 未読通知を2件生成
        $this->student->notify(new QaReplyPostedNotification($this->thread));
        $this->student->notify(new QaReplyPostedNotification($this->thread));

        $this->assertEquals(2, DatabaseNotification::where('notifiable_id', $this->student->id)->whereNull('read_at')->count());

        // 一括既読（markAllAsRead）を送信
        $response = $this->actingAs($this->student)->post(route('notifications.markAllAsRead'));
        $response->assertRedirect(route('notifications.index'));

        // 検証：自分宛の未読通知が「0件」になっていることを厳格に証明
        $this->assertEquals(0, DatabaseNotification::where('notifiable_id', $this->student->id)->whereNull('read_at')->count());
    }

    /**
     * ⑤ セキュリティ制御（他人宛の通知操作に対する403認可拒否）テスト
     */
    public function test_他人宛の通知の詳細表示や既読化を試みた場合は一律で認可拒否エラーになること(): void
    {
        // 受講生A宛の通知を作成
        $this->student->notify(new QaReplyPostedNotification($this->thread));
        $notification = DatabaseNotification::where('notifiable_id', $this->student->id)->first();

        // 別の受講生Bアカウントによる、他人宛の通知詳細（show）への侵入をブロック検証
        $response = $this->actingAs($this->anotherStudent)->get(route('notifications.show', $notification->id));
        $response->assertStatus(403);

        // コーチアカウントによる、他人宛の通知既読（markAsRead）への侵入をブロック検証
        $response = $this->actingAs($this->coach)->post(route('notifications.markAsRead', $notification->id));
        $response->assertStatus(403);
    }
}
