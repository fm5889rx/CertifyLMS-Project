<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use Illuminate\Foundation\Http\FormRequest;

class QaThreadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'certification_id' => ['required', 'exists:certifications,id'],
        ];

        // PATCHまたはPUTメソッドの場合、certification_idのバリデーションを除外
        if ($this->isMethod('patch') || $this->isMethod('put')) {
            unset($rules['certification_id']);
        };

        return $rules;
    }
}
