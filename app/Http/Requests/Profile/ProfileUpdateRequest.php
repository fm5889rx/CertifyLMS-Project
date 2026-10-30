<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();

        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'], // ⭕ 画面から届く本物の正解キー
        ];

        // コーチ（role == coach）の場合のみ面談URLの必須ルールを合流
        if ($user && $user->isCoach()) {
            $rules['meeting_url'] = ['required', 'url', 'max:255'];
        }

        return $rules;
    }
}
