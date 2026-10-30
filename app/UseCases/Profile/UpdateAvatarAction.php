<?php

declare(strict_types=1);

namespace App\UseCases\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * アバター画像のアップロードおよび古いファイルのストレージ削除をカプセル化するユースケース
 */
class UpdateAvatarAction
{
    public function execute(User $user, UploadedFile $file): bool
    {
        if ($user->avatar_url) {
            Storage::disk('public')->delete($user->avatar_url);
        }

        $path = $file->store('avatars', 'public');
        $user->avatar_url = $path;

        return $user->save();
    }

    public function delete(User $user): bool
    {
        if ($user->avatar_url) {
            Storage::disk('public')->delete($user->avatar_url);
            $user->avatar_url = null;
            return $user->save();
        }

        return false;
    }
}
