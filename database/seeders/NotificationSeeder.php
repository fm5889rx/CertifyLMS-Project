<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Question;
use App\Enums\UserRole;
use App\Enums\QaThreadStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Notifications\DatabaseNotification;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::where('role', UserRole::Student)->first() ?? User::factory()->create(['role' => UserRole::Student, 'name' => '受講生A']);

        $thread = Question::create([
            'id'                 => (string) Str::ulid(),
            'user_id'            => $student->id,
            'title'              => '通知ページネーション検証用の質問',
            'body'               => '本文です。',
            'status'             => QaThreadStatus::Open->value ?? 'Open',
        ]);

        // ページネーション検証：25件のループ生成
        // コントローラの paginate(20) に合わせ、20件をオーバーさせて「2ページ目」を出現させる
        for ($i = 1; $i <= 25; $i++) {
            DatabaseNotification::create([
                'id'              => (string) Str::uuid(),
                'type'            => 'App\Notifications\QaReplyPostedNotification',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id'   => $student->id,
                'data'            => [
                    'title' => "【検証用第 {$i} 件】あなたの質問に新しい回答が投稿されました。",
                    'url'   => route('qa-board.show', ['thread' => $thread->id]),
                ],
                // 15件は未読、10件は既読にして混在状態を美しく再現
                'read_at' => $i > 15 ? now()->subHours($i) : null,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }
    }
}
