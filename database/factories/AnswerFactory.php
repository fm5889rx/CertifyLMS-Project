<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    public function definition(): array
    {
        return [
            'question_id' => Question::inRandomOrder()->first()?->id ?? Question::factory(),
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'body' => $this->faker->realText(100),
            'is_best' => false,
            'created_at' => $this->faker->dateTimeBetween('-3週間', 'now'),
        ];
    }
}
