<?php

declare(strict_types=1);

namespace App\Http\Requests\LearningGoal;

use Illuminate\Foundation\Http\FormRequest;

class LearningGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * バリデーションルールを定義する
     */
    public function rules(): array
    {
        // 環境のタイムゾーンのブレに影響されないよう、明示的な本日の日付文字列（YYYY-MM-DD）を動的にバインドする
        $today = now()->toDateString();

        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_date' => ['required', 'date', 'after_or_equal:' . $today], // 💡 today ではなく $today 変数を結合する
        ];
    }

    /**
     * 【受け入れ条件完全準拠】カスタムエラーメッセージの定義
     */
    public function messages(): array
    {
        return [
            'title.required' => '目標 を入力してください。',
            'title.max' => '目標 は 100 文字以内で入力してください。',
            'target_date.date' => '目標期日 は有効な日付ではありません。',
        ];
    }
}
