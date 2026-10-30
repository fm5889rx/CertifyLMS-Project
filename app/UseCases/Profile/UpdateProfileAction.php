<?php

declare(strict_types=1);

namespace App\UseCases\Profile;

use App\Models\User;

/**
 * ユーザーのプロフィール基本情報を安全に更新するユースケース（S-B-06仕様完全同期版）
 */
class UpdateProfileAction
{
    /**
     * プロフィール基本情報の更新実行
     *
     * @param User $user ログイン中のユーザーモデル
     * @param array<string, mixed> $validatedData FormRequestで検証済みの正解データ
     * @return bool
     */
    public function __invoke(User $user, array $validatedData): bool
    {
        $user->name = $validatedData['name'];
        
        // ⭕ 【本物のマッピング】画面から届いた正解データ 'bio' を、
        // そのまま User モデルの 'bio' カラムへ 1対1 で正確に代入して保存します。
        $user->bio = $validatedData['bio'] ?? null;

        // コーチの場合のみ固定面談URLを更新
        if ($user->isCoach() && array_key_exists('meeting_url', $validatedData)) {
            $user->meeting_url = $validatedData['meeting_url'];
        }

        return $user->save();
    }
}
