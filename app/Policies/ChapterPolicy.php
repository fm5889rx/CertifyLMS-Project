<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;      // 追加：B-B-03
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;

/**
 * Chapter の認可ポリシー。(B-B-01修正版)->（B-B-03修正版）
 */
class ChapterPolicy
{
    /**
     * 【引数適合】：第2引数の $part から親の資格を辿って担当チェックを行う
     */
    public function viewAny(User $auth, Part $part): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $part->certification ? $this->assignedCoach($auth, $part->certification) : false,
            default => false,
        };
    }

    /**
     * 【引数適合】：所属するpartのリレーションを通じて親の資格を辿り担当チェックを行う
     */
    public function view(User $auth, Chapter $chapter): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $chapter->part && $chapter->part->certification
                ? $this->assignedCoach($auth, $chapter->part->certification)
                : false,
            // B-B-03修正前：default => $chapter->status === ContentStatus::Published,
            // B-B-03修正後：最上位の親資格のステータスも Published であることをチェック
            default => $chapter->status === ContentStatus::Published
                && $chapter->part
                && $chapter->part->certification
                && $chapter->part->certification->status === CertificationStatus::Published,
        };
    }

    /**
     * 【引数適合】：$part->certification を canManage へ中継
     */
    public function create(User $auth, Part $part): bool
    {
        return $part->certification ? $this->canManage($auth, $part->certification) : false;
    }

    public function update(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    public function delete(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    public function publish(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    public function unpublish(User $auth, Chapter $chapter): bool
    {
        return $chapter->part && $chapter->part->certification
            ? $this->canManage($auth, $chapter->part->certification)
            : false;
    }

    /**
     * 【引数適合】：$part->certification を canManage へ中継
     */
    public function reorder(User $auth, Part $part): bool
    {
        return $part->certification ? $this->canManage($auth, $part->certification) : false;
    }

    private function canManage(User $auth, Certification $certification): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->assignedCoach($auth, $certification),
            default => false,
        };
    }

    private function assignedCoach(User $coach, Certification $certification): bool
    {
        return $certification->coaches()->where('users.id', $coach->id)->exists();
    }
}
