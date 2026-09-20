<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notifications;

use App\Models\User;
use App\Models\Meeting;
use App\Models\Enrollment;
use App\Models\MeetingReminderLog;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\MeetingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $coach;
    private Enrollment $enrollment;

    /**
     * 各テストの初期状態セットアップ（Enumオブジェクト規約の完全徹底）
     */
    protected function setUp(): void
    {
        parent::setUp();

        //【T-A-05：テスト空間キュー自動執行同期規約のマウント】
        config(['queue.default' => 'sync']);

        // Enumオブジェクトを使ってユーザーを生成
        $inProgressStatus = UserStatus::InProgress;

        $this->student = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus, 'name' => 'テスト受講生']);
        $this->coach   = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'name' => 'テストコーチ']);

        // 親となる受講登録の生成
        $this->enrollment = Enrollment::factory()->create(['user_id' => $this->student->id]);
    }

    /**
     * ① 前日（eve）ウィンドウの配信・ログ記録テスト
     */
    public function test_前日コマンドは明日の予約済み面談を正常に検知してリマインダーを配信し重複防止ログを記録すること(): void
    {
        // 明日の昼12:00で予約済み面談を生成
        $meeting = Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'student_id'    => $this->student->id,
            'coach_id'      => $this->coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addDay()->setHour(12)->setMinute(0)->setSecond(0),
            'topic'         => '明日の模擬面談',
        ]);

        // --window=eve 引数を投げてArtisanコマンドを実行！
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        // 1. 重複防止履歴ログテーブルに「eve」として物理保存されていることを検証
        $this->assertDatabaseHas('meeting_reminder_logs', [
            'meeting_id' => $meeting->id,
            'window'     => 'eve',
        ]);

        // 2. 受講生とコーチの双方の通知テーブルへ新着リマインドが刻まれていることを検証
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->student->id,
            'type'          => 'App\Notifications\MeetingReminderNotification',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->coach->id,
            'type'          => 'App\Notifications\MeetingReminderNotification',
        ]);
    }

    /**
     * ② 1時間前（one_hour_before）ウィンドウの配信テスト
     */
    public function test_1時間前コマンドは今から30分後の予約済み面談を正常に検知して直前リマインダーを配信すること(): void
    {
        // 今から30分後で予約済み面談を生成
        $meeting = Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'student_id'    => $this->student->id,
            'coach_id'      => $this->coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addMinutes(30),
            'topic'         => '直前の成果発表会',
        ]);

        // --window=one_hour_before 引数を投げてコマンド実行
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0);

        // 1. 重複防止履歴ログテーブルに「eve」として物理保存されていることを検証
        $this->assertDatabaseHas('meeting_reminder_logs', [
            'meeting_id' => $meeting->id,
            'window'     => 'one_hour_before',
        ]);

        // 2. 受講生の通知テーブルへ新着リマインドが刻まれていることを検証
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
    }

    /**
     * ③ 要件「重複配信防止（二重ガード）」の検証テスト
     */
    public function test_コマンドが重複して再実行されても二重配信ログへの記録や通知の連発が鉄壁にブロックされること(): void
    {
        $meeting = Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'student_id'    => $this->student->id,
            'coach_id'      => $this->coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addMinutes(30),
            'topic'         => '重複検証面談',
        ]);

        // 1回目の実行（通常配信）
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before']);

        // 通知が受講生宛てに「1件」あることを確認
        $firstCount = \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $this->student->id)->count();
        $this->assertEquals(1, $firstCount);

        // 2回目の実行（重複起動・再実行のシミュレート）
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0);

        // 2回目の実行後も通知の総数が「1件」のまま増えていない（二重配信が100%防止された）ことを検証
        $secondCount = \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $this->student->id)->count();
        $this->assertEquals(1, $secondCount);
    }

    /**
     * ④ 要件「受信者の利用状態による配信スキップガード」のテスト
     */
    public function test_受講生またはコーチが休会や退会などでInProgress状態ではない場合はリマインダー配信が安全にスキップされること(): void
    {
        // 🚨 受講生を「退会済（withdrawn）」などの非活性状態へ上書き設定！
        $this->student->update(['status' => UserStatus::Withdrawn ?? 'withdrawn']);

        $meeting = Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $this->enrollment->id,
            'student_id'    => $this->student->id,
            'coach_id'      => $this->coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addMinutes(30),
            'topic'         => 'スキップ対象面談',
        ]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before']);

        // 重複防止ログテーブルにも、通知テーブルにも1文字も書き込まれていない（スキップガード成功）を検証
        $this->assertDatabaseMissing('meeting_reminder_logs', ['meeting_id' => $meeting->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->student->id]);
    }
}
