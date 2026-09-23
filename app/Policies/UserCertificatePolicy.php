<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;

class UserCertificatePolicy
{
    /**
     * 修了証 PDF のダウンロード認可を全自動検閲
     */
    public function download(User $user, Certificate $certificate): bool
    {
        // ガード①：管理者(admin)は全件ダウンロード許可
        if ($user->role === UserRole::Admin) {
            return true;
        }

        // ガード②：受講生(student)の場合、本人の修了証（user_idの一致）のみ許可
        if ($user->role === UserRole::Student) {
            return (string) $user->id === (string) $certificate->user_id;
        }

        // ガード③：コーチ(coach)の場合、自分の担当資格に紐づく修了証のみダウンロード許可
        if ($user->role === UserRole::Coach) {
            $enrollment = Enrollment::findOrFail($certificate->enrollment_id);
            $certification = Certification::FindOrFail($enrollment?->certification_id);

            if (! $certification) {
                return false;
            }

            // 対象資格（Certification）に紐づく担当コーチ陣（coachesコレクション）の ID の中に、
            // 現在ログイン中のコーチ（$user->id）が実在（contains）しているかをチェック
            return $certification->coaches->contains('id', $user->id);
        }

        // その他のロールがあれば全て false を返す
        return false;
    }
}
