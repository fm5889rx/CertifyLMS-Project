<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

/**
 * EnrollmentNote（受講生メモ）リソースに対する画面描画用の認可ポリシー（S-B-07仕様）
 */
class EnrollmentNotePolicy
{
    /**
     * 受講登録詳細画面で、コーチメモのセクション自体を表示するかどうかを判定する（Bladeの247行目と完全連動）
     */
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        // 1. 受講生（student）は閲覧含めすべて拒否
        if ($user->isStudent()) {
            return false;
        }

        // 2. 管理者（admin）は無条件で閲覧可
        if ($user->isAdmin()) {
            return true;
        }

        // 3. コーチ（coach）は、すでにEnrollmentPolicy側で磨き上げた「現役の担当アサイン」の有無に型安全に完全委譲する
        if ($user->isCoach()) {
            // EnrollmentPolicyをインスタンス化して、全く同じ厳格なアサインチェックを走らせる
            $enrollmentPolicy = new EnrollmentPolicy();
            
            // EnrollmentPolicyのviewメソッド、または内部の現役判定メソッドと同じロジックで認可を返却
            return $user->assignedCertifications()
                ->where('certification_coach_assignments.certification_id', $enrollment->certification_id)
                ->exists();
        }

        return false;
    }
}
