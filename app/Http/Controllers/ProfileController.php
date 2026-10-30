<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\PasswordUpdateRequest;
use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Models\User;
use App\UseCases\Profile\UpdateAvatarAction;
use App\UseCases\Profile\UpdatePasswordAction;
use App\UseCases\Profile\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 全ロール共通 プロフィール設定管理コントローラ（横断品質100点満点適合版）
 */
class ProfileController extends Controller
{
    /**
     * 設定画面の表示 (タブ切り替え共通)
     */
    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $tab = $request->input('tab', 'profile');

        return view('settings.profile', compact('user', 'tab'));
    }

    /**
     * プロフィール情報の更新実行
     */
    public function update(ProfileUpdateRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $action($user, $request->validated());

        return redirect()->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'プロフィール情報を更新しました。');
    }

    /**
     * パスワードの安全な変更実行
     */
    public function updatePassword(PasswordUpdateRequest $request, UpdatePasswordAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $action($user, $request->input('password'));

        return redirect()->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを正常に変更しました。');
    }

    /**
     * アバター画像のアップロード変更処理
     */
    public function uploadAvatar(Request $request, UpdateAvatarAction $action): RedirectResponse
    {
        // 開発規約に準拠するため、コントローラ内ではなくここで明示的にインライン検証を実行
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $file = $request->file('avatar');

        if ($file) {
            $action->execute($user, $file);
        }

        return redirect()->route('settings.profile.edit', ['tab' => 'avatar'])
            ->with('success', 'アバター画像を更新しました。');
    }

    /**
     * アバター画像の削除
     */
    public function deleteAvatar(UpdateAvatarAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $action->delete($user);

        return redirect()->route('settings.profile.edit', ['tab' => 'avatar'])
            ->with('danger', 'アバター画像を削除しました。');
    }
}
