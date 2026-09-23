<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use Illuminate\Foundation\Http\FormRequest;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;        // 二重認可衝突を避けるためtrue固定
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'default_meeting_quota' => ['required', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
