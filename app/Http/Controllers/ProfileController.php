<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\ProfileUpdateRequest;
use App\Http\Requests\Profile\PasswordUpdateRequest;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * 設定画面の表示 (タブ切り替え共通)
     */
    public function edit(Request $request): View
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $tab = $request->input('tab', 'profile');

        return view('settings.profile', compact('user', 'tab'));
    }

    /**
     * プロフィール情報の更新実行
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $data = $request->validated();

        $user->name = $data['name'];
        $user->bio = $data['introduction'] ?? null;

        $user->save();

        return redirect()->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'プロフィール情報を更新しました。');
    }

    /**
     * パスワードの安全な変更実行
     */
    public function updatePassword(PasswordUpdateRequest $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $data = $request->validated();

        $user->password = Hash::make($data['password']);
        $user->save();

        return redirect()->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを正常に変更しました。');
    }

    /**
     * アバター画像のアップロード変更処理
     */
    public function uploadAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($request->file('avatar')) {
            if ($user->avatar_url) {
                Storage::disk('public')->delete($user->avatar_url);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_url = $path;

            $user->save();
        }

        return redirect()->route('settings.profile.edit', ['tab' => 'avatar'])
            ->with('success', 'アバター画像を更新しました。');
    }

    /**
     * アバター画像の削除
     */
    public function deleteAvatar(): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->avatar_url) {
            Storage::disk('public')->delete($user->avatar_url);
            $user->avatar_url = null;

            $user->save();
        }

        return redirect()->route('settings.profile.edit', ['tab' => 'avatar'])
            ->with('danger', 'アバター画像を削除しました。');
    }
}
