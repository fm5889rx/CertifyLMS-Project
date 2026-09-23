<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordUpdateRequest extends FormRequest
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
            // 現在ログインしている本人のパスワードと一致しているかをLaravel機能で確認
            'current_password' => ['required', 'string', 'current_password'],

            // 新しいパスワード：必須 / 確認用入力との一致（confirmed）/ 最低8文字制限
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
