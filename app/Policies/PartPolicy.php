<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Part;
use App\Models\User;

/**
 * Part の認可ポリシー。(B-B-01 による修正版)->（B-B-03による修正版）
 *
 * - admin: 全資格配下を CRUD 可
 * - coach: 担当資格(certification_coach_assignments)配下のみ CRUD 可
 * - student: Published 状態のみ閲覧可
 */
class PartPolicy
{
    /**
     * 【B-B-01修正】：担当資格配下の教材管理一覧（Part一覧）へのアクセス解放
     */
    public function viewAny(User $auth, Certification $certification): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            // 修正前：UserRole::Coach => false,
            // 修正後：ログイン中のコーチが「担当資格」にアサインされているかリレーションでチェック
            UserRole::Coach => $this->assignedCoach($auth, $certification),
            default => false,
        };
    }

    /**
     * 【B-B-01修正】：担当資格配下の各教材詳細（Part詳細等）へのアクセス解放
     */
    public function view(User $auth, Part $part): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            // B-B-01修正前：UserRole::Coach => false,
            // B-B-01修正後：親リレーション「$part->certification」を通じて担当資格かをチェック
            UserRole::Coach => $this->assignedCoach($auth, $part->certification),
            // B-B-03修正前：default => $part->status === ContentStatus::Published,
            // B-B-03修正後：教材自体が Published であり、かつ親資格のステータスも Publishedである場合のみ閲覧を許可
            default => $part->status === ContentStatus::Published
                && $part->certification
                && $part->certification->status === CertificationStatus::Published,
        };
    }

    public function create(User $auth, Certification $certification): bool
    {
        return $this->canManage($auth, $certification);
    }

    public function update(User $auth, Part $part): bool
    {
        return $this->canManage($auth, $part->certification);
    }

    public function delete(User $auth, Part $part): bool
    {
        return $this->canManage($auth, $part->certification);
    }

    public function publish(User $auth, Part $part): bool
    {
        return $this->canManage($auth, $part->certification);
    }

    public function unpublish(User $auth, Part $part): bool
    {
        return $this->canManage($auth, $part->certification);
    }

    public function reorder(User $auth, Certification $certification): bool
    {
        return $this->canManage($auth, $certification);
    }

    /**
     * 【B-B-01バグ修正】：作成・編集・削除・公開・並び替えに関する共通権門番の解放
     */
    private function canManage(User $auth, Certification $certification): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            // 修正前: UserRole::Coach => false,
            // 修正後：ここを一括解放することで、配下のChapter/Section/演習問題等のCRUDの403エラーを無くす
            UserRole::Coach => $this->assignedCoach($auth, $certification),
            default => false,
        };
    }

    private function assignedCoach(User $coach, Certification $certification): bool
    {
        return $certification->coaches()->where('users.id', $coach->id)->exists();
    }
}
