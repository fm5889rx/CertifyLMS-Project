<?php

declare(strict_types=1);

namespace App\UseCases\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * ユーザーのパスワードを安全にハッシュ化して変更するユースケース
 */
class UpdatePasswordAction
{
    public function __invoke(User $user, string $newPassword): bool
    {
        $user->password = Hash::make($newPassword);
        return $user->save();
    }
}
