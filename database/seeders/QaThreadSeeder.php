<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Question;
use App\Models\Answer;
use App\Models\User;
use App\Models\Certification;
use App\Enums\QaThreadStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class QaThreadSeeder extends Seeder
{
    public function run(): void
    {
        // 1. すでに別のシーダーで作られている「資格マスター」をデータベースから全件取得
        $allCertifications = Certification::all();
        $users = User::all();

        // 万が一、他のシーダーが回っていない場合のための安全対策
        if ($allCertifications->isEmpty()) {
            throw new \Exception('エラー: certificationsテーブルが空です。先に資格マスターのシーダーを実行してください。');
        }
        if ($users->isEmpty()) {
            $users = User::factory()->count(5)->create();
        }

        // 2. 質問（Question）と回答（Answer）の作成
        // 既存の資格マスターからランダムにIDを引っ張ってきて、質問データに確実に結びつけます
        for ($i = 0; $i < 40; $i++) {
            $author = $users->random();
            $cert = $allCertifications->random(); // 👈 既存の資格データからランダムに選択

            $question = Question::create([
                'id' => (string) Str::ulid(),
                'user_id' => $author->id,
                'certification_id' => $cert->id, // 👈 既存の正しい資格IDを確実に紐付け
                'title' => 'テスト質問タイトル ' . ($i + 1),
                'body' => 'これはテスト質問の本文です。資格IDとユーザーIDが完璧にリンクしています。',
                'status' => rand(0, 1) ? QaThreadStatus::Open : QaThreadStatus::Resolved,
                'created_at' => now()->subDays(rand(0, 30)),
            ]);

            // 各質問に紐づく回答を作成
            $replyCount = rand(0, 3);
            for ($j = 0; $j < $replyCount; $j++) {
                Answer::create([
                    'id' => (string) Str::ulid(),
                    'question_id' => $question->id,
                    'user_id' => $users->random()->id,
                    'body' => '質問に対するテスト回答内容です。リプライカウントに正しく反映されます。',
                    'is_best' => false,
                    'created_at' => $question->created_at->addHours(rand(1, 24)),
                ]);
            }
        }
    }
}
