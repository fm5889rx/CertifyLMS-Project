<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProfileUpdateRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:50'],            // 氏名：必須 / 50文字上限
            'introduction' => ['nullable', 'string', 'max:1000'],          // 自己紹介：任意 / 1000文字上限
        ];

        // ログインしている本人のロールが「コーチ」の場合のみ、Blade内の入力欄に合わせて
        // 固定面談URL の必須検証（URL形式）を動的に合流させる
        $user = Auth::user();
        if ($user && ($user->role === UserRole::Coach->value || $user->role === 'coach')) {
            $rules['meeting_url'] = ['required', 'url', 'max:255'];
        }

        return $rules;
    }
}
