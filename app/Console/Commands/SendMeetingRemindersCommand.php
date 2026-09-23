<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderLog;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * 予約済み面談に対する自動リマインダー通知を一斉配信する Schedule Command。
 *
 * --window=eve（前日分） または --window=one_hour_before（1時間前分）の引数を動的に評価。
 * 重複防止ログ（meeting_reminder_logs）を参照し、同一ウィンドウ内での多重配信を厳格にブロック（冪等）。
 * 受信者（Student / Coach）の利用状態が InProgress（受講中）である場合のみ安全に配信をフックします。
 */
class SendMeetingRemindersCommand extends Command
{
    /**
     * コマンドのシグネチャを定義
     * --window=eve（前日分） または --window=one_hour_before（1時間前分）
     */
    protected $signature = 'notifications:send-meeting-reminders {--window= : 配信タイミング (eve または one_hour_before)}';

    /**
     * コマンドの説明文
     */
    protected $description = '予約済み面談に対する前日および1時間前の自動リマインダー通知を一斉配信します';

    /**
     * コマンドの実行ロジック
     */
    public function handle(): int
    {
        $window = $this->option('window');

        // 指定されたウィンドウ引数が不適切ならエラーを出して即時終了
        if ($window !== 'eve' && $window !== 'one_hour_before') {
            $this->error('エラー: --window オプションには "eve" または "one_hour_before" を指定してください。');

            return Command::FAILURE;
        }

        $this->info("面談リマインダー処理を開始します。 [対象ウィンドウ: {$window}]");

        $now = now();
        $query = Meeting::where('status', MeetingStatus::Reserved); // Enumオブジェクト同期

        if ($window === 'eve') {
            // 「前日」範囲：面談開始時刻（scheduled_at）が「明日（24時間後〜48時間後）」の範囲にある面談を抽出
            $startRange = $now->copy()->addDay()->startOfDay()->toDateTimeString();
            $endRange = $now->copy()->addDay()->endOfDay()->toDateTimeString();
            $query->whereBetween('scheduled_at', [$startRange, $endRange]);
        } else {
            // 「1時間前」範囲：面談開始時刻が「今から1時間以内（現在時刻〜1時間後）」の範囲にある面談を抽出
            $startRange = $now->copy()->toDateTimeString();
            $endRange = $now->copy()->addHour()->toDateTimeString();
            $query->whereBetween('scheduled_at', [$startRange, $endRange]);
        }

        $meetings = $query->get();
        $processedCount = 0;

        foreach ($meetings as $meeting) {
            // 履歴ログを参照し重複時はスルー
            $alreadySent = MeetingReminderLog::where('meeting_id', $meeting->id)
                ->where('window', $window)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $student = $meeting->student;
            $coach = $meeting->coach;

            if (! $student || ! $coach) {
                continue;
            }

            // 「受信者の利用状態によっては配信しない」をUserStatus Enumでガード
            if ($student->status !== UserStatus::InProgress || $coach->status !== UserStatus::InProgress) {
                continue;
            }

            // トランザクションを開始し、「重複防止ログの記録」と「通知クラスの発火」を行う
            DB::transaction(function () use ($meeting, $window, $student, $coach) {

                // ① 重複防止ログの記録
                MeetingReminderLog::create([
                    'id' => (string) Str::ulid(),
                    'meeting_id' => $meeting->id,
                    'window' => $window,
                ]);

                // ② リマインダー通知を発火
                $notification = new MeetingReminderNotification($meeting, $window);

                $student->notify($notification);
                $coach->notify($notification);
            });

            $processedCount++;
        }

        $this->info("面談リマインダー処理が正常に完了しました。（配信確定面談数: {$processedCount} 件）");

        return Command::SUCCESS;
    }
}
