<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Certification;
use App\Models\Question;
use App\Models\User;
use App\Enums\QaThreadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $certificate = Certification::inRandomOrder()->first();

        return [
            // 既存のUserからランダムに紐付けるか、いなければ新規作成
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),

            // 既存の資格があればそのIDを使い、1件もなければ新しく1件ファクトリで作って紐付ける
            'certification_id' => $certificate ? $certificate->id : Certification::factory(),

            'title' => $this->faker->realText(30) . 'について質問です',

            'body' => $this->faker->realText(200),

            // Enumのケース（Open,UnresolvedまたはResolved）をランダムにセット
            $status = $this->faker->randomElement([QaThreadStatus::Open, QaThreadStatus::Unresolved, QaThreadStatus::Resolved]),
            "status" => $status,

            // ステータスが解決済（Resolved）なら過去のランダムな日時を入れ、未解決なら null にする
            'resolved_at' => $status === QaThreadStatus::Resolved ? $this->faker->dateTimeBetween('-2 weeks', 'now') : null,

            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
