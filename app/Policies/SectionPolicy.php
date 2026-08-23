<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Section;
use App\Models\User;

/**
 * Section の認可ポリシー。（B-B-01修正版）
 *
 * - admin: 全資格配下を CRUD 可
 * - coach: 担当資格配下のみ CRUD 可、Draft 状態の view も可
 * - student: Section / Chapter / Part が全て Published 状態のときのみ閲覧可(cascade visibility)
 */
class SectionPolicy
{
    /**
     * 【引数・階層適合】：第2引数の $chapter（親）から遡って担当資格かをチェック
     */
    public function viewAny(User $auth, Chapter $chapter): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $chapter->part && $chapter->part->certification
                ? $this->assignedCoach($auth, $chapter->part->certification)
                : false,
            default => false,
        };
    }

    /**
     * 【引数・階層適合】：ログで暴いた単数形リレーションの鎖を遡って担当チェック
     */
    public function view(User $auth, Section $section): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $section->chapter && $section->chapter->part && $section->chapter->part->certification
                ? $this->assignedCoach($auth, $section->chapter->part->certification)
                : false,
            default => $section->status === ContentStatus::Published,
        };
    }

    /**
     * 【引数・階層適合】：新規作成時、親の $chapter から遡って canManage へリレー中継
     */
    public function create(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    /**
     * 【引数・階層適合】：編集画面（edit/update）へのアクセス解放
     */
    public function update(User $auth, Section $section): bool
    {
        return $section->chapter && $section->chapter->part && $section->chapter->part->certification
            ? $this->canManage($auth, $section->chapter->part->certification)
            : false;
    }

    public function delete(User $auth, Section $section): bool
    {
        return $section->chapter && $section->chapter->part && $section->chapter->part->certification
            ? $this->canManage($auth, $section->chapter->part->certification)
            : false;
    }

    public function publish(User $auth, Section $section): bool
    {
        return $section->chapter && $section->chapter->part && $section->chapter->part->certification
            ? $this->canManage($auth, $section->chapter->part->certification)
            : false;
    }

    public function unpublish(User $auth, Section $section): bool
    {
        return $section->chapter && $section->chapter->part && $section->chapter->part->certification
            ? $this->canManage($auth, $section->chapter->part->certification)
            : false;
    }

    public function reorder(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    /**
     * 共通の管理権限チェック
     */
    private function canManage(User $auth, Certification $certification): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->assignedCoach($auth, $certification),
            default => false,
        };
    }

    /**
     * 既存の担当チェックリレーション
     */
    private function assignedCoach(User $coach, Certification $certification): bool
    {
        return $certification->coaches()->where('users.id', $coach->id)->exists();
    }
}