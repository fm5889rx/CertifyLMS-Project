<?php

declare(strict_types=1);

namespace App\Http\Requests\LearningGoal;

use Illuminate\Foundation\Http\FormRequest;

class LearningGoalRequest extends FormRequest
{
    /**
     * リクエストに対するユーザーの認可権限を判定する
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * バリデーションルールを定義する
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],            // タイトル：必須 / 100文字制限
            'description' => ['nullable', 'string', 'max:1000'],           // 詳細：任意 / 1000文字制限
            'target_date' => ['required', 'date', 'after_or_equal:today'], // 目標期日：必須 / 今日以降の日付
        ];
    }
}
